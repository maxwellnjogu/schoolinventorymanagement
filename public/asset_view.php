<?php
/**
 * Fixed Asset Dossier & Lifecycle View
 * Tracks Specifications, Transfers, Maintenance Logs, and Disposal Records
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Asset Dossier';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/asset_helpers.php';

require_login();

$user = current_user();
$pdo = Database::getConnection();

if (!$pdo) {
    die("Database connection error: " . Database::getError());
}

$assetId = (int)($_GET['id'] ?? 0);

if ($assetId <= 0) {
    flash_set('danger', 'Invalid Asset ID.');
    redirect(url('public/assets.php'));
}

$asset = get_asset_details($pdo, $assetId);

if (!$asset) {
    flash_set('danger', 'Fixed asset not found.');
    redirect(url('public/assets.php'));
}

// Department head view permission
if ($user['role'] === 'department_head') {
    if (!empty($user['department_id']) && (int)$user['department_id'] !== (int)$asset['department_id']) {
        flash_set('danger', 'Access denied. You can only view assets deployed to your department.');
        redirect(url('public/unauthorized.php'));
    }
}

$errors = [];

// Handle Modal Actions: Transfer, Maintenance, Disposal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    require_role(['admin', 'storekeeper']);

    if (!csrf_verify()) {
        $errors[] = 'Security token invalid or expired. Please submit the form again.';
    } else {
        $action = trim($_POST['form_action']);

        if ($action === 'transfer') {
            try {
                transfer_fixed_asset($pdo, $assetId, [
                    'to_department_id' => (int)($_POST['to_department_id'] ?? 0),
                    'to_location_id'   => (int)($_POST['to_location_id'] ?? 0),
                    'to_assigned_to'   => trim($_POST['to_assigned_to'] ?? ''),
                    'transfer_date'    => trim($_POST['transfer_date'] ?? date('Y-m-d')),
                    'reason'           => trim($_POST['reason'] ?? '')
                ], (int)$user['id']);

                flash_set('success', "Asset {$asset['asset_tag']} transferred successfully.");
                redirect(url("public/asset_view.php?id={$assetId}&tab=transfers"));
            } catch (Exception $e) {
                $errors[] = 'Transfer Error: ' . $e->getMessage();
            }
        } elseif ($action === 'log_maintenance') {
            try {
                record_asset_maintenance($pdo, $assetId, [
                    'maintenance_date' => trim($_POST['maintenance_date'] ?? date('Y-m-d')),
                    'completion_date'  => !empty($_POST['completion_date']) ? trim($_POST['completion_date']) : null,
                    'maintenance_type' => trim($_POST['maintenance_type'] ?? 'servicing'),
                    'service_provider' => trim($_POST['service_provider'] ?? ''),
                    'cost'             => (float)($_POST['cost'] ?? 0.0),
                    'status'           => trim($_POST['status'] ?? 'completed'),
                    'description'      => trim($_POST['description'] ?? ''),
                    'condition_after'  => trim($_POST['condition_after'] ?? 'good')
                ], (int)$user['id']);

                flash_set('success', "Maintenance log recorded successfully for {$asset['asset_tag']}.");
                redirect(url("public/asset_view.php?id={$assetId}&tab=maintenance"));
            } catch (Exception $e) {
                $errors[] = 'Maintenance Error: ' . $e->getMessage();
            }
        } elseif ($action === 'dispose') {
            try {
                dispose_fixed_asset($pdo, $assetId, [
                    'disposal_date'   => trim($_POST['disposal_date'] ?? date('Y-m-d')),
                    'disposal_method' => trim($_POST['disposal_method'] ?? 'scrapped'),
                    'proceeds'        => (float)($_POST['proceeds'] ?? 0.0),
                    'reason'          => trim($_POST['reason'] ?? '')
                ], (int)$user['id']);

                flash_set('warning', "Asset {$asset['asset_tag']} has been marked as disposed/written off.");
                redirect(url("public/asset_view.php?id={$assetId}&tab=disposal"));
            } catch (Exception $e) {
                $errors[] = 'Disposal Error: ' . $e->getMessage();
            }
        }
    }

    // Refresh asset details after action
    $asset = get_asset_details($pdo, $assetId);
}

// Fetch history
$transfers = get_asset_transfers($pdo, $assetId);
$maintenanceLogs = get_asset_maintenance_history($pdo, $assetId);

$totalMaintCost = 0.0;
foreach ($maintenanceLogs as $m) {
    $totalMaintCost += (float)$m['cost'];
}

// Calculate Asset Age
$purchaseTs = strtotime($asset['purchase_date']);
$nowTs = time();
$diffDays = max(0, floor(($nowTs - $purchaseTs) / (60 * 60 * 24)));
$ageYears = floor($diffDays / 365);
$ageMonths = floor(($diffDays % 365) / 30);
$ageString = ($ageYears > 0) ? "{$ageYears} yr " . ($ageMonths > 0 ? "{$ageMonths} mo" : "") : "{$diffDays} days";

$activeTab = $_GET['tab'] ?? 'specs';

// Dropdowns for modals
$departments = $pdo->query("SELECT id, name, code FROM `departments` ORDER BY name ASC")->fetchAll();
$locations = $pdo->query("SELECT id, name, code FROM `locations` ORDER BY name ASC")->fetchAll();

$isDisposed = in_array($asset['status'], ['disposed', 'written_off'], true);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/dashboard.php')) ?>" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= e(url('public/assets.php')) ?>" class="text-decoration-none">Fixed Assets</a></li>
                <li class="breadcrumb-item active"><?= e($asset['asset_tag']) ?></li>
            </ol>
        </nav>
        <div class="d-flex align-items-center gap-3">
            <h2 class="h3 fw-bold mb-0"><?= e($asset['name']) ?></h2>
            <span class="badge <?= e(asset_status_badge_class($asset['status'])) ?> fs-6">
                <?= e(str_replace('_', ' ', $asset['status'])) ?>
            </span>
            <span class="badge <?= e(asset_condition_badge_class($asset['condition_status'])) ?> fs-6 text-capitalize">
                <?= e($asset['condition_status']) ?>
            </span>
        </div>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0 d-flex justify-content-md-end gap-2 flex-wrap">
        <a href="<?= e(url('public/assets.php')) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <button type="button" class="btn btn-outline-dark" onclick="window.print();">
            <i class="bi bi-printer me-1"></i> Print Dossier
        </button>
        <?php if (has_role(['admin', 'storekeeper'])): ?>
            <a href="<?= e(url('public/asset_edit.php?id=' . $asset['id'])) ?>" class="btn btn-outline-primary">
                <i class="bi bi-pencil me-1"></i> Edit
            </a>
            <?php if (!$isDisposed): ?>
                <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#transferModal">
                    <i class="bi bi-arrow-left-right me-1"></i> Transfer
                </button>
                <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#maintenanceModal">
                    <i class="bi bi-tools me-1"></i> Log Service
                </button>
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#disposalModal">
                    <i class="bi bi-trash3 me-1"></i> Dispose
                </button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i>Please correct the following:</div>
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Summary Metric Cards -->
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="card-title mb-0 fw-bold text-primary">
                    <i class="bi bi-upc-scan me-2"></i>Asset Identification
                </h6>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small">Asset Tag / ID:</span>
                    <span class="fw-bold font-monospace text-primary fs-6"><?= e($asset['asset_tag']) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small">Serial Number:</span>
                    <span class="font-monospace fw-semibold"><?= e($asset['serial_number'] ?: 'Not recorded') ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small">Category:</span>
                    <span class="badge bg-light text-dark"><?= e($asset['category_name']) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted small">Model / Brand:</span>
                    <span class="fw-semibold small"><?= e($asset['model'] ?: $asset['manufacturer'] ?: 'Standard') ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="card-title mb-0 fw-bold text-primary">
                    <i class="bi bi-geo-alt me-2"></i>Custody & Deployment
                </h6>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small">Department:</span>
                    <span class="fw-semibold"><?= e($asset['department_name']) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small">Location:</span>
                    <span class="fw-semibold"><?= e($asset['location_name']) ?> (<?= e($asset['location_code'] ?? '') ?>)</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small">Current Custodian:</span>
                    <span class="fw-bold text-dark"><?= e($asset['assigned_to'] ?: 'General Use') ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted small">Total Transfers:</span>
                    <span class="badge bg-light text-dark"><?= count($transfers) ?> logged</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="card-title mb-0 fw-bold text-primary">
                    <i class="bi bi-cash-stack me-2"></i>Financials & Lifecycle
                </h6>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small">Acquisition Cost:</span>
                    <span class="fw-bold text-primary"><?= format_currency((float)$asset['purchase_cost']) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small">Acquired On:</span>
                    <span class="fw-semibold"><?= e(date('d M Y', strtotime($asset['purchase_date']))) ?> (<?= e($ageString) ?> old)</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small">Maintenance Spent:</span>
                    <span class="fw-bold text-warning"><?= format_currency($totalMaintCost) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted small">Operational Status:</span>
                    <span class="badge <?= e(asset_status_badge_class($asset['status'])) ?> text-capitalize small">
                        <?= e(str_replace('_', ' ', $asset['status'])) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs Section -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white border-bottom p-0">
        <ul class="nav nav-tabs card-header-tabs m-0 px-3" id="assetTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link <?= ($activeTab === 'specs') ? 'active' : '' ?>" id="specs-tab" data-bs-toggle="tab" data-bs-target="#specs-tab-pane" type="button" role="tab">
                    <i class="bi bi-info-circle me-1"></i> Specifications & Details
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link <?= ($activeTab === 'transfers') ? 'active' : '' ?>" id="transfers-tab" data-bs-toggle="tab" data-bs-target="#transfers-tab-pane" type="button" role="tab">
                    <i class="bi bi-arrow-left-right me-1"></i> Custody Transfers (<?= count($transfers) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link <?= ($activeTab === 'maintenance') ? 'active' : '' ?>" id="maintenance-tab" data-bs-toggle="tab" data-bs-target="#maintenance-tab-pane" type="button" role="tab">
                    <i class="bi bi-tools me-1"></i> Maintenance History (<?= count($maintenanceLogs) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link <?= ($activeTab === 'disposal') ? 'active' : '' ?>" id="disposal-tab" data-bs-toggle="tab" data-bs-target="#disposal-tab-pane" type="button" role="tab">
                    <i class="bi bi-archive me-1"></i> Disposal Record <?= $isDisposed ? '<span class="badge bg-danger ms-1">Disposed</span>' : '' ?>
                </button>
            </li>
        </ul>
    </div>
    <div class="card-body p-4">
        <div class="tab-content" id="assetTabsContent">
            <!-- Tab 1: Specs -->
            <div class="tab-pane fade <?= ($activeTab === 'specs') ? 'show active' : '' ?>" id="specs-tab-pane" role="tabpanel">
                <div class="row g-4">
                    <div class="col-md-7">
                        <h6 class="fw-bold mb-3 text-primary">Technical Specifications</h6>
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm align-middle">
                                <tbody>
                                    <tr>
                                        <th class="bg-light w-35">Asset Tag / ID</th>
                                        <td class="font-monospace fw-bold text-primary"><?= e($asset['asset_tag']) ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Serial Number</th>
                                        <td class="font-monospace"><?= e($asset['serial_number'] ?: 'Not recorded') ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Manufacturer</th>
                                        <td><?= e($asset['manufacturer'] ?: 'N/A') ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Model</th>
                                        <td><?= e($asset['model'] ?: 'N/A') ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Catalog Prototype</th>
                                        <td>
                                            <?php if ($asset['catalog_item_name']): ?>
                                                <?= e($asset['catalog_item_name']) ?> (<?= e($asset['catalog_item_code']) ?>)
                                            <?php else: ?>
                                                <span class="text-muted">Unique Standalone Asset</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Initial Acquisition Cost</th>
                                        <td class="fw-bold"><?= format_currency((float)$asset['purchase_cost']) ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Acquisition Date</th>
                                        <td><?= e(date('d F Y', strtotime($asset['purchase_date']))) ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Recorded in System</th>
                                        <td class="small text-muted"><?= e(date('d M Y H:i:s', strtotime($asset['created_at']))) ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <?php if (!empty($asset['notes'])): ?>
                            <div class="mt-3">
                                <h6 class="fw-bold text-secondary mb-1">Technical & Warranty Notes</h6>
                                <div class="p-3 bg-light rounded text-muted small"><?= nl2br(e($asset['notes'])) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Printable Barcode Card Preview -->
                    <div class="col-md-5">
                        <div class="card border p-3 bg-light text-center h-100 d-flex flex-column justify-content-center align-items-center">
                            <div class="fw-bold small text-muted text-uppercase mb-1">St. Jude High School Property</div>
                            <div class="fw-bold fs-5 text-dark mb-2"><?= e($asset['name']) ?></div>
                            
                            <!-- Barcode visual placeholder -->
                            <div class="bg-white p-3 border rounded shadow-sm mb-2 w-100 text-center">
                                <div class="font-monospace fs-4 fw-bold tracking-wider mb-1 text-primary">
                                    *<?= e($asset['asset_tag']) ?>*
                                </div>
                                <div class="small text-muted font-monospace">SN: <?= e($asset['serial_number'] ?: 'N/A') ?></div>
                            </div>
                            
                            <div class="small text-muted">
                                <strong>Department:</strong> <?= e($asset['department_name']) ?> &bull; 
                                <strong>Room:</strong> <?= e($asset['location_name']) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 2: Custody Transfers -->
            <div class="tab-pane fade <?= ($activeTab === 'transfers') ? 'show active' : '' ?>" id="transfers-tab-pane" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0 text-primary">Inter-Departmental Transfer History</h6>
                    <?php if (!$isDisposed && has_role(['admin', 'storekeeper'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#transferModal">
                            <i class="bi bi-arrow-left-right me-1"></i> Transfer Asset
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (empty($transfers)): ?>
                    <div class="p-4 text-center text-muted border rounded bg-light">
                        <i class="bi bi-arrow-left-right display-6 d-block mb-2 text-secondary opacity-50"></i>
                        No custody transfers recorded. Asset remains in its original deployment location.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>From Department & Location</th>
                                    <th>To Department & Location</th>
                                    <th>Transferred Custodian</th>
                                    <th>Authorized By</th>
                                    <th>Reason / Authorization Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transfers as $t): ?>
                                    <tr>
                                        <td class="fw-semibold small text-nowrap"><?= e(date('d M Y', strtotime($t['transfer_date']))) ?></td>
                                        <td>
                                            <div class="fw-semibold small"><?= e($t['from_dept_name']) ?></div>
                                            <div class="text-muted small"><?= e($t['from_loc_name']) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold small text-primary"><?= e($t['to_dept_name']) ?></div>
                                            <div class="text-muted small"><?= e($t['to_loc_name']) ?></div>
                                        </td>
                                        <td><?= e($t['to_assigned_to'] ?: '<span class="text-muted">Unchanged</span>') ?></td>
                                        <td class="small"><?= e($t['transferred_by_name'] ?? 'Storekeeper') ?></td>
                                        <td class="small text-muted"><?= e($t['reason']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tab 3: Maintenance Logs -->
            <div class="tab-pane fade <?= ($activeTab === 'maintenance') ? 'show active' : '' ?>" id="maintenance-tab-pane" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h6 class="fw-bold mb-0 text-primary">Servicing, Repairs & Calibration Logs</h6>
                        <span class="small text-muted">Cumulative Maintenance Expenditure: <strong><?= format_currency($totalMaintCost) ?></strong></span>
                    </div>
                    <?php if (!$isDisposed && has_role(['admin', 'storekeeper'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#maintenanceModal">
                            <i class="bi bi-tools me-1"></i> Log Maintenance
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (empty($maintenanceLogs)): ?>
                    <div class="p-4 text-center text-muted border rounded bg-light">
                        <i class="bi bi-tools display-6 d-block mb-2 text-secondary opacity-50"></i>
                        No servicing or repair records logged for this equipment.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Service Date</th>
                                    <th>Type</th>
                                    <th>Service Provider / Technician</th>
                                    <th class="text-end">Cost (KSh)</th>
                                    <th>Description of Work</th>
                                    <th class="text-center">Condition After</th>
                                    <th class="text-center">Status</th>
                                    <th>Logged By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($maintenanceLogs as $m): ?>
                                    <tr>
                                        <td class="fw-semibold small text-nowrap"><?= e(date('d M Y', strtotime($m['maintenance_date']))) ?></td>
                                        <td>
                                            <span class="badge bg-light text-dark text-capitalize"><?= e($m['maintenance_type']) ?></span>
                                        </td>
                                        <td class="fw-semibold small"><?= e($m['service_provider']) ?></td>
                                        <td class="text-end fw-bold text-primary"><?= format_currency((float)$m['cost']) ?></td>
                                        <td class="small"><?= nl2br(e($m['description'])) ?></td>
                                        <td class="text-center">
                                            <span class="badge <?= e(asset_condition_badge_class($m['condition_after'])) ?> text-capitalize small">
                                                <?= e($m['condition_after']) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge <?= ($m['status'] === 'completed') ? 'bg-success' : 'bg-warning text-dark' ?> small">
                                                <?= e(ucfirst($m['status'])) ?>
                                            </span>
                                        </td>
                                        <td class="small text-muted"><?= e($m['recorded_by_name'] ?? 'Officer') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td colspan="3" class="text-end">Total Servicing Expenditure:</td>
                                    <td class="text-end text-primary fs-6"><?= format_currency($totalMaintCost) ?></td>
                                    <td colspan="4"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tab 4: Disposal -->
            <div class="tab-pane fade <?= ($activeTab === 'disposal') ? 'show active' : '' ?>" id="disposal-tab-pane" role="tabpanel">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold mb-0 text-primary">Disposal & Write-Off Status</h6>
                    <?php if (!$isDisposed && has_role(['admin', 'storekeeper'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#disposalModal">
                            <i class="bi bi-trash3 me-1"></i> Record Asset Disposal
                        </button>
                    <?php endif; ?>
                </div>

                <?php if ($isDisposed && $asset['disposal_id']): ?>
                    <div class="p-4 bg-danger-subtle text-danger rounded border border-danger mb-3">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-exclamation-octagon-fill fs-4"></i>
                            <h5 class="mb-0 fw-bold">Asset Disposed / Written-Off</h5>
                        </div>
                        <div class="row g-3 mt-1">
                            <div class="col-sm-6 col-md-3">
                                <div class="small text-muted">Disposal Date</div>
                                <div class="fw-bold text-dark"><?= e(date('d M Y', strtotime($asset['disposal_date']))) ?></div>
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <div class="small text-muted">Disposal Method</div>
                                <div class="fw-bold text-dark text-capitalize"><?= e(str_replace('_', ' ', $asset['disposal_method'])) ?></div>
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <div class="small text-muted">Recovered Proceeds</div>
                                <div class="fw-bold text-success fs-6"><?= format_currency((float)$asset['proceeds']) ?></div>
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <div class="small text-muted">Authorized By</div>
                                <div class="fw-bold text-dark"><?= e($asset['authorized_by_name'] ?? 'Principal / BOM') ?></div>
                            </div>
                            <div class="col-12">
                                <div class="small text-muted">Official Board of Management Justification</div>
                                <div class="p-2 bg-white rounded border text-dark mt-1"><?= nl2br(e($asset['disposal_reason'])) ?></div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="p-4 text-center text-muted border rounded bg-light">
                        <i class="bi bi-shield-check display-6 d-block mb-2 text-success opacity-50"></i>
                        <h5>Asset is Active and Operational</h5>
                        <p class="small text-muted mb-0">This asset is in active service and has not been scheduled for decommissioning or disposal.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 1: TRANSFER ASSET -->
<!-- ========================================== -->
<?php if (!$isDisposed && has_role(['admin', 'storekeeper'])): ?>
<div class="modal fade" id="transferModal" tabindex="-1" aria-labelledby="transferModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="<?= e(url('public/asset_view.php?id=' . $asset['id'])) ?>" method="POST">
                <?= csrf_input() ?>
                <input type="hidden" name="form_action" value="transfer">

                <div class="modal-header bg-warning-subtle text-dark">
                    <h5 class="modal-title fw-bold" id="transferModalLabel">
                        <i class="bi bi-arrow-left-right me-2"></i>Transfer Asset: <?= e($asset['asset_tag']) ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label text-muted small">Current Location & Custody</label>
                        <div class="p-2 bg-light rounded small">
                            <strong><?= e($asset['department_name']) ?></strong> &bull; <?= e($asset['location_name']) ?> (Custodian: <?= e($asset['assigned_to'] ?: 'General') ?>)
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="to_department_id" class="form-label fw-semibold">Destination Department <span class="text-danger">*</span></label>
                        <select name="to_department_id" id="to_department_id" class="form-select" required>
                            <option value="">-- Select Destination Department --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= ((int)$asset['department_id'] === (int)$d['id']) ? 'selected' : '' ?>>
                                    <?= e($d['name']) ?> (<?= e($d['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="to_location_id" class="form-label fw-semibold">Destination Location / Room <span class="text-danger">*</span></label>
                        <select name="to_location_id" id="to_location_id" class="form-select" required>
                            <option value="">-- Select Destination Location --</option>
                            <?php foreach ($locations as $l): ?>
                                <option value="<?= (int)$l['id'] ?>" <?= ((int)$asset['location_id'] === (int)$l['id']) ? 'selected' : '' ?>>
                                    <?= e($l['name']) ?> (<?= e($l['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="to_assigned_to" class="form-label fw-semibold">New Custodian / Room Bench</label>
                        <input type="text" name="to_assigned_to" id="to_assigned_to" class="form-control" 
                               placeholder="e.g. Mr. Kiprono (Biology Teacher) or Bench 4" 
                               value="<?= e($asset['assigned_to'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label for="transfer_date" class="form-label fw-semibold">Transfer Date <span class="text-danger">*</span></label>
                        <input type="date" name="transfer_date" id="transfer_date" class="form-control" 
                               value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="reason" class="form-label fw-semibold">Authorization Reason <span class="text-danger">*</span></label>
                        <textarea name="reason" id="reason" rows="2" class="form-control" 
                                  placeholder="e.g. Reassigned to Computer Lab 2 for Form 4 national exam registration." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning fw-semibold">
                        <i class="bi bi-check-circle me-1"></i> Confirm Transfer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 2: LOG MAINTENANCE -->
<!-- ========================================== -->
<div class="modal fade" id="maintenanceModal" tabindex="-1" aria-labelledby="maintenanceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="<?= e(url('public/asset_view.php?id=' . $asset['id'])) ?>" method="POST">
                <?= csrf_input() ?>
                <input type="hidden" name="form_action" value="log_maintenance">

                <div class="modal-header bg-success-subtle text-dark">
                    <h5 class="modal-title fw-bold" id="maintenanceModalLabel">
                        <i class="bi bi-tools me-2"></i>Log Equipment Maintenance: <?= e($asset['asset_tag']) ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="maintenance_type" class="form-label fw-semibold">Maintenance Type <span class="text-danger">*</span></label>
                            <select name="maintenance_type" id="maintenance_type" class="form-select" required>
                                <option value="servicing">Regular Servicing</option>
                                <option value="repair">Corrective Repair</option>
                                <option value="preventive">Preventive Maintenance</option>
                                <option value="calibration">Calibration / Alignment</option>
                                <option value="inspection">Safety Inspection</option>
                            </select>
                        </div>

                        <div class="col-md-5">
                            <label for="service_provider" class="form-label fw-semibold">Service Provider / Technician <span class="text-danger">*</span></label>
                            <input type="text" name="service_provider" id="service_provider" class="form-control" 
                                   placeholder="e.g. CompSolv Tech Ltd or In-house Tech" required>
                        </div>

                        <div class="col-md-3">
                            <label for="cost" class="form-label fw-semibold">Repair Cost (KSh) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">KSh</span>
                                <input type="number" step="0.01" min="0" name="cost" id="cost" class="form-control text-end fw-bold" 
                                       placeholder="0.00" value="0.00" required>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label for="maintenance_date" class="form-label fw-semibold">Service Date <span class="text-danger">*</span></label>
                            <input type="date" name="maintenance_date" id="maintenance_date" class="form-control" 
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-4">
                            <label for="completion_date" class="form-label fw-semibold">Completion Date</label>
                            <input type="date" name="completion_date" id="completion_date" class="form-control" 
                                   value="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="col-md-4">
                            <label for="condition_after" class="form-label fw-semibold">Condition After Service</label>
                            <select name="condition_after" id="condition_after" class="form-select">
                                <option value="good" selected>Good / Fully Functional</option>
                                <option value="fair">Fair / Operational with Limitations</option>
                                <option value="new">Like New</option>
                                <option value="damaged">Damaged / Unresolved</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label for="description" class="form-label fw-semibold">Description of Work / Replaced Parts <span class="text-danger">*</span></label>
                            <textarea name="description" id="description" rows="3" class="form-control" 
                                      placeholder="e.g. Power supply unit (PSU) replaced. Thermal paste reapplied and dust filters cleared." required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success fw-semibold">
                        <i class="bi bi-check-circle me-1"></i> Save Maintenance Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL 3: DISPOSAL / WRITE-OFF -->
<!-- ========================================== -->
<div class="modal fade" id="disposalModal" tabindex="-1" aria-labelledby="disposalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="<?= e(url('public/asset_view.php?id=' . $asset['id'])) ?>" method="POST">
                <?= csrf_input() ?>
                <input type="hidden" name="form_action" value="dispose">

                <div class="modal-header bg-danger-subtle text-dark">
                    <h5 class="modal-title fw-bold" id="disposalModalLabel">
                        <i class="bi bi-trash3 me-2 text-danger"></i>Decommission Asset: <?= e($asset['asset_tag']) ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="p-2 bg-light rounded text-danger small mb-3">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i> <strong>Warning:</strong> Decommissioning permanently marks this asset as disposed or written off.
                    </div>

                    <div class="mb-3">
                        <label for="disposal_method" class="form-label fw-semibold">Disposal Method <span class="text-danger">*</span></label>
                        <select name="disposal_method" id="disposal_method" class="form-select" required>
                            <option value="scrapped">Scrapped / Salvaged for parts</option>
                            <option value="sold">Sold at Public Auction / Tender</option>
                            <option value="donated">Donated to community</option>
                            <option value="written_off">Written Off (Total Loss / Obsolete)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="disposal_date" class="form-label fw-semibold">Disposal Date <span class="text-danger">*</span></label>
                        <input type="date" name="disposal_date" id="disposal_date" class="form-control" 
                               value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="proceeds" class="form-label fw-semibold">Salvage / Auction Proceeds (KSh)</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light">KSh</span>
                            <input type="number" step="0.01" min="0" name="proceeds" id="proceeds" 
                                   class="form-control text-end fw-bold" value="0.00">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="disposal_reason" class="form-label fw-semibold">Board of Management (BOM) Authorization Reason <span class="text-danger">*</span></label>
                        <textarea name="reason" id="disposal_reason" rows="3" class="form-control" 
                                  placeholder="e.g. Approved under BOM Minute #14/2026. Equipment beyond economic repair." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger fw-semibold" onclick="return confirm('Confirm permanent disposal and decommissioning of this asset?');">
                        <i class="bi bi-trash3 me-1"></i> Confirm Disposal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
