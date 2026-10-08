<?php
/**
 * Services Price List
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireLogin();
$db = Database::getConnection();

$platformFilter = strtolower(trim($_GET['platform'] ?? 'all'));
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT s.*, c.name AS category_name, c.platform
    FROM services s
    JOIN categories c ON s.category_id = c.id
    WHERE s.status = 'active'
";
$params = [];

if ($platformFilter !== 'all' && in_array($platformFilter, ['instagram', 'youtube', 'telegram', 'facebook', 'tiktok', 'twitter'])) {
    $sql .= " AND c.platform = :platform";
    $params['platform'] = $platformFilter;
}

if (!empty($search)) {
    $sql .= " AND (s.name LIKE :search_name OR c.name LIKE :search_cat)";
    $params['search_name'] = "%$search%";
    $params['search_cat'] = "%$search%";
}

$sql .= " ORDER BY c.sort_order ASC, s.rate_per_1000 ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$services = $stmt->fetchAll();

$pageTitle = "All Services - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-5xl mx-auto my-4 space-y-6">
    <div class="flex items-center justify-between">
        <a href="/user/dashboard.php" class="text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            &larr; Back to Dashboard
        </a>
        <a href="/user/new-order.php" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-md transition-all">
            + New Order
        </a>
    </div>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-slate-100">
            <div>
                <h2 class="text-2xl font-extrabold text-slate-900">All Services</h2>
                <p class="text-xs text-slate-500 mt-0.5">Transparent pricing for top tier social growth</p>
            </div>
            <!-- Search bar -->
            <form action="/user/services.php" method="GET" class="relative">
                <?php if ($platformFilter !== 'all'): ?>
                    <input type="hidden" name="platform" value="<?= e($platformFilter) ?>">
                <?php endif; ?>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search services..." class="pl-9 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs w-64 focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                <span class="absolute left-3 top-3 text-slate-400 text-xs">🔍</span>
            </form>
        </div>

        <!-- Platform Tabs -->
        <div class="flex items-center gap-2 overflow-x-auto py-4">
            <a href="/user/services.php" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $platformFilter === 'all' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">
                All Platforms
            </a>
            <?php foreach (['instagram' => 'Instagram', 'youtube' => 'YouTube', 'telegram' => 'Telegram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'twitter' => 'Twitter (X)'] as $k => $l): ?>
                <a href="/user/services.php?platform=<?= $k ?>" class="px-4 py-2 rounded-xl text-xs font-bold transition-all whitespace-nowrap <?= $platformFilter === $k ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">
                    <?= $l ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Grid of Services -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-2">
            <div class="bg-gradient-to-b from-pink-50 to-white p-5 rounded-2xl border border-pink-100 text-center shadow-sm">
                <div class="w-12 h-12 rounded-xl bg-pink-500 text-white mx-auto flex items-center justify-center text-xl font-bold mb-3 shadow-md">👥</div>
                <h4 class="text-sm font-extrabold text-slate-900">Followers</h4>
                <span class="text-xl font-extrabold text-slate-900 block mt-2">₹35 <span class="text-xs text-slate-400 font-normal">/ 1K</span></span>
            </div>
            <div class="bg-gradient-to-b from-rose-50 to-white p-5 rounded-2xl border border-rose-100 text-center shadow-sm">
                <div class="w-12 h-12 rounded-xl bg-rose-500 text-white mx-auto flex items-center justify-center text-xl font-bold mb-3 shadow-md">❤️</div>
                <h4 class="text-sm font-extrabold text-slate-900">Likes</h4>
                <span class="text-xl font-extrabold text-slate-900 block mt-2">₹20 <span class="text-xs text-slate-400 font-normal">/ 1K</span></span>
            </div>
            <div class="bg-gradient-to-b from-purple-50 to-white p-5 rounded-2xl border border-purple-100 text-center shadow-sm">
                <div class="w-12 h-12 rounded-xl bg-purple-500 text-white mx-auto flex items-center justify-center text-xl font-bold mb-3 shadow-md">👁️</div>
                <h4 class="text-sm font-extrabold text-slate-900">Views</h4>
                <span class="text-xl font-extrabold text-slate-900 block mt-2">₹15 <span class="text-xs text-slate-400 font-normal">/ 1K</span></span>
            </div>
            <div class="bg-gradient-to-b from-amber-50 to-white p-5 rounded-2xl border border-amber-100 text-center shadow-sm">
                <div class="w-12 h-12 rounded-xl bg-amber-500 text-white mx-auto flex items-center justify-center text-xl font-bold mb-3 shadow-md">💬</div>
                <h4 class="text-sm font-extrabold text-slate-900">Comments</h4>
                <span class="text-xl font-extrabold text-slate-900 block mt-2">₹50 <span class="text-xs text-slate-400 font-normal">/ 1K</span></span>
            </div>
        </div>

        <!-- Banner Feature -->
        <div class="my-6 p-4 rounded-2xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white flex items-center justify-between shadow-lg">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center text-lg">🚀</div>
                <div>
                    <h4 class="text-sm font-extrabold">Premium Quality Services</h4>
                    <p class="text-xs text-blue-100">Boost your social media presence today with high-retention servers.</p>
                </div>
            </div>
            <a href="/user/new-order.php" class="px-4 py-2 bg-white text-blue-600 hover:bg-blue-50 text-xs font-bold rounded-xl shadow-md transition-all whitespace-nowrap">Order Now &rarr;</a>
        </div>

        <!-- Detailed Table -->
        <div class="overflow-x-auto mt-6">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-slate-200 text-slate-400 uppercase tracking-wider">
                        <th class="py-3 px-3">ID</th>
                        <th class="py-3 px-3">Service</th>
                        <th class="py-3 px-3">Rate / 1K</th>
                        <th class="py-3 px-3">Min / Max</th>
                        <th class="py-3 px-3">Speed</th>
                        <th class="py-3 px-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <?php foreach ($services as $s): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="py-3.5 px-3 font-mono text-slate-400">#<?= (int)$s['id'] ?></td>
                            <td class="py-3.5 px-3 font-semibold text-slate-900">
                                <?= e($s['name']) ?>
                                <span class="block text-[10px] text-slate-400 font-normal"><?= e($s['category_name']) ?></span>
                            </td>
                            <td class="py-3.5 px-3 font-extrabold text-blue-600 tabular-nums">₹<?= number_format((float)$s['rate_per_1000'], 2) ?></td>
                            <td class="py-3.5 px-3 text-slate-500 tabular-nums"><?= number_format($s['min_quantity']) ?> / <?= number_format($s['max_quantity']) ?></td>
                            <td class="py-3.5 px-3"><span class="px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 text-[10px] font-bold"><?= e($s['speed']) ?></span></td>
                            <td class="py-3.5 px-3 text-right">
                                <a href="/user/new-order.php?service_id=<?= (int)$s['id'] ?>" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-bold text-[11px] shadow-sm transition-all">Order</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
