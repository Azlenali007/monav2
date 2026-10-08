<?php
/**
 * User Registration Page
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';

if (Auth::check()) {
    redirect('/user/dashboard.php');
}

$allowRegistration = get_setting('allow_registration', '1') === '1';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();

    if (!$allowRegistration) {
        $error = "New user registrations are currently closed by the administrator.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['password_confirm'] ?? '');

        if (empty($username) || empty($email) || empty($password)) {
            $error = "Please fill in all required fields.";
        } elseif ($password !== $confirmPassword) {
            $error = "Passwords do not match.";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters long.";
        } else {
            $db = Database::getConnection();
            
            // Check if email or username already taken
            $stmt = $db->prepare("SELECT id FROM users WHERE email = :email OR username = :username LIMIT 1");
            $stmt->execute(['email' => $email, 'username' => $username]);
            if ($stmt->fetch()) {
                $error = "An account with this email or username already exists.";
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $apiKey = 'usr_' . bin2hex(random_bytes(12));

                $insert = $db->prepare("
                    INSERT INTO users (username, email, password, phone, balance, role, status, api_key, email_verified)
                    VALUES (:username, :email, :password, :phone, 0.0000, 'user', 'active', :api_key, 1)
                ");
                $insert->execute([
                    'username' => $username,
                    'email' => $email,
                    'password' => $hashedPassword,
                    'phone' => $phone ?: null,
                    'api_key' => $apiKey
                ]);

                $newUserId = (int)$db->lastInsertId();
                session_regenerate_id(true);
                $_SESSION['user_id'] = $newUserId;
                $_SESSION['user_role'] = 'user';

                set_flash('success', 'Account created successfully! Welcome to ' . app_name() . '.');
                redirect('/user/dashboard.php');
            }
        }
    }
}

$pageTitle = "Register - " . app_name();
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-4xl mx-auto my-8">
    <div class="bg-white rounded-3xl shadow-xl border border-slate-200/80 overflow-hidden grid grid-cols-1 md:grid-cols-2">
        <!-- Left Banner -->
        <div class="bg-gradient-to-br from-blue-600 via-indigo-600 to-purple-700 p-8 text-white flex flex-col justify-between">
            <div>
                <div class="flex items-center gap-2 mb-8">
                    <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center">
                        <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" /></svg>
                    </div>
                    <span class="font-bold text-lg"><?= e(app_name()) ?></span>
                </div>
                <h2 class="text-3xl font-extrabold leading-tight">
                    Start Growing Today
                </h2>
                <p class="mt-4 text-xs text-blue-100 max-w-xs">
                    Join thousands of creators, influencers, and agencies scaling their digital reach with our high-speed panel.
                </p>
            </div>
            <div class="pt-8 text-xs text-blue-200">
                <span>⚡ 100% Safe & Tested</span> • <span>🚀 Instant Activation</span>
            </div>
        </div>

        <!-- Right Form -->
        <div class="p-8 sm:p-10 flex flex-col justify-center">
            <!-- Tabs -->
            <div class="flex rounded-xl bg-slate-100 p-1 mb-6">
                <a href="/login.php" class="flex-1 text-center py-2 text-xs font-bold rounded-lg text-slate-600 hover:text-slate-900">Login</a>
                <a href="/register.php" class="flex-1 text-center py-2 text-xs font-bold rounded-lg bg-white text-blue-600 shadow-sm">Register</a>
            </div>

            <h3 class="text-2xl font-extrabold text-slate-900">Create Account</h3>
            <p class="text-xs text-slate-500 mt-1">Get started in under 30 seconds.</p>

            <?php if (!$allowRegistration): ?>
                <div class="mt-6 p-5 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 text-xs font-medium space-y-3">
                    <div class="flex items-center gap-2 text-amber-800 font-bold text-sm">
                        <span>⚠️</span> New Registrations Closed
                    </div>
                    <p>New user registrations are currently disabled by administrator policy. Please check back later or log in to an existing account.</p>
                    <a href="/login.php" class="inline-block px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-bold rounded-xl transition-colors">Go to Login &rarr;</a>
                </div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="mt-4 p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-600 text-xs font-semibold">
                        <?= e($error) ?>
                    </div>
                <?php endif; ?>

                <form action="/register.php" method="POST" class="mt-5 space-y-3.5">
                    <?= CSRF::field() ?>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Full Name or Username</label>
                        <input type="text" name="username" required placeholder="Aaris Ali" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Email Address</label>
                        <input type="email" name="email" required placeholder="aarisali@gmail.com" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Phone Number (Optional)</label>
                        <input type="tel" name="phone" placeholder="+91 98765 43210" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Password</label>
                            <input type="password" name="password" required placeholder="••••••••" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">Confirm</label>
                            <input type="password" name="password_confirm" required placeholder="••••••••" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600 transition-all">
                        </div>
                    </div>

                    <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm rounded-xl shadow-lg shadow-blue-500/25 transition-all mt-2">
                        Create Account &rarr;
                    </button>
                </form>
            <?php endif; ?>

            <p class="mt-4 text-center text-xs text-slate-500">
                Already have an account? <a href="/login.php" class="font-bold text-blue-600 hover:underline">Login</a>
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
