<?php
/**
 * Safe GitHub Update Center & Database Migration Console
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/updates.php';

Auth::requireAdmin();
$currentUser = Auth::user();

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';

    // 1. Run Safe Update Installation
    if ($action === 'install_update') {
        $res = UpdateManager::installUpdate((int)$currentUser['id']);
        if ($res['success']) {
            set_flash('success', "Application updated successfully from v{$res['version_from']} to v{$res['version_to']}! Verified backup created ({$res['backup_file']}) and {$res['migrations_count']} database migration(s) applied.");
        } else {
            set_flash('error', "Update failed: " . ($res['error'] ?? 'Unknown error occurred'));
        }
        redirect('/admin/updates.php');
    }

    // 2. Rollback to Previous Version
    if ($action === 'rollback') {
        $backupFile = trim($_POST['backup_file'] ?? '');
        $res = UpdateManager::rollback(!empty($backupFile) ? $backupFile : null);
        if ($res['success']) {
            set_flash('info', "Rollback completed. Application restored to v{$res['restored_version']}.");
        } else {
            set_flash('error', "Rollback failed: " . ($res['error'] ?? 'Unknown error occurred'));
        }
        redirect('/admin/updates.php');
    }

    // 3. Force Release Lock
    if ($action === 'release_lock') {
        UpdateManager::releaseLock();
        set_flash('info', 'Update concurrency lock manually cleared.');
        redirect('/admin/updates.php');
    }
}

$currentVersion = UpdateManager::getCurrentVersion();
$latestRelease = UpdateManager::getLatestRelease();
$hasUpdate = UpdateManager::hasUpdateAvailable();
$val = UpdateManager::validateEnvironment();
$pendingMigrations = UpdateManager::getPendingMigrations();
$auditLogs = UpdateManager::getAuditLogs(15);
$isLocked = UpdateManager::isLocked();

// Available backups
$backupDir = __DIR__ . '/../storage/backups';
$backups = [];
if (is_dir($backupDir)) {
    $files = glob($backupDir . '/*.json') ?: [];
    rsort($files);
    foreach ($files as $f) {
        $backups[] = [
            'name' => basename($f),
            'size' => round(filesize($f) / 1024, 2) . ' KB',
            'date' => date('Y-m-d H:i:s', filemtime($f))
        ];
    }
}

$pageTitle = "GitHub Update Center - Admin Console";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-6xl mx-auto my-6 space-y-8">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight flex items-center gap-2">
                <span>🚀</span> GitHub Update Center &amp; System Health
            </h1>
            <p class="text-xs text-slate-500 mt-1">Automated release synchronization, zero-data-loss database migrations, pre-update verified backups &amp; recovery</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-mono font-bold bg-slate-900 text-white shadow-xs">
                <span>Installed:</span>
                <span class="text-blue-400">v<?= e($currentVersion) ?></span>
            </span>
            <?php if ($hasUpdate): ?>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 animate-pulse">
                    <span>⚡</span> Update Available: v<?= e($latestRelease['latest_version'] ?? '') ?>
                </span>
            <?php else: ?>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-200">
                    <span>✓</span> Up to date
                </span>
            <?php endif; ?>
        </div>
    </div>

    <?= render_flash() ?>

    <?php if ($isLocked): ?>
        <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="text-2xl">⚠️</span>
                <div>
                    <h4 class="text-xs font-extrabold text-amber-900">Update Process Currently In Progress</h4>
                    <p class="text-[11px] text-amber-700">An update lock is active to prevent concurrency conflicts. If an update stalled, you may release the lock.</p>
                </div>
            </div>
            <form action="/admin/updates.php" method="POST">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="release_lock">
                <button type="submit" class="px-3.5 py-1.5 bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold rounded-xl shadow-xs transition-colors cursor-pointer">
                    Release Lock
                </button>
            </form>
        </div>
    <?php endif; ?>

    <!-- 1. RELEASE OVERVIEW & ACTION HERO -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6 pb-6 border-b border-slate-100">
            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase bg-blue-50 text-blue-700 border border-blue-200">
                        Official Release
                    </span>
                    <h2 class="text-xl font-black text-slate-900 tracking-tight">
                        <?= e($latestRelease['title'] ?? 'Feature Expansion Update') ?>
                    </h2>
                    <span class="text-xs font-mono font-bold text-slate-500">
                        v<?= e($latestRelease['latest_version'] ?? '1.1.0') ?>
                    </span>
                </div>
                <p class="text-xs text-slate-500 max-w-2xl leading-relaxed">
                    Released on <?= e($latestRelease['release_date'] ?? date('Y-m-d')) ?> &bull; Package Size: <?= e($latestRelease['size'] ?? '2.4 MB') ?>
                </p>
            </div>

            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 shrink-0">
                <?php if ($hasUpdate): ?>
                    <form action="/admin/updates.php" method="POST" onsubmit="return confirm('Initiate safe update to v<?= addslashes($latestRelease['latest_version'] ?? '') ?>?\n\nAn automated verified backup will be generated first.')">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="install_update">
                        <button type="submit" 
                                <?= (!$val['passed'] || $isLocked) ? 'disabled' : '' ?>
                                class="w-full sm:w-auto px-6 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 disabled:opacity-50 text-white font-extrabold text-xs sm:text-sm rounded-2xl shadow-lg shadow-blue-500/25 transition-all flex items-center justify-center gap-2 cursor-pointer">
                            <span>🚀</span>
                            <span>Install Safe Update Now &rarr;</span>
                        </button>
                    </form>
                <?php else: ?>
                    <button type="button" disabled class="px-6 py-3 bg-slate-100 text-slate-400 font-extrabold text-xs rounded-2xl cursor-not-allowed flex items-center justify-center gap-2">
                        <span>✓</span>
                        <span>Latest Version Installed</span>
                    </button>
                <?php endif; ?>

                <?php if (!empty($backups)): ?>
                    <form action="/admin/updates.php" method="POST" onsubmit="return confirm('Rollback application to latest verified backup?')">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="rollback">
                        <input type="hidden" name="backup_file" value="<?= e($backups[0]['name']) ?>">
                        <button type="submit" class="px-4 py-3 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-bold rounded-2xl transition-all cursor-pointer">
                            ↺ Rollback
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Release Notes & Changes Tabs/List -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Release Notes -->
            <div class="space-y-3">
                <h3 class="text-xs font-extrabold text-slate-900 uppercase tracking-wider flex items-center gap-2">
                    <span>📋</span> Release Highlights &amp; Changelog
                </h3>
                <ul class="space-y-2">
                    <?php foreach (($latestRelease['release_notes'] ?? []) as $note): ?>
                        <li class="flex items-start gap-2.5 text-xs text-slate-600 leading-relaxed">
                            <span class="w-1.5 h-1.5 rounded-full bg-blue-600 shrink-0 mt-1.5"></span>
                            <span><?= e($note) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Changed Files & Migrations -->
            <div class="space-y-3">
                <h3 class="text-xs font-extrabold text-slate-900 uppercase tracking-wider flex items-center gap-2">
                    <span>📦</span> Modified &amp; Added Components
                </h3>
                <div class="bg-slate-50 rounded-2xl p-4 border border-slate-200/80 space-y-3 max-h-56 overflow-y-auto">
                    <?php if (!empty($latestRelease['changed_files']['added'])): ?>
                        <div>
                            <span class="text-[10px] font-mono font-bold text-emerald-700 uppercase block mb-1">Added Files</span>
                            <div class="space-y-1">
                                <?php foreach ($latestRelease['changed_files']['added'] as $f): ?>
                                    <div class="text-[11px] font-mono text-slate-700 flex items-center gap-1.5">
                                        <span class="text-emerald-500 font-bold">+</span> <?= e($f) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($latestRelease['changed_files']['modified'])): ?>
                        <div class="pt-2 border-t border-slate-200">
                            <span class="text-[10px] font-mono font-bold text-blue-700 uppercase block mb-1">Modified Files</span>
                            <div class="space-y-1">
                                <?php foreach ($latestRelease['changed_files']['modified'] as $f): ?>
                                    <div class="text-[11px] font-mono text-slate-700 flex items-center gap-1.5">
                                        <span class="text-blue-500 font-bold">&bull;</span> <?= e($f) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($pendingMigrations)): ?>
                        <div class="pt-2 border-t border-slate-200">
                            <span class="text-[10px] font-mono font-bold text-purple-700 uppercase block mb-1">Pending Database Migrations</span>
                            <div class="space-y-1">
                                <?php foreach ($pendingMigrations as $pm): ?>
                                    <div class="text-[11px] font-mono text-purple-900 font-bold flex items-center gap-1.5">
                                        <span>⚡</span> <?= e($pm['file']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. PRE-UPDATE SYSTEM PREREQUISITES & HEALTH CHECKLIST -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-4">
        <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
            <div>
                <h2 class="text-base font-extrabold text-slate-900 tracking-tight">System Environment Verification</h2>
                <p class="text-xs text-slate-400 mt-0.5">Automated validation checks performed before any upgrade script executes</p>
            </div>
            <span class="px-2.5 py-0.5 rounded-full text-xs font-bold <?= $val['passed'] ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200' ?>">
                <?= $val['passed'] ? '✓ Prerequisites Met' : '⚠️ Action Required' ?>
            </span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <?php foreach ($val['checks'] as $c): ?>
                <div class="p-3.5 rounded-2xl border <?= $c['status'] ? 'bg-emerald-50/40 border-emerald-200/80' : 'bg-rose-50/50 border-rose-200' ?> space-y-1">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-extrabold text-slate-900 truncate"><?= e($c['name']) ?></span>
                        <span class="text-xs"><?= $c['status'] ? '✅' : '❌' ?></span>
                    </div>
                    <p class="text-[11px] <?= $c['status'] ? 'text-slate-600' : 'text-rose-700 font-medium' ?> leading-tight">
                        <?= e($c['message']) ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 3. VERIFIED BACKUPS & AUDIT LOGS -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Backups Storage -->
        <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm space-y-4">
            <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-extrabold text-slate-900">Verified System Backups</h3>
                    <p class="text-[11px] text-slate-400">Created automatically before every migration</p>
                </div>
                <span class="text-xs font-mono font-bold text-slate-500"><?= count($backups) ?> stored</span>
            </div>

            <?php if (empty($backups)): ?>
                <div class="p-6 text-center text-slate-400 text-xs">
                    No verified backups on record yet. Backups will be generated automatically upon install.
                </div>
            <?php else: ?>
                <div class="space-y-2 max-h-60 overflow-y-auto">
                    <?php foreach ($backups as $b): ?>
                        <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-between text-xs">
                            <div class="space-y-0.5">
                                <div class="font-mono font-bold text-slate-800 text-[11px] truncate max-w-[220px]"><?= e($b['name']) ?></div>
                                <div class="text-[10px] text-slate-400 font-mono"><?= e($b['date']) ?> &bull; <?= e($b['size']) ?></div>
                            </div>
                            <form action="/admin/updates.php" method="POST" onsubmit="return confirm('Restore from backup <?= addslashes($b['name']) ?>?')">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="rollback">
                                <input type="hidden" name="backup_file" value="<?= e($b['name']) ?>">
                                <button type="submit" class="px-2.5 py-1 bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 rounded-lg text-[11px] font-bold cursor-pointer">
                                    Restore
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Update Audit Logs -->
        <div class="bg-white rounded-3xl p-6 border border-slate-200/80 shadow-sm space-y-4">
            <div class="border-b border-slate-100 pb-3 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-extrabold text-slate-900">Update Audit Ledger</h3>
                    <p class="text-[11px] text-slate-400">History of updates, validation states &amp; health checks</p>
                </div>
                <span class="text-xs font-mono font-bold text-slate-500">Last <?= count($auditLogs) ?> entries</span>
            </div>

            <?php if (empty($auditLogs)): ?>
                <div class="p-6 text-center text-slate-400 text-xs">
                    No update logs recorded yet.
                </div>
            <?php else: ?>
                <div class="space-y-2 max-h-60 overflow-y-auto">
                    <?php foreach ($auditLogs as $log): 
                        $statusColor = match($log['status']) {
                            'success' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                            'in_progress' => 'bg-blue-50 text-blue-700 border-blue-200 animate-pulse',
                            'rolled_back' => 'bg-amber-50 text-amber-700 border-amber-200',
                            default => 'bg-rose-50 text-rose-700 border-rose-200'
                        };
                    ?>
                        <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs space-y-1">
                            <div class="flex items-center justify-between">
                                <span class="font-mono font-bold text-slate-800">
                                    v<?= e($log['version_from']) ?> &rarr; v<?= e($log['version_to']) ?>
                                </span>
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-extrabold uppercase border <?= $statusColor ?>">
                                    <?= e($log['status']) ?>
                                </span>
                            </div>
                            <div class="text-[10px] text-slate-400 font-mono flex items-center justify-between">
                                <span>Started: <?= date('d M Y, H:i', strtotime($log['started_at'])) ?></span>
                                <span>Health: <?= e($log['health_check_status']) ?></span>
                            </div>
                            <?php if (!empty($log['error_message'])): ?>
                                <p class="text-[10px] text-rose-600 font-mono truncate"><?= e($log['error_message']) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
