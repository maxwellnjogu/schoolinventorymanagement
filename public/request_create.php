<?php
/**
 * Create Department Stock Requisition
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'New Stock Request';

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

$errors = [];

// Fetch departments
$departmentsStmt = $pdo->query("SELECT id, name, code FROM `departments` ORDER BY name ASC");
$departments = $departmentsStmt->fetchAll();

// Fetch items with current stock
$itemsStmt = $pdo->query("SELECT id, item_code, name, unit_of_measure, quantity_on_hand FROM `items` ORDER BY name ASC");
$items = $itemsStmt->fetchAll();

$userDeptId = $user['department_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Security token invalid or expired. Please submit the form again.';
    } else {
        $departmentId = (int)($_POST['department_id'] ?? $userDeptId ?? 0);
        $requestDate = trim($_POST['request_date'] ?? date('Y-m-d'));
        $remarks = trim($_POST['remarks'] ?? '');

        $itemIds = $_POST['item_id'] ?? [];
        $quantities = $_POST['quantity_requested'] ?? [];
        $lineRemarks = $_POST['line_remarks'] ?? [];

        $requestItems = [];
        if (is_array($itemIds) && count($itemIds) > 0) {
            for ($i = 0; $i < count($itemIds); $i++) {
                $itemId = (int)($itemIds[$i] ?? 0);
                $qty = (float)($quantities[$i] ?? 0);
                $lRemarks = trim($lineRemarks[$i] ?? '');

                if ($itemId > 0 && $qty > 0) {
                    $requestItems[] = [
                        'item_id'            => $itemId,
                        'quantity_requested' => $qty,
                        'remarks'            => $lRemarks
                    ];
                }
            }
        }

        if ($departmentId <= 0) {
            $errors[] = 'Please select a requesting school department.';
        }
        if (empty($requestDate)) {
            $errors[] = 'Request date is required.';
        }
        if (empty($requestItems)) {
            $errors[] = 'Please add at least one item with a requested quantity greater than zero.';
        }

        if (empty($errors)) {
            try {
                $result = create_stock_request($pdo, [
                    'department_id' => $departmentId,
                    'request_date'  => $requestDate,
                    'remarks'       => $remarks
                ], $requestItems, (int)$user['id']);

                flash_set('success', "Stock Requisition {$result['request_number']} submitted successfully! Pending approval.");
                redirect(url('public/request_view.php?id=' . $result['request_id']));
            } catch (Exception $e) {
                $errors[] = 'Error saving requisition: ' . $e->getMessage();
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
                <li class="breadcrumb-item"><a href="<?= e(url('public/requests.php')) ?>" class="text-decoration-none">Stock Requisitions</a></li>
                <li class="breadcrumb-item active">New Request</li>
            </ol>
        </nav>
        <h2 class="h3 fw-bold mb-0">Create Department Stock Request</h2>
        <p class="text-muted small mb-0">Requisition materials and supplies from the central school inventory.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <a href="<?= e(url('public/requests.php')) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Requisitions
        </a>
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

<form action="<?= e(url('public/request_create.php')) ?>" method="POST" id="requestForm">
    <?= csrf_input() ?>

    <div class="row g-4">
        <!-- Requisition Header -->
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="card-title mb-0 fw-bold text-primary">
                        <i class="bi bi-building me-2"></i>Department & Requisition Details
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label for="department_id" class="form-label fw-semibold">School Department <span class="text-danger">*</span></label>
                            <?php if ($user['role'] === 'department_head' && $userDeptId): ?>
                                <?php 
                                    $deptName = 'My Department';
                                    foreach ($departments as $d) {
                                        if ((int)$d['id'] === (int)$userDeptId) {
                                            $deptName = $d['name'] . ' (' . $d['code'] . ')';
                                            break;
                                        }
                                    }
                                ?>
                                <input type="text" class="form-control bg-light" readonly value="<?= e($deptName) ?>">
                                <input type="hidden" name="department_id" value="<?= (int)$userDeptId ?>">
                            <?php else: ?>
                                <select name="department_id" id="department_id" class="form-select" required>
                                    <option value="">-- Select Department --</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?= (int)$d['id'] ?>" <?= ((int)($_POST['department_id'] ?? $userDeptId) === (int)$d['id']) ? 'selected' : '' ?>>
                                            <?= e($d['name']) ?> (<?= e($d['code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-3">
                            <label for="request_date" class="form-label fw-semibold">Request Date <span class="text-danger">*</span></label>
                            <input type="date" name="request_date" id="request_date" class="form-control" 
                                   value="<?= e($_POST['request_date'] ?? date('Y-m-d')) ?>" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Requested By</label>
                            <input type="text" class="form-control bg-light" readonly value="<?= e($user['full_name']) ?> (<?= e(role_display_name($user['role'])) ?>)">
                        </div>

                        <div class="col-12">
                            <label for="remarks" class="form-label fw-semibold">Purpose / Department Justification</label>
                            <textarea name="remarks" id="remarks" rows="2" class="form-control" 
                                      placeholder="e.g. Needed for Form 3 & 4 End-of-Term Examinations and Laboratory Practicals."><?= e($_POST['remarks'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Line Items Table -->
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0 fw-bold text-primary">
                        <i class="bi bi-list-check me-2"></i>Requested Items
                    </h5>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddRow">
                        <i class="bi bi-plus-circle me-1"></i> Add Another Item
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="reqItemsTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 45%;">Item Description</th>
                                    <th style="width: 15%;">Unit of Measure</th>
                                    <th style="width: 15%;">Available in Store</th>
                                    <th style="width: 15%;">Requested Qty <span class="text-danger">*</span></th>
                                    <th style="width: 50px;" class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody id="reqItemsBody">
                                <!-- Dynamic rows -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white py-3 text-muted small border-top">
                    <i class="bi bi-info-circle me-1 text-primary"></i> <strong>Workflow Note:</strong> Once submitted, your requisition will be pending review by the Administration/Storekeeper. Approving the request will <em>not</em> reduce stock; store inventory is only deducted when items are physically issued.
                </div>
            </div>
        </div>

        <!-- Submit Buttons -->
        <div class="col-12 text-end">
            <a href="<?= e(url('public/requests.php')) ?>" class="btn btn-outline-secondary me-2">Cancel</a>
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-send me-1"></i> Submit Stock Request
            </button>
        </div>
    </div>
</form>

<template id="rowTemplate">
    <tr class="item-row">
        <td>
            <select name="item_id[]" class="form-select item-select" required>
                <option value="">-- Choose Item --</option>
                <?php foreach ($items as $it): ?>
                    <option value="<?= (int)$it['id'] ?>" 
                            data-uom="<?= e($it['unit_of_measure']) ?>"
                            data-stock="<?= (float)$it['quantity_on_hand'] ?>">
                        <?= e($it['name']) ?> (<?= e($it['item_code']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="text" class="form-control bg-light uom-display" readonly value="-">
        </td>
        <td>
            <input type="text" class="form-control bg-light stock-display fw-bold" readonly value="-">
        </td>
        <td>
            <input type="number" step="0.01" min="0.01" name="quantity_requested[]" class="form-control qty-input fw-bold" placeholder="0.00" required>
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-outline-danger btn-sm btn-remove-row" title="Remove row">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>
</template>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tbody = document.getElementById('reqItemsBody');
    const template = document.getElementById('rowTemplate');
    const btnAddRow = document.getElementById('btnAddRow');

    function addRow() {
        const clone = template.content.cloneNode(true);
        const row = clone.querySelector('.item-row');
        
        const select = row.querySelector('.item-select');
        const uomDisplay = row.querySelector('.uom-display');
        const stockDisplay = row.querySelector('.stock-display');
        const btnRemove = row.querySelector('.btn-remove-row');

        select.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            if (opt && opt.value) {
                uomDisplay.value = opt.getAttribute('data-uom') || '-';
                const stock = parseFloat(opt.getAttribute('data-stock')) || 0;
                stockDisplay.value = stock + ' ' + (opt.getAttribute('data-uom') || '');
                if (stock <= 0) {
                    stockDisplay.classList.add('text-danger');
                    stockDisplay.classList.remove('text-success');
                } else {
                    stockDisplay.classList.add('text-success');
                    stockDisplay.classList.remove('text-danger');
                }
            } else {
                uomDisplay.value = '-';
                stockDisplay.value = '-';
                stockDisplay.classList.remove('text-danger', 'text-success');
            }
        });

        btnRemove.addEventListener('click', function() {
            if (tbody.querySelectorAll('.item-row').length > 1) {
                row.remove();
            } else {
                alert('A stock request must have at least one line item.');
            }
        });

        tbody.appendChild(clone);
    }

    btnAddRow.addEventListener('click', addRow);
    addRow();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
