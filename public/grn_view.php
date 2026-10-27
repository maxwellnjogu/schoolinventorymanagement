<?php
/**
 * Goods Received Note Details View
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'GRN Details';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/procurement_helpers.php';

require_role(['admin', 'storekeeper', 'auditor']);

$user = current_user();
$pdo = Database::getConnection();

if (!$pdo) {
    die("Database connection error: " . Database::getError());
}

$grnId = (int)($_GET['id'] ?? 0);

if ($grnId <= 0) {
    flash_set('danger', 'Invalid GRN ID.');
    redirect(url('public/grns.php'));
}

// Fetch GRN Header
$stmt = $pdo->prepare("
    SELECT grn.*, po.po_number, po.order_date, po.status AS po_status,
           s.company_name, s.supplier_code, s.contact_person, s.phone, s.email,
           u.full_name AS received_by_name
    FROM `goods_received_notes` grn
    JOIN `purchase_orders` po ON grn.po_id = po.id
    JOIN `suppliers` s ON grn.supplier_id = s.id
    LEFT JOIN `users` u ON grn.received_by = u.id
    WHERE grn.id = ?
");
$stmt->execute([$grnId]);
$grn = $stmt->fetch();

if (!$grn) {
    flash_set('danger', 'Goods Received Note not found.');
    redirect(url('public/grns.php'));
}

// Fetch GRN Items
$itemsStmt = $pdo->prepare("
    SELECT gri.*, i.name AS item_name, i.item_code, i.unit_of_measure, i.quantity_on_hand,
           poi.quantity_ordered, poi.quantity_received AS total_po_received
    FROM `goods_received_items` gri
    JOIN `items` i ON gri.item_id = i.id
    JOIN `purchase_order_items` poi ON gri.po_item_id = poi.id
    WHERE gri.grn_id = ?
    ORDER BY gri.id ASC
");
$itemsStmt->execute([$grnId]);
$grnItems = $itemsStmt->fetchAll();

$totalQty = 0.0;
$totalValuation = 0.0;
foreach ($grnItems as $item) {
    $totalQty += (float)$item['quantity_received'];
    $totalValuation += (float)$item['total_cost'];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/dashboard.php')) ?>" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= e(url('public/grns.php')) ?>" class="text-decoration-none">Goods Received Notes</a></li>
                <li class="breadcrumb-item active"><?= e($grn['grn_number']) ?></li>
            </ol>
        </nav>
        <div class="d-flex align-items-center gap-3">
            <h2 class="h3 fw-bold mb-0"><?= e($grn['grn_number']) ?></h2>
            <span class="badge bg-success-subtle text-success border border-success fs-6">
                <i class="bi bi-check-circle-fill me-1"></i> Stock Received
            </span>
        </div>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0 d-flex justify-content-md-end gap-2">
        <a href="<?= e(url('public/grns.php')) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to GRNs
        </a>
        <a href="<?= e(url('public/po_view.php?id=' . $grn['po_id'])) ?>" class="btn btn-outline-primary">
            <i class="bi bi-file-earmark-text me-1"></i> View PO
        </a>
        <button type="button" class="btn btn-outline-dark" onclick="window.print();">
            <i class="bi bi-printer me-1"></i> Print Note
        </button>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- GRN Header Card -->
    <div class="col-md-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold text-primary">
                    <i class="bi bi-truck me-2"></i>Delivery Specification
                </h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="text-muted small">Supplier</div>
                        <div class="fw-bold fs-6"><?= e($grn['company_name']) ?></div>
                        <div class="text-muted small">Code: <?= e($grn['supplier_code']) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Linked Purchase Order</div>
                        <div class="fw-bold fs-6">
                            <a href="<?= e(url('public/po_view.php?id=' . $grn['po_id'])) ?>" class="text-decoration-none">
                                <?= e($grn['po_number']) ?>
                            </a>
                        </div>
                        <div class="text-muted small">PO Status: <span class="badge <?= e(po_status_badge_class($grn['po_status'])) ?>"><?= e(ucwords(str_replace('_', ' ', $grn['po_status']))) ?></span></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Delivery Note / Invoice #</div>
                        <div class="fw-bold font-monospace fs-6"><?= e($grn['delivery_note_number'] ?: 'Not provided') ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Date Received</div>
                        <div class="fw-semibold"><?= e(date('d M Y', strtotime($grn['received_date']))) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Received By Officer</div>
                        <div class="fw-semibold"><?= e($grn['received_by_name'] ?? 'Storekeeper') ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">System Entry Timestamp</div>
                        <div class="text-muted small"><?= e(date('d M Y H:i:s', strtotime($grn['created_at']))) ?></div>
                    </div>
                    <?php if (!empty($grn['remarks'])): ?>
                        <div class="col-12">
                            <div class="text-muted small">Delivery Remarks</div>
                            <div class="p-2 bg-light rounded text-secondary small"><?= nl2br(e($grn['remarks'])) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Batch Summary Card -->
    <div class="col-md-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold text-primary">
                    <i class="bi bi-calculator me-2"></i>Batch Delivery Totals
                </h5>
            </div>
            <div class="card-body d-flex flex-column justify-content-between">
                <div>
                    <div class="list-group list-group-flush small">
                        <div class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Total Unique Line Items:</span>
                            <span class="fw-bold"><?= count($grnItems) ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Total Items Received:</span>
                            <span class="fw-bold text-success fs-6"><?= number_format($totalQty, 1) ?> units</span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Inventory Ledger Status:</span>
                            <span class="badge bg-success-subtle text-success">Updated & In Stock</span>
                        </div>
                    </div>
                </div>

                <div class="p-3 bg-light rounded mt-3 text-center">
                    <div class="text-muted small mb-1">Delivered Batch Valuation</div>
                    <div class="h3 fw-bold text-primary mb-0"><?= format_currency($totalValuation) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Received Items Table -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 border-bottom">
        <h5 class="card-title mb-0 fw-bold text-primary">
            <i class="bi bi-box-seam me-2"></i>Received Physical Stock Items
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
                        <th class="text-center">Delivered Qty</th>
                        <th class="text-end">Unit Cost</th>
                        <th class="text-end">Total Batch Cost</th>
                        <th>Remarks</th>
                        <th class="text-end">Store Stock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grnItems as $item): ?>
                        <tr>
                            <td><span class="badge bg-light text-dark font-monospace"><?= e($item['item_code']) ?></span></td>
                            <td class="fw-semibold">
                                <a href="<?= e(url('public/item_movements.php?id=' . $item['item_id'])) ?>" class="text-decoration-none">
                                    <?= e($item['item_name']) ?>
                                </a>
                            </td>
                            <td><?= e($item['unit_of_measure']) ?></td>
                            <td class="text-center fw-bold text-success fs-6">+<?= number_format((float)$item['quantity_received'], 1) ?></td>
                            <td class="text-end"><?= format_currency((float)$item['unit_cost']) ?></td>
                            <td class="text-end fw-semibold text-primary"><?= format_currency((float)$item['total_cost']) ?></td>
                            <td class="text-muted small"><?= e($item['remarks'] ?: '-') ?></td>
                            <td class="text-end">
                                <span class="badge bg-light text-secondary">
                                    Current: <?= (float)$item['quantity_on_hand'] ?> <?= e($item['unit_of_measure']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="5" class="text-end">Total Batch Delivery Valuation:</td>
                        <td class="text-end text-primary fs-5"><?= format_currency($totalValuation) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
