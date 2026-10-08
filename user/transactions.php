<?php
/**
 * User Transactions Ledger
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireLogin();
$user = Auth::user();
$db = Database::getConnection();

$typeFilter = strtolower(trim($_GET['type'] ?? 'all'));

$sql = "SELECT * FROM transactions WHERE user_id = :uid";
$params = ['uid' => $user['id']];

if ($typeFilter === 'add_funds') {
    $sql .= " AND type = 'deposit'";
} elseif ($typeFilter === 'orders') {
    $sql .= " AND type = 'order'";
} elseif ($typeFilter === 'refunds') {
    $sql .= " AND type = 'refund'";
}

$sql .= " ORDER BY created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$pageTitle = "Transactions - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto my-4 space-y-6">
    <div class="flex items-center justify-between">
        <a href="/user/dashboard.php" class="text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            &larr; Back to Dashboard
        </a>
        <a href="/user/add-funds.php" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-md transition-all">
            + Add Funds
        </a>
    </div>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl">
        <div class="pb-6 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-extrabold text-slate-900">Transactions</h2>
                <p class="text-xs text-slate-500 mt-0.5">Your complete financial ledger & payment records</p>
            </div>
            <div class="text-right">
                <span class="text-xs text-slate-400 block font-medium">Balance</span>
                <span class="text-xl font-extrabold text-slate-900 tabular-nums"><?= format_currency($user['balance']) ?></span>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="flex items-center gap-2 overflow-x-auto py-4">
            <a href="/user/transactions.php?type=all" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $typeFilter === 'all' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">All</a>
            <a href="/user/transactions.php?type=add_funds" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $typeFilter === 'add_funds' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">Add Funds</a>
            <a href="/user/transactions.php?type=orders" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $typeFilter === 'orders' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">Orders</a>
            <a href="/user/transactions.php?type=refunds" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $typeFilter === 'refunds' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">Refunds</a>
        </div>

        <!-- Transactions List -->
        <?php if (empty($transactions)): ?>
            <div class="py-16 text-center">
                <div class="w-16 h-16 rounded-full bg-slate-100 mx-auto flex items-center justify-center text-2xl mb-3">💳</div>
                <h3 class="text-sm font-bold text-slate-900">No transactions recorded yet</h3>
                <p class="text-xs text-slate-500 mt-1">Deposits and order deductions will appear here.</p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-slate-100 mt-2">
                <?php foreach ($transactions as $txn): 
                    $isDeposit = ((float)$txn['amount'] > 0);
                ?>
                    <div class="py-4 flex items-center justify-between hover:bg-slate-50/70 p-3 rounded-2xl transition-all">
                        <div class="flex items-center gap-3.5">
                            <div class="w-11 h-11 rounded-2xl flex items-center justify-center text-lg shadow-sm shrink-0 <?= $isDeposit ? 'bg-emerald-50 text-emerald-600 border border-emerald-100' : 'bg-rose-50 text-rose-600 border border-rose-100' ?>">
                                <?= $isDeposit ? '↓' : '↑' ?>
                            </div>
                            <div>
                                <h4 class="text-sm font-extrabold text-slate-900">
                                    <?= $isDeposit ? 'Add Funds' : 'Order Payment' ?>
                                </h4>
                                <div class="flex items-center gap-2 text-xs text-slate-500 mt-0.5">
                                    <span class="font-medium"><?= e($txn['gateway']) ?></span>
                                    <span>•</span>
                                    <span class="font-mono text-[11px] text-slate-400"><?= e($txn['gateway_txn_id']) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="text-base font-extrabold tabular-nums <?= $isDeposit ? 'text-emerald-600' : 'text-rose-600' ?>">
                                <?= $isDeposit ? '+' : '' ?><?= format_currency($txn['amount']) ?>
                            </span>
                            <span class="block text-[11px] text-slate-400 mt-0.5">
                                <?= date('d M Y, h:i A', strtotime($txn['created_at'])) ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
