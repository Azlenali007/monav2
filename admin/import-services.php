<?php
/**
 * Admin Service Import From Provider API (Architecture Foundation & UI)
 * SMM Panel - PHP 8.3+
 *
 * NOTE: This is an architectural foundation for a FUTURE feature.
 * No live provider fetch or bulk database insertions are executed in this phase.
 * The system reuses saved provider credentials server-side and prepares duplicate
 * detection, category mapping, and pricing markup pipelines.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireAdmin();
$db = Database::getConnection();

// Fetch saved active providers
$providers = $db->query("SELECT id, name, api_url, currency, balance, status FROM providers WHERE status = 'active' ORDER BY id DESC")->fetchAll();

// Fetch existing categories for mapping
$categories = $db->query("SELECT id, name, platform FROM categories WHERE status = 'active' ORDER BY sort_order ASC")->fetchAll();

$preselectedProviderId = (int)($_GET['provider_id'] ?? ($providers[0]['id'] ?? 0));

// Find details for selected provider
$currentProvider = null;
foreach ($providers as $p) {
    if ((int)$p['id'] === $preselectedProviderId) {
        $currentProvider = $p;
        break;
    }
}

$pageTitle = "Import Services from Provider - " . app_name() . " Admin";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto my-4 space-y-6">
    <!-- Breadcrumbs & Navigation -->
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2 text-xs font-semibold text-slate-500">
            <a href="/admin/services.php" class="hover:text-blue-600 transition-colors">Services</a>
            <span>/</span>
            <span class="text-slate-800 font-bold">Import from Provider API</span>
        </div>
        <a href="/admin/services.php" class="text-xs font-bold text-slate-500 hover:text-blue-600 transition-colors">
            &larr; Back to Services Catalog
        </a>
    </div>

    <!-- Future Release Architecture Banner -->
    <div class="bg-gradient-to-r from-indigo-900 via-slate-900 to-blue-950 text-white rounded-3xl p-6 sm:p-8 shadow-xl relative overflow-hidden">
        <div class="relative z-10 max-w-3xl space-y-2">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-500/20 border border-blue-400/30 text-blue-300 text-[11px] font-bold uppercase tracking-wider">
                <span class="w-2 h-2 rounded-full bg-blue-400 animate-pulse"></span>
                Architecture Foundation • Future Feature Preview
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight">Provider API Bulk Service Import</h1>
            <p class="text-xs sm:text-sm text-slate-300 leading-relaxed font-normal">
                This interface establishes the architecture, provider connection, duplicate prevention schema, and markup calculation pipelines. 
                Actual remote fetching and bulk database synchronization are scheduled for future release.
            </p>
        </div>
    </div>

    <!-- Step 1: Provider Selection & Pricing / Markup Settings -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl space-y-6">
        <div class="flex items-center justify-between pb-4 border-b border-slate-100">
            <div class="flex items-center gap-3">
                <span class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center font-black text-sm">1</span>
                <div>
                    <h2 class="text-base font-extrabold text-slate-900">Select Provider &amp; Configure Pricing Markup</h2>
                    <p class="text-xs text-slate-400">Reuses existing saved provider credentials without re-entering API keys</p>
                </div>
            </div>
            <span class="px-2.5 py-1 rounded bg-slate-100 text-slate-600 text-[10px] font-mono font-bold uppercase">
                Server-Side Secure
            </span>
        </div>

        <?php if (empty($providers)): ?>
            <div class="p-6 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 text-xs text-center space-y-2">
                <p class="font-bold">No active external SMM providers found.</p>
                <p class="text-amber-700">Please connect a provider first in the Providers control panel before importing services.</p>
                <a href="/admin/providers.php" class="inline-block mt-2 px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-xl font-bold text-xs transition-colors">
                    Add Provider in Providers Manager &rarr;
                </a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Select Existing Saved Provider -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Select Saved Provider</label>
                    <select id="providerSelect" onchange="onProviderChange(this.value)" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                        <?php foreach ($providers as $prov): ?>
                            <option value="<?= (int)$prov['id'] ?>" <?= ((int)$prov['id'] === $preselectedProviderId) ? 'selected' : '' ?>>
                                <?= e($prov['name']) ?> (<?= e($prov['currency']) ?> <?= number_format((float)$prov['balance'], 2) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="text-[11px] text-slate-400 mt-1 block">API credentials loaded securely from database.</span>
                </div>

                <!-- Markup Percentage -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Profit Markup Percentage</label>
                    <div class="relative">
                        <input type="number" id="markupPercent" value="25" min="0" max="1000" step="1" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                        <span class="absolute right-4 top-2.5 text-xs text-slate-400 font-bold">%</span>
                    </div>
                    <span class="text-[11px] text-slate-400 mt-1 block">E.g., 25% markup on provider original rate.</span>
                </div>

                <!-- Fallback Category -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1.5">Default Fallback Category</label>
                    <select id="fallbackCategory" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-600">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>"><?= e($cat['name']) ?> (<?= ucfirst($cat['platform']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <span class="text-[11px] text-slate-400 mt-1 block">Assigned if provider category is unrecognized.</span>
                </div>
            </div>

            <!-- Provider Connection Status Card -->
            <?php if ($currentProvider): ?>
                <div class="p-4 rounded-2xl bg-slate-50 border border-slate-200/80 flex flex-wrap items-center justify-between gap-4 text-xs">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-blue-100 text-blue-700 flex items-center justify-center font-black text-sm">
                            🔌
                        </div>
                        <div>
                            <span class="font-extrabold text-slate-900 text-sm block"><?= e($currentProvider['name']) ?></span>
                            <span class="font-mono text-[11px] text-slate-500"><?= e($currentProvider['api_url']) ?></span>
                        </div>
                    </div>
                    <div class="flex items-center gap-4 text-right">
                        <div>
                            <span class="text-slate-400 text-[11px] block font-medium">Provider Balance</span>
                            <span class="font-extrabold text-emerald-600 text-sm">
                                <?= e($currentProvider['currency']) ?> <?= number_format((float)$currentProvider['balance'], 2) ?>
                            </span>
                        </div>
                        <!-- Fetch Services Button (Explicitly Marked Coming Soon) -->
                        <button type="button" 
                                onclick="showFutureFeatureNotice('Fetch Services')" 
                                class="px-5 py-2.5 bg-blue-600/90 hover:bg-blue-700 text-white rounded-xl text-xs font-bold transition-all shadow-md flex items-center gap-2">
                            <span>Fetch Services</span>
                            <span class="px-1.5 py-0.5 rounded bg-blue-500 text-[10px] uppercase font-bold tracking-wider">Coming Soon</span>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Step 2: Service Review, Duplicate Detection & Bulk Selection Preview -->
    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200/80 shadow-xl space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-100">
            <div class="flex items-center gap-3">
                <span class="w-8 h-8 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center font-black text-sm">2</span>
                <div>
                    <h2 class="text-base font-extrabold text-slate-900">Service Review &amp; Bulk Import Pipeline</h2>
                    <p class="text-xs text-slate-400">Preview of the multi-service selection and database mapping grid</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                    <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                    Planned Feature
                </span>
            </div>
        </div>

        <!-- Architectural Engine Details Card -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <div class="p-4 rounded-2xl bg-blue-50/50 border border-blue-200/60 space-y-1">
                <span class="font-extrabold text-blue-900 flex items-center gap-1.5">
                    <span>🛡️</span> Duplicate Prevention
                </span>
                <p class="text-[11px] text-blue-800 leading-relaxed">
                    Uses composite unique key <code>(provider_id + provider_service_id)</code> to detect existing catalog entries and prevent duplicates.
                </p>
            </div>
            <div class="p-4 rounded-2xl bg-emerald-50/50 border border-emerald-200/60 space-y-1">
                <span class="font-extrabold text-emerald-900 flex items-center gap-1.5">
                    <span>📁</span> Category Mapping
                </span>
                <p class="text-[11px] text-emerald-800 leading-relaxed">
                    Binds provider categories directly into the existing <code>categories</code> table (Instagram, YouTube, etc.) with manual override.
                </p>
            </div>
            <div class="p-4 rounded-2xl bg-purple-50/50 border border-purple-200/60 space-y-1">
                <span class="font-extrabold text-purple-900 flex items-center gap-1.5">
                    <span>📈</span> Profit Margin Logic
                </span>
                <p class="text-[11px] text-purple-800 leading-relaxed">
                    Stores provider price in <code>original_rate</code> and applies formula to compute panel customer price in <code>rate_per_1000</code>.
                </p>
            </div>
        </div>

        <!-- Table Architecture Preview -->
        <div class="overflow-x-auto border border-slate-200/80 rounded-2xl">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-400 uppercase tracking-wider">
                        <th class="py-3 px-3 w-10 text-center">
                            <input type="checkbox" disabled class="w-4 h-4 rounded border-slate-300 opacity-60">
                        </th>
                        <th class="py-3 px-3">Provider ID</th>
                        <th class="py-3 px-3">Service Name</th>
                        <th class="py-3 px-3">Original Rate</th>
                        <th class="py-3 px-3">Selling Rate</th>
                        <th class="py-3 px-3">Category</th>
                        <th class="py-3 px-3">Min / Max</th>
                        <th class="py-3 px-3 text-right">Detection</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-600">
                    <tr>
                        <td colspan="8" class="py-12 text-center">
                            <div class="max-w-md mx-auto space-y-3">
                                <div class="w-12 h-12 rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto text-xl">
                                    📦
                                </div>
                                <h3 class="text-sm font-extrabold text-slate-800">Ready for Live Provider Integration</h3>
                                <p class="text-xs text-slate-500 leading-relaxed">
                                    When the service-import engine is released, clicking <strong>Fetch Services</strong> will query 
                                    <code>action=services</code> on the selected provider, populate this table, and enable bulk importation directly into the <code>services</code> table.
                                </p>
                                <span class="inline-block px-3 py-1 rounded-full bg-slate-100 text-slate-500 text-[11px] font-mono">
                                    No mock or dummy services are generated in preview mode.
                                </span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Action Controls Bar -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pt-2 border-t border-slate-100">
            <div class="text-xs text-slate-400">
                Bulk Import Action: Inserts selected rows into <code>services</code> table with mapped category and calculated rate.
            </div>
            <button type="button" 
                    onclick="showFutureFeatureNotice('Bulk Service Import')"
                    class="px-6 py-3 bg-slate-200 text-slate-500 rounded-xl text-xs font-bold cursor-not-allowed transition-all flex items-center gap-2">
                <span>Import Selected Services</span>
                <span class="px-2 py-0.5 rounded bg-slate-300 text-[10px] uppercase font-bold">Planned</span>
            </button>
        </div>
    </div>
</div>

<script>
function onProviderChange(providerId) {
    window.location.href = '/admin/import-services.php?provider_id=' + encodeURIComponent(providerId);
}

function showFutureFeatureNotice(featureName) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'info',
            title: featureName + ' (Coming Soon)',
            text: 'The architecture and database mappings for provider service importing are established. Live fetching and bulk database synchronization will be activated in the next development cycle without restructuring your existing catalog.',
            confirmButtonColor: '#2563eb',
            confirmButtonText: 'Understood'
        });
    } else {
        alert(featureName + ' is scheduled for future release. Architecture is prepared.');
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
