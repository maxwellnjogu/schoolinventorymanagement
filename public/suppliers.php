<?php
/**
 * Supplier Management Directory
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Suppliers Directory';

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

$canManage = has_role(['admin', 'storekeeper']);
$errors = [];

// Handle Supplier Creation or Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    require_csrf_token();

    $action = $_POST['action'] ?? 'create';
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $supplierCode = strtoupper(trim($_POST['supplier_code'] ?? ''));
    $companyName = trim($_POST['company_name'] ?? '');
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

    if (empty($supplierCode)) {
        $errors[] = 'Supplier Code is required.';
    }
    if (empty($companyName)) {
        $errors[] = 'Company Name is required.';
    }

    if (empty($errors)) {
        try {
            if ($action === 'create') {
                $chk = $pdo->prepare("SELECT id FROM `suppliers` WHERE `supplier_code` = ?");
                $chk->execute([$supplierCode]);
                if ($chk->fetch()) {
                    $errors[] = "Supplier code '{$supplierCode}' already exists.";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO `suppliers` (
                            `supplier_code`, `company_name`, `contact_person`,
                            `phone`, `email`, `address`, `status`, `created_at`
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $supplierCode, $companyName, $contactPerson ?: null,
                        $phone ?: null, $email ?: null, $address ?: null, $status
                    ]);
                    $newId = (int)$pdo->lastInsertId();
                    log_audit_action($pdo, 'SUPPLIER_CREATED', 'suppliers', $newId, "Created supplier {$companyName} ({$supplierCode})");
                    set_flash('success', "Supplier '{$companyName}' registered successfully!");
                    redirect('public/suppliers.php');
                }
            } elseif ($action === 'update' && $supplierId > 0) {
                $chk = $pdo->prepare("SELECT id FROM `suppliers` WHERE `supplier_code` = ? AND id != ?");
                $chk->execute([$supplierCode, $supplierId]);
                if ($chk->fetch()) {
                    $errors[] = "Supplier code '{$supplierCode}' is already used by another vendor.";
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE `suppliers`
                        SET `supplier_code` = ?, `company_name` = ?, `contact_person` = ?,
                            `phone` = ?, `email` = ?, `address` = ?, `status` = ?, `updated_at` = NOW()
                        WHERE `id` = ?
                    ");
                    $stmt->execute([
                        $supplierCode, $companyName, $contactPerson ?: null,
                        $phone ?: null, $email ?: null, $address ?: null, $status, $supplierId
                    ]);
                    log_audit_action($pdo, 'SUPPLIER_UPDATED', 'suppliers', $supplierId, "Updated supplier {$companyName}");
                    set_flash('success', "Supplier '{$companyName}' updated successfully!");
                    redirect('public/suppliers.php');
                }
            }
        } catch (PDOException $e) {
            error_log('Supplier error: ' . $e->getMessage());
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Fetch all suppliers with PO counts
$query = "
    SELECT s.*, COUNT(po.id) AS po_count, COALESCE(SUM(po.total_amount), 0.0) AS total_orders_val
    FROM `suppliers` s
    LEFT JOIN `purchase_orders` po ON s.id = po.supplier_id
    GROUP BY s.id
    ORDER BY s.company_name ASC
";
$suppliers = $pdo->query($query)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-truck text-primary me-2"></i>Approved Suppliers & Vendors</h4>
        <div class="text-muted small">Maintain verified contractors, textbook publishers, science apparatus vendors, and foodstuff distributors.</div>
    </div>
    <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary d-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#newSupplierModal">
            <i class="bi bi-plus-circle-fill"></i>
            <span>Register Supplier</span>
        </button>
    <?php endif; ?>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger py-2 px-3 small shadow-sm mb-4">
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card card-custom shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small">
                    <tr>
                        <th class="ps-3" style="width: 120px;">Code</th>
                        <th>Company Name</th>
                        <th>Contact Person</th>
                        <th>Telephone / Email</th>
                        <th>Physical Address</th>
                        <th class="text-center">Orders</th>
                        <th class="text-center">Status</th>
                        <?php if ($canManage): ?>
                            <th class="text-end pe-3">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="small">
                    <?php if (empty($suppliers)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">No suppliers registered in the system yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($suppliers as $sup): ?>
                            <tr>
                                <td class="ps-3">
                                    <code class="fw-bold text-dark"><?= e($sup['supplier_code']) ?></code>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= e($sup['company_name']) ?></div>
                                    <div class="text-muted" style="font-size: 0.72rem;">Spend: <?= format_currency($sup['total_orders_val']) ?></div>
                                </td>
                                <td>
                                    <?= e($sup['contact_person'] ?? '-') ?>
                                </td>
                                <td>
                                    <div><i class="bi bi-telephone text-muted me-1"></i><?= e($sup['phone'] ?? '-') ?></div>
                                    <?php if (!empty($sup['email'])): ?>
                                        <div class="text-muted" style="font-size: 0.72rem;"><i class="bi bi-envelope text-muted me-1"></i><?= e($sup['email']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted">
                                    <?= e($sup['address'] ?? '-') ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark border">
                                        <?= (int)$sup['po_count'] ?> PO<?= (int)$sup['po_count'] === 1 ? '' : 's' ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($sup['status'] === 'active'): ?>
                                        <span class="badge bg-success-subtle text-success border border-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($canManage): ?>
                                    <td class="text-end pe-3">
                                        <div class="btn-group btn-group-sm">
                                            <a href="<?= e(url('public/po_create.php?supplier_id=' . (int)$sup['id'])) ?>" class="btn btn-outline-success" title="Create Purchase Order">
                                                <i class="bi bi-receipt"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-primary btn-edit-supplier"
                                                data-id="<?= (int)$sup['id'] ?>"
                                                data-code="<?= e($sup['supplier_code']) ?>"
                                                data-name="<?= e($sup['company_name']) ?>"
                                                data-contact="<?= e($sup['contact_person'] ?? '') ?>"
                                                data-phone="<?= e($sup['phone'] ?? '') ?>"
                                                data-email="<?= e($sup['email'] ?? '') ?>"
                                                data-address="<?= e($sup['address'] ?? '') ?>"
                                                data-status="<?= e($sup['status']) ?>"
                                                title="Edit Supplier">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: New Supplier -->
<div class="modal fade" id="newSupplierModal" tabindex="-1" aria-labelledby="newSupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content card-custom">
            <form method="POST" action="<?= e(url('public/suppliers.php')) ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="newSupplierModalLabel"><i class="bi bi-plus-circle text-primary me-2"></i>Register Approved Supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body small">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="new_code" class="form-label fw-semibold text-muted">Supplier Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control font-monospace" id="new_code" name="supplier_code" placeholder="e.g. SUP-005" required>
                        </div>
                        <div class="col-md-8">
                            <label for="new_company" class="form-label fw-semibold text-muted">Company / Vendor Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="new_company" name="company_name" placeholder="e.g. Kenya Literature Bureau" required>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="new_contact" class="form-label fw-semibold text-muted">Contact Representative</label>
                            <input type="text" class="form-control" id="new_contact" name="contact_person" placeholder="e.g. Jane Mumo">
                        </div>
                        <div class="col-md-4">
                            <label for="new_phone" class="form-label fw-semibold text-muted">Telephone Number</label>
                            <input type="text" class="form-control" id="new_phone" name="phone" placeholder="e.g. +254 722 000 111">
                        </div>
                        <div class="col-md-4">
                            <label for="new_email" class="form-label fw-semibold text-muted">Official Email Address</label>
                            <input type="email" class="form-control" id="new_email" name="email" placeholder="e.g. sales@vendor.co.ke">
                        </div>
                    </div>
                    <div class="row g-3 mb-2">
                        <div class="col-md-8">
                            <label for="new_address" class="form-label fw-semibold text-muted">Physical / Postal Address</label>
                            <textarea class="form-control" id="new_address" name="address" rows="2" placeholder="e.g. Industrial Area, Nairobi"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label for="new_status" class="form-label fw-semibold text-muted">Supplier Status</label>
                            <select class="form-select" id="new_status" name="status">
                                <option value="active">Active (Approved)</option>
                                <option value="inactive">Inactive / Suspended</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Save Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Supplier -->
<div class="modal fade" id="editSupplierModal" tabindex="-1" aria-labelledby="editSupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content card-custom">
            <form method="POST" action="<?= e(url('public/suppliers.php')) ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="supplier_id" id="edit_sup_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="editSupplierModalLabel"><i class="bi bi-pencil text-primary me-2"></i>Edit Supplier Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body small">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="edit_code" class="form-label fw-semibold text-muted">Supplier Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control font-monospace" id="edit_code" name="supplier_code" required>
                        </div>
                        <div class="col-md-8">
                            <label for="edit_company" class="form-label fw-semibold text-muted">Company Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_company" name="company_name" required>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="edit_contact" class="form-label fw-semibold text-muted">Contact Representative</label>
                            <input type="text" class="form-control" id="edit_contact" name="contact_person">
                        </div>
                        <div class="col-md-4">
                            <label for="edit_phone" class="form-label fw-semibold text-muted">Telephone Number</label>
                            <input type="text" class="form-control" id="edit_phone" name="phone">
                        </div>
                        <div class="col-md-4">
                            <label for="edit_email" class="form-label fw-semibold text-muted">Official Email</label>
                            <input type="email" class="form-control" id="edit_email" name="email">
                        </div>
                    </div>
                    <div class="row g-3 mb-2">
                        <div class="col-md-8">
                            <label for="edit_address" class="form-label fw-semibold text-muted">Physical / Postal Address</label>
                            <textarea class="form-control" id="edit_address" name="address" rows="2"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label for="edit_status" class="form-label fw-semibold text-muted">Status</label>
                            <select class="form-select" id="edit_status" name="status">
                                <option value="active">Active (Approved)</option>
                                <option value="inactive">Inactive / Suspended</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Update Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const editButtons = document.querySelectorAll('.btn-edit-supplier');
    const editModal = new bootstrap.Modal(document.getElementById('editSupplierModal'));
    
    editButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('edit_sup_id').value = btn.getAttribute('data-id');
            document.getElementById('edit_code').value = btn.getAttribute('data-code');
            document.getElementById('edit_company').value = btn.getAttribute('data-name');
            document.getElementById('edit_contact').value = btn.getAttribute('data-contact');
            document.getElementById('edit_phone').value = btn.getAttribute('data-phone');
            document.getElementById('edit_email').value = btn.getAttribute('data-email');
            document.getElementById('edit_address').value = btn.getAttribute('data-address');
            document.getElementById('edit_status').value = btn.getAttribute('data-status');
            editModal.show();
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
