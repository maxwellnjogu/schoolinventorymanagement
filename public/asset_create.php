<?php
/**
 * Register New Individual Fixed Asset
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Register New Asset';

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

$errors = [];

// Fetch dropdown data
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
        $assetTag = trim($_POST['asset_tag'] ?? '');
        $serialNumber = trim($_POST['serial_number'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $manufacturer = trim($_POST['manufacturer'] ?? '');
        $purchaseDate = trim($_POST['purchase_date'] ?? date('Y-m-d'));
        $purchaseCost = (float)($_POST['purchase_cost'] ?? 0.0);
        $conditionStatus = trim($_POST['condition_status'] ?? 'new');
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
                $res = create_fixed_asset($pdo, [
                    'asset_tag'        => $assetTag,
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

                flash_set('success', "Fixed Asset '{$name}' registered successfully with Asset ID {$res['asset_tag']}.");
                redirect(url('public/asset_view.php?id=' . $res['asset_id']));
            } catch (Exception $e) {
                $errors[] = 'Registration Error: ' . $e->getMessage();
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
                <li class="breadcrumb-item active">Register Asset</li>
            </ol>
        </nav>
        <h2 class="h3 fw-bold mb-0">Register Fixed Asset</h2>
        <p class="text-muted small mb-0">Tag and enroll an individual capital asset with unique serial number, acquisition cost, and custodian.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <a href="<?= e(url('public/assets.php')) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Register
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

<form action="<?= e(url('public/asset_create.php')) ?>" method="POST" id="assetForm">
    <?= csrf_input() ?>

    <div class="row g-4">
        <!-- Asset Identification -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="card-title mb-0 fw-bold text-primary">
                        <i class="bi bi-tag-fill me-2"></i>Asset Identification & Specifications
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label for="name" class="form-label fw-semibold">Asset Name & Description <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="name" class="form-control" 
                                   placeholder="e.g. HP ProDesk 400 G7 Desktop Computer" 
                                   value="<?= e($_POST['name'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-4">
                            <label for="asset_tag" class="form-label fw-semibold">Asset ID / Barcode Tag</label>
                            <input type="text" name="asset_tag" id="asset_tag" class="form-control font-monospace" 
                                   placeholder="Leave blank to auto-generate" 
                                   value="<?= e($_POST['asset_tag'] ?? '') ?>">
                            <div class="form-text small">Auto-generated format: <code>SJHS/CAT/2026/0001</code></div>
                        </div>

                        <div class="col-md-4">
                            <label for="category_id" class="form-label fw-semibold">Asset Category <span class="text-danger">*</span></label>
                            <select name="category_id" id="category_id" class="form-select" required>
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= (int)$cat['id'] ?>" <?= ((int)($_POST['category_id'] ?? 0) === (int)$cat['id']) ? 'selected' : '' ?>>
                                        <?= e($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="serial_number" class="form-label fw-semibold">Physical Serial Number</label>
                            <input type="text" name="serial_number" id="serial_number" class="form-control font-monospace" 
                                   placeholder="e.g. CZC21948B9 or KDD 450Z" 
                                   value="<?= e($_POST['serial_number'] ?? '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label for="item_id" class="form-label fw-semibold">Linked Catalog Prototype</label>
                            <select name="item_id" id="item_id" class="form-select">
                                <option value="">-- None / Standalone Asset --</option>
                                <?php foreach ($catalogItems as $ci): ?>
                                    <option value="<?= (int)$ci['id'] ?>" <?= ((int)($_POST['item_id'] ?? 0) === (int)$ci['id']) ? 'selected' : '' ?>>
                                        <?= e($ci['name']) ?> (<?= e($ci['item_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="model" class="form-label fw-semibold">Model / Variant</label>
                            <input type="text" name="model" id="model" class="form-control" 
                                   placeholder="e.g. ProDesk 400 Core i5 16GB RAM" 
                                   value="<?= e($_POST['model'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label for="manufacturer" class="form-label fw-semibold">Manufacturer / Brand</label>
                            <input type="text" name="manufacturer" id="manufacturer" class="form-control" 
                                   placeholder="e.g. Hewlett Packard / Isuzu / Olympus" 
                                   value="<?= e($_POST['manufacturer'] ?? '') ?>">
                        </div>

                        <div class="col-12">
                            <label for="notes" class="form-label fw-semibold">Technical Notes & Warranty Details</label>
                            <textarea name="notes" id="notes" rows="3" class="form-control" 
                                      placeholder="e.g. 3-Year Onsite Manufacturer Warranty. Includes 19-inch monitor and keyboard set."><?= e($_POST['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Deployment, Financials & Custody -->
        <div class="col-lg-4">
            <!-- Financials Card -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="card-title mb-0 fw-bold text-primary">
                        <i class="bi bi-cash-coin me-2"></i>Acquisition & Financials
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="purchase_cost" class="form-label fw-semibold">Acquisition Cost (KSh) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light fw-bold">KSh</span>
                            <input type="number" step="0.01" min="0" name="purchase_cost" id="purchase_cost" 
                                   class="form-control fw-bold fs-5 text-end" placeholder="0.00" 
                                   value="<?= e($_POST['purchase_cost'] ?? '0.00') ?>" required>
                        </div>
                        <div class="form-text small">Strictly fixed to Kenyan Shillings (KSh).</div>
                    </div>

                    <div class="mb-3">
                        <label for="purchase_date" class="form-label fw-semibold">Acquisition Date <span class="text-danger">*</span></label>
                        <input type="date" name="purchase_date" id="purchase_date" class="form-control" 
                               value="<?= e($_POST['purchase_date'] ?? date('Y-m-d')) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="condition_status" class="form-label fw-semibold">Initial Physical Condition</label>
                        <select name="condition_status" id="condition_status" class="form-select">
                            <option value="new" <?= (($_POST['condition_status'] ?? 'new') === 'new') ? 'selected' : '' ?>>New / Pristine</option>
                            <option value="good" <?= (($_POST['condition_status'] ?? '') === 'good') ? 'selected' : '' ?>>Good (Fully Functional)</option>
                            <option value="fair" <?= (($_POST['condition_status'] ?? '') === 'fair') ? 'selected' : '' ?>>Fair (Minor cosmetic wear)</option>
                            <option value="damaged" <?= (($_POST['condition_status'] ?? '') === 'damaged') ? 'selected' : '' ?>>Damaged / Defective</option>
                        </select>
                    </div>

                    <div>
                        <label for="status" class="form-label fw-semibold">Operational Status</label>
                        <select name="status" id="status" class="form-select">
                            <option value="in_use" <?= (($_POST['status'] ?? 'in_use') === 'in_use') ? 'selected' : '' ?>>In Active Use</option>
                            <option value="under_maintenance" <?= (($_POST['status'] ?? '') === 'under_maintenance') ? 'selected' : '' ?>>Under Maintenance / Setup</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Custody Card -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="card-title mb-0 fw-bold text-primary">
                        <i class="bi bi-geo-alt-fill me-2"></i>Custody & Deployment
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="department_id" class="form-label fw-semibold">Assigned Department <span class="text-danger">*</span></label>
                        <select name="department_id" id="department_id" class="form-select" required>
                            <option value="">-- Select Department --</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= (int)$dept['id'] ?>" <?= ((int)($_POST['department_id'] ?? 0) === (int)$dept['id']) ? 'selected' : '' ?>>
                                    <?= e($dept['name']) ?> (<?= e($dept['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="location_id" class="form-label fw-semibold">Physical Location / Room <span class="text-danger">*</span></label>
                        <select name="location_id" id="location_id" class="form-select" required>
                            <option value="">-- Select Location --</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?= (int)$loc['id'] ?>" <?= ((int)($_POST['location_id'] ?? 0) === (int)$loc['id']) ? 'selected' : '' ?>>
                                    <?= e($loc['name']) ?> (<?= e($loc['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="assigned_to" class="form-label fw-semibold">Custodian / Room Identifier</label>
                        <input type="text" name="assigned_to" id="assigned_to" class="form-control" 
                               placeholder="e.g. Lab 1 Workstation 12 or Mr. Mwenda" 
                               value="<?= e($_POST['assigned_to'] ?? '') ?>">
                        <div class="form-text small">Person, vehicle driver, or bench responsible.</div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary py-2 fw-semibold">
                    <i class="bi bi-check-circle me-1"></i> Save & Enroll Asset
                </button>
                <a href="<?= e(url('public/assets.php')) ?>" class="btn btn-outline-secondary">
                    Cancel
                </a>
            </div>
        </div>
    </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
