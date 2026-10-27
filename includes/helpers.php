<?php
/**
 * Global Helper Functions & Utilities
 * St. Jude High School Inventory Management System
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Safe HTML output encoding
 * Prevents Cross-Site Scripting (XSS)
 * 
 * @param mixed $value
 * @return string
 */
function e($value): string {
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Format currency strictly in KSh per business rules
 * 
 * @param float|int|string $amount
 * @param int $decimals
 * @return string
 */
function format_currency($amount, int $decimals = 2): string {
    $num = is_numeric($amount) ? (float)$amount : 0.0;
    return APP_CURRENCY . ' ' . number_format($num, $decimals);
}

/**
 * Format date for display
 * 
 * @param string|null $dateStr
 * @param string $format
 * @return string
 */
function format_date(?string $dateStr, string $format = 'd M Y, h:i A'): string {
    if (empty($dateStr)) {
        return '-';
    }
    try {
        $dt = new DateTime($dateStr);
        return $dt->format($format);
    } catch (Exception $e) {
        return e($dateStr);
    }
}

/**
 * Format simple date (Y-m-d) to display date (e.g. 15 Jan 2025)
 * 
 * @param string|null $dateStr
 * @return string
 */
function format_simple_date(?string $dateStr): string {
    return format_date($dateStr, 'd M Y');
}

/**
 * Set a flash notification message in session
 * 
 * @param string $type ('success', 'danger', 'warning', 'info')
 * @param string $message
 */
function set_flash(string $type, string $message): void {
    if (!isset($_SESSION['flash_messages'])) {
        $_SESSION['flash_messages'] = [];
    }
    $_SESSION['flash_messages'][] = [
        'type'    => $type,
        'message' => $message
    ];
}

/**
 * Check if flash messages exist
 * 
 * @return bool
 */
function has_flash(): bool {
    return !empty($_SESSION['flash_messages']);
}

/**
 * Retrieve and clear all flash messages
 * 
 * @return array
 */
function get_flash(): array {
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return $messages;
}

/**
 * Safe HTTP redirection
 * 
 * @param string $path
 */
function redirect(string $path): void {
    // If not absolute URL, prefix with BASE_URL
    if (!preg_match('#^https?://#i', $path)) {
        $path = rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
    }
    header('Location: ' . $path);
    exit;
}

/**
 * Build application URL relative to BASE_URL
 * 
 * @param string $path
 * @return string
 */
function url(string $path = ''): string {
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

/**
 * Log user action into audit_logs table using prepared statements
 * 
 * @param PDO|null $pdo
 * @param string $action
 * @param string $entity_type
 * @param int|null $entity_id
 * @param string|array|null $details
 * @return bool
 */
function log_audit_action(?PDO $pdo, string $action, string $entity_type, ?int $entity_id = null, $details = null): bool {
    if ($pdo === null) {
        return false;
    }

    try {
        $userId = $_SESSION['user_id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);
        $detailsText = is_array($details) ? json_encode($details, JSON_UNESCAPED_SLASHES) : (string)$details;

        $stmt = $pdo->prepare("
            INSERT INTO `audit_logs` (`user_id`, `action`, `entity_type`, `entity_id`, `details`, `ip_address`, `user_agent`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$userId, $action, $entity_type, $entity_id, $detailsText, $ip, $ua]);
    } catch (PDOException $e) {
        error_log('Audit Log Error: ' . $e->getMessage());
        return false;
    }
}
