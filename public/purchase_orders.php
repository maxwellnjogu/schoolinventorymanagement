<?php
/**
 * Purchase Orders Listing
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Purchase Orders';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/procurement_helpers.php';

require_login();

$user = current_user();
$pdo = Database::getConnection();

if (!$pdo) {
    die("Database connection error: " . Database::getError());
}

$statusFilter = $_GET['status'] ?? '';
$supplierFilter = !empty($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : null;
$search = trim($_GET['search'] ?? '');

$where = ["1=1"];
$params = [];

if (!empty($statusFilter)) {
    $where[] = "po.status = ?";
    $params[] = $statusFilter;
}

if ($supplierFilter) {
    $where[] = "po.supplier_id = ?";
    $params[] = $supplierFilter;
}

if (!empty($search)) {
    $where[] = "(po.po_number LIKE ? OR s.company_name LIKE ? OR po.notes LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$whereClause = implode(" AND ", $where);

// Fetch POs
$query = "
    SELECT po.*, s.company_name, s.supplier_code, u.full_name AS creator_name,
           COUNT(poi.id) AS item_count,
           SUM(poi.quantity_ordered) AS total_ordered_qty,
           SUM(poi.quantity_received) AS total_received_qty
    FROM `purchase_orders` po
    JOIN `suppliers` s ON po.supplier_id = s.id
    LEFT JOIN `users` u ON po.created_by = u.id
    LEFT JOIN `purchase_order_items` poi ON po.id = poi.po_id
    WHERE {$whereClause}
    GROUP BY po.id
    ORDER BY po.order_date DESC, po.id DESC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Suppliers for filter
$suppliers = $pdo->query("SELECT id, company_name FROM `suppliers` ORDER BY company_name ASC")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-receipt-cutoff text-primary me-2"></i>Purchase Orders (PO)</h4>
        <div class="text-muted small">Procurement orders sent to suppliers. Stock increases strictly when physical goods are received via GRN.</div>
    </div>
    <?php if (has_role(['admin', 'storekeeper'])): ?>
        <a href="<?= e(url('public/po_create.php')) ?>" class="btn btn-primary d-flex align-items-center gap-2 shadow-sm">
            <i class="bi bi-plus-circle-fill"></i>
            <span>Create Purchase Order</span>
        </a>
    <?php endif; ?>
</div>

<!-- Search & Filters -->
<div class="card card-custom mb-4 shadow-sm">
    <div class="card-body p-3">
        <form method="GET" action="<?= e(url('public/purchase_orders.php')) ?>" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label for="search" class="form-label small fw-semibold text-muted mb-1">Search Orders</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" id="search" name="search" value="<?= e($search) ?>" placeholder="PO number, supplier name, notes...">
                </div>
            </div>

            <div class="col-sm-6 col-md-3">
                <label for="supplier_id" class="form-label small fw-semibold text-muted mb-1">Supplier</label>
                <select class="form-select form-select-sm" id="supplier_id" name="supplier_id">
                    <option value="">All Suppliers</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $supplierFilter === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= e($s['company_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-sm-6 col-md-3">
                <label for="status" class="form-label small fw-semibold text-muted mb-1">Order Status</label>
                <select class="form-select form-select-sm" id="status" name="status">
                    <option value="">All Statuses</option>
                    <option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="ordered" <?= $statusFilter === 'ordered' ? 'selected' : '' ?>>Ordered (Pending Delivery)</option>
                    <option value="partially_received" <?= $statusFilter === 'partially_received' ? 'selected' : '' ?>>Partially Received</option>
                    <option value="received" <?= $statusFilter === 'received' ? 'selected' : '' ?>>Fully Received</option>
                    <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>

            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary w-100" title="Filter"><i class="bi bi-funnel-fill me-1"></i>Filter</button>
                <a href="<?= e(url('public/purchase_orders.php')) ?>" class="btn btn-sm btn-outline-secondary" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Orders Table -->
<div class="card card-custom shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small">
                    <tr>
                        <th class="ps-3" style="width: 140px;">PO Number</th>
                        <th>Supplier / Vendor</th>
                        <th>Order Date</th>
                        <th>Expected Date</th>
                        <th class="text-center">Items</th>
                        <th class="text-end">Total (<?= e(APP_CURRENCY) ?>)</th>
                        <th class="text-center">Delivery Progress</th>
                        <th class="text-center">Status</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="small">
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-2 d-block mb-2 text-muted"></i>
                                No purchase orders found matching your search criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $po): ?>
                            <?php 
                                $orderedQty = (float)$po['total_ordered_qty'];
                                $receivedQty = (float)$po['total_received_qty'];
                                $pct = ($orderedQty > 0) ? min(100, round(($receivedQty / $orderedQty) * 100)) : 0;
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <a href="<?= e(url('public/po_view.php?id=' . (int)$po['id'])) ?>" class="fw-bold text-decoration-none">
                                        <code><?= e($po['po_number']) ?></code>
                                    </a>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= e($po['company_name']) ?></div>
                                    <div class="text-muted" style="font-size: 0.72rem;">By: <?= e($po['creator_name'] ?? 'Admin') ?></div>
                                </td>
                                <td><?= format_simple_date($po['order_date']) ?></td>
                                <td><?= $po['expected_date'] ? format_simple_date($po['expected_date']) : '-' ?></td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark border">
                                        <?= (int)$po['item_count'] ?> line<?= (int)$po['item_count'] === 1 ? '' : 's' ?>
                                    </span>
                                </td>
                                <td class="text-end fw-bold text-success">
                                    <?= format_currency($po['total_amount']) ?>
                                </td>
                                <td class="text-center" style="width: 140px;">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height: 6px;">
                                            <div class="progress-bar <?= $pct == 100 ? 'bg-success' : 'bg-primary' ?>" role="progressbar" style="width: <?= $pct ?>%;" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                        <span class="text-muted" style="font-size: 0.72rem;"><?= $pct ?>%</span>
                                    </div>
                                    <div class="text-muted" style="font-size: 0.7rem;"><?= number_format($receivedQty, 0) ?> / <?= number_format($orderedQty, 0) ?> rec'd</div>
                                </td>
                                <td class="text-center">
                                    <?php if ($po['status'] === 'received'): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Fully Received</span>
                                    <?php elseif ($po['status'] === 'partially_received'): ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Partially Received</span>
                                    <?php elseif ($po['status'] === 'ordered'): ?>
                                        <span class="badge bg-primary"><i class="bi bi-send me-1"></i>Ordered</span>
                                    <?php elseif ($po['status'] === 'draft'): ?>
                                        <span class="badge bg-secondary">Draft</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Cancelled</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3">
                                    <div class="btn-group btn-group-sm">
                                        <a href="<?= e(url('public/po_view.php?id=' . (int)$po['id'])) ?>" class="btn btn-outline-primary" title="View Order Details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if (has_role(['admin', 'storekeeper']) && in_array($po['status'], ['ordered', 'partially_received'], true)): ?>
                                            <a href="<?= e(url('public/grn_create.php?po_id=' . (int)$po['id'])) ?>" class="btn btn-success" title="Receive Goods (GRN)">
                                                <i class="bi bi-box-arrow-in-down me-1"></i>Receive
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
