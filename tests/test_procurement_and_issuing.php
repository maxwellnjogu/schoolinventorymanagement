<?php
/**
 * Automated Test Suite: Procurement, Goods Receiving, Partial Deliveries,
 * Requisitions, Partial Approvals, Stock Issuing & Department Returns
 * St. Jude High School Inventory Management System
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/inventory_helpers.php';
require_once __DIR__ . '/../includes/procurement_helpers.php';
require_once __DIR__ . '/../includes/requisition_helpers.php';

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
echo "Testing Procurement, GRN, Requisitions, Issuing & Returns\n";
echo "========================================================\n\n";

try {
    // 1. Fetch test actors
    $admin = $pdo->query("SELECT id FROM `users` WHERE username = 'admin'")->fetch();
    $adminId = (int)$admin['id'];

    $hod = $pdo->query("SELECT id, department_id FROM `users` WHERE username = 'hod_science'")->fetch();
    $hodId = (int)$hod['id'];
    $scienceDeptId = (int)$hod['department_id'];

    // 2. Fetch or create an active supplier
    $supplier = $pdo->query("SELECT id FROM `suppliers` WHERE status = 'active' LIMIT 1")->fetch();
    if (!$supplier) {
        $pdo->query("INSERT INTO `suppliers` (supplier_code, company_name, contact_person, status) VALUES ('SUP-TEST', 'Test Supplier Ltd', 'Test Contact', 'active')");
        $supplierId = (int)$pdo->lastInsertId();
    } else {
        $supplierId = (int)$supplier['id'];
    }

    // 3. Create a clean dedicated test item
    $testItemCode = 'TEST-PROC-' . time();
    $initStock = 10.0;
    $unitPrice = 500.00;

    $cat = $pdo->query("SELECT id FROM `categories` LIMIT 1")->fetch();
    $catId = (int)$cat['id'];
    $loc = $pdo->query("SELECT id FROM `locations` LIMIT 1")->fetch();
    $locId = (int)$loc['id'];

    // Insert test item
    $pdo->beginTransaction();
    $insertStmt = $pdo->prepare("
        INSERT INTO `items` (
            `category_id`, `location_id`, `item_code`, `name`,
            `description`, `unit_of_measure`, `is_asset`,
            `unit_cost`, `quantity_on_hand`, `reorder_level`,
            `status`, `created_at`
        ) VALUES (1, 1, ?, 'Test Procurement Reams', 'Procurement Testing Item', 'reams', 0, ?, 0.0, 5.0, 'active', NOW())
    ");
    $insertStmt->execute([$testItemCode, $unitPrice]);
    $testItemId = (int)$pdo->lastInsertId();

    $openRes = record_stock_movement($pdo, [
        'item_id'          => $testItemId,
        'movement_type'    => 'in',
        'quantity'         => $initStock,
        'reference_type'   => 'adjustment',
        'reference_number' => 'INIT-' . $testItemId,
        'unit_cost'        => $unitPrice,
        'performed_by'     => $adminId,
        'notes'            => 'Initial opening stock for test'
    ]);
    $pdo->commit();

    assert_test("Test Item created with initial stock 10.0", (float)$openRes['balance_after'] === 10.0);

    // ========================================================
    // PURCHASE ORDERS & GOODS RECEIVING TESTS
    // ========================================================

    // 4. Test unique PO number format
    $poNum = generate_po_number($pdo);
    assert_test("Generate unique PO number matches PO-YYYY-#### pattern", (bool)preg_match('/^PO-\d{4}-\d{4}$/', $poNum), "Got: {$poNum}");

    // 5. Create Purchase Order for 100 units
    $orderQty = 100.0;
    $poRes = create_purchase_order($pdo, [
        'supplier_id' => $supplierId,
        'order_date'  => date('Y-m-d'),
        'status'      => 'ordered',
        'notes'       => 'Test delivery'
    ], [
        [
            'item_id'          => $testItemId,
            'quantity_ordered' => $orderQty,
            'unit_price'       => $unitPrice
        ]
    ], $adminId);

    $testPoId = $poRes['po_id'];
    $testPoNumber = $poRes['po_number'];
    assert_test("Purchase Order Created ({$testPoNumber})", $poRes['success'] && $testPoId > 0);

    // 6. Verify stock has NOT changed on PO creation
    $stockCheck = (float)$pdo->query("SELECT quantity_on_hand FROM `items` WHERE id = {$testItemId}")->fetchColumn();
    assert_test("Stock STRICTLY unchanged on PO creation (remains 10.0)", $stockCheck === 10.0, "Got: {$stockCheck}");

    // 7. Test Partial Delivery: Deliver 40 out of 100 items via GRN
    $firstBatchQty = 40.0;
    $poItemRow = $pdo->query("SELECT id FROM `purchase_order_items` WHERE po_id = {$testPoId}")->fetch();
    $poItemId = (int)$poItemRow['id'];

    $grn1 = receive_goods_grn($pdo, $testPoId, [
        'delivery_note_number' => 'DN-PARTIAL-001',
        'received_date'        => date('Y-m-d'),
        'remarks'              => 'First batch partial delivery'
    ], [
        [
            'po_item_id'        => $poItemId,
            'item_id'           => $testItemId,
            'quantity_received' => $firstBatchQty,
            'unit_cost'         => $unitPrice
        ]
    ], $adminId);

    assert_test("First Partial GRN Created ({$grn1['grn_number']})", $grn1['success']);
    assert_test("PO status updated to 'partially_received'", $grn1['po_status'] === 'partially_received');

    // 8. Verify outstanding quantity calculation (100 - 40 = 60)
    $poiCheck = $pdo->query("SELECT quantity_ordered, quantity_received FROM `purchase_order_items` WHERE id = {$poItemId}")->fetch();
    $outstanding = (float)$poiCheck['quantity_ordered'] - (float)$poiCheck['quantity_received'];
    assert_test("PO Line Outstanding Quantity equals 60.0", $outstanding === 60.0, "Got: {$outstanding}");

    // 9. Verify physical stock increased by exactly 40 (10 + 40 = 50)
    $stockAfterGrn1 = (float)$pdo->query("SELECT quantity_on_hand FROM `items` WHERE id = {$testItemId}")->fetchColumn();
    assert_test("Physical stock updated to 50.0 upon First GRN", $stockAfterGrn1 === 50.0, "Got: {$stockAfterGrn1}");

    // 10. Verify stock movement ledger entry for GRN 1
    $mvGrn1 = $pdo->query("SELECT * FROM `stock_movements` WHERE reference_number = '{$grn1['grn_number']}'")->fetch();
    assert_test("Stock movement recorded: Type 'in', Qty 40.0, Balance After 50.0", 
        $mvGrn1 && $mvGrn1['movement_type'] === 'in' && (float)$mvGrn1['quantity'] === 40.0 && (float)$mvGrn1['balance_after'] === 50.0
    );

    // 11. Test Over-Delivery Prevention (Attempt to receive 70 when outstanding is 60)
    $overflowCaught = false;
    try {
        receive_goods_grn($pdo, $testPoId, [
            'delivery_note_number' => 'DN-EXCESS',
            'received_date'        => date('Y-m-d'),
        ], [
            [
                'po_item_id'        => $poItemId,
                'item_id'           => $testItemId,
                'quantity_received' => 70.0, // Exceeds outstanding 60.0
                'unit_cost'         => $unitPrice
            ]
        ], $adminId);
    } catch (OverflowException $e) {
        $overflowCaught = true;
    }
    assert_test("Prevent over-delivery exceeding outstanding quantity (OverflowException caught)", $overflowCaught);

    // 12. Complete Delivery: Deliver remaining 60 items via second GRN
    $grn2 = receive_goods_grn($pdo, $testPoId, [
        'delivery_note_number' => 'DN-FINAL-002',
        'received_date'        => date('Y-m-d'),
        'remarks'              => 'Final completing delivery'
    ], [
        [
            'po_item_id'        => $poItemId,
            'item_id'           => $testItemId,
            'quantity_received' => 60.0,
            'unit_cost'         => $unitPrice
        ]
    ], $adminId);

    assert_test("Second Completing GRN Created ({$grn2['grn_number']})", $grn2['success']);
    assert_test("PO status updated to 'received' (Full Fulfillment)", $grn2['po_status'] === 'received');

    // 13. Verify final stock balance (50 + 60 = 110)
    $stockAfterGrn2 = (float)$pdo->query("SELECT quantity_on_hand FROM `items` WHERE id = {$testItemId}")->fetchColumn();
    assert_test("Physical stock updated to 110.0 upon Full Delivery", $stockAfterGrn2 === 110.0, "Got: {$stockAfterGrn2}");

    // ========================================================
    // DEPARTMENT REQUISITIONS & APPROVAL TESTS
    // ========================================================

    // 14. Test unique request number format
    $reqNum = generate_request_number($pdo);
    assert_test("Generate unique Request number matches REQ-YYYY-#### pattern", (bool)preg_match('/^REQ-\d{4}-\d{4}$/', $reqNum), "Got: {$reqNum}");

    // 15. Create Stock Request for 50 items
    $reqRes = create_stock_request($pdo, [
        'department_id' => $scienceDeptId,
        'request_date'  => date('Y-m-d'),
        'remarks'       => 'Science lab practical tests'
    ], [
        [
            'item_id'            => $testItemId,
            'quantity_requested' => 50.0,
            'remarks'            => 'Paper for exams'
        ]
    ], $hodId);

    $testReqId = $reqRes['request_id'];
    assert_test("Department Stock Request Created ({$reqRes['request_number']})", $reqRes['success'] && $testReqId > 0);

    // Check status is pending
    $reqStatus = $pdo->query("SELECT status FROM `stock_requests` WHERE id = {$testReqId}")->fetchColumn();
    assert_test("Initial Request Status is 'pending'", $reqStatus === 'pending');

    // 16. Test Requisition Rejection with Reason
    $rejReqRes = create_stock_request($pdo, [
        'department_id' => $scienceDeptId,
        'request_date'  => date('Y-m-d'),
        'remarks'       => 'To be rejected'
    ], [
        [
            'item_id'            => $testItemId,
            'quantity_requested' => 20.0
        ]
    ], $hodId);
    $rejReqId = $rejReqRes['request_id'];

    $rejResult = review_stock_request($pdo, $rejReqId, 'reject', 'Department budget quota exceeded for Term 2', [], $adminId);
    assert_test("Requisition Rejection handled successfully", $rejResult['status'] === 'rejected');

    $rejRow = $pdo->query("SELECT status, rejection_reason FROM `stock_requests` WHERE id = {$rejReqId}")->fetch();
    assert_test("Rejection Reason stored correctly in DB", $rejRow['rejection_reason'] === 'Department budget quota exceeded for Term 2');

    // 17. Verify stock balance was NOT changed by rejection
    $stockAfterRej = (float)$pdo->query("SELECT quantity_on_hand FROM `items` WHERE id = {$testItemId}")->fetchColumn();
    assert_test("Stock balance strictly untouched by rejection (remains 110.0)", $stockAfterRej === 110.0);

    // 18. Test Partial Approval on the main request (Requested 50.0, Approve 30.0)
    $reqItemRow = $pdo->query("SELECT id FROM `stock_request_items` WHERE request_id = {$testReqId}")->fetch();
    $reqItemId = (int)$reqItemRow['id'];

    $appResult = review_stock_request($pdo, $testReqId, 'approve', null, [
        $reqItemId => 30.0 // Partial approval
    ], $adminId);

    assert_test("Partial Approval handled successfully (Status 'approved')", $appResult['status'] === 'approved');

    $appItemRow = $pdo->query("SELECT quantity_requested, quantity_approved FROM `stock_request_items` WHERE id = {$reqItemId}")->fetch();
    assert_test("Approved Quantity recorded as 30.0 (Requested was 50.0)", (float)$appItemRow['quantity_approved'] === 30.0);

    // 19. STRICT BUSINESS RULE: Verify stock has NOT decreased upon Approval!
    $stockAfterApp = (float)$pdo->query("SELECT quantity_on_hand FROM `items` WHERE id = {$testItemId}")->fetchColumn();
    assert_test("CRITICAL: Stock strictly untouched upon Approval (remains 110.0)", $stockAfterApp === 110.0, "Got: {$stockAfterApp}");

    // ========================================================
    // STOCK ISSUING TESTS
    // ========================================================

    // 20. Test unique Issue number format
    $issNum = generate_issue_number($pdo);
    assert_test("Generate unique Issue number matches ISS-YYYY-#### pattern", (bool)preg_match('/^ISS-\d{4}-\d{4}$/', $issNum), "Got: {$issNum}");

    // 21. Attempt to issue more than approved quantity (Attempt to issue 35 when approved is 30)
    $overIssueCaught = false;
    try {
        issue_stock_for_request($pdo, $testReqId, [
            'issued_to'  => 'Mr. Otieno (Science)',
            'issue_date' => date('Y-m-d')
        ], [
            [
                'request_item_id' => $reqItemId,
                'item_id'         => $testItemId,
                'quantity_issued' => 35.0 // Exceeds approved 30.0
            ]
        ], $adminId);
    } catch (OverflowException $e) {
        $overIssueCaught = true;
    }
    assert_test("Prevent issuing more than approved quantity (OverflowException caught)", $overIssueCaught);

    // 21b. Test Insufficient Store Stock Rejection (UnderflowException)
    // Create zero-stock item, request & approve 5, then try to issue
    $zeroItemCode = 'ZERO-STOCK-' . time();
    $pdo->prepare("INSERT INTO `items` (`category_id`, `location_id`, `item_code`, `name`, `unit_of_measure`, `is_asset`, `unit_cost`, `quantity_on_hand`, `reorder_level`, `status`, `created_at`) VALUES (1, 1, ?, 'Zero Stock Chalk', 'Pack', 0, 50.0, 0.0, 2.0, 'active', NOW())")->execute([$zeroItemCode]);
    $zeroItemId = (int)$pdo->lastInsertId();

    $zeroReqRes = create_stock_request($pdo, [
        'department_id' => $scienceDeptId,
        'request_date'  => date('Y-m-d'),
        'remarks'       => 'Zero stock test'
    ], [
        ['item_id' => $zeroItemId, 'quantity_requested' => 5.0]
    ], $hodId);
    $zeroReqId = $zeroReqRes['request_id'];
    $zeroReqItemRow = $pdo->query("SELECT id FROM `stock_request_items` WHERE request_id = {$zeroReqId}")->fetch();
    $zeroReqItemId = (int)$zeroReqItemRow['id'];
    review_stock_request($pdo, $zeroReqId, 'approve', null, [$zeroReqItemId => 5.0], $adminId);

    $underflowCaught = false;
    try {
        issue_stock_for_request($pdo, $zeroReqId, [
            'issued_to'  => 'Mr. Otieno',
            'issue_date' => date('Y-m-d')
        ], [
            ['request_item_id' => $zeroReqItemId, 'item_id' => $zeroItemId, 'quantity_issued' => 5.0]
        ], $adminId);
    } catch (UnderflowException $e) {
        $underflowCaught = true;
    }
    assert_test("Prevent issuing when insufficient store stock (UnderflowException caught)", $underflowCaught);

    // Cleanup zero-stock test item
    $pdo->query("DELETE FROM `stock_request_items` WHERE request_id = {$zeroReqId}");
    $pdo->query("DELETE FROM `stock_requests` WHERE id = {$zeroReqId}");
    $pdo->query("DELETE FROM `items` WHERE id = {$zeroItemId}");

    // 22. Partial Issue: Issue 20 of the approved 30 units
    $issue1 = issue_stock_for_request($pdo, $testReqId, [
        'issued_to'  => 'Mr. Otieno (Chemistry HOD)',
        'issue_date' => date('Y-m-d'),
        'remarks'    => 'First batch for Lab A'
    ], [
        [
            'request_item_id' => $reqItemId,
            'item_id'         => $testItemId,
            'quantity_issued' => 20.0
        ]
    ], $adminId);

    assert_test("First Partial Issue Note Created ({$issue1['issue_number']})", $issue1['success']);
    assert_test("Request status updated to 'partially_issued'", $issue1['request_status'] === 'partially_issued');

    // 23. Verify store stock decreases strictly on actual issue (110 - 20 = 90)
    $stockAfterIss1 = (float)$pdo->query("SELECT quantity_on_hand FROM `items` WHERE id = {$testItemId}")->fetchColumn();
    assert_test("Store stock decreased by 20.0 to 90.0 on actual issue", $stockAfterIss1 === 90.0, "Got: {$stockAfterIss1}");

    // 24. Verify Stock Movement Ledger entry for Issue
    $mvIss1 = $pdo->query("SELECT * FROM `stock_movements` WHERE reference_number = '{$issue1['issue_number']}'")->fetch();
    assert_test("Issue Stock Movement: Type 'out', Qty 20.0, Balance After 90.0", 
        $mvIss1 && $mvIss1['movement_type'] === 'out' && (float)$mvIss1['quantity'] === 20.0 && (float)$mvIss1['balance_after'] === 90.0
    );

    // 25. Final Issue completing the approved 30 units (Issue remaining 10)
    $issue2 = issue_stock_for_request($pdo, $testReqId, [
        'issued_to'  => 'Mr. Otieno (Chemistry HOD)',
        'issue_date' => date('Y-m-d'),
        'remarks'    => 'Second batch completing approved requisition'
    ], [
        [
            'request_item_id' => $reqItemId,
            'item_id'         => $testItemId,
            'quantity_issued' => 10.0
        ]
    ], $adminId);

    assert_test("Final Issue Note Created ({$issue2['issue_number']})", $issue2['success']);
    assert_test("Request status updated to 'issued' (Fully Completed)", $issue2['request_status'] === 'issued');

    // 26. Verify store stock decreased by another 10 (90 - 10 = 80)
    $stockAfterIss2 = (float)$pdo->query("SELECT quantity_on_hand FROM `items` WHERE id = {$testItemId}")->fetchColumn();
    assert_test("Store stock decreased by 10.0 to 80.0 on final issue", $stockAfterIss2 === 80.0, "Got: {$stockAfterIss2}");

    // ========================================================
    // DEPARTMENT STOCK RETURN TESTS
    // ========================================================

    // 27. Record Department Return of 5 unused units
    $returnRes = record_stock_return($pdo, [
        'department_id' => $scienceDeptId,
        'item_id'       => $testItemId,
        'quantity'      => 5.0,
        'return_reason' => 'Unused test papers returned from Chemistry practicals'
    ], $adminId);

    assert_test("Department Stock Return processed successfully", $returnRes['success']);
    assert_test("Stock balance increased by 5.0 to 85.0 upon Return", (float)$returnRes['balance_after'] === 85.0);

    // 28. Verify Return Ledger entry
    $mvRet = $pdo->query("SELECT * FROM `stock_movements` WHERE reference_number = '{$returnRes['return_number']}'")->fetch();
    assert_test("Return Stock Movement: Type 'in', Reference 'return', Balance 85.0",
        $mvRet && $mvRet['movement_type'] === 'in' && $mvRet['reference_type'] === 'return' && (float)$mvRet['balance_after'] === 85.0
    );

    // ========================================================
    // CLEANUP
    // ========================================================
    $pdo->query("DELETE FROM `goods_received_items` WHERE item_id = {$testItemId}");
    $pdo->query("DELETE FROM `goods_received_notes` WHERE po_id = {$testPoId}");
    $pdo->query("DELETE FROM `purchase_order_items` WHERE po_id = {$testPoId}");
    $pdo->query("DELETE FROM `purchase_orders` WHERE id = {$testPoId}");
    $pdo->query("DELETE FROM `stock_issue_items` WHERE item_id = {$testItemId}");
    $pdo->query("DELETE FROM `stock_issues` WHERE request_id IN ({$testReqId}, {$rejReqId})");
    $pdo->query("DELETE FROM `stock_request_items` WHERE item_id = {$testItemId}");
    $pdo->query("DELETE FROM `stock_requests` WHERE id IN ({$testReqId}, {$rejReqId})");
    $pdo->query("DELETE FROM `stock_movements` WHERE item_id = {$testItemId}");
    $pdo->query("DELETE FROM `items` WHERE id = {$testItemId}");

    assert_test("Test fixtures cleaned up cleanly", true);

} catch (Exception $e) {
    echo "EXCEPTION OCCURRED: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failed++;
}

echo "\n========================================================\n";
echo "Procurement & Issuing Test Results: {$passed} Passed, {$failed} Failed\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
