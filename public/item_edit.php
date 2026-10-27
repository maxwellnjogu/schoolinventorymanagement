<?php
/**
 * Edit Inventory Item Details
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Edit Inventory Item';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory_helpers.php';

require_role(['admin', 'storekeeper']);

$user = current_user();
$pdo = Database::getConnection();

if (!$pdo) {
    die("Database connection error: " . Database::getError());
}

$itemId = (int)($_GET['id'] ?? 0);
if ($itemId <= 0) {
    set_flash('danger', 'Invalid item identifier.');
    redirect('public/items.php');
}

// Fetch item details
$stmt = $pdo->prepare("
    SELECT i.*, c.name AS category_name, l.name AS location_name
    FROM `items` i
    LEFT JOIN `categories` c ON i.category_id = c.id
    LEFT JOIN `locations` l ON i.location_id = l.id
    WHERE i.id = ?
");
$stmt->execute([$itemId]);
$item = $stmt->fetch();

if (!$item) {
    set_flash('danger', 'Inventory item not found.');
    redirect('public/items.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $name            = trim($_POST['name'] ?? '');
    $description     = trim($_POST['description'] ?? '');
    $categoryId      = (int)($_POST['category_id'] ?? 0);
    $locationId      = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
    $unitOfMeasure   = trim($_POST['unit_of_measure'] ?? 'Pcs');
    $isAsset         = isset($_POST['is_asset']) && $_POST['is_asset'] === '1' ? 1 : 0;
    $unitCost        = (float)($_POST['unit_cost'] ?? 0.0);
    $reorderLevel    = (float)($_POST['reorder_level'] ?? 10.0);
    $status          = in_array($_POST['status'] ?? '', ['active', 'discontinued'], true) ? $_POST['status'] : 'active';

    // Validation
    if (empty($name)) {
        $errors[] = 'Item Name is required.';
    }

    if ($categoryId <= 0) {
        $errors[] = 'Please select a valid category.';
    }

    if ($unitCost < 0) {
        $errors[] = 'Unit cost cannot be negative.';
    }

    if ($reorderLevel < 0) {
        $errors[] = 'Minimum stock threshold cannot be negative.';
    }

    if (empty($errors)) {
        try {
            $updateStmt = $pdo->prepare("
                UPDATE `items`
                SET `name` = ?,
                    `description` = ?,
                    `category_id` = ?,
                    `location_id` = ?,
                    `unit_of_measure` = ?,
                    `is_asset` = ?,
                    `unit_cost` = ?,
                    `reorder_level` = ?,
                    `status` = ?,
                    `updated_at` = NOW()
                WHERE `id` = ?
            ");
            $updateStmt->execute([
                $name,
                $description ?: null,
                $categoryId,
                $locationId,
                $unitOfMeasure,
                $isAsset,
                $unitCost,
                $reorderLevel,
                $status,
                $itemId
            ]);

            log_audit_action($pdo, 'ITEM_UPDATED', 'items', $itemId, [
                'name'          => $name,
                'category_id'   => $categoryId,
                'unit_cost'     => $unitCost,
                'reorder_level' => $reorderLevel,
                'status'        => $status
            ]);

            set_flash('success', "Item '{$name}' was successfully updated.");
            redirect('public/items.php');
        } catch (PDOException $e) {
            error_log('Item update error: ' . $e->getMessage());
            $errors[] = 'Database update error: ' . $e->getMessage();
        }
    }
}

// Fetch categories, locations, units
$categories = $pdo->query("SELECT id, name, is_asset_category FROM `categories` ORDER BY name ASC")->fetchAll();
$locations = $pdo->query("SELECT id, name, code FROM `locations` ORDER BY name ASC")->fetchAll();
$units = get_standard_units();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Item: <?= e($item['name']) ?></h4>
                <div class="text-muted small">Update catalog specifications and threshold parameters. Item Code: <code><?= e($item['item_code']) ?></code></div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= e(url('public/item_movements.php?id=' . $itemId)) ?>" class="btn btn-outline-info btn-sm">
                    <i class="bi bi-clock-history me-1"></i>Stock History
                </a>
                <a href="<?= e(url('public/items.php')) ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back to Catalog
                </a>
            </div>
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

        <div class="card card-custom shadow-sm mb-4">
            <div class="card-header fw-bold bg-light py-3 d-flex justify-content-between align-items-center">
                <span><i class="bi bi-card-checklist text-primary me-2"></i>Catalog Item Information</span>
                <span class="badge bg-secondary font-monospace"><?= e($item['item_code']) ?></span>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= e(url('public/item_edit.php?id=' . $itemId)) ?>" novalidate>
                    <?= csrf_input() ?>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-muted">Item Code (Permanent)</label>
                            <input type="text" class="form-control font-monospace bg-light" value="<?= e($item['item_code']) ?>" disabled readonly>
                        </div>

                        <div class="col-md-8">
                            <label for="name" class="form-label small fw-semibold text-muted">Item Name / Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" value="<?= e($item['name']) ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label small fw-semibold text-muted">Detailed Description / Specifications</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?= e($item['description'] ?? '') ?></textarea>
                    </div>

                    <div class="row g-3 mb-3">
                        <!-- Category -->
                        <div class="col-md-6">
                            <label for="category_id" class="form-label small fw-semibold text-muted">Category <span class="text-danger">*</span></label>
                            <select class="form-select" id="category_id" name="category_id" required>
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= (int)$cat['id'] ?>" <?= (int)$item['category_id'] === (int)$cat['id'] ? 'selected' : '' ?>>
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
                                    <option value="<?= (int)$loc['id'] ?>" <?= (int)$item['location_id'] === (int)$loc['id'] ? 'selected' : '' ?>>
                                        <?= e($loc['name']) ?> (<?= e($loc['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <!-- Classification -->
                        <div class="col-md-4">
                            <label for="is_asset" class="form-label small fw-semibold text-muted">Classification <span class="text-danger">*</span></label>
                            <select class="form-select" id="is_asset" name="is_asset" required>
                                <option value="0" <?= $item['is_asset'] == 0 ? 'selected' : '' ?>>Consumable Inventory (Stock by Qty)</option>
                                <option value="1" <?= $item['is_asset'] == 1 ? 'selected' : '' ?>>Fixed Asset (Tracked by Asset Tag)</option>
                            </select>
                        </div>

                        <!-- Unit of Measure -->
                        <div class="col-md-4">
                            <label for="unit_of_measure" class="form-label small fw-semibold text-muted">Unit of Measure <span class="text-danger">*</span></label>
                            <select class="form-select" id="unit_of_measure" name="unit_of_measure" required>
                                <?php foreach ($units as $uKey => $uLabel): ?>
                                    <option value="<?= e($uKey) ?>" <?= $item['unit_of_measure'] === $uKey ? 'selected' : '' ?>>
                                        <?= e($uLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Unit Cost -->
                        <div class="col-md-4">
                            <label for="unit_cost" class="form-label small fw-semibold text-muted">Unit Cost (<?= e(APP_CURRENCY) ?>) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light fw-bold text-success"><?= e(APP_CURRENCY) ?></span>
                                <input type="number" step="0.01" min="0" class="form-control" id="unit_cost" name="unit_cost" value="<?= e($item['unit_cost']) ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-4 p-3 rounded-3 bg-light border">
                        <div class="col-12">
                            <div class="fw-bold text-dark small"><i class="bi bi-shield-check me-1 text-primary"></i>Stock Control & Status</div>
                        </div>

                        <!-- Current Stock on Hand (Controlled) -->
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold text-muted">Current Quantity on Hand</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="text" class="form-control bg-white fw-bold text-primary" value="<?= number_format((float)$item['quantity_on_hand'], 1) ?> <?= e($item['unit_of_measure']) ?>" readonly disabled>
                                <a href="<?= e(url('public/stock_adjustment.php?item_id=' . $itemId)) ?>" class="btn btn-outline-warning btn-sm text-nowrap text-dark" title="Record Audited Adjustment">
                                    <i class="bi bi-sliders me-1"></i>Adjust
                                </a>
                            </div>
                            <div class="form-text small text-muted">Quantity increases via GRN receipts and decreases upon verified stock issues.</div>
                        </div>

                        <!-- Minimum Reorder Level Threshold -->
                        <div class="col-md-4">
                            <label for="reorder_level" class="form-label small fw-semibold text-muted">Minimum Reorder Threshold <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0" class="form-control" id="reorder_level" name="reorder_level" value="<?= e($item['reorder_level']) ?>" required>
                            <div class="form-text small text-muted">Alerts trigger on dashboard when stock falls to or below this level.</div>
                        </div>

                        <!-- Status -->
                        <div class="col-md-4">
                            <label for="status" class="form-label small fw-semibold text-muted">Catalog Item Status <span class="text-danger">*</span></label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="active" <?= $item['status'] === 'active' ? 'selected' : '' ?>>Active (In circulation)</option>
                                <option value="discontinued" <?= $item['status'] === 'discontinued' ? 'selected' : '' ?>>Discontinued (Phased out)</option>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?= e(url('public/items.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary d-flex align-items-center gap-2 shadow-sm">
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Save Changes</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
