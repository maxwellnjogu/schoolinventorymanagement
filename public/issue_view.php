<?php
/**
 * Stock Issue Note Details View
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Stock Issue Details';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/requisition_helpers.php';

require_login();

$user = current_user();
$pdo = Database::getConnection();

if (!$pdo) {
    die("Database connection error: " . Database::getError());
}

$issueId = (int)($_GET['id'] ?? 0);

if ($issueId <= 0) {
    flash_set('danger', 'Invalid Issue ID.');
    redirect(url('public/issues.php'));
}

// Fetch Issue Header
$stmt = $pdo->prepare("
    SELECT si.*, sr.request_number, sr.request_date, sr.status AS req_status,
           d.name AS department_name, d.code AS department_code,
           u.full_name AS issuer_name
    FROM `stock_issues` si
    JOIN `stock_requests` sr ON si.request_id = sr.id
    JOIN `departments` d ON si.department_id = d.id
    LEFT JOIN `users` u ON si.issued_by = u.id
    WHERE si.id = ?
");
$stmt->execute([$issueId]);
$issue = $stmt->fetch();

if (!$issue) {
    flash_set('danger', 'Stock issue note not found.');
    redirect(url('public/issues.php'));
}

// Check department permission
if ($user['role'] === 'department_head') {
    if (!empty($user['department_id']) && (int)$user['department_id'] !== (int)$issue['department_id']) {
        flash_set('danger', 'Access denied. You can only view issue notes for your assigned department.');
        redirect(url('public/unauthorized.php'));
    }
}

// Fetch Issue Line Items
$itemsStmt = $pdo->prepare("
    SELECT sii.*, i.name AS item_name, i.item_code, i.unit_of_measure, i.quantity_on_hand, i.unit_cost
    FROM `stock_issue_items` sii
    JOIN `items` i ON sii.item_id = i.id
    WHERE sii.issue_id = ?
    ORDER BY sii.id ASC
");
$itemsStmt->execute([$issueId]);
$lineItems = $itemsStmt->fetchAll();

$totalQuantity = 0.0;
$totalValuation = 0.0;
foreach ($lineItems as $item) {
    $qty = (float)$item['quantity_issued'];
    $totalQuantity += $qty;
    $totalValuation += ($qty * (float)$item['unit_cost']);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/dashboard.php')) ?>" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= e(url('public/issues.php')) ?>" class="text-decoration-none">Stock Issues</a></li>
                <li class="breadcrumb-item active"><?= e($issue['issue_number']) ?></li>
            </ol>
        </nav>
        <div class="d-flex align-items-center gap-3">
            <h2 class="h3 fw-bold mb-0"><?= e($issue['issue_number']) ?></h2>
            <span class="badge bg-danger-subtle text-danger border border-danger fs-6">
                <i class="bi bi-box-arrow-up-right me-1"></i> Stock Issued
            </span>
        </div>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0 d-flex justify-content-md-end gap-2">
        <a href="<?= e(url('public/issues.php')) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Issues
        </a>
        <a href="<?= e(url('public/request_view.php?id=' . $issue['request_id'])) ?>" class="btn btn-outline-primary">
            <i class="bi bi-file-earmark-text me-1"></i> View Requisition
        </a>
        <button type="button" class="btn btn-outline-dark" onclick="window.print();">
            <i class="bi bi-printer me-1"></i> Print Handover Note
        </button>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Issue Header Card -->
    <div class="col-md-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold text-primary">
                    <i class="bi bi-person-badge me-2"></i>Handover Specification
                </h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="text-muted small">Receiving Department</div>
                        <div class="fw-bold fs-6"><?= e($issue['department_name']) ?></div>
                        <div class="text-muted small">Code: <?= e($issue['department_code']) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Linked Stock Requisition</div>
                        <div class="fw-bold fs-6">
                            <a href="<?= e(url('public/request_view.php?id=' . $issue['request_id'])) ?>" class="text-decoration-none">
                                <?= e($issue['request_number']) ?>
                            </a>
                        </div>
                        <div class="text-muted small">Req Status: <span class="badge <?= e(request_status_badge_class($issue['req_status'])) ?>"><?= e(ucwords(str_replace('_', ' ', $issue['req_status']))) ?></span></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Recipient (Received By)</div>
                        <div class="fw-bold text-dark fs-6"><?= e($issue['issued_to']) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Date Issued</div>
                        <div class="fw-semibold"><?= e(date('d M Y', strtotime($issue['issue_date']))) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Storekeeper (Issued By)</div>
                        <div class="fw-semibold"><?= e($issue['issuer_name'] ?? 'Storekeeper') ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">System Entry Timestamp</div>
                        <div class="text-muted small"><?= e(date('d M Y H:i:s', strtotime($issue['created_at']))) ?></div>
                    </div>
                    <?php if (!empty($issue['remarks'])): ?>
                        <div class="col-12">
                            <div class="text-muted small">Handover Remarks</div>
                            <div class="p-2 bg-light rounded text-secondary small"><?= nl2br(e($issue['remarks'])) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Handover Summary Card -->
    <div class="col-md-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold text-primary">
                    <i class="bi bi-box-seam me-2"></i>Handover Totals
                </h5>
            </div>
            <div class="card-body d-flex flex-column justify-content-between">
                <div>
                    <div class="list-group list-group-flush small">
                        <div class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Total Unique Line Items:</span>
                            <span class="fw-bold"><?= count($lineItems) ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Total Units Issued:</span>
                            <span class="fw-bold text-danger fs-6"><?= number_format($totalQuantity, 1) ?> units</span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Inventory Ledger Status:</span>
                            <span class="badge bg-danger-subtle text-danger">Stock Decremented</span>
                        </div>
                    </div>
                </div>

                <div class="p-3 bg-light rounded mt-3 text-center">
                    <div class="text-muted small mb-1">Estimated Issue Valuation</div>
                    <div class="h3 fw-bold text-primary mb-0"><?= format_currency($totalValuation) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Issued Items Table -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 border-bottom">
        <h5 class="card-title mb-0 fw-bold text-primary">
            <i class="bi bi-list-check me-2"></i>Issued Stock Items
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
                        <th class="text-center">Quantity Handed Over</th>
                        <th class="text-end">Unit Cost</th>
                        <th class="text-end">Total Valuation</th>
                        <th>Remarks</th>
                        <th class="text-end">Current Store Stock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lineItems as $item): 
                        $qty = (float)$item['quantity_issued'];
                        $lineVal = $qty * (float)$item['unit_cost'];
                    ?>
                        <tr>
                            <td><span class="badge bg-light text-dark font-monospace"><?= e($item['item_code']) ?></span></td>
                            <td class="fw-semibold">
                                <a href="<?= e(url('public/item_movements.php?id=' . $item['item_id'])) ?>" class="text-decoration-none">
                                    <?= e($item['item_name']) ?>
                                </a>
                            </td>
                            <td><?= e($item['unit_of_measure']) ?></td>
                            <td class="text-center fw-bold text-danger fs-6">-<?= number_format($qty, 1) ?></td>
                            <td class="text-end"><?= format_currency((float)$item['unit_cost']) ?></td>
                            <td class="text-end fw-semibold text-primary"><?= format_currency($lineVal) ?></td>
                            <td class="text-muted small"><?= e($item['remarks'] ?: '-') ?></td>
                            <td class="text-end">
                                <span class="badge bg-light text-secondary">
                                    Remaining: <?= (float)$item['quantity_on_hand'] ?> <?= e($item['unit_of_measure']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="5" class="text-end">Total Issue Valuation:</td>
                        <td class="text-end text-primary fs-5"><?= format_currency($totalValuation) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Handover Signature Slip (For Print) -->
<div class="card shadow-sm border-0 d-none d-print-block">
    <div class="card-body">
        <h6 class="fw-bold mb-4">Official Handover Sign-Off</h6>
        <div class="row pt-4 text-center">
            <div class="col-6">
                <div class="border-top border-dark pt-2" style="margin-top: 50px;">
                    <strong>Issued By (Storekeeper):</strong> <?= e($issue['issuer_name'] ?? 'Storekeeper') ?><br>
                    <small>Signature & Date</small>
                </div>
            </div>
            <div class="col-6">
                <div class="border-top border-dark pt-2" style="margin-top: 50px;">
                    <strong>Received By (Department):</strong> <?= e($issue['issued_to']) ?><br>
                    <small>Signature & Date</small>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
