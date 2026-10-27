<?php
/**
 * Root Entry Point
 * St. Jude High School Inventory Management System
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect('public/dashboard.php');
} else {
    redirect('public/login.php');
}
