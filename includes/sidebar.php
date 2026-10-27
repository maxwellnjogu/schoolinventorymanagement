<?php
/**
 * Shared Sidebar Navigation Layout
 * Role-Based Access Control Menu
 * St. Jude High School Inventory Management System
 */

$user = current_user();
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$role = $user['role'] ?? 'storekeeper';
?>
<aside class="app-sidebar" id="appSidebar">
    <!-- Brand / School Header -->
    <div class="sidebar-brand">
        <div class="sidebar-brand-icon">
            <i class="bi bi-building"></i>
        </div>
        <div class="sidebar-brand-text">
            SJHS Inventory
            <small>High School Portal</small>
        </div>
    </div>

    <!-- User Profile Strip -->
    <?php if ($user): ?>
        <div class="sidebar-user d-flex align-items-center gap-2">
            <div class="avatar-circle bg-secondary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px; font-weight: 600;">
                <?= strtoupper(substr(e($user['username']), 0, 1)) ?>
            </div>
            <div class="text-truncate" style="max-width: 170px;">
                <div class="text-white fw-semibold small text-truncate"><?= e($user['full_name']) ?></div>
                <span class="badge <?= e(role_badge_class($user['role'])) ?> badge-role">
                    <?= e(role_display_name($user['role'])) ?>
                </span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Navigation Links -->
    <div class="sidebar-nav-container">
        <nav class="sidebar-nav nav flex-column">
            <!-- 1. Dashboard -->
            <div class="nav-section-title">Overview</div>
            <a class="nav-link <?= $currentScript === 'dashboard.php' ? 'active' : '' ?>" href="<?= e(url('public/dashboard.php')) ?>">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard</span>
            </a>

            <!-- 2. Inventory Management -->
            <div class="nav-section-title">Stock & Catalog</div>
            <a class="nav-link <?= in_array($currentScript, ['items.php', 'item_create.php', 'item_edit.php', 'item_movements.php']) ? 'active' : '' ?>" href="<?= e(url('public/items.php')) ?>">
                <i class="bi bi-box-seam"></i>
                <span>Inventory Items</span>
            </a>
            <a class="nav-link <?= in_array($currentScript, ['categories.php', 'category_create.php', 'category_edit.php']) ? 'active' : '' ?>" href="<?= e(url('public/categories.php')) ?>">
                <i class="bi bi-tags"></i>
                <span>Categories</span>
            </a>
            <a class="nav-link <?= in_array($currentScript, ['locations.php', 'location_create.php', 'location_edit.php']) ? 'active' : '' ?>" href="<?= e(url('public/locations.php')) ?>">
                <i class="bi bi-geo-alt"></i>
                <span>Storage Locations</span>
            </a>

            <!-- 3. Procurement & Supply -->
            <?php if (has_role(['admin', 'storekeeper'])): ?>
                <div class="nav-section-title">Procurement</div>
                <a class="nav-link <?= $currentScript === 'suppliers.php' ? 'active' : '' ?>" href="<?= e(url('public/suppliers.php')) ?>">
                    <i class="bi bi-truck"></i>
                    <span>Suppliers</span>
                </a>
                <a class="nav-link <?= in_array($currentScript, ['purchase_orders.php', 'po_create.php', 'po_view.php']) ? 'active' : '' ?>" href="<?= e(url('public/purchase_orders.php')) ?>">
                    <i class="bi bi-receipt-cutoff"></i>
                    <span>Purchase Orders</span>
                </a>
                <a class="nav-link <?= in_array($currentScript, ['grns.php', 'grn_create.php', 'grn_view.php']) ? 'active' : '' ?>" href="<?= e(url('public/grns.php')) ?>">
                    <i class="bi bi-box-arrow-in-down"></i>
                    <span>Goods Receiving (GRN)</span>
                </a>
            <?php endif; ?>

            <!-- 4. Requisitions & Issues -->
            <div class="nav-section-title">Requisitions</div>
            <a class="nav-link <?= in_array($currentScript, ['requests.php', 'request_create.php', 'request_view.php']) ? 'active' : '' ?>" href="<?= e(url('public/requests.php')) ?>">
                <i class="bi bi-file-earmark-text"></i>
                <span>Stock Requisitions</span>
            </a>
            <?php if (has_role(['admin', 'storekeeper'])): ?>
                <a class="nav-link <?= in_array($currentScript, ['issues.php', 'issue_create.php', 'issue_view.php']) ? 'active' : '' ?>" href="<?= e(url('public/issues.php')) ?>">
                    <i class="bi bi-box-arrow-up-right"></i>
                    <span>Stock Issuing</span>
                </a>
                <a class="nav-link <?= $currentScript === 'stock_return.php' ? 'active' : '' ?>" href="<?= e(url('public/stock_return.php')) ?>">
                    <i class="bi bi-arrow-return-left"></i>
                    <span>Department Returns</span>
                </a>
            <?php endif; ?>

            <!-- 5. Fixed Asset Register -->
            <div class="nav-section-title">Fixed Assets</div>
            <a class="nav-link <?= in_array($currentScript, ['assets.php', 'asset_create.php', 'asset_edit.php', 'asset_view.php']) ? 'active' : '' ?>" href="<?= e(url('public/assets.php')) ?>">
                <i class="bi bi-display"></i>
                <span>Asset Register</span>
            </a>

            <!-- 6. Movements & Audit -->
            <div class="nav-section-title">Logs & Reports</div>
            <?php if (has_role(['admin', 'storekeeper', 'auditor'])): ?>
                <a class="nav-link <?= in_array($currentScript, ['movements.php', 'stock_adjustment.php']) ? 'active' : '' ?>" href="<?= e(url('public/movements.php')) ?>">
                    <i class="bi bi-arrow-left-right"></i>
                    <span>Stock Movements</span>
                </a>
            <?php endif; ?>
            <?php if (has_role(['admin', 'auditor'])): ?>
                <a class="nav-link <?= $currentScript === 'reports.php' ? 'active' : '' ?>" href="<?= e(url('public/dashboard.php#reports')) ?>">
                    <i class="bi bi-graph-up-arrow"></i>
                    <span>Reports & Analytics</span>
                    <span class="badge bg-secondary ms-auto text-xs">Phase 6</span>
                </a>
                <a class="nav-link <?= $currentScript === 'audit_logs.php' ? 'active' : '' ?>" href="<?= e(url('public/dashboard.php#audit')) ?>">
                    <i class="bi bi-shield-check"></i>
                    <span>Audit Logs</span>
                    <span class="badge bg-secondary ms-auto text-xs">Phase 6</span>
                </a>
            <?php endif; ?>

            <!-- 7. Administration & Profile -->
            <div class="nav-section-title">Account & System</div>
            <a class="nav-link <?= $currentScript === 'profile.php' ? 'active' : '' ?>" href="<?= e(url('public/profile.php')) ?>">
                <i class="bi bi-person-circle"></i>
                <span>My Profile</span>
            </a>
            <?php if (has_role('admin')): ?>
                <a class="nav-link <?= $currentScript === 'setup_database.php' ? 'active' : '' ?>" href="<?= e(url('setup_database.php')) ?>">
                    <i class="bi bi-database-gear text-warning"></i>
                    <span class="text-warning">DB Setup Utility</span>
                </a>
            <?php endif; ?>
        </nav>
    </div>

    <!-- Sign Out Footer Button -->
    <div class="p-3 bg-dark border-top border-secondary">
        <form action="<?= e(url('public/logout.php')) ?>" method="POST" class="m-0">
            <?= csrf_input() ?>
            <button type="submit" class="btn btn-outline-danger btn-sm w-100 d-flex align-items-center justify-content-center gap-2">
                <i class="bi bi-box-arrow-right"></i>
                <span>Sign Out</span>
            </button>
        </form>
    </div>
</aside>
