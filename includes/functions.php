<?php
/**
 * Helper Functions
 * SMM Panel - PHP 8.3+
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

function e(?string $string): string {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

/**
 * Dynamic System Settings from Database
 */
function get_setting(string $key, ?string $default = null): ?string {
    if (!isset($GLOBALS['_app_settings_cache'])) {
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT `key`, `value` FROM settings");
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $GLOBALS['_app_settings_cache'] = is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            $GLOBALS['_app_settings_cache'] = [];
        }
    }
    return $GLOBALS['_app_settings_cache'][$key] ?? $default;
}

function get_all_settings(): array {
    if (isset($GLOBALS['_app_settings_cache'])) {
        return $GLOBALS['_app_settings_cache'];
    }
    try {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT `key`, `value` FROM settings");
        $GLOBALS['_app_settings_cache'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        return $GLOBALS['_app_settings_cache'];
    } catch (\Throwable $e) {
        return [];
    }
}

function set_setting(string $key, string $value): bool {
    try {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM settings WHERE `key` = :k LIMIT 1");
        $stmt->execute(['k' => $key]);
        $success = false;
        if ($stmt->fetch()) {
            $upd = $db->prepare("UPDATE settings SET `value` = :v, updated_at = CURRENT_TIMESTAMP WHERE `key` = :k");
            $success = $upd->execute(['v' => $value, 'k' => $key]);
        } else {
            $ins = $db->prepare("INSERT INTO settings (`key`, `value`) VALUES (:k, :v)");
            $success = $ins->execute(['k' => $key, 'v' => $value]);
        }
        if ($success) {
            if (!isset($GLOBALS['_app_settings_cache'])) {
                $GLOBALS['_app_settings_cache'] = [];
            }
            $GLOBALS['_app_settings_cache'][$key] = $value;
        }
        return $success;
    } catch (\Throwable $e) {
        error_log("set_setting error: " . $e->getMessage());
        return false;
    }
}

function update_config_file_settings(array $pairs): bool {
    $configFile = __DIR__ . '/config.php';
    if (!file_exists($configFile) || !is_writable($configFile)) {
        return false;
    }
    $content = file_get_contents($configFile);
    if ($content === false) return false;

    $map = [
        'site_name' => 'APP_NAME',
        'site_tagline' => 'APP_TAGLINE',
        'currency' => 'APP_CURRENCY',
        'currency_code' => 'APP_CURRENCY_CODE',
        'razorpay_key_id' => 'RAZORPAY_KEY_ID',
        'razorpay_key_secret' => 'RAZORPAY_KEY_SECRET'
    ];

    foreach ($pairs as $key => $val) {
        if (isset($map[$key])) {
            $constName = $map[$key];
            $escaped = addcslashes($val, "'\\");
            $pattern = "/define\s*\(\s*['\"]" . preg_quote($constName, '/') . "['\"]\s*,\s*.*?['\"]\s*\);/";
            $replacement = "define('" . $constName . "', '" . $escaped . "');";
            $content = preg_replace($pattern, $replacement, $content);
        }
    }

    return (bool)file_put_contents($configFile, $content);
}

function app_name(): string {
    $s = get_setting('site_name');
    if ($s !== null && $s !== '') return $s;
    return defined('APP_NAME') ? APP_NAME : 'SMM Panel';
}

function app_tagline(): string {
    $s = get_setting('site_tagline');
    if ($s !== null && $s !== '') return $s;
    return defined('APP_TAGLINE') ? APP_TAGLINE : 'Grow Your Social Media';
}

function app_currency(): string {
    $s = get_setting('currency');
    if ($s !== null && $s !== '') return $s;
    return defined('APP_CURRENCY') ? APP_CURRENCY : '₹';
}

function app_currency_code(): string {
    $s = get_setting('currency_code');
    if ($s !== null && $s !== '') return $s;
    return defined('APP_CURRENCY_CODE') ? APP_CURRENCY_CODE : 'INR';
}

function is_maintenance_mode(): bool {
    return get_setting('maintenance_mode', '0') === '1';
}

function check_maintenance_mode(): void {
    if (!is_maintenance_mode()) {
        return;
    }

    // Admins always have access
    if (class_exists('Auth') && Auth::check() && Auth::isAdmin()) {
        return;
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '';

    // Allow admin section so admins can log in and manage settings
    if (str_starts_with($script, '/admin/')) {
        return;
    }

    // Allow login and logout scripts
    if (str_ends_with($script, 'login.php') || str_ends_with($script, 'logout.php')) {
        return;
    }

    // Handle API endpoints
    if (str_starts_with($script, '/api/') || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'error' => 'System is currently undergoing scheduled maintenance. Please try again later.'
        ]);
        exit;
    }

    // Render HTML maintenance page for public and user pages
    render_maintenance_page();
    exit;
}

function render_maintenance_page(): void {
    http_response_code(503);
    header('Retry-After: 3600');
    $appName = app_name();
    $appTagline = app_tagline();
    $supportEmail = get_setting('support_email', 'support@smmpanel.local');
    ?>
    <!DOCTYPE html>
    <html lang="en" class="h-full bg-slate-900">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?> - Maintenance Mode</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
    </head>
    <body class="h-full flex items-center justify-center p-4 bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-950 text-white">
        <div class="max-w-lg w-full text-center space-y-6 bg-slate-900/90 backdrop-blur-xl p-8 sm:p-10 rounded-3xl border border-slate-800 shadow-2xl">
            <div class="w-16 h-16 rounded-2xl bg-amber-500/20 text-amber-400 border border-amber-500/30 flex items-center justify-center text-3xl mx-auto shadow-inner">
                🛠️
            </div>
            
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-500/10 border border-amber-500/30 text-amber-300 text-xs font-bold uppercase tracking-wider">
                <span class="w-2 h-2 rounded-full bg-amber-400 animate-pulse"></span>
                Scheduled Maintenance Active
            </div>

            <div>
                <h1 class="text-3xl font-extrabold tracking-tight"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="text-xs text-indigo-300/80 font-medium mt-1"><?= htmlspecialchars($appTagline, ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <p class="text-sm text-slate-300 leading-relaxed font-normal">
                We are currently performing routine upgrades and server maintenance to enhance your experience. Normal access is temporarily paused. We will be back online shortly!
            </p>

            <div class="pt-4 border-t border-slate-800/80 flex flex-col sm:flex-row items-center justify-center gap-4 text-xs">
                <?php if ($supportEmail): ?>
                    <a href="mailto:<?= htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') ?>" class="text-slate-400 hover:text-slate-200 transition-colors">
                        <?= htmlspecialchars($supportEmail, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endif; ?>
                <a href="/login.php" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 hover:text-white font-bold transition-all border border-slate-700/60 inline-flex items-center gap-1.5">
                    <span>🔐</span> Admin Login
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

function format_currency(float|int|string $amount): string {
    $val = (float)$amount;
    return app_currency() . number_format($val, 2);
}

function redirect(string $path): never {
    header("Location: " . $path);
    exit;
}

function set_flash(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type' => $type, // 'success', 'error', 'warning', 'info'
        'message' => $message
    ];
}

function get_flash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function render_flash(): string {
    $flash = get_flash();
    if (!$flash) return '';
    $colors = [
        'success' => 'bg-emerald-500/10 border-emerald-500/30 text-emerald-700',
        'error' => 'bg-rose-500/10 border-rose-500/30 text-rose-700',
        'warning' => 'bg-amber-500/10 border-amber-500/30 text-amber-700',
        'info' => 'bg-blue-500/10 border-blue-500/30 text-blue-700'
    ];
    $cls = $colors[$flash['type']] ?? $colors['info'];
    return sprintf(
        '<div class="p-4 mb-4 text-sm rounded-xl border %s flex items-center justify-between" role="alert"><span>%s</span><button type="button" onclick="this.parentElement.remove()" class="text-xs font-bold opacity-75 hover:opacity-100">&times;</button></div>',
        $cls,
        e($flash['message'])
    );
}

/**
 * Standard SMM Provider API Connector (cURL)
 */
function call_provider_api(string $apiUrl, array $postData, int $timeout = 15): array {
    if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
        return ['error' => 'Invalid Provider API URL: ' . $apiUrl];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'User-Agent: SMM-Panel-Engine/1.0',
            'Accept: application/json'
        ]
    ]);
    
    $response = curl_exec($ch);
    $errorNumber = curl_errno($ch);
    $errorMessage = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errorNumber !== 0) {
        return ['error' => 'Provider connection failed (' . $errorMessage . ')'];
    }

    if ($httpCode >= 400) {
        return ['error' => 'Provider server returned HTTP ' . $httpCode];
    }

    if (empty($response)) {
        return ['error' => 'Empty response received from provider API'];
    }

    $decoded = json_decode((string)$response, true);
    if (!is_array($decoded)) {
        return ['error' => 'Invalid JSON received from provider API'];
    }

    return $decoded;
}
