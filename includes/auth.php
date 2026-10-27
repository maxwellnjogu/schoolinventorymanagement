<?php
/**
 * Authentication and Role-Based Access Control (RBAC)
 * St. Jude High School Inventory Management System
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';

// Check for session timeout (30 minutes inactivity)
if (isset($_SESSION['user_id']) && isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > 1800) {
        logout_user();
        set_flash('warning', 'Your session has expired due to inactivity. Please log in again.');
        redirect('public/login.php');
    }
    $_SESSION['last_activity'] = time();
}

/**
 * Check if a user is currently authenticated
 * 
 * @return bool
 */
function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

/**
 * Get the currently authenticated user's session data
 * 
 * @return array|null
 */
function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }

    return [
        'id'              => (int)$_SESSION['user_id'],
        'username'        => $_SESSION['username'] ?? '',
        'full_name'       => $_SESSION['full_name'] ?? '',
        'email'           => $_SESSION['email'] ?? '',
        'role'            => $_SESSION['role'] ?? '',
        'department_id'   => $_SESSION['department_id'] ?? null,
        'department_name' => $_SESSION['department_name'] ?? 'General Administration',
        'last_login'      => $_SESSION['last_login'] ?? null,
    ];
}

/**
 * Require user to be logged in; redirects to login page if unauthenticated
 */
function require_login(): void {
    if (!is_logged_in()) {
        set_flash('warning', 'Please sign in to access this page.');
        redirect('public/login.php');
    }
}

/**
 * Check if the current user possesses one of the allowed roles
 * 
 * @param string|array $allowed_roles
 * @return bool
 */
function has_role($allowed_roles): bool {
    if (!is_logged_in()) {
        return false;
    }

    $currentRole = $_SESSION['role'] ?? '';
    if (is_string($allowed_roles)) {
        return strtolower($currentRole) === strtolower($allowed_roles);
    }

    if (is_array($allowed_roles)) {
        $allowed = array_map('strtolower', $allowed_roles);
        return in_array(strtolower($currentRole), $allowed, true);
    }

    return false;
}

/**
 * Enforce role requirement; redirects to 403 unauthorized page if check fails
 * 
 * @param string|array $allowed_roles
 */
function require_role($allowed_roles): void {
    require_login();

    if (!has_role($allowed_roles)) {
        http_response_code(403);
        redirect('public/unauthorized.php');
    }
}

/**
 * Rate Limiter: check failed login attempts in the past 15 minutes
 * 
 * @param PDO|null $pdo
 * @param string $ip
 * @param string $username
 * @return array [is_locked => bool, remaining_seconds => int, attempts => int]
 */
function check_login_rate_limit(?PDO $pdo, string $ip, string $username): array {
    if ($pdo === null) {
        return ['is_locked' => false, 'remaining_seconds' => 0, 'attempts' => 0];
    }

    try {
        $window = 15; // 15 minutes
        $maxAttempts = 5;

        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total_failed, MAX(attempted_at) AS latest_attempt
            FROM `login_attempts`
            WHERE (ip_address = ? OR username = ?)
              AND attempted_at >= (NOW() - INTERVAL ? MINUTE)
        ");
        $stmt->execute([$ip, $username, $window]);
        $row = $stmt->fetch();

        $attempts = (int)($row['total_failed'] ?? 0);
        if ($attempts >= $maxAttempts) {
            $latest = strtotime($row['latest_attempt'] ?? 'now');
            $lockoutExpires = $latest + ($window * 60);
            $remaining = max(0, $lockoutExpires - time());

            if ($remaining > 0) {
                return [
                    'is_locked'         => true,
                    'remaining_seconds' => $remaining,
                    'attempts'          => $attempts
                ];
            }
        }

        return ['is_locked' => false, 'remaining_seconds' => 0, 'attempts' => $attempts];
    } catch (PDOException $e) {
        error_log('Rate limit check error: ' . $e->getMessage());
        return ['is_locked' => false, 'remaining_seconds' => 0, 'attempts' => 0];
    }
}

/**
 * Record a failed login attempt
 */
function record_failed_login(?PDO $pdo, string $ip, string $username): void {
    if ($pdo === null) {
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO `login_attempts` (`ip_address`, `username`)
            VALUES (?, ?)
        ");
        $stmt->execute([$ip, substr($username, 0, 50)]);
    } catch (PDOException $e) {
        error_log('Record failed login error: ' . $e->getMessage());
    }
}

/**
 * Clear failed login attempts after successful authentication
 */
function clear_failed_logins(?PDO $pdo, string $ip, string $username): void {
    if ($pdo === null) {
        return;
    }

    try {
        $stmt = $pdo->prepare("
            DELETE FROM `login_attempts`
            WHERE ip_address = ? OR username = ?
        ");
        $stmt->execute([$ip, $username]);
    } catch (PDOException $e) {
        error_log('Clear failed logins error: ' . $e->getMessage());
    }
}

/**
 * Authenticate user credentials securely
 * 
 * @param PDO $pdo
 * @param string $username
 * @param string $password
 * @return array [success => bool, message => string]
 */
function login_user(PDO $pdo, string $username, string $password): array {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $cleanUsername = trim($username);

    if (empty($cleanUsername) || empty($password)) {
        return ['success' => false, 'message' => 'Username and password are required.'];
    }

    // Check rate limiting / brute-force lockout
    $rateCheck = check_login_rate_limit($pdo, $ip, $cleanUsername);
    if ($rateCheck['is_locked']) {
        $mins = ceil($rateCheck['remaining_seconds'] / 60);
        return [
            'success' => false, 
            'message' => "Too many failed login attempts. Please wait {$mins} minute(s) before trying again."
        ];
    }

    try {
        // Fetch user by username or email
        $stmt = $pdo->prepare("
            SELECT u.*, d.name AS department_name
            FROM `users` u
            LEFT JOIN `departments` d ON u.department_id = d.id
            WHERE u.username = ? OR u.email = ?
            LIMIT 1
        ");
        $stmt->execute([$cleanUsername, $cleanUsername]);
        $user = $stmt->fetch();

        // Constant time verification & check
        if (!$user || !password_verify($password, $user['password_hash'])) {
            record_failed_login($pdo, $ip, $cleanUsername);
            log_audit_action($pdo, 'FAILED_LOGIN', 'users', $user['id'] ?? null, "Failed login attempt for user: {$cleanUsername}");
            return ['success' => false, 'message' => 'Invalid username or password.'];
        }

        // Check account active status
        if ($user['status'] !== 'active') {
            return ['success' => false, 'message' => 'Your account has been deactivated. Please contact the administrator.'];
        }

        // Prevent session fixation by regenerating session ID
        session_regenerate_id(true);

        // Populate session
        $_SESSION['user_id']         = (int)$user['id'];
        $_SESSION['username']        = $user['username'];
        $_SESSION['full_name']       = $user['full_name'];
        $_SESSION['email']           = $user['email'];
        $_SESSION['role']            = $user['role'];
        $_SESSION['department_id']   = $user['department_id'] ? (int)$user['department_id'] : null;
        $_SESSION['department_name'] = $user['department_name'] ?? 'General Administration';
        $_SESSION['last_login']      = $user['last_login'];
        $_SESSION['last_activity']   = time();

        // Update last login in database
        $updateStmt = $pdo->prepare("UPDATE `users` SET `last_login` = NOW() WHERE `id` = ?");
        $updateStmt->execute([$user['id']]);

        // Clear previous failed attempts
        clear_failed_logins($pdo, $ip, $cleanUsername);

        // Audit log successful sign in
        log_audit_action($pdo, 'USER_LOGIN', 'users', (int)$user['id'], "User {$user['username']} logged in successfully with role {$user['role']}");

        return ['success' => true, 'message' => 'Login successful.'];
    } catch (PDOException $e) {
        error_log('Login error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'A system error occurred during authentication. Please try again.'];
    }
}

/**
 * Destroy current session and log out
 */
function logout_user(): void {
    if (isset($_SESSION['user_id'])) {
        $pdo = Database::getConnection();
        if ($pdo) {
            log_audit_action($pdo, 'USER_LOGOUT', 'users', (int)$_SESSION['user_id'], "User logged out");
        }
    }

    // Unset all session variables
    $_SESSION = [];

    // Expire session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    // Destroy session
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Human readable label for role
 */
function role_display_name(string $role): string {
    switch (strtolower($role)) {
        case 'admin':
            return 'Principal / Administrator';
        case 'storekeeper':
            return 'Storekeeper / Inventory Officer';
        case 'department_head':
            return 'Department Head (HOD)';
        case 'auditor':
            return 'Internal Auditor';
        default:
            return ucfirst($role);
    }
}

/**
 * Role badge styling class
 */
function role_badge_class(string $role): string {
    switch (strtolower($role)) {
        case 'admin':
            return 'bg-danger text-white';
        case 'storekeeper':
            return 'bg-primary text-white';
        case 'department_head':
            return 'bg-success text-white';
        case 'auditor':
            return 'bg-warning text-dark';
        default:
            return 'bg-secondary text-white';
    }
}
