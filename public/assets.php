<?php
/**
 * Fixed Asset Register Directory
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Fixed Asset Register';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/asset_helpers.php';

require_login();

$user = current_user();
$pdo = Database::getConnection();

if (!$pdo) {
    die("Database connection error: " . Database::getError());
}

$search = trim($_GET['search'] ?? '');
$categoryFilter = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$departmentFilter = !empty($_GET['department_id']) ? (int)$_GET['department_id'] : null;
$locationFilter = !empty($_GET['location_id']) ? (int)$_GET['location_id'] : null;
$conditionFilter = trim($_GET['condition'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$where = ["1=1"];
$params = [];

// Department head scoping
if ($user['role'] === 'department_head' && !empty($user['department_id'])) {
    $where[] = "fa.department_id = ?";
    $params[] = (int)$user['department_id'];
} elseif ($departmentFilter) {
    $where[] = "fa.department_id = ?";
    $params[] = $departmentFilter;
}

if (!empty($search)) {
    $where[] = "(fa.asset_tag LIKE ? OR fa.name LIKE ? OR fa.serial_number LIKE ? OR fa.model LIKE ? OR fa.manufacturer LIKE ? OR fa.assigned_to LIKE ?)";
    $term = "%{$search}%";
    for ($i = 0; $i < 6; $i++) {
        $params[] = $term;
    }
}

if ($categoryFilter) {
    $where[] = "fa.category_id = ?";
    $params[] = $categoryFilter;
}

if ($locationFilter) {
    $where[] = "fa.location_id = ?";
    $params[] = $locationFilter;
}

if (!empty($conditionFilter)) {
    $where[] = "fa.condition_status = ?";
    $params[] = $conditionFilter;
}

if (!empty($statusFilter)) {
    $where[] = "fa.status = ?";
    $params[] = $statusFilter;
}

$whereClause = implode(" AND ", $where);

// Fetch assets
$sql = "
    SELECT fa.*, c.name AS category_name,
           d.name AS department_name, d.code AS department_code,
           l.name AS location_name, l.code AS location_code, l.type AS location_type
    FROM `fixed_assets` fa
    JOIN `categories` c ON fa.category_id = c.id
    JOIN `departments` d ON fa.department_id = d.id
    JOIN `locations` l ON fa.location_id = l.id
    WHERE {$whereClause}
    ORDER BY fa.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$assets = $stmt->fetchAll();

// Summary stats
$stats = get_asset_summary_stats($pdo);

// Filter options
$categories = $pdo->query("SELECT id, name FROM `categories` ORDER BY name ASC")->fetchAll();
$departments = $pdo->query("SELECT id, name, code FROM `departments` ORDER BY name ASC")->fetchAll();
$locations = $pdo->query("SELECT id, name, code, type FROM `locations` ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/dashboard.php')) ?>" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">Fixed Asset Register</li>
            </ol>
        </nav>
        <h2 class="h3 fw-bold mb-0">Fixed Asset Register</h2>
        <p class="text-muted small mb-0">Individual tracking of school computers, lab equipment, vehicles, and furniture.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0 d-flex justify-content-md-end gap-2">
        <button type="button" class="btn btn-outline-dark" onclick="window.print();">
            <i class="bi bi-printer me-1"></i> Print Register
        </button>
        <?php if (has_role(['admin', 'storekeeper'])): ?>
            <a href="<?= e(url('public/asset_create.php')) ?>" class="btn btn-primary">
                <i class="bi bi-plus-circle me-1"></i> Register New Asset
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Key Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-primary-subtle text-primary p-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                    <i class="bi bi-display fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold">Total Fixed Assets</div>
                    <div class="h4 fw-bold mb-0"><?= number_format($stats['total_assets']) ?> units</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-success-subtle text-success p-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                    <i class="bi bi-check-circle fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold">In Active Use</div>
                    <div class="h4 fw-bold mb-0 text-success"><?= number_format($stats['in_use_count']) ?> units</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-warning-subtle text-warning p-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                    <i class="bi bi-tools fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold">Under Maintenance</div>
                    <div class="h4 fw-bold mb-0 text-warning"><?= number_format($stats['under_maintenance_count']) ?> units</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle bg-info-subtle text-info p-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                    <i class="bi bi-cash-stack fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold">Capital Valuation</div>
                    <div class="h5 fw-bold mb-0 text-primary"><?= format_currency($stats['total_acquisition_cost']) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Search & Filters -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="<?= e(url('public/assets.php')) ?>" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="search" class="form-label small fw-semibold text-muted">Search Asset Tag / Serial / Name</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" id="search" class="form-control" 
                           placeholder="Tag, serial #, model, custodian..." 
                           value="<?= e($search) ?>">
                </div>
            </div>

            <div class="col-md-2">
                <label for="category_id" class="form-label small fw-semibold text-muted">Category</label>
                <select name="category_id" id="category_id" class="form-select">
                    <option value="">-- All Categories --</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>" <?= ($categoryFilter === (int)$cat['id']) ? 'selected' : '' ?>>
                            <?= e($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($user['role'] !== 'department_head'): ?>
                <div class="col-md-2">
                    <label for="department_id" class="form-label small fw-semibold text-muted">Department</label>
                    <select name="department_id" id="department_id" class="form-select">
                        <option value="">-- All Departments --</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= (int)$dept['id'] ?>" <?= ($departmentFilter === (int)$dept['id']) ? 'selected' : '' ?>>
                                <?= e($dept['name']) ?> (<?= e($dept['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="col-md-2">
                <label for="location_id" class="form-label small fw-semibold text-muted">Location</label>
                <select name="location_id" id="location_id" class="form-select">
                    <option value="">-- All Locations --</option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?= (int)$loc['id'] ?>" <?= ($locationFilter === (int)$loc['id']) ? 'selected' : '' ?>>
                            <?= e($loc['name']) ?> (<?= e($loc['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-1">
                <label for="condition" class="form-label small fw-semibold text-muted">Condition</label>
                <select name="condition" id="condition" class="form-select">
                    <option value="">All</option>
                    <option value="new" <?= ($conditionFilter === 'new') ? 'selected' : '' ?>>New</option>
                    <option value="good" <?= ($conditionFilter === 'good') ? 'selected' : '' ?>>Good</option>
                    <option value="fair" <?= ($conditionFilter === 'fair') ? 'selected' : '' ?>>Fair</option>
                    <option value="damaged" <?= ($conditionFilter === 'damaged') ? 'selected' : '' ?>>Damaged</option>
                    <option value="disposed" <?= ($conditionFilter === 'disposed') ? 'selected' : '' ?>>Disposed</option>
                </select>
            </div>

            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="bi bi-filter me-1"></i> Filter
                </button>
                <a href="<?= e(url('public/assets.php')) ?>" class="btn btn-outline-secondary" title="Reset Filters">
                    <i class="bi bi-x-circle"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Assets Table -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fw-bold text-primary">
            <i class="bi bi-archive me-2"></i>Registered Assets (<?= count($assets) ?>)
        </h5>
        <div class="small text-muted">
            <i class="bi bi-info-circle me-1"></i> Individual asset tracking is separate from consumable inventory counts.
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($assets)): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-inbox display-4 d-block mb-3 text-secondary opacity-50"></i>
                <h5>No fixed assets found</h5>
                <p class="small text-muted">No registered equipment matches the selected search and filter criteria.</p>
                <?php if (has_role(['admin', 'storekeeper'])): ?>
                    <a href="<?= e(url('public/asset_create.php')) ?>" class="btn btn-primary btn-sm mt-2">
                        <i class="bi bi-plus-circle me-1"></i> Register New Asset
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Asset ID / Tag</th>
                            <th>Asset Name & Model</th>
                            <th>Category</th>
                            <th>Serial Number</th>
                            <th>Department & Location</th>
                            <th>Acquired</th>
                            <th class="text-end">Cost (KSh)</th>
                            <th class="text-center">Condition</th>
                            <th class="text-center">Status</th>
                            <th>Custodian</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assets as $a): ?>
                            <tr>
                                <td class="fw-bold font-monospace text-primary">
                                    <a href="<?= e(url('public/asset_view.php?id=' . $a['id'])) ?>" class="text-decoration-none">
                                        <?= e($a['asset_tag']) ?>
                                    </a>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark">
                                        <a href="<?= e(url('public/asset_view.php?id=' . $a['id'])) ?>" class="text-decoration-none text-dark">
                                            <?= e($a['name']) ?>
                                        </a>
                                    </div>
                                    <div class="text-muted small"><?= e($a['model'] ?: $a['manufacturer'] ?: 'Standard') ?></div>
                                </td>
                                <td><span class="badge bg-light text-dark"><?= e($a['category_name']) ?></span></td>
                                <td class="font-monospace small"><?= e($a['serial_number'] ?: '<span class="text-muted">N/A</span>') ?></td>
                                <td>
                                    <div class="fw-semibold small"><?= e($a['department_name']) ?></div>
                                    <div class="text-muted small"><?= e($a['location_name']) ?> (<?= e($a['location_code']) ?>)</div>
                                </td>
                                <td class="small text-nowrap"><?= e(date('d M Y', strtotime($a['purchase_date']))) ?></td>
                                <td class="text-end fw-semibold text-primary"><?= format_currency((float)$a['purchase_cost']) ?></td>
                                <td class="text-center">
                                    <span class="badge <?= e(asset_condition_badge_class($a['condition_status'])) ?> text-capitalize">
                                        <?= e($a['condition_status']) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?= e(asset_status_badge_class($a['status'])) ?> text-capitalize small">
                                        <?= e(str_replace('_', ' ', $a['status'])) ?>
                                    </span>
                                </td>
                                <td class="small"><?= e($a['assigned_to'] ?: '<span class="text-muted">-</span>') ?></td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="<?= e(url('public/asset_view.php?id=' . $a['id'])) ?>" class="btn btn-outline-primary" title="View Dossier">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if (has_role(['admin', 'storekeeper'])): ?>
                                            <a href="<?= e(url('public/asset_edit.php?id=' . $a['id'])) ?>" class="btn btn-outline-secondary" title="Edit Metadata">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
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
