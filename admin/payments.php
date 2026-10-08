<?php
/**
 * Admin Payment Gateways & Manual Deposit Approvals
 * SMM Panel - PHP 8.3+
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

    if ($action === 'save_settings') {
        $settings = [
            'razorpay_key_id' => trim($_POST['razorpay_key_id'] ?? ''),
            'razorpay_key_secret' => trim($_POST['razorpay_key_secret'] ?? ''),
            'upi_id' => trim($_POST['upi_id'] ?? 'smmpanel@upi'),
            'cryptomus_merchant_id' => trim($_POST['cryptomus_merchant_id'] ?? ''),
            'cryptomus_api_key' => trim($_POST['cryptomus_api_key'] ?? ''),
            'bank_account_name' => trim($_POST['bank_account_name'] ?? 'SMM Panel Digital'),
            'bank_account_number' => trim($_POST['bank_account_number'] ?? ''),
            'bank_ifsc' => trim($_POST['bank_ifsc'] ?? ''),
            'bank_name' => trim($_POST['bank_name'] ?? ''),
            'min_deposit' => trim($_POST['min_deposit'] ?? '100'),
            'max_deposit' => trim($_POST['max_deposit'] ?? '50000'),
        ];

        foreach ($settings as $k => $v) {
            set_setting($k, $v);
        }
        update_config_file_settings($settings);

        set_flash('success', 'Payment gateway and manual deposit configurations saved successfully.');
        redirect('/admin/payments.php');
    } elseif ($action === 'approve_deposit') {
        $txnId = (int)($_POST['transaction_id'] ?? 0);

        $stmt = $db->prepare("SELECT * FROM transactions WHERE id = :id AND type = 'deposit' AND status = 'pending' LIMIT 1");
        $stmt->execute(['id' => $txnId]);
        $txn = $stmt->fetch();

        if ($txn) {
            $db->beginTransaction();
            try {
                // Concurrency-safe wallet credit
                $credit = $db->prepare("UPDATE users SET balance = balance + :amt WHERE id = :uid");
                $credit->execute(['amt' => $txn['amount'], 'uid' => $txn['user_id']]);

                // Update transaction status
                $upd = $db->prepare("UPDATE transactions SET status = 'completed', note = CONCAT(COALESCE(note, ''), ' [Approved by Admin]') WHERE id = :id");
                $upd->execute(['id' => $txnId]);

                $db->commit();

                // Trigger eligible referral commission
                process_referral_commission((int)$txn['user_id'], (float)$txn['amount'], 'deposit', $txnId, null);

                set_flash('success', "Deposit #{$txnId} of " . format_currency($txn['amount']) . " approved and credited to User #{$txn['user_id']}.");
            } catch (Exception $e) {
                $db->rollBack();
                set_flash('error', "Deposit approval failed: " . $e->getMessage());
            }
        }
        redirect('/admin/payments.php');
    } elseif ($action === 'reject_deposit') {
        $txnId = (int)($_POST['transaction_id'] ?? 0);
        $reason = trim($_POST['reject_reason'] ?? 'Invalid transaction reference or unconfirmed receipt');

        $upd = $db->prepare("UPDATE transactions SET status = 'failed', note = CONCAT(COALESCE(note, ''), ' [Rejected: ', :reason, ']') WHERE id = :id AND status = 'pending'");
        $upd->execute(['id' => $txnId, 'reason' => $reason]);

        set_flash('info', "Deposit #{$txnId} rejected.");
        redirect('/admin/payments.php');
    }
}

// Settings
$razorpayKeyId = get_setting('razorpay_key_id', defined('RAZORPAY_KEY_ID') ? RAZORPAY_KEY_ID : '');
$razorpayKeySecret = get_setting('razorpay_key_secret', defined('RAZORPAY_KEY_SECRET') ? RAZORPAY_KEY_SECRET : '');
$upiId = get_setting('upi_id', 'smmpanel@upi');
$cryptomusMerchant = get_setting('cryptomus_merchant_id', '');
$cryptomusKey = get_setting('cryptomus_api_key', '');
$bankAccountName = get_setting('bank_account_name', 'SMM Panel Digital');
$bankAccountNumber = get_setting('bank_account_number', '');
$bankIfsc = get_setting('bank_ifsc', '');
$bankName = get_setting('bank_name', '');
$minDeposit = get_setting('min_deposit', '100');
$maxDeposit = get_setting('max_deposit', '50000');

// Pending Deposits Queue
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

<div class="max-w-6xl mx-auto my-6 space-y-8">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight flex items-center gap-2">
                <span>💳</span> Payment Gateways &amp; Approvals
            </h1>
            <p class="text-xs text-slate-500 mt-1">Review user deposit proofs, verify UPI UTR numbers, and manage production gateway keys</p>
        </div>
        <div class="flex items-center gap-2">
            <?php if (count($pendingDeposits) > 0): ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200 animate-pulse">
                    ⚡ <?= count($pendingDeposits) ?> Manual Deposits Awaiting Verification
                </span>
            <?php else: ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                    ✓ All Deposits Processed
                </span>
            <?php endif; ?>
        </div>
    </div>

    <?= render_flash() ?>

    <!-- Pending Manual Deposits Queue Card -->
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
                                            <button type="submit" class="px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold shadow-xs">
                                                ✓ Credit Wallet
                                            </button>
                                        </form>
                                        <form action="/admin/payments.php" method="POST" class="inline" onsubmit="return confirm('Reject this deposit request?')">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="action" value="reject_deposit">
                                            <input type="hidden" name="transaction_id" value="<?= $dep['id'] ?>">
                                            <button type="submit" class="px-2.5 py-1 bg-rose-50 text-rose-700 hover:bg-rose-100 rounded-lg text-xs font-bold border border-rose-200">
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

    <!-- Gateways Configuration Form Card -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div class="border-b border-slate-100 pb-4">
            <h2 class="text-base font-extrabold text-slate-900 tracking-tight">Payment Gateways Configuration</h2>
            <p class="text-xs text-slate-400 mt-0.5">Secure API credentials are never exposed to frontend client code</p>
        </div>

        <form action="/admin/payments.php" method="POST" class="space-y-6">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="save_settings">

            <!-- 1. Razorpay Gateway -->
            <div class="p-6 rounded-2xl bg-blue-50/40 border border-blue-200/80 space-y-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">⚡</span>
                        <h3 class="text-sm font-black text-blue-950">Razorpay (Automated UPI, Cards &amp; NetBanking)</h3>
                    </div>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-800 uppercase">Live Webhook Protected</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Razorpay Key ID</label>
                        <input type="text" name="razorpay_key_id" value="<?= e($razorpayKeyId) ?>" placeholder="rzp_live_..." class="w-full px-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Razorpay Key Secret</label>
                        <input type="password" name="razorpay_key_secret" value="<?= e($razorpayKeySecret) ?>" placeholder="••••••••••••" class="w-full px-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                </div>
                <div class="text-[11px] text-slate-500 font-mono">
                    Webhook Listener URL: <span class="text-blue-600 bg-white px-1.5 py-0.5 rounded border border-slate-200"><?= e(app_url()) ?>/api/payments.php</span>
                </div>
            </div>

            <!-- 2. Paytm / UPI QR Manual Deposit -->
            <div class="p-6 rounded-2xl bg-emerald-50/40 border border-emerald-200/80 space-y-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">📲</span>
                        <h3 class="text-sm font-black text-emerald-950">Paytm / UPI QR &amp; Direct VPA</h3>
                    </div>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 uppercase">100% Zero Gateway Fees</span>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Official UPI VPA / ID</label>
                    <input type="text" name="upi_id" value="<?= e($upiId) ?>" placeholder="smmpanel@okaxis" class="w-full sm:w-1/2 px-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                    <span class="text-[11px] text-slate-400 mt-1 block">Displayed on the user Add Funds screen with instructions to submit the 12-digit UTR.</span>
                </div>
            </div>

            <!-- 3. Cryptomus / Crypto USDT -->
            <div class="p-6 rounded-2xl bg-amber-50/40 border border-amber-200/80 space-y-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-lg">🪙</span>
                        <h3 class="text-sm font-black text-amber-950">Cryptomus / Crypto USDT (TRC20 / BEP20)</h3>
                    </div>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 uppercase">Automated Invoicing</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Merchant UUID</label>
                        <input type="text" name="cryptomus_merchant_id" value="<?= e($cryptomusMerchant) ?>" placeholder="e.g. 8f9b..." class="w-full px-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Payment API Key</label>
                        <input type="password" name="cryptomus_api_key" value="<?= e($cryptomusKey) ?>" placeholder="••••••••••••" class="w-full px-3.5 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                </div>
            </div>

            <!-- 4. Bank Wire Details -->
            <div class="p-6 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-4">
                <div class="flex items-center gap-2">
                    <span class="text-lg">🏦</span>
                    <h3 class="text-sm font-black text-slate-900">Direct Bank Wire / RTGS / NEFT</h3>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Account Holder Name</label>
                        <input type="text" name="bank_account_name" value="<?= e($bankAccountName) ?>" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Account Number</label>
                        <input type="text" name="bank_account_number" value="<?= e($bankAccountNumber) ?>" placeholder="123456789012" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">IFSC / Routing Code</label>
                        <input type="text" name="bank_ifsc" value="<?= e($bankIfsc) ?>" placeholder="HDFC0001234" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Bank Name</label>
                        <input type="text" name="bank_name" value="<?= e($bankName) ?>" placeholder="HDFC Bank" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs">
                    </div>
                </div>
            </div>

            <!-- 5. Deposit Limits -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Minimum Deposit Amount (<?= e(app_currency()) ?>)</label>
                    <input type="number" step="0.01" min="1" name="min_deposit" value="<?= e($minDeposit) ?>" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Maximum Single Deposit Amount (<?= e(app_currency()) ?>)</label>
                    <input type="number" step="0.01" min="10" name="max_deposit" value="<?= e($maxDeposit) ?>" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                </div>
            </div>

            <div class="pt-4 border-t border-slate-100 flex justify-end">
                <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold shadow-md shadow-blue-500/20 transition-all">
                    Save Gateway Credentials
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
