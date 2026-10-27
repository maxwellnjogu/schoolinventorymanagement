<?php
/**
 * Master Stock Movements Ledger
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Stock Movements Ledger';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

$user = current_user();
$pdo = Database::getConnection();

if (!$pdo) {
    die("Database connection error: " . Database::getError());
}

$refTypeFilter = $_GET['reference_type'] ?? '';
$movementTypeFilter = $_GET['movement_type'] ?? '';
$itemIdFilter = !empty($_GET['item_id']) ? (int)$_GET['item_id'] : null;

$where = ["1=1"];
$params = [];

if (!empty($refTypeFilter)) {
    $where[] = "sm.reference_type = ?";
    $params[] = $refTypeFilter;
}

if (!empty($movementTypeFilter)) {
    $where[] = "sm.movement_type = ?";
    $params[] = $movementTypeFilter;
}

if ($itemIdFilter) {
    $where[] = "sm.item_id = ?";
    $params[] = $itemIdFilter;
}

$whereClause = implode(" AND ", $where);

// Fetch movements with items and users
$query = "
    SELECT sm.*, i.item_code, i.name AS item_name, i.unit_of_measure, l.name AS location_name,
           u.full_name AS actor_name, u.role AS actor_role
    FROM `stock_movements` sm
    JOIN `items` i ON sm.item_id = i.id
    LEFT JOIN `locations` l ON sm.location_id = l.id
    LEFT JOIN `users` u ON sm.performed_by = u.id
    WHERE {$whereClause}
    ORDER BY sm.created_at DESC, sm.id DESC
    LIMIT 100
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$movements = $stmt->fetchAll();

// Fetch items for filter dropdown
$items = $pdo->query("SELECT id, item_code, name FROM `items` ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-arrow-left-right text-primary me-2"></i>Stock Movement Ledger</h4>
        <div class="text-muted small">Comprehensive transaction record of all receipts, issues, adjustments, and returns across the school.</div>
    </div>
    <?php if (has_role(['admin', 'storekeeper'])): ?>
        <a href="<?= e(url('public/stock_adjustment.php')) ?>" class="btn btn-warning text-dark btn-sm d-flex align-items-center gap-1 shadow-sm">
            <i class="bi bi-sliders"></i>
            <span>New Stock Adjustment</span>
        </a>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card card-custom mb-4 shadow-sm">
    <div class="card-body p-3">
        <form method="GET" action="<?= e(url('public/movements.php')) ?>" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label for="item_id" class="form-label small fw-semibold text-muted mb-1">Filter by Item</label>
                <select class="form-select form-select-sm" id="item_id" name="item_id">
                    <option value="">All Catalog Items</option>
                    <?php foreach ($items as $it): ?>
                        <option value="<?= (int)$it['id'] ?>" <?= $itemIdFilter === (int)$it['id'] ? 'selected' : '' ?>>
                            [<?= e($it['item_code']) ?>] <?= e($it['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label for="reference_type" class="form-label small fw-semibold text-muted mb-1">Transaction Reference</label>
                <select class="form-select form-select-sm" id="reference_type" name="reference_type">
                    <option value="">All Reference Types</option>
                    <option value="grn" <?= $refTypeFilter === 'grn' ? 'selected' : '' ?>>Goods Received (GRN)</option>
                    <option value="issue" <?= $refTypeFilter === 'issue' ? 'selected' : '' ?>>Stock Issue</option>
                    <option value="adjustment" <?= $refTypeFilter === 'adjustment' ? 'selected' : '' ?>>Stock Adjustment</option>
                    <option value="return" <?= $refTypeFilter === 'return' ? 'selected' : '' ?>>Return</option>
                    <option value="damage" <?= $refTypeFilter === 'damage' ? 'selected' : '' ?>>Damage / Write-off</option>
                </select>
            </div>

            <div class="col-md-3">
                <label for="movement_type" class="form-label small fw-semibold text-muted mb-1">Flow Direction</label>
                <select class="form-select form-select-sm" id="movement_type" name="movement_type">
                    <option value="">All Directions</option>
                    <option value="in" <?= $movementTypeFilter === 'in' ? 'selected' : '' ?>>Stock In (Inflow)</option>
                    <option value="out" <?= $movementTypeFilter === 'out' ? 'selected' : '' ?>>Stock Out (Outflow)</option>
                </select>
            </div>

            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary w-100" title="Filter"><i class="bi bi-funnel-fill me-1"></i>Filter</button>
                <a href="<?= e(url('public/movements.php')) ?>" class="btn btn-sm btn-outline-secondary" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Ledger Table -->
<div class="card card-custom shadow-sm">
    <div class="card-header fw-bold bg-light py-3 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-check text-primary me-2"></i>Recent Stock Movements</span>
        <span class="badge bg-secondary"><?= count($movements) ?> Records</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small">
                    <tr>
                        <th class="ps-3" style="width: 150px;">Timestamp</th>
                        <th>Item Description</th>
                        <th>Ref Type</th>
                        <th>Reference #</th>
                        <th class="text-center">Flow</th>
                        <th class="text-end">Quantity</th>
                        <th class="text-end">Balance After</th>
                        <th>User</th>
                        <th class="pe-3">Audit Notes</th>
                    </tr>
                </thead>
                <tbody class="small">
                    <?php if (empty($movements)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-2 text-muted"></i>
                                No stock movement records found matching your filter criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($movements as $m): ?>
                            <tr>
                                <td class="ps-3 text-muted">
                                    <?= format_date($m['created_at']) ?>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark">
                                        <a href="<?= e(url('public/item_movements.php?id=' . (int)$m['item_id'])) ?>" class="text-decoration-none">
                                            <?= e($m['item_name']) ?>
                                        </a>
                                    </div>
                                    <code><?= e($m['item_code']) ?></code>
                                </td>
                                <td>
                                    <?php if ($m['reference_type'] === 'grn'): ?>
                                        <span class="badge bg-success">GRN</span>
                                    <?php elseif ($m['reference_type'] === 'issue'): ?>
                                        <span class="badge bg-danger">Issue</span>
                                    <?php elseif ($m['reference_type'] === 'adjustment'): ?>
                                        <span class="badge bg-warning text-dark">Adjustment</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?= ucfirst(e($m['reference_type'])) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code class="fw-semibold"><?= e($m['reference_number']) ?></code>
                                </td>
                                <td class="text-center">
                                    <?php if ($m['movement_type'] === 'in'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success">In</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger">Out</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold">
                                    <?php if ($m['movement_type'] === 'in'): ?>
                                        <span class="text-success">+<?= number_format((float)$m['quantity'], 1) ?></span>
                                    <?php else: ?>
                                        <span class="text-danger">-<?= number_format((float)$m['quantity'], 1) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-bold text-dark">
                                    <?= number_format((float)$m['balance_after'], 1) ?> <?= e($m['unit_of_measure']) ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= e($m['actor_name'] ?? 'System') ?></div>
                                    <small class="text-muted"><?= e(role_display_name($m['actor_role'] ?? 'storekeeper')) ?></small>
                                </td>
                                <td class="pe-3 text-muted">
                                    <?= e($m['notes'] ?? '-') ?>
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
