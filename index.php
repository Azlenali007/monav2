<?php
/**
 * Landing Page
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = "SMM Panel - Boost Your Social Media";
require_once __DIR__ . '/includes/header.php';
?>

<!-- Hero Section -->
<section class="py-12 md:py-20 text-center relative overflow-hidden">
    <!-- Accent Badge -->
    <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-blue-50 border border-blue-200/60 text-blue-600 text-xs font-bold uppercase tracking-widest mb-6">
        <svg class="w-4 h-4 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
            <path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/>
        </svg>
        Social Media Growth
    </div>

    <!-- Main Headline -->
    <h1 class="text-4xl sm:text-6xl font-extrabold text-slate-900 tracking-tight max-w-4xl mx-auto leading-tight">
        Boost Your Social Media <br>
        <span class="font-script text-5xl sm:text-7xl font-bold text-blue-600 inline-block -rotate-1 mt-1">With Our SMM Panel</span>
    </h1>

    <p class="mt-6 text-base sm:text-lg text-slate-600 max-w-2xl mx-auto leading-relaxed">
        Get real followers, likes, views and more. Fast, secure and affordable services for all major social media platforms.
    </p>

    <!-- 4 Key Badges -->
    <div class="mt-8 flex flex-wrap items-center justify-center gap-3 sm:gap-6 text-xs sm:text-sm font-semibold text-slate-700">
        <div class="flex items-center gap-2 bg-white px-4 py-2 rounded-xl shadow-sm border border-slate-200/80">
            <span class="text-blue-600">⚡</span> Fast Delivery
        </div>
        <div class="flex items-center gap-2 bg-white px-4 py-2 rounded-xl shadow-sm border border-slate-200/80">
            <span class="text-blue-600">🔒</span> 100% Safe
        </div>
        <div class="flex items-center gap-2 bg-white px-4 py-2 rounded-xl shadow-sm border border-slate-200/80">
            <span class="text-amber-500">⭐</span> High Quality
        </div>
        <div class="flex items-center gap-2 bg-white px-4 py-2 rounded-xl shadow-sm border border-slate-200/80">
            <span class="text-blue-600">💬</span> 24/7 Support
        </div>
    </div>

    <!-- 3D Service Cards Showcase -->
    <div class="mt-16 grid grid-cols-1 md:grid-cols-3 gap-8 max-w-5xl mx-auto items-center px-4">
        <!-- Card 1: YouTube -->
        <div class="bg-gradient-to-b from-red-50 to-white rounded-3xl p-6 border border-red-100 shadow-xl text-left transition-transform hover:-translate-y-2">
            <span class="inline-block px-3 py-1 bg-red-100 text-red-600 text-xs font-bold rounded-lg mb-4">YouTube</span>
            <div class="w-20 h-20 mx-auto my-3 bg-red-600 rounded-2xl flex items-center justify-center shadow-lg shadow-red-500/30 text-white">
                <svg class="w-10 h-10 fill-current" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
            </div>
            <h3 class="text-xl font-bold text-slate-900 mt-4">YouTube Views</h3>
            <p class="text-xs text-slate-500 mt-1">Real Views • High Retention</p>
            <div class="mt-4 flex items-baseline justify-between">
                <div><span class="text-2xl font-extrabold text-slate-900">₹12</span> <span class="text-xs text-slate-500 font-medium">/ 1K</span></div>
                <span class="text-[11px] font-bold text-emerald-600 bg-emerald-50 px-2.5 py-1 rounded-md">⚡ Fast Delivery</span>
            </div>
            <a href="/login.php" class="mt-5 block w-full py-3 bg-blue-600 hover:bg-blue-700 text-white text-center font-bold text-sm rounded-xl shadow-md shadow-blue-500/20 transition-all">Order Now &rarr;</a>
        </div>

        <!-- Card 2: Instagram (Elevated Featured Center Card) -->
        <div class="bg-gradient-to-b from-pink-50 via-purple-50 to-white rounded-3xl p-8 border-2 border-pink-200/80 shadow-2xl scale-105 relative z-10 text-left">
            <div class="flex items-center justify-between mb-2">
                <span class="inline-block px-3 py-1 bg-pink-100 text-pink-600 text-xs font-bold rounded-lg">Instagram</span>
                <span class="px-2.5 py-0.5 bg-gradient-to-r from-pink-500 to-rose-500 text-white text-[10px] font-extrabold rounded-full uppercase tracking-wider">+1K Trending</span>
            </div>
            <div class="w-24 h-24 mx-auto my-4 bg-gradient-to-tr from-amber-500 via-rose-500 to-purple-600 rounded-3xl flex items-center justify-center shadow-xl shadow-rose-500/30 text-white">
                <svg class="w-12 h-12 fill-current" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
            </div>
            <h3 class="text-2xl font-extrabold text-slate-900 mt-4">Instagram Followers</h3>
            <p class="text-xs text-slate-600 mt-1">Real & Active Followers • High Quality • Fast Delivery</p>
            <div class="mt-5 flex items-baseline justify-between">
                <div><span class="text-3xl font-extrabold text-slate-900">₹35</span> <span class="text-xs text-slate-500 font-medium">/ 1K</span></div>
                <span class="text-[11px] font-bold text-emerald-600 bg-emerald-50 px-3 py-1 rounded-md">⚡ Starts in 1-2 Hours</span>
            </div>
            <a href="/login.php" class="mt-6 block w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white text-center font-bold text-sm rounded-xl shadow-lg shadow-blue-500/30 transition-all">Order Now &rarr;</a>
        </div>

        <!-- Card 3: Telegram -->
        <div class="bg-gradient-to-b from-sky-50 to-white rounded-3xl p-6 border border-sky-100 shadow-xl text-left transition-transform hover:-translate-y-2">
            <span class="inline-block px-3 py-1 bg-sky-100 text-sky-600 text-xs font-bold rounded-lg mb-4">Telegram</span>
            <div class="w-20 h-20 mx-auto my-3 bg-sky-500 rounded-2xl flex items-center justify-center shadow-lg shadow-sky-500/30 text-white">
                <svg class="w-10 h-10 fill-current" viewBox="0 0 24 24"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221l-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.446 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.121l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.458c.536-.196 1.006.128.832.943z"/></svg>
            </div>
            <h3 class="text-xl font-bold text-slate-900 mt-4">Telegram Members</h3>
            <p class="text-xs text-slate-500 mt-1">Real & Active Members • Instant Start</p>
            <div class="mt-4 flex items-baseline justify-between">
                <div><span class="text-2xl font-extrabold text-slate-900">₹45</span> <span class="text-xs text-slate-500 font-medium">/ 1K</span></div>
                <span class="text-[11px] font-bold text-emerald-600 bg-emerald-50 px-2.5 py-1 rounded-md">⚡ Fast Delivery</span>
            </div>
            <a href="/login.php" class="mt-5 block w-full py-3 bg-blue-600 hover:bg-blue-700 text-white text-center font-bold text-sm rounded-xl shadow-md shadow-blue-500/20 transition-all">Order Now &rarr;</a>
        </div>
    </div>

    <!-- Supported Platforms Section -->
    <div class="mt-24">
        <span class="text-xs font-bold text-slate-400 uppercase tracking-widest block mb-2">Supported Platforms</span>
        <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900">All Major Social Media Platforms</h2>
        
        <div class="mt-8 flex flex-wrap items-center justify-center gap-4 sm:gap-6 max-w-4xl mx-auto">
            <div class="flex flex-col items-center gap-2 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-sm w-28 hover:shadow-md transition-shadow">
                <div class="w-12 h-12 rounded-xl bg-gradient-to-tr from-amber-500 via-rose-500 to-purple-600 flex items-center justify-center text-white text-xl font-bold shadow-md">IG</div>
                <span class="text-xs font-semibold text-slate-700">Instagram</span>
            </div>
            <div class="flex flex-col items-center gap-2 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-sm w-28 hover:shadow-md transition-shadow">
                <div class="w-12 h-12 rounded-xl bg-red-600 flex items-center justify-center text-white text-xl font-bold shadow-md">YT</div>
                <span class="text-xs font-semibold text-slate-700">YouTube</span>
            </div>
            <div class="flex flex-col items-center gap-2 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-sm w-28 hover:shadow-md transition-shadow">
                <div class="w-12 h-12 rounded-xl bg-sky-500 flex items-center justify-center text-white text-xl font-bold shadow-md">TG</div>
                <span class="text-xs font-semibold text-slate-700">Telegram</span>
            </div>
            <div class="flex flex-col items-center gap-2 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-sm w-28 hover:shadow-md transition-shadow">
                <div class="w-12 h-12 rounded-xl bg-blue-600 flex items-center justify-center text-white text-xl font-bold shadow-md">FB</div>
                <span class="text-xs font-semibold text-slate-700">Facebook</span>
            </div>
            <div class="flex flex-col items-center gap-2 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-sm w-28 hover:shadow-md transition-shadow">
                <div class="w-12 h-12 rounded-xl bg-black flex items-center justify-center text-white text-xl font-bold shadow-md">TT</div>
                <span class="text-xs font-semibold text-slate-700">TikTok</span>
            </div>
            <div class="flex flex-col items-center gap-2 p-4 bg-white rounded-2xl border border-slate-200/80 shadow-sm w-28 hover:shadow-md transition-shadow">
                <div class="w-12 h-12 rounded-xl bg-slate-900 flex items-center justify-center text-white text-xl font-bold shadow-md">X</div>
                <span class="text-xs font-semibold text-slate-700">Twitter (X)</span>
            </div>
        </div>
    </div>

    <!-- Slogan Accent -->
    <div class="mt-20">
        <h3 class="font-script text-4xl sm:text-5xl font-bold text-blue-600">Grow Faster, Smarter!</h3>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
