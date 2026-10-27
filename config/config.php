<?php
/**
 * Application Configuration
 * St. Jude High School Inventory Management System
 */

// Prevent multiple inclusion
if (defined('APP_INITIALIZED')) {
    return;
}
define('APP_INITIALIZED', true);

// Application details
define('APP_NAME', 'St. Jude High School - Inventory Management System');
define('APP_SHORT_NAME', 'SJHS Inventory');
define('APP_VERSION', '1.0.0-phase1');

// Currency is strictly Kenyan Shillings (KSh) per business rules
define('APP_CURRENCY', 'KSh');

// Base URL calculation (works out-of-the-box in XAMPP htdocs or subdirectories)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
// Normalize base path
$basePath = preg_replace('#/(public|includes|config|database)$#', '', $scriptDir);
$basePath = rtrim($basePath, '/');
define('BASE_URL', $protocol . $host . $basePath);

// Session Security Configuration
if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        // 30 minutes session inactivity lifetime
        ini_set('session.gc_maxlifetime', 1800);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.use_only_cookies', 1);
        
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        
        session_set_cookie_params([
            'lifetime' => 0, // Session cookie expires on browser close
            'path'     => $basePath ?: '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        session_name('SJHS_INVENTORY_SESSID');
    }
    @session_start();
}

// Security Headers
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self' https: data: 'unsafe-inline'; script-src 'self' https: 'unsafe-inline'; style-src 'self' https: 'unsafe-inline'; font-src 'self' https: data:;");
}

// Timezone (East Africa Time)
date_default_timezone_set('Africa/Nairobi');

// Database configuration defaults (can be overridden by environment variables or custom config)
$defaultPort = '3306';
if (!getenv('DB_PORT')) {
    $testSocket = @fsockopen('127.0.0.1', 3307, $errno, $errstr, 0.2);
    if ($testSocket) {
        fclose($testSocket);
        $defaultPort = '3307';
    }
}

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: $defaultPort);
define('DB_NAME', getenv('DB_NAME') ?: 'school_inventory');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');
