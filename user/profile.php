<?php
/**
 * User Profile & Settings
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireLogin();
$user = Auth::user();
$db = Database::getConnection();

$tab = $_GET['tab'] ?? 'account';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $username = trim($_POST['username'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (empty($username)) {
            $error = "Name cannot be empty.";
        } else {
            $stmt = $db->prepare("UPDATE users SET username = :username, phone = :phone WHERE id = :id");
            $stmt->execute(['username' => $username, 'phone' => $phone, 'id' => $user['id']]);
            set_flash('success', 'Profile updated successfully!');
            redirect('/user/profile.php');
        }
    } elseif ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $stmt = $db->prepare("SELECT password FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $user['id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($current, $row['password'])) {
            $error = "Incorrect current password.";
        } elseif (strlen($new) < 8) {
            $error = "New password must be at least 8 characters long.";
        } elseif ($new !== $confirm) {
            $error = "New passwords do not match.";
        } else {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = :pwd WHERE id = :id");
            $stmt->execute(['pwd' => $hashed, 'id' => $user['id']]);
            set_flash('success', 'Password updated successfully!');
            redirect('/user/profile.php?tab=password');
        }
    } elseif ($action === 'regenerate_api') {
        $newApiKey = 'usr_' . bin2hex(random_bytes(12));
        $stmt = $db->prepare("UPDATE users SET api_key = :key WHERE id = :id");
        $stmt->execute(['key' => $newApiKey, 'id' => $user['id']]);
        set_flash('success', 'New API Key generated successfully!');
        redirect('/user/profile.php?tab=api');
    }
}

$pageTitle = "Profile - " . app_name();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto my-6 space-y-6">
    <div class="flex items-center justify-between">
        <a href="/user/dashboard.php" class="text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            &larr; Back to Dashboard
        </a>
    </div>

    <!-- Main Profile Card -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl">
        <h2 class="text-2xl font-extrabold text-slate-900 pb-6 border-b border-slate-100">Profile & Settings</h2>

        <?php if ($error): ?>
            <div class="my-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mt-6">
            <!-- Left Tabs -->
            <div class="space-y-1">
                <a href="/user/profile.php?tab=account" class="flex items-center gap-3 px-4 py-3 rounded-2xl text-xs font-bold transition-all <?= $tab === 'account' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'text-slate-600 hover:bg-slate-50' ?>">
                    <span>👤</span> Account Info
                </a>
                <a href="/user/profile.php?tab=password" class="flex items-center gap-3 px-4 py-3 rounded-2xl text-xs font-bold transition-all <?= $tab === 'password' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'text-slate-600 hover:bg-slate-50' ?>">
                    <span>🔒</span> Change Password
                </a>
                <a href="/user/profile.php?tab=api" class="flex items-center gap-3 px-4 py-3 rounded-2xl text-xs font-bold transition-all <?= $tab === 'api' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'text-slate-600 hover:bg-slate-50' ?>">
                    <span>⚡</span> API Access
                </a>
            </div>

            <!-- Right Content Panels -->
            <div class="md:col-span-2">
                <?php if ($tab === 'account'): ?>
                    <!-- Avatar & User ID header -->
                    <div class="flex items-center gap-4 p-4 bg-slate-50 rounded-2xl border border-slate-200/60 mb-6">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 flex items-center justify-center text-white text-xl font-bold">
                            <?= strtoupper(substr($user['username'], 0, 1)) ?>
                        </div>
                        <div>
                            <h3 class="text-base font-extrabold text-slate-900"><?= e($user['username']) ?></h3>
                            <span class="text-xs text-slate-400">User ID: <strong class="text-slate-700">#<?= (int)$user['id'] ?></strong></span>
                        </div>
                    </div>

                    <form action="/user/profile.php" method="POST" class="space-y-4">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="update_profile">

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Full Name</label>
                            <input type="text" name="username" value="<?= e($user['username']) ?>" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                        </div>

                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-xs font-bold text-slate-700">Email Address</label>
                                <span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-600 text-[10px] font-bold">Verified</span>
                            </div>
                            <input type="email" value="<?= e($user['email']) ?>" disabled class="w-full px-4 py-2.5 bg-slate-100 border border-slate-200 rounded-xl text-xs text-slate-500 cursor-not-allowed">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Phone Number</label>
                            <input type="tel" name="phone" value="<?= e($user['phone'] ?? '+91 98765 43210') ?>" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Joined Date</label>
                            <input type="text" value="<?= date('d M Y', strtotime($user['created_at'])) ?>" disabled class="w-full px-4 py-2.5 bg-slate-100 border border-slate-200 rounded-xl text-xs text-slate-500 cursor-not-allowed">
                        </div>

                        <button type="submit" class="w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-md transition-all mt-4">
                            Save Changes
                        </button>
                    </form>

                <?php elseif ($tab === 'password'): ?>
                    <form action="/user/profile.php?tab=password" method="POST" class="space-y-4">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="change_password">

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Current Password</label>
                            <input type="password" name="current_password" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">New Password</label>
                            <input type="password" name="new_password" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Confirm New Password</label>
                            <input type="password" name="confirm_password" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                        </div>

                        <button type="submit" class="w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-md transition-all mt-4">
                            Update Password
                        </button>
                    </form>

                <?php elseif ($tab === 'api'): ?>
                    <div class="space-y-4">
                        <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200/60">
                            <label class="block text-xs font-bold text-slate-700 mb-1">Your API Key</label>
                            <div class="flex items-center gap-2">
                                <input type="text" readonly value="<?= e($user['api_key']) ?>" id="apiKeyField" class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl text-xs font-mono text-slate-700 select-all">
                                <button type="button" onclick="copyToClipboard(document.getElementById('apiKeyField').value, 'API Key copied to clipboard!');" class="px-4 py-2.5 bg-slate-200 hover:bg-slate-300 rounded-xl text-xs font-bold text-slate-700">Copy</button>
                            </div>
                        </div>

                        <p class="text-xs text-slate-500">
                            Use this key to automate orders through the SMM API v2 endpoint at <code><?= e(APP_URL) ?>/api/orders.php</code>.
                        </p>

                        <form action="/user/profile.php?tab=api" method="POST" onsubmit="return confirm('Regenerating will invalidate your existing API key. Continue?');">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="regenerate_api">
                            <button type="submit" class="px-5 py-2.5 bg-rose-50 border border-rose-200 text-rose-600 hover:bg-rose-100 font-bold text-xs rounded-xl transition-colors">
                                Regenerate API Key
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
