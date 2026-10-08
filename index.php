<?php
/**
 * Modern Landing Page with Live Service Showcase
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$currentUser = Auth::user();
$isLoggedIn = ($currentUser !== null);
$db = Database::getConnection();

// Query real services from database
$featuredServices = [];
$totalServicesCount = 0;
try {
    $totalServicesCount = (int)($db->query("SELECT COUNT(*) FROM services WHERE status = 'active'")->fetchColumn() ?: 0);
    $stmt = $db->query("
        SELECT s.*, c.name AS category_name, c.platform
        FROM services s
        JOIN categories c ON s.category_id = c.id
        WHERE s.status = 'active'
        ORDER BY c.sort_order ASC, s.rate_per_1000 ASC
        LIMIT 8
    ");
    $featuredServices = $stmt->fetchAll() ?: [];
} catch (Exception $e) {
    $featuredServices = [];
}

// Fallback featured services if database is not yet seeded
if (empty($featuredServices)) {
    $featuredServices = [
        [
            'id' => 101,
            'name' => 'Instagram Followers [Real & Active - High Quality]',
            'category_name' => 'Instagram Growth',
            'platform' => 'instagram',
            'rate_per_1000' => 35.0,
            'min_quantity' => 1000,
            'max_quantity' => 1000000,
            'speed' => 'Starts in 1-2 Hours',
            'description' => 'Real followers from active accounts worldwide with 30 days refill guarantee.'
        ],
        [
            'id' => 201,
            'name' => 'YouTube Views [Real Views - High Retention]',
            'category_name' => 'YouTube Promotion',
            'platform' => 'youtube',
            'rate_per_1000' => 12.0,
            'min_quantity' => 1000,
            'max_quantity' => 5000000,
            'speed' => 'Fast Delivery',
            'description' => 'Monetization-safe real YouTube views with excellent audience retention.'
        ],
        [
            'id' => 301,
            'name' => 'Telegram Members [Real & Active - Instant Start]',
            'category_name' => 'Telegram Marketing',
            'platform' => 'telegram',
            'rate_per_1000' => 45.0,
            'min_quantity' => 500,
            'max_quantity' => 200000,
            'speed' => 'Instant Start',
            'description' => 'High quality Telegram channel and group subscribers. Zero drop guarantee.'
        ],
        [
            'id' => 102,
            'name' => 'Instagram Likes [High Quality - Instant Start]',
            'category_name' => 'Instagram Likes',
            'platform' => 'instagram',
            'rate_per_1000' => 20.0,
            'min_quantity' => 100,
            'max_quantity' => 500000,
            'speed' => 'Instant Start',
            'description' => 'Instant likes on posts, reels and carousels from premium profiles.'
        ],
        [
            'id' => 401,
            'name' => 'TikTok Followers [Real & Active Accounts]',
            'category_name' => 'TikTok Growth',
            'platform' => 'tiktok',
            'rate_per_1000' => 65.0,
            'min_quantity' => 100,
            'max_quantity' => 200000,
            'speed' => 'Instant Start',
            'description' => 'Accelerate your TikTok creator profile with organic-style followers.'
        ],
        [
            'id' => 501,
            'name' => 'Facebook Page Likes & Followers [Non-Drop]',
            'category_name' => 'Facebook Promotion',
            'platform' => 'facebook',
            'rate_per_1000' => 80.0,
            'min_quantity' => 100,
            'max_quantity' => 500000,
            'speed' => 'Instant Start',
            'description' => 'High retention worldwide page likes and followers with 30-day refill.'
        ]
    ];
    $totalServicesCount = count($featuredServices);
}

// Helper for platform styling
function get_landing_platform_theme(string $platform): array {
    $p = strtolower($platform);
    return match ($p) {
        'instagram' => [
            'bg' => 'from-pink-500 via-rose-500 to-purple-600',
            'badge_bg' => 'bg-pink-50 text-pink-700 border-pink-200/80',
            'accent' => 'text-pink-600',
            'border_hover' => 'hover:border-pink-300',
            'label' => 'Instagram',
            'icon' => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>'
        ],
        'youtube' => [
            'bg' => 'from-red-600 to-rose-600',
            'badge_bg' => 'bg-red-50 text-red-700 border-red-200/80',
            'accent' => 'text-red-600',
            'border_hover' => 'hover:border-red-300',
            'label' => 'YouTube',
            'icon' => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>'
        ],
        'telegram' => [
            'bg' => 'from-sky-500 to-blue-600',
            'badge_bg' => 'bg-sky-50 text-sky-700 border-sky-200/80',
            'accent' => 'text-sky-600',
            'border_hover' => 'hover:border-sky-300',
            'label' => 'Telegram',
            'icon' => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221l-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.446 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.121l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.458c.536-.196 1.006.128.832.943z"/></svg>'
        ],
        'facebook' => [
            'bg' => 'from-blue-600 to-indigo-700',
            'badge_bg' => 'bg-blue-50 text-blue-700 border-blue-200/80',
            'accent' => 'text-blue-600',
            'border_hover' => 'hover:border-blue-300',
            'label' => 'Facebook',
            'icon' => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>'
        ],
        'tiktok' => [
            'bg' => 'from-slate-900 via-zinc-800 to-black',
            'badge_bg' => 'bg-slate-100 text-slate-800 border-slate-200/80',
            'accent' => 'text-slate-900',
            'border_hover' => 'hover:border-slate-400',
            'label' => 'TikTok',
            'icon' => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.24 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.89 1.53-.02 2.87-1.12 3.18-2.61.12-.59.13-1.2.12-1.8V.02h-.01z"/></svg>'
        ],
        'twitter' => [
            'bg' => 'from-slate-800 to-slate-900',
            'badge_bg' => 'bg-slate-100 text-slate-800 border-slate-200/80',
            'accent' => 'text-slate-900',
            'border_hover' => 'hover:border-slate-400',
            'label' => 'Twitter (X)',
            'icon' => '<svg class="w-4 h-4 fill-current" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>'
        ],
        default => [
            'bg' => 'from-indigo-600 to-blue-600',
            'badge_bg' => 'bg-indigo-50 text-indigo-700 border-indigo-200/80',
            'accent' => 'text-indigo-600',
            'border_hover' => 'hover:border-indigo-300',
            'label' => ucfirst($platform ?: 'General'),
            'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>'
        ]
    };
}

$pageTitle = "Boost Your Social Media - " . app_name();
require_once __DIR__ . '/includes/header.php';
?>

<!-- Hero Section -->
<section class="py-12 md:py-20 text-center relative overflow-hidden">
    <!-- Accent Badge -->
    <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-blue-50 border border-blue-200/60 text-blue-600 text-xs font-bold uppercase tracking-widest mb-6">
        <svg class="w-4 h-4 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
            <path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/>
        </svg>
        Social Media Growth Platform
    </div>

    <!-- Main Headline -->
    <h1 class="text-4xl sm:text-6xl font-extrabold text-slate-900 tracking-tight max-w-4xl mx-auto leading-tight">
        Boost Your Social Media <br>
        <span class="font-script text-5xl sm:text-7xl font-bold text-blue-600 inline-block -rotate-1 mt-1">With Our SMM Panel</span>
    </h1>

    <p class="mt-6 text-base sm:text-lg text-slate-600 max-w-2xl mx-auto leading-relaxed">
        Get real followers, likes, views and channel growth. Fast, automated, safe and affordable services for all major platforms.
    </p>

    <!-- 4 Key Badges -->
    <div class="mt-8 flex flex-wrap items-center justify-center gap-3 sm:gap-6 text-xs sm:text-sm font-semibold text-slate-700">
        <div class="flex items-center gap-2 bg-white px-4 py-2 rounded-xl shadow-sm border border-slate-200/80">
            <span class="text-blue-600">⚡</span> Instant Delivery
        </div>
        <div class="flex items-center gap-2 bg-white px-4 py-2 rounded-xl shadow-sm border border-slate-200/80">
            <span class="text-blue-600">🔒</span> 100% Safe & Secure
        </div>
        <div class="flex items-center gap-2 bg-white px-4 py-2 rounded-xl shadow-sm border border-slate-200/80">
            <span class="text-amber-500">⭐</span> High Quality Non-Drop
        </div>
        <div class="flex items-center gap-2 bg-white px-4 py-2 rounded-xl shadow-sm border border-slate-200/80">
            <span class="text-blue-600">💬</span> 24/7 Dedicated Support
        </div>
    </div>

    <!-- Top Featured Services Header -->
    <div class="mt-20 max-w-7xl mx-auto px-4 text-left">
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4 mb-8">
            <div>
                <span class="text-xs font-bold text-blue-600 uppercase tracking-widest block mb-1">Featured Packages</span>
                <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900">Trending Services & Best Rates</h2>
                <p class="text-xs sm:text-sm text-slate-500 mt-1">
                    Direct from our high-performance automated catalog. Instant ordering with live progress tracking.
                </p>
            </div>
            <a href="/user/services.php" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-extrabold rounded-xl transition-all self-start sm:self-auto">
                <span>View Full Catalog (<?= number_format($totalServicesCount) ?>+)</span>
                <span>&rarr;</span>
            </a>
        </div>

        <!-- Modern Animated Service Cards Grid (Desktop 2-4 cols, Mobile 1 col) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <?php foreach ($featuredServices as $s): 
                $theme = get_landing_platform_theme($s['platform'] ?? 'other');
                $orderUrl = $isLoggedIn 
                    ? '/user/new-order.php?service_id=' . (int)$s['id'] 
                    : '/login.php?redirect=' . urlencode('/user/new-order.php?service_id=' . (int)$s['id']);
            ?>
                <!-- Service Card -->
                <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm hover:shadow-2xl hover:-translate-y-2 transition-all duration-300 flex flex-col justify-between group relative overflow-hidden <?= $theme['border_hover'] ?>">
                    <!-- Top Gradient Glow Strip -->
                    <div class="absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r <?= $theme['bg'] ?>"></div>

                    <!-- Card Body Top -->
                    <div>
                        <!-- Category Badge & Service ID -->
                        <div class="flex items-start justify-between gap-2 mb-3">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-bold border <?= $theme['badge_bg'] ?>">
                                <span><?= $theme['icon'] ?></span>
                                <span class="truncate max-w-[130px]"><?= e($theme['label']) ?></span>
                            </span>
                            <span class="font-mono text-[11px] font-bold text-slate-400 bg-slate-50 px-2.5 py-0.5 rounded-lg border border-slate-100">
                                #<?= (int)$s['id'] ?>
                            </span>
                        </div>

                        <!-- Service Title -->
                        <h3 class="text-sm sm:text-base font-extrabold text-slate-900 group-hover:text-blue-600 transition-colors line-clamp-2 leading-snug">
                            <a href="<?= e($orderUrl) ?>" class="focus:outline-none">
                                <?= e($s['name']) ?>
                            </a>
                        </h3>
                        <span class="text-[11px] font-medium text-slate-400 block mt-1 truncate">
                            <?= e($s['category_name']) ?>
                        </span>

                        <!-- Specs Grid -->
                        <div class="grid grid-cols-2 gap-2 mt-4 text-center">
                            <div class="bg-slate-50/80 rounded-xl p-2 border border-slate-100">
                                <span class="text-[10px] text-slate-400 uppercase font-bold block">Min Order</span>
                                <span class="text-xs font-black text-slate-800 font-mono-nums"><?= number_format((int)$s['min_quantity']) ?></span>
                            </div>
                            <div class="bg-slate-50/80 rounded-xl p-2 border border-slate-100">
                                <span class="text-[10px] text-slate-400 uppercase font-bold block">Max Order</span>
                                <span class="text-xs font-black text-slate-800 font-mono-nums"><?= number_format((int)$s['max_quantity']) ?></span>
                            </div>
                            <div class="col-span-2 bg-slate-50/80 rounded-xl px-2.5 py-1.5 border border-slate-100 flex items-center justify-between text-xs">
                                <span class="text-[10px] text-slate-400 uppercase font-bold">Speed</span>
                                <span class="text-[11px] font-bold text-emerald-600 flex items-center gap-1 truncate">
                                    <span>⚡</span>
                                    <span class="truncate"><?= e($s['speed'] ?: 'Instant Start') ?></span>
                                </span>
                            </div>
                        </div>

                        <!-- Description Snippet -->
                        <?php if (!empty($s['description'])): ?>
                            <p class="text-[11px] text-slate-500 mt-3 line-clamp-2 leading-relaxed bg-slate-50/50 p-2.5 rounded-xl border border-slate-100/60">
                                <?= e($s['description']) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Card Footer: Rate & Order Action -->
                    <div class="mt-5 pt-4 border-t border-slate-100">
                        <div class="flex items-baseline justify-between mb-3">
                            <div>
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Price per 1K</span>
                                <div class="flex items-baseline">
                                    <span class="text-2xl font-black text-slate-900 tabular-nums">
                                        <?= format_currency((float)$s['rate_per_1000']) ?>
                                    </span>
                                    <span class="text-xs font-semibold text-slate-500 ml-1">/ 1K</span>
                                </div>
                            </div>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase bg-emerald-50 text-emerald-700 border border-emerald-200">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                Active
                            </span>
                        </div>

                        <!-- Order CTA -->
                        <a href="<?= e($orderUrl) ?>" 
                           class="w-full py-3 px-4 bg-blue-600 hover:bg-blue-700 active:scale-95 text-white font-extrabold text-xs rounded-xl shadow-md shadow-blue-500/25 transition-all flex items-center justify-center gap-2 group-hover:bg-blue-700">
                            <span><?= $isLoggedIn ? 'Order Now' : 'Get Started & Order' ?></span>
                            <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Full Catalog Banner CTA -->
        <div class="mt-12 bg-gradient-to-r from-blue-600 to-indigo-600 rounded-3xl p-8 text-white flex flex-col md:flex-row items-center justify-between gap-6 shadow-xl shadow-blue-500/20">
            <div>
                <span class="text-xs font-bold uppercase tracking-wider text-blue-200 block mb-1">Looking for more services?</span>
                <h3 class="text-xl sm:text-2xl font-black">Explore all <?= number_format($totalServicesCount) ?>+ growth packages</h3>
                <p class="text-xs sm:text-sm text-blue-100 mt-1 max-w-xl">
                    Filter by platform, search specific keywords, and inspect detailed speed and fulfillment guidelines in our live interactive services directory.
                </p>
            </div>
            <a href="/user/services.php" class="px-6 py-3.5 bg-white hover:bg-slate-50 text-blue-600 font-extrabold text-xs rounded-2xl shadow-lg transition-all shrink-0 flex items-center gap-2">
                <span>Open Full Services Catalog</span>
                <span>&rarr;</span>
            </a>
        </div>
    </div>

    <!-- Supported Platforms Section -->
    <div class="mt-24">
        <span class="text-xs font-bold text-slate-400 uppercase tracking-widest block mb-2">Supported Platforms</span>
        <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900">All Major Social Media Platforms</h2>
        
        <div class="mt-8 flex flex-wrap items-center justify-center gap-4 sm:gap-6 max-w-4xl mx-auto">
            <a href="/user/services.php?platform=instagram" class="flex flex-col items-center gap-2 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-sm w-28 hover:shadow-md hover:-translate-y-1 transition-all">
                <div class="w-12 h-12 rounded-xl bg-gradient-to-tr from-amber-500 via-rose-500 to-purple-600 flex items-center justify-center text-white text-xl font-bold shadow-md">IG</div>
                <span class="text-xs font-semibold text-slate-700">Instagram</span>
            </a>
            <a href="/user/services.php?platform=youtube" class="flex flex-col items-center gap-2 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-sm w-28 hover:shadow-md hover:-translate-y-1 transition-all">
                <div class="w-12 h-12 rounded-xl bg-red-600 flex items-center justify-center text-white text-xl font-bold shadow-md">YT</div>
                <span class="text-xs font-semibold text-slate-700">YouTube</span>
            </a>
            <a href="/user/services.php?platform=telegram" class="flex flex-col items-center gap-2 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-sm w-28 hover:shadow-md hover:-translate-y-1 transition-all">
                <div class="w-12 h-12 rounded-xl bg-sky-500 flex items-center justify-center text-white text-xl font-bold shadow-md">TG</div>
                <span class="text-xs font-semibold text-slate-700">Telegram</span>
            </a>
            <a href="/user/services.php?platform=facebook" class="flex flex-col items-center gap-2 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-sm w-28 hover:shadow-md hover:-translate-y-1 transition-all">
                <div class="w-12 h-12 rounded-xl bg-blue-600 flex items-center justify-center text-white text-xl font-bold shadow-md">FB</div>
                <span class="text-xs font-semibold text-slate-700">Facebook</span>
            </a>
            <a href="/user/services.php?platform=tiktok" class="flex flex-col items-center gap-2 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-sm w-28 hover:shadow-md hover:-translate-y-1 transition-all">
                <div class="w-12 h-12 rounded-xl bg-black flex items-center justify-center text-white text-xl font-bold shadow-md">TT</div>
                <span class="text-xs font-semibold text-slate-700">TikTok</span>
            </a>
            <a href="/user/services.php?platform=twitter" class="flex flex-col items-center gap-2 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-sm w-28 hover:shadow-md hover:-translate-y-1 transition-all">
                <div class="w-12 h-12 rounded-xl bg-slate-900 flex items-center justify-center text-white text-xl font-bold shadow-md">X</div>
                <span class="text-xs font-semibold text-slate-700">Twitter (X)</span>
            </a>
        </div>
    </div>

    <!-- Slogan Accent -->
    <div class="mt-20">
        <h3 class="font-script text-4xl sm:text-5xl font-bold text-blue-600">Grow Faster, Smarter!</h3>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
