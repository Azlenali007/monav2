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
        <div class="absolute -top-14 -right-14 w-36 h-36 rounded-full bg-blue-500/10 blur-2xl pointer-events-none"></div>

        <!-- Top Right Close Button -->
        <button type="button" 
                @click="dismiss(false)"
                aria-label="Close announcement"
                class="absolute top-4 right-4 w-8 h-8 rounded-full bg-slate-100 text-slate-400 hover:text-slate-700 hover:bg-slate-200 flex items-center justify-center transition-colors cursor-pointer">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>

        <!-- Badge & Icon Header -->
        <div class="flex items-center gap-3.5 mb-5">
            <!-- Dynamic Themed Icon Box -->
            <div class="w-12 h-12 rounded-2xl flex items-center justify-center text-2xl font-bold shadow-md shrink-0"
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
                    <span class="text-xl">🎁</span>
                </template>

                <!-- Service Alert Icon -->
                <template x-if="announcement.type === 'service'">
                    <span class="text-xl">⚡</span>
                </template>

                <!-- Maintenance Icon -->
                <template x-if="announcement.type === 'maintenance'">
                    <span class="text-xl">⚠️</span>
                </template>

                <!-- Update Icon -->
                <template x-if="announcement.type === 'update'">
                    <span class="text-xl">🚀</span>
                </template>

                <!-- General Announcement / System Icon -->
                <template x-if="announcement.type === 'announcement' || announcement.type === 'system'">
                    <span class="text-xl">📢</span>
                </template>
            </div>

            <div>
                <!-- Category/Badge Pill -->
                <span class="inline-block px-3 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wider"
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
                <span class="text-[11px] text-slate-400 block font-medium mt-0.5"><?= e(app_name()) ?> Notice</span>
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
                   class="w-full sm:w-auto px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-extrabold text-xs rounded-xl shadow-lg shadow-blue-500/25 transition-all flex items-center justify-center gap-2 text-center cursor-pointer">
                    <span x-text="announcement.btn_text"></span>
                    <span>&rarr;</span>
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
