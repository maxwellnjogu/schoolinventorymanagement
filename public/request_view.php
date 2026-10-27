<?php
/**
 * Department Stock Request Details & Review
 * Supports Full/Partial Approvals and Rejections without touching stock
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Stock Request Details';

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

$requestId = (int)($_GET['id'] ?? 0);

if ($requestId <= 0) {
    flash_set('danger', 'Invalid Request ID.');
    redirect(url('public/requests.php'));
}

// Fetch Request Header
$stmt = $pdo->prepare("
    SELECT sr.*, d.name AS department_name, d.code AS department_code,
           u.full_name AS requester_name, u.role AS requester_role,
           app.full_name AS approver_name
    FROM `stock_requests` sr
    JOIN `departments` d ON sr.department_id = d.id
    LEFT JOIN `users` u ON sr.requested_by = u.id
    LEFT JOIN `users` app ON sr.approved_by = app.id
    WHERE sr.id = ?
");
$stmt->execute([$requestId]);
$req = $stmt->fetch();

if (!$req) {
    flash_set('danger', 'Stock request not found.');
    redirect(url('public/requests.php'));
}

// Enforce role permission: department head only views their department's requests
if ($user['role'] === 'department_head') {
    if (!empty($user['department_id']) && (int)$user['department_id'] !== (int)$req['department_id']) {
        flash_set('danger', 'Access denied. You can only view requisitions for your assigned department.');
        redirect(url('public/unauthorized.php'));
    }
}

$errors = [];

// Handle Approval / Rejection Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_role(['admin', 'storekeeper']);

    if (!csrf_verify()) {
        $errors[] = 'Security token invalid or expired. Please submit the review again.';
    } else {
        $action = trim($_POST['action']);
        $reason = trim($_POST['rejection_reason'] ?? '');
        $approvedQtys = $_POST['quantity_approved'] ?? [];

        try {
            $res = review_stock_request($pdo, $requestId, $action, $reason, $approvedQtys, (int)$user['id']);
            if ($action === 'approve') {
                flash_set('success', "Stock Request {$req['request_number']} approved successfully. You may now issue physical items.");
            } else {
                flash_set('warning', "Stock Request {$req['request_number']} has been rejected.");
            }
            redirect(url("public/request_view.php?id={$requestId}"));
        } catch (Exception $e) {
            $errors[] = 'Review Error: ' . $e->getMessage();
        }
    }
}

// Fetch Request Line Items
$itemsStmt = $pdo->prepare("
    SELECT sri.*, i.name AS item_name, i.item_code, i.unit_of_measure, i.quantity_on_hand
    FROM `stock_request_items` sri
    JOIN `items` i ON sri.item_id = i.id
    WHERE sri.request_id = ?
    ORDER BY sri.id ASC
");
$itemsStmt->execute([$requestId]);
$lineItems = $itemsStmt->fetchAll();

// Fetch Linked Stock Issue Notes
$issuesStmt = $pdo->prepare("
    SELECT si.*, u.full_name AS issued_by_name,
           COUNT(sii.id) AS items_count,
           SUM(sii.quantity_issued) AS total_issued_quantity
    FROM `stock_issues` si
    LEFT JOIN `stock_issue_items` sii ON si.id = sii.issue_id
    LEFT JOIN `users` u ON si.issued_by = u.id
    WHERE si.request_id = ?
    GROUP BY si.id
    ORDER BY si.id DESC
");
$issuesStmt->execute([$requestId]);
$linkedIssues = $issuesStmt->fetchAll();

// Metrics
$totalRequested = 0.0;
$totalApproved = 0.0;
$totalIssued = 0.0;
foreach ($lineItems as $line) {
    $totalRequested += (float)$line['quantity_requested'];
    $totalApproved += (float)$line['quantity_approved'];
    $totalIssued += (float)$line['quantity_issued'];
}

$canReview = ($req['status'] === 'pending' && has_role(['admin', 'storekeeper']));
$canIssue = (in_array($req['status'], ['approved', 'partially_issued'], true) && has_role(['admin', 'storekeeper']));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/dashboard.php')) ?>" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= e(url('public/requests.php')) ?>" class="text-decoration-none">Stock Requisitions</a></li>
                <li class="breadcrumb-item active"><?= e($req['request_number']) ?></li>
            </ol>
        </nav>
        <div class="d-flex align-items-center gap-3">
            <h2 class="h3 fw-bold mb-0"><?= e($req['request_number']) ?></h2>
            <span class="badge <?= e(request_status_badge_class($req['status'])) ?> fs-6">
                <?= e(ucwords(str_replace('_', ' ', $req['status']))) ?>
            </span>
        </div>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0 d-flex justify-content-md-end gap-2">
        <a href="<?= e(url('public/requests.php')) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <button type="button" class="btn btn-outline-dark" onclick="window.print();">
            <i class="bi bi-printer me-1"></i> Print
        </button>
        <?php if ($canIssue): ?>
            <a href="<?= e(url('public/issue_create.php?request_id=' . $req['id'])) ?>" class="btn btn-success">
                <i class="bi bi-box-arrow-up-right me-1"></i> Issue Physical Stock
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i>Review Error:</div>
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <!-- Requisition Overview Card -->
    <div class="col-md-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold text-primary">
                    <i class="bi bi-file-earmark-text me-2"></i>Requisition Details
                </h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="text-muted small">Department</div>
                        <div class="fw-bold fs-6"><?= e($req['department_name']) ?></div>
                        <div class="text-muted small">Code: <?= e($req['department_code']) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Request Date</div>
                        <div class="fw-semibold"><?= e(date('d M Y', strtotime($req['request_date']))) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Requested By</div>
                        <div class="fw-semibold"><?= e($req['requester_name'] ?? 'User') ?></div>
                        <div class="text-muted small"><?= e(role_display_name($req['requester_role'] ?? 'department_head')) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Current Status</div>
                        <div>
                            <span class="badge <?= e(request_status_badge_class($req['status'])) ?>">
                                <?= e(ucwords(str_replace('_', ' ', $req['status']))) ?>
                            </span>
                        </div>
                    </div>

                    <?php if ($req['status'] === 'rejected'): ?>
                        <div class="col-12">
                            <div class="p-3 bg-danger-subtle text-danger rounded border border-danger">
                                <div class="fw-bold"><i class="bi bi-x-circle-fill me-1"></i> Requisition Rejected</div>
                                <div class="small mt-1"><strong>Reason:</strong> <?= e($req['rejection_reason'] ?: 'No specific reason provided.') ?></div>
                                <div class="small text-muted mt-1">Reviewed by <?= e($req['approver_name'] ?? 'Storekeeper') ?> on <?= e(date('d M Y H:i', strtotime($req['approval_date']))) ?></div>
                            </div>
                        </div>
                    <?php elseif ($req['approved_by']): ?>
                        <div class="col-12">
                            <div class="p-3 bg-success-subtle text-success rounded border border-success">
                                <div class="fw-bold"><i class="bi bi-check-circle-fill me-1"></i> Requisition Approved</div>
                                <div class="small mt-1">Reviewed and approved by <?= e($req['approver_name']) ?> on <?= e(date('d M Y H:i', strtotime($req['approval_date']))) ?>.</div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($req['remarks'])): ?>
                        <div class="col-12">
                            <div class="text-muted small">Department Purpose / Justification</div>
                            <div class="p-2 bg-light rounded text-secondary small"><?= nl2br(e($req['remarks'])) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Fulfillment Status Card -->
    <div class="col-md-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold text-primary">
                    <i class="bi bi-pie-chart me-2"></i>Issuing Status
                </h5>
            </div>
            <div class="card-body d-flex flex-column justify-content-between">
                <div>
                    <div class="list-group list-group-flush small">
                        <div class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Total Line Items:</span>
                            <span class="fw-bold"><?= count($lineItems) ?></span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Total Requested Qty:</span>
                            <span class="fw-bold"><?= number_format($totalRequested, 1) ?> units</span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Total Approved Qty:</span>
                            <span class="fw-bold text-primary"><?= number_format($totalApproved, 1) ?> units</span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Total Physically Issued:</span>
                            <span class="fw-bold text-success"><?= number_format($totalIssued, 1) ?> units</span>
                        </div>
                        <div class="list-group-item d-flex justify-content-between px-0 py-2">
                            <span class="text-muted">Remaining to Issue:</span>
                            <span class="fw-bold text-warning"><?= number_format(max(0.0, $totalApproved - $totalIssued), 1) ?> units</span>
                        </div>
                    </div>
                </div>

                <div class="p-3 bg-light rounded mt-3 text-center">
                    <?php if ($canIssue): ?>
                        <a href="<?= e(url('public/issue_create.php?request_id=' . $req['id'])) ?>" class="btn btn-success w-100">
                            <i class="bi bi-box-arrow-up-right me-1"></i> Issue Available Stock Now
                        </a>
                    <?php elseif ($req['status'] === 'issued'): ?>
                        <div class="text-success fw-bold"><i class="bi bi-check-all fs-4 d-block"></i> Requisition Completed & Fully Issued</div>
                    <?php elseif ($req['status'] === 'pending'): ?>
                        <div class="text-warning fw-semibold"><i class="bi bi-hourglass-split me-1"></i> Awaiting Administrative Approval</div>
                    <?php else: ?>
                        <div class="text-muted small">No pending physical actions for this requisition.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- If Pending & Admin/Storekeeper: Approval/Rejection Panel -->
<?php if ($canReview): ?>
    <div class="card shadow-sm border-warning mb-4">
        <div class="card-header bg-warning-subtle py-3 border-bottom border-warning d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0 fw-bold text-dark">
                <i class="bi bi-person-check-fill me-2 text-warning"></i>Administrative Approval Panel
            </h5>
            <span class="badge bg-warning text-dark">Action Required</span>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-3">
                <i class="bi bi-shield-lock-fill text-primary me-1"></i> <strong>Business Rule:</strong> You can approve items in full or grant partial quantities. Approving will <em>not</em> deduct stock. Store inventory will only be reduced when the physical issue is recorded.
            </p>

            <form action="<?= e(url('public/request_view.php?id=' . $req['id'])) ?>" method="POST" id="reviewForm">
                <?= csrf_input() ?>

                <div class="table-responsive mb-3">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Item</th>
                                <th class="text-center" style="width: 15%;">Available in Store</th>
                                <th class="text-center" style="width: 15%;">Requested Qty</th>
                                <th class="text-center" style="width: 25%;">Approved Qty (Can Partial Approve)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lineItems as $item): 
                                $stock = (float)$item['quantity_on_hand'];
                                $reqQty = (float)$item['quantity_requested'];
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= e($item['item_name']) ?></div>
                                        <span class="badge bg-light text-dark font-monospace"><?= e($item['item_code']) ?></span>
                                        <span class="text-muted small">(<?= e($item['unit_of_measure']) ?>)</span>
                                    </td>
                                    <td class="text-center <?= ($stock < $reqQty) ? 'text-danger fw-bold' : 'text-success fw-semibold' ?>">
                                        <?= $stock ?> <?= e($item['unit_of_measure']) ?>
                                    </td>
                                    <td class="text-center fw-bold fs-6">
                                        <?= $reqQty ?>
                                    </td>
                                    <td>
                                        <div class="input-group">
                                            <input type="number" step="0.01" min="0" max="<?= $reqQty ?>" 
                                                   name="quantity_approved[<?= (int)$item['id'] ?>]" 
                                                   class="form-control text-center fw-bold" 
                                                   value="<?= $reqQty ?>" required>
                                            <span class="input-group-text"><?= e($item['unit_of_measure']) ?></span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Rejection Reason Input (Collapsible) -->
                <div class="collapse mb-3" id="rejectionSection">
                    <div class="p-3 bg-light rounded border border-danger">
                        <label for="rejection_reason" class="form-label fw-bold text-danger">Rejection Reason <span class="text-danger">*</span></label>
                        <textarea name="rejection_reason" id="rejection_reason" rows="2" class="form-control" 
                                  placeholder="Provide clear reasons for rejecting this requisition (e.g. Out of stock, budget limit reached, request duplicate)."></textarea>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center">
                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#rejectionSection" id="btnToggleReject">
                        <i class="bi bi-x-circle me-1"></i> Reject Requisition
                    </button>
                    <div class="d-flex gap-2">
                        <button type="submit" name="action" value="reject" class="btn btn-danger d-none" id="btnConfirmReject">
                            <i class="bi bi-x-circle-fill me-1"></i> Confirm Rejection
                        </button>
                        <button type="submit" name="action" value="approve" class="btn btn-success px-4" id="btnConfirmApprove">
                            <i class="bi bi-check-circle-fill me-1"></i> Approve Requisition
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const toggleReject = document.getElementById('btnToggleReject');
        const rejectBtn = document.getElementById('btnConfirmReject');
        const approveBtn = document.getElementById('btnConfirmApprove');
        const rejectSection = document.getElementById('rejectionSection');
        const rejectReason = document.getElementById('rejection_reason');

        rejectSection.addEventListener('show.bs.collapse', function() {
            rejectBtn.classList.remove('d-none');
            approveBtn.classList.add('d-none');
            rejectReason.setAttribute('required', 'required');
        });

        rejectSection.addEventListener('hide.bs.collapse', function() {
            rejectBtn.classList.add('d-none');
            approveBtn.classList.remove('d-none');
            rejectReason.removeAttribute('required');
        });
    });
    </script>
<?php endif; ?>

<!-- Line Items List -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 border-bottom">
        <h5 class="card-title mb-0 fw-bold text-primary">
            <i class="bi bi-list-check me-2"></i>Requested Line Items
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
                        <th class="text-center">Requested Qty</th>
                        <th class="text-center">Approved Qty</th>
                        <th class="text-center">Issued Qty</th>
                        <th class="text-center">Remaining to Issue</th>
                        <th class="text-end">Store Available Stock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lineItems as $item): 
                        $reqQ = (float)$item['quantity_requested'];
                        $appQ = (float)$item['quantity_approved'];
                        $issQ = (float)$item['quantity_issued'];
                        $remQ = max(0.0, $appQ - $issQ);
                        $storeStock = (float)$item['quantity_on_hand'];
                    ?>
                        <tr>
                            <td><span class="badge bg-light text-dark font-monospace"><?= e($item['item_code']) ?></span></td>
                            <td class="fw-semibold">
                                <a href="<?= e(url('public/item_movements.php?id=' . $item['item_id'])) ?>" class="text-decoration-none">
                                    <?= e($item['item_name']) ?>
                                </a>
                                <?php if (!empty($item['remarks'])): ?>
                                    <div class="text-muted small">Note: <?= e($item['remarks']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($item['unit_of_measure']) ?></td>
                            <td class="text-center fw-bold"><?= number_format($reqQ, 1) ?></td>
                            <td class="text-center fw-bold text-primary"><?= number_format($appQ, 1) ?></td>
                            <td class="text-center fw-bold text-success"><?= number_format($issQ, 1) ?></td>
                            <td class="text-center fw-bold <?= ($remQ > 0) ? 'text-warning' : 'text-muted' ?>">
                                <?= number_format($remQ, 1) ?>
                            </td>
                            <td class="text-end">
                                <span class="badge <?= ($storeStock < $remQ) ? 'bg-danger-subtle text-danger border border-danger' : 'bg-light text-dark' ?>">
                                    <?= $storeStock ?> <?= e($item['unit_of_measure']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Linked Stock Issue Notes History -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fw-bold text-primary">
            <i class="bi bi-box-arrow-up-right me-2"></i>Physical Stock Issue Notes
        </h5>
        <?php if ($canIssue): ?>
            <a href="<?= e(url('public/issue_create.php?request_id=' . $req['id'])) ?>" class="btn btn-sm btn-success">
                <i class="bi bi-plus-circle me-1"></i> Issue Stock
            </a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($linkedIssues)): ?>
            <div class="p-4 text-center text-muted">
                <i class="bi bi-inbox display-6 d-block mb-2 text-secondary opacity-50"></i>
                No physical issues have been recorded yet for this requisition.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Issue Number</th>
                            <th>Issue Date</th>
                            <th>Issued To (Recipient)</th>
                            <th>Storekeeper (Issuer)</th>
                            <th class="text-center">Total Quantity Issued</th>
                            <th>Remarks</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($linkedIssues as $issue): ?>
                            <tr>
                                <td class="fw-bold font-monospace text-primary">
                                    <a href="<?= e(url('public/issue_view.php?id=' . $issue['id'])) ?>" class="text-decoration-none">
                                        <?= e($issue['issue_number']) ?>
                                    </a>
                                </td>
                                <td><?= e(date('d M Y', strtotime($issue['issue_date']))) ?></td>
                                <td class="fw-semibold"><?= e($issue['issued_to']) ?></td>
                                <td><?= e($issue['issued_by_name'] ?? 'Storekeeper') ?></td>
                                <td class="text-center fw-bold text-success"><?= number_format((float)$issue['total_issued_quantity'], 1) ?> units</td>
                                <td class="text-muted small"><?= e($issue['remarks'] ?: '-') ?></td>
                                <td class="text-end">
                                    <a href="<?= e(url('public/issue_view.php?id=' . $issue['id'])) ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye me-1"></i> View Issue Note
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
