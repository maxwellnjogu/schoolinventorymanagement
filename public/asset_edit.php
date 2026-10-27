<?php
/**
 * Edit Fixed Asset Metadata
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Edit Fixed Asset';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/asset_helpers.php';

require_role(['admin', 'storekeeper']);

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

$errors = [];

$categories = $pdo->query("SELECT id, name FROM `categories` ORDER BY name ASC")->fetchAll();
$departments = $pdo->query("SELECT id, name, code FROM `departments` ORDER BY name ASC")->fetchAll();
$locations = $pdo->query("SELECT id, name, code FROM `locations` ORDER BY name ASC")->fetchAll();
$catalogItems = $pdo->query("SELECT id, name, item_code FROM `items` WHERE is_asset = 1 ORDER BY name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Security token invalid or expired. Please submit the form again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $departmentId = (int)($_POST['department_id'] ?? 0);
        $locationId = (int)($_POST['location_id'] ?? 0);
        $itemId = !empty($_POST['item_id']) ? (int)$_POST['item_id'] : null;
        $serialNumber = trim($_POST['serial_number'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $manufacturer = trim($_POST['manufacturer'] ?? '');
        $purchaseDate = trim($_POST['purchase_date'] ?? date('Y-m-d'));
        $purchaseCost = (float)($_POST['purchase_cost'] ?? 0.0);
        $conditionStatus = trim($_POST['condition_status'] ?? 'good');
        $status = trim($_POST['status'] ?? 'in_use');
        $assignedTo = trim($_POST['assigned_to'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if (empty($name)) {
            $errors[] = 'Asset name is required.';
        }
        if ($categoryId <= 0) {
            $errors[] = 'Please select an asset category.';
        }
        if ($departmentId <= 0) {
            $errors[] = 'Please select the assigned school department.';
        }
        if ($locationId <= 0) {
            $errors[] = 'Please select a deployment location.';
        }
        if ($purchaseCost < 0) {
            $errors[] = 'Acquisition cost cannot be negative.';
        }

        if (empty($errors)) {
            try {
                update_fixed_asset($pdo, $assetId, [
                    'name'             => $name,
                    'category_id'      => $categoryId,
                    'department_id'    => $departmentId,
                    'location_id'      => $locationId,
                    'item_id'          => $itemId,
                    'serial_number'    => $serialNumber,
                    'model'            => $model,
                    'manufacturer'     => $manufacturer,
                    'purchase_date'    => $purchaseDate,
                    'purchase_cost'    => $purchaseCost,
                    'condition_status' => $conditionStatus,
                    'status'           => $status,
                    'assigned_to'      => $assignedTo,
                    'notes'            => $notes
                ], (int)$user['id']);

                flash_set('success', "Fixed Asset '{$asset['asset_tag']}' updated successfully.");
                redirect(url('public/asset_view.php?id=' . $assetId));
            } catch (Exception $e) {
                $errors[] = 'Update Error: ' . $e->getMessage();
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/dashboard.php')) ?>" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= e(url('public/assets.php')) ?>" class="text-decoration-none">Fixed Assets</a></li>
                <li class="breadcrumb-item"><a href="<?= e(url('public/asset_view.php?id=' . $asset['id'])) ?>" class="text-decoration-none"><?= e($asset['asset_tag']) ?></a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
        <h2 class="h3 fw-bold mb-0">Edit Fixed Asset: <?= e($asset['asset_tag']) ?></h2>
        <p class="text-muted small mb-0">Update equipment specifications, serial numbers, condition, and deployment custody.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <a href="<?= e(url('public/asset_view.php?id=' . $asset['id'])) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Dossier
        </a>
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

<form action="<?= e(url('public/asset_edit.php?id=' . $asset['id'])) ?>" method="POST" id="assetEditForm">
    <?= csrf_input() ?>

    <div class="row g-4">
        <!-- Specifications -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0 fw-bold text-primary">
                        <i class="bi bi-pencil-square me-2"></i>Asset Specifications
                    </h5>
                    <span class="badge bg-light text-dark font-monospace fs-6"><?= e($asset['asset_tag']) ?></span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="name" class="form-label fw-semibold">Asset Name & Description <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="name" class="form-control" 
                                   value="<?= e($_POST['name'] ?? $asset['name']) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label for="category_id" class="form-label fw-semibold">Asset Category <span class="text-danger">*</span></label>
                            <select name="category_id" id="category_id" class="form-select" required>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= (int)$cat['id'] ?>" <?= ((int)($_POST['category_id'] ?? $asset['category_id']) === (int)$cat['id']) ? 'selected' : '' ?>>
                                        <?= e($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="serial_number" class="form-label fw-semibold">Physical Serial Number</label>
                            <input type="text" name="serial_number" id="serial_number" class="form-control font-monospace" 
                                   value="<?= e($_POST['serial_number'] ?? $asset['serial_number'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label for="model" class="form-label fw-semibold">Model / Variant</label>
                            <input type="text" name="model" id="model" class="form-control" 
                                   value="<?= e($_POST['model'] ?? $asset['model'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label for="manufacturer" class="form-label fw-semibold">Manufacturer / Brand</label>
                            <input type="text" name="manufacturer" id="manufacturer" class="form-control" 
                                   value="<?= e($_POST['manufacturer'] ?? $asset['manufacturer'] ?? '') ?>">
                        </div>

                        <div class="col-md-12">
                            <label for="item_id" class="form-label fw-semibold">Linked Catalog Prototype</label>
                            <select name="item_id" id="item_id" class="form-select">
                                <option value="">-- None / Standalone Asset --</option>
                                <?php foreach ($catalogItems as $ci): ?>
                                    <option value="<?= (int)$ci['id'] ?>" <?= ((int)($_POST['item_id'] ?? $asset['item_id']) === (int)$ci['id']) ? 'selected' : '' ?>>
                                        <?= e($ci['name']) ?> (<?= e($ci['item_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label for="notes" class="form-label fw-semibold">Technical Notes & Details</label>
                            <textarea name="notes" id="notes" rows="3" class="form-control"><?= e($_POST['notes'] ?? $asset['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Financials & Custody -->
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="card-title mb-0 fw-bold text-primary">
                        <i class="bi bi-cash-coin me-2"></i>Financial & Condition
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="purchase_cost" class="form-label fw-semibold">Acquisition Cost (KSh) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light fw-bold">KSh</span>
                            <input type="number" step="0.01" min="0" name="purchase_cost" id="purchase_cost" 
                                   class="form-control fw-bold fs-5 text-end" 
                                   value="<?= e($_POST['purchase_cost'] ?? $asset['purchase_cost']) ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="purchase_date" class="form-label fw-semibold">Acquisition Date <span class="text-danger">*</span></label>
                        <input type="date" name="purchase_date" id="purchase_date" class="form-control" 
                               value="<?= e($_POST['purchase_date'] ?? $asset['purchase_date']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="condition_status" class="form-label fw-semibold">Physical Condition</label>
                        <select name="condition_status" id="condition_status" class="form-select">
                            <option value="new" <?= (($_POST['condition_status'] ?? $asset['condition_status']) === 'new') ? 'selected' : '' ?>>New / Pristine</option>
                            <option value="good" <?= (($_POST['condition_status'] ?? $asset['condition_status']) === 'good') ? 'selected' : '' ?>>Good (Fully Functional)</option>
                            <option value="fair" <?= (($_POST['condition_status'] ?? $asset['condition_status']) === 'fair') ? 'selected' : '' ?>>Fair (Minor wear)</option>
                            <option value="damaged" <?= (($_POST['condition_status'] ?? $asset['condition_status']) === 'damaged') ? 'selected' : '' ?>>Damaged / Defective</option>
                            <option value="disposed" <?= (($_POST['condition_status'] ?? $asset['condition_status']) === 'disposed') ? 'selected' : '' ?>>Disposed</option>
                        </select>
                    </div>

                    <div>
                        <label for="status" class="form-label fw-semibold">Operational Status</label>
                        <select name="status" id="status" class="form-select">
                            <option value="in_use" <?= (($_POST['status'] ?? $asset['status']) === 'in_use') ? 'selected' : '' ?>>In Active Use</option>
                            <option value="under_maintenance" <?= (($_POST['status'] ?? $asset['status']) === 'under_maintenance') ? 'selected' : '' ?>>Under Maintenance</option>
                            <option value="disposed" <?= (($_POST['status'] ?? $asset['status']) === 'disposed') ? 'selected' : '' ?>>Disposed</option>
                            <option value="written_off" <?= (($_POST['status'] ?? $asset['status']) === 'written_off') ? 'selected' : '' ?>>Written Off</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="card-title mb-0 fw-bold text-primary">
                        <i class="bi bi-geo-alt-fill me-2"></i>Deployment
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="department_id" class="form-label fw-semibold">Department <span class="text-danger">*</span></label>
                        <select name="department_id" id="department_id" class="form-select" required>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= (int)$dept['id'] ?>" <?= ((int)($_POST['department_id'] ?? $asset['department_id']) === (int)$dept['id']) ? 'selected' : '' ?>>
                                    <?= e($dept['name']) ?> (<?= e($dept['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="location_id" class="form-label fw-semibold">Location <span class="text-danger">*</span></label>
                        <select name="location_id" id="location_id" class="form-select" required>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?= (int)$loc['id'] ?>" <?= ((int)($_POST['location_id'] ?? $asset['location_id']) === (int)$loc['id']) ? 'selected' : '' ?>>
                                    <?= e($loc['name']) ?> (<?= e($loc['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="assigned_to" class="form-label fw-semibold">Custodian / Room Identifier</label>
                        <input type="text" name="assigned_to" id="assigned_to" class="form-control" 
                               value="<?= e($_POST['assigned_to'] ?? $asset['assigned_to'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary py-2 fw-semibold">
                    <i class="bi bi-check-circle me-1"></i> Save Changes
                </button>
                <a href="<?= e(url('public/asset_view.php?id=' . $asset['id'])) ?>" class="btn btn-outline-secondary">
                    Cancel
                </a>
            </div>
        </div>
    </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
