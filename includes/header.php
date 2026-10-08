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
    ['name' => 'Transactions', 'url' => '/admin/transactions.php', 'icon' => '📑'],
    ['name' => 'Tickets', 'url' => '/admin/tickets.php', 'icon' => '💬'],
    ['name' => 'Announcements', 'url' => '/admin/announcements.php', 'icon' => '📢'],
    ['name' => 'Settings', 'url' => '/admin/settings.php', 'icon' => '⚙️'],
];
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-[#f8fafc]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="Production SMM Panel platform with client portal, admin dashboard, automated order processing, Razorpay payment gateway, and API integration.">
    <meta property="og:title" content="<?= e($pageTitle) ?>">
    <meta property="og:description" content="Production SMM Panel platform with client portal, admin dashboard, automated order processing, Razorpay payment gateway, and API integration.">

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
        .card-glow-blue { box-shadow: 0 20px 35px -10px rgba(37, 99, 235, 0.25); }
        .card-glow-green { box-shadow: 0 20px 35px -10px rgba(16, 185, 129, 0.25); }
        .card-glow-amber { box-shadow: 0 20px 35px -10px rgba(245, 158, 11, 0.25); }
        .safe-bottom-padding { padding-bottom: max(1.25rem, env(safe-area-inset-bottom, 1.25rem)); }
    </style>
</head>
<body class="min-h-full font-sans text-slate-800 antialiased bg-[#f8fafc]">
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
        <!-- User Panel Minimal Top Bar -->
        <header class="bg-white/80 backdrop-blur-md border-b border-slate-200/60 sticky top-0 z-30">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 h-14 flex items-center justify-between">
                <a href="/user/dashboard.php" class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-blue-600 text-white flex items-center justify-center font-black text-sm">
                        ⚡
                    </div>
                    <span class="text-sm font-extrabold text-slate-900"><?= e(app_name()) ?></span>
                </a>
                <div class="flex items-center gap-3">
                    <a href="/user/add-funds.php" class="flex items-center gap-1.5 bg-blue-50 hover:bg-blue-100 border border-blue-200/60 px-3 py-1 rounded-xl text-xs font-bold text-blue-600 transition-colors">
                        <span>Balance:</span>
                        <span class="font-extrabold text-slate-900 font-mono-nums"><?= format_currency($currentUser['balance'] ?? 0) ?></span>
                    </a>
                    <a href="/user/profile.php" class="w-8 h-8 rounded-full bg-slate-200 hover:bg-slate-300 text-slate-700 font-extrabold flex items-center justify-center text-xs transition-colors" title="My Profile">
                        <?= strtoupper(substr($currentUser['username'] ?? 'U', 0, 1)) ?>
                    </a>
                    <a href="/logout.php" class="text-xs text-rose-500 font-bold ml-1 hover:text-rose-600">Logout</a>
                </div>
            </div>
        </header>
        <!-- Main Content Container for User Pages (pb-28 padding ensures bottom nav never obscures content) -->
        <main class="flex-1 pb-28">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                <?= render_flash() ?>
    <?php endif; ?>
