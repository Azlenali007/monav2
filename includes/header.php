<?php
/**
 * Shared Header Template
 * SMM Panel - PHP 8+
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

// Enforce maintenance mode for public & user visitors
check_maintenance_mode();

$currentUser = Auth::user();
$pageTitle = $pageTitle ?? (app_name() . ' - ' . app_tagline());
$isAdminPage = str_starts_with($_SERVER['SCRIPT_NAME'] ?? '', '/admin');
$isUserPage = str_starts_with($_SERVER['SCRIPT_NAME'] ?? '', '/user');
$currentScript = $_SERVER['SCRIPT_NAME'] ?? '';

$adminNavLinks = [
    ['name' => 'Dashboard', 'url' => '/admin/dashboard.php', 'icon' => '📊'],
    ['name' => 'Users', 'url' => '/admin/users.php', 'icon' => '👥'],
    ['name' => 'Orders', 'url' => '/admin/orders.php', 'icon' => '📦'],
    ['name' => 'Services', 'url' => '/admin/services.php', 'icon' => '⚡'],
    ['name' => 'Providers', 'url' => '/admin/providers.php', 'icon' => '🔌'],
    ['name' => 'Payments', 'url' => '/admin/payments.php', 'icon' => '💳'],
    ['name' => 'Referrals', 'url' => '/admin/referrals.php', 'icon' => '🎁'],
    ['name' => 'Currencies', 'url' => '/admin/currencies.php', 'icon' => '💱'],
    ['name' => 'Transactions', 'url' => '/admin/transactions.php', 'icon' => '📑'],
    ['name' => 'Tickets', 'url' => '/admin/tickets.php', 'icon' => '💬'],
    ['name' => 'Announcements', 'url' => '/admin/announcements.php', 'icon' => '📢'],
    ['name' => 'Settings', 'url' => '/admin/settings.php', 'icon' => '⚙️'],
    ['name' => 'Update Center', 'url' => '/admin/updates.php', 'icon' => '🚀'],
];
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-[#f8fafc]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="Production SMM Panel platform with client portal, admin dashboard, automated order processing, multi-currency support, referral earnings, and API integration.">
    <meta property="og:title" content="<?= e($pageTitle) ?>">
    <meta property="og:description" content="Production SMM Panel platform with client portal, admin dashboard, automated order processing, multi-currency support, referral earnings, and API integration.">

    <!-- Progressive Web App (PWA) Manifest & Meta -->
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#2563eb">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="SMM Panel">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192.svg">

    <!-- Tailwind CSS (via CDN) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Alpine.js (via CDN) -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <!-- SweetAlert2 (via CDN) -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php if ($isAdminPage): ?>
        <!-- Chart.js (Admin Dashboard analytics only) -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php endif; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        script: ['Caveat', 'cursive'],
                        mono: ['"JetBrains Mono"', 'monospace']
                    }
                }
            }
        }
    </script>
    <style>
        .font-script { font-family: 'Caveat', cursive; }
        .font-mono-nums { font-variant-numeric: tabular-nums; }
        .card-glow-blue { box-shadow: 0 20px 40px -12px rgba(37, 99, 235, 0.22); }
        .card-glow-green { box-shadow: 0 20px 40px -12px rgba(16, 185, 129, 0.22); }
        .card-glow-amber { box-shadow: 0 20px 40px -12px rgba(245, 158, 11, 0.22); }
        .card-glass { background: rgba(255, 255, 255, 0.92); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); }
        .safe-bottom-padding { padding-bottom: max(1.25rem, env(safe-area-inset-bottom, 1.25rem)); }
    </style>
</head>
<body class="min-h-full font-sans text-slate-800 antialiased bg-[#f6f9fc] selection:bg-blue-600 selection:text-white">
    <?php if ($isAdminPage): ?>
        <!-- Admin App Layout Container with Alpine.js Sidebar state -->
        <div x-data="{ sidebarOpen: false }" class="min-h-screen flex flex-col lg:flex-row bg-[#0f172a]/[0.02]">
            
            <!-- Mobile Dark Backdrop Overlay -->
            <div x-show="sidebarOpen" 
                 x-transition:enter="transition-opacity ease-linear duration-300"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity ease-linear duration-300"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 @click="sidebarOpen = false" 
                 class="fixed inset-0 z-40 bg-slate-950/60 backdrop-blur-xs lg:hidden" 
                 style="display: none;"></div>

            <!-- Admin Collapsible / Slider Navigation Sidebar -->
            <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
                   class="fixed inset-y-0 left-0 z-50 w-64 bg-slate-900 border-r border-slate-800 text-slate-300 flex flex-col justify-between transition-transform duration-300 ease-in-out lg:static lg:translate-x-0 lg:min-h-screen lg:shrink-0">
                
                <div>
                    <!-- Sidebar Brand Header -->
                    <div class="h-16 px-6 flex items-center justify-between border-b border-slate-800">
                        <a href="/admin/dashboard.php" class="flex items-center gap-2.5">
                            <div class="w-9 h-9 rounded-xl bg-blue-600 flex items-center justify-center text-white font-black shadow-md shadow-blue-500/20">
                                ⚡
                            </div>
                            <div>
                                <span class="text-base font-extrabold text-white tracking-tight leading-none block"><?= e(app_name()) ?></span>
                                <span class="text-[10px] font-bold text-blue-400 tracking-wider uppercase">Admin Control</span>
                            </div>
                        </a>
                        <!-- Mobile Close Drawer Button -->
                        <button type="button" @click="sidebarOpen = false" class="lg:hidden text-slate-400 hover:text-white p-1 rounded-lg">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <!-- Sidebar Navigation Links -->
                    <nav class="p-4 space-y-1 text-xs">
                        <?php foreach ($adminNavLinks as $link): 
                            $isActive = ($currentScript === $link['url']);
                        ?>
                            <a href="<?= $link['url'] ?>"
                                class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition-all <?= $isActive ? 'bg-blue-600 text-white font-bold shadow-md shadow-blue-500/25' : 'text-slate-300 hover:text-white hover:bg-slate-800 font-semibold' ?>">
                                <span class="text-base leading-none"><?= $link['icon'] ?></span>
                                <span><?= $link['name'] ?></span>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                </div>

                <!-- Sidebar Bottom Actions -->
                <div class="p-4 border-t border-slate-800 space-y-2">
                    <a href="/user/dashboard.php" class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl text-xs font-bold text-slate-300 hover:text-white hover:bg-slate-800 transition-colors">
                        <span>👤</span> Client View
                    </a>
                    <a href="/logout.php" class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-xl text-xs font-bold text-rose-400 hover:text-rose-300 hover:bg-rose-500/10 transition-colors">
                        <span>🚪</span> Logout
                    </a>
                </div>
            </aside>

            <!-- Admin Main Wrapper Area -->
            <div class="flex-1 flex flex-col min-w-0">
                <!-- Mobile Header with Hamburger button -->
                <header class="sticky top-0 z-30 bg-slate-900 border-b border-slate-800 text-white lg:hidden h-16 px-4 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <button type="button" 
                                @click="sidebarOpen = !sidebarOpen" 
                                aria-label="Toggle Navigation"
                                class="w-10 h-10 rounded-xl bg-slate-800 border border-slate-700/80 flex items-center justify-center text-slate-300 hover:text-white transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 6h16M4 12h16M4 18h16"/>
                            </svg>
                        </button>
                        <div class="flex items-center gap-2">
                            <span class="text-base font-extrabold tracking-tight"><?= e(app_name()) ?> Admin</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="/user/dashboard.php" class="px-3 py-1.5 rounded-lg bg-slate-800 text-slate-300 text-xs font-bold hover:bg-slate-700">Client</a>
                        <a href="/logout.php" class="text-xs font-bold text-rose-400 px-2 py-1">Exit</a>
                    </div>
                </header>

                <!-- Desktop Top Bar Header -->
                <header class="hidden lg:flex sticky top-0 z-30 bg-white/90 backdrop-blur-md border-b border-slate-200/80 h-16 px-8 items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Administration</span>
                        <span class="text-slate-300">/</span>
                        <span class="text-sm font-extrabold text-slate-800"><?= e($pageTitle) ?></span>
                    </div>
                    <div class="flex items-center gap-4">
                        <?php if (is_maintenance_mode()): ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                                Maintenance Mode Active
                            </span>
                        <?php endif; ?>
                        <a href="/user/dashboard.php" class="px-3.5 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition-all">
                            Client Portal &rarr;
                        </a>
                        <a href="/logout.php" class="text-xs font-bold text-rose-600 hover:text-rose-700">
                            Logout
                        </a>
                    </div>
                </header>

                <!-- Main Content Body -->
                <main class="flex-1 overflow-y-auto">
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                        <?= render_flash() ?>
    <?php elseif (!$isUserPage): ?>
        <!-- Public Landing Page Header -->
        <header class="sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-slate-200/80">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <a href="/" class="flex items-center gap-2.5">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-blue-600 to-indigo-600 flex items-center justify-center text-white shadow-md shadow-blue-500/20">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                            </svg>
                        </div>
                        <div>
                            <span class="text-lg font-bold text-slate-900 tracking-tight leading-none block"><?= e(app_name()) ?></span>
                            <span class="text-[11px] font-medium text-slate-400 leading-none"><?= e(app_tagline()) ?></span>
                        </div>
                    </a>
                    <nav class="hidden md:flex items-center gap-6 text-sm font-semibold text-slate-600">
                        <a href="/" class="hover:text-blue-600 transition-colors">Home</a>
                        <a href="/user/services.php" class="hover:text-blue-600 transition-colors">Services</a>
                        <a href="/login.php" class="hover:text-blue-600 transition-colors">How It Works</a>
                        <a href="/user/tickets.php" class="hover:text-blue-600 transition-colors">Support</a>
                    </nav>
                    <div class="flex items-center gap-3">
                        <?php if ($currentUser): ?>
                            <a href="/user/dashboard.php" class="px-4 py-2 text-xs font-bold text-white bg-blue-600 rounded-xl shadow-md">Dashboard</a>
                            <a href="/logout.php" class="text-xs font-semibold text-rose-500 hover:text-rose-600">Logout</a>
                        <?php else: ?>
                            <a href="/login.php" class="px-4 py-2 text-xs font-bold text-blue-600 hover:bg-blue-50 rounded-xl transition-colors">Login</a>
                            <a href="/register.php" class="px-5 py-2 text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-xl shadow-md shadow-blue-500/25 transition-all">Register</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </header>
        <main class="flex-1">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                <?= render_flash() ?>
    <?php else: ?>
        <!-- User Panel Modern Glass Header -->
        <header class="bg-white/85 backdrop-blur-xl border-b border-slate-200/70 sticky top-0 z-30 shadow-xs">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
                <!-- Brand Emblem -->
                <div class="flex items-center gap-6">
                    <a href="/user/dashboard.php" class="flex items-center gap-2.5 group">
                        <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-blue-600 to-indigo-600 text-white flex items-center justify-center font-black text-sm shadow-md shadow-blue-500/25 group-hover:scale-105 transition-transform">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                            </svg>
                        </div>
                        <div>
                            <span class="text-base font-black text-slate-900 tracking-tight leading-none block"><?= e(app_name()) ?></span>
                            <span class="text-[10px] font-medium text-slate-400 tracking-tight leading-none mt-1 block"><?= e(app_tagline()) ?></span>
                        </div>
                    </a>

                    <?php if (!str_contains($currentScript, 'dashboard.php')): ?>
                    <!-- Desktop Navigation Links for sub-pages -->
                    <nav class="hidden md:flex items-center gap-1 pl-4 border-l border-slate-200">
                        <a href="/user/dashboard.php" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-colors text-slate-600 hover:text-slate-900 hover:bg-slate-50">
                            Dashboard
                        </a>
                        <a href="/user/new-order.php" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all <?= str_contains($currentScript, 'new-order.php') ? 'bg-blue-600 text-white shadow-sm shadow-blue-500/25' : 'text-blue-600 hover:bg-blue-50' ?>">
                            + New Order
                        </a>
                        <a href="/user/services.php" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-colors <?= str_contains($currentScript, 'services.php') ? 'bg-blue-50 text-blue-600' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50' ?>">
                            Services
                        </a>
                        <a href="/user/orders.php" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-colors <?= str_contains($currentScript, 'orders.php') ? 'bg-blue-50 text-blue-600' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50' ?>">
                            Orders
                        </a>
                        <a href="/user/referrals.php" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-colors <?= str_contains($currentScript, 'referrals.php') ? 'bg-blue-50 text-blue-600' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50' ?>">
                            🎁 Referrals
                        </a>
                        <a href="/user/api.php" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-colors <?= str_contains($currentScript, 'api.php') ? 'bg-blue-50 text-blue-600' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50' ?>">
                            API
                        </a>
                        <a href="/user/tickets.php" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-colors <?= str_contains($currentScript, 'tickets.php') ? 'bg-blue-50 text-blue-600' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-50' ?>">
                            Support
                        </a>
                    </nav>
                    <?php endif; ?>
                </div>

                <!-- Right Action Bar (Reference Header Style) -->
                <div class="flex items-center gap-2 sm:gap-2.5">
                    <!-- Global Currency Switcher Selector -->
                    <?php 
                        $headerUserCurrency = get_user_currency();
                        $headerAllCurrencies = get_active_currencies();
                    ?>
                    <div class="relative inline-block">
                        <select onchange="window.switchCurrency(this.value)" class="appearance-none bg-slate-100 hover:bg-slate-200/80 text-slate-800 text-[11px] font-extrabold pl-2.5 pr-6 py-1.5 rounded-xl border border-slate-200/60 focus:outline-none cursor-pointer transition-colors" title="Change Display Currency">
                            <?php foreach ($headerAllCurrencies as $c): ?>
                                <option value="<?= e($c['code']) ?>" <?= $c['code'] === $headerUserCurrency['code'] ? 'selected' : '' ?>>
                                    <?= e($c['symbol']) ?> <?= e($c['code']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2 text-slate-400 text-[9px]">▼</span>
                    </div>

                    <!-- PWA Install Button (Shown if browser supports installation) -->
                    <button type="button" onclick="window.installPWA()" class="pwa-install-trigger hidden px-2.5 py-1.5 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white text-[11px] font-bold shadow-xs hover:scale-105 active:scale-95 transition-all flex items-center gap-1" title="Install App to Homescreen">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        <span class="hidden sm:inline">Install App</span>
                    </button>

                    <?php 
                        $userUnreadNotifs = $currentUser ? get_unread_notifications_count((int)$currentUser['id']) : 0;
                    ?>
                    <!-- Notification Bell Icon Button with Live Unread Indicator -->
                    <a href="/user/notifications.php" class="w-9 h-9 rounded-full bg-blue-50/80 hover:bg-blue-100 text-blue-600 flex items-center justify-center transition-colors relative" title="Notification Center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                        </svg>
                        <?php if ($userUnreadNotifs > 0): ?>
                            <span class="absolute -top-1 -right-1 min-w-[18px] h-[18px] rounded-full bg-rose-500 text-white text-[10px] font-black flex items-center justify-center px-1 border-2 border-white shadow-xs">
                                <?= $userUnreadNotifs > 99 ? '99+' : $userUnreadNotifs ?>
                            </span>
                        <?php endif; ?>
                    </a>

                    <?php if (str_contains($currentScript, 'dashboard.php')): ?>
                        <!-- 2. Small Support/Ticket Icon Button -->
                        <a href="/user/tickets.php" class="w-9 h-9 rounded-full bg-blue-50/80 hover:bg-blue-100 text-blue-600 flex items-center justify-center transition-colors" title="Support Help Desk">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                            </svg>
                        </a>

                        <!-- 3. Profile Avatar Circular Button -->
                        <a href="/user/profile.php" class="w-9 h-9 rounded-full bg-gradient-to-tr from-blue-600 to-indigo-600 text-white flex items-center justify-center font-black text-xs border-2 border-white shadow-xs hover:ring-2 hover:ring-blue-200 transition-all" title="User Profile">
                            <?= strtoupper(substr($currentUser['username'] ?? 'U', 0, 1)) ?>
                        </a>
                    <?php else: ?>
                        <!-- Standard Subpage Header Controls -->
                        <a href="/user/add-funds.php" class="flex items-center gap-2 bg-gradient-to-r from-blue-50 to-indigo-50/80 hover:from-blue-100 hover:to-indigo-100/90 border border-blue-200/80 pl-3 pr-2 py-1.5 rounded-2xl transition-all shadow-xs group">
                            <span class="text-[11px] font-semibold text-slate-500 hidden sm:inline">Balance:</span>
                            <span class="text-xs sm:text-sm font-extrabold text-blue-700 font-mono-nums"><?= format_currency($currentUser['balance'] ?? 0) ?></span>
                            <span class="w-6 h-6 rounded-xl bg-blue-600 text-white font-extrabold flex items-center justify-center text-xs shadow-xs group-hover:scale-105 transition-transform">+</span>
                        </a>

                        <a href="/user/profile.php" class="flex items-center gap-2 p-1 pl-2 sm:pr-3 rounded-2xl hover:bg-slate-100 border border-slate-200/60 transition-colors" title="My Profile">
                            <div class="w-7 h-7 rounded-xl bg-gradient-to-tr from-slate-800 to-slate-900 text-white font-black flex items-center justify-center text-xs shadow-xs">
                                <?= strtoupper(substr($currentUser['username'] ?? 'U', 0, 1)) ?>
                            </div>
                            <span class="text-xs font-bold text-slate-700 hidden sm:inline"><?= e($currentUser['username'] ?? 'Account') ?></span>
                        </a>

                        <a href="/logout.php" class="text-xs text-rose-500 hover:text-rose-600 font-bold px-2 py-1 transition-colors" title="Logout">
                            Logout
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </header>
        <!-- Main Content Container for User Pages (pb-28 padding ensures bottom nav never obscures content) -->
        <main class="flex-1 pb-28">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                <?= render_flash() ?>
    <?php endif; ?>
