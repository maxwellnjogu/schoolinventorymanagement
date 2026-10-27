<?php
/**
 * Inventory Items Listing & Search
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Inventory Catalog';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory_helpers.php';

require_login();

$user = current_user();
$pdo = Database::getConnection();

if (!$pdo) {
    die("Database connection error: " . Database::getError());
}

// Search and filter parameters
$search = trim($_GET['search'] ?? '');
$categoryId = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$locationId = !empty($_GET['location_id']) ? (int)$_GET['location_id'] : null;
$classification = $_GET['classification'] ?? ''; // 'consumable', 'asset', or ''
$stockFilter = $_GET['stock_filter'] ?? ''; // 'low_stock', 'out_of_stock', 'in_stock', or ''

// Build dynamic prepared query
$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(i.item_code LIKE ? OR i.name LIKE ? OR i.description LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($categoryId) {
    $where[] = "i.category_id = ?";
    $params[] = $categoryId;
}

if ($locationId) {
    $where[] = "i.location_id = ?";
    $params[] = $locationId;
}

if ($classification === 'consumable') {
    $where[] = "i.is_asset = 0";
} elseif ($classification === 'asset') {
    $where[] = "i.is_asset = 1";
}

if ($stockFilter === 'low_stock') {
    $where[] = "i.quantity_on_hand <= i.reorder_level AND i.quantity_on_hand > 0";
} elseif ($stockFilter === 'out_of_stock') {
    $where[] = "i.quantity_on_hand <= 0";
} elseif ($stockFilter === 'in_stock') {
    $where[] = "i.quantity_on_hand > i.reorder_level";
}

$whereClause = implode(" AND ", $where);

// Fetch items
$query = "
    SELECT i.*, c.name AS category_name, c.is_asset_category, l.name AS location_name
    FROM `items` i
    LEFT JOIN `categories` c ON i.category_id = c.id
    LEFT JOIN `locations` l ON i.location_id = l.id
    WHERE {$whereClause}
    ORDER BY i.name ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Fetch filter dropdown options
$categories = $pdo->query("SELECT id, name, is_asset_category FROM `categories` ORDER BY name ASC")->fetchAll();
$locations = $pdo->query("SELECT id, name, code FROM `locations` ORDER BY name ASC")->fetchAll();

// Quick stock counters
$totalCount = count($items);
$totalValue = 0.0;
foreach ($items as $it) {
    $totalValue += ((float)$it['quantity_on_hand'] * (float)$it['unit_cost']);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-box-seam text-primary me-2"></i>Inventory & Stock Catalog</h4>
        <div class="text-muted small">Comprehensive consumable catalog, fixed assets classification, stock levels and thresholds.</div>
    </div>
    <div class="d-flex gap-2">
        <?php if (has_role(['admin', 'storekeeper'])): ?>
            <a href="<?= e(url('public/item_create.php')) ?>" class="btn btn-primary d-flex align-items-center gap-2 shadow-sm">
                <i class="bi bi-plus-circle-fill"></i>
                <span>Add New Item</span>
            </a>
            <a href="<?= e(url('public/stock_adjustment.php')) ?>" class="btn btn-outline-secondary d-flex align-items-center gap-2">
                <i class="bi bi-sliders"></i>
                <span>Stock Adjustment</span>
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Filtering Card -->
<div class="card card-custom mb-4 shadow-sm">
    <div class="card-body p-3">
        <form method="GET" action="<?= e(url('public/items.php')) ?>" class="row g-2 align-items-end">
            <!-- Keyword search -->
            <div class="col-md-3">
                <label for="search" class="form-label small fw-semibold text-muted mb-1">Search Keywords</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" id="search" name="search" value="<?= e($search) ?>" placeholder="Code, title, description...">
                </div>
            </div>

            <!-- Category filter -->
            <div class="col-sm-6 col-md-2">
                <label for="category_id" class="form-label small fw-semibold text-muted mb-1">Category</label>
                <select class="form-select form-select-sm" id="category_id" name="category_id">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" <?= $categoryId === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= e($cat['name']) ?> <?= $cat['is_asset_category'] ? '(Asset)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Location filter -->
            <div class="col-sm-6 col-md-2">
                <label for="location_id" class="form-label small fw-semibold text-muted mb-1">Storage Location</label>
                <select class="form-select form-select-sm" id="location_id" name="location_id">
                    <option value="">All Locations</option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?= (int)$loc['id'] ?>" <?= $locationId === (int)$loc['id'] ? 'selected' : '' ?>>
                            <?= e($loc['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Classification filter -->
            <div class="col-sm-6 col-md-2">
                <label for="classification" class="form-label small fw-semibold text-muted mb-1">Classification</label>
                <select class="form-select form-select-sm" id="classification" name="classification">
                    <option value="">All Classifications</option>
                    <option value="consumable" <?= $classification === 'consumable' ? 'selected' : '' ?>>Consumables</option>
                    <option value="asset" <?= $classification === 'asset' ? 'selected' : '' ?>>Fixed Assets</option>
                </select>
            </div>

            <!-- Stock level filter -->
            <div class="col-sm-6 col-md-2">
                <label for="stock_filter" class="form-label small fw-semibold text-muted mb-1">Stock Status</label>
                <select class="form-select form-select-sm" id="stock_filter" name="stock_filter">
                    <option value="">All Stock Levels</option>
                    <option value="in_stock" <?= $stockFilter === 'in_stock' ? 'selected' : '' ?>>Healthy Stock</option>
                    <option value="low_stock" <?= $stockFilter === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                    <option value="out_of_stock" <?= $stockFilter === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                </select>
            </div>

            <!-- Filter Buttons -->
            <div class="col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary w-100" title="Filter"><i class="bi bi-funnel-fill"></i></button>
                <a href="<?= e(url('public/items.php')) ?>" class="btn btn-sm btn-outline-secondary" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Summary Row -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="small text-muted">
        Showing <strong><?= $totalCount ?></strong> item<?= $totalCount === 1 ? '' : 's' ?> found
        <?php if ($totalValue > 0): ?>
            <span class="mx-2">|</span> Total Catalog Stock Value: <strong class="text-success"><?= format_currency($totalValue) ?></strong>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <span class="badge bg-light text-dark border"><i class="bi bi-circle-fill text-success me-1" style="font-size: 0.6rem;"></i>Healthy</span>
        <span class="badge bg-light text-dark border"><i class="bi bi-circle-fill text-warning me-1" style="font-size: 0.6rem;"></i>Low Stock</span>
        <span class="badge bg-light text-dark border"><i class="bi bi-circle-fill text-danger me-1" style="font-size: 0.6rem;"></i>Out of Stock</span>
    </div>
</div>

<!-- Inventory Items Table -->
<div class="card card-custom shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small">
                    <tr>
                        <th class="ps-3" style="width: 120px;">Item Code</th>
                        <th>Item Description</th>
                        <th>Category</th>
                        <th>Classification</th>
                        <th>Location</th>
                        <th class="text-end">Unit Cost</th>
                        <th class="text-center">Current Stock</th>
                        <th class="text-center">Threshold</th>
                        <th class="text-center">Status</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="small">
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-2 text-muted"></i>
                                No inventory items match your search criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                            <?php 
                                $qty = (float)$item['quantity_on_hand'];
                                $reorder = (float)$item['reorder_level'];
                                $isLow = ($qty <= $reorder && $qty > 0);
                                $isOut = ($qty <= 0);
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <code class="fw-bold text-dark"><?= e($item['item_code']) ?></code>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= e($item['name']) ?></div>
                                    <?php if (!empty($item['description'])): ?>
                                        <div class="text-muted text-truncate" style="max-width: 250px; font-size: 0.75rem;">
                                            <?= e($item['description']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <?= e($item['category_name'] ?? 'Uncategorized') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($item['is_asset']): ?>
                                        <span class="badge bg-info text-dark"><i class="bi bi-display me-1"></i>Fixed Asset</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary text-white"><i class="bi bi-box me-1"></i>Consumable</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= e($item['location_name'] ?? 'Main Store') ?></span>
                                </td>
                                <td class="text-end fw-semibold">
                                    <?= format_currency($item['unit_cost']) ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($isOut): ?>
                                        <span class="badge bg-danger text-white fs-6">0.0 <?= e($item['unit_of_measure']) ?></span>
                                    <?php elseif ($isLow): ?>
                                        <span class="badge bg-warning text-dark fs-6"><?= number_format($qty, 1) ?> <?= e($item['unit_of_measure']) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-success text-white fs-6"><?= number_format($qty, 1) ?> <?= e($item['unit_of_measure']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center text-muted">
                                    <?= number_format($reorder, 1) ?> <?= e($item['unit_of_measure']) ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($item['status'] === 'active'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger">Discontinued</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <div class="btn-group btn-group-sm">
                                        <a href="<?= e(url('public/item_movements.php?id=' . (int)$item['id'])) ?>" class="btn btn-outline-info" title="View Stock Movements">
                                            <i class="bi bi-clock-history"></i>
                                        </a>
                                        <?php if (has_role(['admin', 'storekeeper'])): ?>
                                            <a href="<?= e(url('public/item_edit.php?id=' . (int)$item['id'])) ?>" class="btn btn-outline-primary" title="Edit Item Details">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="<?= e(url('public/stock_adjustment.php?item_id=' . (int)$item['id'])) ?>" class="btn btn-outline-warning text-dark" title="Adjust Stock">
                                                <i class="bi bi-sliders"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
