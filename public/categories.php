<?php
/**
 * Categories Management
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Category Management';

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

// Handle category creation / update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    require_csrf_token();

    $action = $_POST['action'] ?? 'create';
    $catId = (int)($_POST['category_id'] ?? 0);
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $isAsset = isset($_POST['is_asset_category']) && $_POST['is_asset_category'] === '1' ? 1 : 0;

    if (empty($code)) {
        $errors[] = 'Category Code is required.';
    }
    if (empty($name)) {
        $errors[] = 'Category Name is required.';
    }

    if (empty($errors)) {
        try {
            if ($action === 'create') {
                // Check unique code
                $chk = $pdo->prepare("SELECT id FROM `categories` WHERE `code` = ?");
                $chk->execute([$code]);
                if ($chk->fetch()) {
                    $errors[] = "Category code '{$code}' already exists.";
                } else {
                    $insertStmt = $pdo->prepare("
                        INSERT INTO `categories` (`code`, `name`, `is_asset_category`, `description`, `created_at`)
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $insertStmt->execute([$code, $name, $isAsset, $description ?: null]);
                    log_audit_action($pdo, 'CATEGORY_CREATED', 'categories', (int)$pdo->lastInsertId(), "Created category {$name} ({$code})");
                    set_flash('success', "Category '{$name}' created successfully!");
                    redirect('public/categories.php');
                }
            } elseif ($action === 'update' && $catId > 0) {
                $chk = $pdo->prepare("SELECT id FROM `categories` WHERE `code` = ? AND id != ?");
                $chk->execute([$code, $catId]);
                if ($chk->fetch()) {
                    $errors[] = "Category code '{$code}' is already used by another category.";
                } else {
                    $updStmt = $pdo->prepare("
                        UPDATE `categories`
                        SET `code` = ?, `name` = ?, `is_asset_category` = ?, `description` = ?, `updated_at` = NOW()
                        WHERE `id` = ?
                    ");
                    $updStmt->execute([$code, $name, $isAsset, $description ?: null, $catId]);
                    log_audit_action($pdo, 'CATEGORY_UPDATED', 'categories', $catId, "Updated category {$name}");
                    set_flash('success', "Category '{$name}' updated successfully!");
                    redirect('public/categories.php');
                }
            }
        } catch (PDOException $e) {
            error_log('Category error: ' . $e->getMessage());
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Fetch all categories with item counts
$query = "
    SELECT c.*, COUNT(i.id) AS item_count
    FROM `categories` c
    LEFT JOIN `items` i ON c.id = i.category_id
    GROUP BY c.id
    ORDER BY c.name ASC
";
$categories = $pdo->query($query)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-tags-fill text-primary me-2"></i>Inventory Categories</h4>
        <div class="text-muted small">Manage classifications separating consumable school supplies from fixed capital assets.</div>
    </div>
    <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary d-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#newCategoryModal">
            <i class="bi bi-plus-circle-fill"></i>
            <span>Add New Category</span>
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
                        <th class="ps-3" style="width: 130px;">Code</th>
                        <th>Category Name</th>
                        <th>Classification</th>
                        <th>Description</th>
                        <th class="text-center">Assigned Items</th>
                        <?php if ($canManage): ?>
                            <th class="text-end pe-3">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="small">
                    <?php if (empty($categories)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">No categories defined yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td class="ps-3">
                                    <code class="fw-bold text-dark"><?= e($cat['code']) ?></code>
                                </td>
                                <td class="fw-bold text-dark">
                                    <?= e($cat['name']) ?>
                                </td>
                                <td>
                                    <?php if ($cat['is_asset_category']): ?>
                                        <span class="badge bg-info text-dark"><i class="bi bi-display me-1"></i>Fixed Asset Category</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary text-white"><i class="bi bi-box me-1"></i>Consumable Category</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted">
                                    <?= e($cat['description'] ?? '-') ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark border px-2 py-1">
                                        <?= (int)$cat['item_count'] ?> item<?= (int)$cat['item_count'] === 1 ? '' : 's' ?>
                                    </span>
                                </td>
                                <?php if ($canManage): ?>
                                    <td class="text-end pe-3">
                                        <button type="button" class="btn btn-sm btn-outline-primary btn-edit-category" 
                                            data-id="<?= (int)$cat['id'] ?>"
                                            data-code="<?= e($cat['code']) ?>"
                                            data-name="<?= e($cat['name']) ?>"
                                            data-is-asset="<?= $cat['is_asset_category'] ? '1' : '0' ?>"
                                            data-description="<?= e($cat['description'] ?? '') ?>">
                                            <i class="bi bi-pencil me-1"></i>Edit
                                        </button>
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

<!-- Modal: New Category -->
<div class="modal fade" id="newCategoryModal" tabindex="-1" aria-labelledby="newCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content card-custom">
            <form method="POST" action="<?= e(url('public/categories.php')) ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="newCategoryModalLabel"><i class="bi bi-plus-circle text-primary me-2"></i>Create New Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body small">
                    <div class="mb-3">
                        <label for="new_code" class="form-label fw-semibold text-muted">Category Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control font-monospace" id="new_code" name="code" placeholder="e.g. CAT-STATIONERY" required>
                    </div>
                    <div class="mb-3">
                        <label for="new_name" class="form-label fw-semibold text-muted">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="new_name" name="name" placeholder="e.g. Science Lab Reagents" required>
                    </div>
                    <div class="mb-3">
                        <label for="new_is_asset" class="form-label fw-semibold text-muted">Category Classification <span class="text-danger">*</span></label>
                        <select class="form-select" id="new_is_asset" name="is_asset_category" required>
                            <option value="0">Consumable Supplies (Depleted through usage)</option>
                            <option value="1">Fixed Assets (Long-term school property & equipment)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="new_description" class="form-label fw-semibold text-muted">Description</label>
                        <textarea class="form-control" id="new_description" name="description" rows="2" placeholder="Optional details..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Category -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-labelledby="editCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content card-custom">
            <form method="POST" action="<?= e(url('public/categories.php')) ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="category_id" id="edit_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="editCategoryModalLabel"><i class="bi bi-pencil text-primary me-2"></i>Edit Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body small">
                    <div class="mb-3">
                        <label for="edit_code" class="form-label fw-semibold text-muted">Category Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control font-monospace" id="edit_code" name="code" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_name" class="form-label fw-semibold text-muted">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_is_asset" class="form-label fw-semibold text-muted">Category Classification <span class="text-danger">*</span></label>
                        <select class="form-select" id="edit_is_asset" name="is_asset_category" required>
                            <option value="0">Consumable Supplies</option>
                            <option value="1">Fixed Assets</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label fw-semibold text-muted">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Update Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const editButtons = document.querySelectorAll('.btn-edit-category');
    const editModal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
    
    editButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('edit_id').value = btn.getAttribute('data-id');
            document.getElementById('edit_code').value = btn.getAttribute('data-code');
            document.getElementById('edit_name').value = btn.getAttribute('data-name');
            document.getElementById('edit_is_asset').value = btn.getAttribute('data-is-asset');
            document.getElementById('edit_description').value = btn.getAttribute('data-description');
            editModal.show();
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
