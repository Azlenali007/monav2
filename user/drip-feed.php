<?php
/**
 * User Drip-feed Order Portal
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

$error = null;

// Fetch active services
$servicesStmt = $db->query("
    SELECT s.*, c.name AS category_name, c.platform 
    FROM services s 
    JOIN categories c ON s.category_id = c.id 
    WHERE s.status = 'active' 
    ORDER BY c.sort_order ASC, s.id ASC
");
$services = $servicesStmt->fetchAll();

// Handle Drip-feed submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();

    $serviceId = (int)($_POST['service_id'] ?? 0);
    $link = trim($_POST['link'] ?? '');
    $qtyPerRun = (int)($_POST['quantity_per_run'] ?? 0);
    $runs = (int)($_POST['runs'] ?? 0);
    $interval = (int)($_POST['interval'] ?? 0); // minutes

    $svcStmt = $db->prepare("SELECT * FROM services WHERE id = :id AND status = 'active' LIMIT 1");
    $svcStmt->execute(['id' => $serviceId]);
    $service = $svcStmt->fetch();

    if (!$service) {
        $error = "Selected service is invalid or unavailable.";
    } elseif (empty($link) || !filter_var($link, FILTER_VALIDATE_URL)) {
        $error = "Please enter a valid target URL.";
    } elseif ($qtyPerRun < (int)$service['min_quantity'] || $qtyPerRun > (int)$service['max_quantity']) {
        $error = "Quantity per run must be between {$service['min_quantity']} and {$service['max_quantity']}.";
    } elseif ($runs < 2 || $runs > 100) {
        $error = "Drip-feed runs must be between 2 and 100 cycles.";
    } elseif ($interval < 10 || $interval > 1440) {
        $error = "Interval must be between 10 minutes and 1440 minutes (24 hours).";
    } else {
        $totalQuantity = $qtyPerRun * $runs;
        $charge = round(($totalQuantity / 1000) * (float)$service['rate_per_1000'], 4);

        if ($user['balance'] < $charge) {
            $error = "Insufficient balance. Total cost is " . format_currency($charge) . ", but your balance is " . format_currency($user['balance']) . ".";
        } else {
            $db->beginTransaction();
            try {
                // Deduct balance atomically
                $deduct = $db->prepare("UPDATE users SET balance = balance - :charge, spent = spent + :charge WHERE id = :uid AND balance >= :charge");
                $deduct->execute(['charge' => $charge, 'uid' => $user['id']]);

                if ($deduct->rowCount() === 0) {
                    throw new Exception("Balance deduction failed.");
                }

                $note = "Drip-feed: {$runs} runs x {$qtyPerRun} every {$interval}m";

                // Insert primary parent order
                $insOrder = $db->prepare("
                    INSERT INTO orders (user_id, service_id, provider_id, link, quantity, charge, start_count, remains, status, mode)
                    VALUES (:uid, :sid, :pid, :link, :qty, :charge, 0, :remains, 'processing', 'auto')
                ");
                $insOrder->execute([
                    'uid' => $user['id'],
                    'sid' => $service['id'],
                    'pid' => $service['provider_id'] ?: null,
                    'link' => $link,
                    'qty' => $totalQuantity,
                    'charge' => $charge,
                    'remains' => $totalQuantity
                ]);
                $orderId = (int)$db->lastInsertId();

                // Insert transaction
                $insTxn = $db->prepare("
                    INSERT INTO transactions (user_id, order_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, :oid, 'order', :amt, 'system', :txnid, 'completed', :note)
                ");
                $insTxn->execute([
                    'uid' => $user['id'],
                    'oid' => $orderId,
                    'amt' => -$charge,
                    'txnid' => 'ORD-' . $orderId,
                    'note' => 'Order #' . $orderId . ': ' . $service['name'] . ' (' . $note . ')'
                ]);

                $db->commit();

                // Trigger referral commission if eligible
                process_referral_commission((int)$user['id'], $charge, 'order', null, $orderId);

                set_flash('success', "Drip-feed Order #{$orderId} scheduled successfully! ({$totalQuantity} total over {$runs} intervals)");
                redirect('/user/orders.php');
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to schedule drip-feed order: " . $e->getMessage();
            }
        }
    }
}

$pageTitle = "Drip-feed Order - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-3xl mx-auto my-6 space-y-6">
    <div class="flex items-center justify-between">
        <a href="/user/dashboard.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Back to Dashboard</span>
        </a>
        <div class="text-xs font-bold text-slate-500">
            Current Balance: <strong class="text-slate-900 font-mono-nums text-sm"><?= format_currency($user['balance']) ?></strong>
        </div>
    </div>

    <?= render_flash() ?>

    <?php if ($error): ?>
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold flex items-center gap-2">
            <span>⚠️</span>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Drip-feed Order Form Card -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div>
            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-extrabold bg-blue-50 text-blue-700 border border-blue-200 mb-2">
                <span>💧</span> Automated Interval Delivery
            </div>
            <h1 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">Drip-feed Order</h1>
            <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                Gradually deliver followers, likes, or views in controlled bursts over hours or days for completely organic-looking growth metrics.
            </p>
        </div>

        <form action="/user/drip-feed.php" method="POST" id="dripfeedForm" class="space-y-5">
            <?= CSRF::field() ?>

            <!-- Service Select -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1.5">Select Service</label>
                <select name="service_id" id="serviceSelect" required onchange="calculateDripPrice()" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-bold text-slate-900 focus:ring-2 focus:ring-blue-500">
                    <?php foreach ($services as $svc): ?>
                        <option value="<?= $svc['id'] ?>"
                                data-rate="<?= (float)$svc['rate_per_1000'] ?>"
                                data-user-rate="<?= (float)get_user_rate($svc['rate_per_1000']) ?>"
                                data-min="<?= (int)$svc['min_quantity'] ?>"
                                data-max="<?= (int)$svc['max_quantity'] ?>">
                            [<?= ucfirst($svc['platform']) ?>] <?= e($svc['name']) ?> — <?= format_currency($svc['rate_per_1000']) ?> / 1K
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Target Link -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1.5">Target URL / Link</label>
                <input type="url" name="link" required placeholder="https://instagram.com/myusername or post link" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-mono text-slate-900 focus:ring-2 focus:ring-blue-500">
            </div>

            <!-- Dripfeed Parameters Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Quantity per Run <span class="text-rose-500">*</span></label>
                    <input type="number" id="qtyPerRun" name="quantity_per_run" value="100" min="10" required oninput="calculateDripPrice()" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold">
                    <span class="text-[10px] text-slate-400 mt-0.5 block">Amount per cycle</span>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Runs Count <span class="text-rose-500">*</span></label>
                    <input type="number" id="runsCount" name="runs" value="5" min="2" max="100" required oninput="calculateDripPrice()" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold">
                    <span class="text-[10px] text-slate-400 mt-0.5 block">Total delivery cycles</span>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Interval (Minutes) <span class="text-rose-500">*</span></label>
                    <input type="number" name="interval" value="60" min="10" max="1440" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold">
                    <span class="text-[10px] text-slate-400 mt-0.5 block">Pause between runs</span>
                </div>
            </div>

            <!-- Calculation Showcase Card -->
            <div class="p-5 rounded-2xl bg-blue-50/60 border border-blue-200/80 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div>
                    <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wider block">Estimated Total Order Details</span>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="text-xs font-extrabold text-slate-800" id="totalQtyDisplay">500 Total Qty</span>
                        <span>•</span>
                        <span class="text-xs text-slate-500" id="runsDisplay">5 runs x 100</span>
                    </div>
                </div>
                <div class="text-right">
                    <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wider block">Total Estimated Charge</span>
                    <div class="text-2xl font-black text-blue-700 font-mono-nums" id="totalChargeDisplay">
                        <?= format_currency(0) ?>
                    </div>
                </div>
            </div>

            <div class="pt-2">
                <button type="submit" class="w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-black text-xs rounded-xl shadow-md shadow-blue-500/25 active:scale-95 transition-all cursor-pointer">
                    ⚡ Schedule Drip-feed Order Now &rarr;
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function calculateDripPrice() {
    const sel = document.getElementById('serviceSelect');
    const opt = sel.options[sel.selectedIndex];
    if (!opt) return;

    const userRate = parseFloat(opt.getAttribute('data-user-rate') || 0);
    const qty = parseInt(document.getElementById('qtyPerRun').value) || 0;
    const runs = parseInt(document.getElementById('runsCount').value) || 0;

    const totalQty = qty * runs;
    const charge = (totalQty / 1000) * userRate;

    document.getElementById('totalQtyDisplay').innerText = totalQty.toLocaleString() + ' Total Qty';
    document.getElementById('runsDisplay').innerText = runs + ' runs x ' + qty.toLocaleString();
    document.getElementById('totalChargeDisplay').innerText = '<?= e(app_currency()) ?>' + charge.toFixed(2);
}

document.addEventListener('DOMContentLoaded', () => {
    calculateDripPrice();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
