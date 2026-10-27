<?php
/**
 * Create New Inventory Item
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Add Inventory Item';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory_helpers.php';

// Access Control: Only Storekeeper & Admin can create items
require_role(['admin', 'storekeeper']);

$user = current_user();
$pdo = Database::getConnection();

if (!$pdo) {
    die("Database connection error: " . Database::getError());
}

$errors = [];
$formData = [
    'item_code'        => generate_item_code($pdo, 'ITM'),
    'name'             => '',
    'description'      => '',
    'category_id'      => '',
    'location_id'      => '',
    'unit_of_measure'  => 'Pcs',
    'is_asset'         => '0',
    'unit_cost'        => '0.00',
    'opening_stock'    => '0',
    'reorder_level'    => '10'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $formData['item_code']       = strtoupper(trim($_POST['item_code'] ?? ''));
    $formData['name']            = trim($_POST['name'] ?? '');
    $formData['description']     = trim($_POST['description'] ?? '');
    $formData['category_id']     = (int)($_POST['category_id'] ?? 0);
    $formData['location_id']     = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
    $formData['unit_of_measure'] = trim($_POST['unit_of_measure'] ?? 'Pcs');
    $formData['is_asset']        = isset($_POST['is_asset']) && $_POST['is_asset'] === '1' ? 1 : 0;
    $formData['unit_cost']       = (float)($_POST['unit_cost'] ?? 0.0);
    $formData['opening_stock']   = (float)($_POST['opening_stock'] ?? 0.0);
    $formData['reorder_level']   = (float)($_POST['reorder_level'] ?? 10.0);

    // Validation
    if (empty($formData['item_code'])) {
        $errors[] = 'Item Code is required.';
    } else {
        $checkStmt = $pdo->prepare("SELECT id FROM `items` WHERE `item_code` = ? LIMIT 1");
        $checkStmt->execute([$formData['item_code']]);
        if ($checkStmt->fetch()) {
            $errors[] = "Item Code '{$formData['item_code']}' already exists. Please choose a unique code.";
        }
    }

    if (empty($formData['name'])) {
        $errors[] = 'Item Name is required.';
    }

    if ($formData['category_id'] <= 0) {
        $errors[] = 'Please select a valid category.';
    }

    if ($formData['unit_cost'] < 0) {
        $errors[] = 'Unit cost cannot be negative.';
    }

    if ($formData['reorder_level'] < 0) {
        $errors[] = 'Minimum stock threshold cannot be negative.';
    }

    if ($formData['opening_stock'] < 0) {
        $errors[] = 'Opening stock cannot be negative.';
    }

    // Process creation with database transaction
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Insert into items catalog
            $insertStmt = $pdo->prepare("
                INSERT INTO `items` (
                    `category_id`, `location_id`, `item_code`, `name`,
                    `description`, `unit_of_measure`, `is_asset`,
                    `unit_cost`, `quantity_on_hand`, `reorder_level`,
                    `status`, `created_at`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, 'active', NOW())
            ");
            $insertStmt->execute([
                $formData['category_id'],
                $formData['location_id'],
                $formData['item_code'],
                $formData['name'],
                $formData['description'] ?: null,
                $formData['unit_of_measure'],
                $formData['is_asset'],
                $formData['unit_cost'],
                $formData['reorder_level']
            ]);
            $itemId = (int)$pdo->lastInsertId();

            // If opening stock > 0, record opening stock movement atomically
            if ($formData['opening_stock'] > 0) {
                record_stock_movement($pdo, [
                    'item_id'          => $itemId,
                    'movement_type'    => 'in',
                    'quantity'         => $formData['opening_stock'],
                    'reference_type'   => 'adjustment',
                    'reference_number' => 'OPENING-STOCK-' . $formData['item_code'],
                    'reference_id'     => $itemId,
                    'location_id'      => $formData['location_id'],
                    'unit_cost'        => $formData['unit_cost'],
                    'performed_by'     => $user['id'],
                    'notes'            => 'Initial opening stock recorded upon item registration'
                ]);
            }

            // Record audit log
            log_audit_action($pdo, 'ITEM_CREATED', 'items', $itemId, [
                'item_code'     => $formData['item_code'],
                'name'          => $formData['name'],
                'opening_stock' => $formData['opening_stock'],
                'unit_cost'     => $formData['unit_cost'],
                'is_asset'      => $formData['is_asset']
            ]);

            $pdo->commit();

            set_flash('success', "Item '{$formData['name']}' ({$formData['item_code']}) was registered successfully!");
            redirect('public/items.php');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Item creation error: ' . $e->getMessage());
            $errors[] = 'Failed to create item: ' . $e->getMessage();
        }
    }
}

// Fetch categories and locations for form selects
$categories = $pdo->query("SELECT id, name, is_asset_category FROM `categories` ORDER BY name ASC")->fetchAll();
$locations = $pdo->query("SELECT id, name, code FROM `locations` ORDER BY name ASC")->fetchAll();
$units = get_standard_units();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-plus-circle-fill text-primary me-2"></i>Register New Inventory Item</h4>
                <div class="text-muted small">Add a new consumable supply or catalog item to the high school inventory registry.</div>
            </div>
            <a href="<?= e(url('public/items.php')) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to Catalog
            </a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger shadow-sm py-2 px-3 mb-4 small">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Please correct the following errors:</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                        <li><?= e($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card card-custom shadow-sm">
            <div class="card-header fw-bold bg-light py-3">
                <i class="bi bi-card-checklist text-primary me-2"></i>Item Master Specifications
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= e(url('public/item_create.php')) ?>" novalidate>
                    <?= csrf_input() ?>

                    <div class="row g-3 mb-3">
                        <!-- Item Code -->
                        <div class="col-md-4">
                            <label for="item_code" class="form-label small fw-semibold text-muted">Item Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control font-monospace" id="item_code" name="item_code" value="<?= e($formData['item_code']) ?>" required placeholder="e.g. ITM-0010">
                            <div class="form-text small">Unique inventory reference identifier.</div>
                        </div>

                        <!-- Item Name -->
                        <div class="col-md-8">
                            <label for="name" class="form-label small fw-semibold text-muted">Item Name / Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" value="<?= e($formData['name']) ?>" required placeholder="e.g. A4 Exercise Books 200 Pages">
                        </div>
                    </div>

                    <!-- Description -->
                    <div class="mb-3">
                        <label for="description" class="form-label small fw-semibold text-muted">Detailed Description / Specifications</label>
                        <textarea class="form-control" id="description" name="description" rows="2" placeholder="e.g. Technical specifications, packaging details, brand, or grade..."><?= e($formData['description']) ?></textarea>
                    </div>

                    <div class="row g-3 mb-3">
                        <!-- Category -->
                        <div class="col-md-6">
                            <label for="category_id" class="form-label small fw-semibold text-muted">Category <span class="text-danger">*</span></label>
                            <select class="form-select" id="category_id" name="category_id" required>
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= (int)$cat['id'] ?>" <?= (int)$formData['category_id'] === (int)$cat['id'] ? 'selected' : '' ?>>
                                        <?= e($cat['name']) ?> <?= $cat['is_asset_category'] ? '(Fixed Asset Category)' : '(Consumable)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Storage Location -->
                        <div class="col-md-6">
                            <label for="location_id" class="form-label small fw-semibold text-muted">Primary Storage Location</label>
                            <select class="form-select" id="location_id" name="location_id">
                                <option value="">-- Select Location --</option>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?= (int)$loc['id'] ?>" <?= (int)$formData['location_id'] === (int)$loc['id'] ? 'selected' : '' ?>>
                                        <?= e($loc['name']) ?> (<?= e($loc['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <!-- Classification: Consumable vs Fixed Asset -->
                        <div class="col-md-4">
                            <label for="is_asset" class="form-label small fw-semibold text-muted">Classification <span class="text-danger">*</span></label>
                            <select class="form-select" id="is_asset" name="is_asset" required>
                                <option value="0" <?= $formData['is_asset'] == 0 ? 'selected' : '' ?>>Consumable Inventory (Stock by Qty)</option>
                                <option value="1" <?= $formData['is_asset'] == 1 ? 'selected' : '' ?>>Fixed Asset (Tracked by Asset Tag)</option>
                            </select>
                            <div class="form-text small">Consumables are depleted through issues; assets remain in service.</div>
                        </div>

                        <!-- Unit of Measure -->
                        <div class="col-md-4">
                            <label for="unit_of_measure" class="form-label small fw-semibold text-muted">Unit of Measure <span class="text-danger">*</span></label>
                            <select class="form-select" id="unit_of_measure" name="unit_of_measure" required>
                                <?php foreach ($units as $uKey => $uLabel): ?>
                                    <option value="<?= e($uKey) ?>" <?= $formData['unit_of_measure'] === $uKey ? 'selected' : '' ?>>
                                        <?= e($uLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Unit Cost in KSh -->
                        <div class="col-md-4">
                            <label for="unit_cost" class="form-label small fw-semibold text-muted">Unit Cost (<?= e(APP_CURRENCY) ?>) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light fw-bold text-success"><?= e(APP_CURRENCY) ?></span>
                                <input type="number" step="0.01" min="0" class="form-control" id="unit_cost" name="unit_cost" value="<?= e($formData['unit_cost']) ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4 p-3 rounded-3 bg-light border">
                        <div class="col-12">
                            <div class="fw-bold text-dark small"><i class="bi bi-calculator me-1 text-primary"></i>Stock Balances & Threshold Rules</div>
                        </div>

                        <!-- Opening Stock -->
                        <div class="col-md-6">
                            <label for="opening_stock" class="form-label small fw-semibold text-muted">Initial Opening Stock Quantity</label>
                            <input type="number" step="0.01" min="0" class="form-control" id="opening_stock" name="opening_stock" value="<?= e($formData['opening_stock']) ?>">
                            <div class="form-text small text-muted">
                                If quantity > 0, an initial opening balance movement is recorded in the immutable stock ledger.
                            </div>
                        </div>

                        <!-- Reorder Level / Minimum Threshold -->
                        <div class="col-md-6">
                            <label for="reorder_level" class="form-label small fw-semibold text-muted">Minimum Stock Threshold (Reorder Level) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0" class="form-control" id="reorder_level" name="reorder_level" value="<?= e($formData['reorder_level']) ?>" required>
                            <div class="form-text small text-muted">
                                When stock reaches or falls below this number, low-stock alerts will trigger on the dashboard.
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?= e(url('public/items.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary d-flex align-items-center gap-2 shadow-sm">
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Save Item to Inventory</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
