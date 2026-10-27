<?php
/**
 * Storage Locations Management
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Storage Locations';

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

// Types mapping
$locationTypes = [
    'main_store'       => 'Central Store / Warehouse',
    'department_store' => 'Departmental Store',
    'laboratory'       => 'Laboratory Preparation Room',
    'workshop'         => 'Workshop / Maintenance',
    'kitchen'          => 'Dining Hall & Kitchen Pantry',
    'library'          => 'Library Archive Store',
    'office'           => 'Staff / Bursary Office Store'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    require_csrf_token();

    $action = $_POST['action'] ?? 'create';
    $locId = (int)($_POST['location_id'] ?? 0);
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $type = trim($_POST['type'] ?? 'main_store');
    $description = trim($_POST['description'] ?? '');

    if (empty($code)) {
        $errors[] = 'Location Code is required.';
    }
    if (empty($name)) {
        $errors[] = 'Location Name is required.';
    }
    if (!array_key_exists($type, $locationTypes)) {
        $type = 'main_store';
    }

    if (empty($errors)) {
        try {
            if ($action === 'create') {
                $chk = $pdo->prepare("SELECT id FROM `locations` WHERE `code` = ?");
                $chk->execute([$code]);
                if ($chk->fetch()) {
                    $errors[] = "Location code '{$code}' already exists.";
                } else {
                    $insertStmt = $pdo->prepare("
                        INSERT INTO `locations` (`code`, `name`, `type`, `description`, `created_at`)
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $insertStmt->execute([$code, $name, $type, $description ?: null]);
                    log_audit_action($pdo, 'LOCATION_CREATED', 'locations', (int)$pdo->lastInsertId(), "Created location {$name} ({$code})");
                    set_flash('success', "Storage location '{$name}' created successfully!");
                    redirect('public/locations.php');
                }
            } elseif ($action === 'update' && $locId > 0) {
                $chk = $pdo->prepare("SELECT id FROM `locations` WHERE `code` = ? AND id != ?");
                $chk->execute([$code, $locId]);
                if ($chk->fetch()) {
                    $errors[] = "Location code '{$code}' is already used by another location.";
                } else {
                    $updStmt = $pdo->prepare("
                        UPDATE `locations`
                        SET `code` = ?, `name` = ?, `type` = ?, `description` = ?, `updated_at` = NOW()
                        WHERE `id` = ?
                    ");
                    $updStmt->execute([$code, $name, $type, $description ?: null, $locId]);
                    log_audit_action($pdo, 'LOCATION_UPDATED', 'locations', $locId, "Updated location {$name}");
                    set_flash('success', "Storage location '{$name}' updated successfully!");
                    redirect('public/locations.php');
                }
            }
        } catch (PDOException $e) {
            error_log('Location error: ' . $e->getMessage());
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Fetch locations with item counts
$query = "
    SELECT l.*, COUNT(i.id) AS item_count
    FROM `locations` l
    LEFT JOIN `items` i ON l.id = i.location_id
    GROUP BY l.id
    ORDER BY l.name ASC
";
$locations = $pdo->query($query)->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-geo-alt-fill text-primary me-2"></i>Storage Locations & Warehouses</h4>
        <div class="text-muted small">Manage school stock depositories, laboratory prep rooms, workshops, and kitchen pantries.</div>
    </div>
    <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary d-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#newLocationModal">
            <i class="bi bi-plus-circle-fill"></i>
            <span>Add New Location</span>
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
                        <th>Location Name</th>
                        <th>Facility Type</th>
                        <th>Description</th>
                        <th class="text-center">Stored Items</th>
                        <?php if ($canManage): ?>
                            <th class="text-end pe-3">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="small">
                    <?php if (empty($locations)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">No storage locations registered yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($locations as $loc): ?>
                            <tr>
                                <td class="ps-3">
                                    <code class="fw-bold text-dark"><?= e($loc['code']) ?></code>
                                </td>
                                <td class="fw-bold text-dark">
                                    <?= e($loc['name']) ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <?= e($locationTypes[$loc['type']] ?? $loc['type']) ?>
                                    </span>
                                </td>
                                <td class="text-muted">
                                    <?= e($loc['description'] ?? '-') ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark border px-2 py-1">
                                        <?= (int)$loc['item_count'] ?> item<?= (int)$loc['item_count'] === 1 ? '' : 's' ?>
                                    </span>
                                </td>
                                <?php if ($canManage): ?>
                                    <td class="text-end pe-3">
                                        <button type="button" class="btn btn-sm btn-outline-primary btn-edit-location" 
                                            data-id="<?= (int)$loc['id'] ?>"
                                            data-code="<?= e($loc['code']) ?>"
                                            data-name="<?= e($loc['name']) ?>"
                                            data-type="<?= e($loc['type']) ?>"
                                            data-description="<?= e($loc['description'] ?? '') ?>">
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

<!-- Modal: New Location -->
<div class="modal fade" id="newLocationModal" tabindex="-1" aria-labelledby="newLocationModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content card-custom">
            <form method="POST" action="<?= e(url('public/locations.php')) ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="newLocationModalLabel"><i class="bi bi-plus-circle text-primary me-2"></i>Create Storage Location</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body small">
                    <div class="mb-3">
                        <label for="new_code" class="form-label fw-semibold text-muted">Location Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control font-monospace" id="new_code" name="code" placeholder="e.g. LOC-SCI-LAB" required>
                    </div>
                    <div class="mb-3">
                        <label for="new_name" class="form-label fw-semibold text-muted">Location Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="new_name" name="name" placeholder="e.g. Physics Prep Room Shelf A" required>
                    </div>
                    <div class="mb-3">
                        <label for="new_type" class="form-label fw-semibold text-muted">Facility Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="new_type" name="type" required>
                            <?php foreach ($locationTypes as $tKey => $tLabel): ?>
                                <option value="<?= e($tKey) ?>"><?= e($tLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="new_description" class="form-label fw-semibold text-muted">Description / Notes</label>
                        <textarea class="form-control" id="new_description" name="description" rows="2" placeholder="Building, room number, or storage rack details..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Save Location</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Location -->
<div class="modal fade" id="editLocationModal" tabindex="-1" aria-labelledby="editLocationModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content card-custom">
            <form method="POST" action="<?= e(url('public/locations.php')) ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="location_id" id="edit_loc_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="editLocationModalLabel"><i class="bi bi-pencil text-primary me-2"></i>Edit Storage Location</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body small">
                    <div class="mb-3">
                        <label for="edit_loc_code" class="form-label fw-semibold text-muted">Location Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control font-monospace" id="edit_loc_code" name="code" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_loc_name" class="form-label fw-semibold text-muted">Location Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_loc_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_loc_type" class="form-label fw-semibold text-muted">Facility Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="edit_loc_type" name="type" required>
                            <?php foreach ($locationTypes as $tKey => $tLabel): ?>
                                <option value="<?= e($tKey) ?>"><?= e($tLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="edit_loc_description" class="form-label fw-semibold text-muted">Description / Notes</label>
                        <textarea class="form-control" id="edit_loc_description" name="description" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Update Location</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const editButtons = document.querySelectorAll('.btn-edit-location');
    const editModal = new bootstrap.Modal(document.getElementById('editLocationModal'));
    
    editButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('edit_loc_id').value = btn.getAttribute('data-id');
            document.getElementById('edit_loc_code').value = btn.getAttribute('data-code');
            document.getElementById('edit_loc_name').value = btn.getAttribute('data-name');
            document.getElementById('edit_loc_type').value = btn.getAttribute('data-type');
            document.getElementById('edit_loc_description').value = btn.getAttribute('data-description');
            editModal.show();
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
