<?php
/**
 * Automated Phase 1 Test Suite
 * St. Jude High School Inventory Management System
 */

echo "========================================================\n";
echo "Running Phase 1 Test Suite for School Inventory System\n";
echo "========================================================\n\n";

$testsPassed = 0;
$testsFailed = 0;

function assert_test(string $name, bool $condition, string $details = '') {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo "[PASS] {$name}\n";
        $testsPassed++;
    } else {
        echo "[FAIL] {$name} - {$details}\n";
        $testsFailed++;
    }
}

// 1. Test Config & Constants
require_once __DIR__ . '/../config/config.php';
assert_test("Currency Constant is strictly KSh", defined('APP_CURRENCY') && APP_CURRENCY === 'KSh', "Current value: " . (defined('APP_CURRENCY') ? APP_CURRENCY : 'Undefined'));
assert_test("App Name is defined", defined('APP_NAME') && !empty(APP_NAME));
assert_test("Database Constants defined", defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER'));

// 2. Test Helpers
require_once __DIR__ . '/../includes/helpers.php';
assert_test("XSS Escaping e()", e("<script>alert('xss')</script>") === "&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;");
assert_test("Currency Formatter format_currency()", format_currency(1500) === "KSh 1,500.00", "Got: " . format_currency(1500));
assert_test("Currency Formatter with Decimals", format_currency(2450.75) === "KSh 2,450.75", "Got: " . format_currency(2450.75));
assert_test("Currency Formatter with Zero", format_currency(0) === "KSh 0.00", "Got: " . format_currency(0));

// 3. Test CSRF Protection
require_once __DIR__ . '/../includes/csrf.php';
$token = generate_csrf_token();
assert_test("CSRF Token Generation", !empty($token) && strlen($token) === 64, "Token: " . $token);
assert_test("CSRF Token Verification (Valid)", verify_csrf_token($token) === true);
assert_test("CSRF Token Verification (Invalid)", verify_csrf_token('invalid_token_value') === false);
assert_test("CSRF Input Generator contains token", strpos(csrf_input(), $token) !== false);

// 4. Test RBAC & Roles Logic
require_once __DIR__ . '/../includes/auth.php';
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
assert_test("Role Check has_role('admin') for admin", has_role('admin') === true);
assert_test("Role Check has_role('storekeeper') for admin", has_role('storekeeper') === false);
assert_test("Role Check has_role(['admin', 'storekeeper']) for admin", has_role(['admin', 'storekeeper']) === true);

$_SESSION['role'] = 'storekeeper';
assert_test("Role Check has_role('storekeeper') for storekeeper", has_role('storekeeper') === true);
assert_test("Role Check has_role('admin') for storekeeper", has_role('admin') === false);

$_SESSION['role'] = 'department_head';
assert_test("Role Check has_role('department_head')", has_role('department_head') === true);
assert_test("Role Display Name for department_head", role_display_name('department_head') === 'Department Head (HOD)');

// 5. Test Password Hashes with Seed Passwords
$accounts = [
    'admin' => [
        'password' => 'Admin@123',
        'hash' => '$2y$10$nP7I3oxxaSxKg8qi5BB1B.pUte4wa/AeDar.UBMHj4kAp/upTBOFW'
    ],
    'storekeeper' => [
        'password' => 'Store@123',
        'hash' => '$2y$10$WvNepDsBW7J0Shydo0Smn.tbV04X4O2GXSNpPN1QZNEbJhZGimOyC'
    ],
    'hod_science' => [
        'password' => 'Hod@123',
        'hash' => '$2y$10$1jgdZNgTGGGlNTkLAyJ.MO3JgjZHJRyzrvyjXCI/TXYDHaWV/2FBe'
    ],
    'auditor' => [
        'password' => 'Audit@123',
        'hash' => '$2y$10$BRxD98VYHDuB43JmqedQQecSrnHrQkBcot8Q18DBqqiUUAnwqKBOO'
    ]
];

foreach ($accounts as $role => $creds) {
    $verified = password_verify($creds['password'], $creds['hash']);
    assert_test("Password Verify for '{$role}' ({$creds['password']})", $verified === true);
}

// 6. Test SQL Schema File Integrity
$schemaContent = file_get_contents(__DIR__ . '/../database/schema.sql');
$requiredTables = [
    'departments', 'locations', 'categories', 'users', 'login_attempts',
    'suppliers', 'items', 'purchase_orders', 'purchase_order_items',
    'goods_received_notes', 'goods_received_items', 'stock_requests',
    'stock_request_items', 'stock_issues', 'stock_issue_items',
    'fixed_assets', 'stock_movements', 'audit_logs'
];

foreach ($requiredTables as $table) {
    assert_test("Schema defines table `{$table}`", strpos($schemaContent, "CREATE TABLE `{$table}`") !== false);
}

// 7. Test Seed File Integrity
$seedContent = file_get_contents(__DIR__ . '/../database/seed.sql');
assert_test("Seed data contains default departments", strpos($seedContent, "DEP-ADMIN") !== false);
assert_test("Seed data contains default users", strpos($seedContent, "principal@stjudehigh.ac.ke") !== false);
assert_test("Seed data contains sample consumable items", strpos($seedContent, "ITM-PAP-A4") !== false);
assert_test("Seed data contains sample fixed assets", strpos($seedContent, "SJHS/ICT/PC/001") !== false);

echo "\n========================================================\n";
echo "Test Results: {$testsPassed} Passed, {$testsFailed} Failed\n";
echo "========================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
