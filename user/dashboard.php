<?php
/**
 * User Dashboard (Card Hub)
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

// Fetch summary metrics
$stmt = $db->prepare("SELECT COUNT(*) AS total_orders FROM orders WHERE user_id = :uid");
$stmt->execute(['uid' => $user['id']]);
$totalOrders = (int)($stmt->fetch()['total_orders'] ?? 0);

// Fetch recent 3 orders
$stmt = $db->prepare("
    SELECT o.*, s.name AS service_name, c.platform
    FROM orders o
    JOIN services s ON o.service_id = s.id
    JOIN categories c ON s.category_id = c.id
    WHERE o.user_id = :uid
    ORDER BY o.created_at DESC
    LIMIT 3
");
$stmt->execute(['uid' => $user['id']]);
$recentOrders = $stmt->fetchAll();

$pageTitle = "Dashboard - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-6xl mx-auto space-y-8">
    <!-- User Welcome & Balance Bar -->
    <div class="bg-white rounded-3xl p-6 md:p-8 shadow-sm border border-slate-200/80 flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
        <div class="flex items-center gap-4">
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 flex items-center justify-center text-white text-2xl font-bold shadow-md shadow-blue-500/20">
                <?= strtoupper(substr($user['username'], 0, 1)) ?>
            </div>
            <div>
                <span class="text-xs font-semibold text-slate-400">Welcome back,</span>
                <h1 class="text-2xl font-extrabold text-slate-900"><?= e($user['username']) ?></h1>
                <span class="text-xs font-medium text-slate-500">User ID: <span class="font-bold text-slate-700">#<?= (int)$user['id'] ?></span></span>
            </div>
        </div>
        <div class="flex items-center gap-4 bg-slate-50 p-3 rounded-2xl border border-slate-200/60 w-full md:w-auto justify-between md:justify-start">
            <div class="text-left">
                <span class="text-xs font-semibold text-slate-500 block">Your Balance</span>
                <span class="text-2xl font-extrabold text-slate-900 tabular-nums"><?= format_currency($user['balance']) ?></span>
            </div>
            <a href="/user/add-funds.php" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-md shadow-blue-500/25 transition-all flex items-center gap-1.5 whitespace-nowrap">
                <span>+</span> Add Funds
            </a>
        </div>
    </div>

    <!-- 3D Perspective Card Carousel with Smooth Viewport Expansion -->
    <div x-data="cardCarousel()" class="relative w-full overflow-hidden select-none py-2" style="perspective: 1100px; -webkit-perspective: 1100px;">
        <div class="flex items-center justify-between mb-3 px-2">
            <div>
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest">Main Hub</h3>
                <p class="text-xs text-slate-500 hidden sm:block">Tap the center card to open, or swipe to browse</p>
            </div>
            <!-- Navigation controls -->
            <div class="flex items-center gap-2">
                <button type="button" @click="prev()" aria-label="Previous card" class="w-8 h-8 rounded-full bg-white border border-slate-200/80 shadow-sm flex items-center justify-center text-slate-700 hover:bg-slate-50 hover:text-blue-600 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <button type="button" @click="next()" aria-label="Next card" class="w-8 h-8 rounded-full bg-white border border-slate-200/80 shadow-sm flex items-center justify-center text-slate-700 hover:bg-slate-50 hover:text-blue-600 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
        </div>

        <!-- 3D Stage Container -->
        <div class="relative w-full h-[280px] sm:h-[310px] md:h-[330px] flex items-center justify-center overflow-visible" 
             style="transform-style: preserve-3d; -webkit-transform-style: preserve-3d;"
             @touchstart="touchStart($event)"
             @touchmove="touchMove($event)"
             @touchend="touchEnd($event)"
             @mousedown="dragStart($event)"
             @mousemove="dragMove($event)"
             @mouseup="dragEnd($event)"
             @mouseleave="dragEnd($event)">
            
            <template x-for="(item, index) in items" :key="item.id">
                <div class="absolute w-[270px] sm:w-[330px] md:w-[360px] h-[250px] sm:h-[280px] md:h-[300px] rounded-3xl p-6 sm:p-7 text-white shadow-2xl transition-all duration-500 ease-[cubic-bezier(0.25,1,0.5,1)] flex flex-col justify-between cursor-pointer"
                     :class="'bg-gradient-to-br ' + item.bgClass"
                     :style="getCardStyle(index)"
                     :data-card-index="index"
                     @click="cardClick($event, index, item.link)">
                    
                    <!-- Top row: Icon and Badge -->
                    <div class="flex items-start justify-between">
                        <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl bg-white/20 backdrop-blur-md flex items-center justify-center text-2xl sm:text-3xl shadow-inner" x-text="item.icon"></div>
                        <span class="px-3 py-1 rounded-full bg-white/20 backdrop-blur-md text-[11px] font-bold tracking-wide text-white/95" x-text="item.badge"></span>
                    </div>

                    <!-- Middle: Titles -->
                    <div>
                        <h2 class="text-2xl sm:text-3xl font-black tracking-tight" x-text="item.title"></h2>
                        <p class="text-xs sm:text-sm text-white/80 mt-1 font-medium" x-text="item.subtitle"></p>
                    </div>

                    <!-- Bottom: Action link & arrow -->
                    <div class="flex items-center justify-between text-xs sm:text-sm font-bold text-white pt-2 border-t border-white/15">
                        <span x-text="item.action"></span>
                        <span class="text-lg group-hover:translate-x-1 transition-transform">&rarr;</span>
                    </div>
                </div>
            </template>
        </div>

        <!-- Carousel Pagination Dots -->
        <div class="flex items-center justify-center gap-2 mt-4">
            <template x-for="(item, idx) in items" :key="'dot-' + item.id">
                <button type="button" 
                        @click="goTo(idx)" 
                        :aria-label="'Go to ' + item.title"
                        class="h-2 rounded-full transition-all duration-300"
                        :class="active === idx ? 'w-7 bg-blue-600' : 'w-2 bg-slate-300 hover:bg-slate-400'">
                </button>
            </template>
        </div>
    </div>

    <!-- Quick Access Hub -->
    <div>
        <h3 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">Quick Access</h3>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <a href="/user/services.php" class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm hover:shadow-md transition-shadow flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-pink-50 text-pink-600 flex items-center justify-center text-lg font-bold">⚡</div>
                <div>
                    <h4 class="text-sm font-bold text-slate-900">Services</h4>
                    <p class="text-[11px] text-slate-500">Browse all services</p>
                </div>
            </a>
            <a href="/user/transactions.php" class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm hover:shadow-md transition-shadow flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-lg font-bold">💳</div>
                <div>
                    <h4 class="text-sm font-bold text-slate-900">Transactions</h4>
                    <p class="text-[11px] text-slate-500">Wallet & history</p>
                </div>
            </a>
            <a href="/user/tickets.php" class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm hover:shadow-md transition-shadow flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-lg font-bold">💬</div>
                <div>
                    <h4 class="text-sm font-bold text-slate-900">Support</h4>
                    <p class="text-[11px] text-slate-500">Get 24/7 help</p>
                </div>
            </a>
            <a href="/user/profile.php" class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-sm hover:shadow-md transition-shadow flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-slate-100 text-slate-700 flex items-center justify-center text-lg font-bold">👤</div>
                <div>
                    <h4 class="text-sm font-bold text-slate-900">Profile</h4>
                    <p class="text-[11px] text-slate-500">Account settings</p>
                </div>
            </a>
        </div>
    </div>

    <!-- Recent Orders Section -->
    <?php if (!empty($recentOrders)): ?>
        <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-slate-900">Recent Orders</h3>
                <a href="/user/orders.php" class="text-xs font-bold text-blue-600 hover:underline">View All &rarr;</a>
            </div>
            <div class="divide-y divide-slate-100">
                <?php foreach ($recentOrders as $ro): ?>
                    <div class="py-3.5 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-slate-100 flex items-center justify-center text-xs font-bold text-slate-700 uppercase">
                                <?= substr($ro['platform'], 0, 2) ?>
                            </div>
                            <div>
                                <h4 class="text-xs font-bold text-slate-900"><?= e($ro['service_name']) ?></h4>
                                <span class="text-[11px] text-slate-400">#<?= (int)$ro['id'] ?> • <?= date('d M Y, h:i A', strtotime($ro['created_at'])) ?></span>
                            </div>
                        </div>
                        <div class="text-right flex items-center gap-3">
                            <div>
                                <span class="text-xs font-bold text-slate-900 block tabular-nums"><?= format_currency($ro['charge']) ?></span>
                                <span class="text-[10px] text-slate-400 font-medium"><?= number_format($ro['quantity']) ?> qty</span>
                            </div>
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?= $ro['status'] === 'completed' ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600' ?>">
                                <?= e($ro['status']) ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
