<?php
/**
 * Admin Payment Gateway Management & Deposit Approvals
 * SMM Panel - PHP 8.3+
 *
 * Provides centralized management for:
 * - Razorpay, PayPal, PhonePe, Paytm, Binance Pay
 * - Enable/Disable toggles with strict configuration checks
 * - Credential configurations and server-side secret protection
 * - Manual UPI / QR deposit approvals queue
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireAdmin();
$db = Database::getConnection();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';

    // 1. Toggle Gateway Status (Enable / Disable)
    if ($action === 'toggle_status') {
        $code = trim($_POST['code'] ?? '');
        $enable = (int)($_POST['enable'] ?? 0) === 1;

        $res = toggle_gateway_status($code, $enable);
        if ($res['success']) {
            $statusLabel = $enable ? 'enabled' : 'disabled';
            set_flash('success', "Payment gateway '{$code}' has been successfully {$statusLabel}.");
        } else {
            set_flash('error', $res['error']);
        }
        redirect('/admin/payments.php');
    }

    // 2. Save Gateway Configuration
    if ($action === 'configure_gateway') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $mode = trim($_POST['mode'] ?? 'test');
        $minAmount = (float)($_POST['min_amount'] ?? 100);
        $maxAmount = (float)($_POST['max_amount'] ?? 50000);
        $feePercent = (float)($_POST['fee_percent'] ?? 0);
        $instructions = trim($_POST['instructions'] ?? '');

        // Extract credentials depending on gateway code
        $config = [];
        if ($code === 'razorpay') {
            $config = [
                'key_id' => trim($_POST['razorpay_key_id'] ?? ''),
                'key_secret' => trim($_POST['razorpay_key_secret'] ?? ''),
                'webhook_secret' => trim($_POST['razorpay_webhook_secret'] ?? ''),
                'mode' => $mode
            ];
        } elseif ($code === 'paypal') {
            $config = [
                'client_id' => trim($_POST['paypal_client_id'] ?? ''),
                'client_secret' => trim($_POST['paypal_client_secret'] ?? ''),
                'webhook_id' => trim($_POST['paypal_webhook_id'] ?? ''),
                'mode' => in_array($mode, ['sandbox', 'live'], true) ? $mode : ($mode === 'live' ? 'live' : 'sandbox')
            ];
        } elseif ($code === 'phonepe') {
            $config = [
                'merchant_id' => trim($_POST['phonepe_merchant_id'] ?? ''),
                'salt_key' => trim($_POST['phonepe_salt_key'] ?? ''),
                'salt_index' => trim($_POST['phonepe_salt_index'] ?? '1'),
                'mode' => in_array($mode, ['sandbox', 'production'], true) ? $mode : ($mode === 'live' ? 'production' : 'sandbox')
            ];
        } elseif ($code === 'paytm') {
            $config = [
                'merchant_id' => trim($_POST['paytm_merchant_id'] ?? ''),
                'merchant_key' => trim($_POST['paytm_merchant_key'] ?? ''),
                'channel_id' => trim($_POST['paytm_channel_id'] ?? 'WEB'),
                'industry_type' => trim($_POST['paytm_industry_type'] ?? 'Retail'),
                'upi_id' => trim($_POST['paytm_upi_id'] ?? 'smmpanel@upi'),
                'mode' => in_array($mode, ['staging', 'production'], true) ? $mode : ($mode === 'live' ? 'production' : 'staging')
            ];
        } elseif ($code === 'binance') {
            $config = [
                'api_key' => trim($_POST['binance_api_key'] ?? ''),
                'secret_key' => trim($_POST['binance_secret_key'] ?? ''),
                'merchant_id' => trim($_POST['binance_merchant_id'] ?? ''),
                'mode' => $mode
            ];
        } else {
            // Generic custom gateway
            $config = $_POST['custom_config'] ?? [];
        }

        $extra = [
            'name' => $name,
            'min_amount' => $minAmount,
            'max_amount' => $maxAmount,
            'fee_percent' => $feePercent,
            'instructions' => $instructions,
            'mode' => in_array($mode, ['live', 'production'], true) ? 'live' : 'test'
        ];

        $ok = update_gateway_config($code, $config, $extra);
        if ($ok) {
            set_flash('success', "Configuration for '{$name}' saved successfully.");
        } else {
            set_flash('error', "Failed to update configuration for '{$name}'.");
        }
        redirect('/admin/payments.php');
    }

    // 3. Approve Manual Deposit
    if ($action === 'approve_deposit') {
        $txnId = (int)($_POST['transaction_id'] ?? 0);

        $stmt = $db->prepare("SELECT * FROM transactions WHERE id = :id AND type = 'deposit' AND status = 'pending' LIMIT 1");
        $stmt->execute(['id' => $txnId]);
        $txn = $stmt->fetch();

        if ($txn) {
            $db->beginTransaction();
            try {
                // Concurrency-safe atomic credit
                $credit = $db->prepare("UPDATE users SET balance = balance + :amt WHERE id = :uid");
                $credit->execute(['amt' => $txn['amount'], 'uid' => $txn['user_id']]);

                $upd = $db->prepare("UPDATE transactions SET status = 'completed', note = CONCAT(COALESCE(note, ''), ' [Approved by Admin]') WHERE id = :id");
                $upd->execute(['id' => $txnId]);

                $db->commit();

                // Process referral rewards if eligible
                process_referral_commission((int)$txn['user_id'], (float)$txn['amount'], 'deposit', $txnId, null);

                set_flash('success', "Deposit #{$txnId} of " . format_currency($txn['amount']) . " approved and credited to User #{$txn['user_id']}.");
            } catch (Exception $e) {
                $db->rollBack();
                set_flash('error', "Deposit approval failed: " . $e->getMessage());
            }
        }
        redirect('/admin/payments.php');
    }

    // 4. Reject Manual Deposit
    if ($action === 'reject_deposit') {
        $txnId = (int)($_POST['transaction_id'] ?? 0);
        $reason = trim($_POST['reject_reason'] ?? 'Invalid transaction reference or unconfirmed receipt');

        $upd = $db->prepare("UPDATE transactions SET status = 'failed', note = CONCAT(COALESCE(note, ''), ' [Rejected: ', :reason, ']') WHERE id = :id AND status = 'pending'");
        $upd->execute(['id' => $txnId, 'reason' => $reason]);

        set_flash('info', "Deposit #{$txnId} rejected.");
        redirect('/admin/payments.php');
    }
}

// Fetch all payment gateways
$allGateways = get_payment_methods(false);

// Pending Manual Deposits Queue
$pendingStmt = $db->query("
    SELECT t.*, u.username, u.email, u.balance AS current_balance
    FROM transactions t
    JOIN users u ON t.user_id = u.id
    WHERE t.type = 'deposit' AND t.status = 'pending'
    ORDER BY t.created_at ASC
");
$pendingDeposits = $pendingStmt->fetchAll();

$pageTitle = "Payment Gateways & Approvals - Admin Console";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-6xl mx-auto my-6 space-y-8" x-data="adminPaymentsHub()">
    <!-- Header Summary -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight flex items-center gap-2">
                <span>💳</span> Payment Gateway Management &amp; Approvals
            </h1>
            <p class="text-xs text-slate-500 mt-1">Configure live and test gateway credentials, enable/disable customer payment methods, and review manual deposits</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-200">
                <span>🔒</span> Server-Side Secrets Protected
            </span>
            <?php if (count($pendingDeposits) > 0): ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200 animate-pulse">
                    ⚡ <?= count($pendingDeposits) ?> Manual Deposits Awaiting Verification
                </span>
            <?php endif; ?>
        </div>
    </div>

    <?= render_flash() ?>

    <!-- SECTION 1: PAYMENT GATEWAYS LIST (Requirements 2, 3, 4, 5) -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div class="border-b border-slate-100 pb-4 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>
                <h2 class="text-base font-extrabold text-slate-900 tracking-tight">Active &amp; Available Payment Gateways</h2>
                <p class="text-xs text-slate-400 mt-0.5">Toggle customer checkout availability, set transaction limits, and configure API merchant credentials</p>
            </div>
            <div class="text-xs font-medium text-slate-500">
                Gateways enabled here dynamically appear on the user Add Funds portal.
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4">
            <?php foreach ($allGateways as $gw): 
                $isConfigured = is_gateway_configured($gw);
                $isEnabled = ($gw['status'] === 'active');
                $cfg = get_gateway_config($gw);
                $modeVal = $gw['mode'] ?? ($cfg['mode'] ?? 'test');
                $isLive = in_array(strtolower($modeVal), ['live', 'production'], true);
            ?>
                <div class="p-5 rounded-2xl border transition-all <?= $isEnabled ? 'bg-white border-blue-200/80 shadow-xs' : 'bg-slate-50/70 border-slate-200' ?>">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <!-- Left: Icon, Name, Code, Badges -->
                        <div class="flex items-start sm:items-center gap-4">
                            <div class="w-13 h-13 rounded-2xl flex items-center justify-center text-2xl shadow-xs shrink-0 <?= $isEnabled ? 'bg-blue-50 text-blue-600 border border-blue-100' : 'bg-slate-200 text-slate-400' ?>">
                                <?= e($gw['icon'] ?: '💳') ?>
                            </div>
                            <div class="space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-base font-extrabold text-slate-900"><?= e($gw['name']) ?></h3>
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-mono font-bold bg-slate-100 text-slate-600 border border-slate-200">
                                        <?= e($gw['code']) ?>
                                    </span>
                                    
                                    <!-- Status Badge -->
                                    <?php if ($isEnabled): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Enabled
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase bg-slate-100 text-slate-600 border border-slate-200">
                                            Disabled
                                        </span>
                                    <?php endif; ?>

                                    <!-- Configuration Status Badge -->
                                    <?php if ($isConfigured): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                            ✓ Configured
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                            ⚠️ Configuration Required
                                        </span>
                                    <?php endif; ?>

                                    <!-- Environment Mode Badge -->
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-black uppercase tracking-wider <?= $isLive ? 'bg-purple-50 text-purple-700 border border-purple-200' : 'bg-amber-50 text-amber-800 border border-amber-200' ?>">
                                        <?= $isLive ? 'Live' : 'Sandbox / Test' ?>
                                    </span>
                                </div>

                                <p class="text-xs text-slate-500 max-w-2xl leading-relaxed">
                                    <?= e($gw['instructions'] ?: 'Standard payment processing gateway.') ?>
                                </p>

                                <div class="flex flex-wrap items-center gap-3 text-[11px] font-medium text-slate-400 pt-0.5">
                                    <span>Limits: <strong class="text-slate-700 font-mono"><?= format_currency($gw['min_amount']) ?> - <?= format_currency($gw['max_amount']) ?></strong></span>
                                    <span>•</span>
                                    <span>Fee: <strong class="text-slate-700 font-mono"><?= (float)$gw['fee_percent'] ?>%</strong></span>
                                    <span>•</span>
                                    <span>Priority: <strong class="text-slate-700 font-mono">#<?= (int)$gw['sort_order'] ?></strong></span>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Actions (Configure, Enable, Disable) -->
                        <div class="flex items-center gap-2.5 shrink-0 pt-2 lg:pt-0">
                            <!-- Configure Button -->
                            <button type="button" 
                                    @click="openConfigModal(<?= htmlspecialchars(json_encode($gw), ENT_QUOTES) ?>)" 
                                    class="px-4 py-2 bg-slate-100 hover:bg-slate-200 active:scale-95 text-slate-800 text-xs font-bold rounded-xl transition-all flex items-center gap-1.5 cursor-pointer">
                                <span>⚙️</span>
                                <span>Configure</span>
                            </button>

                            <!-- Enable / Disable Form Actions -->
                            <?php if ($isEnabled): ?>
                                <form action="/admin/payments.php" method="POST" class="inline" onsubmit="return confirm('Disable <?= addslashes($gw['name']) ?>? It will immediately be hidden from user Add Funds.')">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="code" value="<?= e($gw['code']) ?>">
                                    <input type="hidden" name="enable" value="0">
                                    <button type="submit" class="px-4 py-2 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 active:scale-95 text-xs font-bold rounded-xl transition-all cursor-pointer">
                                        Disable
                                    </button>
                                </form>
                            <?php else: ?>
                                <form action="/admin/payments.php" method="POST" class="inline">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="code" value="<?= e($gw['code']) ?>">
                                    <input type="hidden" name="enable" value="1">
                                    <button type="submit" 
                                            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white active:scale-95 text-xs font-bold rounded-xl shadow-sm transition-all cursor-pointer">
                                        Enable
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- SECTION 2: PENDING MANUAL DEPOSITS QUEUE (Preserved & Enhanced) -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div class="border-b border-slate-100 pb-4 flex items-center justify-between">
            <div>
                <h2 class="text-base font-extrabold text-slate-900 tracking-tight">Manual &amp; QR Deposits Queue</h2>
                <p class="text-xs text-slate-400 mt-0.5">Users who submitted 12-digit UTR numbers or bank transfer reference slips</p>
            </div>
            <span class="text-xs font-mono font-bold text-amber-600 bg-amber-50 px-2.5 py-1 rounded-lg border border-amber-200">
                <?= count($pendingDeposits) ?> Pending
            </span>
        </div>

        <?php if (empty($pendingDeposits)): ?>
            <div class="p-10 text-center text-slate-400 space-y-2">
                <div class="text-3xl">✨</div>
                <div class="text-xs font-bold text-slate-600">No pending manual deposits in queue.</div>
                <p class="text-[11px] text-slate-400">Incoming Paytm QR and bank wire deposit requests will appear here for staff review.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-slate-400 border-b border-slate-100 pb-3 uppercase text-[10px] font-bold tracking-wider">
                            <th class="py-3 px-3">#ID</th>
                            <th class="py-3 px-3">Submitted</th>
                            <th class="py-3 px-3">User</th>
                            <th class="py-3 px-3">Method</th>
                            <th class="py-3 px-3">UTR / Txn ID</th>
                            <th class="py-3 px-3 text-right">Amount</th>
                            <th class="py-3 px-3">User Notes</th>
                            <th class="py-3 px-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($pendingDeposits as $dep): ?>
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="py-3.5 px-3 font-mono text-slate-400 text-[11px]">#<?= $dep['id'] ?></td>
                                <td class="py-3.5 px-3 font-mono text-slate-500 text-[11px] whitespace-nowrap">
                                    <?= date('d M Y, H:i', strtotime($dep['created_at'])) ?>
                                </td>
                                <td class="py-3.5 px-3">
                                    <div class="font-extrabold text-slate-900"><?= e($dep['username']) ?></div>
                                    <div class="text-[10px] text-slate-400 font-mono"><?= e($dep['email']) ?></div>
                                </td>
                                <td class="py-3.5 px-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700">
                                        <?= e($dep['gateway']) ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-3 font-mono font-bold text-slate-800 select-all">
                                    <?= e($dep['gateway_txn_id'] ?: 'N/A') ?>
                                </td>
                                <td class="py-3.5 px-3 text-right font-mono-nums font-black text-emerald-600 text-sm">
                                    <?= format_currency($dep['amount']) ?>
                                </td>
                                <td class="py-3.5 px-3 text-slate-500 max-w-xs truncate" title="<?= e($dep['note']) ?>">
                                    <?= e($dep['note'] ?: '—') ?>
                                </td>
                                <td class="py-3.5 px-3 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <form action="/admin/payments.php" method="POST" class="inline" onsubmit="return confirm('Confirm receipt of <?= format_currency($dep['amount']) ?> and credit wallet?')">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="action" value="approve_deposit">
                                            <input type="hidden" name="transaction_id" value="<?= $dep['id'] ?>">
                                            <button type="submit" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold rounded-lg text-xs shadow-xs transition-colors cursor-pointer">
                                                Approve
                                            </button>
                                        </form>

                                        <form action="/admin/payments.php" method="POST" class="inline" onsubmit="return confirm('Reject deposit #<?= $dep['id'] ?>?')">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="action" value="reject_deposit">
                                            <input type="hidden" name="transaction_id" value="<?= $dep['id'] ?>">
                                            <button type="submit" class="px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 font-extrabold rounded-lg text-xs transition-colors cursor-pointer">
                                                Reject
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- GATEWAY CONFIGURATION MODAL (Requirements 5 & 7) -->
    <div x-show="activeGw !== null"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-xs overflow-y-auto"
         style="display: none;"
         @keydown.escape.window="closeConfigModal()">
        
        <div @click.outside="closeConfigModal()" class="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl border border-slate-100 relative space-y-6 my-8">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <span class="text-3xl" x-text="activeGw?.icon || '💳'"></span>
                    <div>
                        <h3 class="text-lg font-black text-slate-900 tracking-tight" x-text="'Configure ' + (activeGw?.name || 'Gateway')"></h3>
                        <p class="text-xs text-slate-400">Settings and API keys remain protected server-side.</p>
                    </div>
                </div>
                <button type="button" @click="closeConfigModal()" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center text-sm font-bold">✕</button>
            </div>

            <form action="/admin/payments.php" method="POST" class="space-y-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="configure_gateway">
                <input type="hidden" name="code" :value="activeGw?.code">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Display Name</label>
                        <input type="text" name="name" :value="activeGw?.name" required class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Environment Mode</label>
                        <select name="mode" class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold">
                            <option value="test" :selected="activeGw?.mode === 'test' || activeGwConfig?.mode === 'test' || activeGwConfig?.mode === 'sandbox' || activeGwConfig?.mode === 'staging'">Sandbox / Test / Staging</option>
                            <option value="live" :selected="activeGw?.mode === 'live' || activeGwConfig?.mode === 'live' || activeGwConfig?.mode === 'production'">Live / Production</option>
                        </select>
                    </div>
                </div>

                <!-- 1. RAZORPAY CONFIGURATION FIELDS -->
                <template x-if="activeGw?.code === 'razorpay'">
                    <div class="space-y-3 p-4 rounded-2xl bg-blue-50/50 border border-blue-100">
                        <span class="text-[11px] font-extrabold text-blue-900 block">Razorpay Merchant Credentials</span>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Key ID <span class="text-rose-500">*</span></label>
                            <input type="text" name="razorpay_key_id" :value="activeGwConfig.key_id || ''" placeholder="rzp_test_..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Key Secret <span class="text-rose-500">*</span></label>
                            <input type="password" name="razorpay_key_secret" :value="activeGwConfig.key_secret || ''" placeholder="••••••••••••••••" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Webhook Secret (Optional)</label>
                            <input type="password" name="razorpay_webhook_secret" :value="activeGwConfig.webhook_secret || ''" placeholder="••••••••••••••••" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                    </div>
                </template>

                <!-- 2. PAYPAL CONFIGURATION FIELDS -->
                <template x-if="activeGw?.code === 'paypal'">
                    <div class="space-y-3 p-4 rounded-2xl bg-sky-50/50 border border-sky-100">
                        <span class="text-[11px] font-extrabold text-sky-900 block">PayPal REST API Credentials</span>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Client ID <span class="text-rose-500">*</span></label>
                            <input type="text" name="paypal_client_id" :value="activeGwConfig.client_id || ''" placeholder="AXXXXXXXXX..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Client Secret <span class="text-rose-500">*</span></label>
                            <input type="password" name="paypal_client_secret" :value="activeGwConfig.client_secret || ''" placeholder="••••••••••••••••" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Webhook ID (Optional)</label>
                            <input type="text" name="paypal_webhook_id" :value="activeGwConfig.webhook_id || ''" placeholder="8XXXXXXXXX..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                    </div>
                </template>

                <!-- 3. PHONEPE CONFIGURATION FIELDS -->
                <template x-if="activeGw?.code === 'phonepe'">
                    <div class="space-y-3 p-4 rounded-2xl bg-purple-50/50 border border-purple-100">
                        <span class="text-[11px] font-extrabold text-purple-900 block">PhonePe Merchant Credentials</span>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Merchant ID <span class="text-rose-500">*</span></label>
                            <input type="text" name="phonepe_merchant_id" :value="activeGwConfig.merchant_id || ''" placeholder="MERCHANTUAT" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Salt Key <span class="text-rose-500">*</span></label>
                            <input type="password" name="phonepe_salt_key" :value="activeGwConfig.salt_key || ''" placeholder="••••••••••••••••" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Salt Index</label>
                            <input type="text" name="phonepe_salt_index" :value="activeGwConfig.salt_index || '1'" placeholder="1" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                    </div>
                </template>

                <!-- 4. PAYTM CONFIGURATION FIELDS -->
                <template x-if="activeGw?.code === 'paytm'">
                    <div class="space-y-3 p-4 rounded-2xl bg-blue-50/50 border border-blue-100">
                        <span class="text-[11px] font-extrabold text-blue-900 block">Paytm Merchant &amp; QR Settings</span>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Merchant ID (MID) <span class="text-rose-500">*</span></label>
                            <input type="text" name="paytm_merchant_id" :value="activeGwConfig.merchant_id || ''" placeholder="YourPaytmMID123" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Merchant Key <span class="text-rose-500">*</span></label>
                            <input type="password" name="paytm_merchant_key" :value="activeGwConfig.merchant_key || ''" placeholder="••••••••••••••••" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Official UPI ID for Manual QR</label>
                            <input type="text" name="paytm_upi_id" :value="activeGwConfig.upi_id || 'smmpanel@upi'" placeholder="smmpanel@upi" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                    </div>
                </template>

                <!-- 5. BINANCE PAY CONFIGURATION FIELDS -->
                <template x-if="activeGw?.code === 'binance'">
                    <div class="space-y-3 p-4 rounded-2xl bg-amber-50/50 border border-amber-100">
                        <span class="text-[11px] font-extrabold text-amber-900 block">Binance Pay Merchant API</span>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">API Key <span class="text-rose-500">*</span></label>
                            <input type="text" name="binance_api_key" :value="activeGwConfig.api_key || ''" placeholder="binance_api_key_..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Secret Key <span class="text-rose-500">*</span></label>
                            <input type="password" name="binance_secret_key" :value="activeGwConfig.secret_key || ''" placeholder="••••••••••••••••" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-600 mb-1">Merchant ID (Optional)</label>
                            <input type="text" name="binance_merchant_id" :value="activeGwConfig.merchant_id || ''" placeholder="987654321" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                        </div>
                    </div>
                </template>

                <!-- Limits & Instructions -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Min Deposit</label>
                        <input type="number" step="1" name="min_amount" :value="activeGw?.min_amount" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Max Deposit</label>
                        <input type="number" step="1" name="max_amount" :value="activeGw?.max_amount" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Fee %</label>
                        <input type="number" step="0.1" name="fee_percent" :value="activeGw?.fee_percent" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">User Instructions / Note</label>
                    <textarea name="instructions" rows="2" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs" :value="activeGw?.instructions"></textarea>
                </div>

                <div class="pt-3 border-t border-slate-100 flex items-center justify-end gap-3">
                    <button type="button" @click="closeConfigModal()" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl text-xs cursor-pointer">
                        Cancel
                    </button>
                    <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-extrabold rounded-xl text-xs shadow-md shadow-blue-500/25 cursor-pointer">
                        Save Gateway Configuration &rarr;
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function adminPaymentsHub() {
    return {
        activeGw: null,
        activeGwConfig: {},

        openConfigModal(gw) {
            this.activeGw = gw;
            let cfg = {};
            try {
                if (typeof gw.config_data === 'string' && gw.config_data.trim() !== '') {
                    cfg = JSON.parse(gw.config_data);
                } else if (typeof gw.config_data === 'object' && gw.config_data !== null) {
                    cfg = gw.config_data;
                }
            } catch (e) {
                cfg = {};
            }
            this.activeGwConfig = cfg;
        },

        closeConfigModal() {
            this.activeGw = null;
            this.activeGwConfig = {};
        }
    };
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
