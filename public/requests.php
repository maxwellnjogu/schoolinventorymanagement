<?php
/**
 * Department Stock Requests (Requisitions) Directory
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Stock Requisitions';

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

$statusFilter = $_GET['status'] ?? '';
$departmentFilter = !empty($_GET['department_id']) ? (int)$_GET['department_id'] : null;
$search = trim($_GET['search'] ?? '');

$where = ["1=1"];
$params = [];

// Role-based visibility: Department heads only see their own department's requisitions
if ($user['role'] === 'department_head') {
    if (!empty($user['department_id'])) {
        $where[] = "sr.department_id = ?";
        $params[] = (int)$user['department_id'];
    } else {
        // Fallback to requester user ID if no department assigned
        $where[] = "sr.requested_by = ?";
        $params[] = (int)$user['id'];
    }
} elseif ($departmentFilter) {
    $where[] = "sr.department_id = ?";
    $params[] = $departmentFilter;
}

if (!empty($statusFilter)) {
    $where[] = "sr.status = ?";
    $params[] = $statusFilter;
}

if (!empty($search)) {
    $where[] = "(sr.request_number LIKE ? OR d.name LIKE ? OR u.full_name LIKE ? OR sr.remarks LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$whereClause = implode(" AND ", $where);

// Fetch requests
$sql = "
    SELECT sr.*, d.name AS department_name, d.code AS department_code,
           u.full_name AS requester_name,
           app.full_name AS approver_name,
           COUNT(sri.id) AS item_count,
           SUM(sri.quantity_requested) AS total_requested_qty,
           SUM(sri.quantity_approved) AS total_approved_qty,
           SUM(sri.quantity_issued) AS total_issued_qty
    FROM `stock_requests` sr
    JOIN `departments` d ON sr.department_id = d.id
    LEFT JOIN `users` u ON sr.requested_by = u.id
    LEFT JOIN `users` app ON sr.approved_by = app.id
    LEFT JOIN `stock_request_items` sri ON sr.id = sri.request_id
    WHERE {$whereClause}
    GROUP BY sr.id
    ORDER BY sr.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// Departments for filter dropdown
$departments = $pdo->query("SELECT id, name, code FROM `departments` ORDER BY name ASC")->fetchAll();

// Counts by status for quick tabs
$countQuery = ($user['role'] === 'department_head' && !empty($user['department_id']))
    ? "SELECT status, COUNT(*) as cnt FROM `stock_requests` WHERE department_id = {$user['department_id']} GROUP BY status"
    : "SELECT status, COUNT(*) as cnt FROM `stock_requests` GROUP BY status";
$countsStmt = $pdo->query($countQuery);
$statusCounts = [];
while ($row = $countsStmt->fetch()) {
    $statusCounts[$row['status']] = (int)$row['cnt'];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/dashboard.php')) ?>" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">Stock Requisitions</li>
            </ol>
        </nav>
        <h2 class="h3 fw-bold mb-0">Department Stock Requests</h2>
        <p class="text-muted small mb-0">Internal requisitions from high school departments (Science, Boarding, IT, Administration).</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <a href="<?= e(url('public/request_create.php')) ?>" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> New Stock Request
        </a>
    </div>
</div>

<!-- Status Filters Bar -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="<?= e(url('public/requests.php')) ?>" class="btn btn-sm <?= empty($statusFilter) ? 'btn-dark' : 'btn-outline-secondary' ?>">
        All Requests (<?= array_sum($statusCounts) ?>)
    </a>
    <a href="<?= e(url('public/requests.php?status=pending')) ?>" class="btn btn-sm <?= ($statusFilter === 'pending') ? 'btn-warning text-dark' : 'btn-outline-warning text-dark' ?>">
        Pending Approval (<?= $statusCounts['pending'] ?? 0 ?>)
    </a>
    <a href="<?= e(url('public/requests.php?status=approved')) ?>" class="btn btn-sm <?= ($statusFilter === 'approved') ? 'btn-info text-white' : 'btn-outline-info' ?>">
        Approved (Ready for Issue) (<?= $statusCounts['approved'] ?? 0 ?>)
    </a>
    <a href="<?= e(url('public/requests.php?status=partially_issued')) ?>" class="btn btn-sm <?= ($statusFilter === 'partially_issued') ? 'btn-primary' : 'btn-outline-primary' ?>">
        Partially Issued (<?= $statusCounts['partially_issued'] ?? 0 ?>)
    </a>
    <a href="<?= e(url('public/requests.php?status=issued')) ?>" class="btn btn-sm <?= ($statusFilter === 'issued') ? 'btn-success' : 'btn-outline-success' ?>">
        Fully Issued (<?= $statusCounts['issued'] ?? 0 ?>)
    </a>
    <a href="<?= e(url('public/requests.php?status=rejected')) ?>" class="btn btn-sm <?= ($statusFilter === 'rejected') ? 'btn-danger' : 'btn-outline-danger' ?>">
        Rejected (<?= $statusCounts['rejected'] ?? 0 ?>)
    </a>
</div>

<!-- Search & Filters -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="<?= e(url('public/requests.php')) ?>" class="row g-3 align-items-end">
            <input type="hidden" name="status" value="<?= e($statusFilter) ?>">

            <div class="col-md-5">
                <label for="search" class="form-label small fw-semibold text-muted">Search Requests</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" id="search" class="form-control" 
                           placeholder="Request #, department, teacher or purpose..." 
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
                <a href="<?= e(url('public/requests.php')) ?>" class="btn btn-outline-secondary" title="Reset Filters">
                    <i class="bi bi-x-circle"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Requests Table -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fw-bold text-primary">
            <i class="bi bi-file-earmark-text me-2"></i>Requisitions (<?= count($requests) ?>)
        </h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($requests)): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-inbox display-4 d-block mb-3 text-secondary opacity-50"></i>
                <h5>No stock requisitions found</h5>
                <p class="small text-muted">Create a new stock request to requisition materials from store.</p>
                <a href="<?= e(url('public/request_create.php')) ?>" class="btn btn-primary btn-sm mt-2">
                    <i class="bi bi-plus-circle me-1"></i> New Request
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Request #</th>
                            <th>Request Date</th>
                            <th>Department</th>
                            <th>Requested By</th>
                            <th class="text-center">Items</th>
                            <th class="text-center">Requested Qty</th>
                            <th class="text-center">Issued Qty</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $req): ?>
                            <tr>
                                <td class="fw-bold font-monospace text-primary">
                                    <a href="<?= e(url('public/request_view.php?id=' . $req['id'])) ?>" class="text-decoration-none">
                                        <?= e($req['request_number']) ?>
                                    </a>
                                </td>
                                <td><?= e(date('d M Y', strtotime($req['request_date']))) ?></td>
                                <td>
                                    <div class="fw-semibold"><?= e($req['department_name']) ?></div>
                                    <span class="badge bg-light text-dark"><?= e($req['department_code']) ?></span>
                                </td>
                                <td><?= e($req['requester_name'] ?? 'User') ?></td>
                                <td class="text-center"><?= (int)$req['item_count'] ?> items</td>
                                <td class="text-center fw-bold"><?= number_format((float)$req['total_requested_qty'], 1) ?></td>
                                <td class="text-center fw-bold text-success"><?= number_format((float)$req['total_issued_qty'], 1) ?></td>
                                <td class="text-center">
                                    <span class="badge <?= e(request_status_badge_class($req['status'])) ?>">
                                        <?= e(ucwords(str_replace('_', ' ', $req['status']))) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="<?= e(url('public/request_view.php?id=' . $req['id'])) ?>" class="btn btn-sm btn-outline-primary">
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
