<?php
/**
 * New Order Page
 * SMM Panel - PHP 8.3+
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

// Fetch categories and services
$categoriesStmt = $db->query("SELECT * FROM categories WHERE status = 'active' ORDER BY sort_order ASC");
$categories = $categoriesStmt->fetchAll();

$servicesStmt = $db->query("SELECT * FROM services WHERE status = 'active' ORDER BY category_id, id ASC");
$services = $servicesStmt->fetchAll();

$preselectedServiceId = (int)($_GET['service_id'] ?? 0);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();

    $serviceId = (int)($_POST['service_id'] ?? 0);
    $link = trim($_POST['link'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 0);

    // Validate service
    $stmt = $db->prepare("SELECT * FROM services WHERE id = :id AND status = 'active' LIMIT 1");
    $stmt->execute(['id' => $serviceId]);
    $service = $stmt->fetch();

    if (!$service) {
        $error = "Selected service is invalid or unavailable.";
    } elseif (empty($link) || !filter_var($link, FILTER_VALIDATE_URL)) {
        $error = "Please enter a valid target URL (e.g., https://instagram.com/profile).";
    } elseif ($quantity < $service['min_quantity'] || $quantity > $service['max_quantity']) {
        $error = sprintf("Quantity must be between %s and %s.", number_format($service['min_quantity']), number_format($service['max_quantity']));
    } else {
        // Calculate total cost
        $ratePer1000 = (float)$service['rate_per_1000'];
        $charge = round(($quantity / 1000) * $ratePer1000, 4);

        if ($user['balance'] < $charge) {
            $error = sprintf("Insufficient balance. Total cost is %s, but your balance is %s. Please add funds.", format_currency($charge), format_currency($user['balance']));
        } else {
            // Begin atomic transaction
            $db->beginTransaction();
            try {
                // 1. Deduct balance from user
                $deduct = $db->prepare("UPDATE users SET balance = balance - :charge, spent = spent + :charge WHERE id = :uid AND balance >= :charge");
                $deduct->execute(['charge' => $charge, 'uid' => $user['id']]);

                if ($deduct->rowCount() === 0) {
                    throw new Exception("Balance deduction failed due to insufficient funds.");
                }

                // 2. Insert order
                $insertOrder = $db->prepare("
                    INSERT INTO orders (user_id, service_id, provider_id, link, quantity, charge, start_count, remains, status, mode)
                    VALUES (:uid, :sid, :pid, :link, :qty, :charge, 0, :remains, 'processing', 'auto')
                ");
                $insertOrder->execute([
                    'uid' => $user['id'],
                    'sid' => $service['id'],
                    'pid' => $service['provider_id'] ?: null,
                    'link' => $link,
                    'qty' => $quantity,
                    'charge' => $charge,
                    'remains' => $quantity
                ]);

                $orderId = (int)$db->lastInsertId();

                // 3. Insert transaction ledger entry
                $insertTxn = $db->prepare("
                    INSERT INTO transactions (user_id, order_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, :oid, 'order', :amount, 'system', :txnid, 'completed', :note)
                ");
                $insertTxn->execute([
                    'uid' => $user['id'],
                    'oid' => $orderId,
                    'amount' => -$charge,
                    'txnid' => 'ORD-' . $orderId,
                    'note' => 'Order #' . $orderId . ': ' . $service['name']
                ]);

                $db->commit();
                set_flash('success', "Order #{$orderId} placed successfully!");
                redirect('/user/orders.php');
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to place order: " . $e->getMessage();
            }
        }
    }
}

$pageTitle = "New Order - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto my-4">
    <!-- Breadcrumb / Back button -->
    <div class="mb-4 flex items-center justify-between">
        <a href="/user/dashboard.php" class="flex items-center gap-2 text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            &larr; Back to Dashboard
        </a>
        <div class="text-xs text-slate-500">
            Available Balance: <strong class="text-slate-900 tabular-nums"><?= format_currency($user['balance']) ?></strong>
        </div>
    </div>

    <!-- Main Card -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl">
        <!-- Step Indicator -->
        <div class="flex items-center justify-between max-w-md mx-auto mb-8 border-b border-slate-100 pb-4">
            <div class="flex items-center gap-2 text-xs font-bold text-blue-600">
                <span class="w-6 h-6 rounded-full bg-blue-600 text-white flex items-center justify-center text-xs">1</span>
                <span>Select Service</span>
            </div>
            <div class="flex items-center gap-2 text-xs font-semibold text-slate-400">
                <span class="w-6 h-6 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center text-xs">2</span>
                <span>Link Info</span>
            </div>
            <div class="flex items-center gap-2 text-xs font-semibold text-slate-400">
                <span class="w-6 h-6 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center text-xs">3</span>
                <span>Payment</span>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form action="/user/new-order.php" method="POST" id="orderForm" class="space-y-6">
            <?= CSRF::field() ?>

            <!-- Platform Filters -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-2">Platform</label>
                <div class="flex flex-wrap gap-2" id="platformPills">
                    <?php
                    $platforms = ['instagram' => 'Instagram', 'youtube' => 'YouTube', 'telegram' => 'Telegram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'twitter' => 'Twitter (X)'];
                    $firstPlat = true;
                    foreach ($platforms as $pKey => $pLabel): ?>
                        <button type="button" onclick="selectPlatform('<?= $pKey ?>')" data-platform="<?= $pKey ?>" class="platform-btn px-4 py-2 rounded-xl text-xs font-bold border transition-all <?= $firstPlat ? 'bg-blue-600 border-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 border-slate-200 text-slate-700 hover:bg-slate-100' ?>">
                            <?= $pLabel ?>
                        </button>
                    <?php $firstPlat = false; endforeach; ?>
                </div>
            </div>

            <!-- Service Dropdown -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-2">Service</label>
                <select name="service_id" id="serviceSelect" required onchange="updateServiceDetails()" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                    <?php foreach ($services as $svc): 
                        $isSelected = ($preselectedServiceId > 0 && $preselectedServiceId === (int)$svc['id']);
                    ?>
                        <option value="<?= $svc['id'] ?>"
                            <?= $isSelected ? 'selected' : '' ?>
                            data-rate="<?= (float)$svc['rate_per_1000'] ?>"
                            data-min="<?= (int)$svc['min_quantity'] ?>"
                            data-max="<?= (int)$svc['max_quantity'] ?>"
                            data-speed="<?= e($svc['speed']) ?>"
                            data-desc="<?= e($svc['description']) ?>">
                            <?= e($svc['name']) ?> — <?= format_currency($svc['rate_per_1000']) ?> / 1K
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Service Details Box -->
            <div id="serviceDetailsBox" class="p-4 rounded-2xl bg-gradient-to-r from-blue-50 to-indigo-50/50 border border-blue-100 flex items-center justify-between">
                <div>
                    <span id="badgeRate" class="text-xl font-extrabold text-blue-700">₹35.00 / 1K</span>
                    <p id="badgeSpeed" class="text-xs font-semibold text-emerald-600 mt-0.5">⚡ Fast Delivery</p>
                </div>
                <div class="text-right text-xs text-slate-500">
                    <div>Min: <strong id="lblMin" class="text-slate-800">1,000</strong></div>
                    <div>Max: <strong id="lblMax" class="text-slate-800">1,000,000</strong></div>
                </div>
            </div>

            <!-- Quantity Counter Stepper -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-2">Quantity</label>
                <div class="flex items-center gap-3">
                    <button type="button" onclick="adjustQty(-500)" class="w-12 h-12 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-lg font-bold flex items-center justify-center transition-colors">-</button>
                    <input type="number" name="quantity" id="quantityInput" value="1000" min="10" max="10000000" required oninput="calculatePrice()" class="flex-1 py-3 px-4 text-center font-bold text-base bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                    <button type="button" onclick="adjustQty(500)" class="w-12 h-12 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-lg font-bold flex items-center justify-center transition-colors">+</button>
                </div>
            </div>

            <!-- Target Link -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-2">Target Link / URL</label>
                <input type="url" name="link" id="linkInput" placeholder="https://instagram.com/yourusername" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                <span class="text-[11px] text-slate-400 mt-1 block">Make sure your social media account is set to public.</span>
            </div>

            <!-- Total Price & Submit Bar -->
            <div class="pt-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div>
                    <span class="text-xs text-slate-500 font-medium block">Total Price:</span>
                    <span id="totalPrice" class="text-3xl font-extrabold text-slate-900 tabular-nums">₹35.00</span>
                </div>
                <button type="submit" class="w-full sm:w-auto px-8 py-4 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-sm rounded-2xl shadow-xl shadow-blue-500/30 transition-all">
                    Place Order &rarr;
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function updateServiceDetails() {
    const sel = document.getElementById('serviceSelect');
    const opt = sel.options[sel.selectedIndex];
    if (!opt) return;

    const rate = parseFloat(opt.getAttribute('data-rate') || 0);
    const min = parseInt(opt.getAttribute('data-min') || 100);
    const max = parseInt(opt.getAttribute('data-max') || 10000);
    const speed = opt.getAttribute('data-speed') || 'Instant Start';

    document.getElementById('badgeRate').innerText = '<?= e(app_currency()) ?>' + rate.toFixed(2) + ' / 1K';
    document.getElementById('badgeSpeed').innerText = '⚡ ' + speed;
    document.getElementById('lblMin').innerText = min.toLocaleString();
    document.getElementById('lblMax').innerText = max.toLocaleString();

    const qtyInput = document.getElementById('quantityInput');
    qtyInput.min = min;
    qtyInput.max = max;
    if (parseInt(qtyInput.value) < min) {
        qtyInput.value = min;
    }
    calculatePrice();
}

function adjustQty(amount) {
    const input = document.getElementById('quantityInput');
    let val = (parseInt(input.value) || 0) + amount;
    const min = parseInt(input.min) || 10;
    const max = parseInt(input.max) || 1000000;
    if (val < min) val = min;
    if (val > max) val = max;
    input.value = val;
    calculatePrice();
}

function calculatePrice() {
    const sel = document.getElementById('serviceSelect');
    const opt = sel.options[sel.selectedIndex];
    const qty = parseInt(document.getElementById('quantityInput').value) || 0;
    if (!opt) return;

    const rate = parseFloat(opt.getAttribute('data-rate') || 0);
    const total = (qty / 1000) * rate;
    document.getElementById('totalPrice').innerText = '<?= e(app_currency()) ?>' + total.toFixed(2);
}

function selectPlatform(plat) {
    document.querySelectorAll('.platform-btn').forEach(btn => {
        if (btn.getAttribute('data-platform') === plat) {
            btn.className = 'platform-btn px-4 py-2 rounded-xl text-xs font-bold border transition-all bg-blue-600 border-blue-600 text-white shadow-md shadow-blue-500/20';
        } else {
            btn.className = 'platform-btn px-4 py-2 rounded-xl text-xs font-bold border transition-all bg-slate-50 border-slate-200 text-slate-700 hover:bg-slate-100';
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    updateServiceDetails();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
