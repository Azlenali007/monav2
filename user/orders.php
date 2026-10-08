<?php
/**
 * User Orders List
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

$statusFilter = strtolower(trim($_GET['status'] ?? 'all'));
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT o.*, s.name AS service_name, c.platform
    FROM orders o
    JOIN services s ON o.service_id = s.id
    JOIN categories c ON s.category_id = c.id
    WHERE o.user_id = :uid
";
$params = ['uid' => $user['id']];

if ($statusFilter !== 'all' && in_array($statusFilter, ['processing', 'completed', 'cancelled', 'pending'])) {
    $sql .= " AND o.status = :status";
    $params['status'] = $statusFilter;
}

if (!empty($search)) {
    $sql .= " AND (o.id = :search_id OR o.link LIKE :search_link OR s.name LIKE :search_name)";
    $params['search_id'] = is_numeric($search) ? (int)$search : 0;
    $params['search_link'] = "%$search%";
    $params['search_name'] = "%$search%";
}

$sql .= " ORDER BY o.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$pageTitle = "My Orders - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto my-4 space-y-6">
    <div class="flex items-center justify-between">
        <a href="/user/dashboard.php" class="text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            &larr; Back to Dashboard
        </a>
        <a href="/user/new-order.php" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-md transition-all">
            + New Order
        </a>
    </div>

    <!-- Main Container -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-slate-100">
            <div>
                <h2 class="text-2xl font-extrabold text-slate-900">My Orders</h2>
                <p class="text-xs text-slate-500 mt-0.5">Track your social media campaigns in real-time</p>
            </div>
            <!-- Search bar -->
            <form action="/user/orders.php" method="GET" class="relative">
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search order ID or link..." class="pl-9 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs w-60 focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                <span class="absolute left-3 top-2.5 text-slate-400 text-xs">🔍</span>
            </form>
        </div>

        <!-- Filter Tabs -->
        <div class="flex items-center gap-2 overflow-x-auto py-4">
            <?php
            $tabs = ['all' => 'All', 'processing' => 'Processing', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
            foreach ($tabs as $key => $lbl):
                $active = ($statusFilter === $key);
            ?>
                <a href="/user/orders.php?status=<?= $key ?>" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $active ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 hover:bg-slate-100 text-slate-600' ?>">
                    <?= $lbl ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Orders List -->
        <?php if (empty($orders)): ?>
            <div class="py-16 text-center">
                <div class="w-16 h-16 rounded-full bg-slate-100 mx-auto flex items-center justify-center text-2xl mb-3">📦</div>
                <h3 class="text-sm font-bold text-slate-900">No orders found</h3>
                <p class="text-xs text-slate-500 mt-1">There are no orders matching your current filter.</p>
                <a href="/user/new-order.php" class="mt-4 inline-block px-5 py-2.5 bg-blue-600 text-white font-bold text-xs rounded-xl shadow-md">Place Your First Order</a>
            </div>
        <?php else: ?>
            <div class="divide-y divide-slate-100 mt-2">
                <?php foreach ($orders as $order): ?>
                    <a href="/user/order-details.php?id=<?= (int)$order['id'] ?>" class="py-4 flex items-center justify-between hover:bg-slate-50/70 p-3 rounded-2xl transition-all group">
                        <div class="flex items-center gap-3.5">
                            <!-- Platform Icon Badge -->
                            <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-pink-500 to-purple-600 flex items-center justify-center text-white text-xs font-extrabold shadow-sm shrink-0">
                                <?= strtoupper(substr($order['platform'], 0, 2)) ?>
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-slate-900 group-hover:text-blue-600 transition-colors">
                                    <?= e($order['service_name']) ?>
                                </h4>
                                <div class="flex items-center gap-2 text-xs text-slate-500 mt-0.5">
                                    <span class="font-bold text-slate-700 font-mono-nums"><?= number_format($order['quantity']) ?> qty</span>
                                    <span>•</span>
                                    <span class="font-extrabold text-slate-900"><?= format_currency($order['charge']) ?></span>
                                    <span>•</span>
                                    <span class="font-mono text-[11px] text-slate-400">#<?= (int)$order['id'] ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-4 text-right">
                            <div>
                                <span class="px-3 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-wider <?= $order['status'] === 'completed' ? 'bg-emerald-50 text-emerald-600 border border-emerald-200' : ($order['status'] === 'processing' ? 'bg-amber-50 text-amber-600 border border-amber-200' : 'bg-slate-100 text-slate-600') ?>">
                                    <?= e($order['status']) ?>
                                </span>
                                <span class="block text-[11px] text-slate-400 mt-1 font-medium">
                                    <?= date('d M Y, h:i A', strtotime($order['created_at'])) ?>
                                </span>
                            </div>
                            <span class="text-slate-300 group-hover:text-blue-600 group-hover:translate-x-1 transition-all text-base">&rarr;</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
