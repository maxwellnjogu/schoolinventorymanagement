<?php
/**
 * User Login Page
 * St. Jude High School Inventory Management System
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

// Redirect if already authenticated
if (is_logged_in()) {
    redirect('public/dashboard.php');
}

$errorMessage = '';
$usernameVal = '';
$pdo = Database::getConnection();

// Process POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $usernameVal = trim($_POST['username'] ?? '');
    $passwordVal = $_POST['password'] ?? '';

    if (!$pdo) {
        $errorMessage = Database::getError() ?? 'Database connection unavailable. Please ensure MySQL is running in XAMPP.';
    } else {
        $result = login_user($pdo, $usernameVal, $passwordVal);
        if ($result['success']) {
            set_flash('success', 'Welcome back, ' . htmlspecialchars($_SESSION['full_name'], ENT_QUOTES, 'UTF-8') . '!');
            redirect('public/dashboard.php');
        } else {
            $errorMessage = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - <?= e(APP_SHORT_NAME) ?></title>
    
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <!-- Custom Theme Styles -->
    <link rel="stylesheet" href="<?= e(url('public/assets/css/style.css')) ?>">
</head>
<body class="auth-page">

<div class="auth-card">
    <!-- Header with school branding -->
    <div class="auth-header">
        <div class="d-inline-flex align-items-center justify-content-center p-3 rounded-circle bg-white bg-opacity-10 mb-3">
            <i class="bi bi-mortarboard fs-1 text-white"></i>
        </div>
        <h4 class="fw-bold mb-1">St. Jude High School</h4>
        <div class="text-white-50 small">Inventory & Asset Management Portal</div>
    </div>

    <!-- Login Form Body -->
    <div class="auth-body">
        <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-danger d-flex align-items-center small py-2 px-3 mb-3" role="alert">
                <i class="bi bi-exclamation-triangle-fill fs-5 me-2 flex-shrink-0"></i>
                <div><?= e($errorMessage) ?></div>
            </div>
        <?php endif; ?>

        <?php if (has_flash()): ?>
            <?php foreach (get_flash() as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> small py-2 px-3 mb-3" role="alert">
                    <?= e($flash['message']) ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <form method="POST" action="<?= e(url('public/login.php')) ?>" novalidate>
            <?= csrf_input() ?>

            <div class="mb-3">
                <label for="username" class="form-label fw-semibold small text-muted">Username or Email</label>
                <div class="input-group">
                    <span class="input-group-text bg-light text-muted"><i class="bi bi-person"></i></span>
                    <input type="text" class="form-control" id="username" name="username" value="<?= e($usernameVal) ?>" required autofocus autocomplete="username" placeholder="e.g. admin or storekeeper">
                </div>
            </div>

            <div class="mb-4">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label for="password" class="form-label fw-semibold small text-muted mb-0">Password</label>
                </div>
                <div class="input-group">
                    <span class="input-group-text bg-light text-muted"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password" placeholder="Enter your password">
                    <button class="btn btn-outline-secondary" type="button" id="togglePassword" aria-label="Toggle password visibility">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold d-flex align-items-center justify-content-center gap-2 shadow-sm">
                <i class="bi bi-box-arrow-in-right"></i>
                <span>Sign In to System</span>
            </button>
        </form>

        <!-- Development Default Accounts Helper -->
        <div class="demo-accounts-box">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="fw-bold text-dark"><i class="bi bi-key-fill text-warning me-1"></i>Development Test Accounts</span>
                <span class="badge bg-secondary">Phase 1</span>
            </div>
            
            <div class="demo-account-item">
                <div>
                    <strong>admin</strong> <span class="badge bg-danger text-white">Principal</span>
                    <div class="text-muted" style="font-size: 0.72rem;">Pass: <code>Admin@123</code></div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 btn-use-account" data-user="admin" data-pass="Admin@123">Use</button>
            </div>

            <div class="demo-account-item">
                <div>
                    <strong>storekeeper</strong> <span class="badge bg-primary text-white">Stores</span>
                    <div class="text-muted" style="font-size: 0.72rem;">Pass: <code>Store@123</code></div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 btn-use-account" data-user="storekeeper" data-pass="Store@123">Use</button>
            </div>

            <div class="demo-account-item">
                <div>
                    <strong>hod_science</strong> <span class="badge bg-success text-white">HOD Science</span>
                    <div class="text-muted" style="font-size: 0.72rem;">Pass: <code>Hod@123</code></div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 btn-use-account" data-user="hod_science" data-pass="Hod@123">Use</button>
            </div>

            <div class="demo-account-item">
                <div>
                    <strong>auditor</strong> <span class="badge bg-warning text-dark">Auditor</span>
                    <div class="text-muted" style="font-size: 0.72rem;">Pass: <code>Audit@123</code></div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 btn-use-account" data-user="auditor" data-pass="Audit@123">Use</button>
            </div>

            <div class="mt-2 text-center pt-2 border-top">
                <a href="<?= e(url('setup_database.php')) ?>" class="text-decoration-none small text-muted">
                    <i class="bi bi-database-check me-1"></i>Database Setup & Connection Check
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Main Application JS -->
<script src="<?= e(url('public/assets/js/main.js')) ?>"></script>
</body>
</html>
