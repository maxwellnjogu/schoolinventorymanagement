<?php
/**
 * User Profile and Password Management
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'My Profile';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

$user = current_user();
$pdo = Database::getConnection();
$errors = [];
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($currentPassword)) {
        $errors[] = 'Current password is required.';
    }

    if (empty($newPassword)) {
        $errors[] = 'New password is required.';
    } elseif (strlen($newPassword) < 8) {
        $errors[] = 'New password must be at least 8 characters long.';
    }

    if ($newPassword !== $confirmPassword) {
        $errors[] = 'New password and confirmation do not match.';
    }

    if (empty($errors)) {
        if (!$pdo) {
            $errors[] = 'Database is currently unreachable. Please check your MySQL service.';
        } else {
            try {
                // Verify current password from database
                $stmt = $pdo->prepare("SELECT password_hash FROM `users` WHERE `id` = ?");
                $stmt->execute([$user['id']]);
                $hash = $stmt->fetchColumn();

                if (!$hash || !password_verify($currentPassword, $hash)) {
                    $errors[] = 'Incorrect current password.';
                    log_audit_action($pdo, 'PASSWORD_CHANGE_FAILED', 'users', $user['id'], 'Failed password change: Incorrect current password');
                } else {
                    // Update to secure new password
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $updateStmt = $pdo->prepare("UPDATE `users` SET `password_hash` = ?, `updated_at` = NOW() WHERE `id` = ?");
                    $updateStmt->execute([$newHash, $user['id']]);

                    log_audit_action($pdo, 'PASSWORD_CHANGE_SUCCESS', 'users', $user['id'], 'User changed password successfully');
                    set_flash('success', 'Your password has been updated successfully!');
                    redirect('public/profile.php');
                }
            } catch (PDOException $e) {
                error_log('Password update error: ' . $e->getMessage());
                $errors[] = 'An error occurred while updating your password. Please try again.';
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-4">
    <!-- User Information Card -->
    <div class="col-lg-5">
        <div class="card card-custom h-100">
            <div class="card-header fw-bold">
                <i class="bi bi-person-badge-fill text-primary me-2"></i>Account Details
            </div>
            <div class="card-body">
                <div class="text-center py-3">
                    <div class="avatar-circle bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3 shadow" style="width: 72px; height: 72px; font-size: 1.8rem; font-weight: 700;">
                        <?= strtoupper(substr(e($user['username']), 0, 1)) ?>
                    </div>
                    <h5 class="fw-bold mb-1"><?= e($user['full_name']) ?></h5>
                    <div>
                        <span class="badge <?= e(role_badge_class($user['role'])) ?> px-3 py-1">
                            <?= e(role_display_name($user['role'])) ?>
                        </span>
                    </div>
                </div>

                <hr class="my-3">

                <dl class="row small mb-0">
                    <dt class="col-sm-5 text-muted">Username:</dt>
                    <dd class="col-sm-7 fw-semibold"><code><?= e($user['username']) ?></code></dd>

                    <dt class="col-sm-5 text-muted">Official Email:</dt>
                    <dd class="col-sm-7"><?= e($user['email']) ?></dd>

                    <dt class="col-sm-5 text-muted">Department:</dt>
                    <dd class="col-sm-7"><?= e($user['department_name']) ?></dd>

                    <dt class="col-sm-5 text-muted">Account Status:</dt>
                    <dd class="col-sm-7"><span class="badge bg-success">Active</span></dd>

                    <dt class="col-sm-5 text-muted">Last Session Login:</dt>
                    <dd class="col-sm-7"><?= format_date($user['last_login']) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <!-- Password Update Card -->
    <div class="col-lg-7">
        <div class="card card-custom">
            <div class="card-header fw-bold">
                <i class="bi bi-key-fill text-warning me-2"></i>Change Security Password
            </div>
            <div class="card-body p-4">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger small py-2 px-3 mb-3">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $err): ?>
                                <li><?= e($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= e(url('public/profile.php')) ?>" novalidate>
                    <?= csrf_input() ?>

                    <div class="mb-3">
                        <label for="current_password" class="form-label small fw-semibold text-muted">Current Password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password" placeholder="Enter current password">
                    </div>

                    <div class="mb-3">
                        <label for="new_password" class="form-label small fw-semibold text-muted">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8" autocomplete="new-password" placeholder="Minimum 8 characters">
                        <div class="form-text small">Use a strong password combining uppercase, lowercase, numbers or symbols.</div>
                    </div>

                    <div class="mb-4">
                        <label for="confirm_password" class="form-label small fw-semibold text-muted">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password" placeholder="Re-type new password">
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary d-flex align-items-center gap-2">
                            <i class="bi bi-shield-lock"></i>
                            <span>Update Password</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
