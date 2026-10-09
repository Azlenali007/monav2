<?php
/**
 * Simplified New Order Page (Matching Exact UI Reference)
 * SMM Panel - PHP 8.3+
 *
 * Final form contains ONLY:
 * 1. Charge (pale mint background, dark blue label)
 * 2. Submit button (large rounded cyan/blue CTA)
 * Surrounded by cyan/turquoise aesthetic surroundings.
 * All backend validations, wallet safety, and real provider order execution are preserved.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireLogin();
$user = Auth::user();
$db = Database::getConnection();

// Fetch active services and categories for selection
$servicesStmt = $db->query("
    SELECT s.*, c.name AS category_name, c.platform
    FROM services s
    JOIN categories c ON s.category_id = c.id
    WHERE s.status = 'active'
    ORDER BY c.sort_order ASC, s.rate_per_1000 ASC, s.id ASC
");
$allServices = $servicesStmt->fetchAll();

// Preselect from GET params or session
$preServiceId = (int)($_GET['service_id'] ?? ($_SESSION['pending_order']['service_id'] ?? ($allServices[0]['id'] ?? 0)));
$preLink = trim((string)($_GET['link'] ?? ($_SESSION['pending_order']['link'] ?? '')));
$preQuantity = (int)($_GET['quantity'] ?? ($_SESSION['pending_order']['quantity'] ?? 0));

$error = null;

// Handle step 1: Configure / Update Order Details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'configure') {
    CSRF::verifyOrAbort();

    $serviceId = (int)($_POST['service_id'] ?? 0);
    $link = trim($_POST['link'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 0);

    $stmt = $db->prepare("SELECT * FROM services WHERE id = :id AND status = 'active' LIMIT 1");
    $stmt->execute(['id' => $serviceId]);
    $svc = $stmt->fetch();

    if (!$svc) {
        $error = "Selected service is invalid or unavailable.";
    } elseif (empty($link) || !filter_var($link, FILTER_VALIDATE_URL)) {
        $error = "Please enter a valid target URL (e.g., https://instagram.com/profile).";
    } elseif ($quantity < (int)$svc['min_quantity'] || $quantity > (int)$svc['max_quantity']) {
        $error = sprintf("Quantity must be between %s and %s for '%s'.", number_format($svc['min_quantity']), number_format($svc['max_quantity']), $svc['name']);
    } else {
        $token = bin2hex(random_bytes(16));
        $serverCharge = round(($quantity / 1000) * (float)$svc['rate_per_1000'], 4);

        $_SESSION['pending_order'] = [
            'service_id' => $svc['id'],
            'link' => $link,
            'quantity' => $quantity,
            'calculated_charge' => $serverCharge,
            'token' => $token,
            'created_at' => time()
        ];

        redirect('/user/new-order.php');
    }
}

// Handle step 2: Final Form Submit (Charge & Submit Button Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'submit_order')) {
    CSRF::verifyOrAbort();

    $submittedToken = trim($_POST['order_token'] ?? '');
    $pending = $_SESSION['pending_order'] ?? null;

    if (!$pending || empty($pending['service_id']) || empty($pending['link']) || empty($pending['quantity'])) {
        $error = "Order details are missing. Please configure your target service and link first.";
    } elseif (!empty($submittedToken) && !hash_equals((string)$pending['token'], $submittedToken)) {
        $error = "Order session expired or tampered. Please re-confirm your order.";
    } else {
        $serviceId = (int)$pending['service_id'];
        $link = (string)$pending['link'];
        $quantity = (int)$pending['quantity'];

        // Authoritative server-side service validation
        $svcStmt = $db->prepare("SELECT * FROM services WHERE id = :id AND status = 'active' LIMIT 1");
        $svcStmt->execute(['id' => $serviceId]);
        $service = $svcStmt->fetch();

        if (!$service) {
            $error = "Selected service is no longer active.";
        } elseif (!filter_var($link, FILTER_VALIDATE_URL)) {
            $error = "Invalid destination URL.";
        } elseif ($quantity < (int)$service['min_quantity'] || $quantity > (int)$service['max_quantity']) {
            $error = "Quantity out of allowed limits.";
        } else {
            // Recalculate authoritative charge on server
            $ratePer1000 = (float)$service['rate_per_1000'];
            $charge = round(($quantity / 1000) * $ratePer1000, 4);

            if ($user['balance'] < $charge) {
                $error = sprintf("Insufficient balance. Total charge is %s, but your available balance is %s. Please add funds.", format_currency($charge), format_currency($user['balance']));
            } else {
                // Atomic database execution
                $db->beginTransaction();
                try {
                    // 1. Deduct user wallet
                    $deduct = $db->prepare("UPDATE users SET balance = balance - :charge, spent = spent + :charge WHERE id = :uid AND balance >= :charge");
                    $deduct->execute(['charge' => $charge, 'uid' => $user['id']]);

                    if ($deduct->rowCount() === 0) {
                        throw new Exception("Balance deduction failed due to insufficient funds.");
                    }

                    // 2. Insert order
                    $insOrder = $db->prepare("
                        INSERT INTO orders (user_id, service_id, provider_id, link, quantity, charge, start_count, remains, status, mode)
                        VALUES (:uid, :sid, :pid, :link, :qty, :charge, 0, :remains, 'processing', 'auto')
                    ");
                    $insOrder->execute([
                        'uid' => $user['id'],
                        'sid' => $service['id'],
                        'pid' => $service['provider_id'] ?: null,
                        'link' => $link,
                        'qty' => $quantity,
                        'charge' => $charge,
                        'remains' => $quantity
                    ]);
                    $orderId = (int)$db->lastInsertId();

                    // 3. Dispatch to live provider if active provider mapping exists
                    $providerOrderId = null;
                    if (!empty($service['provider_id'])) {
                        $pStmt = $db->prepare("SELECT api_url, api_key, status FROM providers WHERE id = :pid LIMIT 1");
                        $pStmt->execute(['pid' => $service['provider_id']]);
                        $prov = $pStmt->fetch();

                        if ($prov && $prov['status'] === 'active' && !empty($service['provider_service_id'])) {
                            $apiResp = call_provider_api($prov['api_url'], [
                                'key' => $prov['api_key'],
                                'action' => 'add',
                                'service' => $service['provider_service_id'],
                                'link' => $link,
                                'quantity' => $quantity
                            ]);

                            if (!empty($apiResp['order'])) {
                                $providerOrderId = (string)$apiResp['order'];
                                $db->prepare("UPDATE orders SET provider_order_id = :poid, status = 'processing' WHERE id = :id")->execute([
                                    'poid' => $providerOrderId,
                                    'id' => $orderId
                                ]);
                            }
                        }
                    }

                    // 4. Record transaction ledger entry
                    $insTxn = $db->prepare("
                        INSERT INTO transactions (user_id, order_id, type, amount, gateway, gateway_txn_id, status, note)
                        VALUES (:uid, :oid, 'order', :amt, 'system', :txnid, 'completed', :note)
                    ");
                    $insTxn->execute([
                        'uid' => $user['id'],
                        'oid' => $orderId,
                        'amt' => -$charge,
                        'txnid' => 'ORD-' . $orderId,
                        'note' => 'Order #' . $orderId . ': ' . $service['name']
                    ]);

                    $db->commit();

                    // Clear pending order session
                    unset($_SESSION['pending_order']);

                    // Trigger referral commission if active
                    process_referral_commission((int)$user['id'], $charge, 'order', null, $orderId);

                    set_flash('success', "Order #{$orderId} placed successfully!");
                    redirect('/user/orders.php');
                } catch (\Throwable $e) {
                    $db->rollBack();
                    $error = "Failed to place order: " . $e->getMessage();
                }
            }
        }
    }
}

// Current active pending order state
$pendingOrder = $_SESSION['pending_order'] ?? null;
$selectedService = null;
$finalCharge = 0.0;

if ($pendingOrder && !empty($pendingOrder['service_id'])) {
    foreach ($allServices as $s) {
        if ((int)$s['id'] === (int)$pendingOrder['service_id']) {
            $selectedService = $s;
            break;
        }
    }
    if ($selectedService) {
        $finalCharge = round(((int)$pendingOrder['quantity'] / 1000) * (float)$selectedService['rate_per_1000'], 4);
    }
}

// If no pending order is ready yet, initialize with default values for preview
if (!$selectedService && !empty($allServices)) {
    $selectedService = $allServices[0];
    $defaultQty = (int)$selectedService['min_quantity'];
    $finalCharge = round(($defaultQty / 1000) * (float)$selectedService['rate_per_1000'], 4);
}

// Check if user requested to edit details or has no configured destination link
$showConfigStep = isset($_GET['edit']) || empty($pendingOrder['link']);

$pageTitle = "New Order - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Cyan / Turquoise Ambient Background Surroundings -->
<div class="fixed inset-0 pointer-events-none -z-10 bg-gradient-to-br from-[#cffafe]/40 via-[#ccfbf1]/30 to-[#f0fdf4]/50">
    <div class="absolute -top-32 -left-32 w-96 h-96 rounded-full bg-cyan-200/30 blur-3xl"></div>
    <div class="absolute top-1/3 -right-32 w-96 h-96 rounded-full bg-teal-200/25 blur-3xl"></div>
    <div class="absolute -bottom-32 left-1/4 w-96 h-96 rounded-full bg-emerald-200/25 blur-3xl"></div>
</div>

<div class="max-w-2xl mx-auto my-4 sm:my-8 px-4 space-y-6">
    <!-- Top Bar with Wallet Balance -->
    <div class="flex items-center justify-between">
        <a href="/user/dashboard.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Back to Dashboard</span>
        </a>
        <div class="text-xs font-bold text-slate-500">
            Available Balance: <strong class="text-slate-900 font-mono-nums text-sm"><?= format_currency($user['balance']) ?></strong>
        </div>
    </div>

    <?= render_flash() ?>

    <?php if ($error): ?>
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold flex items-center gap-2">
            <span>⚠️</span>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($showConfigStep): ?>
        <!-- STEP 1: CONFIGURE TARGET SERVICE & LINK -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-teal-100 shadow-xl space-y-5">
            <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-black text-[#0f2d59] tracking-tight">Configure New Order</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Select service, destination URL, and quantity to calculate charge</p>
                </div>
                <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-cyan-50 text-cyan-800 border border-cyan-200">
                    Step 1 of 2
                </span>
            </div>

            <form action="/user/new-order.php" method="POST" class="space-y-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="configure">

                <div>
                    <label class="block text-xs font-extrabold text-[#0f2d59] uppercase tracking-wider mb-2">Service</label>
                    <select name="service_id" id="configService" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs sm:text-sm font-semibold text-slate-800 focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-600 cursor-pointer">
                        <?php foreach ($allServices as $s): ?>
                            <option value="<?= $s['id'] ?>"
                                    <?= ($selectedService && (int)$selectedService['id'] === (int)$s['id']) ? 'selected' : '' ?>
                                    data-min="<?= (int)$s['min_quantity'] ?>"
                                    data-max="<?= (int)$s['max_quantity'] ?>">
                                [<?= ucfirst($s['platform']) ?>] <?= e($s['name']) ?> — <?= format_currency($s['rate_per_1000']) ?> / 1K (Min: <?= number_format($s['min_quantity']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-extrabold text-[#0f2d59] uppercase tracking-wider mb-2">Target Profile or Post URL</label>
                    <input type="url" name="link" required placeholder="https://instagram.com/yourusername" value="<?= e($preLink) ?>" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs sm:text-sm font-medium focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-600">
                </div>

                <div>
                    <label class="block text-xs font-extrabold text-[#0f2d59] uppercase tracking-wider mb-2">Order Quantity</label>
                    <input type="number" name="quantity" required value="<?= $preQuantity > 0 ? $preQuantity : ((int)($selectedService['min_quantity'] ?? 1000)) ?>" min="1" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs sm:text-sm font-bold font-mono-nums focus:ring-2 focus:ring-cyan-500/20 focus:border-cyan-600">
                </div>

                <div class="pt-3 border-t border-slate-100 flex items-center justify-end">
                    <button type="submit" class="px-7 py-3 bg-gradient-to-r from-blue-600 to-cyan-600 hover:from-blue-700 hover:to-cyan-700 text-white font-black text-xs sm:text-sm rounded-2xl shadow-lg shadow-cyan-500/25 active:scale-95 transition-all cursor-pointer">
                        Continue to Checkout &rarr;
                    </button>
                </div>
            </form>
        </div>

    <?php else: ?>

        <!-- STEP 2: SIMPLIFIED NEW ORDER FORM (EXACT MATCH TO USER SPECIFICATION) -->
        <!-- Form contains ONLY: 1. Charge, 2. Submit button -->
        <!-- White rounded form panel, pale mint input background, dark blue labels, large rounded Submit button -->
        <div class="bg-white rounded-3xl p-6 sm:p-10 shadow-2xl border border-teal-100/80 max-w-md w-full mx-auto relative space-y-6">
            
            <form action="/user/new-order.php" method="POST" id="simplifiedOrderForm" class="space-y-6">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="submit_order">
                <input type="hidden" name="order_token" value="<?= e($pendingOrder['token'] ?? '') ?>">

                <!-- 1. CHARGE FIELD ONLY -->
                <div>
                    <label class="block text-sm font-black text-[#0f2d59] tracking-tight mb-2">Charge</label>
                    <input type="text" 
                           id="chargeDisplay"
                           readonly 
                           value="<?= e(format_currency($finalCharge)) ?>" 
                           class="w-full px-5 py-4 rounded-2xl bg-[#e8f5e9] border border-[#c8e6c9] text-[#0f2d59] font-black text-xl font-mono-nums focus:outline-none cursor-default select-all shadow-inner">
                </div>

                <!-- 2. SUBMIT BUTTON ONLY -->
                <button type="submit" 
                        id="btnSubmitOrder"
                        class="w-full py-4 px-6 rounded-2xl bg-gradient-to-r from-[#0284c7] to-[#0ea5e9] hover:from-[#0369a1] hover:to-[#0284c7] text-white font-black text-base shadow-lg shadow-sky-500/25 active:scale-95 transition-all cursor-pointer text-center block">
                    Submit
                </button>
            </form>

            <!-- Order Context Preview / Modification Control -->
            <div class="pt-4 border-t border-slate-100 flex items-center justify-between text-xs text-slate-400">
                <div class="truncate max-w-[200px]" title="<?= e($selectedService['name'] ?? '') ?> &bull; <?= number_format($pendingOrder['quantity'] ?? 0) ?> units">
                    <span class="font-bold text-slate-700"><?= e($selectedService['name'] ?? 'Selected Service') ?></span>
                    <span class="block text-[11px] font-mono text-slate-400"><?= number_format($pendingOrder['quantity'] ?? 0) ?> units &bull; <?= e(substr($pendingOrder['link'] ?? '', 0, 25)) ?>...</span>
                </div>
                <a href="/user/new-order.php?edit=1" class="text-xs font-bold text-blue-600 hover:text-blue-700 hover:underline shrink-0">
                    Change Details
                </a>
            </div>
        </div>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
