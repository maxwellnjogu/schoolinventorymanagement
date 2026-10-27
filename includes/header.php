<?php
/**
 * Shared Header Layout
 * St. Jude High School Inventory Management System
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';

$user = current_user();
$pageTitle = isset($pageTitle) ? $pageTitle . ' - ' . APP_SHORT_NAME : APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="St. Jude High School Inventory, Asset, and Stock Management System">
    
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <!-- Custom Theme Styles -->
    <link rel="stylesheet" href="<?= e(url('public/assets/css/style.css')) ?>">
</head>
<body>

<div class="app-wrapper">
    <!-- Include Sidebar Navigation -->
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <!-- Mobile Backdrop -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- Main Content Wrapper -->
    <div class="app-main">
        <!-- Top Sticky Header -->
        <header class="app-topbar">
            <div class="d-flex align-items-center">
                <button type="button" class="btn btn-outline-secondary btn-sm d-lg-none me-3" id="sidebarToggle" aria-label="Toggle navigation">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <div class="d-none d-sm-block">
                    <span class="text-muted small">Academic Year 2026 / Term 1</span>
                    <span class="mx-2 text-muted">|</span>
                    <span class="fw-semibold text-dark"><i class="bi bi-mortarboard me-1 text-primary"></i>St. Jude High School</span>
                </div>
            </div>

            <div class="d-flex align-items-center gap-3">
                <!-- Currency Badge indicator -->
                <div class="badge bg-light text-dark border px-2 py-1 d-none d-md-inline-block">
                    <i class="bi bi-cash-stack text-success me-1"></i>Currency: <strong>KSh</strong>
                </div>

                <!-- User Dropdown Menu -->
                <?php if ($user): ?>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light border dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="userMenuButton">
                            <span class="avatar-circle bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 28px; height: 28px; font-size: 0.8rem;">
                                <?= strtoupper(substr(e($user['username']), 0, 1)) ?>
                            </span>
                            <span class="d-none d-md-inline fw-semibold"><?= e($user['full_name']) ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border" aria-labelledby="userMenuButton">
                            <li class="dropdown-header">
                                <div class="fw-bold"><?= e($user['full_name']) ?></div>
                                <small class="text-muted"><?= e($user['department_name']) ?></small>
                                <div class="mt-1">
                                    <span class="badge <?= e(role_badge_class($user['role'])) ?>"><?= e(role_display_name($user['role'])) ?></span>
                                </div>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="<?= e(url('public/profile.php')) ?>">
                                    <i class="bi bi-person-gear me-2 text-primary"></i>My Profile & Password
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form action="<?= e(url('public/logout.php')) ?>" method="POST" class="m-0 p-0">
                                    <?= csrf_input() ?>
                                    <button type="submit" class="dropdown-item text-danger">
                                        <i class="bi bi-box-arrow-right me-2"></i>Sign Out
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <!-- Main Body Content -->
        <main class="app-content">
            <!-- Flash Message Banner Notifications -->
            <?php if (has_flash()): ?>
                <div class="flash-messages-container mb-4">
                    <?php foreach (get_flash() as $flash): ?>
                        <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show shadow-sm" role="alert">
                            <div class="d-flex align-items-center">
                                <?php if ($flash['type'] === 'success'): ?>
                                    <i class="bi bi-check-circle-fill fs-5 me-2"></i>
                                <?php elseif ($flash['type'] === 'danger'): ?>
                                    <i class="bi bi-exclamation-triangle-fill fs-5 me-2"></i>
                                <?php elseif ($flash['type'] === 'warning'): ?>
                                    <i class="bi bi-exclamation-circle-fill fs-5 me-2"></i>
                                <?php else: ?>
                                    <i class="bi bi-info-circle-fill fs-5 me-2"></i>
                                <?php endif; ?>
                                <div><?= e($flash['message']) ?></div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
