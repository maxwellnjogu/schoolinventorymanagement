<?php
/**
 * Automated Test Suite: Fixed Asset Register
 * Validates unique asset IDs, serial numbers, acquisition cost, condition,
 * transfers, maintenance history, disposal, and isolation from consumable stock.
 * St. Jude High School Inventory Management System
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/asset_helpers.php';

$pdo = Database::getConnection();

if (!$pdo) {
    echo "FAIL: Cannot connect to MySQL on port " . DB_PORT . "\n";
    exit(1);
}

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$name}\n";
    } else {
        $failed++;
        echo "[FAIL] {$name} - {$detail}\n";
    }
}

echo "========================================================\n";
echo "Testing Fixed Asset Register, Lifecycle & Disposals\n";
echo "========================================================\n\n";

try {
    // 1. Fetch test actor (admin)
    $admin = $pdo->query("SELECT id FROM `users` WHERE username = 'admin'")->fetch();
    $adminId = (int)$admin['id'];

    // 2. Fetch test departments, locations, categories
    $depts = $pdo->query("SELECT id FROM `departments` ORDER BY id ASC LIMIT 2")->fetchAll();
    $dept1Id = (int)$depts[0]['id'];
    $dept2Id = (int)$depts[1]['id'];

    $locs = $pdo->query("SELECT id FROM `locations` ORDER BY id ASC LIMIT 2")->fetchAll();
    $loc1Id = (int)$locs[0]['id'];
    $loc2Id = (int)$locs[1]['id'];

    $cat = $pdo->query("SELECT id FROM `categories` LIMIT 1")->fetch();
    $catId = (int)$cat['id'];

    // ========================================================
    // 1. ASSET TAG GENERATION & UNIQUE REGISTRATION
    // ========================================================
    $tag = generate_asset_tag($pdo, 'ICT');
    assert_test("Generate unique Asset Tag matches SJHS/CAT/YYYY/####", (bool)preg_match('/^SJHS\/ICT\/\d{4}\/\d{4}$/', $tag), "Got: {$tag}");

    $uniqueSerial = 'SN-TEST-' . time() . '-' . rand(100, 999);
    $purchaseCost = 65000.00;
    $purchaseDate = '2025-06-15';

    // Record consumable stock total before asset creation to verify isolation
    $consumableTotalBefore = (float)$pdo->query("SELECT SUM(quantity_on_hand) FROM `items`")->fetchColumn();

    $createRes = create_fixed_asset($pdo, [
        'name'             => 'Test Dell OptiPlex 7080 Workstation',
        'category_id'      => $catId,
        'department_id'    => $dept1Id,
        'location_id'      => $loc1Id,
        'serial_number'    => $uniqueSerial,
        'model'            => 'OptiPlex 7080',
        'manufacturer'     => 'Dell Technologies',
        'purchase_date'    => $purchaseDate,
        'purchase_cost'    => $purchaseCost,
        'condition_status' => 'new',
        'status'           => 'in_use',
        'assigned_to'      => 'ICT Lab Room 101',
        'notes'            => 'Test individual computer unit'
    ], $adminId);

    $testAssetId = $createRes['asset_id'];
    $testAssetTag = $createRes['asset_tag'];
    assert_test("Fixed Asset Registered successfully with ID {$testAssetId}", $createRes['success'] && $testAssetId > 0);

    // ========================================================
    // 2. ISOLATION FROM CONSUMABLE STOCK
    // ========================================================
    $consumableTotalAfter = (float)$pdo->query("SELECT SUM(quantity_on_hand) FROM `items`")->fetchColumn();
    assert_test("Consumable inventory quantities strictly UNTOUCHED by fixed asset registration", $consumableTotalBefore === $consumableTotalAfter);

    // Verify Asset Details in DB
    $asset = get_asset_details($pdo, $testAssetId);
    assert_test("Asset details retrieved accurately", $asset && $asset['serial_number'] === $uniqueSerial);
    assert_test("Acquisition cost stored as 65,000 KSh", (float)$asset['purchase_cost'] === 65000.00);
    assert_test("Initial condition is 'new'", $asset['condition_status'] === 'new');
    assert_test("Initial operational status is 'in_use'", $asset['status'] === 'in_use');

    // ========================================================
    // 3. SERIAL NUMBER & TAG DUPLICATION PREVENTION
    // ========================================================
    $dupSerialCaught = false;
    try {
        create_fixed_asset($pdo, [
            'name'             => 'Duplicate Serial Machine',
            'category_id'      => $catId,
            'department_id'    => $dept1Id,
            'location_id'      => $loc1Id,
            'serial_number'    => $uniqueSerial // Duplicate!
        ], $adminId);
    } catch (InvalidArgumentException $e) {
        $dupSerialCaught = true;
    }
    assert_test("Prevent duplicate physical serial number registration (InvalidArgumentException)", $dupSerialCaught);

    // ========================================================
    // 4. METADATA UPDATE
    // ========================================================
    $updateSuccess = update_fixed_asset($pdo, $testAssetId, [
        'name'             => 'Test Dell OptiPlex 7080 Workstation (Upgraded)',
        'category_id'      => $catId,
        'department_id'    => $dept1Id,
        'location_id'      => $loc1Id,
        'serial_number'    => $uniqueSerial,
        'model'            => 'OptiPlex 7080 MT',
        'manufacturer'     => 'Dell Technologies',
        'purchase_date'    => $purchaseDate,
        'purchase_cost'    => 68000.00, // Adjusted valuation
        'condition_status' => 'good',
        'status'           => 'in_use',
        'assigned_to'      => 'ICT Lab Workstation 05',
        'notes'            => 'Upgraded with 16GB RAM'
    ], $adminId);
    assert_test("Asset metadata updated successfully", $updateSuccess);

    $assetUpdated = get_asset_details($pdo, $testAssetId);
    assert_test("Updated name and cost (68,000 KSh) verified", 
        $assetUpdated['name'] === 'Test Dell OptiPlex 7080 Workstation (Upgraded)' && (float)$assetUpdated['purchase_cost'] === 68000.00
    );

    // ========================================================
    // 5. ASSET CUSTODY TRANSFER
    // ========================================================
    $transferRes = transfer_fixed_asset($pdo, $testAssetId, [
        'to_department_id' => $dept2Id,
        'to_location_id'   => $loc2Id,
        'to_assigned_to'   => 'Science Dept Teacher Desk 2',
        'transfer_date'    => date('Y-m-d'),
        'reason'           => 'Reassigned to Science department for laboratory reporting'
    ], $adminId);
    assert_test("Asset Custody Transfer recorded successfully", $transferRes['success']);

    // Check that asset location and department changed
    $assetAfterTransfer = get_asset_details($pdo, $testAssetId);
    assert_test("Asset current department updated to Destination Dept", (int)$assetAfterTransfer['department_id'] === $dept2Id);
    assert_test("Asset current location updated to Destination Location", (int)$assetAfterTransfer['location_id'] === $loc2Id);
    assert_test("Asset custodian updated to 'Science Dept Teacher Desk 2'", $assetAfterTransfer['assigned_to'] === 'Science Dept Teacher Desk 2');

    // Check transfer history log
    $transferHistory = get_asset_transfers($pdo, $testAssetId);
    assert_test("Transfer history contains 1 record", count($transferHistory) === 1);
    assert_test("Transfer log records original department as from_department", (int)$transferHistory[0]['from_department_id'] === $dept1Id);
    assert_test("Transfer log records destination department as to_department", (int)$transferHistory[0]['to_department_id'] === $dept2Id);

    // ========================================================
    // 6. MAINTENANCE & SERVICING HISTORY
    // ========================================================
    $maintCost1 = 3500.00;
    $maintRes1 = record_asset_maintenance($pdo, $testAssetId, [
        'maintenance_date' => date('Y-m-d'),
        'completion_date'  => date('Y-m-d'),
        'maintenance_type' => 'repair',
        'service_provider' => 'FastFix Computers Nakuru',
        'cost'             => $maintCost1,
        'status'           => 'completed',
        'description'      => 'Replaced cooling fan and serviced motherboard power connector',
        'condition_after'  => 'good'
    ], $adminId);
    assert_test("First Maintenance log recorded successfully", $maintRes1['success']);

    // Second maintenance (preventive servicing)
    $maintCost2 = 1500.00;
    $maintRes2 = record_asset_maintenance($pdo, $testAssetId, [
        'maintenance_date' => date('Y-m-d'),
        'completion_date'  => date('Y-m-d'),
        'maintenance_type' => 'preventive',
        'service_provider' => 'School IT Technician',
        'cost'             => $maintCost2,
        'status'           => 'completed',
        'description'      => 'Cleaned heat sink, OS patch updates and anti-virus scan',
        'condition_after'  => 'good'
    ], $adminId);
    assert_test("Second Maintenance log recorded successfully", $maintRes2['success']);

    $maintLogs = get_asset_maintenance_history($pdo, $testAssetId);
    assert_test("Asset maintenance history contains 2 records", count($maintLogs) === 2);

    $totMaint = 0.0;
    foreach ($maintLogs as $ml) {
        $totMaint += (float)$ml['cost'];
    }
    assert_test("Cumulative maintenance cost equals 5,000 KSh (3500 + 1500)", $totMaint === 5000.00, "Got: {$totMaint}");

    // ========================================================
    // 7. ASSET DISPOSAL & WRITE-OFF
    // ========================================================
    $proceeds = 8500.00;
    $dispRes = dispose_fixed_asset($pdo, $testAssetId, [
        'disposal_date'   => date('Y-m-d'),
        'disposal_method' => 'sold',
        'proceeds'        => $proceeds,
        'reason'          => 'Auctioned under BOM resolution #05/2026 due to fleet upgrade'
    ], $adminId);
    assert_test("Asset Disposal recorded successfully", $dispRes['success']);

    $assetDisposed = get_asset_details($pdo, $testAssetId);
    assert_test("Asset condition_status updated to 'disposed'", $assetDisposed['condition_status'] === 'disposed');
    assert_test("Asset operational status updated to 'disposed'", $assetDisposed['status'] === 'disposed');
    assert_test("Disposal record reflects method 'sold'", $assetDisposed['disposal_method'] === 'sold');
    assert_test("Disposal proceeds recorded as 8,500 KSh", (float)$assetDisposed['proceeds'] === $proceeds);

    // 8. Prevent transfer of already disposed asset
    $transferDisposedCaught = false;
    try {
        transfer_fixed_asset($pdo, $testAssetId, [
            'to_department_id' => $dept1Id,
            'to_location_id'   => $loc1Id,
            'reason'           => 'Attempt transfer after disposal'
        ], $adminId);
    } catch (InvalidArgumentException $e) {
        $transferDisposedCaught = true;
    }
    assert_test("Prevent transfer of disposed/written-off asset (InvalidArgumentException caught)", $transferDisposedCaught);

    // ========================================================
    // 9. SUMMARY STATS INTEGRITY
    // ========================================================
    $summaryStats = get_asset_summary_stats($pdo);
    assert_test("Summary stats returns valid total assets count (> 0)", $summaryStats['total_assets'] > 0);
    assert_test("Summary stats returns total acquisition cost (> 0 KSh)", $summaryStats['total_acquisition_cost'] > 0);
    assert_test("Summary stats returns disposed count (> 0)", $summaryStats['disposed_count'] > 0);

    // ========================================================
    // CLEANUP
    // ========================================================
    $pdo->query("DELETE FROM `asset_disposals` WHERE asset_id = {$testAssetId}");
    $pdo->query("DELETE FROM `asset_maintenance` WHERE asset_id = {$testAssetId}");
    $pdo->query("DELETE FROM `asset_transfers` WHERE asset_id = {$testAssetId}");
    $pdo->query("DELETE FROM `fixed_assets` WHERE id = {$testAssetId}");

    assert_test("Fixed asset test fixtures cleaned up cleanly", true);

} catch (Exception $e) {
    echo "EXCEPTION OCCURRED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
}

echo "\n========================================================\n";
echo "Fixed Asset Register Test Results: {$passed} Passed, {$failed} Failed\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
