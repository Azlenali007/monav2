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

$stmtComp = $db->prepare("SELECT COUNT(*) AS completed_orders FROM orders WHERE user_id = :uid AND status = 'completed'");
$stmtComp->execute(['uid' => $user['id']]);
$completedOrders = (int)($stmtComp->fetch()['completed_orders'] ?? 0);

$stmtTix = $db->prepare("SELECT COUNT(*) AS active_tickets FROM tickets WHERE user_id = :uid AND status IN ('open', 'in_progress')");
$stmtTix->execute(['uid' => $user['id']]);
$activeTickets = (int)($stmtTix->fetch()['active_tickets'] ?? 0);

$totalSpent = (float)($user['spent'] ?? 0);

// Fetch recent 4 orders
$stmt = $db->prepare("
    SELECT o.*, s.name AS service_name, c.platform
    FROM orders o
    JOIN services s ON o.service_id = s.id
    JOIN categories c ON s.category_id = c.id
    WHERE o.user_id = :uid
    ORDER BY o.created_at DESC
    LIMIT 4
");
$stmt->execute(['uid' => $user['id']]);
$recentOrders = $stmt->fetchAll();

// Check for active popup announcements for logged-in user
$activeAnnouncement = null;
try {
    $dismissedSession = $_SESSION['dismissed_announcements'] ?? [];
    $stmtAnnounce = $db->prepare("
        SELECT a.* 
        FROM announcements a
        LEFT JOIN user_announcement_dismissals d ON a.id = d.announcement_id AND d.user_id = :uid
        WHERE a.status = 'active'
          AND (a.show_once = 0 OR d.user_id IS NULL)
          AND (a.starts_at IS NULL OR a.starts_at <= NOW())
          AND (a.expires_at IS NULL OR a.expires_at >= NOW())
        ORDER BY a.id DESC
        LIMIT 1
    ");
    $stmtAnnounce->execute(['uid' => $user['id']]);
    $candidate = $stmtAnnounce->fetch();
    if ($candidate && !isset($dismissedSession[(int)$candidate['id']])) {
        $activeAnnouncement = $candidate;
    }
} catch (Exception $e) {
    error_log("Announcement check: " . $e->getMessage());
}

$pageTitle = "Dashboard - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-6xl mx-auto space-y-8 pb-8">
    <!-- User Welcome & Balance Hero Banner -->
    <div class="relative bg-gradient-to-br from-white via-sky-50/40 to-blue-50/30 rounded-3xl p-6 sm:p-8 border border-white/80 shadow-[0_20px_50px_-15px_rgba(37,99,235,0.08)] overflow-hidden">
        <!-- Ambient decorative glows -->
        <div class="absolute -top-16 -right-16 w-64 h-64 rounded-full bg-blue-500/10 blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-16 -left-16 w-56 h-56 rounded-full bg-indigo-500/10 blur-3xl pointer-events-none"></div>

        <div class="relative flex flex-col md:flex-row items-start md:items-center justify-between gap-6 pb-6 border-b border-slate-200/60">
            <!-- User Info Profile Card -->
            <div class="flex items-center gap-4">
                <div class="relative">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-tr from-blue-600 via-indigo-600 to-blue-700 flex items-center justify-center text-white text-2xl font-black shadow-lg shadow-blue-500/25 border-2 border-white ring-4 ring-blue-50">
                        <?= strtoupper(substr($user['username'], 0, 1)) ?>
                    </div>
                    <span class="absolute -bottom-1 -right-1 w-4 h-4 rounded-full bg-emerald-500 border-2 border-white shadow-xs" title="Online"></span>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-slate-400">Welcome back,</span>
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-50 text-emerald-600 border border-emerald-200/70 shadow-xs">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Active
                        </span>
                    </div>
                    <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight flex items-center gap-2">
                        <span><?= e($user['username']) ?></span>
                        <svg class="w-5 h-5 text-blue-500 shrink-0" fill="currentColor" viewBox="0 0 20 20" title="Verified Member">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                    </h1>
                    <div class="flex flex-wrap items-center gap-2.5 text-xs text-slate-500 mt-1">
                        <span class="font-mono bg-slate-100/90 text-slate-700 px-2 py-0.5 rounded-md font-bold">UID #<?= (int)$user['id'] ?></span>
                        <span class="text-slate-300">•</span>
                        <span class="font-semibold text-blue-600 flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            Verified Client
                        </span>
                    </div>
                </div>
            </div>

            <!-- Balance & Fast Actions -->
            <div class="flex flex-wrap sm:flex-nowrap items-center gap-3 w-full md:w-auto">
                <div class="bg-white/95 backdrop-blur-md px-5 py-3.5 rounded-2xl border border-slate-200/80 shadow-[0_4px_20px_-4px_rgba(15,23,42,0.06)] flex-1 sm:flex-none min-w-[170px]">
                    <div class="flex items-center justify-between gap-2 mb-0.5">
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Wallet Balance</span>
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    </div>
                    <div class="text-2xl sm:text-3xl font-black text-slate-900 tabular-nums font-mono-nums tracking-tight">
                        <?= format_currency($user['balance']) ?>
                    </div>
                </div>
                <div class="flex items-center gap-2.5 flex-1 sm:flex-none">
                    <a href="/user/add-funds.php" class="px-5 py-3.5 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 active:scale-95 text-white font-black text-xs rounded-2xl shadow-lg shadow-blue-500/25 transition-all flex items-center justify-center gap-2 flex-1 whitespace-nowrap">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                        <span>Add Funds</span>
                    </a>
                    <a href="/user/new-order.php" class="px-5 py-3.5 bg-slate-900 hover:bg-slate-800 active:scale-95 text-white font-black text-xs rounded-2xl shadow-md transition-all flex items-center justify-center gap-2 flex-1 whitespace-nowrap">
                        <svg class="w-4 h-4 text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/></svg>
                        <span>New Order</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- 4 Key Metric Cards with Rich Icons -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 pt-6">
            <!-- 1. Total Orders -->
            <div class="bg-white/90 backdrop-blur-sm p-4 sm:p-5 rounded-2xl border border-slate-200/70 shadow-xs hover:shadow-md hover:border-blue-200 transition-all group">
                <div class="flex items-center justify-between text-slate-400 mb-2">
                    <span class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500">Total Orders</span>
                    <div class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    </div>
                </div>
                <div class="text-2xl sm:text-3xl font-black text-slate-900 tabular-nums font-mono-nums"><?= number_format($totalOrders) ?></div>
                <span class="text-[11px] text-slate-400 font-medium block mt-0.5">Campaigns launched</span>
            </div>

            <!-- 2. Completed -->
            <div class="bg-white/90 backdrop-blur-sm p-4 sm:p-5 rounded-2xl border border-slate-200/70 shadow-xs hover:shadow-md hover:border-emerald-200 transition-all group">
                <div class="flex items-center justify-between text-slate-400 mb-2">
                    <span class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500">Completed</span>
                    <div class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>
                <div class="text-2xl sm:text-3xl font-black text-emerald-600 tabular-nums font-mono-nums"><?= number_format($completedOrders) ?></div>
                <span class="text-[11px] text-slate-400 font-medium block mt-0.5">Fully delivered</span>
            </div>

            <!-- 3. Total Spent -->
            <div class="bg-white/90 backdrop-blur-sm p-4 sm:p-5 rounded-2xl border border-slate-200/70 shadow-xs hover:shadow-md hover:border-indigo-200 transition-all group">
                <div class="flex items-center justify-between text-slate-400 mb-2">
                    <span class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500">Total Spent</span>
                    <div class="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>
                <div class="text-2xl sm:text-3xl font-black text-blue-700 tabular-nums font-mono-nums"><?= format_currency($totalSpent) ?></div>
                <span class="text-[11px] text-slate-400 font-medium block mt-0.5">Lifetime spending</span>
            </div>

            <!-- 4. Active Tickets -->
            <div class="bg-white/90 backdrop-blur-sm p-4 sm:p-5 rounded-2xl border border-slate-200/70 shadow-xs hover:shadow-md hover:border-purple-200 transition-all group">
                <div class="flex items-center justify-between text-slate-400 mb-2">
                    <span class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500">Active Tickets</span>
                    <div class="w-8 h-8 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center group-hover:scale-110 transition-transform">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                    </div>
                </div>
                <div class="text-2xl sm:text-3xl font-black text-slate-900 tabular-nums font-mono-nums"><?= number_format($activeTickets) ?></div>
                <span class="text-[11px] text-slate-400 font-medium block mt-0.5">24/7 Support queue</span>
            </div>
        </div>
    </div>

    <!-- 3D Perspective Card Carousel with Smooth Viewport Expansion -->
    <div x-data="cardCarousel()" class="relative w-full overflow-hidden select-none py-2" style="perspective: 1100px; -webkit-perspective: 1100px;">
        <div class="flex items-center justify-between mb-4 px-2">
            <div>
                <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest flex items-center gap-2">
                    <span>Main Hub</span>
                    <span class="px-2 py-0.5 rounded-full bg-blue-50 text-blue-600 text-[10px] font-extrabold border border-blue-200/60">Interactive</span>
                </h3>
                <p class="text-xs text-slate-500 hidden sm:block mt-0.5">Tap the center card to open, or swipe to browse</p>
            </div>
            <!-- Navigation controls -->
            <div class="flex items-center gap-2">
                <button type="button" @click="prev()" aria-label="Previous card" class="w-9 h-9 rounded-2xl bg-white border border-slate-200/80 shadow-xs flex items-center justify-center text-slate-700 hover:bg-slate-50 hover:text-blue-600 hover:border-blue-300 active:scale-95 transition-all cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <button type="button" @click="next()" aria-label="Next card" class="w-9 h-9 rounded-2xl bg-white border border-slate-200/80 shadow-xs flex items-center justify-center text-slate-700 hover:bg-slate-50 hover:text-blue-600 hover:border-blue-300 active:scale-95 transition-all cursor-pointer">
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
                <div class="absolute w-[270px] sm:w-[330px] md:w-[360px] h-[250px] sm:h-[280px] md:h-[300px] rounded-3xl p-6 sm:p-7 text-white shadow-2xl transition-all duration-500 ease-[cubic-bezier(0.25,1,0.5,1)] flex flex-col justify-between cursor-pointer border border-white/20 overflow-hidden group"
                     :class="'bg-gradient-to-br ' + item.bgClass"
                     :style="getCardStyle(index)"
                     :data-card-index="index"
                     @click="cardClick($event, index, item.link)">
                    
                    <!-- Ambient Inner Lighting -->
                    <div class="absolute -top-12 -right-12 w-32 h-32 rounded-full bg-white/20 blur-2xl pointer-events-none"></div>

                    <!-- Top row: Icon and Badge -->
                    <div class="relative flex items-start justify-between">
                        <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-2xl bg-white/20 backdrop-blur-md flex items-center justify-center text-2xl sm:text-3xl shadow-inner border border-white/25">
                            <!-- SVGs or dynamic icon -->
                            <template x-if="item.id === 'add-funds'">
                                <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                            </template>
                            <template x-if="item.id === 'new-order'">
                                <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </template>
                            <template x-if="item.id === 'my-orders'">
                                <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                            </template>
                            <template x-if="item.id === 'services'">
                                <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                            </template>
                            <template x-if="item.id === 'support'">
                                <svg class="w-6 h-6 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                            </template>
                        </div>
                        <span class="px-3 py-1 rounded-full bg-white/20 backdrop-blur-md text-[10px] font-black uppercase tracking-wider text-white border border-white/25 shadow-xs" x-text="item.badge"></span>
                    </div>

                    <!-- Middle: Titles -->
                    <div class="relative">
                        <h2 class="text-2xl sm:text-3xl font-black tracking-tight drop-shadow-xs" x-text="item.title"></h2>
                        <p class="text-xs sm:text-sm text-white/90 mt-1 font-medium line-clamp-1" x-text="item.subtitle"></p>
                    </div>

                    <!-- Bottom: Action link & arrow -->
                    <div class="relative flex items-center justify-between text-xs sm:text-sm font-bold text-white pt-3 border-t border-white/20">
                        <span class="font-extrabold tracking-wide uppercase text-[11px]" x-text="item.action || 'Open'"></span>
                        <div class="w-7 h-7 rounded-full bg-white/20 backdrop-blur-md flex items-center justify-center group-hover:bg-white group-hover:text-slate-900 transition-colors shadow-xs">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                        </div>
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
                        class="h-2 rounded-full transition-all duration-300 cursor-pointer"
                        :class="active === idx ? 'w-8 bg-blue-600 shadow-xs' : 'w-2 bg-slate-300 hover:bg-slate-400'">
                </button>
            </template>
        </div>
    </div>

    <!-- Quick Access Hub -->
    <div>
        <div class="flex items-center justify-between mb-4 px-1">
            <div class="flex items-center gap-2">
                <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest">Portal Shortcuts</h3>
                <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[10px] font-bold">6 Tools</span>
            </div>
            <span class="text-xs text-slate-400 font-medium">Quick jump to essential tools</span>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4">
            <!-- 1. New Order -->
            <a href="/user/new-order.php" class="bg-white p-4 sm:p-5 rounded-3xl border border-slate-200/80 shadow-[0_4px_20px_-4px_rgba(15,23,42,0.04)] hover:shadow-lg hover:shadow-blue-500/10 hover:border-blue-300 hover:-translate-y-1 transition-all group flex flex-col justify-between">
                <div class="flex items-center justify-between mb-3">
                    <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 text-white flex items-center justify-center shadow-md shadow-blue-500/25 group-hover:scale-110 transition-transform">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <div class="w-6 h-6 rounded-full bg-slate-50 text-slate-400 group-hover:bg-blue-600 group-hover:text-white flex items-center justify-center transition-colors">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                    </div>
                </div>
                <div>
                    <h4 class="text-xs sm:text-sm font-extrabold text-slate-900 group-hover:text-blue-600 transition-colors">New Order</h4>
                    <p class="text-[11px] text-slate-400 mt-0.5 font-medium">Instant delivery</p>
                </div>
            </a>

            <!-- 2. Services Hub -->
            <a href="/user/services.php" class="bg-white p-4 sm:p-5 rounded-3xl border border-slate-200/80 shadow-[0_4px_20px_-4px_rgba(15,23,42,0.04)] hover:shadow-lg hover:shadow-pink-500/10 hover:border-pink-300 hover:-translate-y-1 transition-all group flex flex-col justify-between">
                <div class="flex items-center justify-between mb-3">
                    <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-pink-500 to-rose-600 text-white flex items-center justify-center shadow-md shadow-pink-500/25 group-hover:scale-110 transition-transform">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                    </div>
                    <div class="w-6 h-6 rounded-full bg-slate-50 text-slate-400 group-hover:bg-pink-600 group-hover:text-white flex items-center justify-center transition-colors">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                    </div>
                </div>
                <div>
                    <h4 class="text-xs sm:text-sm font-extrabold text-slate-900 group-hover:text-pink-600 transition-colors">Services</h4>
                    <p class="text-[11px] text-slate-400 mt-0.5 font-medium">Wholesale rates</p>
                </div>
            </a>

            <!-- 3. Track Orders -->
            <a href="/user/orders.php" class="bg-white p-4 sm:p-5 rounded-3xl border border-slate-200/80 shadow-[0_4px_20px_-4px_rgba(15,23,42,0.04)] hover:shadow-lg hover:shadow-amber-500/10 hover:border-amber-300 hover:-translate-y-1 transition-all group flex flex-col justify-between">
                <div class="flex items-center justify-between mb-3">
                    <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-amber-500 to-orange-600 text-white flex items-center justify-center shadow-md shadow-amber-500/25 group-hover:scale-110 transition-transform">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    </div>
                    <div class="w-6 h-6 rounded-full bg-slate-50 text-slate-400 group-hover:bg-amber-600 group-hover:text-white flex items-center justify-center transition-colors">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                    </div>
                </div>
                <div>
                    <h4 class="text-xs sm:text-sm font-extrabold text-slate-900 group-hover:text-amber-600 transition-colors">My Orders</h4>
                    <p class="text-[11px] text-slate-400 mt-0.5 font-medium">Live tracking</p>
                </div>
            </a>

            <!-- 4. Add Funds -->
            <a href="/user/add-funds.php" class="bg-white p-4 sm:p-5 rounded-3xl border border-slate-200/80 shadow-[0_4px_20px_-4px_rgba(15,23,42,0.04)] hover:shadow-lg hover:shadow-emerald-500/10 hover:border-emerald-300 hover:-translate-y-1 transition-all group flex flex-col justify-between">
                <div class="flex items-center justify-between mb-3">
                    <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-emerald-500 to-teal-600 text-white flex items-center justify-center shadow-md shadow-emerald-500/25 group-hover:scale-110 transition-transform">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    </div>
                    <div class="w-6 h-6 rounded-full bg-slate-50 text-slate-400 group-hover:bg-emerald-600 group-hover:text-white flex items-center justify-center transition-colors">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                    </div>
                </div>
                <div>
                    <h4 class="text-xs sm:text-sm font-extrabold text-slate-900 group-hover:text-emerald-600 transition-colors">Add Funds</h4>
                    <p class="text-[11px] text-slate-400 mt-0.5 font-medium">Auto-credit</p>
                </div>
            </a>

            <!-- 5. Transactions -->
            <a href="/user/transactions.php" class="bg-white p-4 sm:p-5 rounded-3xl border border-slate-200/80 shadow-[0_4px_20px_-4px_rgba(15,23,42,0.04)] hover:shadow-lg hover:shadow-indigo-500/10 hover:border-indigo-300 hover:-translate-y-1 transition-all group flex flex-col justify-between">
                <div class="flex items-center justify-between mb-3">
                    <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-indigo-500 to-blue-600 text-white flex items-center justify-center shadow-md shadow-indigo-500/25 group-hover:scale-110 transition-transform">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <div class="w-6 h-6 rounded-full bg-slate-50 text-slate-400 group-hover:bg-indigo-600 group-hover:text-white flex items-center justify-center transition-colors">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                    </div>
                </div>
                <div>
                    <h4 class="text-xs sm:text-sm font-extrabold text-slate-900 group-hover:text-indigo-600 transition-colors">Ledger</h4>
                    <p class="text-[11px] text-slate-400 mt-0.5 font-medium">Payment log</p>
                </div>
            </a>

            <!-- 6. 24/7 Support -->
            <a href="/user/tickets.php" class="bg-white p-4 sm:p-5 rounded-3xl border border-slate-200/80 shadow-[0_4px_20px_-4px_rgba(15,23,42,0.04)] hover:shadow-lg hover:shadow-purple-500/10 hover:border-purple-300 hover:-translate-y-1 transition-all group flex flex-col justify-between">
                <div class="flex items-center justify-between mb-3">
                    <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-purple-500 to-violet-600 text-white flex items-center justify-center shadow-md shadow-purple-500/25 group-hover:scale-110 transition-transform">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                    </div>
                    <div class="w-6 h-6 rounded-full bg-slate-50 text-slate-400 group-hover:bg-purple-600 group-hover:text-white flex items-center justify-center transition-colors">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                    </div>
                </div>
                <div>
                    <h4 class="text-xs sm:text-sm font-extrabold text-slate-900 group-hover:text-purple-600 transition-colors">Support</h4>
                    <p class="text-[11px] text-slate-400 mt-0.5 font-medium">Dedicated desk</p>
                </div>
            </a>
        </div>
    </div>

    <!-- Recent Orders Section -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm">
        <div class="flex items-center justify-between mb-5 pb-4 border-b border-slate-100">
            <div>
                <h3 class="text-lg font-black text-slate-900 tracking-tight flex items-center gap-2">
                    <span>Recent Orders</span>
                    <span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
                </h3>
                <p class="text-xs text-slate-400 mt-0.5">Real-time status updates of your latest campaigns</p>
            </div>
            <a href="/user/orders.php" class="px-3.5 py-1.5 rounded-xl text-xs font-black text-blue-600 hover:bg-blue-50 transition-colors flex items-center gap-1.5">
                <span>View All Orders</span>
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
            </a>
        </div>

        <?php if (empty($recentOrders)): ?>
            <div class="py-12 text-center">
                <div class="w-14 h-14 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center text-2xl mx-auto mb-3 shadow-xs">
                    <svg class="w-7 h-7 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                </div>
                <h4 class="text-sm font-bold text-slate-900">No recent orders yet</h4>
                <p class="text-xs text-slate-400 max-w-sm mx-auto mt-1">Ready to boost your social media? Place your first order with instant start.</p>
                <a href="/user/new-order.php" class="mt-4 inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-black text-xs rounded-xl shadow-md shadow-blue-500/25 transition-all">
                    <svg class="w-4 h-4 text-amber-300" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/></svg>
                    <span>Launch First Campaign</span>
                </a>
            </div>
        <?php else: ?>
            <div class="divide-y divide-slate-100">
                <?php foreach ($recentOrders as $ro): 
                    $plat = strtolower($ro['platform']);
                    $platStyle = match ($plat) {
                        'instagram' => ['bg' => 'bg-pink-50 text-pink-600 border-pink-200/80', 'name' => 'Instagram'],
                        'youtube' => ['bg' => 'bg-red-50 text-red-600 border-red-200/80', 'name' => 'YouTube'],
                        'telegram' => ['bg' => 'bg-sky-50 text-sky-600 border-sky-200/80', 'name' => 'Telegram'],
                        'facebook' => ['bg' => 'bg-blue-50 text-blue-600 border-blue-200/80', 'name' => 'Facebook'],
                        'tiktok' => ['bg' => 'bg-slate-100 text-slate-900 border-slate-200', 'name' => 'TikTok'],
                        'twitter' => ['bg' => 'bg-slate-900 text-white border-slate-700', 'name' => 'X / Twitter'],
                        default => ['bg' => 'bg-indigo-50 text-indigo-600 border-indigo-200/80', 'name' => ucfirst($plat)]
                    };

                    $statusClass = match ($ro['status']) {
                        'completed' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                        'processing' => 'bg-sky-50 text-sky-700 border-sky-200',
                        'in_progress' => 'bg-blue-50 text-blue-700 border-blue-200',
                        'pending' => 'bg-amber-50 text-amber-700 border-amber-200',
                        'canceled', 'refunded' => 'bg-rose-50 text-rose-700 border-rose-200',
                        default => 'bg-slate-100 text-slate-700 border-slate-200'
                    };
                ?>
                    <div class="py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-slate-50/80 px-3 sm:px-4 -mx-1 sm:-mx-2 rounded-2xl transition-colors group">
                        <div class="flex items-center gap-3.5">
                            <!-- Platform Specific SVG Icon Box -->
                            <div class="w-11 h-11 rounded-2xl flex items-center justify-center shrink-0 border shadow-xs <?= $platStyle['bg'] ?>">
                                <?php if ($plat === 'instagram'): ?>
                                    <svg class="w-5 h-5 text-pink-600" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                                <?php elseif ($plat === 'youtube'): ?>
                                    <svg class="w-5 h-5 text-red-600" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                                <?php elseif ($plat === 'telegram'): ?>
                                    <svg class="w-5 h-5 text-sky-500" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.37.74-.56 2.92-1.27 4.86-2.11 5.83-2.52 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06-.01.19-.03.38z"/></svg>
                                <?php elseif ($plat === 'tiktok'): ?>
                                    <svg class="w-5 h-5 text-slate-900" viewBox="0 0 24 24" fill="currentColor"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.24 1.07-.14 1.61.24 1.16 1.18 2.09 2.35 2.33.88.19 1.82.07 2.58-.41.74-.46 1.25-1.24 1.39-2.09.11-.64.1-1.28.1-1.92.01-5.06.01-10.12.01-15.18h.07z"/></svg>
                                <?php elseif ($plat === 'facebook'): ?>
                                    <svg class="w-5 h-5 text-blue-600" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                <?php else: ?>
                                    <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h4 class="text-xs sm:text-sm font-extrabold text-slate-900 group-hover:text-blue-600 transition-colors line-clamp-1"><?= e($ro['service_name']) ?></h4>
                                <div class="flex items-center gap-2 text-[11px] text-slate-400 mt-0.5">
                                    <span class="font-mono bg-slate-100 text-slate-600 px-1.5 py-0.2 rounded font-bold">#<?= (int)$ro['id'] ?></span>
                                    <span>•</span>
                                    <span><?= date('d M Y, h:i A', strtotime($ro['created_at'])) ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between sm:justify-end gap-4 shrink-0 pl-14 sm:pl-0">
                            <div class="text-left sm:text-right">
                                <span class="text-xs sm:text-sm font-black text-slate-900 block tabular-nums font-mono-nums"><?= format_currency($ro['charge']) ?></span>
                                <span class="text-[11px] text-slate-400 font-semibold"><?= number_format($ro['quantity']) ?> qty</span>
                            </div>
                            <span class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-wider border shadow-xs <?= $statusClass ?>">
                                <?= e(str_replace('_', ' ', $ro['status'])) ?>
                            </span>
                            <a href="/user/order-details.php?id=<?= (int)$ro['id'] ?>" class="text-slate-400 hover:text-blue-600 p-2 rounded-xl hover:bg-white hover:shadow-xs transition-all" title="View Order Details">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($activeAnnouncement): ?>
<!-- ✨ Modern User Announcement / Notification Popup Modal -->
<div x-data="userAnnouncementPopup(<?= htmlspecialchars(json_encode($activeAnnouncement), ENT_QUOTES, 'UTF-8') ?>)"
     x-show="show"
     x-cloak
     class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6"
     style="display: none;"
     @keydown.escape.window="dismiss(false)">
    
    <!-- Dark Backdrop with Soft Blur -->
    <div x-show="show"
         x-transition:enter="transition-opacity ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="dismiss(false)"
         class="fixed inset-0 bg-slate-950/60 backdrop-blur-sm"></div>

    <!-- Popup Card Container -->
    <div x-show="show"
         x-transition:enter="transition ease-out duration-300 transform"
         x-transition:enter-start="opacity-0 translate-y-4 scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-200 transform"
         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
         x-transition:leave-end="opacity-0 translate-y-4 scale-95"
         class="relative bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-200/90 z-10 overflow-hidden"
         @click.outside="dismiss(false)">
        
        <!-- Subtle Decorative Ambient Light Glow -->
        <div class="absolute -top-14 -right-14 w-40 h-40 rounded-full bg-blue-500/10 blur-2xl pointer-events-none"></div>

        <!-- Top Right Close Button -->
        <button type="button" 
                @click="dismiss(false)" 
                aria-label="Close announcement"
                class="absolute top-4 right-4 w-9 h-9 rounded-full bg-slate-100 text-slate-400 hover:text-slate-700 hover:bg-slate-200 flex items-center justify-center transition-colors cursor-pointer">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>

        <!-- Badge & Icon Header -->
        <div class="flex items-center gap-3.5 mb-5">
            <!-- Dynamic Themed Icon Box -->
            <div class="w-13 h-13 rounded-2xl flex items-center justify-center text-2xl font-bold shadow-md shrink-0"
                 :class="{
                    'bg-sky-50 text-sky-600 border border-sky-200': announcement.type === 'telegram',
                    'bg-emerald-50 text-emerald-600 border border-emerald-200': announcement.type === 'offer',
                    'bg-purple-50 text-purple-600 border border-purple-200': announcement.type === 'service',
                    'bg-amber-50 text-amber-600 border border-amber-200': announcement.type === 'maintenance',
                    'bg-blue-50 text-blue-600 border border-blue-200': announcement.type === 'update',
                    'bg-indigo-50 text-indigo-600 border border-indigo-200': announcement.type === 'announcement' || announcement.type === 'system'
                 }">
                <!-- Telegram Icon -->
                <template x-if="announcement.type === 'telegram'">
                    <svg class="w-6 h-6 text-sky-500" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.37.74-.56 2.92-1.27 4.86-2.11 5.83-2.52 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06-.01.19-.03.38z"/>
                    </svg>
                </template>

                <!-- Offer / Bonus Icon -->
                <template x-if="announcement.type === 'offer'">
                    <span class="text-2xl">🎁</span>
                </template>

                <!-- Service Alert Icon -->
                <template x-if="announcement.type === 'service'">
                    <span class="text-2xl">⚡</span>
                </template>

                <!-- Maintenance Icon -->
                <template x-if="announcement.type === 'maintenance'">
                    <span class="text-2xl">⚠️</span>
                </template>

                <!-- Update Icon -->
                <template x-if="announcement.type === 'update'">
                    <span class="text-2xl">🚀</span>
                </template>

                <!-- General Announcement / System Icon -->
                <template x-if="announcement.type === 'announcement' || announcement.type === 'system'">
                    <span class="text-2xl">📢</span>
                </template>
            </div>

            <div>
                <!-- Category/Badge Pill -->
                <span class="inline-block px-3 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider shadow-xs"
                      :class="{
                        'bg-sky-50 text-sky-700 border border-sky-200': announcement.type === 'telegram',
                        'bg-emerald-50 text-emerald-700 border border-emerald-200': announcement.type === 'offer',
                        'bg-purple-50 text-purple-700 border border-purple-200': announcement.type === 'service',
                        'bg-amber-50 text-amber-700 border border-amber-200': announcement.type === 'maintenance',
                        'bg-blue-50 text-blue-700 border border-blue-200': announcement.type === 'update',
                        'bg-indigo-50 text-indigo-700 border border-indigo-200': announcement.type === 'announcement' || announcement.type === 'system'
                      }"
                      x-text="announcement.badge_text || 'Announcement'">
                </span>
                <span class="text-[11px] text-slate-400 block font-bold mt-0.5"><?= e(app_name()) ?> Notice</span>
            </div>
        </div>

        <!-- Title & Formatted Message -->
        <div class="space-y-2.5">
            <h3 class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight leading-snug" 
                x-text="announcement.title"></h3>
            <div class="text-xs sm:text-sm text-slate-600 leading-relaxed font-normal whitespace-pre-line" 
                x-text="announcement.message"></div>
        </div>

        <!-- Action Button & Dismiss Controls -->
        <div class="pt-6 mt-6 border-t border-slate-100 flex flex-col-reverse sm:flex-row items-center justify-between gap-3">
            <button type="button" 
                    @click="dismiss(true)" 
                    class="w-full sm:w-auto px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition-colors text-center cursor-pointer">
                Don't show again
            </button>

            <template x-if="announcement.btn_text && announcement.btn_link">
                <a :href="announcement.btn_link" 
                   @click="dismiss(true)"
                   :target="announcement.btn_link.startsWith('http') ? '_blank' : '_self'" 
                   class="w-full sm:w-auto px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-black text-xs rounded-xl shadow-lg shadow-blue-500/25 transition-all flex items-center justify-center gap-2 text-center cursor-pointer">
                    <span x-text="announcement.btn_text"></span>
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </a>
            </template>
        </div>
    </div>
</div>

<script>
/**
 * User Announcement Popup Controller
 * Manages display, smooth animation, and background AJAX dismissal logging
 */
function userAnnouncementPopup(data) {
    return {
        show: !!data,
        announcement: data || {},

        dismiss(dontShowAgain = false) {
            this.show = false;
            if (!this.announcement || !this.announcement.id) return;

            // Send async dismissal request to server
            fetch('/user/api/dismiss-announcement.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    announcement_id: this.announcement.id,
                    dont_show_again: dontShowAgain || this.announcement.show_once == 1
                })
            }).catch(err => {
                console.log('Announcement dismissed locally', err);
            });
        }
    };
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
