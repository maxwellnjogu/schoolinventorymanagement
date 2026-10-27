<?php
/**
 * Create Physical Stock Issue Note
 * Validates store stock, reduces inventory atomically, and marks requisition status
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Issue Stock';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/requisition_helpers.php';

require_role(['admin', 'storekeeper']);

$user = current_user();
$pdo = Database::getConnection();

if (!$pdo) {
    die("Database connection error: " . Database::getError());
}

$requestId = (int)($_GET['request_id'] ?? $_POST['request_id'] ?? 0);
$errors = [];

// Eligible requests for stock issuing
$eligibleRequests = $pdo->query("
    SELECT sr.id, sr.request_number, sr.request_date, d.name AS department_name
    FROM `stock_requests` sr
    JOIN `departments` d ON sr.department_id = d.id
    WHERE sr.status IN ('approved', 'partially_issued')
    ORDER BY sr.id DESC
")->fetchAll();

$selectedReq = null;
$reqItems = [];

if ($requestId > 0) {
    $stmt = $pdo->prepare("
        SELECT sr.*, d.name AS department_name, d.code AS department_code,
               u.full_name AS requester_name
        FROM `stock_requests` sr
        JOIN `departments` d ON sr.department_id = d.id
        LEFT JOIN `users` u ON sr.requested_by = u.id
        WHERE sr.id = ?
    ");
    $stmt->execute([$requestId]);
    $selectedReq = $stmt->fetch();

    if ($selectedReq) {
        $itemsStmt = $pdo->prepare("
            SELECT sri.*, i.name AS item_name, i.item_code, i.unit_of_measure, i.quantity_on_hand
            FROM `stock_request_items` sri
            JOIN `items` i ON sri.item_id = i.id
            WHERE sri.request_id = ?
            ORDER BY sri.id ASC
        ");
        $itemsStmt->execute([$requestId]);
        $reqItems = $itemsStmt->fetchAll();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Security token invalid or expired. Please submit the form again.';
    } elseif (!$selectedReq) {
        $errors[] = 'Please select a valid approved stock request.';
    } elseif (!in_array($selectedReq['status'], ['approved', 'partially_issued'], true)) {
        $errors[] = "Cannot issue stock for request in '{$selectedReq['status']}' status.";
    } else {
        $issuedTo = trim($_POST['issued_to'] ?? '');
        $issueDate = trim($_POST['issue_date'] ?? date('Y-m-d'));
        $remarks = trim($_POST['remarks'] ?? '');

        $reqItemIds = $_POST['request_item_id'] ?? [];
        $itemIds = $_POST['item_id'] ?? [];
        $issueQtys = $_POST['quantity_issued'] ?? [];
        $lineRemarks = $_POST['line_remarks'] ?? [];

        $issuedLines = [];
        $hasAnyQty = false;

        if (is_array($reqItemIds) && count($reqItemIds) > 0) {
            for ($i = 0; $i < count($reqItemIds); $i++) {
                $sriId = (int)($reqItemIds[$i] ?? 0);
                $itemId = (int)($itemIds[$i] ?? 0);
                $qty = (float)($issueQtys[$i] ?? 0.0);
                $lRemark = trim($lineRemarks[$i] ?? '');

                if ($qty > 0) {
                    $hasAnyQty = true;
                    $issuedLines[] = [
                        'request_item_id' => $sriId,
                        'item_id'         => $itemId,
                        'quantity_issued' => $qty,
                        'remarks'         => $lRemark
                    ];
                }
            }
        }

        if (empty($issuedTo)) {
            $errors[] = 'Please specify the recipient receiving the stock (e.g. Teacher name / Department representative).';
        }

        if (!$hasAnyQty) {
            $errors[] = 'Please specify an issuing quantity greater than zero for at least one item.';
        }

        if (empty($errors)) {
            try {
                $res = issue_stock_for_request($pdo, $requestId, [
                    'issued_to'  => $issuedTo,
                    'issue_date' => $issueDate,
                    'remarks'    => $remarks
                ], $issuedLines, (int)$user['id']);

                flash_set('success', "Stock Issue Note {$res['issue_number']} recorded successfully! Physical store inventory has been reduced. Request status: " . ucwords(str_replace('_', ' ', $res['request_status'])) . ".");
                redirect(url('public/issue_view.php?id=' . $res['issue_id']));
            } catch (Exception $e) {
                $errors[] = 'Stock Issue Error: ' . $e->getMessage();
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/dashboard.php')) ?>" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= e(url('public/issues.php')) ?>" class="text-decoration-none">Stock Issues</a></li>
                <li class="breadcrumb-item active">Issue Stock</li>
            </ol>
        </nav>
        <h2 class="h3 fw-bold mb-0">Record Stock Issue Note</h2>
        <p class="text-muted small mb-0">Physically release stock to a department representative and atomically decrement inventory.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <?php if ($selectedReq): ?>
            <a href="<?= e(url('public/request_view.php?id=' . $selectedReq['id'])) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to Requisition
            </a>
        <?php else: ?>
            <a href="<?= e(url('public/issues.php')) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to Issues
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i>Please correct the following:</div>
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!$selectedReq): ?>
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="card-title mb-0 fw-bold text-primary">
                <i class="bi bi-file-earmark-check me-2"></i>Select Approved Stock Request
            </h5>
        </div>
        <div class="card-body">
            <form method="GET" action="<?= e(url('public/issue_create.php')) ?>" class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label for="select_req" class="form-label fw-semibold">Approved Requisitions</label>
                    <select name="request_id" id="select_req" class="form-select" required>
                        <option value="">-- Choose Requisition --</option>
                        <?php foreach ($eligibleRequests as $ereq): ?>
                            <option value="<?= (int)$ereq['id'] ?>">
                                <?= e($ereq['request_number']) ?> &mdash; <?= e($ereq['department_name']) ?> (Date: <?= e(date('d M Y', strtotime($ereq['request_date']))) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-arrow-right-circle me-1"></i> Load Requisition
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>

    <form action="<?= e(url('public/issue_create.php?request_id=' . $selectedReq['id'])) ?>" method="POST" id="issueForm">
        <?= csrf_input() ?>
        <input type="hidden" name="request_id" value="<?= (int)$selectedReq['id'] ?>">

        <!-- Header Card -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0 fw-bold text-primary">
                    <i class="bi bi-person-badge me-2"></i>Issuing & Handover Details
                </h5>
                <span class="badge bg-light text-dark border">
                    Req: <strong><?= e($selectedReq['request_number']) ?></strong> &bull; <?= e($selectedReq['department_name']) ?>
                </span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="issued_to" class="form-label fw-semibold">Recipient Name / Teacher <span class="text-danger">*</span></label>
                        <input type="text" name="issued_to" id="issued_to" class="form-control" 
                               placeholder="e.g. Mr. John Otieno (Chemistry HOD)" 
                               value="<?= e($_POST['issued_to'] ?? $selectedReq['requester_name'] ?? '') ?>" required>
                    </div>

                    <div class="col-md-4">
                        <label for="issue_date" class="form-label fw-semibold">Date Issued <span class="text-danger">*</span></label>
                        <input type="date" name="issue_date" id="issue_date" class="form-control" 
                               value="<?= e($_POST['issue_date'] ?? date('Y-m-d')) ?>" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Issuing Storekeeper</label>
                        <input type="text" class="form-control bg-light" readonly value="<?= e($user['full_name']) ?>">
                    </div>

                    <div class="col-12">
                        <label for="remarks" class="form-label fw-semibold">Handover Notes / Instructions</label>
                        <textarea name="remarks" id="remarks" rows="2" class="form-control" 
                                  placeholder="e.g. Items handed over in sound condition. Laboratory apparatus checked for breakage."><?= e($_POST['remarks'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Line Items Table -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold text-primary">
                    <i class="bi bi-box-seam me-2"></i>Items to Issue
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 25%;">Item Description</th>
                                <th style="width: 10%;" class="text-center">Approved</th>
                                <th style="width: 10%;" class="text-center">Already Issued</th>
                                <th style="width: 10%;" class="text-center text-warning">Unissued</th>
                                <th style="width: 12%;" class="text-center">Available Stock</th>
                                <th style="width: 15%;" class="text-center">Quantity to Issue <span class="text-danger">*</span></th>
                                <th style="width: 18%;">Line Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reqItems as $i => $item): 
                                $approved = (float)$item['quantity_approved'];
                                $alreadyIssued = (float)$item['quantity_issued'];
                                $unissued = max(0.0, $approved - $alreadyIssued);
                                $stock = (float)$item['quantity_on_hand'];
                                $maxCanIssue = min($unissued, $stock);
                                $isFullyIssued = ($unissued <= 0);
                                $isOutOfStock = (!$isFullyIssued && $stock <= 0);
                            ?>
                                <tr class="<?= $isFullyIssued ? 'table-light text-muted' : ($isOutOfStock ? 'table-danger' : '') ?>">
                                    <td>
                                        <div class="fw-semibold"><?= e($item['item_name']) ?></div>
                                        <span class="badge bg-light text-dark font-monospace"><?= e($item['item_code']) ?></span>
                                        <span class="text-muted small ms-1">(<?= e($item['unit_of_measure']) ?>)</span>
                                        <input type="hidden" name="request_item_id[]" value="<?= (int)$item['id'] ?>">
                                        <input type="hidden" name="item_id[]" value="<?= (int)$item['item_id'] ?>">
                                    </td>
                                    <td class="text-center fw-semibold"><?= number_format($approved, 1) ?></td>
                                    <td class="text-center text-success"><?= number_format($alreadyIssued, 1) ?></td>
                                    <td class="text-center fw-bold <?= ($unissued > 0) ? 'text-warning' : 'text-muted' ?>">
                                        <?= number_format($unissued, 1) ?>
                                    </td>
                                    <td class="text-center <?= ($stock < $unissued) ? 'text-danger fw-bold' : 'text-success fw-semibold' ?>">
                                        <?= number_format($stock, 1) ?>
                                    </td>
                                    <td>
                                        <?php if ($isFullyIssued): ?>
                                            <div class="text-center text-success small fw-semibold">
                                                <i class="bi bi-check-circle-fill me-1"></i> Completed
                                            </div>
                                            <input type="hidden" name="quantity_issued[]" value="0">
                                        <?php elseif ($isOutOfStock): ?>
                                            <div class="text-center text-danger small fw-semibold">
                                                <i class="bi bi-x-circle me-1"></i> Out of Stock
                                            </div>
                                            <input type="hidden" name="quantity_issued[]" value="0">
                                        <?php else: ?>
                                            <div class="input-group">
                                                <input type="number" step="0.01" min="0" max="<?= $maxCanIssue ?>" 
                                                       name="quantity_issued[]" 
                                                       class="form-control text-center fw-bold issue-input" 
                                                       placeholder="0.00" 
                                                       value="<?= (isset($_POST['quantity_issued'][$i])) ? e($_POST['quantity_issued'][$i]) : $maxCanIssue ?>" 
                                                       required>
                                                <button type="button" class="btn btn-outline-secondary btn-sm btn-fill-issue" 
                                                        data-max="<?= $maxCanIssue ?>" title="Fill available amount">
                                                    Max
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <input type="text" name="line_remarks[]" class="form-control form-control-sm" 
                                               placeholder="e.g. Lab room 3" 
                                               value="<?= e($_POST['line_remarks'][$i] ?? '') ?>"
                                               <?= ($isFullyIssued || $isOutOfStock) ? 'readonly' : '' ?>>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white py-3 border-top d-flex justify-content-between align-items-center">
                <div class="text-muted small">
                    <i class="bi bi-shield-lock-fill text-primary me-1"></i> <strong>Strict Stock Invariant:</strong> Stock decreases strictly when this issue is recorded. Available stock is validated on row lock to prevent negative balances.
                </div>
                <div>
                    <a href="<?= e(url('public/request_view.php?id=' . $selectedReq['id'])) ?>" class="btn btn-outline-secondary me-2">Cancel</a>
                    <button type="submit" class="btn btn-success px-4" onclick="return confirm('Confirm physical handover and deduct inventory?');">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Record Stock Issue
                    </button>
                </div>
            </div>
        </div>
    </form>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.btn-fill-issue').forEach(btn => {
            btn.addEventListener('click', function() {
                const maxVal = this.getAttribute('data-max');
                const input = this.parentElement.querySelector('.issue-input');
                if (input) {
                    input.value = maxVal;
                }
            });
        });
    });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
