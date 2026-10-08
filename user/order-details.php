<?php
/**
 * User Order Details
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

$orderId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT o.*, s.name AS service_name, s.speed, c.name AS category_name, c.platform
    FROM orders o
    JOIN services s ON o.service_id = s.id
    JOIN categories c ON s.category_id = c.id
    WHERE o.id = :oid AND o.user_id = :uid
    LIMIT 1
");
$stmt->execute(['oid' => $orderId, 'uid' => $user['id']]);
$order = $stmt->fetch();

if (!$order) {
    set_flash('error', 'Order not found.');
    redirect('/user/orders.php');
}

$pageTitle = "Order #" . $order['id'] . " - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-3xl mx-auto my-6 space-y-6">
    <div class="flex items-center justify-between">
        <a href="/user/orders.php" class="text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            &larr; Back to Orders
        </a>
        <a href="/user/tickets.php?order_id=<?= (int)$order['id'] ?>" class="text-xs font-bold text-blue-600 hover:underline">
            Need Help with this Order?
        </a>
    </div>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl space-y-6">
        <div class="flex items-start justify-between border-b border-slate-100 pb-6">
            <div>
                <span class="text-xs font-mono text-slate-400">ORDER #<?= (int)$order['id'] ?></span>
                <h2 class="text-2xl font-extrabold text-slate-900 mt-1"><?= e($order['service_name']) ?></h2>
                <span class="text-xs text-slate-500"><?= e($order['category_name']) ?></span>
            </div>
            <span class="px-3.5 py-1.5 rounded-full text-xs font-extrabold uppercase tracking-wider <?= $order['status'] === 'completed' ? 'bg-emerald-50 text-emerald-600 border border-emerald-200' : 'bg-amber-50 text-amber-600 border border-amber-200' ?>">
                <?= e($order['status']) ?>
            </span>
        </div>

        <!-- Details Grid -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 bg-slate-50 p-4 rounded-2xl border border-slate-200/60 text-center">
            <div>
                <span class="text-xs text-slate-400 block font-medium">Quantity</span>
                <span class="text-lg font-bold text-slate-900 tabular-nums"><?= number_format($order['quantity']) ?></span>
            </div>
            <div>
                <span class="text-xs text-slate-400 block font-medium">Charge</span>
                <span class="text-lg font-bold text-slate-900 tabular-nums"><?= format_currency($order['charge']) ?></span>
            </div>
            <div>
                <span class="text-xs text-slate-400 block font-medium">Start Count</span>
                <span class="text-lg font-bold text-slate-900 tabular-nums"><?= number_format($order['start_count']) ?></span>
            </div>
            <div>
                <span class="text-xs text-slate-400 block font-medium">Remains</span>
                <span class="text-lg font-bold text-slate-900 tabular-nums"><?= number_format($order['remains']) ?></span>
            </div>
        </div>

        <!-- Target Link -->
        <div>
            <label class="block text-xs font-bold text-slate-400 mb-1">Target Link</label>
            <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono text-blue-600 break-all select-all">
                <a href="<?= e($order['link']) ?>" target="_blank" rel="noopener noreferrer" class="hover:underline">
                    <?= e($order['link']) ?>
                </a>
            </div>
        </div>

        <div class="flex items-center justify-between text-xs text-slate-400 border-t border-slate-100 pt-4">
            <span>Created: <?= date('d M Y, h:i:s A', strtotime($order['created_at'])) ?></span>
            <span>Speed: <?= e($order['speed']) ?></span>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
