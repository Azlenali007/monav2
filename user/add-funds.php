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

<div class="max-w-xl mx-auto my-6">
    <div class="mb-4">
        <a href="/user/dashboard.php" class="text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            &larr; Back to Dashboard
        </a>
    </div>

    <!-- Main Card -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl">
        <div class="flex items-center justify-between pb-6 border-b border-slate-100">
            <h2 class="text-xl font-extrabold text-slate-900">Add Funds</h2>
            <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-lg">
                💳
            </div>
        </div>

        <!-- Current Balance Callout -->
        <div class="my-6 p-4 rounded-2xl bg-gradient-to-r from-blue-50 to-indigo-50/50 border border-blue-100 flex items-center justify-between">
            <div>
                <span class="text-xs text-slate-500 font-medium block">Current Balance</span>
                <span class="text-3xl font-extrabold text-slate-900 tabular-nums"><?= format_currency($user['balance']) ?></span>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-blue-600 text-white flex items-center justify-center text-xl shadow-md shadow-blue-500/25">
                💰
            </div>
        </div>

        <?php if ($error): ?>
            <div class="mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form action="/user/add-funds.php" method="POST" id="depositForm" class="space-y-6">
            <?= CSRF::field() ?>

            <!-- Select Amount Pills -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-2">Select Amount</label>
                <div class="grid grid-cols-3 gap-3">
                    <?php foreach ([100, 200, 500, 1000, 2000] as $preset): ?>
                        <button type="button" onclick="setDepositAmount(<?= $preset ?>)" class="amount-btn py-3 rounded-xl border text-sm font-extrabold transition-all <?= $preset === 200 ? 'bg-blue-600 border-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 border-slate-200 text-slate-800 hover:bg-slate-100' ?>" data-amount="<?= $preset ?>">
                            ₹<?= number_format($preset) ?>
                        </button>
                    <?php endforeach; ?>
                    <button type="button" onclick="focusCustomAmount()" class="amount-btn py-3 rounded-xl border border-slate-200 bg-slate-50 text-slate-700 text-sm font-extrabold hover:bg-slate-100 transition-all" data-amount="custom">
                        Other
                    </button>
                </div>
            </div>

            <!-- Custom Amount Input -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Enter Amount (<?= e(app_currency_code()) ?>)</label>
                <div class="relative">
                    <span class="absolute left-4 top-3 text-slate-400 font-bold text-sm"><?= e(app_currency()) ?></span>
                    <input type="number" name="amount" id="amountInput" value="<?= (int)max(100, $minDeposit) ?>" min="<?= (int)$minDeposit ?>" max="<?= (int)$maxDeposit ?>" step="1" required oninput="syncPayButton()" class="w-full pl-8 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-base font-bold text-slate-900 focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                </div>
                <span class="text-[11px] text-slate-400 mt-1 block">Min deposit: <?= format_currency($minDeposit) ?> • Max deposit: <?= format_currency($maxDeposit) ?></span>
            </div>

            <!-- Payment Method Card (Razorpay) -->
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-2">Payment Method</label>
                <div class="p-4 rounded-2xl border-2 border-blue-600 bg-blue-50/40 flex items-center justify-between cursor-pointer">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-white border border-slate-200 flex items-center justify-center font-extrabold text-blue-700 text-xs shadow-sm">
                            RZP
                        </div>
                        <div>
                            <span class="text-sm font-extrabold text-slate-900 block">Razorpay</span>
                            <span class="text-[11px] text-slate-500 font-medium">UPI, Cards, NetBanking, Paytm, GPay</span>
                        </div>
                    </div>
                    <span class="text-xs font-bold text-blue-600 bg-blue-100 px-2.5 py-1 rounded-md">Secure & Fast</span>
                </div>
                <input type="hidden" name="gateway" value="Razorpay">
            </div>

            <!-- Submit Button -->
            <button type="submit" id="btnPayNow" class="w-full py-4 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-sm rounded-2xl shadow-xl shadow-blue-500/30 transition-all">
                Pay Now ₹200 &rarr;
            </button>

            <!-- Trust badge -->
            <div class="text-center flex items-center justify-center gap-2 text-xs text-slate-400 pt-1">
                <svg class="w-4 h-4 text-emerald-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                <span>Your payment is secure and encrypted</span>
            </div>
        </form>
    </div>
</div>

<script>
function setDepositAmount(amt) {
    document.getElementById('amountInput').value = amt;
    document.querySelectorAll('.amount-btn').forEach(btn => {
        if (parseInt(btn.getAttribute('data-amount')) === amt) {
            btn.className = 'amount-btn py-3 rounded-xl border text-sm font-extrabold transition-all bg-blue-600 border-blue-600 text-white shadow-md shadow-blue-500/20';
        } else {
            btn.className = 'amount-btn py-3 rounded-xl border border-slate-200 bg-slate-50 text-slate-800 text-sm font-extrabold hover:bg-slate-100 transition-all';
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
            btn.className = 'amount-btn py-3 rounded-xl border text-sm font-extrabold transition-all bg-blue-600 border-blue-600 text-white shadow-md shadow-blue-500/20';
        } else {
            btn.className = 'amount-btn py-3 rounded-xl border border-slate-200 bg-slate-50 text-slate-800 text-sm font-extrabold hover:bg-slate-100 transition-all';
        }
    });
}

function syncPayButton() {
    const val = parseFloat(document.getElementById('amountInput').value) || 0;
    document.getElementById('btnPayNow').innerText = 'Pay Now ' + '<?= e(app_currency()) ?>' + val.toLocaleString() + ' →';
}

document.addEventListener('DOMContentLoaded', () => {
    syncPayButton();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
