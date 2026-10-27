<?php
/**
 * Department Stock Requisitions, Approvals, and Issuing Helpers
 * St. Jude High School Inventory Management System
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/inventory_helpers.php';

/**
 * Generate Next Unique Stock Request Number (e.g. REQ-2026-0001)
 * 
 * @param PDO $pdo
 * @return string
 */
function generate_request_number(PDO $pdo): string {
    $year = date('Y');
    $prefix = "REQ-{$year}-";
    $stmt = $pdo->prepare("SELECT request_number FROM `stock_requests` WHERE request_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    if ($last && preg_match('/-(\d+)$/', $last, $matches)) {
        $next = (int)$matches[1] + 1;
    } else {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM `stock_requests`");
        $next = ((int)$countStmt->fetchColumn()) + 1;
    }

    return sprintf('%s%04d', $prefix, $next);
}

/**
 * Generate Next Unique Stock Issue Number (e.g. ISS-2026-0001)
 * 
 * @param PDO $pdo
 * @return string
 */
function generate_issue_number(PDO $pdo): string {
    $year = date('Y');
    $prefix = "ISS-{$year}-";
    $stmt = $pdo->prepare("SELECT issue_number FROM `stock_issues` WHERE issue_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    if ($last && preg_match('/-(\d+)$/', $last, $matches)) {
        $next = (int)$matches[1] + 1;
    } else {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM `stock_issues`");
        $next = ((int)$countStmt->fetchColumn()) + 1;
    }

    return sprintf('%s%04d', $prefix, $next);
}

/**
 * Create a Department Stock Request (Requisition)
 * 
 * @param PDO $pdo
 * @param array $reqData [department_id, request_date, remarks]
 * @param array $items [[item_id, quantity_requested, remarks]]
 * @param int $userId
 * @return array [success => bool, request_id => int, request_number => string]
 * @throws Exception
 */
function create_stock_request(PDO $pdo, array $reqData, array $items, int $userId): array {
    $departmentId = (int)($reqData['department_id'] ?? 0);
    $requestDate = $reqData['request_date'] ?? date('Y-m-d');
    $remarks = $reqData['remarks'] ?? null;

    if ($departmentId <= 0) {
        throw new InvalidArgumentException('Please specify the requesting school department.');
    }

    if (empty($items)) {
        throw new InvalidArgumentException('A stock request must contain at least one requested item.');
    }

    $isLocalTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $isLocalTx = true;
    }

    try {
        $reqNumber = generate_request_number($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO `stock_requests` (
                `request_number`, `department_id`, `requested_by`, `request_date`,
                `status`, `remarks`, `created_at`
            ) VALUES (?, ?, ?, ?, 'pending', ?, NOW())
        ");
        $stmt->execute([
            $reqNumber,
            $departmentId,
            $userId,
            $requestDate,
            $remarks
        ]);
        $requestId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare("
            INSERT INTO `stock_request_items` (
                `request_id`, `item_id`, `quantity_requested`,
                `quantity_approved`, `quantity_issued`, `remarks`
            ) VALUES (?, ?, ?, 0.00, 0.00, ?)
        ");

        foreach ($items as $line) {
            $itemId = (int)$line['item_id'];
            $qty = (float)$line['quantity_requested'];
            $lineRemarks = $line['remarks'] ?? null;

            if ($qty <= 0) {
                throw new InvalidArgumentException("Quantity requested must be greater than zero.");
            }

            $itemStmt->execute([$requestId, $itemId, $qty, $lineRemarks]);
        }

        log_audit_action($pdo, 'REQUEST_CREATED', 'stock_requests', $requestId, [
            'request_number' => $reqNumber,
            'department_id'  => $departmentId,
            'items_count'    => count($items)
        ]);

        if ($isLocalTx) {
            $pdo->commit();
        }

        return ['success' => true, 'request_id' => $requestId, 'request_number' => $reqNumber];
    } catch (Exception $e) {
        if ($isLocalTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Create request error: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Review, Approve (Full or Partial), or Reject Stock Request
 * Business Rule: Approval does NOT reduce stock! Stock decreases ONLY when issued.
 * 
 * @param PDO $pdo
 * @param int $requestId
 * @param string $action 'approve' | 'reject'
 * @param string|null $rejectionReason
 * @param array $approvedLines [request_item_id => quantity_approved]
 * @param int $userId
 * @return array [success => bool, status => string]
 * @throws Exception
 */
function review_stock_request(PDO $pdo, int $requestId, string $action, ?string $rejectionReason, array $approvedLines, int $userId): array {
    $action = strtolower(trim($action));

    if (!in_array($action, ['approve', 'reject'], true)) {
        throw new InvalidArgumentException("Invalid review action: {$action}");
    }

    $isLocalTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $isLocalTx = true;
    }

    try {
        $lockStmt = $pdo->prepare("SELECT * FROM `stock_requests` WHERE id = ? FOR UPDATE");
        $lockStmt->execute([$requestId]);
        $req = $lockStmt->fetch();

        if (!$req) {
            throw new RuntimeException("Stock request #{$requestId} not found.");
        }

        if ($req['status'] !== 'pending') {
            throw new InvalidArgumentException("Cannot review a request that is already in '{$req['status']}' status.");
        }

        if ($action === 'reject') {
            if (empty(trim((string)$rejectionReason))) {
                throw new InvalidArgumentException('Please provide an official rejection reason.');
            }

            $pdo->prepare("
                UPDATE `stock_requests`
                SET `status` = 'rejected',
                    `approved_by` = ?,
                    `approval_date` = NOW(),
                    `rejection_reason` = ?,
                    `updated_at` = NOW()
                WHERE id = ?
            ")->execute([$userId, trim($rejectionReason), $requestId]);

            log_audit_action($pdo, 'REQUEST_REJECTED', 'stock_requests', $requestId, [
                'request_number' => $req['request_number'],
                'reason'         => $rejectionReason
            ]);

            $newStatus = 'rejected';
        } else {
            // Action === 'approve' (Supports full and partial approvals)
            $updItem = $pdo->prepare("
                UPDATE `stock_request_items`
                SET `quantity_approved` = ?
                WHERE id = ? AND request_id = ?
            ");

            $totalApproved = 0.0;

            foreach ($approvedLines as $reqItemId => $appQty) {
                $reqItemId = (int)$reqItemId;
                $appQty = max(0.0, (float)$appQty);

                // Fetch requested quantity to ensure approved <= requested
                $chkItem = $pdo->prepare("SELECT quantity_requested FROM `stock_request_items` WHERE id = ? AND request_id = ?");
                $chkItem->execute([$reqItemId, $requestId]);
                $reqQty = (float)$chkItem->fetchColumn();

                if ($appQty > $reqQty) {
                    throw new OverflowException("Approved quantity ({$appQty}) cannot exceed requested quantity ({$reqQty}).");
                }

                $updItem->execute([$appQty, $reqItemId, $requestId]);
                $totalApproved += $appQty;
            }

            if ($totalApproved <= 0) {
                throw new InvalidArgumentException("At least one item must have an approved quantity greater than zero. Otherwise reject the request.");
            }

            $pdo->prepare("
                UPDATE `stock_requests`
                SET `status` = 'approved',
                    `approved_by` = ?,
                    `approval_date` = NOW(),
                    `rejection_reason` = NULL,
                    `updated_at` = NOW()
                WHERE id = ?
            ")->execute([$userId, $requestId]);

            // Note: Per business rules, stock is NOT decreased here!
            log_audit_action($pdo, 'REQUEST_APPROVED', 'stock_requests', $requestId, [
                'request_number' => $req['request_number'],
                'total_approved' => $totalApproved
            ]);

            $newStatus = 'approved';
        }

        if ($isLocalTx) {
            $pdo->commit();
        }

        return ['success' => true, 'status' => $newStatus];
    } catch (Exception $e) {
        if ($isLocalTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Review request error: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Issue Stock for Approved Request
 * Business Rule: Stock decreases ONLY when goods are actually issued!
 * Validates available stock on hand and prevents negative stock.
 * 
 * @param PDO $pdo
 * @param int $requestId
 * @param array $issueData [issued_to, issue_date, remarks]
 * @param array $issuedLines [[request_item_id, item_id, quantity_issued, remarks]]
 * @param int $userId
 * @return array [success => bool, issue_id => int, issue_number => string, request_status => string]
 * @throws Exception
 */
function issue_stock_for_request(PDO $pdo, int $requestId, array $issueData, array $issuedLines, int $userId): array {
    $issuedTo = trim($issueData['issued_to'] ?? '');
    $issueDate = $issueData['issue_date'] ?? date('Y-m-d');
    $remarks = $issueData['remarks'] ?? null;

    if (empty($issuedTo)) {
        throw new InvalidArgumentException('Please specify the recipient receiving the stock (e.g. Teacher name / Lab technician).');
    }

    $isLocalTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $isLocalTx = true;
    }

    try {
        $lockStmt = $pdo->prepare("SELECT * FROM `stock_requests` WHERE id = ? FOR UPDATE");
        $lockStmt->execute([$requestId]);
        $req = $lockStmt->fetch();

        if (!$req) {
            throw new RuntimeException("Stock request #{$requestId} not found.");
        }

        if (!in_array($req['status'], ['approved', 'partially_issued'], true)) {
            throw new InvalidArgumentException("Stock can only be issued against 'approved' or 'partially_issued' requisitions (Current: '{$req['status']}').");
        }

        $issueNumber = generate_issue_number($pdo);

        // 1. Create Stock Issue header
        $issueStmt = $pdo->prepare("
            INSERT INTO `stock_issues` (
                `issue_number`, `request_id`, `department_id`,
                `issued_by`, `issued_to`, `issue_date`, `remarks`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $issueStmt->execute([
            $issueNumber,
            $requestId,
            $req['department_id'],
            $userId,
            $issuedTo,
            $issueDate,
            $remarks
        ]);
        $issueId = (int)$pdo->lastInsertId();

        $lineStmt = $pdo->prepare("
            INSERT INTO `stock_issue_items` (
                `issue_id`, `item_id`, `quantity_issued`, `remarks`
            ) VALUES (?, ?, ?, ?)
        ");

        $updReqItem = $pdo->prepare("
            UPDATE `stock_request_items`
            SET `quantity_issued` = `quantity_issued` + ?
            WHERE id = ? AND request_id = ?
        ");

        $hasIssuedAny = false;

        foreach ($issuedLines as $line) {
            $reqItemId = (int)($line['request_item_id'] ?? 0);
            $itemId = (int)($line['item_id'] ?? 0);
            $qtyToIssue = (float)($line['quantity_issued'] ?? 0.0);
            $lineRemarks = $line['remarks'] ?? null;

            if ($qtyToIssue <= 0) {
                continue; // Skip lines with 0 issued in this batch
            }

            // Lock request item line
            $sriStmt = $pdo->prepare("SELECT * FROM `stock_request_items` WHERE id = ? AND request_id = ? FOR UPDATE");
            $sriStmt->execute([$reqItemId, $requestId]);
            $sri = $sriStmt->fetch();

            if (!$sri) {
                throw new RuntimeException("Requisition line item #{$reqItemId} not found.");
            }

            $approved = (float)$sri['quantity_approved'];
            $alreadyIssued = (float)$sri['quantity_issued'];
            $remainingAllowed = max(0.0, $approved - $alreadyIssued);

            if ($qtyToIssue > $remainingAllowed) {
                throw new OverflowException("Quantity to issue ({$qtyToIssue}) exceeds unissued approved quantity ({$remainingAllowed}) for item #{$itemId}.");
            }

            // Lock item catalog row to validate available stock on hand
            $itemStmt = $pdo->prepare("SELECT id, name, quantity_on_hand, unit_of_measure, unit_cost FROM `items` WHERE id = ? FOR UPDATE");
            $itemStmt->execute([$itemId]);
            $item = $itemStmt->fetch();

            if (!$item) {
                throw new RuntimeException("Item #{$itemId} not found in inventory catalog.");
            }

            $availableStock = (float)$item['quantity_on_hand'];
            if ($availableStock < $qtyToIssue) {
                throw new UnderflowException("Insufficient stock in store for '{$item['name']}'. Available: {$availableStock} {$item['unit_of_measure']}, Requested to issue: {$qtyToIssue} {$item['unit_of_measure']}.");
            }

            // 1. Insert issue line item
            $lineStmt->execute([$issueId, $itemId, $qtyToIssue, $lineRemarks]);

            // 2. Update request line item quantity issued
            $updReqItem->execute([$qtyToIssue, $reqItemId, $requestId]);

            // 3. Stock decreases ONLY upon actual issue: record atomic stock movement
            record_stock_movement($pdo, [
                'item_id'          => $itemId,
                'movement_type'    => 'out',
                'quantity'         => $qtyToIssue,
                'reference_type'   => 'issue',
                'reference_number' => $issueNumber,
                'reference_id'     => $issueId,
                'unit_cost'        => (float)$item['unit_cost'],
                'performed_by'     => $userId,
                'notes'            => "Stock Issued to {$issuedTo} (Req: {$req['request_number']})"
            ]);

            $hasIssuedAny = true;
        }

        if (!$hasIssuedAny) {
            throw new InvalidArgumentException("Please specify at least one item quantity to issue.");
        }

        // Determine updated status of the request
        $chkTotals = $pdo->prepare("
            SELECT SUM(quantity_approved) AS total_approved, SUM(quantity_issued) AS total_issued
            FROM `stock_request_items`
            WHERE request_id = ?
        ");
        $chkTotals->execute([$requestId]);
        $totals = $chkTotals->fetch();

        $totApproved = (float)($totals['total_approved'] ?? 0.0);
        $totIssued = (float)($totals['total_issued'] ?? 0.0);

        if ($totIssued >= $totApproved && $totApproved > 0) {
            $newReqStatus = 'issued';
        } else {
            $newReqStatus = 'partially_issued';
        }

        $pdo->prepare("UPDATE `stock_requests` SET `status` = ?, `updated_at` = NOW() WHERE id = ?")
            ->execute([$newReqStatus, $requestId]);

        log_audit_action($pdo, 'STOCK_ISSUED', 'stock_issues', $issueId, [
            'issue_number'   => $issueNumber,
            'request_number' => $req['request_number'],
            'issued_to'      => $issuedTo,
            'new_status'     => $newReqStatus
        ]);

        if ($isLocalTx) {
            $pdo->commit();
        }

        return [
            'success'        => true,
            'issue_id'       => $issueId,
            'issue_number'   => $issueNumber,
            'request_status' => $newReqStatus
        ];
    } catch (Exception $e) {
        if ($isLocalTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Issue stock error: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Record a Return of Unused/Excess Stock from a Department
 * Stock increases when returned to store
 * 
 * @param PDO $pdo
 * @param array $returnData [item_id, department_id, quantity, return_reason]
 * @param int $userId
 * @return array [success => bool, balance_after => float]
 * @throws Exception
 */
function record_stock_return(PDO $pdo, array $returnData, int $userId): array {
    $itemId = (int)($returnData['item_id'] ?? 0);
    $departmentId = (int)($returnData['department_id'] ?? 0);
    $quantity = (float)($returnData['quantity'] ?? 0.0);
    $reason = trim($returnData['return_reason'] ?? 'Unused items returned by department');

    if ($itemId <= 0 || $quantity <= 0) {
        throw new InvalidArgumentException('Invalid item or return quantity.');
    }

    $isLocalTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $isLocalTx = true;
    }

    try {
        $returnNumber = 'RET-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        // Record stock movement (movement_type = 'in', reference_type = 'return')
        $res = record_stock_movement($pdo, [
            'item_id'          => $itemId,
            'movement_type'    => 'in',
            'quantity'         => $quantity,
            'reference_type'   => 'return',
            'reference_number' => $returnNumber,
            'performed_by'     => $userId,
            'notes'            => "Stock Return from Dept #{$departmentId}: {$reason}"
        ]);

        log_audit_action($pdo, 'STOCK_RETURNED', 'items', $itemId, [
            'return_number' => $returnNumber,
            'department_id' => $departmentId,
            'quantity'      => $quantity,
            'reason'        => $reason,
            'balance_after' => $res['balance_after']
        ]);

        if ($isLocalTx) {
            $pdo->commit();
        }

        return ['success' => true, 'balance_after' => $res['balance_after'], 'return_number' => $returnNumber];
    } catch (Exception $e) {
        if ($isLocalTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Stock return error: ' . $e->getMessage());
        throw $e;
    }
}
