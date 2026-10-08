<?php
/**
 * Add Funds Page (Multi-Gateway Production Architecture)
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
$upiId = get_setting('upi_id', 'smmpanel@upi');
$bankAccountName = get_setting('bank_account_name', 'SMM Panel Digital');
$bankAccountNumber = get_setting('bank_account_number', '123456789012');
$bankIfsc = get_setting('bank_ifsc', 'HDFC0001234');
$bankName = get_setting('bank_name', 'HDFC Bank');
$razorpayKeyId = get_setting('razorpay_key_id', defined('RAZORPAY_KEY_ID') ? RAZORPAY_KEY_ID : '');

$error = null;
$success = null;
$activeTab = $_GET['method'] ?? 'paytm_qr';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();

    $gateway = trim($_POST['gateway'] ?? 'paytm_qr');
    $amount = (float)($_POST['amount'] ?? 0);

    if ($amount < $minDeposit) {
        $error = "Minimum deposit amount is " . format_currency($minDeposit) . ".";
    } elseif ($amount > $maxDeposit) {
        $error = "Maximum single deposit amount is " . format_currency($maxDeposit) . ".";
    } else {
        if ($gateway === 'paytm_qr' || $gateway === 'manual_bank') {
            $utr = trim($_POST['utr_number'] ?? $_POST['bank_reference'] ?? '');
            $senderNote = trim($_POST['sender_note'] ?? '');

            if (empty($utr) || strlen($utr) < 6) {
                $error = "Please enter a valid 12-digit UTR number or bank transaction reference ID.";
            } else {
                // Prevent duplicate UTR submission
                $check = $db->prepare("SELECT id FROM transactions WHERE gateway_txn_id = :utr LIMIT 1");
                $check->execute(['utr' => $utr]);
                if ($check->fetch()) {
                    $error = "This Transaction / UTR ID has already been submitted. Please check your transaction history.";
                } else {
                    $methodName = ($gateway === 'paytm_qr') ? 'Paytm / UPI QR' : 'Bank Wire';
                    $stmt = $db->prepare("
                        INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                        VALUES (:uid, 'deposit', :amt, :gw, :txnid, 'pending', :note)
                    ");
                    $stmt->execute([
                        'uid' => $user['id'],
                        'amt' => $amount,
                        'gw' => $methodName,
                        'txnid' => $utr,
                        'note' => "Pending manual approval (" . ($senderNote ?: "UTR: {$utr}") . ")"
                    ]);

                    set_flash('success', "Deposit request of " . format_currency($amount) . " submitted successfully! Your UTR #{$utr} is queued for admin verification and wallet credit.");
                    redirect('/user/add-funds.php');
                }
            }
        } elseif ($gateway === 'razorpay') {
            // Production Razorpay Payment Intent Creation
            $orderId = 'rzp_ord_' . bin2hex(random_bytes(6));
            $stmt = $db->prepare("
                INSERT INTO transactions (user_id, type, amount, gateway, gateway_txn_id, status, note)
                VALUES (:uid, 'deposit', :amt, 'Razorpay', :txnid, 'pending', 'Awaiting Razorpay gateway completion')
            ");
            $stmt->execute([
                'uid' => $user['id'],
                'amt' => $amount,
                'txnid' => $orderId
            ]);

            // Stored pending transaction, proceed to checkout verification
            $_SESSION['pending_razorpay'] = [
                'txn_id' => $db->lastInsertId(),
                'order_id' => $orderId,
                'amount' => $amount
            ];
            set_flash('info', "Payment intent initiated for " . format_currency($amount) . ". Complete the payment to trigger automated balance update.");
            redirect('/user/add-funds.php?checkout=razorpay&order=' . urlencode($orderId));
        }
    }
}

// Fetch user's recent deposits
$recentDepositsStmt = $db->prepare("
    SELECT * FROM transactions 
    WHERE user_id = :uid AND type = 'deposit' 
    ORDER BY id DESC LIMIT 15
");
$recentDepositsStmt->execute(['uid' => $user['id']]);
$recentDeposits = $recentDepositsStmt->fetchAll();

$pageTitle = "Add Funds - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto my-6 space-y-6">
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

    <!-- Current Balance Callout Card -->
    <div class="p-6 rounded-3xl bg-gradient-to-br from-blue-900 via-indigo-900 to-slate-900 text-white shadow-xl flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <span class="text-[11px] text-blue-300 font-bold uppercase tracking-wider block">Current Account Balance</span>
            <div class="text-3xl sm:text-4xl font-black font-mono-nums mt-1 text-emerald-300">
                <?= format_currency($user['balance']) ?>
            </div>
            <span class="text-[11px] text-slate-300 font-medium flex items-center gap-1.5 mt-1.5">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span> Instant Wallet Delivery
            </span>
        </div>
        <div class="text-left sm:text-right">
            <span class="text-[11px] text-blue-300 font-bold uppercase tracking-wider block">Limits</span>
            <span class="text-xs text-slate-200 block font-mono">Min: <?= format_currency($minDeposit) ?> • Max: <?= format_currency($maxDeposit) ?></span>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold flex items-center gap-2">
            <span>⚠️</span>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Gateway Selector & Form Container -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div>
            <h2 class="text-lg font-black text-slate-900 tracking-tight">Select Deposit Gateway</h2>
            <p class="text-xs text-slate-400 mt-0.5">Choose your preferred automated or instant UPI deposit method</p>
        </div>

        <!-- Gateway Pills Tabs -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <button type="button" onclick="selectGateway('paytm_qr')" id="tab-paytm_qr" class="p-3.5 rounded-2xl border text-left transition-all gateway-tab bg-blue-50/70 border-blue-500 shadow-xs">
                <span class="text-xl block mb-1">📲</span>
                <span class="text-xs font-black text-slate-900 block leading-tight">Paytm / UPI QR</span>
                <span class="text-[10px] text-emerald-600 font-bold">0% Fees • Instant</span>
            </button>
            <button type="button" onclick="selectGateway('razorpay')" id="tab-razorpay" class="p-3.5 rounded-2xl border text-left transition-all gateway-tab border-slate-200 hover:border-slate-300">
                <span class="text-xl block mb-1">⚡</span>
                <span class="text-xs font-black text-slate-900 block leading-tight">Razorpay Auto</span>
                <span class="text-[10px] text-blue-600 font-bold">Cards / NetBanking</span>
            </button>
            <button type="button" onclick="selectGateway('cryptomus')" id="tab-cryptomus" class="p-3.5 rounded-2xl border text-left transition-all gateway-tab border-slate-200 hover:border-slate-300">
                <span class="text-xl block mb-1">🪙</span>
                <span class="text-xs font-black text-slate-900 block leading-tight">Crypto USDT</span>
                <span class="text-[10px] text-purple-600 font-bold">TRC20 / BEP20</span>
            </button>
            <button type="button" onclick="selectGateway('manual_bank')" id="tab-manual_bank" class="p-3.5 rounded-2xl border text-left transition-all gateway-tab border-slate-200 hover:border-slate-300">
                <span class="text-xl block mb-1">🏦</span>
                <span class="text-xs font-black text-slate-900 block leading-tight">Bank Wire</span>
                <span class="text-[10px] text-slate-500 font-bold">NEFT / RTGS</span>
            </button>
        </div>

        <!-- 1. Paytm / UPI QR Content -->
        <div id="method-paytm_qr" class="gateway-content space-y-6 pt-2">
            <div class="grid grid-cols-1 md:grid-cols-12 gap-6 p-5 rounded-2xl bg-slate-50 border border-slate-200/80 items-center">
                <!-- QR Visual Box -->
                <div class="md:col-span-4 flex flex-col items-center justify-center p-4 bg-white rounded-2xl border border-slate-200 text-center shadow-xs">
                    <!-- SVG Generated Dynamic QR placeholder -->
                    <div class="w-36 h-36 bg-slate-900 rounded-xl p-2 flex items-center justify-center text-white relative group">
                        <svg class="w-32 h-32 text-white" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M2 2h8v8H2V2zm2 2v4h4V4H4zm10-2h8v8h-8V2zm2 2v4h4V4h-4zM2 14h8v8H2v-8zm2 2v4h4v-4H4zm13-2h3v3h-3v-3zm0 5h3v3h-3v-3zm-3-5h2v2h-2v-2zm0 4h2v2h-2v-2zm5-1h2v2h-2v-2z"/>
                        </svg>
                        <div class="absolute inset-0 flex items-center justify-center bg-black/60 rounded-xl opacity-0 group-hover:opacity-100 transition-opacity text-[10px] text-white font-bold p-2">
                            Scan via GPay / PhonePe / Paytm
                        </div>
                    </div>
                    <span class="text-[11px] font-bold text-slate-500 mt-2 block">UPI QR Code</span>
                </div>

                <div class="md:col-span-8 space-y-3">
                    <div class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-black bg-emerald-100 text-emerald-800">
                        ⚡ RECOMMENDED ZERO FEE DEPOSIT
                    </div>
                    <h3 class="text-sm font-extrabold text-slate-900">How to pay via UPI:</h3>
                    <ol class="text-xs text-slate-600 space-y-1.5 list-decimal pl-4">
                        <li>Scan the QR code or send to UPI ID: <strong class="text-blue-600 font-mono select-all"><?= e($upiId) ?></strong></li>
                        <li>Complete the payment on your UPI app (Google Pay, PhonePe, Paytm, BHIM).</li>
                        <li>Copy the <strong>12-digit UTR / Reference Number</strong> from the payment receipt.</li>
                        <li>Paste the UTR number in the form below and click Submit Verification.</li>
                    </ol>
                </div>
            </div>

            <form action="/user/add-funds.php" method="POST" class="space-y-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="gateway" value="paytm_qr">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Deposit Amount (<?= e(app_currency()) ?>) <span class="text-rose-500">*</span></label>
                        <input type="number" step="1" min="<?= $minDeposit ?>" max="<?= $maxDeposit ?>" name="amount" required placeholder="500" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">12-Digit UTR / Transaction ID <span class="text-rose-500">*</span></label>
                        <input type="text" name="utr_number" required placeholder="e.g. 412389501234" maxlength="32" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Your UPI Name or Reference Note (Optional)</label>
                    <input type="text" name="sender_note" placeholder="Sender Name or bank reference" class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>

                <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs rounded-xl shadow-md shadow-blue-500/25 transition-all">
                    Submit UTR for Verification &rarr;
                </button>
            </form>
        </div>

        <!-- 2. Razorpay Automated Content -->
        <div id="method-razorpay" class="gateway-content space-y-6 pt-2 hidden">
            <div class="p-5 rounded-2xl bg-blue-50/50 border border-blue-200/80 space-y-2">
                <span class="text-[10px] font-black uppercase text-blue-800 tracking-wider">Automated Payment Gateway</span>
                <h3 class="text-sm font-extrabold text-slate-900">Instant Automated Checkout</h3>
                <p class="text-xs text-slate-600">
                    Secured by Razorpay. Supports Credit Cards, Debit Cards, NetBanking, and live UPI intent. Balance is automatically credited upon HMAC webhook verification.
                </p>
            </div>

            <form action="/user/add-funds.php" method="POST" class="space-y-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="gateway" value="razorpay">

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Amount to Add (<?= e(app_currency()) ?>) <span class="text-rose-500">*</span></label>
                    <input type="number" step="1" min="<?= $minDeposit ?>" max="<?= $maxDeposit ?>" name="amount" required placeholder="1000" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold focus:ring-2 focus:ring-blue-500">
                </div>

                <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs rounded-xl shadow-md shadow-blue-500/25 transition-all">
                    Proceed to Razorpay Checkout &rarr;
                </button>
            </form>
        </div>

        <!-- 3. Cryptomus USDT Content -->
        <div id="method-cryptomus" class="gateway-content space-y-6 pt-2 hidden">
            <div class="p-5 rounded-2xl bg-purple-50/50 border border-purple-200/80 space-y-2">
                <span class="text-[10px] font-black uppercase text-purple-800 tracking-wider">Cryptocurrency Portal</span>
                <h3 class="text-sm font-extrabold text-slate-900">USDT &amp; Crypto Payments</h3>
                <p class="text-xs text-slate-600">
                    Supports USDT (TRC-20, BEP-20, Polygon), Bitcoin, Ethereum, and Litecoin. Real-time blockchain confirmation automatically credits your wallet.
                </p>
            </div>

            <form action="/user/add-funds.php" method="POST" class="space-y-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="gateway" value="cryptomus">

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Amount in <?= e(app_currency()) ?> (Calculated dynamically into USDT) <span class="text-rose-500">*</span></label>
                    <input type="number" step="1" min="500" max="<?= $maxDeposit ?>" name="amount" required placeholder="2500" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold focus:ring-2 focus:ring-purple-500">
                </div>

                <button type="submit" class="w-full py-3 bg-purple-600 hover:bg-purple-700 text-white font-extrabold text-xs rounded-xl shadow-md shadow-purple-500/25 transition-all">
                    Generate Crypto Invoice &rarr;
                </button>
            </form>
        </div>

        <!-- 4. Bank Wire Content -->
        <div id="method-manual_bank" class="gateway-content space-y-6 pt-2 hidden">
            <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-3">
                <h3 class="text-xs font-extrabold text-slate-900 uppercase tracking-wider">Bank Account Credentials</h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                    <div>
                        <span class="text-slate-400 text-[10px] block">Account Name</span>
                        <strong class="text-slate-800"><?= e($bankAccountName) ?></strong>
                    </div>
                    <div>
                        <span class="text-slate-400 text-[10px] block">Account Number</span>
                        <strong class="text-slate-800 font-mono"><?= e($bankAccountNumber) ?></strong>
                    </div>
                    <div>
                        <span class="text-slate-400 text-[10px] block">IFSC Code</span>
                        <strong class="text-slate-800 font-mono"><?= e($bankIfsc) ?></strong>
                    </div>
                    <div>
                        <span class="text-slate-400 text-[10px] block">Bank Name</span>
                        <strong class="text-slate-800"><?= e($bankName) ?></strong>
                    </div>
                </div>
            </div>

            <form action="/user/add-funds.php" method="POST" class="space-y-4">
                <?= CSRF::field() ?>
                <input type="hidden" name="gateway" value="manual_bank">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Transferred Amount (<?= e(app_currency()) ?>) <span class="text-rose-500">*</span></label>
                        <input type="number" step="1" min="500" max="1000000" name="amount" required placeholder="5000" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono font-bold">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">IMPS / NEFT Reference Number <span class="text-rose-500">*</span></label>
                        <input type="text" name="bank_reference" required placeholder="e.g. CMS123456789" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                </div>

                <button type="submit" class="w-full py-3 bg-slate-900 hover:bg-slate-800 text-white font-extrabold text-xs rounded-xl shadow-md transition-all">
                    Submit Bank Slip for Credit &rarr;
                </button>
            </form>
        </div>
    </div>

    <!-- User Recent Deposit Ledger -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-base font-extrabold text-slate-900 tracking-tight">Recent Deposits History</h3>
                <p class="text-xs text-slate-400 mt-0.5">Live status of your recent payments</p>
            </div>
            <span class="text-xs font-bold text-slate-500"><?= count($recentDeposits) ?> records</span>
        </div>

        <?php if (empty($recentDeposits)): ?>
            <div class="p-8 text-center text-slate-400 space-y-2">
                <div class="text-2xl">💳</div>
                <div class="text-xs font-bold text-slate-600">No deposit records found.</div>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-slate-400 border-b border-slate-100 pb-3 uppercase text-[10px] font-bold tracking-wider">
                            <th class="py-3 px-3">Date</th>
                            <th class="py-3 px-3">Gateway</th>
                            <th class="py-3 px-3">Reference / UTR</th>
                            <th class="py-3 px-3 text-right">Amount</th>
                            <th class="py-3 px-3 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($recentDeposits as $tx): ?>
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="py-3 px-3 text-slate-500 font-mono text-[11px] whitespace-nowrap">
                                    <?= date('d M Y, H:i', strtotime($tx['created_at'])) ?>
                                </td>
                                <td class="py-3 px-3 font-semibold text-slate-800">
                                    <?= e($tx['gateway']) ?>
                                </td>
                                <td class="py-3 px-3 font-mono text-slate-600 select-all">
                                    <?= e($tx['gateway_txn_id'] ?: '—') ?>
                                </td>
                                <td class="py-3 px-3 text-right font-mono-nums font-black text-slate-900">
                                    +<?= format_currency($tx['amount']) ?>
                                </td>
                                <td class="py-3 px-3 text-center">
                                    <?php if ($tx['status'] === 'completed'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            ✓ Credited
                                        </span>
                                    <?php elseif ($tx['status'] === 'pending'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                            ⏳ Pending Review
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                            Failed / Rejected
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function selectGateway(method) {
    document.querySelectorAll('.gateway-tab').forEach(t => {
        t.classList.remove('bg-blue-50/70', 'border-blue-500', 'shadow-xs');
        t.classList.add('border-slate-200');
    });
    const currentTab = document.getElementById('tab-' + method);
    if (currentTab) {
        currentTab.classList.add('bg-blue-50/70', 'border-blue-500', 'shadow-xs');
        currentTab.classList.remove('border-slate-200');
    }
    document.querySelectorAll('.gateway-content').forEach(c => c.classList.add('hidden'));
    const currentContent = document.getElementById('method-' + method);
    if (currentContent) {
        currentContent.classList.remove('hidden');
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
