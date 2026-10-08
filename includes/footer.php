<?php
/**
 * Shared Footer Template
 * SMM Panel - PHP 8+
 */

declare(strict_types=1);

$isAdminPage = str_starts_with($_SERVER['SCRIPT_NAME'] ?? '', '/admin');
$isUserPage = str_starts_with($_SERVER['SCRIPT_NAME'] ?? '', '/user');
$currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
?>
        </div>
    </main>

    <?php if ($isAdminPage): ?>
            <!-- Admin Bottom Footer -->
            <footer class="mt-auto bg-white border-t border-slate-200/80 py-4 px-8 text-xs text-slate-500 flex items-center justify-between">
                <span>&copy; <?= date('Y') ?> <?= e(app_name()) ?>. Administrator Console.</span>
                <span class="font-mono text-[11px] text-slate-400">PHP 8.3+ • PDO Secure Engine</span>
            </footer>
        </div>
    </div>

    <?php elseif ($isUserPage): ?>
        <!-- User Fixed Bottom Navigation Bar (Services, Transactions, Support, Profile) -->
        <!-- Fixed to viewport, stays visible during scrolling, handles safe-area-inset-bottom -->
        <nav class="fixed bottom-0 left-0 right-0 z-40 bg-white/95 backdrop-blur-md border-t border-slate-200/90 shadow-2xl" 
             style="padding-bottom: max(0.5rem, env(safe-area-inset-bottom, 0.5rem));"
             aria-label="User Quick Navigation">
            <div class="max-w-md mx-auto px-4 py-2 flex items-center justify-around">
                <!-- 1. Services -->
                <?php $isServicesActive = str_contains($currentScript, 'services.php'); ?>
                <a href="/user/services.php" 
                   class="flex flex-col items-center gap-1 py-1 px-3 rounded-2xl transition-all <?= $isServicesActive ? 'text-blue-600 font-extrabold' : 'text-slate-500 hover:text-slate-900 font-semibold' ?>">
                    <div class="w-8 h-8 rounded-xl flex items-center justify-center <?= $isServicesActive ? 'bg-blue-50 text-blue-600' : 'text-slate-500' ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                        </svg>
                    </div>
                    <span class="text-[11px] tracking-tight">Services</span>
                </a>

                <!-- 2. Transactions -->
                <?php $isTxnActive = str_contains($currentScript, 'transactions.php'); ?>
                <a href="/user/transactions.php" 
                   class="flex flex-col items-center gap-1 py-1 px-3 rounded-2xl transition-all <?= $isTxnActive ? 'text-blue-600 font-extrabold' : 'text-slate-500 hover:text-slate-900 font-semibold' ?>">
                    <div class="w-8 h-8 rounded-xl flex items-center justify-center <?= $isTxnActive ? 'bg-blue-50 text-blue-600' : 'text-slate-500' ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                        </svg>
                    </div>
                    <span class="text-[11px] tracking-tight">Transactions</span>
                </a>

                <!-- 3. Support -->
                <?php $isSupportActive = str_contains($currentScript, 'tickets.php'); ?>
                <a href="/user/tickets.php" 
                   class="flex flex-col items-center gap-1 py-1 px-3 rounded-2xl transition-all <?= $isSupportActive ? 'text-blue-600 font-extrabold' : 'text-slate-500 hover:text-slate-900 font-semibold' ?>">
                    <div class="w-8 h-8 rounded-xl flex items-center justify-center <?= $isSupportActive ? 'bg-blue-50 text-blue-600' : 'text-slate-500' ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </div>
                    <span class="text-[11px] tracking-tight">Support</span>
                </a>

                <!-- 4. Profile -->
                <?php $isProfileActive = str_contains($currentScript, 'profile.php'); ?>
                <a href="/user/profile.php" 
                   class="flex flex-col items-center gap-1 py-1 px-3 rounded-2xl transition-all <?= $isProfileActive ? 'text-blue-600 font-extrabold' : 'text-slate-500 hover:text-slate-900 font-semibold' ?>">
                    <div class="w-8 h-8 rounded-xl flex items-center justify-center <?= $isProfileActive ? 'bg-blue-50 text-blue-600' : 'text-slate-500' ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                    </div>
                    <span class="text-[11px] tracking-tight">Profile</span>
                </a>
            </div>
        </nav>

    <?php else: ?>
        <!-- Public Bottom Footer -->
        <footer class="mt-auto bg-white border-t border-slate-200/80 py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row items-center justify-between gap-4 text-xs text-slate-500">
                <div class="flex items-center gap-2">
                    <span class="font-bold text-slate-700"><?= e(app_name()) ?></span>
                    <span>&copy; <?= date('Y') ?>. All rights reserved. Plain PHP 8+ & MySQL PDO architecture.</span>
                </div>
                <div class="flex items-center gap-6">
                    <a href="/install/" class="text-blue-600 hover:underline font-semibold">Web Installer</a>
                    <a href="/user/services.php" class="hover:text-slate-900 transition-colors">Services</a>
                    <a href="/user/tickets.php" class="hover:text-slate-900 transition-colors">Support</a>
                </div>
            </div>
        </footer>
    <?php endif; ?>

    <!-- Application JavaScript Core -->
    <script src="/assets/js/app.js"></script>
    <?php if ($isUserPage): ?>
        <script src="/assets/js/user.js"></script>
    <?php endif; ?>
    <?php if ($isAdminPage): ?>
        <script src="/assets/js/admin.js"></script>
    <?php endif; ?>
</body>
</html>
