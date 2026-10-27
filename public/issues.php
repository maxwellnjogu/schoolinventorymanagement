<?php
/**
 * Stock Issue Notes Directory
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Stock Issues';

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

$search = trim($_GET['search'] ?? '');
$departmentFilter = !empty($_GET['department_id']) ? (int)$_GET['department_id'] : null;

$where = ["1=1"];
$params = [];

if ($user['role'] === 'department_head' && !empty($user['department_id'])) {
    $where[] = "si.department_id = ?";
    $params[] = (int)$user['department_id'];
} elseif ($departmentFilter) {
    $where[] = "si.department_id = ?";
    $params[] = $departmentFilter;
}

if (!empty($search)) {
    $where[] = "(si.issue_number LIKE ? OR sr.request_number LIKE ? OR si.issued_to LIKE ? OR d.name LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$whereClause = implode(" AND ", $where);

// Fetch issue records
$sql = "
    SELECT si.*, sr.request_number, d.name AS department_name, d.code AS department_code,
           u.full_name AS issuer_name,
           COUNT(sii.id) AS item_count,
           SUM(sii.quantity_issued) AS total_quantity
    FROM `stock_issues` si
    JOIN `stock_requests` sr ON si.request_id = sr.id
    JOIN `departments` d ON si.department_id = d.id
    LEFT JOIN `users` u ON si.issued_by = u.id
    LEFT JOIN `stock_issue_items` sii ON si.id = sii.issue_id
    WHERE {$whereClause}
    GROUP BY si.id
    ORDER BY si.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$issues = $stmt->fetchAll();

$departments = $pdo->query("SELECT id, name, code FROM `departments` ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/dashboard.php')) ?>" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">Stock Issues</li>
            </ol>
        </nav>
        <h2 class="h3 fw-bold mb-0">Stock Issue Notes (Archive)</h2>
        <p class="text-muted small mb-0">Official inventory handover records to academic and operational departments.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0 d-flex justify-content-md-end gap-2">
        <a href="<?= e(url('public/stock_return.php')) ?>" class="btn btn-outline-warning">
            <i class="bi bi-arrow-return-left me-1"></i> Return Stock to Store
        </a>
        <?php if (has_role(['admin', 'storekeeper'])): ?>
            <a href="<?= e(url('public/issue_create.php')) ?>" class="btn btn-primary">
                <i class="bi bi-box-arrow-up-right me-1"></i> New Stock Issue
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Filters -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="<?= e(url('public/issues.php')) ?>" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label for="search" class="form-label small fw-semibold text-muted">Search Issue Notes</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" id="search" class="form-control" 
                           placeholder="Issue #, request #, teacher or department..." 
                           value="<?= e($search) ?>">
                </div>
            </div>

            <?php if ($user['role'] !== 'department_head'): ?>
                <div class="col-md-4">
                    <label for="department_id" class="form-label small fw-semibold text-muted">Filter Department</label>
                    <select name="department_id" id="department_id" class="form-select">
                        <option value="">-- All Departments --</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= (int)$d['id'] ?>" <?= ($departmentFilter === (int)$d['id']) ? 'selected' : '' ?>>
                                <?= e($d['name']) ?> (<?= e($d['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <div class="col-md-4"></div>
            <?php endif; ?>

            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="bi bi-filter me-1"></i> Filter
                </button>
                <a href="<?= e(url('public/issues.php')) ?>" class="btn btn-outline-secondary" title="Reset Filters">
                    <i class="bi bi-x-circle"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Issues Table -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fw-bold text-primary">
            <i class="bi bi-journal-arrow-up me-2"></i>Stock Issue Records (<?= count($issues) ?>)
        </h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($issues)): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-inbox display-4 d-block mb-3 text-secondary opacity-50"></i>
                <h5>No stock issues found</h5>
                <p class="small text-muted">No stock issue notes match your filter criteria.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Issue Number</th>
                            <th>Issue Date</th>
                            <th>Requisition #</th>
                            <th>Department</th>
                            <th>Issued To (Recipient)</th>
                            <th class="text-center">Items Count</th>
                            <th class="text-center">Total Quantity</th>
                            <th>Issued By</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($issues as $row): ?>
                            <tr>
                                <td class="fw-bold font-monospace text-primary">
                                    <a href="<?= e(url('public/issue_view.php?id=' . $row['id'])) ?>" class="text-decoration-none">
                                        <?= e($row['issue_number']) ?>
                                    </a>
                                </td>
                                <td><?= e(date('d M Y', strtotime($row['issue_date']))) ?></td>
                                <td>
                                    <a href="<?= e(url('public/request_view.php?id=' . $row['request_id'])) ?>" class="badge bg-light text-dark border text-decoration-none font-monospace">
                                        <?= e($row['request_number']) ?>
                                    </a>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= e($row['department_name']) ?></div>
                                    <span class="badge bg-light text-dark"><?= e($row['department_code']) ?></span>
                                </td>
                                <td class="fw-semibold"><?= e($row['issued_to']) ?></td>
                                <td class="text-center"><?= (int)$row['item_count'] ?></td>
                                <td class="text-center fw-bold text-danger">-<?= number_format((float)$row['total_quantity'], 1) ?></td>
                                <td><?= e($row['issuer_name'] ?? 'Storekeeper') ?></td>
                                <td class="text-end">
                                    <a href="<?= e(url('public/issue_view.php?id=' . $row['id'])) ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye me-1"></i> View
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
