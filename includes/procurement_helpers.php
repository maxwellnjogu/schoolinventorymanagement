<?php
/**
 * Procurement & Goods Receiving Helpers
 * St. Jude High School Inventory Management System
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/inventory_helpers.php';

/**
 * Generate Next Unique Purchase Order Number (e.g. PO-2026-0001)
 * 
 * @param PDO $pdo
 * @return string
 */
function generate_po_number(PDO $pdo): string {
    $year = date('Y');
    $prefix = "PO-{$year}-";
    $stmt = $pdo->prepare("SELECT po_number FROM `purchase_orders` WHERE po_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    if ($last && preg_match('/-(\d+)$/', $last, $matches)) {
        $next = (int)$matches[1] + 1;
    } else {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM `purchase_orders`");
        $next = ((int)$countStmt->fetchColumn()) + 1;
    }

    return sprintf('%s%04d', $prefix, $next);
}

/**
 * Generate Next Unique Goods Received Note Number (e.g. GRN-2026-0001)
 * 
 * @param PDO $pdo
 * @return string
 */
function generate_grn_number(PDO $pdo): string {
    $year = date('Y');
    $prefix = "GRN-{$year}-";
    $stmt = $pdo->prepare("SELECT grn_number FROM `goods_received_notes` WHERE grn_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    if ($last && preg_match('/-(\d+)$/', $last, $matches)) {
        $next = (int)$matches[1] + 1;
    } else {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM `goods_received_notes`");
        $next = ((int)$countStmt->fetchColumn()) + 1;
    }

    return sprintf('%s%04d', $prefix, $next);
}

/**
 * Create Purchase Order with Line Items
 * 
 * @param PDO $pdo
 * @param array $poData [supplier_id, order_date, expected_date, notes]
 * @param array $items [[item_id, quantity_ordered, unit_price]]
 * @param int $userId
 * @return array [success => bool, po_id => int, po_number => string]
 * @throws Exception
 */
function create_purchase_order(PDO $pdo, array $poData, array $items, int $userId): array {
    $supplierId = (int)($poData['supplier_id'] ?? 0);
    $orderDate = $poData['order_date'] ?? date('Y-m-d');
    $expectedDate = !empty($poData['expected_date']) ? $poData['expected_date'] : null;
    $notes = $poData['notes'] ?? null;
    $status = in_array($poData['status'] ?? '', ['draft', 'ordered'], true) ? $poData['status'] : 'ordered';

    if ($supplierId <= 0) {
        throw new InvalidArgumentException('Please select an approved supplier.');
    }

    if (empty($items)) {
        throw new InvalidArgumentException('A purchase order must contain at least one line item.');
    }

    $isLocalTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $isLocalTx = true;
    }

    try {
        $poNumber = generate_po_number($pdo);
        $totalAmount = 0.0;

        // Calculate total amount
        foreach ($items as $line) {
            $qty = (float)($line['quantity_ordered'] ?? 0.0);
            $price = (float)($line['unit_price'] ?? 0.0);
            if ($qty <= 0) {
                throw new InvalidArgumentException("Quantity ordered must be greater than zero.");
            }
            if ($price < 0) {
                throw new InvalidArgumentException("Unit price cannot be negative.");
            }
            $totalAmount += ($qty * $price);
        }

        // Insert PO header
        $poStmt = $pdo->prepare("
            INSERT INTO `purchase_orders` (
                `po_number`, `supplier_id`, `order_date`, `expected_date`,
                `status`, `total_amount`, `notes`, `created_by`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $poStmt->execute([
            $poNumber,
            $supplierId,
            $orderDate,
            $expectedDate,
            $status,
            $totalAmount,
            $notes,
            $userId
        ]);
        $poId = (int)$pdo->lastInsertId();

        // Insert PO line items
        $itemStmt = $pdo->prepare("
            INSERT INTO `purchase_order_items` (
                `po_id`, `item_id`, `quantity_ordered`, `unit_price`,
                `total_price`, `quantity_received`
            ) VALUES (?, ?, ?, ?, ?, 0.00)
        ");

        foreach ($items as $line) {
            $itemId = (int)$line['item_id'];
            $qty = (float)$line['quantity_ordered'];
            $price = (float)$line['unit_price'];
            $lineTotal = $qty * $price;

            $itemStmt->execute([$poId, $itemId, $qty, $price, $lineTotal]);
        }

        // Audit log
        log_audit_action($pdo, 'PO_CREATED', 'purchase_orders', $poId, [
            'po_number'    => $poNumber,
            'supplier_id'  => $supplierId,
            'total_amount' => $totalAmount,
            'items_count'  => count($items),
            'status'       => $status
        ]);

        if ($isLocalTx) {
            $pdo->commit();
        }

        return ['success' => true, 'po_id' => $poId, 'po_number' => $poNumber];
    } catch (Exception $e) {
        if ($isLocalTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Create PO error: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Receive Goods via Goods Received Note (GRN)
 * Stock increases ONLY upon creation of GRN per business rules.
 * Supports partial and complete deliveries.
 * 
 * @param PDO $pdo
 * @param int $poId
 * @param array $grnData [delivery_note_number, received_date, remarks]
 * @param array $receivedLines [[po_item_id, item_id, quantity_received, unit_cost, remarks]]
 * @param int $userId
 * @return array [success => bool, grn_id => int, grn_number => string, po_status => string]
 * @throws Exception
 */
function receive_goods_grn(PDO $pdo, int $poId, array $grnData, array $receivedLines, int $userId): array {
    $deliveryNote = trim($grnData['delivery_note_number'] ?? '');
    $receivedDate = $grnData['received_date'] ?? date('Y-m-d');
    $remarks = $grnData['remarks'] ?? null;

    if ($poId <= 0) {
        throw new InvalidArgumentException('Valid Purchase Order required for goods receiving.');
    }

    $isLocalTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $isLocalTx = true;
    }

    try {
        // Lock PO record
        $poLock = $pdo->prepare("SELECT * FROM `purchase_orders` WHERE id = ? FOR UPDATE");
        $poLock->execute([$poId]);
        $po = $poLock->fetch();

        if (!$po) {
            throw new RuntimeException("Purchase order #{$poId} not found.");
        }

        if (in_array($po['status'], ['draft', 'cancelled', 'received'], true)) {
            throw new InvalidArgumentException("Cannot receive goods on PO in '{$po['status']}' status.");
        }

        $grnNumber = generate_grn_number($pdo);

        // Insert GRN header
        $grnStmt = $pdo->prepare("
            INSERT INTO `goods_received_notes` (
                `grn_number`, `po_id`, `supplier_id`, `delivery_note_number`,
                `received_date`, `received_by`, `remarks`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $grnStmt->execute([
            $grnNumber,
            $poId,
            $po['supplier_id'],
            $deliveryNote ?: null,
            $receivedDate,
            $userId,
            $remarks
        ]);
        $grnId = (int)$pdo->lastInsertId();

        $griStmt = $pdo->prepare("
            INSERT INTO `goods_received_items` (
                `grn_id`, `po_item_id`, `item_id`, `quantity_received`,
                `unit_cost`, `total_cost`, `remarks`
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $updPoItem = $pdo->prepare("
            UPDATE `purchase_order_items`
            SET `quantity_received` = `quantity_received` + ?
            WHERE `id` = ? AND `po_id` = ?
        ");

        $hasReceivedAny = false;

        foreach ($receivedLines as $line) {
            $poItemId = (int)($line['po_item_id'] ?? 0);
            $itemId = (int)($line['item_id'] ?? 0);
            $recvQty = (float)($line['quantity_received'] ?? 0.0);
            $unitCost = (float)($line['unit_cost'] ?? 0.0);
            $lineRemarks = $line['remarks'] ?? null;

            if ($recvQty <= 0) {
                continue; // Skip lines with 0 received in this partial batch
            }

            // Lock PO item row to verify outstanding balance
            $poiLock = $pdo->prepare("SELECT * FROM `purchase_order_items` WHERE id = ? AND po_id = ? FOR UPDATE");
            $poiLock->execute([$poItemId, $poId]);
            $poi = $poiLock->fetch();

            if (!$poi) {
                throw new RuntimeException("PO Line item #{$poItemId} not found on this order.");
            }

            $ordered = (float)$poi['quantity_ordered'];
            $alreadyReceived = (float)$poi['quantity_received'];
            $outstanding = max(0.0, $ordered - $alreadyReceived);

            if ($recvQty > $outstanding) {
                throw new OverflowException("Quantity received ({$recvQty}) exceeds outstanding quantity ({$outstanding}) for item #{$itemId}.");
            }

            if ($unitCost <= 0) {
                $unitCost = (float)$poi['unit_price'];
            }
            $lineTotal = $recvQty * $unitCost;

            // 1. Insert GRN line item
            $griStmt->execute([$grnId, $poItemId, $itemId, $recvQty, $unitCost, $lineTotal, $lineRemarks]);

            // 2. Update PO line item received quantity
            $updPoItem->execute([$recvQty, $poItemId, $poId]);

            // 3. Stock increases ONLY when goods are received: record atomic stock movement
            record_stock_movement($pdo, [
                'item_id'          => $itemId,
                'movement_type'    => 'in',
                'quantity'         => $recvQty,
                'reference_type'   => 'grn',
                'reference_number' => $grnNumber,
                'reference_id'     => $grnId,
                'unit_cost'        => $unitCost,
                'performed_by'     => $userId,
                'notes'            => "Goods Received Note from PO {$po['po_number']} (Del Note: {$deliveryNote})"
            ]);

            // Also update item unit cost in catalog if newly supplied cost
            if ($unitCost > 0) {
                $pdo->prepare("UPDATE `items` SET `unit_cost` = ? WHERE `id` = ?")->execute([$unitCost, $itemId]);
            }

            $hasReceivedAny = true;
        }

        if (!$hasReceivedAny) {
            throw new InvalidArgumentException("No quantities were entered for receiving on this delivery.");
        }

        // Determine new status for the Purchase Order
        $checkAllStmt = $pdo->prepare("
            SELECT SUM(quantity_ordered) AS total_ordered, SUM(quantity_received) AS total_received
            FROM `purchase_order_items`
            WHERE po_id = ?
        ");
        $checkAllStmt->execute([$poId]);
        $poTotals = $checkAllStmt->fetch();

        $totOrdered = (float)($poTotals['total_ordered'] ?? 0.0);
        $totReceived = (float)($poTotals['total_received'] ?? 0.0);

        if ($totReceived >= $totOrdered) {
            $newPoStatus = 'received';
        } else {
            $newPoStatus = 'partially_received';
        }

        $pdo->prepare("UPDATE `purchase_orders` SET `status` = ?, `updated_at` = NOW() WHERE `id` = ?")
            ->execute([$newPoStatus, $poId]);

        // Audit log
        log_audit_action($pdo, 'GRN_CREATED', 'goods_received_notes', $grnId, [
            'grn_number'   => $grnNumber,
            'po_number'    => $po['po_number'],
            'new_status'   => $newPoStatus,
            'tot_received' => $totReceived,
            'tot_ordered'  => $totOrdered
        ]);

        if ($isLocalTx) {
            $pdo->commit();
        }

        return [
            'success'     => true,
            'grn_id'      => $grnId,
            'grn_number'  => $grnNumber,
            'po_status'   => $newPoStatus
        ];
    } catch (Exception $e) {
        if ($isLocalTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('GRN error: ' . $e->getMessage());
        throw $e;
    }
}
