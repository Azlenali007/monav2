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

    // =========================================================================
    // ACTION 1: Fetch Services & Categories from Provider API
    // =========================================================================
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

        // Fetch existing categories for intelligent matching and duplicate checks
        $catsStmt = $db->query("SELECT id, name, platform, sort_order, status FROM categories WHERE status = 'active' ORDER BY sort_order ASC, id ASC");
        $allCategories = $catsStmt->fetchAll() ?: [];

        $existingCatsByName = [];
        foreach ($allCategories as $c) {
            $existingCatsByName[strtolower(trim($c['name']))] = [
                'id' => (int)$c['id'],
                'name' => (string)$c['name'],
                'platform' => (string)$c['platform']
            ];
        }

        $providerCurrency = !empty($provider['currency']) ? strtoupper(trim($provider['currency'])) : 'USD';
        $baseCurrency = app_base_currency_code(); // Typically 'INR'
        $conversionRateToBase = convert_currency(1.0, $providerCurrency, $baseCurrency);

        // 4. Normalize returned services safely & group provider categories
        $normalizedList = [];
        $categoryCounts = [];
        $suspiciousRatesCount = 0;

        foreach ($response as $item) {
            if (!is_array($item)) continue;

            $normalized = normalize_provider_service($item);
            $psid = (string)$normalized['provider_service_id'];

            if ($psid === '') continue;

            $rawCatName = trim($normalized['category_name'] ?: 'Other');
            $normalized['category_name'] = $rawCatName;
            $lowerCat = strtolower($rawCatName);

            // Flag duplicate
            $normalized['already_imported'] = isset($existingSet[$psid]);

            // Flag suspicious or implausible provider rates (e.g. 10000000.00 or negative)
            $origRate = (float)$normalized['original_rate'];
            $isSuspicious = ($origRate <= 0 || $origRate > 50000 || !is_finite($origRate));
            $normalized['is_suspicious_rate'] = $isSuspicious;
            if ($isSuspicious) {
                $suspiciousRatesCount++;
            }

            // Category Matching & Presence Check
            $alreadyExists = isset($existingCatsByName[$lowerCat]);
            $localCatId = $alreadyExists ? (int)$existingCatsByName[$lowerCat]['id'] : null;

            if ($alreadyExists) {
                $normalized['matched_category_id'] = $localCatId;
            } else {
                $matchedId = find_matching_category_id($rawCatName, 0);
                $normalized['matched_category_id'] = $matchedId > 0 ? $matchedId : 'auto_create';
            }

            // Derive platform from category name
            $platform = 'other';
            if (str_contains($lowerCat, 'instagram')) $platform = 'instagram';
            elseif (str_contains($lowerCat, 'youtube')) $platform = 'youtube';
            elseif (str_contains($lowerCat, 'telegram')) $platform = 'telegram';
            elseif (str_contains($lowerCat, 'facebook')) $platform = 'facebook';
            elseif (str_contains($lowerCat, 'tiktok')) $platform = 'tiktok';
            elseif (str_contains($lowerCat, 'twitter') || str_contains($lowerCat, ' x ')) $platform = 'twitter';

            // Tally categories
            if (!isset($categoryCounts[$rawCatName])) {
                $categoryCounts[$rawCatName] = [
                    'name' => $rawCatName,
                    'platform' => $platform,
                    'count' => 0,
                    'already_imported' => $alreadyExists,
                    'local_category_id' => $localCatId,
                    'matched_category_id' => $normalized['matched_category_id']
                ];
            }
            $categoryCounts[$rawCatName]['count']++;

            $normalizedList[] = $normalized;
        }

        echo json_encode([
            'success' => true,
            'provider_name' => $provider['name'],
            'provider_currency' => $providerCurrency,
            'base_currency' => $baseCurrency,
            'base_currency_symbol' => app_base_currency(),
            'conversion_rate_to_base' => $conversionRateToBase,
            'services' => $normalizedList,
            'categories' => array_values($categoryCounts),
            'existing_categories' => array_map(fn($c) => [
                'id' => (int)$c['id'],
                'name' => (string)$c['name'],
                'platform' => (string)$c['platform']
            ], $allCategories),
            'total_count' => count($normalizedList),
            'already_imported_count' => count(array_filter($normalizedList, fn($s) => $s['already_imported'])),
            'suspicious_count' => $suspiciousRatesCount
        ]);
        exit;
    }

    // =========================================================================
    // ACTION 2: Import Selected Categories Separately
    // =========================================================================
    if ($action === 'import_categories') {
        header('Content-Type: application/json; charset=UTF-8');
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $rawCatsJson = $_POST['categories_data'] ?? '';

        if ($providerId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Please select a valid provider.']);
            exit;
        }

        $categoriesToImport = json_decode($rawCatsJson, true);
        if (!is_array($categoriesToImport) || empty($categoriesToImport)) {
            echo json_encode(['success' => false, 'error' => 'No categories were selected for import.']);
            exit;
        }

        $createdCount = 0;
        $skippedDuplicates = 0;
        $failedCount = 0;
        $errorDetails = [];

        $db->beginTransaction();
        try {
            // Load existing categories to accurately prevent duplicates
            $stmt = $db->query("SELECT id, name, platform, sort_order FROM categories");
            $existingByName = [];
            $maxSort = 0;
            while ($row = $stmt->fetch()) {
                $existingByName[strtolower(trim((string)$row['name']))] = (int)$row['id'];
                if ((int)$row['sort_order'] > $maxSort) {
                    $maxSort = (int)$row['sort_order'];
                }
            }

            $insStmt = $db->prepare("
                INSERT INTO categories (name, platform, sort_order, status)
                VALUES (:name, :platform, :sort, 'active')
            ");

            $allowedPlatforms = ['instagram', 'youtube', 'telegram', 'facebook', 'tiktok', 'twitter', 'other'];

            foreach ($categoriesToImport as $catItem) {
                $name = trim((string)($catItem['name'] ?? ''));
                if ($name === '') {
                    $failedCount++;
                    $errorDetails[] = "Category name cannot be empty.";
                    continue;
                }

                $lower = strtolower($name);

                // Prevent duplicate categories when importing the same categories multiple times
                if (isset($existingByName[$lower])) {
                    $skippedDuplicates++;
                    continue;
                }

                // Platform determination
                $platform = strtolower(trim((string)($catItem['platform'] ?? 'other')));
                if (!in_array($platform, $allowedPlatforms, true)) {
                    if (str_contains($lower, 'instagram')) $platform = 'instagram';
                    elseif (str_contains($lower, 'youtube')) $platform = 'youtube';
                    elseif (str_contains($lower, 'telegram')) $platform = 'telegram';
                    elseif (str_contains($lower, 'facebook')) $platform = 'facebook';
                    elseif (str_contains($lower, 'tiktok')) $platform = 'tiktok';
                    elseif (str_contains($lower, 'twitter') || str_contains($lower, ' x ')) $platform = 'twitter';
                    else $platform = 'other';
                }

                $maxSort++;
                $insStmt->execute([
                    'name' => $name,
                    'platform' => $platform,
                    'sort' => $maxSort
                ]);
                $newId = (int)$db->lastInsertId();
                $existingByName[$lower] = $newId;
                $createdCount++;
            }

            $db->commit();

            // Refetch active categories to return to client
            $refreshedStmt = $db->query("SELECT id, name, platform FROM categories WHERE status = 'active' ORDER BY sort_order ASC, id ASC");
            $updatedCategories = array_map(function($c) {
                return [
                    'id' => (int)$c['id'],
                    'name' => (string)$c['name'],
                    'platform' => (string)$c['platform']
                ];
            }, $refreshedStmt->fetchAll() ?: []);

            $msg = sprintf(
                "Categories import complete: %d created, %d skipped (already exist in database).",
                $createdCount,
                $skippedDuplicates
            );

            echo json_encode([
                'success' => true,
                'message' => $msg,
                'categories_created' => (int)$createdCount,
                'skipped_duplicates' => (int)$skippedDuplicates,
                'skipped' => (int)$skippedDuplicates,
                'failed' => (int)$failedCount,
                'errors' => $errorDetails,
                'updated_categories' => $updatedCategories
            ]);
            exit;

        } catch (\Throwable $e) {
            $db->rollBack();
            echo json_encode([
                'success' => false,
                'error' => 'Database transaction failed: ' . $e->getMessage(),
                'categories_created' => 0,
                'skipped_duplicates' => 0,
                'skipped' => 0,
                'failed' => count($categoriesToImport),
                'errors' => [$e->getMessage()]
            ]);
            exit;
        }
    }

    // =========================================================================
    // ACTION 3: Execute Import (Categories & Services with Foreign Key Integrity)
    // =========================================================================
    if ($action === 'import_services') {
        header('Content-Type: application/json; charset=UTF-8');
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $importMode = trim($_POST['import_mode'] ?? 'both'); // 'both', 'categories_only', 'services_only'
        $markupPercent = (float)($_POST['markup_percent'] ?? 0.0);
        $markupFixed = (float)($_POST['markup_fixed'] ?? 0.0);
        $defaultCategoryId = (int)($_POST['default_category_id'] ?? 1);
        $autoCreateMissing = isset($_POST['auto_create_missing']) && $_POST['auto_create_missing'] === '1';
        $rawServicesJson = $_POST['services_data'] ?? '';

        if ($providerId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid provider ID specified.']);
            exit;
        }

        // Verify provider exists
        $stmtProv = $db->prepare("SELECT id, name, currency FROM providers WHERE id = :id AND status = 'active' LIMIT 1");
        $stmtProv->execute(['id' => $providerId]);
        $prov = $stmtProv->fetch();

        if (!$prov) {
            echo json_encode(['success' => false, 'error' => 'Selected provider is invalid or inactive.']);
            exit;
        }

        $providerCurrency = !empty($prov['currency']) ? strtoupper(trim($prov['currency'])) : 'USD';
        $baseCurrency = app_base_currency_code(); // Base is 'INR'

        $servicesToImport = json_decode($rawServicesJson, true);
        if (!is_array($servicesToImport) || empty($servicesToImport)) {
            echo json_encode(['success' => false, 'error' => 'No valid services were provided for import.']);
            exit;
        }

        // Ensure default category is valid in database
        $catCheck = $db->prepare("SELECT id FROM categories WHERE id = :id LIMIT 1");
        $catCheck->execute(['id' => $defaultCategoryId]);
        if (!$catCheck->fetch()) {
            $firstCat = (int)$db->query("SELECT id FROM categories ORDER BY sort_order ASC LIMIT 1")->fetchColumn();
            $defaultCategoryId = $firstCat > 0 ? $firstCat : 1;
        }

        $categoriesCreated = 0;
        $categoriesMatched = 0;
        $servicesImported = 0;
        $duplicatesSkipped = 0;
        $failedCount = 0;
        $errorDetails = [];

        $db->beginTransaction();
        try {
            // 1. Load existing categories indexed by ID and by lowercase name
            $allCatsStmt = $db->query("SELECT id, name, platform, sort_order FROM categories");
            $existingCategoriesById = [];
            $existingCategoriesByName = [];
            $maxSort = 0;
            while ($c = $allCatsStmt->fetch()) {
                $cId = (int)$c['id'];
                $existingCategoriesById[$cId] = $c;
                $existingCategoriesByName[strtolower(trim($c['name']))] = $cId;
                if (isset($c['sort_order']) && (int)$c['sort_order'] > $maxSort) {
                    $maxSort = (int)$c['sort_order'];
                }
            }
            if ($maxSort === 0) {
                $maxSort = count($existingCategoriesById);
            }

            // Step A: Category Resolution / Automatic Creation
            // Collects all required provider categories, reuses existing ones, or creates missing ones
            $categoryResolutionMap = [];
            $catInsertStmt = $db->prepare("
                INSERT INTO categories (name, platform, sort_order, status)
                VALUES (:name, :platform, :sort, 'active')
            ");

            foreach ($servicesToImport as $svc) {
                $rawCatName = trim((string)($svc['category_name'] ?? ''));
                if ($rawCatName === '') {
                    // Empty category name will be reported as error in service loop
                    continue;
                }
                $lowerCatName = strtolower($rawCatName);

                // If already resolved in this batch, proceed
                if (isset($categoryResolutionMap[$lowerCatName])) {
                    continue;
                }

                // Check 1: Explicit target category provided by user selection if valid
                $targetCatId = $svc['target_category_id'] ?? 'auto_create';
                if (is_numeric($targetCatId) && (int)$targetCatId > 0 && isset($existingCategoriesById[(int)$targetCatId])) {
                    $categoryResolutionMap[$lowerCatName] = (int)$targetCatId;
                    $categoriesMatched++;
                    continue;
                }

                // Check 2: Check if category name matches an existing category in database
                if (isset($existingCategoriesByName[$lowerCatName])) {
                    $matchedId = $existingCategoriesByName[$lowerCatName];
                    $categoryResolutionMap[$lowerCatName] = $matchedId;
                    $categoriesMatched++;
                    continue;
                }

                // Check 3: Category does not exist -> Automatically create in MySQL database
                $pName = $lowerCatName;
                $platform = 'other';
                if (str_contains($pName, 'instagram')) $platform = 'instagram';
                elseif (str_contains($pName, 'youtube')) $platform = 'youtube';
                elseif (str_contains($pName, 'telegram')) $platform = 'telegram';
                elseif (str_contains($pName, 'facebook')) $platform = 'facebook';
                elseif (str_contains($pName, 'tiktok')) $platform = 'tiktok';
                elseif (str_contains($pName, 'twitter') || str_contains($pName, ' x ')) $platform = 'twitter';

                $maxSort++;
                $catInsertStmt->execute([
                    'name' => $rawCatName,
                    'platform' => $platform,
                    'sort' => $maxSort
                ]);
                $newCatId = (int)$db->lastInsertId();

                $existingCategoriesById[$newCatId] = [
                    'id' => $newCatId,
                    'name' => $rawCatName,
                    'platform' => $platform,
                    'sort_order' => $maxSort
                ];
                $existingCategoriesByName[$lowerCatName] = $newCatId;
                $categoryResolutionMap[$lowerCatName] = $newCatId;
                $categoriesCreated++;
            }

            // Step B: Service Import if mode is 'both' or 'services_only'
            if ($importMode === 'both' || $importMode === 'services_only') {
                $dupCheckStmt = $db->prepare("SELECT id FROM services WHERE provider_id = :pid AND provider_service_id = :psid LIMIT 1");
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
                        $failedCount++;
                        $errorDetails[] = "Missing service ID or name for record.";
                        continue;
                    }

                    // Reject corrupted, negative, non-finite, or absurd rates (> 1,000,000)
                    if ($originalRate <= 0.0 || !is_finite($originalRate) || $originalRate > 1000000.0) {
                        $failedCount++;
                        $errorDetails[] = "Service #{$psid} ({$name}): Invalid or suspicious rate ({$originalRate}). Skipped.";
                        continue;
                    }

                    // Resolve category ID with strict foreign key validation
                    $rawCatName = trim((string)($svc['category_name'] ?? ''));
                    if ($rawCatName === '') {
                        $failedCount++;
                        $errorDetails[] = "Service #{$psid} ({$name}): No usable category information provided. Skipped.";
                        continue;
                    }

                    $lowerCatName = strtolower($rawCatName);
                    $resolvedCatId = $categoryResolutionMap[$lowerCatName] ?? null;

                    // STRICT FK SAFETY CHECK: Ensure category exists in categories table before insert
                    if (!$resolvedCatId || !isset($existingCategoriesById[$resolvedCatId])) {
                        $failedCount++;
                        $errorDetails[] = "Service #{$psid} ({$name}): Category '{$rawCatName}' could not be resolved or created in database.";
                        continue;
                    }

                    // 1. Duplicate check: provider_id + provider_service_id
                    $dupCheckStmt->execute(['pid' => $providerId, 'psid' => $psid]);
                    if ($dupCheckStmt->fetch()) {
                        $duplicatesSkipped++;
                        continue;
                    }

                    // 2. Price calculation:
                    // Convert provider rate to panel base currency (INR) and apply markup ONCE
                    $origRateInBase = convert_currency($originalRate, $providerCurrency, $baseCurrency);
                    $sellingRate = calculate_service_markup($origRateInBase, $markupPercent, $markupFixed);

                    if ($sellingRate <= 0.0001) {
                        $sellingRate = $origRateInBase > 0 ? $origRateInBase : 1.0;
                    }

                    // 3. Insert into services table with verified category_id
                    $insertStmt->execute([
                        'cid'   => $resolvedCatId,
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

            // Refetch active categories if any were created
            $refreshedCats = array_map(fn($c) => [
                'id' => (int)$c['id'],
                'name' => (string)$c['name'],
                'platform' => (string)$c['platform']
            ], $db->query("SELECT id, name, platform FROM categories WHERE status = 'active' ORDER BY sort_order ASC, id ASC")->fetchAll() ?: []);

            // Return standardized numeric JSON response contract
            $msg = ($importMode === 'categories_only')
                ? "Categories import complete: {$categoriesCreated} created, {$categoriesMatched} matched."
                : "Import complete: {$servicesImported} services imported, {$categoriesCreated} categories created, {$duplicatesSkipped} duplicates skipped.";

            echo json_encode([
                'success' => true,
                'message' => $msg,
                'imported' => (int)$servicesImported,
                'skipped' => (int)$duplicatesSkipped,
                'skipped_duplicates' => (int)$duplicatesSkipped,
                'failed' => (int)$failedCount,
                'categories_created' => (int)$categoriesCreated,
                'categories_reused' => (int)$categoriesMatched,
                'updated_categories' => $refreshedCats,
                'errors' => $errorDetails
            ]);
            exit;

        } catch (Throwable $e) {
            $db->rollBack();
            echo json_encode([
                'success' => false,
                'error' => 'Database transaction failed: ' . $e->getMessage(),
                'imported' => 0,
                'skipped' => 0,
                'failed' => count($servicesToImport),
                'categories_created' => 0,
                'categories_reused' => 0,
                'errors' => [$e->getMessage()]
            ]);
            exit;
        }
    }
}

// Fetch saved active providers
$providers = $db->query("SELECT id, name, api_url, currency, balance, status FROM providers WHERE status = 'active' ORDER BY id DESC")->fetchAll() ?: [];

// Fetch existing categories for mapping
$categories = $db->query("SELECT id, name, platform FROM categories WHERE status = 'active' ORDER BY sort_order ASC")->fetchAll() ?: [];

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
                Fetch real service catalogs directly from your connected SMM providers, preview live rates, apply profit markups, and bulk-import directly into your database with automated duplicate prevention and category mapping.
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
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Fixed Addition / 1K (<?= e(app_base_currency_code()) ?>)</label>
                    <input type="number" x-model.number="markupFixed" min="0" step="0.5" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                </div>

                <!-- Default Category -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Fallback Category</label>
                    <select x-model="defaultCategoryId" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>"><?= e($cat['name']) ?> (<?= ucfirst($cat['platform']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Import Operation Mode -->
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
                <div class="text-xs text-slate-500 space-y-0.5">
                    <div>Currency Conversion: <span class="font-bold text-slate-700" x-text="'Provider (' + providerCurrency + ') → Panel (' + baseCurrency + ')'"></span></div>
                    <div>Formula: <code class="font-bold text-blue-700 bg-blue-50 px-2 py-0.5 rounded font-mono">selling_rate = orig_in_base + (orig_in_base * markup%) + fixed</code></div>
                </div>
                <button type="button" 
                        @click="fetchServices()" 
                        :disabled="loading || importing"
                        class="px-6 py-3 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-400 text-white rounded-xl text-xs font-extrabold shadow-lg shadow-blue-500/25 transition-all flex items-center justify-center gap-2 cursor-pointer">
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

    <!-- Step 2: Live Fetched Categories & Services Manager -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl space-y-6" x-show="services.length > 0 || providerCategories.length > 0" style="display: none;">
        <!-- Header & Tab Navigation Bar -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-slate-100">
            <div>
                <div class="flex items-center gap-3">
                    <span class="w-8 h-8 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center font-black text-sm">2</span>
                    <div>
                        <h2 class="text-base font-extrabold text-slate-900">Review &amp; Import from Provider</h2>
                        <p class="text-xs text-slate-500">
                            Loaded <strong class="text-purple-700 font-mono-nums" x-text="providerCategories.length"></strong> categories and <strong class="text-slate-900 font-mono-nums" x-text="services.length"></strong> services from <strong class="text-blue-700" x-text="providerName"></strong>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Tab Switcher -->
            <div class="flex items-center gap-2 bg-slate-100 p-1.5 rounded-2xl">
                <button type="button" 
                        @click="activeTab = 'categories'" 
                        :class="activeTab === 'categories' ? 'bg-purple-600 text-white shadow-md shadow-purple-500/20' : 'text-slate-600 hover:text-slate-900'"
                        class="px-4 py-2 rounded-xl text-xs font-black transition-all flex items-center gap-2 cursor-pointer">
                    <span>📁 Categories</span>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                          :class="activeTab === 'categories' ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-700'"
                          x-text="providerCategories.length"></span>
                    <template x-if="selectedCategoryNames.length > 0">
                        <span class="px-1.5 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-400 text-slate-900" x-text="selectedCategoryNames.length"></span>
                    </template>
                </button>
                <button type="button" 
                        @click="activeTab = 'services'" 
                        :class="activeTab === 'services' ? 'bg-blue-600 text-white shadow-md shadow-blue-500/20' : 'text-slate-600 hover:text-slate-900'"
                        class="px-4 py-2 rounded-xl text-xs font-black transition-all flex items-center gap-2 cursor-pointer">
                    <span>⚡ Services</span>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                          :class="activeTab === 'services' ? 'bg-white/20 text-white' : 'bg-slate-200 text-slate-700'"
                          x-text="services.length"></span>
                    <template x-if="selectedIds.length > 0">
                        <span class="px-1.5 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-400 text-slate-900" x-text="selectedIds.length"></span>
                    </template>
                </button>
            </div>
        </div>

        <!-- ================================================================= -->
        <!-- TAB 1: CATEGORIES SECTION (SELECT & IMPORT CATEGORIES SEPARATELY)  -->
        <!-- ================================================================= -->
        <div x-show="activeTab === 'categories'" class="space-y-5">
            <!-- Categories Header & Actions -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 rounded-2xl bg-purple-50/60 border border-purple-100">
                <div>
                    <h3 class="text-sm font-extrabold text-purple-950 flex items-center gap-2">
                        <span>📁</span>
                        <span>Select Provider Categories to Import</span>
                    </h3>
                    <p class="text-xs text-purple-800/80 mt-0.5">
                        Select individual or all provider categories to import into your local MySQL database. Duplicate entries will be automatically skipped.
                    </p>
                </div>
                <!-- Controls & Import Button -->
                <div class="flex items-center gap-3 shrink-0">
                    <span class="text-xs font-bold text-purple-950">
                        <strong class="text-purple-700 font-extrabold text-sm font-mono-nums" x-text="selectedCategoryNames.length"></strong> of <span class="font-mono-nums" x-text="providerCategories.length"></span> selected
                    </span>
                    <button type="button" 
                            @click="importSelectedCategories()" 
                            :disabled="selectedCategoryNames.length === 0 || importingCategories"
                            class="px-6 py-2.5 bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 disabled:from-slate-300 disabled:to-slate-300 disabled:cursor-not-allowed text-white rounded-xl text-xs font-extrabold shadow-md shadow-purple-500/20 transition-all flex items-center gap-2 cursor-pointer">
                        <template x-if="importingCategories">
                            <svg class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                        </template>
                        <span x-text="importingCategories ? 'Importing Categories...' : '📥 Import Selected Categories (' + selectedCategoryNames.length + ')'"></span>
                    </button>
                </div>
            </div>

            <!-- Categories Filter Controls -->
            <div class="flex flex-wrap items-center justify-between gap-4 bg-slate-50 p-4 rounded-2xl border border-slate-200/70">
                <!-- Search -->
                <div class="w-full sm:w-64">
                    <input type="text" x-model="categorySearchQuery" placeholder="Search provider category..." class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-purple-500/20 focus:border-purple-600">
                </div>

                <!-- Platform Filter -->
                <div class="flex items-center gap-2">
                    <label class="text-xs font-bold text-slate-600">Platform:</label>
                    <select x-model="categoryPlatformFilter" class="px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-semibold">
                        <option value="all">All Platforms</option>
                        <option value="instagram">Instagram</option>
                        <option value="youtube">YouTube</option>
                        <option value="telegram">Telegram</option>
                        <option value="facebook">Facebook</option>
                        <option value="tiktok">TikTok</option>
                        <option value="twitter">Twitter / X</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <!-- Status Filter -->
                <div class="flex items-center gap-2">
                    <label class="text-xs font-bold text-slate-600">Status:</label>
                    <select x-model="categoryStatusFilter" class="px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-semibold">
                        <option value="all">All Categories</option>
                        <option value="new">Only New Categories</option>
                        <option value="in_db">Already in Database</option>
                    </select>
                </div>

                <!-- Quick Select Buttons -->
                <div class="flex items-center gap-2">
                    <button type="button" @click="selectOnlyNewCategories()" class="px-3 py-1.5 bg-purple-50 border border-purple-200 rounded-xl text-xs font-bold text-purple-700 hover:bg-purple-100 cursor-pointer">
                        Select All New
                    </button>
                    <button type="button" @click="selectAllFilteredCategories()" class="px-3 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-100 cursor-pointer">
                        Select All Filtered
                    </button>
                    <button type="button" @click="deselectAllCategories()" class="px-3 py-1.5 text-xs font-bold text-slate-500 hover:text-slate-800 cursor-pointer">
                        Deselect All
                    </button>
                </div>
            </div>

            <!-- Categories Table -->
            <div class="overflow-x-auto border border-slate-200 rounded-2xl max-h-[600px] overflow-y-auto">
                <table class="w-full text-left text-xs">
                    <thead class="sticky top-0 bg-slate-100 z-10">
                        <tr class="border-b border-slate-200 text-slate-500 uppercase tracking-wider font-bold">
                            <th class="py-3 px-3 w-10 text-center">
                                <input type="checkbox" @change="toggleSelectAllCategories($event.target.checked)" :checked="isAllFilteredCategoriesSelected" class="w-4 h-4 rounded border-slate-300 text-purple-600 focus:ring-purple-500 cursor-pointer">
                            </th>
                            <th class="py-3 px-3 min-w-[200px]">Provider Category Name</th>
                            <th class="py-3 px-3 min-w-[140px]">Platform Assignment</th>
                            <th class="py-3 px-3 text-center">Services in Provider</th>
                            <th class="py-3 px-3 min-w-[160px]">Database Status</th>
                            <th class="py-3 px-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <template x-if="paginatedCategories.length === 0">
                            <tr>
                                <td colspan="6" class="py-8 text-center text-slate-400">
                                    No categories match your search or filter.
                                </td>
                            </tr>
                        </template>
                        <template x-for="cat in paginatedCategories" :key="cat.name">
                            <tr class="hover:bg-purple-50/20 transition-colors" :class="selectedCategoryNames.includes(cat.name) ? 'bg-purple-50/40' : ''">
                                <td class="py-3 px-3 text-center">
                                    <input type="checkbox" 
                                           :value="cat.name" 
                                           :checked="selectedCategoryNames.includes(cat.name)"
                                           @change="toggleSelectCategory(cat.name)"
                                           class="w-4 h-4 rounded border-slate-300 text-purple-600 focus:ring-purple-500 cursor-pointer">
                                </td>
                                <td class="py-3 px-3 font-bold text-slate-900" x-text="cat.name"></td>
                                <td class="py-3 px-3">
                                    <select x-model="cat.platform" class="px-2 py-1 bg-white border border-slate-200 rounded-lg text-xs font-semibold capitalize text-slate-700">
                                        <option value="instagram">Instagram</option>
                                        <option value="youtube">YouTube</option>
                                        <option value="telegram">Telegram</option>
                                        <option value="facebook">Facebook</option>
                                        <option value="tiktok">TikTok</option>
                                        <option value="twitter">Twitter / X</option>
                                        <option value="other">Other</option>
                                    </select>
                                </td>
                                <td class="py-3 px-3 text-center font-mono-nums font-bold text-slate-600" x-text="cat.count + ' svcs'"></td>
                                <td class="py-3 px-3">
                                    <template x-if="cat.already_imported">
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            <span>✓</span>
                                            <span x-text="'In Database' + (cat.local_category_id ? ' (#' + cat.local_category_id + ')' : '')"></span>
                                        </span>
                                    </template>
                                    <template x-if="!cat.already_imported">
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase bg-purple-50 text-purple-700 border border-purple-200">
                                            <span>✨</span>
                                            <span>New Category</span>
                                        </span>
                                    </template>
                                </td>
                                <td class="py-3 px-3 text-right">
                                    <template x-if="!cat.already_imported">
                                        <button type="button" 
                                                @click="importSingleCategory(cat.name, cat.platform)"
                                                :disabled="importingCategories"
                                                class="px-3 py-1 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-xs font-bold transition-all cursor-pointer">
                                            Import
                                        </button>
                                    </template>
                                    <template x-if="cat.already_imported">
                                        <span class="text-[11px] font-semibold text-slate-400">Ready</span>
                                    </template>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <!-- Categories Pagination -->
            <div class="flex items-center justify-between text-xs text-slate-500 pt-2">
                <div>
                    Showing <span class="font-bold text-slate-800 font-mono-nums" x-text="filteredCategories.length > 0 ? (((categoryCurrentPage - 1) * categoryPerPage) + 1) : 0"></span> - 
                    <span class="font-bold text-slate-800 font-mono-nums" x-text="Math.min(categoryCurrentPage * categoryPerPage, filteredCategories.length)"></span> 
                    of <span class="font-bold text-slate-800 font-mono-nums" x-text="filteredCategories.length"></span> filtered categories
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" @click="categoryCurrentPage = Math.max(1, categoryCurrentPage - 1)" :disabled="categoryCurrentPage === 1" class="px-3 py-1.5 rounded-xl border border-slate-200 bg-white disabled:opacity-40 cursor-pointer">Previous</button>
                    <span class="font-bold text-slate-800 font-mono-nums" x-text="'Page ' + categoryCurrentPage + ' of ' + categoryTotalPages"></span>
                    <button type="button" @click="categoryCurrentPage = Math.min(categoryTotalPages, categoryCurrentPage + 1)" :disabled="categoryCurrentPage >= categoryTotalPages" class="px-3 py-1.5 rounded-xl border border-slate-200 bg-white disabled:opacity-40 cursor-pointer">Next</button>
                </div>
            </div>
        </div>

        <!-- ================================================================= -->
        <!-- TAB 2: SERVICES SECTION (PRESERVED WORKFLOW & ADVANCED MAPPING)    -->
        <!-- ================================================================= -->
        <div x-show="activeTab === 'services'" class="space-y-6">
            <!-- Services Actions Bar -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 rounded-2xl bg-blue-50/60 border border-blue-100">
                <div>
                    <h3 class="text-sm font-extrabold text-blue-950 flex items-center gap-2">
                        <span>⚡</span>
                        <span>Select Services to Import</span>
                    </h3>
                    <p class="text-xs text-blue-800/80 mt-0.5">
                        Selected services will be mapped to local categories and inserted with configured profit markup.
                    </p>
                </div>
                <div class="flex items-center gap-4 shrink-0">
                    <label class="inline-flex items-center gap-2 text-xs font-bold text-slate-700 cursor-pointer" title="Automatically creates missing categories in the database if not imported yet">
                        <input type="checkbox" x-model="autoCreateMissing" class="w-4 h-4 rounded text-blue-600 border-slate-300">
                        <span>Auto-create missing categories safely</span>
                    </label>
                    <span class="text-xs font-bold text-slate-600">
                        <span class="text-blue-600 font-extrabold text-sm font-mono-nums" x-text="selectedIds.length"></span> selected
                    </span>
                    <button type="button" 
                            @click="importSelected()" 
                            :disabled="selectedIds.length === 0 || importing"
                            class="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed text-white rounded-xl text-xs font-extrabold shadow-md shadow-emerald-500/20 transition-all flex items-center gap-2 cursor-pointer">
                        <template x-if="importing">
                            <svg class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                        </template>
                        <span x-text="importing ? 'Importing into Database...' : '📥 Import Selected Services (' + selectedIds.length + ')'"></span>
                    </button>
                </div>
            </div>

            <!-- CATEGORY PREVIEW & MAPPING BAR -->
            <div class="bg-gradient-to-r from-purple-50/60 to-indigo-50/60 p-4 sm:p-5 rounded-2xl border border-purple-100 space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="text-base">📁</span>
                        <h3 class="text-xs font-extrabold text-slate-900 uppercase tracking-wider">Quick Category Mapping</h3>
                        <span class="text-[11px] font-bold text-purple-700 bg-purple-100/70 px-2 py-0.5 rounded-full" x-text="providerCategories.length + ' Categories'"></span>
                    </div>
                    <div class="text-[11px] text-slate-500">
                        Map provider categories to local categories, or use the <strong>Categories</strong> tab to import them directly first.
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 max-h-48 overflow-y-auto p-1">
                    <template x-for="cat in providerCategories" :key="cat.name">
                        <div class="p-2.5 bg-white rounded-xl border border-purple-100 shadow-2xs space-y-1.5">
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-bold text-slate-800 truncate" :title="cat.name" x-text="cat.name"></span>
                                <span class="text-[10px] font-bold text-slate-400 font-mono-nums shrink-0" x-text="cat.count + ' svcs'"></span>
                            </div>
                            <select :value="cat.matched_category_id" 
                                    @change="onCategoryMappingChange(cat.name, $event.target.value)"
                                    class="w-full px-2 py-1 bg-slate-50 border border-slate-200 rounded-lg text-xs font-medium text-slate-700 focus:ring-1 focus:ring-purple-500">
                                <option value="auto_create">✨ Auto-Create New Category</option>
                                <template x-for="c in categories" :key="c.id">
                                    <option :value="c.id" x-text="c.name + ' (' + c.platform + ')'"></option>
                                </template>
                            </select>
                        </div>
                    </template>
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
                    <select x-model="categoryFilter" class="px-3 py-2 bg-white border border-slate-200 rounded-xl text-xs font-semibold max-w-[200px] truncate">
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
                    <button type="button" @click="selectOnlyNew()" class="px-3 py-1.5 bg-blue-50 border border-blue-200 rounded-xl text-xs font-bold text-blue-700 hover:bg-blue-100 cursor-pointer">
                        Select All New
                    </button>
                    <button type="button" @click="selectAllFiltered()" class="px-3 py-1.5 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-700 hover:bg-slate-100 cursor-pointer">
                        Select All Filtered
                    </button>
                    <button type="button" @click="deselectAll()" class="px-3 py-1.5 text-xs font-bold text-slate-500 hover:text-slate-800 cursor-pointer">
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
                            <th class="py-3 px-3 min-w-[240px]">Service Name</th>
                            <th class="py-3 px-3">Provider Category</th>
                            <th class="py-3 px-3">Orig Rate</th>
                            <th class="py-3 px-3">Panel Selling Rate (<span x-text="baseCurrency"></span>)</th>
                            <th class="py-3 px-3 min-w-[160px]">Target Category</th>
                            <th class="py-3 px-3">Min / Max</th>
                            <th class="py-3 px-3 text-right">Status</th>
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
                                <td class="py-3 px-3 font-mono tabular-nums text-slate-500">
                                    <span x-text="providerCurrency + ' ' + Number(item.original_rate).toFixed(4)"></span>
                                    <template x-if="item.is_suspicious_rate">
                                        <span class="block text-[10px] font-bold text-rose-600 font-sans">⚠️ Implausible Rate</span>
                                    </template>
                                </td>
                                <td class="py-3 px-3 font-extrabold text-blue-600 tabular-nums font-mono" x-text="baseCurrencySymbol + ' ' + calculateRate(item.original_rate)"></td>
                                <td class="py-3 px-3">
                                    <select x-model="item.matched_category_id" class="px-2 py-1 bg-white border border-slate-200 rounded-lg text-xs w-full max-w-[200px] truncate">
                                        <option value="auto_create">✨ Auto-Create New Category</option>
                                        <template x-for="c in categories" :key="c.id">
                                            <option :value="c.id" x-text="c.name + ' (' + c.platform + ')'"></option>
                                        </template>
                                    </select>
                                </td>
                                <td class="py-3 px-3 text-slate-500 tabular-nums font-mono-nums" x-text="Number(item.min_quantity).toLocaleString() + ' - ' + Number(item.max_quantity).toLocaleString()"></td>
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
                    Showing <span class="font-bold text-slate-800 font-mono-nums" x-text="filteredServices.length > 0 ? (((currentPage - 1) * perPage) + 1) : 0"></span> - 
                    <span class="font-bold text-slate-800 font-mono-nums" x-text="Math.min(currentPage * perPage, filteredServices.length)"></span> 
                    of <span class="font-bold text-slate-800 font-mono-nums" x-text="filteredServices.length"></span> filtered services
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" @click="currentPage = Math.max(1, currentPage - 1)" :disabled="currentPage === 1" class="px-3 py-1.5 rounded-xl border border-slate-200 bg-white disabled:opacity-40 cursor-pointer">Previous</button>
                    <span class="font-bold text-slate-800 font-mono-nums" x-text="'Page ' + currentPage + ' of ' + totalPages"></span>
                    <button type="button" @click="currentPage = Math.min(totalPages, currentPage + 1)" :disabled="currentPage >= totalPages" class="px-3 py-1.5 rounded-xl border border-slate-200 bg-white disabled:opacity-40 cursor-pointer">Next</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?= e($csrfToken) ?>';
const INITIAL_CATEGORIES = <?= json_encode(array_map(fn($c) => [
    'id' => (int)$c['id'],
    'name' => (string)$c['name'],
    'platform' => (string)$c['platform']
], $categories), JSON_UNESCAPED_UNICODE) ?>;

function serviceImporter() {
    return {
        selectedProviderId: '<?= (int)$preselectedProviderId ?>',
        activeTab: 'categories',
        providerName: '',
        providerCurrency: 'USD',
        baseCurrency: '<?= e(app_base_currency_code()) ?>',
        baseCurrencySymbol: '<?= e(app_base_currency()) ?>',
        conversionRateToBase: 1.0,
        markupPercent: 25,
        markupFixed: 0,
        defaultCategoryId: '<?= (int)($categories[0]['id'] ?? 1) ?>',
        autoCreateMissing: true,
        importMode: 'both',
        loading: false,
        importing: false,
        importingCategories: false,
        categories: INITIAL_CATEGORIES,
        services: [],
        providerCategories: [],
        selectedIds: [],
        selectedCategoryNames: [],
        manualCategoryNames: [],
        searchQuery: '',
        categoryFilter: 'all',
        statusFilter: 'all',
        currentPage: 1,
        perPage: 50,
        categorySearchQuery: '',
        categoryPlatformFilter: 'all',
        categoryStatusFilter: 'all',
        categoryCurrentPage: 1,
        categoryPerPage: 25,

        onProviderSelect() {
            this.services = [];
            this.providerCategories = [];
            this.selectedIds = [];
            this.selectedCategoryNames = [];
            this.manualCategoryNames = [];
        },

        syncSelectedCategories() {
            // Collect categories associated with currently selected services
            const serviceCatNames = new Set();
            for (const id of this.selectedIds) {
                const s = this.services.find(item => item.provider_service_id === id);
                if (s && s.category_name && s.category_name.trim() !== '') {
                    serviceCatNames.add(s.category_name.trim());
                }
            }
            // Category is selected if it belongs to any selected service OR was explicitly selected by admin
            const combined = new Set([...this.manualCategoryNames, ...serviceCatNames]);
            this.selectedCategoryNames = Array.from(combined);
        },

        // Category Selection & Filter Computeds
        get filteredCategories() {
            return this.providerCategories.filter(cat => {
                if (this.categoryStatusFilter === 'new' && cat.already_imported) return false;
                if (this.categoryStatusFilter === 'in_db' && !cat.already_imported) return false;
                if (this.categoryPlatformFilter !== 'all' && cat.platform !== this.categoryPlatformFilter) return false;
                if (this.categorySearchQuery.trim() !== '') {
                    const q = this.categorySearchQuery.toLowerCase();
                    if (!cat.name.toLowerCase().includes(q)) return false;
                }
                return true;
            });
        },

        get categoryTotalPages() {
            return Math.max(1, Math.ceil(this.filteredCategories.length / this.categoryPerPage));
        },

        get paginatedCategories() {
            const start = (this.categoryCurrentPage - 1) * this.categoryPerPage;
            return this.filteredCategories.slice(start, start + this.categoryPerPage);
        },

        get isAllFilteredCategoriesSelected() {
            const list = this.filteredCategories;
            if (list.length === 0) return false;
            return list.every(c => this.selectedCategoryNames.includes(c.name));
        },

        toggleSelectCategory(catName) {
            const isCurrentlySelected = this.selectedCategoryNames.includes(catName);
            if (isCurrentlySelected) {
                // Admin manually unchecks this category
                this.manualCategoryNames = this.manualCategoryNames.filter(n => n !== catName);
                this.selectedCategoryNames = this.selectedCategoryNames.filter(n => n !== catName);
            } else {
                // Admin manually checks this category
                if (!this.manualCategoryNames.includes(catName)) {
                    this.manualCategoryNames.push(catName);
                }
                if (!this.selectedCategoryNames.includes(catName)) {
                    this.selectedCategoryNames.push(catName);
                }
            }
        },

        toggleSelectAllCategories(checked) {
            if (checked) {
                this.selectAllFilteredCategories();
            } else {
                this.deselectAllCategories();
            }
        },

        selectAllFilteredCategories() {
            const namesToAdd = this.filteredCategories.map(c => c.name);
            this.manualCategoryNames = Array.from(new Set([...this.manualCategoryNames, ...namesToAdd]));
            this.syncSelectedCategories();
        },

        selectOnlyNewCategories() {
            const newNames = this.filteredCategories
                .filter(c => !c.already_imported)
                .map(c => c.name);
            this.manualCategoryNames = Array.from(new Set([...this.manualCategoryNames, ...newNames]));
            this.syncSelectedCategories();
        },

        deselectAllCategories() {
            this.manualCategoryNames = [];
            this.selectedCategoryNames = [];
        },

        async importSingleCategory(name, platform) {
            this.selectedCategoryNames = [name];
            await this.importSelectedCategories();
        },

        async importSelectedCategories() {
            if (this.selectedCategoryNames.length === 0) {
                Swal.fire({ icon: 'info', title: 'No Categories Selected', text: 'Please check one or more categories to import.' });
                return;
            }

            const confirmed = await Swal.fire({
                title: `Import ${this.selectedCategoryNames.length} Category(s)?`,
                text: `Selected categories will be created or mapped in your local database. Categories that already exist will be safely reused without duplicates.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#9333ea',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, Import Categories',
                cancelButtonText: 'Cancel'
            });

            if (!confirmed.isConfirmed) return;

            this.importingCategories = true;

            try {
                const selectedNamesSet = new Set(this.selectedCategoryNames);
                const payloadCats = this.providerCategories
                    .filter(c => selectedNamesSet.has(c.name))
                    .map(c => ({
                        name: c.name,
                        platform: c.platform || 'other'
                    }));

                const formData = new FormData();
                formData.append('action', 'import_categories');
                formData.append('provider_id', this.selectedProviderId);
                formData.append('categories_data', JSON.stringify(payloadCats));
                formData.append('csrf_token', CSRF_TOKEN);
                formData.append('ajax', '1');

                const res = await fetch('/admin/import-services.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const data = await res.json();

                if (data.success) {
                    const created = Number(data.categories_created ?? 0);
                    const skipped = Number(data.skipped_duplicates ?? data.skipped ?? 0);
                    const failed = Number(data.failed ?? 0);

                    // Update local categories list
                    if (Array.isArray(data.updated_categories)) {
                        this.categories = data.updated_categories;
                    }

                    // Update providerCategories state and service matching
                    selectedNamesSet.forEach(name => {
                        const target = this.providerCategories.find(c => c.name === name);
                        if (target) {
                            target.already_imported = true;
                            // Find matching in refreshed categories
                            const found = this.categories.find(c => c.name.toLowerCase() === name.toLowerCase());
                            if (found) {
                                target.local_category_id = found.id;
                                target.matched_category_id = found.id;
                            }
                        }

                        // Also update matched_category_id on services
                        const foundCat = this.categories.find(c => c.name.toLowerCase() === name.toLowerCase());
                        if (foundCat) {
                            this.services.forEach(s => {
                                if (s.category_name && s.category_name.toLowerCase() === name.toLowerCase()) {
                                    s.matched_category_id = foundCat.id;
                                }
                            });
                        }
                    });

                    this.manualCategoryNames = this.manualCategoryNames.filter(n => !selectedNamesSet.has(n));
                    this.syncSelectedCategories();

                    const errorHtml = (data.errors && data.errors.length > 0)
                        ? `<div class="mt-2 text-left max-h-32 overflow-y-auto p-2 bg-rose-50 border border-rose-200 rounded-lg text-[11px] text-rose-800 space-y-1">
                               ${data.errors.slice(0, 10).map(e => `<div>• ${e}</div>`).join('')}
                           </div>`
                        : '';

                    Swal.fire({
                        icon: failed > 0 ? (created > 0 ? 'warning' : 'error') : 'success',
                        title: failed > 0 ? (created > 0 ? 'Categories Import Completed with Warnings' : 'Categories Import Failed') : 'Categories Import Completed',
                        html: `
                            <div class="text-left text-xs space-y-2 p-3.5 bg-slate-50 rounded-xl border border-slate-200 mt-2">
                                <div class="font-bold text-slate-800 pb-1 border-b border-slate-200">Categories Summary:</div>
                                <div class="flex justify-between"><span>✓ <strong>Successfully Created:</strong></span> <span class="text-emerald-600 font-bold font-mono">${created}</span></div>
                                <div class="flex justify-between"><span>• <strong>Skipped (Already in Database):</strong></span> <span class="text-amber-600 font-bold font-mono">${skipped}</span></div>
                                <div class="flex justify-between"><span>✕ <strong>Failed:</strong></span> <span class="text-rose-600 font-bold font-mono">${failed}</span></div>
                            </div>
                            ${errorHtml}
                        `,
                        confirmButtonText: 'Done'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Category Import Failed',
                        text: data.error || 'Failed to complete category insertion.'
                    });
                }
            } catch (err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Category Import Error',
                    text: 'An error occurred while importing categories: ' + err.message
                });
            } finally {
                this.importingCategories = false;
            }
        },

        onCategoryMappingChange(catName, targetCatId) {
            const parsedTarget = (targetCatId === 'auto_create') ? 'auto_create' : parseInt(targetCatId, 10);
            this.services.forEach(s => {
                if (s.category_name === catName) {
                    s.matched_category_id = parsedTarget;
                }
            });
            const pCat = this.providerCategories.find(c => c.name === catName);
            if (pCat) pCat.matched_category_id = parsedTarget;
        },

        calculateRate(originalRate) {
            const orig = parseFloat(originalRate) || 0;
            if (orig <= 0) return '0.00';
            // Convert from provider currency to panel base currency
            const origInBase = orig * this.conversionRateToBase;
            const pct = parseFloat(this.markupPercent) || 0;
            const fixed = parseFloat(this.markupFixed) || 0;
            const res = origInBase + (origInBase * (pct / 100)) + fixed;
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
            this.syncSelectedCategories();
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
            this.syncSelectedCategories();
        },

        selectOnlyNew() {
            const newIds = this.filteredServices
                .filter(s => !s.already_imported)
                .map(s => s.provider_service_id);
            this.selectedIds = newIds;
            this.syncSelectedCategories();
        },

        deselectAll() {
            this.selectedIds = [];
            this.syncSelectedCategories();
        },

        async fetchServices() {
            if (!this.selectedProviderId) {
                Swal.fire({ icon: 'warning', title: 'Provider Required', text: 'Please select a provider first.' });
                return;
            }

            this.loading = true;
            this.services = [];
            this.providerCategories = [];
            this.selectedIds = [];
            this.selectedCategoryNames = [];
            this.manualCategoryNames = [];
            this.currentPage = 1;
            this.categoryCurrentPage = 1;

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
                    this.providerCategories = data.categories || [];
                    this.providerName = data.provider_name || 'Selected Provider';
                    this.providerCurrency = data.provider_currency || 'USD';
                    this.baseCurrency = data.base_currency || 'INR';
                    this.baseCurrencySymbol = data.base_currency_symbol || '₹';
                    this.conversionRateToBase = Number(data.conversion_rate_to_base) || 1.0;
                    if (Array.isArray(data.existing_categories)) {
                        this.categories = data.existing_categories;
                    }

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
                            title: `Loaded ${data.total_count} services across ${this.providerCategories.length} categories`,
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

            const confirmed = await Swal.fire({
                title: `Import ${this.selectedIds.length} Service(s)?`,
                text: `Selected services will be imported into your services database with a ${this.markupPercent}% profit markup. Duplicate entries will be automatically skipped and categories will be mapped safely.`,
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
                const selectedMap = new Set(this.selectedIds);
                const payloadServices = this.services
                    .filter(s => selectedMap.has(s.provider_service_id))
                    .map(s => ({
                        provider_service_id: s.provider_service_id,
                        name: s.name,
                        original_rate: Number(s.original_rate) || 0,
                        category_name: s.category_name || 'Other',
                        target_category_id: s.matched_category_id,
                        min_quantity: s.min_quantity,
                        max_quantity: s.max_quantity,
                        service_type: s.service_type,
                        speed: s.speed,
                        description: s.description
                    }));

                const formData = new FormData();
                formData.append('action', 'import_services');
                formData.append('provider_id', this.selectedProviderId);
                formData.append('import_mode', this.importMode);
                formData.append('markup_percent', this.markupPercent);
                formData.append('markup_fixed', this.markupFixed);
                formData.append('default_category_id', this.defaultCategoryId);
                formData.append('auto_create_missing', this.autoCreateMissing ? '1' : '0');
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
                    // Update already_imported flag locally for successful ones
                    selectedMap.forEach(psid => {
                        const item = this.services.find(s => s.provider_service_id === psid);
                        if (item) item.already_imported = true;
                    });
                    this.selectedIds = [];
                    this.syncSelectedCategories();

                    const imported = Number(data.imported ?? 0);
                    const skipped = Number(data.skipped_duplicates ?? data.skipped ?? 0);
                    const failed = Number(data.failed ?? 0);
                    const catsCreated = Number(data.categories_created ?? 0);
                    const catsReused = Number(data.categories_reused ?? 0);

                    if (Array.isArray(data.updated_categories)) {
                        this.categories = data.updated_categories;
                        // Synchronize providerCategories already_imported and local_category_id
                        const updatedCatNamesMap = new Map();
                        this.categories.forEach(c => {
                            updatedCatNamesMap.set(c.name.toLowerCase(), c.id);
                        });
                        this.providerCategories.forEach(pCat => {
                            const lower = pCat.name.toLowerCase();
                            if (updatedCatNamesMap.has(lower)) {
                                pCat.already_imported = true;
                                const localId = updatedCatNamesMap.get(lower);
                                pCat.local_category_id = localId;
                                pCat.matched_category_id = localId;
                            }
                        });
                    }

                    const errorHtml = (data.errors && data.errors.length > 0)
                        ? `<div class="mt-2 text-left max-h-32 overflow-y-auto p-2 bg-rose-50 border border-rose-200 rounded-lg text-[11px] text-rose-800 space-y-1">
                               ${data.errors.slice(0, 10).map(e => `<div>• ${e}</div>`).join('')}
                               ${data.errors.length > 10 ? `<div>...and ${data.errors.length - 10} more</div>` : ''}
                           </div>`
                        : '';

                    Swal.fire({
                        icon: failed > 0 ? (imported > 0 ? 'warning' : 'error') : 'success',
                        title: failed > 0 ? (imported > 0 ? 'Import Completed with Warnings' : 'Import Failed') : 'Import Completed',
                        html: `
                            <div class="text-left text-xs space-y-2 p-3.5 bg-slate-50 rounded-xl border border-slate-200 mt-2">
                                <div class="font-bold text-slate-800 pb-1 border-b border-slate-200">Services Summary:</div>
                                <div class="flex justify-between"><span>✓ <strong>Successfully Imported:</strong></span> <span class="text-emerald-600 font-bold font-mono">${imported}</span></div>
                                <div class="flex justify-between"><span>• <strong>Skipped (Duplicates):</strong></span> <span class="text-amber-600 font-bold font-mono">${skipped}</span></div>
                                <div class="flex justify-between"><span>✕ <strong>Failed:</strong></span> <span class="text-rose-600 font-bold font-mono">${failed}</span></div>
                                
                                <div class="font-bold text-slate-800 pt-2 pb-1 border-b border-slate-200 border-t">Categories Summary:</div>
                                <div class="flex justify-between"><span>+ <strong>Categories Created:</strong></span> <span class="text-blue-600 font-bold font-mono">${catsCreated}</span></div>
                                <div class="flex justify-between"><span>↻ <strong>Categories Reused/Matched:</strong></span> <span class="text-slate-600 font-bold font-mono">${catsReused}</span></div>
                            </div>
                            ${errorHtml}
                        `,
                        confirmButtonText: 'Done'
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
