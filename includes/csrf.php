<?php
/**
 * Cross-Site Request Forgery (CSRF) Protection
 * St. Jude High School Inventory Management System
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Generate or retrieve existing CSRF token for the session
 * 
 * @return string
 */
function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output hidden CSRF token input field
 * 
 * @return string
 */
function csrf_input(): string {
    $token = htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Verify CSRF token from request against session
 * 
 * @param string|null $token
 * @return bool
 */
function verify_csrf_token(?string $token = null): bool {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    }

    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Require valid CSRF token on POST requests; aborts with 403 if invalid
 */
function require_csrf_token(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token()) {
            http_response_code(403);
            die('Security Violation: Invalid or expired CSRF token. Please return to the previous page, refresh, and submit again.');
        }
    }
}
