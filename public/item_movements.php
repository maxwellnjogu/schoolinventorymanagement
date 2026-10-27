<?php
/**
 * Item Stock Movement History Ledger
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Stock Movement History';

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

$itemId = (int)($_GET['id'] ?? 0);
if ($itemId <= 0) {
    set_flash('danger', 'Invalid item identifier.');
    redirect('public/items.php');
}

// Fetch item specifications
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
    set_flash('danger', 'Item not found in catalog.');
    redirect('public/items.php');
}

// Fetch stock movements for this item in chronological order
$moveStmt = $pdo->prepare("
    SELECT sm.*, u.full_name AS actor_name, u.role AS actor_role
    FROM `stock_movements` sm
    LEFT JOIN `users` u ON sm.performed_by = u.id
    WHERE sm.item_id = ?
    ORDER BY sm.created_at DESC, sm.id DESC
");
$moveStmt->execute([$itemId]);
$movements = $moveStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-clock-history text-primary me-2"></i>Stock Movement History</h4>
        <div class="text-muted small">Immutable audit ledger tracking every stock increase, issue, and adjustment for <strong><?= e($item['name']) ?></strong> (<code><?= e($item['item_code']) ?></code>).</div>
    </div>
    <div class="d-flex gap-2">
        <?php if (has_role(['admin', 'storekeeper'])): ?>
            <a href="<?= e(url('public/stock_adjustment.php?item_id=' . $itemId)) ?>" class="btn btn-warning text-dark btn-sm d-flex align-items-center gap-1">
                <i class="bi bi-sliders"></i>
                <span>Adjust Stock</span>
            </a>
            <a href="<?= e(url('public/item_edit.php?id=' . $itemId)) ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-pencil me-1"></i>Edit Item
            </a>
        <?php endif; ?>
        <a href="<?= e(url('public/items.php')) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to Catalog
        </a>
    </div>
</div>

<!-- Item Details Summary Strip -->
<div class="card card-custom mb-4 shadow-sm">
    <div class="card-body p-3">
        <div class="row g-3 align-items-center small">
            <div class="col-sm-6 col-md-3">
                <span class="text-muted d-block">Item Code & Name:</span>
                <span class="fw-bold text-dark"><code><?= e($item['item_code']) ?></code> - <?= e($item['name']) ?></span>
            </div>
            <div class="col-sm-6 col-md-2">
                <span class="text-muted d-block">Category:</span>
                <span class="badge bg-light text-dark border"><?= e($item['category_name'] ?? 'General') ?></span>
            </div>
            <div class="col-sm-6 col-md-2">
                <span class="text-muted d-block">Storage Location:</span>
                <span><i class="bi bi-geo-alt me-1 text-muted"></i><?= e($item['location_name'] ?? 'Main Store') ?></span>
            </div>
            <div class="col-sm-6 col-md-2">
                <span class="text-muted d-block">Unit Cost:</span>
                <span class="fw-semibold text-success"><?= format_currency($item['unit_cost']) ?></span>
            </div>
            <div class="col-sm-12 col-md-3 text-md-end">
                <span class="text-muted d-block">Current Stock on Hand:</span>
                <span class="badge bg-primary fs-6 px-3 py-1">
                    <?= number_format((float)$item['quantity_on_hand'], 1) ?> <?= e($item['unit_of_measure']) ?>
                </span>
                <span class="text-muted small ms-1">(Min: <?= number_format((float)$item['reorder_level'], 1) ?>)</span>
            </div>
        </div>
    </div>
</div>

<!-- Movements Table -->
<div class="card card-custom shadow-sm">
    <div class="card-header fw-bold bg-light py-3 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-journal-text text-primary me-2"></i>Movement Transaction Ledger</span>
        <span class="badge bg-secondary"><?= count($movements) ?> Transactions</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small">
                    <tr>
                        <th class="ps-3" style="width: 160px;">Date & Time</th>
                        <th>Reference Type</th>
                        <th>Reference #</th>
                        <th class="text-center">Flow Direction</th>
                        <th class="text-end">Quantity</th>
                        <th class="text-end">Balance After</th>
                        <th class="text-end">Unit Cost</th>
                        <th>Author / User</th>
                        <th class="pe-3">Audit Notes</th>
                    </tr>
                </thead>
                <tbody class="small">
                    <?php if (empty($movements)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-4 text-muted">
                                No stock movement records logged for this item yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($movements as $m): ?>
                            <tr>
                                <td class="ps-3 text-muted">
                                    <?= format_date($m['created_at']) ?>
                                </td>
                                <td>
                                    <?php if ($m['reference_type'] === 'grn'): ?>
                                        <span class="badge bg-success"><i class="bi bi-box-arrow-in-down me-1"></i>Goods Receipt (GRN)</span>
                                    <?php elseif ($m['reference_type'] === 'issue'): ?>
                                        <span class="badge bg-danger"><i class="bi bi-box-arrow-up-right me-1"></i>Stock Issue</span>
                                    <?php elseif ($m['reference_type'] === 'adjustment'): ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-sliders me-1"></i>Stock Adjustment</span>
                                    <?php elseif ($m['reference_type'] === 'damage'): ?>
                                        <span class="badge bg-dark text-white"><i class="bi bi-x-octagon me-1"></i>Damage</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><?= ucfirst(e($m['reference_type'])) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code class="fw-bold"><?= e($m['reference_number']) ?></code>
                                </td>
                                <td class="text-center">
                                    <?php if ($m['movement_type'] === 'in'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success">
                                            <i class="bi bi-arrow-down-left me-1"></i>Stock In
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger">
                                            <i class="bi bi-arrow-up-right me-1"></i>Stock Out
                                        </span>
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
                                    <?= number_format((float)$m['balance_after'], 1) ?> <?= e($item['unit_of_measure']) ?>
                                </td>
                                <td class="text-end text-muted">
                                    <?= format_currency($m['unit_cost']) ?>
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
