<?php
/**
 * Automated Stock Calculations & Inventory Integrity Test Suite
 * St. Jude High School Inventory Management System
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/inventory_helpers.php';

echo "========================================================\n";
echo "Testing Real MySQL Stock Calculations & Movements\n";
echo "========================================================\n\n";

$pdo = Database::getConnection();
if (!$pdo) {
    echo "[FATAL] Cannot connect to MySQL: " . Database::getError() . "\n";
    exit(1);
}

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

try {
    // 1. Create a dedicated test item in real MySQL
    $testItemCode = 'TEST-CALC-' . strtoupper(bin2hex(random_bytes(3)));
    $openingStock = 50.0;
    $reorderThreshold = 20.0;
    $unitCost = 450.0;

    // Clean up previous if any
    $pdo->prepare("DELETE FROM `stock_movements` WHERE reference_number LIKE 'TEST%'")->execute();
    $pdo->prepare("DELETE FROM `items` WHERE item_code LIKE 'TEST-CALC-%'")->execute();

    // Insert test item
    $pdo->beginTransaction();
    $insertStmt = $pdo->prepare("
        INSERT INTO `items` (
            `category_id`, `location_id`, `item_code`, `name`,
            `description`, `unit_of_measure`, `is_asset`,
            `unit_cost`, `quantity_on_hand`, `reorder_level`,
            `status`, `created_at`
        ) VALUES (1, 1, ?, 'Test Stock Ream Paper', 'Stock Calculation Testing Item', 'Ream', 0, ?, 0.0, ?, 'active', NOW())
    ");
    $insertStmt->execute([$testItemCode, $unitCost, $reorderThreshold]);
    $testItemId = (int)$pdo->lastInsertId();

    // Record Opening Stock Movement
    $openRes = record_stock_movement($pdo, [
        'item_id'          => $testItemId,
        'movement_type'    => 'in',
        'quantity'         => $openingStock,
        'reference_type'   => 'adjustment',
        'reference_number' => 'TEST-OPEN-' . $testItemCode,
        'performed_by'     => 1,
        'notes'            => 'Initial opening stock test'
    ]);
    $pdo->commit();

    assert_test("Initial Item Created in MySQL", $testItemId > 0, "Item ID: {$testItemId}");
    assert_test("Opening Stock Balance After", (float)$openRes['balance_after'] === 50.0, "Got: " . $openRes['balance_after']);

    // Verify quantity_on_hand in DB
    $checkStmt = $pdo->prepare("SELECT quantity_on_hand FROM `items` WHERE id = ?");
    $checkStmt->execute([$testItemId]);
    $currentQty = (float)$checkStmt->fetchColumn();
    assert_test("MySQL quantity_on_hand equals Opening Stock (50.0)", $currentQty === 50.0, "Got: {$currentQty}");

    // Verify stock_movements ledger entry exists
    $movCheck = $pdo->prepare("SELECT * FROM `stock_movements` WHERE id = ?");
    $movCheck->execute([$openRes['movement_id']]);
    $movRow = $movCheck->fetch();
    assert_test("Opening Stock Ledger Entry Created", !empty($movRow));
    assert_test("Ledger Movement Type is 'in'", $movRow['movement_type'] === 'in');
    assert_test("Ledger Quantity is 50.0", (float)$movRow['quantity'] === 50.0);
    assert_test("Ledger Balance After is 50.0", (float)$movRow['balance_after'] === 50.0);

    // 2. Test Stock Inflow (+30 items, e.g. Goods Receipt)
    $inflowRes = record_stock_movement($pdo, [
        'item_id'          => $testItemId,
        'movement_type'    => 'in',
        'quantity'         => 30.0,
        'reference_type'   => 'grn',
        'reference_number' => 'TEST-GRN-001',
        'performed_by'     => 1,
        'notes'            => 'Incoming stock delivery test'
    ]);
    assert_test("Stock Inflow (+30): Balance After = 80.0", (float)$inflowRes['balance_after'] === 80.0, "Got: " . $inflowRes['balance_after']);

    // Check DB
    $checkStmt->execute([$testItemId]);
    assert_test("MySQL quantity_on_hand updated to 80.0", (float)$checkStmt->fetchColumn() === 80.0);

    // 3. Test Stock Outflow (-25 items, e.g. Stock Issuing)
    $outflowRes = record_stock_movement($pdo, [
        'item_id'          => $testItemId,
        'movement_type'    => 'out',
        'quantity'         => 25.0,
        'reference_type'   => 'issue',
        'reference_number' => 'TEST-ISSUE-001',
        'performed_by'     => 1,
        'notes'            => 'Department issue test'
    ]);
    assert_test("Stock Outflow (-25): Balance After = 55.0", (float)$outflowRes['balance_after'] === 55.0, "Got: " . $outflowRes['balance_after']);

    // Check DB
    $checkStmt->execute([$testItemId]);
    assert_test("MySQL quantity_on_hand updated to 55.0", (float)$checkStmt->fetchColumn() === 55.0);

    // 4. Test Insufficient Stock Prevention (Attempting to deduct 100 when balance is 55)
    $caughtUnderflow = false;
    try {
        record_stock_movement($pdo, [
            'item_id'          => $testItemId,
            'movement_type'    => 'out',
            'quantity'         => 100.0,
            'reference_type'   => 'issue',
            'reference_number' => 'TEST-ISSUE-FAIL',
            'performed_by'     => 1
        ]);
    } catch (UnderflowException $e) {
        $caughtUnderflow = true;
    }
    assert_test("Insufficient Stock Rejection (UnderflowException)", $caughtUnderflow === true);

    // Verify balance was untouched
    $checkStmt->execute([$testItemId]);
    assert_test("Stock Balance Untouched after Rejected Outflow (55.0)", (float)$checkStmt->fetchColumn() === 55.0);

    // 5. Test Stock Adjustment Down to Low-Stock Threshold
    // Deduct 40 so balance becomes 15 (which is <= reorder threshold 20)
    $lowRes = record_stock_movement($pdo, [
        'item_id'          => $testItemId,
        'movement_type'    => 'out',
        'quantity'         => 40.0,
        'reference_type'   => 'adjustment',
        'reference_number' => 'TEST-ADJ-LOW',
        'performed_by'     => 1,
        'notes'            => 'Simulate reaching low stock threshold'
    ]);
    assert_test("Stock Decreased to 15.0", (float)$lowRes['balance_after'] === 15.0);

    // 6. Test Low-Stock Query Logic in MySQL
    $lowStockStmt = $pdo->prepare("
        SELECT id, item_code, quantity_on_hand, reorder_level
        FROM `items`
        WHERE id = ? AND quantity_on_hand <= reorder_level
    ");
    $lowStockStmt->execute([$testItemId]);
    $lowItem = $lowStockStmt->fetch();
    assert_test("Low-Stock Alert Triggered (15 <= 20)", !empty($lowItem) && (float)$lowItem['quantity_on_hand'] === 15.0);

    // 7. Test Physical Count Absolute Reconciliation (Reset to 60)
    $resetRes = record_stock_movement($pdo, [
        'item_id'          => $testItemId,
        'movement_type'    => 'adjustment',
        'quantity'         => 60.0,
        'is_absolute_set'  => true,
        'reference_type'   => 'adjustment',
        'reference_number' => 'TEST-ADJ-COUNT',
        'performed_by'     => 1,
        'notes'            => 'Physical stocktake count reconciliation'
    ]);
    assert_test("Physical Count Reset to 60.0", (float)$resetRes['balance_after'] === 60.0);

    $checkStmt->execute([$testItemId]);
    assert_test("MySQL quantity_on_hand verified at 60.0", (float)$checkStmt->fetchColumn() === 60.0);

    // Item is no longer low-stock
    $lowStockStmt->execute([$testItemId]);
    assert_test("Item Cleared from Low-Stock Alerts after Restock (60 > 20)", $lowStockStmt->fetch() === false);

    // 8. Test Live Valuation Calculation
    // 60 units * 450.0 = 27,000.00 KSh
    $valStmt = $pdo->prepare("SELECT (quantity_on_hand * unit_cost) AS total_val FROM `items` WHERE id = ?");
    $valStmt->execute([$testItemId]);
    $itemVal = (float)$valStmt->fetchColumn();
    assert_test("Item Valuation Calculation (60 * 450 = 27,000 KSh)", $itemVal === 27000.0, "Got: {$itemVal}");

    // 9. Clean up test item
    $pdo->prepare("DELETE FROM `stock_movements` WHERE item_id = ?")->execute([$testItemId]);
    $pdo->prepare("DELETE FROM `items` WHERE id = ?")->execute([$testItemId]);
    assert_test("Test Cleanup Complete", true);

} catch (Exception $e) {
    assert_test("Unexpected Exception during test", false, $e->getMessage());
}

echo "\n========================================================\n";
echo "Inventory Stock Test Results: {$testsPassed} Passed, {$testsFailed} Failed\n";
echo "========================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
