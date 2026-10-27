<?php
/**
 * Logout Handler
 * St. Jude High School Inventory Management System
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';

// Accept both POST with CSRF and GET fallback with confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
}

logout_user();

set_flash('info', 'You have been signed out safely. Thank you.');
redirect('public/login.php');
