<?php
/**
 * Admin System Settings
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
        'site_name' => trim($_POST['site_name'] ?? 'SMM Panel'),
        'site_tagline' => trim($_POST['site_tagline'] ?? 'Grow Your Social Media'),
        'support_email' => trim($_POST['support_email'] ?? 'support@smmpanel.local'),
        'currency' => trim($_POST['currency'] ?? '₹'),
        'currency_code' => trim($_POST['currency_code'] ?? 'INR'),
        'min_deposit' => trim($_POST['min_deposit'] ?? '100'),
        'max_deposit' => trim($_POST['max_deposit'] ?? '50000'),
        'razorpay_key_id' => trim($_POST['razorpay_key_id'] ?? ''),
        'razorpay_key_secret' => trim($_POST['razorpay_key_secret'] ?? ''),
        'maintenance_mode' => isset($_POST['maintenance_mode']) ? '1' : '0',
        'allow_registration' => isset($_POST['allow_registration']) ? '1' : '0',
        'ai_enabled' => isset($_POST['ai_enabled']) ? '1' : '0',
        'ai_provider' => trim($_POST['ai_provider'] ?? 'openai'),
        'ai_model' => trim($_POST['ai_model'] ?? 'gpt-4o'),
        'ai_max_tokens' => trim($_POST['ai_max_tokens'] ?? '500'),
        'ai_system_prompt' => trim($_POST['ai_system_prompt'] ?? 'You are a professional customer support assistant for an SMM Panel platform. Be polite, concise, and helpful. Provide clear resolution steps for order, payment, and service queries.')
    ];

    if (!empty($_POST['ai_api_key'])) {
        $settings['ai_api_key'] = trim($_POST['ai_api_key']);
    }

    foreach ($settings as $k => $v) {
        set_setting($k, $v);
    }
    update_config_file_settings($settings);

    set_flash('success', 'System settings updated and saved successfully.');
    redirect('/admin/settings.php');
}

$siteName = get_setting('site_name', APP_NAME);
$siteTagline = get_setting('site_tagline', APP_TAGLINE);
$supportEmail = get_setting('support_email', 'support@smmpanel.local');
$currency = get_setting('currency', APP_CURRENCY);
$currencyCode = get_setting('currency_code', APP_CURRENCY_CODE);
$minDeposit = get_setting('min_deposit', '100');
$maxDeposit = get_setting('max_deposit', '50000');
$razorpayKeyId = get_setting('razorpay_key_id', defined('RAZORPAY_KEY_ID') ? RAZORPAY_KEY_ID : '');
$razorpayKeySecret = get_setting('razorpay_key_secret', defined('RAZORPAY_KEY_SECRET') ? RAZORPAY_KEY_SECRET : '');
$maintenanceMode = get_setting('maintenance_mode', '0') === '1';
$allowRegistration = get_setting('allow_registration', '1') === '1';
$aiEnabled = get_setting('ai_enabled', '0') === '1';
$aiProvider = get_setting('ai_provider', 'openai');
$aiModel = get_setting('ai_model', 'gpt-4o');
$aiHasApiKey = !empty(get_setting('ai_api_key', ''));
$aiMaxTokens = get_setting('ai_max_tokens', '500');
$aiSystemPrompt = get_setting('ai_system_prompt', 'You are a professional customer support assistant for an SMM Panel platform. Be polite, concise, and helpful. Provide clear resolution steps for order, payment, and service queries.');

$pageTitle = "System Settings - " . $siteName . " Admin";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto my-6 space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">System Settings</h1>
            <p class="text-xs text-slate-500 mt-1">Configure global application branding, payment gateways, and security controls</p>
        </div>
        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200/60">
            Database &amp; Config Synced
        </span>
    </div>

    <?= render_flash() ?>

    <form action="/admin/settings.php" method="POST" class="space-y-6">
        <?= CSRF::field() ?>

        <!-- 1. General & Branding -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
            <div class="border-b border-slate-100 pb-4">
                <h2 class="text-base font-extrabold text-slate-900 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-sm font-bold">🏷️</span>
                    General &amp; Branding
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Control the public identity and support channels of your platform</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Site Name <span class="text-rose-500">*</span></label>
                    <input type="text" name="site_name" value="<?= e($siteName) ?>" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                    <span class="text-[11px] text-slate-400 mt-1 block">Displayed on header, page titles, footer and notifications</span>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Site Tagline</label>
                    <input type="text" name="site_tagline" value="<?= e($siteTagline) ?>" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                    <span class="text-[11px] text-slate-400 mt-1 block">Subheading shown on landing and marketing pages</span>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1.5">Support &amp; Contact Email</label>
                <input type="email" name="support_email" value="<?= e($supportEmail) ?>" class="w-full sm:w-1/2 px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                <span class="text-[11px] text-slate-400 mt-1 block">Email address shown to users for tickets and questions</span>
            </div>
        </div>

        <!-- 2. Currency & Pricing -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
            <div class="border-b border-slate-100 pb-4">
                <h2 class="text-base font-extrabold text-slate-900 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-sm font-bold">💵</span>
                    Currency &amp; Deposit Limits
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Control panel currency symbol and wallet top-up bounds</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Currency Symbol <span class="text-rose-500">*</span></label>
                    <input type="text" name="currency" value="<?= e($currency) ?>" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                    <span class="text-[11px] text-slate-400 mt-1 block">E.g. ₹, $, €</span>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Currency ISO Code <span class="text-rose-500">*</span></label>
                    <input type="text" name="currency_code" value="<?= e($currencyCode) ?>" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900 uppercase focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                    <span class="text-[11px] text-slate-400 mt-1 block">E.g. INR, USD, EUR, GBP</span>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Minimum Deposit (<?= e($currency) ?>)</label>
                    <input type="number" step="0.01" min="1" name="min_deposit" value="<?= e($minDeposit) ?>" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                    <span class="text-[11px] text-slate-400 mt-1 block">Minimum wallet addition amount</span>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Maximum Deposit (<?= e($currency) ?>)</label>
                    <input type="number" step="0.01" min="10" name="max_deposit" value="<?= e($maxDeposit) ?>" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                    <span class="text-[11px] text-slate-400 mt-1 block">Maximum single wallet addition amount</span>
                </div>
            </div>
        </div>

        <!-- 3. Payment Gateway Credentials -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
            <div class="border-b border-slate-100 pb-4">
                <h2 class="text-base font-extrabold text-slate-900 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-sm font-bold">💳</span>
                    Razorpay Gateway Credentials
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Automated UPI, QR code, Card and Net Banking payment processing</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Razorpay Key ID</label>
                    <input type="text" name="razorpay_key_id" value="<?= e($razorpayKeyId) ?>" placeholder="rzp_test_..." class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Razorpay Key Secret</label>
                    <input type="password" name="razorpay_key_secret" value="<?= e($razorpayKeySecret) ?>" placeholder="••••••••••••" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all">
                </div>
            </div>
        </div>

        <!-- 4. Security & Access Control -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
            <div class="border-b border-slate-100 pb-4">
                <h2 class="text-base font-extrabold text-slate-900 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-sm font-bold">🛡️</span>
                    Access &amp; Security Controls
                </h2>
                <p class="text-xs text-slate-400 mt-0.5">Maintenance mode and public user registrations</p>
            </div>

            <div class="space-y-4">
                <label class="flex items-start gap-3 p-4 rounded-2xl bg-slate-50 border border-slate-200/70 cursor-pointer hover:bg-slate-100/60 transition-colors">
                    <input type="checkbox" name="allow_registration" value="1" <?= $allowRegistration ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 text-blue-600 rounded border-slate-300 focus:ring-blue-500">
                    <div>
                        <span class="text-xs font-bold text-slate-900 block">Allow New User Registrations</span>
                        <span class="text-[11px] text-slate-500 block mt-0.5">When checked, guests can register for a new account. When unchecked, registration is closed.</span>
                    </div>
                </label>

                <label class="flex items-start gap-3 p-4 rounded-2xl bg-amber-50/50 border border-amber-200/70 cursor-pointer hover:bg-amber-100/40 transition-colors">
                    <input type="checkbox" name="maintenance_mode" value="1" <?= $maintenanceMode ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 text-amber-600 rounded border-slate-300 focus:ring-amber-500">
                    <div>
                        <span class="text-xs font-bold text-amber-900 block">Enable Maintenance Mode</span>
                        <span class="text-[11px] text-amber-700 block mt-0.5">Restricts normal user access and shows maintenance banner. Admin accounts continue to have full access.</span>
                    </div>
                </label>
            </div>
        </div>

        <!-- 5. Future AI Ticket Reply Assistant Configuration -->
        <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-sm space-y-6">
            <div class="border-b border-slate-100 pb-4 flex items-start justify-between">
                <div>
                    <h2 class="text-base font-extrabold text-slate-900 flex items-center gap-2">
                        <span class="w-8 h-8 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-sm font-bold">✨</span>
                        AI Support Ticket Assistant
                    </h2>
                    <p class="text-xs text-slate-400 mt-0.5">Server-side credentials &amp; architecture for AI-assisted customer ticket responses</p>
                </div>
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-purple-50 text-purple-700 border border-purple-200">
                    Future Integration Ready
                </span>
            </div>

            <div class="space-y-4">
                <label class="flex items-start gap-3 p-4 rounded-2xl bg-slate-50 border border-slate-200/70 cursor-pointer hover:bg-slate-100/60 transition-colors">
                    <input type="checkbox" name="ai_enabled" value="1" <?= $aiEnabled ? 'checked' : '' ?> class="mt-0.5 w-4 h-4 text-purple-600 rounded border-slate-300 focus:ring-purple-500">
                    <div>
                        <span class="text-xs font-bold text-slate-900 block">Enable AI Ticket Reply Assistant</span>
                        <span class="text-[11px] text-slate-500 block mt-0.5">Enables the "✨ Generate AI Reply" button on admin support ticket threads to suggest contextual responses.</span>
                    </div>
                </label>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 pt-2">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">AI Provider</label>
                        <select name="ai_provider" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all">
                            <option value="openai" <?= $aiProvider === 'openai' ? 'selected' : '' ?>>OpenAI (ChatGPT / GPT-4o)</option>
                            <option value="gemini" <?= $aiProvider === 'gemini' ? 'selected' : '' ?>>Google Gemini</option>
                            <option value="anthropic" <?= $aiProvider === 'anthropic' ? 'selected' : '' ?>>Anthropic Claude</option>
                            <option value="custom" <?= $aiProvider === 'custom' ? 'selected' : '' ?>>Custom API Provider</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">AI Model</label>
                        <input type="text" name="ai_model" value="<?= e($aiModel) ?>" placeholder="gpt-4o" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">
                            AI Provider API Key 
                            <?php if ($aiHasApiKey): ?>
                                <span class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-md ml-1">Key Saved</span>
                            <?php endif; ?>
                        </label>
                        <input type="password" name="ai_api_key" placeholder="<?= $aiHasApiKey ? '••••••••••••••••••••••••' : 'Enter AI Provider API Key' ?>" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono text-slate-900 focus:outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all">
                        <span class="text-[11px] text-slate-400 mt-1 block">Stored securely in settings; never exposed to browser client-side code</span>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">Max Response Length (Tokens)</label>
                        <input type="number" name="ai_max_tokens" value="<?= e($aiMaxTokens) ?>" min="50" max="2000" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all">
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold text-slate-700 mb-1.5">System Prompt / Assistance Instructions</label>
                        <textarea name="ai_system_prompt" rows="3" class="w-full p-4 bg-slate-50 border border-slate-200 rounded-xl text-xs font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all leading-relaxed"><?= e($aiSystemPrompt) ?></textarea>
                        <span class="text-[11px] text-slate-400 mt-1 block">Contextual instructions enforced on server-side when generating draft replies for admin review</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 pt-4">
            <a href="/admin/dashboard.php" class="px-5 py-2.5 rounded-xl border border-slate-200 text-xs font-bold text-slate-600 hover:bg-slate-50 transition-colors">
                Cancel
            </a>
            <button type="submit" class="px-7 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-md shadow-blue-500/25 transition-all">
                Save System Settings
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
