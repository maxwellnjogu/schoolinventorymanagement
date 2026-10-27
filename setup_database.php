<?php
/**
 * Database Setup and Migration Diagnostic Tool
 * St. Jude High School Inventory Management System
 * 
 * Can be run from CLI: php setup_database.php
 * Or via web browser: http://localhost/schoolinventorymanagement/setup_database.php
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth.php';

$isCli = (php_sapi_name() === 'cli');
$action = $_POST['action'] ?? ($isCli ? ($argv[1] ?? 'status') : 'status');
$message = '';
$messageType = 'info';

// Web Security: Allow if CLI, OR if no tables exist yet (initial setup), OR if user is admin
if (!$isCli && is_logged_in() && !has_role('admin')) {
    redirect('public/unauthorized.php');
}

$schemaFile = __DIR__ . '/database/schema.sql';
$seedFile = __DIR__ . '/database/seed.sql';

// Function to run multi-query SQL safely
function execute_sql_file(PDO $pdo, string $filePath): array {
    if (!file_exists($filePath)) {
        return ['success' => false, 'message' => "SQL file not found at: {$filePath}"];
    }

    $sql = file_get_contents($filePath);
    if ($sql === false) {
        return ['success' => false, 'message' => "Failed to read SQL file."];
    }

    try {
        $pdo->exec($sql);
        return ['success' => true, 'message' => "Executed successfully."];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => "SQL Execution Error: " . $e->getMessage()];
    }
}

// Check database status
$dbStatus = [
    'connected'     => false,
    'error'         => null,
    'database_name' => DB_NAME,
    'host'          => DB_HOST,
    'port'          => DB_PORT,
    'user'          => DB_USER,
    'tables_found'  => [],
    'has_users'     => false,
    'user_count'    => 0
];

$pdo = Database::getConnection();
if ($pdo) {
    $dbStatus['connected'] = true;
    try {
        $stmt = $pdo->query("SHOW TABLES");
        $dbStatus['tables_found'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (in_array('users', $dbStatus['tables_found'], true)) {
            $userStmt = $pdo->query("SELECT COUNT(*) FROM `users`");
            $dbStatus['user_count'] = (int)$userStmt->fetchColumn();
            $dbStatus['has_users'] = $dbStatus['user_count'] > 0;
        }
    } catch (PDOException $e) {
        $dbStatus['error'] = $e->getMessage();
    }
} else {
    $dbStatus['error'] = Database::getError();
}

// Handle action execution
if ((($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' || $isCli) && in_array($action, ['create_schema', 'seed_data', 'setup_all'])) {
    if (!$isCli) {
        require_csrf_token();
    }

    // Attempt direct server connection to create database if missing
    if (!$dbStatus['connected']) {
        try {
            $rootPdo = new PDO(
                sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET),
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            // Retry standard connection
            $pdo = Database::getConnection();
            if ($pdo) {
                $dbStatus['connected'] = true;
            }
        } catch (PDOException $e) {
            $message = "Cannot create database automatically: " . $e->getMessage() . ". Please create the database '" . DB_NAME . "' manually in phpMyAdmin.";
            $messageType = 'danger';
        }
    }

    if ($pdo) {
        if ($action === 'create_schema' || $action === 'setup_all') {
            $res = execute_sql_file($pdo, $schemaFile);
            if ($res['success']) {
                $message = "Database schema initialized successfully!";
                $messageType = 'success';
            } else {
                $message = $res['message'];
                $messageType = 'danger';
            }
        }

        if ($action === 'seed_data' || ($action === 'setup_all' && $messageType === 'success')) {
            $res = execute_sql_file($pdo, $seedFile);
            if ($res['success']) {
                $message = "Database schema and seed data installed successfully!";
                $messageType = 'success';
            } else {
                $message = $res['message'];
                $messageType = 'danger';
            }
        }

        // Refresh status
        $stmt = $pdo->query("SHOW TABLES");
        $dbStatus['tables_found'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('users', $dbStatus['tables_found'], true)) {
            $userStmt = $pdo->query("SELECT COUNT(*) FROM `users`");
            $dbStatus['user_count'] = (int)$userStmt->fetchColumn();
            $dbStatus['has_users'] = $dbStatus['user_count'] > 0;
        }
    }
}

if ($isCli) {
    echo "========================================================\n";
    echo "St. Jude High School Inventory - DB Setup & Status\n";
    echo "========================================================\n";
    echo "Database: " . DB_NAME . " on " . DB_HOST . ":" . DB_PORT . "\n";
    echo "User: " . DB_USER . "\n";
    echo "Connection: " . ($dbStatus['connected'] ? "CONNECTED" : "FAILED (" . $dbStatus['error'] . ")") . "\n";
    echo "Tables Found (" . count($dbStatus['tables_found']) . "): " . implode(', ', $dbStatus['tables_found']) . "\n";
    echo "Default Users: " . $dbStatus['user_count'] . "\n";
    if (!empty($message)) {
        echo "Result: [{$messageType}] {$message}\n";
    }
    echo "========================================================\n";
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup & Diagnostics - <?= e(APP_SHORT_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= e(url('public/assets/css/style.css')) ?>">
</head>
<body class="bg-light py-4">

<div class="container" style="max-width: 900px;">
    <!-- Brand Header -->
    <div class="card card-custom mb-4 border-0 shadow-sm" style="background: #0f172a; color: #ffffff;">
        <div class="card-body p-4 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <div class="p-3 bg-primary bg-opacity-25 rounded-circle text-white">
                    <i class="bi bi-database-check fs-2"></i>
                </div>
                <div>
                    <h4 class="fw-bold mb-1">Database Setup & Diagnostics</h4>
                    <div class="text-white-50 small"><?= e(APP_NAME) ?></div>
                </div>
            </div>
            <div>
                <a href="<?= e(url('public/login.php')) ?>" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Go to Login
                </a>
            </div>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?= e($messageType) ?> alert-dismissible fade show shadow-sm" role="alert">
            <strong>Result:</strong> <?= e($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Connection Status Card -->
    <div class="card card-custom mb-4">
        <div class="card-header fw-bold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-hdd-network me-2 text-primary"></i>Connection Configuration</span>
            <?php if ($dbStatus['connected']): ?>
                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Connected</span>
            <?php else: ?>
                <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Connection Failed</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="row g-3 small">
                <div class="col-sm-3 text-muted">Host & Port:</div>
                <div class="col-sm-9 fw-semibold"><code><?= e(DB_HOST) ?>:<?= e(DB_PORT) ?></code></div>

                <div class="col-sm-3 text-muted">Database Name:</div>
                <div class="col-sm-9 fw-semibold"><code><?= e(DB_NAME) ?></code></div>

                <div class="col-sm-3 text-muted">Configured User:</div>
                <div class="col-sm-9 fw-semibold"><code><?= e(DB_USER) ?></code></div>

                <div class="col-sm-3 text-muted">Tables in Database:</div>
                <div class="col-sm-9">
                    <?php if (!empty($dbStatus['tables_found'])): ?>
                        <span class="badge bg-primary me-1"><?= count($dbStatus['tables_found']) ?> Tables</span>
                        <span class="text-muted"><?= implode(', ', array_map('e', $dbStatus['tables_found'])) ?></span>
                    <?php else: ?>
                        <span class="text-danger">0 tables found. Schema needs to be imported.</span>
                    <?php endif; ?>
                </div>

                <div class="col-sm-3 text-muted">Default Users:</div>
                <div class="col-sm-9">
                    <?php if ($dbStatus['has_users']): ?>
                        <span class="badge bg-success"><?= $dbStatus['user_count'] ?> Accounts Active</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">No user records</span>
                    <?php endif; ?>
                </div>

                <?php if ($dbStatus['error']): ?>
                    <div class="col-12 mt-2">
                        <div class="alert alert-warning py-2 mb-0">
                            <strong>Notice:</strong> <?= e($dbStatus['error']) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Actions / Migration Cards -->
    <div class="card card-custom mb-4">
        <div class="card-header fw-bold">
            <i class="bi bi-tools me-2 text-warning"></i>Automated Migration Runner
        </div>
        <div class="card-body">
            <p class="text-muted small">
                If your MySQL server allows database and table creation with the configured user credentials, you can install or update the tables and default development accounts below:
            </p>

            <form method="POST" action="<?= e(url('setup_database.php')) ?>" class="d-flex flex-wrap gap-2">
                <?= csrf_input() ?>
                <button type="submit" name="action" value="setup_all" class="btn btn-primary btn-sm d-flex align-items-center gap-2">
                    <i class="bi bi-play-circle-fill"></i>
                    <span>Execute Full Setup (Schema + Seed Data)</span>
                </button>

                <button type="submit" name="action" value="create_schema" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-table me-1"></i>Import Schema Only
                </button>

                <button type="submit" name="action" value="seed_data" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-person-lines-fill me-1"></i>Import Seed Data Only
                </button>
            </form>
        </div>
    </div>

    <!-- Manual Setup Guide for XAMPP / Windows -->
    <div class="card card-custom">
        <div class="card-header fw-bold">
            <i class="bi bi-book me-2 text-info"></i>Manual Setup Instructions for XAMPP on Windows
        </div>
        <div class="card-body small">
            <ol class="ps-3 mb-0">
                <li class="mb-2">
                    <strong>Start XAMPP Control Panel:</strong>
                    Ensure <em>Apache</em> and <em>MySQL</em> services are started.
                </li>
                <li class="mb-2">
                    <strong>Open phpMyAdmin:</strong>
                    Navigate to <code>http://localhost/phpmyadmin/</code> in your browser.
                </li>
                <li class="mb-2">
                    <strong>Create Database:</strong>
                    Create a new database named <code>school_inventory</code> with collation <code>utf8mb4_unicode_ci</code>.
                </li>
                <li class="mb-2">
                    <strong>Import Schema:</strong>
                    Select the <code>school_inventory</code> database, click the <strong>Import</strong> tab, browse to 
                    <code>database/schema.sql</code>, and click <strong>Go</strong>.
                </li>
                <li class="mb-2">
                    <strong>Import Seed Data:</strong>
                    Click <strong>Import</strong> again, browse to <code>database/seed.sql</code>, and click <strong>Go</strong>.
                </li>
                <li class="mb-2">
                    <strong>Verify Connection:</strong>
                    Refresh this page or go to <a href="<?= e(url('public/login.php')) ?>">Login Page</a> and use one of the default development accounts:
                    <ul class="mt-1">
                        <li><strong>Administrator:</strong> <code>admin</code> / <code>Admin@123</code></li>
                        <li><strong>Storekeeper:</strong> <code>storekeeper</code> / <code>Store@123</code></li>
                        <li><strong>HOD Science:</strong> <code>hod_science</code> / <code>Hod@123</code></li>
                        <li><strong>Auditor:</strong> <code>auditor</code> / <code>Audit@123</code></li>
                    </ul>
                </li>
            </ol>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
