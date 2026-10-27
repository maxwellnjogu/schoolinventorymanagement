<?php
/**
 * 403 Unauthorized Access Page
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Access Denied (403)';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';

$user = current_user();

if ($user) {
    require_once __DIR__ . '/../includes/header.php';
} else {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Access Denied - <?= e(APP_SHORT_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= e(url('public/assets/css/style.css')) ?>">
</head>
<body class="bg-light">
<?php } ?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6 text-center">
            <div class="card card-custom p-4 shadow-sm border-0">
                <div class="mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center bg-danger bg-opacity-10 text-danger rounded-circle p-4">
                        <i class="bi bi-shield-x fs-1"></i>
                    </span>
                </div>
                <h3 class="fw-bold text-danger mb-2">Access Denied (403 Forbidden)</h3>
                <p class="text-muted mb-4">
                    You do not possess the required permission level or role privileges to view or perform actions on this section of the inventory management portal.
                </p>

                <?php if ($user): ?>
                    <div class="alert alert-light border small text-start mb-4">
                        <div><strong>Signed in as:</strong> <?= e($user['full_name']) ?></div>
                        <div><strong>Current Assigned Role:</strong> <span class="badge <?= e(role_badge_class($user['role'])) ?>"><?= e(role_display_name($user['role'])) ?></span></div>
                        <div><strong>Department:</strong> <?= e($user['department_name']) ?></div>
                    </div>
                    <div>
                        <a href="<?= e(url('public/dashboard.php')) ?>" class="btn btn-primary d-inline-flex align-items-center gap-2">
                            <i class="bi bi-arrow-left"></i>
                            <span>Return to Dashboard</span>
                        </a>
                    </div>
                <?php else: ?>
                    <div>
                        <a href="<?= e(url('public/login.php')) ?>" class="btn btn-primary d-inline-flex align-items-center gap-2">
                            <i class="bi bi-box-arrow-in-right"></i>
                            <span>Go to Sign In</span>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php 
if ($user) {
    require_once __DIR__ . '/../includes/footer.php'; 
} else {
?>
</body>
</html>
<?php } ?>
