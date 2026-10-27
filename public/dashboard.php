<?php
/**
 * Main Application Dashboard
 * St. Jude High School Inventory Management System
 * Live real MySQL statistics, low-stock alerts, and Chart.js integration
 */

$pageTitle = 'Dashboard';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

// Authentication Guard
require_login();

$user = current_user();
$pdo = Database::getConnection();

// Default metrics
$totalItems = 0;
$totalAssets = 0;
$lowStockCount = 0;
$outOfStockCount = 0;
$healthyStockCount = 0;
$totalInventoryValue = 0.0;
$totalDepartments = 0;
$totalLocations = 0;

$lowStockItems = [];
$recentMovements = [];
$categoryDistribution = [];

if ($pdo) {
    try {
        // 1. Total Consumable Catalog Items
        $stmt = $pdo->query("SELECT COUNT(*) FROM `items` WHERE `is_asset` = 0 AND `status` = 'active'");
        $totalItems = (int)$stmt->fetchColumn();

        // 2. Total Fixed Assets
        $stmt = $pdo->query("SELECT COUNT(*) FROM `fixed_assets` WHERE `condition_status` != 'disposed'");
        $totalAssets = (int)$stmt->fetchColumn();

        // 3. Total Valuation of Stock on Hand
        $stmt = $pdo->query("SELECT COALESCE(SUM(`quantity_on_hand` * `unit_cost`), 0.0) FROM `items` WHERE `status` = 'active'");
        $totalInventoryValue = (float)$stmt->fetchColumn();

        // 4. Stock Health Breakdown
        $stmt = $pdo->query("SELECT COUNT(*) FROM `items` WHERE `quantity_on_hand` <= `reorder_level` AND `quantity_on_hand` > 0 AND `status` = 'active'");
        $lowStockCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM `items` WHERE `quantity_on_hand` <= 0 AND `status` = 'active'");
        $outOfStockCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM `items` WHERE `quantity_on_hand` > `reorder_level` AND `status` = 'active'");
        $healthyStockCount = (int)$stmt->fetchColumn();

        // 5. Locations and Departments Count
        $stmt = $pdo->query("SELECT COUNT(*) FROM `departments`");
        $totalDepartments = (int)$stmt->fetchColumn();

        $stmt = $pdo->query("SELECT COUNT(*) FROM `locations`");
        $totalLocations = (int)$stmt->fetchColumn();

        // 6. Low stock items query (Appears ONLY on dashboard per business rules)
        $stmt = $pdo->query("
            SELECT i.*, c.name AS category_name, l.name AS location_name
            FROM `items` i
            LEFT JOIN `categories` c ON i.category_id = c.id
            LEFT JOIN `locations` l ON i.location_id = l.id
            WHERE i.is_asset = 0 
              AND i.quantity_on_hand <= i.reorder_level 
              AND i.status = 'active'
            ORDER BY (i.quantity_on_hand / CASE WHEN i.reorder_level = 0 THEN 1 ELSE i.reorder_level END) ASC
            LIMIT 15
        ");
        $lowStockItems = $stmt->fetchAll();

        // 7. Recent Real Stock Movements
        $stmt = $pdo->query("
            SELECT sm.*, i.name AS item_name, i.item_code, i.unit_of_measure,
                   u.full_name AS actor_name, u.role AS actor_role
            FROM `stock_movements` sm
            JOIN `items` i ON sm.item_id = i.id
            LEFT JOIN `users` u ON sm.performed_by = u.id
            ORDER BY sm.created_at DESC, sm.id DESC
            LIMIT 6
        ");
        $recentMovements = $stmt->fetchAll();

        // 8. Category Breakdown for Chart.js
        $stmt = $pdo->query("
            SELECT c.name AS category_name, COUNT(i.id) AS item_count,
                   COALESCE(SUM(i.quantity_on_hand * i.unit_cost), 0.0) AS category_value
            FROM `categories` c
            LEFT JOIN `items` i ON c.id = i.category_id AND i.status = 'active'
            GROUP BY c.id
            HAVING item_count > 0
            ORDER BY item_count DESC
            LIMIT 6
        ");
        $categoryDistribution = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Dashboard query error: " . $e->getMessage());
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Welcome Hero Card -->
<div class="card card-custom mb-4 border-0 shadow-sm" style="background: linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%); color: #ffffff;">
    <div class="card-body p-4">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge <?= e(role_badge_class($user['role'])) ?> px-3 py-1 fs-6">
                        <?= e(role_display_name($user['role'])) ?>
                    </span>
                    <span class="text-white-50">|</span>
                    <span class="text-white-50 small"><i class="bi bi-geo-alt me-1"></i><?= e($user['department_name']) ?></span>
                </div>
                <h3 class="fw-bold mb-1">Welcome, <?= e($user['full_name']) ?></h3>
                <p class="text-white-50 mb-0 small">
                    High School Central Inventory & Asset Management Console. Operating currency fixed to <strong><?= e(APP_CURRENCY) ?></strong>.
                </p>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <div class="text-white-50 small">Current System Time (EAT):</div>
                <div class="fs-5 fw-bold text-white"><?= date('l, d M Y') ?></div>
                <div class="text-white-50 small"><?= date('h:i A') ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Business Rule: Low-stock Alerts (Appears ONLY on Dashboard) -->
<?php if (!empty($lowStockItems)): ?>
    <div class="card border-warning mb-4 shadow-sm" style="border-left: 5px solid #d97706 !important;">
        <div class="card-header bg-warning bg-opacity-10 text-dark d-flex align-items-center justify-content-between py-3">
            <div class="d-flex align-items-center">
                <i class="bi bi-exclamation-triangle-fill text-warning fs-4 me-2"></i>
                <span class="fw-bold fs-6">Active Low-Stock Alerts (<?= count($lowStockItems) ?> Consumable <?= count($lowStockItems) === 1 ? 'Item' : 'Items' ?> Require Restocking)</span>
            </div>
            <span class="badge bg-warning text-dark px-3 py-2">Restock Required</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small">
                        <tr>
                            <th class="ps-3" style="width: 130px;">Item Code</th>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Storage Location</th>
                            <th class="text-center">Current Stock</th>
                            <th class="text-center">Reorder Threshold</th>
                            <th class="text-center">Restock Deficit</th>
                            <th class="text-end pe-3">Action</th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <?php foreach ($lowStockItems as $item): ?>
                            <?php 
                                $qty = (float)$item['quantity_on_hand'];
                                $threshold = (float)$item['reorder_level'];
                                $deficit = max(0.0, $threshold - $qty);
                                $isZero = ($qty <= 0);
                            ?>
                            <tr>
                                <td class="ps-3">
                                    <code class="fw-bold text-dark"><?= e($item['item_code']) ?></code>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= e($item['name']) ?></div>
                                    <div class="text-muted" style="font-size: 0.72rem;">Unit Cost: <?= format_currency($item['unit_cost']) ?></div>
                                </td>
                                <td><?= e($item['category_name'] ?? 'General') ?></td>
                                <td><i class="bi bi-geo-alt text-muted me-1"></i><?= e($item['location_name'] ?? 'Main Store') ?></td>
                                <td class="text-center">
                                    <?php if ($isZero): ?>
                                        <span class="badge bg-danger text-white fs-6">0.0 <?= e($item['unit_of_measure']) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark fs-6"><?= number_format($qty, 1) ?> <?= e($item['unit_of_measure']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center fw-semibold text-muted">
                                    <?= number_format($threshold, 1) ?> <?= e($item['unit_of_measure']) ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger">
                                        Needs +<?= number_format($deficit, 1) ?> <?= e($item['unit_of_measure']) ?>
                                    </span>
                                </td>
                                <td class="text-end pe-3">
                                    <div class="btn-group btn-group-sm">
                                        <?php if (has_role(['admin', 'storekeeper'])): ?>
                                            <a href="<?= e(url('public/stock_adjustment.php?item_id=' . (int)$item['id'])) ?>" class="btn btn-sm btn-outline-warning text-dark" title="Adjust Stock">
                                                <i class="bi bi-sliders me-1"></i>Adjust
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?= e(url('public/item_movements.php?id=' . (int)$item['id'])) ?>" class="btn btn-sm btn-outline-secondary" title="View History">
                                            <i class="bi bi-clock-history"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Real Metrics Overview Grid -->
<div class="row g-3 mb-4">
    <!-- Stat 1: Consumable Inventory Items -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card shadow-sm">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label">Consumable Catalog</div>
                    <div class="stat-value text-primary"><?= number_format($totalItems) ?></div>
                    <div class="small text-muted mt-1"><i class="bi bi-box-seam me-1"></i>Active Items</div>
                </div>
                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                    <i class="bi bi-boxes"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Stat 2: Low & Out-of-Stock Alerts -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card shadow-sm">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label">Low Stock Alerts</div>
                    <div class="stat-value text-danger"><?= number_format($lowStockCount + $outOfStockCount) ?></div>
                    <div class="small text-danger mt-1">
                        <i class="bi bi-bell-fill me-1"></i><?= $outOfStockCount ?> Out, <?= $lowStockCount ?> Low
                    </div>
                </div>
                <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                    <i class="bi bi-exclamation-octagon"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Stat 3: Total Stock Valuation in KSh -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card shadow-sm">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label">Total Stock Valuation</div>
                    <div class="stat-value text-success" style="font-size: 1.45rem;">
                        <?= format_currency($totalInventoryValue) ?>
                    </div>
                    <div class="small text-muted mt-1"><i class="bi bi-cash-stack me-1"></i>KSh Cost Basis</div>
                </div>
                <div class="stat-icon bg-success bg-opacity-10 text-success">
                    <i class="bi bi-wallet2"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Stat 4: Fixed Assets Registered -->
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card shadow-sm">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label">Fixed Asset Register</div>
                    <div class="stat-value text-info"><?= number_format($totalAssets) ?></div>
                    <div class="small text-muted mt-1"><i class="bi bi-display me-1"></i>Tagged Equipment</div>
                </div>
                <div class="stat-icon bg-info bg-opacity-10 text-info">
                    <i class="bi bi-laptop"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Visual Chart.js Analytics & Quick Actions -->
<div class="row g-4 mb-4">
    <!-- Left: Chart.js Category Breakdown -->
    <div class="col-lg-7">
        <div class="card card-custom h-100 shadow-sm">
            <div class="card-header fw-bold bg-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-bar-chart-line-fill text-primary me-2"></i>Inventory Breakdown by Category</span>
                <span class="badge bg-light text-dark border">Live MySQL Data</span>
            </div>
            <div class="card-body">
                <div style="height: 260px; position: relative;">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: Stock Health Status Donut & Quick Links -->
    <div class="col-lg-5">
        <div class="card card-custom h-100 shadow-sm">
            <div class="card-header fw-bold bg-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-pie-chart-fill text-success me-2"></i>Stock Level Distribution</span>
                <span class="badge bg-light text-dark border">Health Status</span>
            </div>
            <div class="card-body d-flex flex-column justify-content-between">
                <div style="height: 190px; position: relative;">
                    <canvas id="stockHealthChart"></canvas>
                </div>
                <div class="d-flex justify-content-around text-center pt-3 border-top mt-2 small">
                    <div>
                        <div class="text-success fw-bold"><?= $healthyStockCount ?></div>
                        <div class="text-muted">Healthy</div>
                    </div>
                    <div>
                        <div class="text-warning fw-bold"><?= $lowStockCount ?></div>
                        <div class="text-muted">Low Stock</div>
                    </div>
                    <div>
                        <div class="text-danger fw-bold"><?= $outOfStockCount ?></div>
                        <div class="text-muted">Out of Stock</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions & Real Movement Ledger -->
<div class="row g-4 mb-4">
    <!-- Quick Actions Panel -->
    <div class="col-lg-4">
        <div class="card card-custom h-100 shadow-sm">
            <div class="card-header fw-bold bg-white">
                <i class="bi bi-lightning-charge-fill text-warning me-2"></i>Inventory Quick Actions
            </div>
            <div class="card-body d-flex flex-column gap-2">
                <a href="<?= e(url('public/items.php')) ?>" class="btn btn-outline-primary d-flex align-items-center justify-content-between p-2">
                    <span class="d-flex align-items-center"><i class="bi bi-search me-2"></i>Search Catalog</span>
                    <i class="bi bi-chevron-right small"></i>
                </a>
                <?php if (has_role(['admin', 'storekeeper'])): ?>
                    <a href="<?= e(url('public/item_create.php')) ?>" class="btn btn-primary d-flex align-items-center justify-content-between p-2 shadow-sm">
                        <span class="d-flex align-items-center"><i class="bi bi-plus-circle me-2"></i>Add New Item</span>
                        <i class="bi bi-chevron-right small"></i>
                    </a>
                    <a href="<?= e(url('public/stock_adjustment.php')) ?>" class="btn btn-outline-warning text-dark d-flex align-items-center justify-content-between p-2">
                        <span class="d-flex align-items-center"><i class="bi bi-sliders me-2"></i>Record Stock Adjustment</span>
                        <i class="bi bi-chevron-right small"></i>
                    </a>
                    <a href="<?= e(url('public/categories.php')) ?>" class="btn btn-outline-secondary d-flex align-items-center justify-content-between p-2">
                        <span class="d-flex align-items-center"><i class="bi bi-tags me-2"></i>Manage Categories</span>
                        <i class="bi bi-chevron-right small"></i>
                    </a>
                    <a href="<?= e(url('public/locations.php')) ?>" class="btn btn-outline-secondary d-flex align-items-center justify-content-between p-2">
                        <span class="d-flex align-items-center"><i class="bi bi-geo-alt me-2"></i>Manage Storage Locations</span>
                        <i class="bi bi-chevron-right small"></i>
                    </a>
                <?php endif; ?>
                <a href="<?= e(url('public/movements.php')) ?>" class="btn btn-outline-info d-flex align-items-center justify-content-between p-2">
                    <span class="d-flex align-items-center"><i class="bi bi-arrow-left-right me-2"></i>View Stock Movements</span>
                    <i class="bi bi-chevron-right small"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Live Recent Stock Movements -->
    <div class="col-lg-8">
        <div class="card card-custom h-100 shadow-sm">
            <div class="card-header fw-bold bg-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history text-primary me-2"></i>Live Stock Movements Ledger</span>
                <a href="<?= e(url('public/movements.php')) ?>" class="small text-decoration-none">View All Movements &rarr;</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentMovements)): ?>
                    <div class="text-center py-4 text-muted small">
                        <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                        No stock movement records logged yet.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Date</th>
                                    <th>Item</th>
                                    <th>Reference</th>
                                    <th class="text-center">Flow</th>
                                    <th class="text-end">Quantity</th>
                                    <th class="text-end pe-3">Balance After</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentMovements as $mov): ?>
                                    <tr>
                                        <td class="ps-3 text-muted"><?= format_date($mov['created_at'], 'd M, h:i A') ?></td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= e($mov['item_name']) ?></div>
                                            <code><?= e($mov['item_code']) ?></code>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border"><?= e($mov['reference_number']) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($mov['movement_type'] === 'in'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success">In</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger">Out</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-bold">
                                            <?php if ($mov['movement_type'] === 'in'): ?>
                                                <span class="text-success">+<?= number_format((float)$mov['quantity'], 1) ?></span>
                                            <?php else: ?>
                                                <span class="text-danger">-<?= number_format((float)$mov['quantity'], 1) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3 fw-bold text-dark">
                                            <?= number_format((float)$mov['balance_after'], 1) ?> <?= e($mov['unit_of_measure']) ?>
                                        </td>
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

<!-- Chart.js 4.4.2 CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. Category Bar Chart
    const categoryCtx = document.getElementById('categoryChart');
    if (categoryCtx) {
        <?php
            $catLabels = [];
            $catCounts = [];
            $catValues = [];
            foreach ($categoryDistribution as $cd) {
                $catLabels[] = $cd['category_name'];
                $catCounts[] = (int)$cd['item_count'];
                $catValues[] = (float)$cd['category_value'];
            }
        ?>
        const categoryLabels = <?= json_encode($catLabels) ?>;
        const categoryCounts = <?= json_encode($catCounts) ?>;

        new Chart(categoryCtx, {
            type: 'bar',
            data: {
                labels: categoryLabels,
                datasets: [{
                    label: 'Registered Items',
                    data: categoryCounts,
                    backgroundColor: '#0284c7',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
    }

    // 2. Stock Health Donut Chart
    const healthCtx = document.getElementById('stockHealthChart');
    if (healthCtx) {
        new Chart(healthCtx, {
            type: 'doughnut',
            data: {
                labels: ['Healthy Stock', 'Low Stock', 'Out of Stock'],
                datasets: [{
                    data: [<?= $healthyStockCount ?>, <?= $lowStockCount ?>, <?= $outOfStockCount ?>],
                    backgroundColor: ['#15803d', '#d97706', '#b91c1c'],
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, font: { size: 11 } }
                    }
                },
                cutout: '65%'
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
