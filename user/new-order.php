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

// Fetch categories and services with platform metadata
$categoriesStmt = $db->query("SELECT * FROM categories WHERE status = 'active' ORDER BY sort_order ASC, name ASC");
$categories = $categoriesStmt->fetchAll();

$servicesStmt = $db->query("
    SELECT s.*, c.platform, c.name AS category_name 
    FROM services s 
    JOIN categories c ON s.category_id = c.id 
    WHERE s.status = 'active' 
    ORDER BY c.sort_order ASC, s.rate_per_1000 ASC, s.id ASC
");
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

                // Trigger referral commission if order event enabled
                process_referral_commission((int)$user['id'], $charge, 'order', null, $orderId);

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

<div class="max-w-4xl mx-auto my-4 space-y-6">
    <!-- Header navigation bar -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white/80 backdrop-blur-md p-4 sm:p-5 rounded-3xl border border-slate-200/80 shadow-xs">
        <a href="/user/dashboard.php" class="inline-flex items-center gap-2 text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Back to Dashboard</span>
        </a>
        <div class="flex items-center gap-3">
            <div class="text-xs text-slate-500">
                Wallet Balance: <strong id="userBalanceDisplay" data-balance="<?= (float)$user['balance'] ?>" class="text-slate-900 font-extrabold tabular-nums font-mono-nums"><?= format_currency($user['balance']) ?></strong>
            </div>
            <a href="/user/add-funds.php" class="px-3 py-1.5 rounded-xl bg-blue-50 text-blue-600 hover:bg-blue-100 text-xs font-bold transition-colors">
                + Top Up
            </a>
        </div>
    </div>

    <!-- Main Order Form Card -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl relative overflow-hidden">
        <!-- Step Progress Indicator -->
        <div class="flex items-center justify-between max-w-md mx-auto mb-8 border-b border-slate-100 pb-5">
            <div class="flex items-center gap-2 text-xs font-extrabold text-blue-600">
                <span class="w-6 h-6 rounded-full bg-blue-600 text-white flex items-center justify-center text-xs shadow-xs">1</span>
                <span>Platform & Service</span>
            </div>
            <div class="w-8 h-0.5 bg-blue-200"></div>
            <div class="flex items-center gap-2 text-xs font-bold text-slate-500">
                <span class="w-6 h-6 rounded-full bg-slate-100 text-slate-700 flex items-center justify-center text-xs">2</span>
                <span>Target & Quantity</span>
            </div>
            <div class="w-8 h-0.5 bg-slate-200"></div>
            <div class="flex items-center gap-2 text-xs font-bold text-slate-400">
                <span class="w-6 h-6 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center text-xs">3</span>
                <span>Checkout</span>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="mb-6 p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold flex items-center gap-2">
                <span>⚠️</span>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form action="/user/new-order.php" method="POST" id="orderForm" class="space-y-6">
            <?= CSRF::field() ?>

            <!-- Platform Filters with Authentic SVG Icons -->
            <div>
                <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-2.5">1. Select Social Platform</label>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-2.5" id="platformPills">
                    <?php
                    $platformMeta = [
                        'all' => ['name' => 'All Platforms', 'icon' => '⚡', 'color' => 'blue'],
                        'instagram' => ['name' => 'Instagram', 'icon' => '📷', 'color' => 'pink'],
                        'youtube' => ['name' => 'YouTube', 'icon' => '▶️', 'color' => 'red'],
                        'telegram' => ['name' => 'Telegram', 'icon' => '✈️', 'color' => 'sky'],
                        'facebook' => ['name' => 'Facebook', 'icon' => '👍', 'color' => 'blue'],
                        'tiktok' => ['name' => 'TikTok', 'icon' => '🎵', 'color' => 'slate']
                    ];
                    $first = true;
                    foreach ($platformMeta as $pKey => $pData): 
                    ?>
                        <button type="button" 
                                onclick="filterByPlatform('<?= $pKey ?>')" 
                                data-platform="<?= $pKey ?>" 
                                class="platform-btn p-3 rounded-2xl text-xs font-bold border transition-all flex flex-col items-center gap-1.5 <?= $first ? 'bg-blue-600 border-blue-600 text-white shadow-md shadow-blue-500/25 scale-[1.02]' : 'bg-slate-50 border-slate-200/80 text-slate-700 hover:bg-slate-100' ?>">
                            <span class="text-base"><?= $pData['icon'] ?></span>
                            <span class="tracking-tight text-center leading-tight"><?= $pData['name'] ?></span>
                        </button>
                    <?php $first = false; endforeach; ?>
                </div>
            </div>

            <!-- Service Dropdown -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider">2. Choose Specific Service</label>
                    <span id="serviceCountBadge" class="text-[11px] font-bold text-slate-400"><?= count($services) ?> available</span>
                </div>
                <select name="service_id" id="serviceSelect" required onchange="updateServiceDetails()" class="w-full px-4 py-3.5 bg-slate-50 hover:bg-slate-100/60 border border-slate-200 rounded-2xl text-sm font-semibold text-slate-800 focus:ring-3 focus:ring-blue-500/20 focus:border-blue-600 transition-all cursor-pointer">
                    <?php foreach ($services as $svc): 
                        $isSelected = ($preselectedServiceId > 0 && $preselectedServiceId === (int)$svc['id']);
                        $svcPlat = strtolower($svc['platform'] ?? 'other');
                    ?>
                        <option value="<?= $svc['id'] ?>"
                            <?= $isSelected ? 'selected' : '' ?>
                            data-platform="<?= e($svcPlat) ?>"
                            data-rate="<?= (float)$svc['rate_per_1000'] ?>"
                            data-min="<?= (int)$svc['min_quantity'] ?>"
                            data-max="<?= (int)$svc['max_quantity'] ?>"
                            data-speed="<?= e($svc['speed']) ?>"
                            data-category="<?= e($svc['category_name']) ?>"
                            data-desc="<?= e($svc['description']) ?>">
                            [<?= ucfirst($svcPlat) ?>] <?= e($svc['name']) ?> — <?= format_currency($svc['rate_per_1000']) ?> / 1K
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Service Details Showcase Card -->
            <div id="serviceDetailsBox" class="p-5 rounded-3xl bg-gradient-to-br from-blue-50 via-white to-indigo-50/50 border border-blue-200/80 shadow-xs space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-blue-100">
                    <div>
                        <div class="flex items-center gap-2">
                            <span id="lblCategory" class="text-xs font-bold text-blue-600 uppercase tracking-wider">Social Growth</span>
                            <span class="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-600 bg-emerald-50 px-2.5 py-0.5 rounded-full border border-emerald-200/80">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Guaranteed
                            </span>
                        </div>
                        <h4 id="lblServiceName" class="text-base font-extrabold text-slate-900 mt-0.5">High Quality Followers</h4>
                    </div>
                    <div class="text-left sm:text-right">
                        <span id="badgeRate" class="text-2xl font-black text-blue-700 tabular-nums">₹35.00 / 1K</span>
                        <p id="badgeSpeed" class="text-xs font-semibold text-emerald-600 mt-0.5">⚡ Instant Start (0-15 mins)</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center pt-1">
                    <div class="bg-white/90 p-2.5 rounded-2xl border border-blue-100/80">
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">Min Qty</span>
                        <strong id="lblMin" class="text-xs sm:text-sm font-extrabold text-slate-800">100</strong>
                    </div>
                    <div class="bg-white/90 p-2.5 rounded-2xl border border-blue-100/80">
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">Max Qty</span>
                        <strong id="lblMax" class="text-xs sm:text-sm font-extrabold text-slate-800">1,000,000</strong>
                    </div>
                    <div class="bg-white/90 p-2.5 rounded-2xl border border-blue-100/80">
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">Speed</span>
                        <strong id="lblSpeedText" class="text-xs sm:text-sm font-extrabold text-slate-800">Fast</strong>
                    </div>
                    <div class="bg-white/90 p-2.5 rounded-2xl border border-blue-100/80">
                        <span class="text-[10px] uppercase font-bold text-slate-400 block">Refill</span>
                        <strong class="text-xs sm:text-sm font-extrabold text-emerald-600">30 Days</strong>
                    </div>
                </div>

                <p id="lblDescription" class="text-xs text-slate-500 font-normal leading-relaxed pt-1"></p>
            </div>

            <!-- Target Link Input -->
            <div>
                <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-2">3. Target URL / Profile Link</label>
                <div class="relative">
                    <input type="url" name="link" id="linkInput" placeholder="https://instagram.com/yourusername" required class="w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-2xl text-sm font-medium focus:ring-3 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                    <span class="absolute left-4 top-3.5 text-slate-400 text-sm">🔗</span>
                </div>
                <div class="flex items-center gap-2 mt-1.5 text-[11px] text-slate-400">
                    <span>💡</span>
                    <span>Ensure target account or post is public during delivery. Do not place multiple orders on the same link simultaneously.</span>
                </div>
            </div>

            <!-- Quantity Counter with Presets -->
            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider">4. Desired Quantity</label>
                    <span class="text-xs text-slate-400 font-medium">Use stepper or quick presets</span>
                </div>

                <div class="flex items-center gap-3">
                    <button type="button" onclick="adjustQty(-500)" class="w-13 h-12 rounded-2xl bg-slate-100 hover:bg-slate-200 active:scale-95 text-slate-700 text-xl font-black flex items-center justify-center transition-all cursor-pointer">-</button>
                    <input type="number" name="quantity" id="quantityInput" value="1000" min="10" max="10000000" required oninput="calculatePrice()" class="flex-1 py-3 px-4 text-center font-black text-lg bg-slate-50 border border-slate-200 rounded-2xl focus:ring-3 focus:ring-blue-500/20 focus:border-blue-600 font-mono-nums">
                    <button type="button" onclick="adjustQty(500)" class="w-13 h-12 rounded-2xl bg-slate-100 hover:bg-slate-200 active:scale-95 text-slate-700 text-xl font-black flex items-center justify-center transition-all cursor-pointer">+</button>
                </div>

                <!-- Quick Presets -->
                <div class="flex flex-wrap items-center gap-2 mt-3">
                    <span class="text-[11px] font-bold text-slate-400 mr-1">Presets:</span>
                    <?php foreach ([100, 500, 1000, 2500, 5000, 10000] as $preset): ?>
                        <button type="button" onclick="setPresetQty(<?= $preset ?>)" class="px-3 py-1 rounded-xl bg-slate-100 hover:bg-blue-50 hover:text-blue-600 text-slate-600 text-xs font-bold transition-colors">
                            +<?= number_format($preset) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Total Price & Order Submit Bar -->
            <div class="pt-6 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-5">
                <div>
                    <span class="text-xs text-slate-400 uppercase tracking-wider font-bold block">Estimated Charge:</span>
                    <div class="flex items-baseline gap-2">
                        <span id="totalPrice" class="text-3xl sm:text-4xl font-black text-slate-900 tabular-nums font-mono-nums">₹35.00</span>
                        <span class="text-xs text-slate-400 font-semibold" id="priceSubtext">incl. all fees</span>
                    </div>
                    <p id="insufficientWarning" class="text-xs font-bold text-rose-600 mt-1 hidden">
                        ⚠️ Insufficient balance. <a href="/user/add-funds.php" class="underline">Add funds now</a>
                    </p>
                </div>
                <button type="submit" id="submitOrderBtn" class="w-full sm:w-auto px-10 py-4 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 active:scale-95 text-white font-black text-sm rounded-2xl shadow-xl shadow-blue-500/30 transition-all flex items-center justify-center gap-2 cursor-pointer">
                    <span>⚡ Place Order Now</span>
                    <span>&rarr;</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let currentPlatformFilter = 'all';

function updateServiceDetails() {
    const sel = document.getElementById('serviceSelect');
    const opt = sel.options[sel.selectedIndex];
    if (!opt) return;

    const rate = parseFloat(opt.getAttribute('data-rate') || 0);
    const min = parseInt(opt.getAttribute('data-min') || 100);
    const max = parseInt(opt.getAttribute('data-max') || 10000);
    const speed = opt.getAttribute('data-speed') || 'Instant Start';
    const cat = opt.getAttribute('data-category') || 'General';
    const desc = opt.getAttribute('data-desc') || 'Standard social media service with automated fulfillment.';
    const name = opt.innerText.split('—')[0].trim();

    document.getElementById('badgeRate').innerText = '<?= e(app_currency()) ?>' + rate.toFixed(2) + ' / 1K';
    document.getElementById('badgeSpeed').innerText = '⚡ ' + speed;
    document.getElementById('lblServiceName').innerText = name;
    document.getElementById('lblCategory').innerText = cat;
    document.getElementById('lblSpeedText').innerText = speed;
    document.getElementById('lblDescription').innerText = desc;
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
    const max = parseInt(input.max) || 10000000;
    if (val < min) val = min;
    if (val > max) val = max;
    input.value = val;
    calculatePrice();
}

function setPresetQty(amount) {
    const input = document.getElementById('quantityInput');
    input.value = amount;
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

    // Balance check
    const userBalEl = document.getElementById('userBalanceDisplay');
    const userBal = parseFloat(userBalEl ? userBalEl.getAttribute('data-balance') : 0);
    const warnEl = document.getElementById('insufficientWarning');
    const submitBtn = document.getElementById('submitOrderBtn');

    if (total > userBal) {
        warnEl.classList.remove('hidden');
    } else {
        warnEl.classList.add('hidden');
    }
}

function filterByPlatform(plat) {
    currentPlatformFilter = plat;
    // Update platform button visual states
    document.querySelectorAll('.platform-btn').forEach(btn => {
        if (btn.getAttribute('data-platform') === plat) {
            btn.className = 'platform-btn p-3 rounded-2xl text-xs font-bold border transition-all flex flex-col items-center gap-1.5 bg-blue-600 border-blue-600 text-white shadow-md shadow-blue-500/25 scale-[1.02]';
        } else {
            btn.className = 'platform-btn p-3 rounded-2xl text-xs font-bold border transition-all flex flex-col items-center gap-1.5 bg-slate-50 border-slate-200/80 text-slate-700 hover:bg-slate-100';
        }
    });

    // Filter service dropdown options
    const sel = document.getElementById('serviceSelect');
    let firstVisible = null;
    let visibleCount = 0;

    for (let i = 0; i < sel.options.length; i++) {
        const opt = sel.options[i];
        const optPlat = opt.getAttribute('data-platform') || '';
        if (plat === 'all' || optPlat === plat) {
            opt.hidden = false;
            opt.disabled = false;
            visibleCount++;
            if (!firstVisible) firstVisible = opt;
        } else {
            opt.hidden = true;
            opt.disabled = true;
        }
    }

    document.getElementById('serviceCountBadge').innerText = visibleCount + ' available';

    if (firstVisible) {
        sel.value = firstVisible.value;
        updateServiceDetails();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    updateServiceDetails();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
