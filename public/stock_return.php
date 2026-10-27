<?php
/**
 * Department Stock Returns
 * Record unused/excess department supplies returned to central store
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Department Stock Returns';

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

$errors = [];

// Fetch departments and items
$departments = $pdo->query("SELECT id, name, code FROM `departments` ORDER BY name ASC")->fetchAll();
$items = $pdo->query("SELECT id, item_code, name, unit_of_measure, quantity_on_hand FROM `items` ORDER BY name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Security token invalid or expired. Please submit the form again.';
    } else {
        $departmentId = (int)($_POST['department_id'] ?? 0);
        $itemId = (int)($_POST['item_id'] ?? 0);
        $quantity = (float)($_POST['quantity'] ?? 0);
        $reason = trim($_POST['return_reason'] ?? '');

        if ($departmentId <= 0) {
            $errors[] = 'Please select the returning department.';
        }
        if ($itemId <= 0) {
            $errors[] = 'Please select the item being returned.';
        }
        if ($quantity <= 0) {
            $errors[] = 'Quantity returned must be greater than zero.';
        }
        if (empty($reason)) {
            $errors[] = 'Please specify a reason or condition for the return.';
        }

        if (empty($errors)) {
            try {
                $res = record_stock_return($pdo, [
                    'department_id' => $departmentId,
                    'item_id'       => $itemId,
                    'quantity'      => $quantity,
                    'return_reason' => $reason
                ], (int)$user['id']);

                flash_set('success', "Stock return ({$res['return_number']}) processed successfully. New store stock balance: {$res['balance_after']} units.");
                redirect(url('public/stock_return.php'));
            } catch (Exception $e) {
                $errors[] = 'Return Processing Error: ' . $e->getMessage();
            }
        }
    }
}

// Fetch recent return transactions from stock movements
$returnsStmt = $pdo->query("
    SELECT sm.*, i.name AS item_name, i.item_code, i.unit_of_measure,
           u.full_name AS performed_by_name
    FROM `stock_movements` sm
    JOIN `items` i ON sm.item_id = i.id
    LEFT JOIN `users` u ON sm.performed_by = u.id
    WHERE sm.reference_type = 'return'
    ORDER BY sm.id DESC
    LIMIT 30
");
$recentReturns = $returnsStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/dashboard.php')) ?>" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= e(url('public/issues.php')) ?>" class="text-decoration-none">Stock Issues</a></li>
                <li class="breadcrumb-item active">Department Returns</li>
            </ol>
        </nav>
        <h2 class="h3 fw-bold mb-0">Department Stock Returns</h2>
        <p class="text-muted small mb-0">Record unused or excess materials returned by high school departments to the central stores.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <a href="<?= e(url('public/issues.php')) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Issues
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

<div class="row g-4 mb-4">
    <!-- Return Form -->
    <div class="col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold text-primary">
                    <i class="bi bi-arrow-return-left me-2"></i>Record Item Return
                </h5>
            </div>
            <div class="card-body">
                <form action="<?= e(url('public/stock_return.php')) ?>" method="POST">
                    <?= csrf_input() ?>

                    <div class="mb-3">
                        <label for="department_id" class="form-label fw-semibold">Returning Department <span class="text-danger">*</span></label>
                        <select name="department_id" id="department_id" class="form-select" required>
                            <option value="">-- Select Department --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= ((int)($_POST['department_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>>
                                    <?= e($d['name']) ?> (<?= e($d['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="item_id" class="form-label fw-semibold">Item Being Returned <span class="text-danger">*</span></label>
                        <select name="item_id" id="item_id" class="form-select" required>
                            <option value="">-- Choose Item --</option>
                            <?php foreach ($items as $it): ?>
                                <option value="<?= (int)$it['id'] ?>" data-uom="<?= e($it['unit_of_measure']) ?>" data-stock="<?= (float)$it['quantity_on_hand'] ?>">
                                    <?= e($it['name']) ?> (<?= e($it['item_code']) ?>) [Stock: <?= (float)$it['quantity_on_hand'] ?> <?= e($it['unit_of_measure']) ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="quantity" class="form-label fw-semibold">Quantity Returned <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" step="0.01" min="0.01" name="quantity" id="quantity" 
                                   class="form-control fw-bold" placeholder="0.00" 
                                   value="<?= e($_POST['quantity'] ?? '') ?>" required>
                            <span class="input-group-text" id="uomBadge">Units</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="return_reason" class="form-label fw-semibold">Reason for Return / Condition <span class="text-danger">*</span></label>
                        <textarea name="return_reason" id="return_reason" rows="3" class="form-control" 
                                  placeholder="e.g. Unused exam booklets returned after final exams. Intact and reusable." required><?= e($_POST['return_reason'] ?? '') ?></textarea>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-warning fw-semibold">
                            <i class="bi bi-box-arrow-in-left me-1"></i> Accept Return & Increment Stock
                        </button>
                    </div>
                </form>
            </div>
            <div class="card-footer bg-white text-muted small border-top">
                <i class="bi bi-shield-check text-success me-1"></i> Stock is increased immediately and an audit movement entry is created.
            </div>
        </div>
    </div>

    <!-- Recent Returns History Table -->
    <div class="col-lg-7">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold text-primary">
                    <i class="bi bi-clock-history me-2"></i>Recent Stock Returns
                </h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentReturns)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-arrow-return-left display-6 d-block mb-2 text-secondary opacity-50"></i>
                        No departmental stock returns recorded yet.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Return Ref #</th>
                                    <th>Item</th>
                                    <th class="text-center">Quantity</th>
                                    <th class="text-center">Balance After</th>
                                    <th>Reason / Notes</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentReturns as $ret): ?>
                                    <tr>
                                        <td class="font-monospace fw-bold text-primary small">
                                            <?= e($ret['reference_number']) ?>
                                        </td>
                                        <td class="fw-semibold">
                                            <a href="<?= e(url('public/item_movements.php?id=' . $ret['item_id'])) ?>" class="text-decoration-none">
                                                <?= e($ret['item_name']) ?>
                                            </a>
                                            <div class="text-muted small"><?= e($ret['item_code']) ?></div>
                                        </td>
                                        <td class="text-center fw-bold text-success">+<?= (float)$ret['quantity'] ?></td>
                                        <td class="text-center fw-semibold"><?= (float)$ret['balance_after'] ?></td>
                                        <td class="small text-muted"><?= e($ret['notes']) ?></td>
                                        <td class="small text-nowrap"><?= e(date('d M H:i', strtotime($ret['created_at']))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const itemSelect = document.getElementById('item_id');
    const uomBadge = document.getElementById('uomBadge');

    itemSelect.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (opt && opt.value) {
            uomBadge.textContent = opt.getAttribute('data-uom') || 'Units';
        } else {
            uomBadge.textContent = 'Units';
        }
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
