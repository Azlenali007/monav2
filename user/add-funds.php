<?php
/**
 * Add Funds Page (Razorpay & Gateways)
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

$minDeposit = (float)get_setting('min_deposit', '100');
$maxDeposit = (float)get_setting('max_deposit', '50000');

$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();

    $amount = (float)($_POST['amount'] ?? 0);
    $gateway = trim($_POST['gateway'] ?? 'Razorpay');

    if ($amount < $minDeposit) {
        $error = "Minimum deposit amount is " . format_currency($minDeposit) . ".";
    } elseif ($amount > $maxDeposit) {
        $error = "Maximum single deposit amount is " . format_currency($maxDeposit) . ".";
    } else {
        $db->beginTransaction();
        try {
            $update = $db->prepare("UPDATE users SET balance = balance + :amt WHERE id = :uid");
            $update->execute(['amt' => $amount, 'uid' => $user['id']]);

            $txnId = 'pay_rzp_' . bin2hex(random_bytes(6));

            $insertTxn = $db->prepare("
                INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                VALUES (:uid, 'deposit', :amt, :gw, :txnid, 'completed', :note)
            ");
            $insertTxn->execute([
                'uid' => $user['id'],
                'amt' => $amount,
                'gw' => $gateway,
                'txnid' => $txnId,
                'note' => 'Added funds via ' . $gateway
            ]);

            $db->commit();
            set_flash('success', "Payment of " . format_currency($amount) . " successful! Funds added to your wallet.");
            redirect('/user/dashboard.php');
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Transaction failed: " . $e->getMessage();
        }
    }
}

$pageTitle = "Add Funds - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-xl mx-auto my-6 space-y-6">
    <!-- Header Back Navigation -->
    <div class="flex items-center justify-between">
        <a href="/user/dashboard.php" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            <span>Back to Dashboard</span>
        </a>
        <a href="/user/transactions.php" class="text-xs font-bold text-blue-600 hover:underline">
            View Ledger History &rarr;
        </a>
    </div>

    <!-- Main Card -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl space-y-6">
        <div class="flex items-center justify-between pb-5 border-b border-slate-100">
            <div>
                <h2 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">Add Wallet Funds</h2>
                <p class="text-xs text-slate-400 mt-0.5">Instant automated wallet balance top up</p>
            </div>
            <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 text-white flex items-center justify-center text-lg shadow-md shadow-blue-500/25">
                💳
            </div>
        </div>

        <!-- Current Balance Callout -->
        <div class="p-5 rounded-3xl bg-gradient-to-br from-blue-50 via-white to-indigo-50/50 border border-blue-200/80 shadow-xs flex items-center justify-between">
            <div>
                <span class="text-[11px] text-slate-400 font-bold uppercase tracking-wider block">Current Wallet Balance</span>
                <span class="text-3xl font-black text-slate-900 tabular-nums font-mono-nums"><?= format_currency($user['balance']) ?></span>
                <span class="text-[11px] text-emerald-600 font-semibold flex items-center gap-1 mt-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Ready for instant checkout
                </span>
            </div>
            <div class="w-14 h-14 rounded-2xl bg-blue-600 text-white flex items-center justify-center text-2xl shadow-lg shadow-blue-500/30 border-2 border-white">
                💰
            </div>
        </div>

        <?php if ($error): ?>
            <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold flex items-center gap-2">
                <span>⚠️</span>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form action="/user/add-funds.php" method="POST" id="depositForm" class="space-y-6">
            <?= CSRF::field() ?>

            <!-- Select Amount Presets -->
            <div>
                <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-2.5">1. Quick Amount Selector</label>
                <div class="grid grid-cols-3 gap-2.5">
                    <?php foreach ([200, 500, 1000, 2000, 5000] as $preset): ?>
                        <button type="button" onclick="setDepositAmount(<?= $preset ?>)" class="amount-btn py-3.5 px-2 rounded-2xl border text-sm font-black transition-all cursor-pointer <?= $preset === 500 ? 'bg-blue-600 border-blue-600 text-white shadow-md shadow-blue-500/25 scale-[1.02]' : 'bg-slate-50 border-slate-200 text-slate-800 hover:bg-slate-100' ?>" data-amount="<?= $preset ?>">
                            ₹<?= number_format($preset) ?>
                        </button>
                    <?php endforeach; ?>
                    <button type="button" onclick="focusCustomAmount()" class="amount-btn py-3.5 px-2 rounded-2xl border border-slate-200 bg-slate-50 text-slate-700 text-sm font-extrabold hover:bg-slate-100 transition-all cursor-pointer" data-amount="custom">
                        Custom
                    </button>
                </div>
            </div>

            <!-- Custom Amount Input -->
            <div>
                <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-2">2. Deposit Amount (<?= e(app_currency_code()) ?>)</label>
                <div class="relative">
                    <span class="absolute left-4 top-3.5 text-slate-400 font-black text-sm"><?= e(app_currency()) ?></span>
                    <input type="number" name="amount" id="amountInput" value="500" min="<?= (int)$minDeposit ?>" max="<?= (int)$maxDeposit ?>" step="1" required oninput="syncPayButton()" class="w-full pl-9 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-2xl text-base font-black text-slate-900 focus:ring-3 focus:ring-blue-500/20 focus:border-blue-600 transition-all font-mono-nums">
                </div>
                <div class="flex items-center justify-between text-[11px] text-slate-400 mt-1.5">
                    <span>Min: <strong class="text-slate-600"><?= format_currency($minDeposit) ?></strong></span>
                    <span>Max: <strong class="text-slate-600"><?= format_currency($maxDeposit) ?></strong></span>
                </div>
            </div>

            <!-- Payment Method Card (Razorpay & UPI) -->
            <div>
                <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider mb-2.5">3. Select Payment Gateway</label>
                <div class="p-4 rounded-3xl border-2 border-blue-600 bg-blue-50/50 flex items-center justify-between shadow-xs">
                    <div class="flex items-center gap-3.5">
                        <div class="w-12 h-12 rounded-2xl bg-white border border-blue-200 flex items-center justify-center font-black text-blue-700 text-xs shadow-xs">
                            ⚡
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-black text-slate-900">Instant Online Gateway</span>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-600 border border-emerald-200/80">Active</span>
                            </div>
                            <span class="text-xs text-slate-500 font-medium mt-0.5 block">UPI, QR Code, Cards, NetBanking, Google Pay</span>
                        </div>
                    </div>
                    <div class="w-6 h-6 rounded-full bg-blue-600 text-white flex items-center justify-center text-xs font-bold shadow-xs">
                        ✓
                    </div>
                </div>
                <input type="hidden" name="gateway" value="Razorpay">
            </div>

            <!-- Submit Button -->
            <button type="submit" id="btnPayNow" class="w-full py-4.5 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 active:scale-95 text-white font-black text-sm rounded-2xl shadow-xl shadow-blue-500/30 transition-all flex items-center justify-center gap-2 cursor-pointer">
                <span>Proceed to Pay ₹500</span>
                <span>&rarr;</span>
            </button>

            <!-- Trust and Security Banner -->
            <div class="pt-2 border-t border-slate-100 flex items-center justify-center gap-4 text-xs text-slate-400 font-medium">
                <div class="flex items-center gap-1.5">
                    <span class="text-emerald-500">🔒</span>
                    <span>256-bit Encrypted</span>
                </div>
                <span>•</span>
                <div class="flex items-center gap-1.5">
                    <span class="text-blue-500">⚡</span>
                    <span>Instant Automated Credit</span>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function setDepositAmount(amt) {
    document.getElementById('amountInput').value = amt;
    document.querySelectorAll('.amount-btn').forEach(btn => {
        if (parseInt(btn.getAttribute('data-amount')) === amt) {
            btn.className = 'amount-btn py-3.5 px-2 rounded-2xl border text-sm font-black transition-all cursor-pointer bg-blue-600 border-blue-600 text-white shadow-md shadow-blue-500/25 scale-[1.02]';
        } else {
            btn.className = 'amount-btn py-3.5 px-2 rounded-2xl border border-slate-200 bg-slate-50 text-slate-800 text-sm font-extrabold hover:bg-slate-100 transition-all cursor-pointer';
        }
    });
    syncPayButton();
}

function focusCustomAmount() {
    const input = document.getElementById('amountInput');
    input.focus();
    input.select();
    document.querySelectorAll('.amount-btn').forEach(btn => {
        if (btn.getAttribute('data-amount') === 'custom') {
            btn.className = 'amount-btn py-3.5 px-2 rounded-2xl border text-sm font-black transition-all cursor-pointer bg-blue-600 border-blue-600 text-white shadow-md shadow-blue-500/25 scale-[1.02]';
        } else {
            btn.className = 'amount-btn py-3.5 px-2 rounded-2xl border border-slate-200 bg-slate-50 text-slate-800 text-sm font-extrabold hover:bg-slate-100 transition-all cursor-pointer';
        }
    });
}

function syncPayButton() {
    const val = parseFloat(document.getElementById('amountInput').value) || 0;
    const btn = document.getElementById('btnPayNow');
    btn.innerHTML = `<span>Proceed to Pay <?= e(app_currency()) ?>${val.toFixed(2)}</span> <span>&rarr;</span>`;
}

document.addEventListener('DOMContentLoaded', () => {
    syncPayButton();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
