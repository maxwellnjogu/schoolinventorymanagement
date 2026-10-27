<?php
/**
 * View Purchase Order Details
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Purchase Order Details';

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

$poId = (int)($_GET['id'] ?? 0);

if ($poId <= 0) {
    flash_set('danger', 'Invalid Purchase Order ID.');
    redirect(url('public/purchase_orders.php'));
}

// Handle confirming draft PO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_order') {
    require_role(['admin', 'storekeeper']);
    if (csrf_verify()) {
        $pdo->prepare("UPDATE `purchase_orders` SET `status` = 'ordered', `updated_at` = NOW() WHERE `id` = ? AND `status` = 'draft'")
            ->execute([$poId]);
        flash_set('success', 'Purchase order status updated to Ordered (Confirmed).');
        redirect(url("public/po_view.php?id={$poId}"));
    }
}

// Fetch PO Header
$stmt = $pdo->prepare("
    SELECT po.*, s.company_name, s.supplier_code, s.contact_person, s.phone, s.email, s.address,
           u.full_name AS creator_name
    FROM `purchase_orders` po
    JOIN `suppliers` s ON po.supplier_id = s.id
    LEFT JOIN `users` u ON po.created_by = u.id
    WHERE po.id = ?
");
$stmt->execute([$poId]);
$po = $stmt->fetch();

if (!$po) {
    flash_set('danger', 'Purchase Order not found.');
    redirect(url('public/purchase_orders.php'));
}

// Fetch PO Line Items
$itemsStmt = $pdo->prepare("
    SELECT poi.*, i.name AS item_name, i.item_code, i.unit_of_measure, i.quantity_on_hand
    FROM `purchase_order_items` poi
    JOIN `items` i ON poi.item_id = i.id
    WHERE poi.po_id = ?
    ORDER BY poi.id ASC
");
$itemsStmt->execute([$poId]);
$lineItems = $itemsStmt->fetchAll();

// Fetch Linked GRNs
$grnStmt = $pdo->prepare("
    SELECT grn.*, u.full_name AS received_by_name,
           COUNT(gri.id) AS items_received_count,
           SUM(gri.quantity_received) AS total_received_quantity
    FROM `goods_received_notes` grn
    LEFT JOIN `goods_received_items` gri ON grn.id = gri.grn_id
    LEFT JOIN `users` u ON grn.received_by = u.id
    WHERE grn.po_id = ?
    GROUP BY grn.id
    ORDER BY grn.id DESC
");
$grnStmt->execute([$poId]);
$linkedGrns = $grnStmt->fetchAll();

// Calculate total metrics
$totalOrderedQty = 0.0;
$totalReceivedQty = 0.0;
foreach ($lineItems as $line) {
    $totalOrderedQty += (float)$line['quantity_ordered'];
    $totalReceivedQty += (float)$line['quantity_received'];
}
$outstandingTotalQty = max(0.0, $totalOrderedQty - $totalReceivedQty);
$deliveryPercent = ($totalOrderedQty > 0) ? min(100.0, round(($totalReceivedQty / $totalOrderedQty) * 100, 1)) : 0.0;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/dashboard.php')) ?>" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= e(url('public/purchase_orders.php')) ?>" class="text-decoration-none">Purchase Orders</a></li>
                <li class="breadcrumb-item active"><?= e($po['po_number']) ?></li>
            </ol>
        </nav>
        <div class="d-flex align-items-center gap-3">
            <h2 class="h3 fw-bold mb-0"><?= e($po['po_number']) ?></h2>
            <span class="badge <?= e(po_status_badge_class($po['status'])) ?> fs-6">
                <?= e(ucwords(str_replace('_', ' ', $po['status']))) ?>
            </span>
        </div>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0 d-flex justify-content-md-end gap-2">
        <a href="<?= e(url('public/purchase_orders.php')) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <button type="button" class="btn btn-outline-dark" onclick="window.print();">
            <i class="bi bi-printer me-1"></i> Print
        </button>
        <?php if ($po['status'] === 'draft' && has_role(['admin', 'storekeeper'])): ?>
            <form action="<?= e(url('public/po_view.php?id=' . $po['id'])) ?>" method="POST" class="d-inline">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="confirm_order">
                <button type="submit" class="btn btn-success" onclick="return confirm('Confirm and dispatch this order?');">
                    <i class="bi bi-send me-1"></i> Confirm Order
                </button>
            </form>
        <?php endif; ?>
        <?php if (in_array($po['status'], ['ordered', 'partially_received'], true) && has_role(['admin', 'storekeeper'])): ?>
            <a href="<?= e(url('public/grn_create.php?po_id=' . $po['id'])) ?>" class="btn btn-primary">
                <i class="bi bi-box-arrow-in-down me-1"></i> Receive Goods (GRN)
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- PO Header Card -->
    <div class="col-md-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold text-primary">
                    <i class="bi bi-building me-2"></i>Supplier & Delivery Details
                </h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="text-muted small">Supplier Company</div>
                        <div class="fw-bold fs-6"><?= e($po['company_name']) ?></div>
                        <div class="text-muted small">Code: <span class="badge bg-light text-dark"><?= e($po['supplier_code']) ?></span></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Contact Person</div>
                        <div><?= e($po['contact_person'] ?: 'N/A') ?></div>
                        <div class="text-muted small"><?= e($po['phone'] ?: '') ?> <?= e($po['email'] ? "({$po['email']})" : '') ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Order Date</div>
                        <div class="fw-semibold"><?= e(date('d M Y', strtotime($po['order_date']))) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Expected Delivery</div>
                        <div class="fw-semibold"><?= $po['expected_date'] ? e(date('d M Y', strtotime($po['expected_date']))) : '<span class="text-muted">Not specified</span>' ?></div>
                    </div>
                    <div class="col-12">
                        <div class="text-muted small">Created By</div>
                        <div><?= e($po['creator_name'] ?? 'System') ?> on <?= e(date('d M Y H:i', strtotime($po['created_at']))) ?></div>
                    </div>
                    <?php if (!empty($po['notes'])): ?>
                        <div class="col-12">
                            <div class="text-muted small">Instructions / Delivery Terms</div>
                            <div class="p-2 bg-light rounded text-secondary small"><?= nl2br(e($po['notes'])) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Fulfillment Summary Card -->
    <div class="col-md-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold text-primary">
                    <i class="bi bi-pie-chart me-2"></i>Fulfillment Progress
                </h5>
            </div>
            <div class="card-body d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted small">Overall Received</span>
                        <span class="fw-bold text-primary"><?= $deliveryPercent ?>%</span>
                    </div>
                    <div class="progress mb-3" style="height: 12px;">
                        <div class="progress-bar <?= ($deliveryPercent >= 100) ? 'bg-success' : 'bg-primary' ?>" 
                             role="progressbar" style="width: <?= $deliveryPercent ?>%;" 
                             aria-valuenow="<?= $deliveryPercent ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>

                    <div class="list-group list-group-flush small">
                        <div class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Total Ordered Items:</span>
                            <span class="fw-bold"><?= number_format($totalOrderedQty, 1) ?> units</span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Total Received Items:</span>
                            <span class="fw-bold text-success"><?= number_format($totalReceivedQty, 1) ?> units</span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Outstanding Quantity:</span>
                            <span class="fw-bold text-danger"><?= number_format($outstandingTotalQty, 1) ?> units</span>
                        </div>
                    </div>
                </div>

                <div class="p-3 bg-light rounded mt-3 text-center">
                    <div class="text-muted small mb-1">Total Order Valuation</div>
                    <div class="h3 fw-bold text-primary mb-0"><?= format_currency((float)$po['total_amount']) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Line Items Details Table -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 border-bottom">
        <h5 class="card-title mb-0 fw-bold text-primary">
            <i class="bi bi-list-ol me-2"></i>Order Line Items & Delivery Status
        </h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Item Code</th>
                        <th>Item Description</th>
                        <th>Unit</th>
                        <th class="text-center">Ordered Qty</th>
                        <th class="text-center">Received Qty</th>
                        <th class="text-center">Outstanding Qty</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Line Total</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lineItems as $line): 
                        $ord = (float)$line['quantity_ordered'];
                        $rec = (float)$line['quantity_received'];
                        $out = max(0.0, $ord - $rec);
                        $isComplete = ($rec >= $ord);
                        $isPartial = ($rec > 0 && $rec < $ord);
                    ?>
                        <tr>
                            <td><span class="badge bg-light text-dark font-monospace"><?= e($line['item_code']) ?></span></td>
                            <td class="fw-semibold">
                                <a href="<?= e(url('public/item_movements.php?id=' . $line['item_id'])) ?>" class="text-decoration-none">
                                    <?= e($line['item_name']) ?>
                                </a>
                                <div class="text-muted small">Current store stock: <?= (float)$line['quantity_on_hand'] ?> <?= e($line['unit_of_measure']) ?></div>
                            </td>
                            <td><?= e($line['unit_of_measure']) ?></td>
                            <td class="text-center fw-bold"><?= number_format($ord, 1) ?></td>
                            <td class="text-center fw-bold text-success"><?= number_format($rec, 1) ?></td>
                            <td class="text-center fw-bold <?= ($out > 0) ? 'text-danger' : 'text-muted' ?>"><?= number_format($out, 1) ?></td>
                            <td class="text-end"><?= format_currency((float)$line['unit_price']) ?></td>
                            <td class="text-end fw-semibold"><?= format_currency((float)$line['total_price']) ?></td>
                            <td class="text-center">
                                <?php if ($isComplete): ?>
                                    <span class="badge bg-success-subtle text-success border border-success">Completed</span>
                                <?php elseif ($isPartial): ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning">Partial</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary">Pending</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="7" class="text-end">Total Order Valuation:</td>
                        <td class="text-end text-primary fs-5"><?= format_currency((float)$po['total_amount']) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Linked Goods Received Notes (GRN) -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fw-bold text-primary">
            <i class="bi bi-box-seam-fill me-2"></i>Goods Received Notes (GRN History)
        </h5>
        <?php if (in_array($po['status'], ['ordered', 'partially_received'], true) && has_role(['admin', 'storekeeper'])): ?>
            <a href="<?= e(url('public/grn_create.php?po_id=' . $po['id'])) ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-circle me-1"></i> Record Delivery
            </a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($linkedGrns)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-truck display-6 d-block mb-2 text-secondary opacity-50"></i>
                No goods received yet against this purchase order.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>GRN Number</th>
                            <th>Received Date</th>
                            <th>Delivery Note #</th>
                            <th>Received By</th>
                            <th class="text-center">Total Qty Received</th>
                            <th>Remarks</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($linkedGrns as $grn): ?>
                            <tr>
                                <td class="fw-bold font-monospace text-primary"><?= e($grn['grn_number']) ?></td>
                                <td><?= e(date('d M Y', strtotime($grn['received_date']))) ?></td>
                                <td><?= e($grn['delivery_note_number'] ?: '<span class="text-muted">None</span>') ?></td>
                                <td><?= e($grn['received_by_name'] ?? 'Storekeeper') ?></td>
                                <td class="text-center fw-bold text-success"><?= number_format((float)$grn['total_received_quantity'], 1) ?> units</td>
                                <td class="text-muted small"><?= e($grn['remarks'] ?: '-') ?></td>
                                <td class="text-end">
                                    <a href="<?= e(url('public/grn_view.php?id=' . $grn['id'])) ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye me-1"></i> View GRN
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
