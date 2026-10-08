<?php
/**
 * Admin Payment Gateways
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireAdmin();
$db = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();

    $settings = [
        'razorpay_key_id' => trim($_POST['razorpay_key_id'] ?? ''),
        'razorpay_key_secret' => trim($_POST['razorpay_key_secret'] ?? ''),
        'min_deposit' => trim($_POST['min_deposit'] ?? '100'),
        'max_deposit' => trim($_POST['max_deposit'] ?? '50000'),
    ];

    foreach ($settings as $k => $v) {
        set_setting($k, $v);
    }
    update_config_file_settings($settings);

    set_flash('success', 'Payment gateway settings saved successfully.');
    redirect('/admin/payments.php');
}

$razorpayKeyId = get_setting('razorpay_key_id', defined('RAZORPAY_KEY_ID') ? RAZORPAY_KEY_ID : '');
$razorpayKeySecret = get_setting('razorpay_key_secret', defined('RAZORPAY_KEY_SECRET') ? RAZORPAY_KEY_SECRET : '');
$minDeposit = get_setting('min_deposit', '100');
$maxDeposit = get_setting('max_deposit', '50000');

$pageTitle = "Payment Gateways - " . app_name() . " Admin";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto my-4 space-y-6">
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl">
        <h2 class="text-2xl font-extrabold text-slate-900 pb-6 border-b border-slate-100">Payment Gateway Configuration</h2>

        <form action="/admin/payments.php" method="POST" class="mt-6 space-y-6">
            <?= CSRF::field() ?>

            <!-- Razorpay Card -->
            <div class="p-6 rounded-2xl bg-blue-50/50 border border-blue-200 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-extrabold text-blue-900">Razorpay (Cards, UPI, NetBanking)</h3>
                    <span class="px-2.5 py-1 rounded bg-emerald-100 text-emerald-800 text-[10px] font-bold uppercase">Supported</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Razorpay Key ID</label>
                        <input type="text" name="razorpay_key_id" value="<?= e($razorpayKeyId) ?>" placeholder="rzp_test_..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Razorpay Key Secret</label>
                        <input type="password" name="razorpay_key_secret" value="<?= e($razorpayKeySecret) ?>" placeholder="••••••••••••" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-mono">
                    </div>
                </div>
            </div>

            <!-- Deposit Limits -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Minimum Deposit Amount (<?= e(app_currency()) ?>)</label>
                    <input type="number" step="0.01" min="1" name="min_deposit" value="<?= e($minDeposit) ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Maximum Deposit Amount (<?= e(app_currency()) ?>)</label>
                    <input type="number" step="0.01" min="10" name="max_deposit" value="<?= e($maxDeposit) ?>" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs">
                </div>
            </div>

            <div class="text-right">
                <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-md shadow-blue-500/25 transition-all">
                    Save Gateway Settings
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
