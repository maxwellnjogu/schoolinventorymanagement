<?php
/**
 * Goods Received Notes (GRN) Directory
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Goods Received Notes';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/procurement_helpers.php';

require_role(['admin', 'storekeeper', 'auditor']);

$user = current_user();
$pdo = Database::getConnection();

if (!$pdo) {
    die("Database connection error: " . Database::getError());
}

$search = trim($_GET['search'] ?? '');
$supplierId = !empty($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : null;

$where = ["1=1"];
$params = [];

if (!empty($search)) {
    $where[] = "(grn.grn_number LIKE ? OR grn.delivery_note_number LIKE ? OR po.po_number LIKE ? OR s.company_name LIKE ?)";
    $term = "%{$search}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($supplierId) {
    $where[] = "grn.supplier_id = ?";
    $params[] = $supplierId;
}

$whereClause = implode(" AND ", $where);

// Fetch GRNs
$sql = "
    SELECT grn.*, po.po_number, s.company_name, s.supplier_code,
           u.full_name AS received_by_name,
           COUNT(gri.id) AS total_items_count,
           SUM(gri.quantity_received) AS total_quantity,
           SUM(gri.total_cost) AS total_valuation
    FROM `goods_received_notes` grn
    JOIN `purchase_orders` po ON grn.po_id = po.id
    JOIN `suppliers` s ON grn.supplier_id = s.id
    LEFT JOIN `goods_received_items` gri ON grn.id = gri.grn_id
    LEFT JOIN `users` u ON grn.received_by = u.id
    WHERE {$whereClause}
    GROUP BY grn.id
    ORDER BY grn.id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$grns = $stmt->fetchAll();

// Suppliers for filter
$suppliers = $pdo->query("SELECT id, company_name FROM `suppliers` ORDER BY company_name ASC")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/dashboard.php')) ?>" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">Goods Received Notes</li>
            </ol>
        </nav>
        <h2 class="h3 fw-bold mb-0">Goods Received Notes (GRN)</h2>
        <p class="text-muted small mb-0">Archive of official physical deliveries received into the central high school store.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <?php if (has_role(['admin', 'storekeeper'])): ?>
            <a href="<?= e(url('public/grn_create.php')) ?>" class="btn btn-primary">
                <i class="bi bi-box-arrow-in-down me-1"></i> Receive Delivery (GRN)
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Filters -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <form method="GET" action="<?= e(url('public/grns.php')) ?>" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label for="search" class="form-label small fw-semibold text-muted">Search Records</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" id="search" class="form-control" 
                           placeholder="GRN #, PO #, delivery note or supplier..." 
                           value="<?= e($search) ?>">
                </div>
            </div>

            <div class="col-md-4">
                <label for="supplier_id" class="form-label small fw-semibold text-muted">Filter Supplier</label>
                <select name="supplier_id" id="supplier_id" class="form-select">
                    <option value="">-- All Suppliers --</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= ($supplierId === (int)$s['id']) ? 'selected' : '' ?>>
                            <?= e($s['company_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="bi bi-filter me-1"></i> Filter
                </button>
                <a href="<?= e(url('public/grns.php')) ?>" class="btn btn-outline-secondary" title="Reset Filters">
                    <i class="bi bi-x-circle"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- GRN List Table -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fw-bold text-primary">
            <i class="bi bi-journal-check me-2"></i>Delivery Records (<?= count($grns) ?>)
        </h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($grns)): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-inbox display-4 d-block mb-3 text-secondary opacity-50"></i>
                <h5>No Goods Received Notes found</h5>
                <p class="small text-muted">No physical deliveries match your selected criteria.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>GRN Number</th>
                            <th>Date Received</th>
                            <th>Purchase Order</th>
                            <th>Supplier</th>
                            <th>Delivery Note #</th>
                            <th class="text-center">Items Count</th>
                            <th class="text-center">Total Quantity</th>
                            <th class="text-end">Batch Valuation</th>
                            <th>Received By</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grns as $row): ?>
                            <tr>
                                <td class="fw-bold font-monospace text-primary">
                                    <a href="<?= e(url('public/grn_view.php?id=' . $row['id'])) ?>" class="text-decoration-none">
                                        <?= e($row['grn_number']) ?>
                                    </a>
                                </td>
                                <td><?= e(date('d M Y', strtotime($row['received_date']))) ?></td>
                                <td>
                                    <a href="<?= e(url('public/po_view.php?id=' . $row['po_id'])) ?>" class="badge bg-light text-dark border text-decoration-none font-monospace">
                                        <?= e($row['po_number']) ?>
                                    </a>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= e($row['company_name']) ?></div>
                                    <span class="text-muted small"><?= e($row['supplier_code']) ?></span>
                                </td>
                                <td><?= e($row['delivery_note_number'] ?: '<span class="text-muted">-</span>') ?></td>
                                <td class="text-center"><?= (int)$row['total_items_count'] ?></td>
                                <td class="text-center fw-bold text-success"><?= number_format((float)$row['total_quantity'], 1) ?></td>
                                <td class="text-end fw-semibold text-primary"><?= format_currency((float)$row['total_valuation']) ?></td>
                                <td><?= e($row['received_by_name'] ?? 'Storekeeper') ?></td>
                                <td class="text-end">
                                    <a href="<?= e(url('public/grn_view.php?id=' . $row['id'])) ?>" class="btn btn-sm btn-outline-primary">
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
