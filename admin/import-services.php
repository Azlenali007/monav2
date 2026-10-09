<?php
/**
 * Admin Service Import From Provider API (Real Production Implementation)
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

// Handle AJAX Backend Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrAbort();
    $action = $_POST['action'] ?? '';
    $isAjax = isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

    if ($action === 'fetch_services') {
        header('Content-Type: application/json; charset=UTF-8');
        $providerId = (int)($_POST['provider_id'] ?? 0);

        if ($providerId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Please select a valid provider.']);
            exit;
        }

        // 1. Load provider credentials strictly server-side
        $stmt = $db->prepare("SELECT id, name, api_url, api_key, currency, status FROM providers WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $providerId]);
        $provider = $stmt->fetch();

        if (!$provider) {
            echo json_encode(['success' => false, 'error' => "Provider #{$providerId} not found in database."]);
            exit;
        }

        if ($provider['status'] !== 'active') {
            echo json_encode(['success' => false, 'error' => "Provider '{$provider['name']}' is currently disabled."]);
            exit;
        }

        if (empty($provider['api_url']) || empty($provider['api_key'])) {
            echo json_encode(['success' => false, 'error' => "Provider API URL or API Key is missing. Please configure credentials in Admin > Providers."]);
            exit;
        }

        // 2. Query external Provider API server-side using cURL
        $response = call_provider_api($provider['api_url'], [
            'key' => $provider['api_key'],
            'action' => 'services'
        ], 25);

        // Check for cURL or HTTP transport error
        if (!empty($response['error'])) {
            $safeError = is_string($response['error']) ? $response['error'] : 'Failed to communicate with provider API.';
            echo json_encode(['success' => false, 'error' => "API Communication Error: {$safeError}"]);
            exit;
        }

        // Check if provider returned an API error format (e.g. {"error": "..."})
        if (isset($response['error']) || (isset($response['status']) && $response['status'] === 'fail')) {
            $msg = $response['error'] ?? ($response['message'] ?? 'Provider rejected services request.');
            echo json_encode(['success' => false, 'error' => "Provider Error: {$msg}"]);
            exit;
        }

        if (!is_array($response)) {
            echo json_encode(['success' => false, 'error' => 'Provider returned invalid or non-list response structure.']);
            exit;
        }

        // 3. Fetch already-imported services for this provider to detect duplicates
        $checkStmt = $db->prepare("SELECT provider_service_id FROM services WHERE provider_id = :pid AND provider_service_id IS NOT NULL");
        $checkStmt->execute(['pid' => $providerId]);
        $existingServiceIds = $checkStmt->fetchAll(PDO::FETCH_COLUMN);
        $existingSet = array_flip(array_map('strval', $existingServiceIds));

        // Fetch categories for intelligent matching
        $catsStmt = $db->query("SELECT id, name, platform FROM categories WHERE status = 'active' ORDER BY sort_order ASC");
        $allCategories = $catsStmt->fetchAll();

        // 4. Normalize returned services safely
        $normalizedList = [];
        foreach ($response as $item) {
            if (!is_array($item)) continue;

            $normalized = normalize_provider_service($item);
            $psid = (string)$normalized['provider_service_id'];

            if ($psid === '') continue;

            $normalized['already_imported'] = isset($existingSet[$psid]);
            $normalized['matched_category_id'] = find_matching_category_id($normalized['category_name'], (int)($allCategories[0]['id'] ?? 1));

            $normalizedList[] = $normalized;
        }

        echo json_encode([
            'success' => true,
            'provider_name' => $provider['name'],
            'provider_currency' => $provider['currency'] ?? 'USD',
            'services' => $normalizedList,
            'total_count' => count($normalizedList),
            'already_imported_count' => count(array_filter($normalizedList, fn($s) => $s['already_imported']))
        ]);
        exit;
    }

    if ($action === 'import_services') {
        header('Content-Type: application/json; charset=UTF-8');
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $importMode = trim($_POST['import_mode'] ?? 'both'); // 'both', 'categories_only', 'services_only'
        $markupPercent = (float)($_POST['markup_percent'] ?? 0.0);
        $markupFixed = (float)($_POST['markup_fixed'] ?? 0.0);
        $defaultCategoryId = (int)($_POST['default_category_id'] ?? 1);
        $rawServicesJson = $_POST['services_data'] ?? '';

        if ($providerId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid provider ID specified.']);
            exit;
        }

        // Verify provider exists
        $stmtProv = $db->prepare("SELECT id, name FROM providers WHERE id = :id AND status = 'active' LIMIT 1");
        $stmtProv->execute(['id' => $providerId]);
        $prov = $stmtProv->fetch();

        if (!$prov) {
            echo json_encode(['success' => false, 'error' => 'Selected provider is invalid or inactive.']);
            exit;
        }

        $servicesToImport = json_decode($rawServicesJson, true);
        if (!is_array($servicesToImport) || empty($servicesToImport)) {
            echo json_encode(['success' => false, 'error' => 'No valid services were provided for import.']);
            exit;
        }

        // Ensure default category is valid
        $catCheck = $db->prepare("SELECT id FROM categories WHERE id = :id LIMIT 1");
        $catCheck->execute(['id' => $defaultCategoryId]);
        if (!$catCheck->fetch()) {
            $firstCat = (int)$db->query("SELECT id FROM categories ORDER BY sort_order ASC LIMIT 1")->fetchColumn();
            $defaultCategoryId = $firstCat > 0 ? $firstCat : 1;
        }

        $categoriesCreated = 0;
        $categoriesMatched = 0;
        $servicesImported = 0;
        $servicesUpdated = 0;
        $duplicatesSkipped = 0;
        $errorsCount = 0;

        $db->beginTransaction();
        try {
            // Load existing categories indexed by lowercase name
            $allCatsStmt = $db->query("SELECT id, name, platform FROM categories");
            $existingCategoriesMap = [];
            while ($c = $allCatsStmt->fetch()) {
                $existingCategoriesMap[strtolower(trim($c['name']))] = (int)$c['id'];
            }

            // Category Resolution Map for this import
            $categoryResolutionMap = [];

            // Step A: Handle Category Import if mode is 'both' or 'categories_only'
            if ($importMode === 'both' || $importMode === 'categories_only') {
                $catInsertStmt = $db->prepare("
                    INSERT INTO categories (name, platform, sort_order, status)
                    VALUES (:name, :platform, :sort, 'active')
                ");

                foreach ($servicesToImport as $svc) {
                    $rawCatName = trim((string)($svc['category_name'] ?? 'Other'));
                    if ($rawCatName === '') $rawCatName = 'Other';
                    $lowerCatName = strtolower($rawCatName);

                    if (isset($categoryResolutionMap[$lowerCatName])) {
                        continue;
                    }

                    if (isset($existingCategoriesMap[$lowerCatName])) {
                        // Category already exists
                        $categoryResolutionMap[$lowerCatName] = $existingCategoriesMap[$lowerCatName];
                        $categoriesMatched++;
                    } else {
                        // Create missing category
                        $pName = strtolower($rawCatName);
                        $platform = 'other';
                        if (str_contains($pName, 'instagram')) $platform = 'instagram';
                        elseif (str_contains($pName, 'youtube')) $platform = 'youtube';
                        elseif (str_contains($pName, 'telegram')) $platform = 'telegram';
                        elseif (str_contains($pName, 'facebook')) $platform = 'facebook';
                        elseif (str_contains($pName, 'tiktok')) $platform = 'tiktok';
                        elseif (str_contains($pName, 'twitter') || str_contains($pName, ' x ')) $platform = 'twitter';

                        $catInsertStmt->execute([
                            'name' => $rawCatName,
                            'platform' => $platform,
                            'sort' => count($existingCategoriesMap) + 1
                        ]);
                        $newCatId = (int)$db->lastInsertId();
                        $existingCategoriesMap[$lowerCatName] = $newCatId;
                        $categoryResolutionMap[$lowerCatName] = $newCatId;
                        $categoriesCreated++;
                    }
                }
            }

            // Step B: Handle Service Import if mode is 'both' or 'services_only'
            if ($importMode === 'both' || $importMode === 'services_only') {
                $dupCheckStmt = $db->prepare("SELECT id, name FROM services WHERE provider_id = :pid AND provider_service_id = :psid LIMIT 1");
                $insertStmt = $db->prepare("
                    INSERT INTO services (
                        category_id, provider_id, provider_service_id, name, 
                        rate_per_1000, original_rate, min_quantity, max_quantity, 
                        service_type, speed, description, status
                    ) VALUES (
                        :cid, :pid, :psid, :name, 
                        :rate, :orig, :min, :max, 
                        :stype, :speed, :desc, 'active'
                    )
                ");

                foreach ($servicesToImport as $svc) {
                    $psid = trim((string)($svc['provider_service_id'] ?? ''));
                    $name = trim((string)($svc['name'] ?? ''));
                    $originalRate = (float)($svc['original_rate'] ?? 0.0);
                    $minQty = max(1, (int)($svc['min_quantity'] ?? 10));
                    $maxQty = max($minQty, (int)($svc['max_quantity'] ?? 100000));
                    $serviceType = in_array($svc['service_type'] ?? '', ['custom_comments', 'package', 'poll']) ? $svc['service_type'] : 'default';
                    $speed = trim((string)($svc['speed'] ?? 'Fast Delivery'));
                    $desc = !empty($svc['description']) ? trim((string)$svc['description']) : null;

                    if ($psid === '' || $name === '') {
                        $errorsCount++;
                        continue;
                    }

                    // Resolve category ID
                    $rawCatName = trim((string)($svc['category_name'] ?? ''));
                    $lowerCatName = strtolower($rawCatName);
                    $catId = $defaultCategoryId;

                    if (isset($categoryResolutionMap[$lowerCatName])) {
                        $catId = $categoryResolutionMap[$lowerCatName];
                    } elseif (isset($existingCategoriesMap[$lowerCatName])) {
                        $catId = $existingCategoriesMap[$lowerCatName];
                    } elseif (!empty($svc['category_id'])) {
                        $catId = (int)$svc['category_id'];
                    }

                    // 1. Duplicate check: provider_id + provider_service_id
                    $dupCheckStmt->execute(['pid' => $providerId, 'psid' => $psid]);
                    if ($dupCheckStmt->fetch()) {
                        $duplicatesSkipped++;
                        continue;
                    }

                    // 2. Price markup calculation
                    $sellingRate = calculate_service_markup($originalRate, $markupPercent, $markupFixed);
                    if ($sellingRate <= 0.0001) {
                        $sellingRate = $originalRate > 0 ? $originalRate : 1.0;
                    }

                    // 3. Insert into database
                    $insertStmt->execute([
                        'cid'   => $catId,
                        'pid'   => $providerId,
                        'psid'  => $psid,
                        'name'  => $name,
                        'rate'  => $sellingRate,
                        'orig'  => $originalRate,
                        'min'   => $minQty,
                        'max'   => $maxQty,
                        'stype' => $serviceType,
                        'speed' => $speed,
                        'desc'  => $desc
                    ]);

                    $servicesImported++;
                }
            }

            $db->commit();

            echo json_encode([
                'success' => true,
                'import_mode' => $importMode,
                'categories_created' => $categoriesCreated,
                'categories_matched' => $categoriesMatched,
                'services_imported' => $servicesImported,
                'services_updated' => $servicesUpdated,
                'duplicates_skipped' => $duplicatesSkipped,
                'errors' => $errorsCount,
                'message' => "Import complete: {$categoriesCreated} categories created, {$servicesImported} services imported, {$duplicatesSkipped} duplicates skipped."
            ]);
            exit;

        } catch (Throwable $e) {
            $db->rollBack();
            echo json_encode([
                'success' => false,
                'error' => 'Database transaction failed: ' . $e->getMessage()
            ]);
            exit;
        }
    }
}

// Fetch saved active providers
$providers = $db->query("SELECT id, name, api_url, currency, balance, status FROM providers WHERE status = 'active' ORDER BY id DESC")->fetchAll();

// Fetch existing categories for mapping
$categories = $db->query("SELECT id, name, platform FROM categories WHERE status = 'active' ORDER BY sort_order ASC")->fetchAll();

$preselectedProviderId = (int)($_GET['provider_id'] ?? ($providers[0]['id'] ?? 0));
$currentProvider = null;
foreach ($providers as $p) {
    if ((int)$p['id'] === $preselectedProviderId) {
        $currentProvider = $p;
        break;
    }
}

$csrfToken = CSRF::getToken();
$pageTitle = "Import Services from Provider - " . app_name() . " Admin";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto my-4 space-y-6" x-data="serviceImporter()">
    <!-- Breadcrumbs & Navigation -->
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2 text-xs font-semibold text-slate-500">
            <a href="/admin/services.php" class="hover:text-blue-600 transition-colors">Services</a>
            <span>/</span>
            <span class="text-slate-800 font-bold">Import from Provider API</span>
        </div>
        <div class="flex items-center gap-3">
            <a href="/admin/providers.php" class="text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
                Manage Providers
            </a>
            <span>•</span>
            <a href="/admin/services.php" class="text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
                View All Services Catalog &rarr;
            </a>
        </div>
    </div>

    <!-- Header Banner -->
    <div class="bg-gradient-to-r from-slate-900 via-indigo-950 to-blue-900 text-white rounded-3xl p-6 sm:p-8 shadow-xl flex flex-wrap items-center justify-between gap-4">
        <div>
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-500/20 border border-blue-400/30 text-blue-300 text-[11px] font-bold uppercase tracking-wider mb-2">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                Active Production Import Engine
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight">Import Services from Provider API</h1>
            <p class="text-xs sm:text-sm text-slate-300 mt-1 max-w-2xl leading-relaxed">
                Fetch real service catalogs directly from your connected SMM providers, preview live rates, apply profit markups, and bulk-import directly into your database with automated duplicate prevention.
            </p>
        </div>
        <div class="text-right">
            <span class="text-xs text-slate-400 block font-semibold">Security Standard</span>
            <span class="text-sm font-extrabold text-emerald-400">Credentials Kept Server-Side</span>
        </div>
    </div>

    <!-- Step 1: Provider Selection & Pricing / Markup Settings -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl space-y-6">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div class="flex items-center gap-3">
                <span class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center font-black text-sm">1</span>
                <div>
                    <h2 class="text-base font-extrabold text-slate-900">Select Provider &amp; Configure Profit Markup</h2>
                    <p class="text-xs text-slate-400">Reuses existing saved provider credentials automatically</p>
                </div>
            </div>
            <span class="px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 text-[10px] font-bold uppercase border border-emerald-200">
                Connected
            </span>
        </div>

        <?php if (empty($providers)): ?>
            <div class="p-8 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 text-xs text-center space-y-3">
                <p class="font-bold text-sm">No external SMM providers configured yet.</p>
                <p class="text-amber-700 max-w-md mx-auto">To import services, connect at least one external SMM provider with its API URL and API Key in the Providers section.</p>
                <a href="/admin/providers.php" class="inline-block px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-xs shadow-md transition-all">
                    + Add Provider in Providers Manager
                </a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <!-- Select Existing Saved Provider -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Target Provider</label>
                    <select x-model="selectedProviderId" @change="onProviderSelect()" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                        <?php foreach ($providers as $prov): ?>
                            <option value="<?= (int)$prov['id'] ?>" <?= ((int)$prov['id'] === $preselectedProviderId) ? 'selected' : '' ?>>
                                <?= e($prov['name']) ?> (<?= e($prov['currency']) ?> <?= number_format((float)$prov['balance'], 2) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Markup Percentage -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Profit Markup (%)</label>
                    <div class="relative">
                        <input type="number" x-model.number="markupPercent" min="0" max="1000" step="1" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                        <span class="absolute right-4 top-2.5 text-xs text-slate-400 font-bold">%</span>
                    </div>
                </div>

                <!-- Fixed Markup -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Fixed Addition / 1K (<?= e(app_currency()) ?>)</label>
                    <input type="number" x-model.number="markupFixed" min="0" step="0.5" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                </div>

                <!-- Default Category -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Default Fallback Category</label>
                    <select x-model="defaultCategoryId" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>"><?= e($cat['name']) ?> (<?= ucfirst($cat['platform']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Import Operation Mode (Requirement 7) -->
            <div class="pt-4 border-t border-slate-100 space-y-2">
                <label class="block text-xs font-extrabold text-slate-700 uppercase tracking-wider">Select Import Operation</label>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <label class="p-3.5 rounded-2xl border cursor-pointer transition-all flex items-start gap-3"
                           :class="importMode === 'both' ? 'bg-blue-50/70 border-blue-500 ring-2 ring-blue-500/10 shadow-xs' : 'bg-slate-50 border-slate-200 hover:bg-slate-100'">
                        <input type="radio" name="op_mode" value="both" x-model="importMode" class="mt-0.5 text-blue-600 focus:ring-blue-500">
                        <div>
                            <strong class="text-xs font-bold text-slate-900 block">🌟 Import Categories &amp; Services</strong>
                            <span class="text-[11px] text-slate-500 block leading-tight mt-0.5">Creates missing categories and maps services with profit markup. (Recommended)</span>
                        </div>
                    </label>
                    <label class="p-3.5 rounded-2xl border cursor-pointer transition-all flex items-start gap-3"
                           :class="importMode === 'categories_only' ? 'bg-purple-50/70 border-purple-500 ring-2 ring-purple-500/10 shadow-xs' : 'bg-slate-50 border-slate-200 hover:bg-slate-100'">
                        <input type="radio" name="op_mode" value="categories_only" x-model="importMode" class="mt-0.5 text-purple-600 focus:ring-purple-500">
                        <div>
                            <strong class="text-xs font-bold text-slate-900 block">📁 Import Categories Only</strong>
                            <span class="text-[11px] text-slate-500 block leading-tight mt-0.5">Extracts and creates missing categories from provider without altering services.</span>
                        </div>
                    </label>
                    <label class="p-3.5 rounded-2xl border cursor-pointer transition-all flex items-start gap-3"
                           :class="importMode === 'services_only' ? 'bg-emerald-50/70 border-emerald-500 ring-2 ring-emerald-500/10 shadow-xs' : 'bg-slate-50 border-slate-200 hover:bg-slate-100'">
                        <input type="radio" name="op_mode" value="services_only" x-model="importMode" class="mt-0.5 text-emerald-600 focus:ring-emerald-500">
                        <div>
                            <strong class="text-xs font-bold text-slate-900 block">⚡ Import Services Only</strong>
                            <span class="text-[11px] text-slate-500 block leading-tight mt-0.5">Imports services mapped strictly to existing local category structure.</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Fetch Action Bar -->
            <div class="pt-4 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="text-xs text-slate-500">
                    Formula applied on import: <code class="font-bold text-blue-700 bg-blue-50 px-2 py-0.5 rounded font-mono">selling_rate = original_rate + (original_rate * markup%) + fixed</code>
                </div>
                <button type="button" 
                        @click="fetchServices()" 
                        :disabled="loading || importing"
                        class="px-6 py-3 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-400 text-white rounded-xl text-xs font-extrabold shadow-lg shadow-blue-500/25 transition-all flex items-center justify-center gap-2">
                    <template x-if="loading">
                        <svg class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                    </template>
                    <span x-text="loading ? 'Connecting to Provider API...' : '⚡ Fetch Services from Provider API'"></span>
                </button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Step 2: Live Fetched Services & Bulk Selection -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl space-y-6" x-show="services.length > 0" style="display: none;">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-slate-100">
            <div>
                <div class="flex items-center gap-3">
                    <span class="w-8 h-8 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center font-black text-sm">2</span>
                    <div>
                        <h2 class="text-base font-extrabold text-slate-900">Review &amp; Select Services to Import</h2>
                        <p class="text-xs text-slate-500">
                            Loaded <strong class="text-slate-900" x-text="services.length"></strong> real services from <strong class="text-blue-700" x-text="providerName"></strong>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Action: Bulk Import Button -->
            <div class="flex items-center gap-3">
                <span class="text-xs font-bold text-slate-600">
                    <span class="text-blue-600 font-extrabold text-sm" x-text="selectedIds.length"></span> selected
                </span>
                <button type="button" 
                        @click="importSelected()"
                        :disabled="selectedIds.length === 0 || importing"
                        class="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white rounded-xl text-xs font-extrabold shadow-md shadow-emerald-500/20 transition-all flex items-center gap-2">
                    <template x-if="importing">
                        <svg class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                    </template>
                    <span x-text="importing ? 'Importing into Database...' : '📥 Import Selected (' + selectedIds.length + ')'"></span>
                </button>
            </div>
        </div>

        <!-- Filter Controls -->
        <div class="flex flex-wrap items-center justify-between gap-4 bg-slate-50 p-4 rounded-2xl border border-slate-200/70">
            <!-- Search -->
            <div class="w-full sm:w-64">
                <input type="text" x-model="searchQuery" placeholder="Search service name or ID..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
            </div>

            <!-- Category Filter -->
            <div class="flex items-center gap-2">
                <label class="text-xs font-bold text-slate-600">Category:</label>
                <select x-model="categoryFilter" class="px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-semibold">
                    <option value="all">All Categories</option>
                    <template x-for="cat in uniqueCategories" :key="cat">
                        <option :value="cat" x-text="cat"></option>
                    </template>
                </select>
            </div>

            <!-- Import Status Filter -->
            <div class="flex items-center gap-2">
                <label class="text-xs font-bold text-slate-600">Status:</label>
                <select x-model="statusFilter" class="px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-semibold">
                    <option value="all">All Services</option>
                    <option value="new">Only New Services</option>
                    <option value="imported">Already Imported</option>
                </select>
            </div>

            <!-- Quick Select Buttons -->
            <div class="flex items-center gap-2">
                <button type="button" @click="selectAllFiltered()" class="px-3 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-100">
                    Select All Filtered
                </button>
                <button type="button" @click="selectOnlyNew()" class="px-3 py-1.5 bg-blue-50 border border-blue-200 rounded-xl text-xs font-bold text-blue-700 hover:bg-blue-100">
                    Select Only New
                </button>
                <button type="button" @click="deselectAll()" class="px-3 py-1.5 text-xs font-bold text-slate-500 hover:text-slate-800">
                    Deselect All
                </button>
            </div>
        </div>

        <!-- Services Preview Table -->
        <div class="overflow-x-auto border border-slate-200 rounded-2xl max-h-[600px] overflow-y-auto">
            <table class="w-full text-left text-xs">
                <thead class="sticky top-0 bg-slate-100 z-10">
                    <tr class="border-b border-slate-200 text-slate-500 uppercase tracking-wider font-bold">
                        <th class="py-3 px-3 w-10 text-center">
                            <input type="checkbox" @change="toggleSelectAll($event.target.checked)" :checked="isAllFilteredSelected" class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer">
                        </th>
                        <th class="py-3 px-3">ID</th>
                        <th class="py-3 px-3 min-w-[250px]">Service Name</th>
                        <th class="py-3 px-3">Provider Cat</th>
                        <th class="py-3 px-3">Orig Rate</th>
                        <th class="py-3 px-3">Panel Selling Rate</th>
                        <th class="py-3 px-3 min-w-[150px]">Target Category</th>
                        <th class="py-3 px-3">Min / Max</th>
                        <th class="py-3 px-3 text-right">Duplicate Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <template x-for="item in paginatedServices" :key="item.provider_service_id">
                        <tr class="hover:bg-slate-50 transition-colors" :class="item.already_imported ? 'bg-slate-50/50' : (selectedIds.includes(item.provider_service_id) ? 'bg-blue-50/40' : '')">
                            <td class="py-3 px-3 text-center">
                                <input type="checkbox" 
                                       :value="item.provider_service_id" 
                                       :checked="selectedIds.includes(item.provider_service_id)"
                                       @change="toggleSelectService(item.provider_service_id)"
                                       class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer">
                            </td>
                            <td class="py-3 px-3 font-mono text-slate-400 font-bold" x-text="'#' + item.provider_service_id"></td>
                            <td class="py-3 px-3 font-bold text-slate-900" x-text="item.name"></td>
                            <td class="py-3 px-3 text-slate-500" x-text="item.category_name"></td>
                            <td class="py-3 px-3 font-mono tabular-nums text-slate-500" x-text="providerCurrency + ' ' + Number(item.original_rate).toFixed(2)"></td>
                            <td class="py-3 px-3 font-extrabold text-blue-600 tabular-nums font-mono" x-text="'<?= e(app_currency()) ?> ' + calculateRate(item.original_rate)"></td>
                            <td class="py-3 px-3">
                                <select x-model="item.matched_category_id" class="px-2 py-1 bg-white border border-slate-200 rounded-lg text-xs w-full">
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= (int)$cat['id'] ?>"><?= e($cat['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="py-3 px-3 text-slate-500 tabular-nums" x-text="Number(item.min_quantity).toLocaleString() + ' - ' + Number(item.max_quantity).toLocaleString()"></td>
                            <td class="py-3 px-3 text-right">
                                <template x-if="item.already_imported">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-amber-50 text-amber-700 border border-amber-200">
                                        Already Imported
                                    </span>
                                </template>
                                <template x-if="!item.already_imported">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        New Service
                                    </span>
                                </template>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Pagination Bar -->
        <div class="flex items-center justify-between text-xs text-slate-500 pt-2">
            <div>
                Showing <span class="font-bold text-slate-800" x-text="((currentPage - 1) * perPage) + 1"></span> - 
                <span class="font-bold text-slate-800" x-text="Math.min(currentPage * perPage, filteredServices.length)"></span> 
                of <span class="font-bold text-slate-800" x-text="filteredServices.length"></span> filtered services
            </div>
            <div class="flex items-center gap-2">
                <button type="button" @click="currentPage = Math.max(1, currentPage - 1)" :disabled="currentPage === 1" class="px-3 py-1.5 rounded-xl border border-slate-200 bg-white disabled:opacity-40">Previous</button>
                <span class="font-bold text-slate-800" x-text="'Page ' + currentPage + ' of ' + totalPages"></span>
                <button type="button" @click="currentPage = Math.min(totalPages, currentPage + 1)" :disabled="currentPage >= totalPages" class="px-3 py-1.5 rounded-xl border border-slate-200 bg-white disabled:opacity-40">Next</button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?= e($csrfToken) ?>';

function serviceImporter() {
    return {
        selectedProviderId: '<?= (int)$preselectedProviderId ?>',
        providerName: '',
        providerCurrency: 'USD',
        markupPercent: 25,
        markupFixed: 0,
        defaultCategoryId: '<?= (int)($categories[0]['id'] ?? 1) ?>',
        loading: false,
        importing: false,
        services: [],
        selectedIds: [],
        searchQuery: '',
        categoryFilter: 'all',
        statusFilter: 'all',
        currentPage: 1,
        perPage: 50,

        onProviderSelect() {
            // Clear current list when changing provider
            this.services = [];
            this.selectedIds = [];
        },

        calculateRate(originalRate) {
            const orig = parseFloat(originalRate) || 0;
            const pct = parseFloat(this.markupPercent) || 0;
            const fixed = parseFloat(this.markupFixed) || 0;
            const res = orig + (orig * (pct / 100)) + fixed;
            return res.toFixed(2);
        },

        get uniqueCategories() {
            const set = new Set();
            this.services.forEach(s => {
                if (s.category_name) set.add(s.category_name);
            });
            return Array.from(set).sort();
        },

        get filteredServices() {
            return this.services.filter(s => {
                if (this.statusFilter === 'new' && s.already_imported) return false;
                if (this.statusFilter === 'imported' && !s.already_imported) return false;
                if (this.categoryFilter !== 'all' && s.category_name !== this.categoryFilter) return false;
                if (this.searchQuery.trim() !== '') {
                    const q = this.searchQuery.toLowerCase();
                    const matchName = s.name.toLowerCase().includes(q);
                    const matchId = String(s.provider_service_id).includes(q);
                    if (!matchName && !matchId) return false;
                }
                return true;
            });
        },

        get totalPages() {
            return Math.max(1, Math.ceil(this.filteredServices.length / this.perPage));
        },

        get paginatedServices() {
            const start = (this.currentPage - 1) * this.perPage;
            return this.filteredServices.slice(start, start + this.perPage);
        },

        get isAllFilteredSelected() {
            const filtered = this.filteredServices;
            if (filtered.length === 0) return false;
            return filtered.every(s => this.selectedIds.includes(s.provider_service_id));
        },

        toggleSelectService(psid) {
            const idx = this.selectedIds.indexOf(psid);
            if (idx > -1) {
                this.selectedIds.splice(idx, 1);
            } else {
                this.selectedIds.push(psid);
            }
        },

        toggleSelectAll(checked) {
            if (checked) {
                this.selectAllFiltered();
            } else {
                this.deselectAll();
            }
        },

        selectAllFiltered() {
            const idsToAdd = this.filteredServices.map(s => s.provider_service_id);
            this.selectedIds = Array.from(new Set([...this.selectedIds, ...idsToAdd]));
        },

        selectOnlyNew() {
            const newIds = this.filteredServices
                .filter(s => !s.already_imported)
                .map(s => s.provider_service_id);
            this.selectedIds = newIds;
        },

        deselectAll() {
            this.selectedIds = [];
        },

        async fetchServices() {
            if (!this.selectedProviderId) {
                Swal.fire({ icon: 'warning', title: 'Provider Required', text: 'Please select a provider first.' });
                return;
            }

            this.loading = true;
            this.services = [];
            this.selectedIds = [];
            this.currentPage = 1;

            try {
                const formData = new FormData();
                formData.append('action', 'fetch_services');
                formData.append('provider_id', this.selectedProviderId);
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('ajax', '1');

                const res = await fetch('/admin/import-services.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const data = await res.json();

                if (data.success) {
                    this.services = data.services || [];
                    this.providerName = data.provider_name || 'Selected Provider';
                    this.providerCurrency = data.provider_currency || 'USD';

                    if (this.services.length === 0) {
                        Swal.fire({
                            icon: 'info',
                            title: 'No Services Returned',
                            text: 'The provider API returned an empty list of services.'
                        });
                    } else {
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'success',
                            title: `Loaded ${data.total_count} services from ${data.provider_name}`,
                            showConfirmButton: false,
                            timer: 2500
                        });
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed to Fetch Services',
                        text: data.error || 'Provider communication failed.'
                    });
                }
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Connection Error',
                    text: 'Could not connect to backend server: ' + err.message
                });
            } finally {
                this.loading = false;
            }
        },

        async importSelected() {
            if (this.selectedIds.length === 0) {
                Swal.fire({ icon: 'info', title: 'No Services Selected', text: 'Please check one or more services to import.' });
                return;
            }

            // Confirm with user
            const confirmed = await Swal.fire({
                title: `Import ${this.selectedIds.length} Service(s)?`,
                text: `Selected services will be imported into your services database with a ${this.markupPercent}% profit markup. Duplicate entries will be automatically skipped.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, Import Now',
                cancelButtonText: 'Cancel'
            });

            if (!confirmed.isConfirmed) return;

            this.importing = true;

            try {
                // Prepare selected service payload
                const selectedMap = new Set(this.selectedIds);
                const payloadServices = this.services
                    .filter(s => selectedMap.has(s.provider_service_id))
                    .map(s => ({
                        provider_service_id: s.provider_service_id,
                        name: s.name,
                        original_rate: s.original_rate,
                        category_id: s.matched_category_id,
                        min_quantity: s.min_quantity,
                        max_quantity: s.max_quantity,
                        service_type: s.service_type,
                        speed: s.speed,
                        description: s.description
                    }));

                const formData = new FormData();
                formData.append('action', 'import_services');
                formData.append('provider_id', this.selectedProviderId);
                formData.append('markup_percent', this.markupPercent);
                formData.append('markup_fixed', this.markupFixed);
                formData.append('default_category_id', this.defaultCategoryId);
                formData.append('services_data', JSON.stringify(payloadServices));
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('ajax', '1');

                const res = await fetch('/admin/import-services.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const data = await res.json();

                if (data.success) {
                    // Update already_imported flag locally
                    selectedMap.forEach(psid => {
                        const item = this.services.find(s => s.provider_service_id === psid);
                        if (item) item.already_imported = true;
                    });
                    this.selectedIds = [];

                    Swal.fire({
                        icon: 'success',
                        title: 'Import Completed',
                        html: `
                            <div class="text-left text-xs space-y-1.5 p-3 bg-slate-50 rounded-xl border border-slate-200 mt-2">
                                <div>✓ <strong>Successfully Imported:</strong> <span class="text-emerald-600 font-bold">${data.imported}</span></div>
                                <div>• <strong>Skipped (Duplicates):</strong> <span class="text-amber-600 font-bold">${data.skipped}</span></div>
                                <div>✕ <strong>Failed:</strong> <span class="text-rose-600 font-bold">${data.failed}</span></div>
                            </div>
                        `,
                        confirmButtonText: 'Great!'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Import Failed',
                        text: data.error || 'Failed to complete database insertion.'
                    });
                }
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Import Request Error',
                    text: 'An error occurred while importing: ' + err.message
                });
            } finally {
                this.importing = false;
            }
        }
    };
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
