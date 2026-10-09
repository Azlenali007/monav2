<?php
/**
 * User Mass Order Batch Portal
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
$results = [];

// Handle Mass Order Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();

    $massInput = trim($_POST['mass_order_data'] ?? '');
    if (empty($massInput)) {
        $error = "Please enter at least one order line.";
    } else {
        $lines = preg_split('/\r\n|\r|\n/', $massInput);
        $parsedOrders = [];
        $totalRequiredCharge = 0.0;
        $validationErrors = [];

        // Preload active services for fast lookup
        $servicesStmt = $db->query("SELECT id, name, rate_per_1000, min_quantity, max_quantity, provider_id FROM services WHERE status = 'active'");
        $activeServices = [];
        while ($s = $servicesStmt->fetch()) {
            $activeServices[(int)$s['id']] = $s;
        }

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 3) {
                $validationErrors[] = "Line " . ($lineNum + 1) . ": Invalid format. Use: service_id | link | quantity";
                continue;
            }

            $serviceId = (int)$parts[0];
            $link = $parts[1];
            $quantity = (int)$parts[2];

            if (!isset($activeServices[$serviceId])) {
                $validationErrors[] = "Line " . ($lineNum + 1) . ": Service ID #{$serviceId} is invalid or inactive.";
                continue;
            }

            $svc = $activeServices[$serviceId];
            if (!filter_var($link, FILTER_VALIDATE_URL)) {
                $validationErrors[] = "Line " . ($lineNum + 1) . ": Invalid URL '{$link}'.";
                continue;
            }

            if ($quantity < (int)$svc['min_quantity'] || $quantity > (int)$svc['max_quantity']) {
                $validationErrors[] = "Line " . ($lineNum + 1) . ": Quantity {$quantity} out of range for '{$svc['name']}' ({$svc['min_quantity']} - {$svc['max_quantity']}).";
                continue;
            }

            $charge = round(($quantity / 1000) * (float)$svc['rate_per_1000'], 4);
            $totalRequiredCharge += $charge;

            $parsedOrders[] = [
                'line' => $lineNum + 1,
                'service' => $svc,
                'link' => $link,
                'quantity' => $quantity,
                'charge' => $charge
            ];
        }

        if (!empty($validationErrors) && empty($parsedOrders)) {
            $error = implode('<br>', $validationErrors);
        } elseif (empty($parsedOrders)) {
            $error = "No valid order lines could be processed.";
        } elseif ($user['balance'] < $totalRequiredCharge) {
            $error = "Insufficient account balance. Total required charge is " . format_currency($totalRequiredCharge) . ", but your balance is " . format_currency($user['balance']) . ".";
        } else {
            // Process orders atomically
            $db->beginTransaction();
            try {
                // 1. Deduct total charge
                $deduct = $db->prepare("UPDATE users SET balance = balance - :charge, spent = spent + :charge WHERE id = :uid AND balance >= :charge");
                $deduct->execute(['charge' => $totalRequiredCharge, 'uid' => $user['id']]);

                if ($deduct->rowCount() === 0) {
                    throw new Exception("Balance deduction failed due to concurrent update.");
                }

                $insertedCount = 0;
                $orderInsert = $db->prepare("
                    INSERT INTO orders (user_id, service_id, provider_id, link, quantity, charge, start_count, remains, status, mode)
                    VALUES (:uid, :sid, :pid, :link, :qty, :charge, 0, :remains, 'processing', 'auto')
                ");
                $txnInsert = $db->prepare("
                    INSERT INTO transactions (user_id, order_id, type, amount, gateway, gateway_txn_id, status, note)
                    VALUES (:uid, :oid, 'order', :amt, 'system', :txnid, 'completed', :note)
                ");

                foreach ($parsedOrders as $ord) {
                    $orderInsert->execute([
                        'uid' => $user['id'],
                        'sid' => $ord['service']['id'],
                        'pid' => $ord['service']['provider_id'] ?: null,
                        'link' => $ord['link'],
                        'qty' => $ord['quantity'],
                        'charge' => $ord['charge'],
                        'remains' => $ord['quantity']
                    ]);
                    $newOrderId = (int)$db->lastInsertId();

                    $txnInsert->execute([
                        'uid' => $user['id'],
                        'oid' => $newOrderId,
                        'amt' => -$ord['charge'],
                        'txnid' => 'ORD-' . $newOrderId,
                        'note' => 'Mass Order #' . $newOrderId . ': ' . $ord['service']['name']
                    ]);

                    $results[] = [
                        'order_id' => $newOrderId,
                        'service_name' => $ord['service']['name'],
                        'link' => $ord['link'],
                        'quantity' => $ord['quantity'],
                        'charge' => $ord['charge']
                    ];
                    $insertedCount++;
                }

                $db->commit();
                set_flash('success', "Batch submitted successfully! {$insertedCount} orders created.");
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Mass order execution failed: " . $e->getMessage();
            }
        }
    }
}

// Pre-fetch top services for quick reference guide
$popularServices = $db->query("SELECT id, name, rate_per_1000, min_quantity, max_quantity FROM services WHERE status = 'active' ORDER BY id ASC LIMIT 10")->fetchAll();

$pageTitle = "Mass Order - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto my-6 space-y-6">
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
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold space-y-1">
            <div class="font-extrabold flex items-center gap-1.5"><span>⚠️</span> Error in Mass Order:</div>
            <div><?= $error ?></div>
        </div>
    <?php endif; ?>

    <!-- Mass Order Form Card -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div>
            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-extrabold bg-blue-50 text-blue-700 border border-blue-200 mb-2">
                <span>📚</span> Bulk Processing Engine
            </div>
            <h1 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">Mass Order Submission</h1>
            <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                Place multiple orders simultaneously across different services and target links. Enter one order per line in the format specified below.
            </p>
        </div>

        <!-- Format Instructions Banner -->
        <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200 text-xs text-slate-700 space-y-2">
            <div class="font-bold flex items-center justify-between">
                <span>Format Specification:</span>
                <span class="font-mono text-[11px] text-blue-600 font-bold bg-white px-2 py-0.5 rounded-md border border-slate-200">service_id | link | quantity</span>
            </div>
            <p class="text-[11px] text-slate-500">
                Example: <code class="font-mono text-slate-800 bg-white px-1.5 py-0.5 rounded border border-slate-200">101 | https://instagram.com/myusername | 1000</code>
            </p>
        </div>

        <form action="/user/mass-order.php" method="POST" class="space-y-4">
            <?= CSRF::field() ?>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1.5">Order Data (One order per line)</label>
                <textarea name="mass_order_data" 
                          rows="10" 
                          required 
                          placeholder="101 | https://instagram.com/profile1 | 1000&#10;102 | https://instagram.com/post2 | 500&#10;201 | https://youtube.com/watch?v=123 | 2000"
                          class="w-full p-4 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-mono text-slate-900 focus:ring-2 focus:ring-blue-500 focus:bg-white transition-all"></textarea>
            </div>

            <div class="flex items-center justify-between pt-2">
                <a href="/user/services.php" class="text-xs font-bold text-blue-600 hover:underline">
                    Browse Services &amp; Find Service IDs &rarr;
                </a>
                <button type="submit" class="px-8 py-3 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs rounded-xl shadow-md shadow-blue-500/25 active:scale-95 transition-all cursor-pointer">
                    Submit Mass Order Batch &rarr;
                </button>
            </div>
        </form>
    </div>

    <?php if (!empty($results)): ?>
        <!-- Batch Results Summary -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-emerald-200/80 shadow-sm space-y-4">
            <div class="flex items-center gap-2 text-emerald-700 font-bold text-sm">
                <span>✓</span> Successfully Created <?= count($results) ?> Orders
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-slate-400 border-b border-slate-100 uppercase text-[10px] font-bold">
                            <th class="py-2">Order ID</th>
                            <th class="py-2">Service</th>
                            <th class="py-2">Target Link</th>
                            <th class="py-2 text-right">Quantity</th>
                            <th class="py-2 text-right">Charge</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-mono">
                        <?php foreach ($results as $res): ?>
                            <tr>
                                <td class="py-2 font-bold text-blue-600">#<?= $res['order_id'] ?></td>
                                <td class="py-2 font-sans font-semibold text-slate-800"><?= e($res['service_name']) ?></td>
                                <td class="py-2 text-slate-500 max-w-xs truncate"><?= e($res['link']) ?></td>
                                <td class="py-2 text-right font-bold"><?= number_format($res['quantity']) ?></td>
                                <td class="py-2 text-right font-bold text-emerald-600"><?= format_currency($res['charge']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Service ID Cheat Sheet Card -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-4">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <h3 class="text-sm font-extrabold text-slate-900">Popular Service IDs Reference</h3>
            <a href="/user/services.php" class="text-xs font-bold text-blue-600 hover:underline">View All &rarr;</a>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
            <?php foreach ($popularServices as $svc): ?>
                <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 flex items-center justify-between">
                    <div>
                        <span class="font-mono font-bold text-blue-600 block">ID #<?= $svc['id'] ?></span>
                        <span class="font-bold text-slate-800 truncate block max-w-[220px]"><?= e($svc['name']) ?></span>
                    </div>
                    <div class="text-right">
                        <span class="font-mono font-bold text-slate-700"><?= format_currency($svc['rate_per_1000']) ?>/1K</span>
                        <span class="text-[10px] text-slate-400 block font-mono"><?= $svc['min_quantity'] ?> - <?= $svc['max_quantity'] ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
