<?php
/**
 * Inventory Management & Stock Calculations Helper
 * St. Jude High School Inventory Management System
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

/**
 * Standard Units of Measure for School Supplies
 * 
 * @return array
 */
function get_standard_units(): array {
    return [
        'Pcs'        => 'Pieces (Pcs)',
        'Box'        => 'Box',
        'Carton'     => 'Carton',
        'Dozen'      => 'Dozen (12 pcs)',
        'Ream'       => 'Ream (500 sheets)',
        'Bottle'     => 'Bottle',
        'Tin'        => 'Tin / Can',
        'Bag (90kg)' => 'Bag (90kg grain)',
        'Bag (50kg)' => 'Bag (50kg flour/sugar)',
        'Kg'         => 'Kilograms (Kg)',
        'Liter'      => 'Liters (L)',
        'Roll'       => 'Roll',
        'Set'        => 'Set / Kit',
        'Packet'     => 'Packet',
        'Pair'       => 'Pair'
    ];
}

/**
 * Record a stock movement atomically and update item stock balance
 * All stock-changing operations use database transactions per business rules.
 * 
 * @param PDO $pdo
 * @param array $data [
 *   'item_id'          => int,
 *   'movement_type'    => 'in'|'out'|'adjustment',
 *   'quantity'         => float,
 *   'reference_type'   => 'grn'|'issue'|'adjustment'|'return'|'damage',
 *   'reference_number' => string,
 *   'reference_id'     => int|null,
 *   'location_id'      => int|null,
 *   'unit_cost'        => float|null,
 *   'performed_by'     => int,
 *   'notes'            => string|null
 * ]
 * @return array [success => bool, balance_after => float, movement_id => int, message => string]
 * @throws Exception
 */
function record_stock_movement(PDO $pdo, array $data): array {
    $itemId = (int)($data['item_id'] ?? 0);
    $movementType = strtolower(trim($data['movement_type'] ?? ''));
    $qty = (float)($data['quantity'] ?? 0.0);
    $refType = strtolower(trim($data['reference_type'] ?? 'adjustment'));
    $refNumber = trim($data['reference_number'] ?? 'ADJ-' . strtoupper(bin2hex(random_bytes(4))));
    $refId = isset($data['reference_id']) ? (int)$data['reference_id'] : 0;
    $performedBy = (int)($data['performed_by'] ?? ($_SESSION['user_id'] ?? 1));
    $notes = $data['notes'] ?? null;

    if ($itemId <= 0) {
        throw new InvalidArgumentException('Invalid item ID for stock movement.');
    }

    if ($qty <= 0 && $movementType !== 'adjustment') {
        throw new InvalidArgumentException('Movement quantity must be greater than zero.');
    }

    if (!in_array($movementType, ['in', 'out', 'adjustment'], true)) {
        throw new InvalidArgumentException("Invalid movement type: {$movementType}");
    }

    if (!in_array($refType, ['grn', 'issue', 'adjustment', 'return', 'damage'], true)) {
        throw new InvalidArgumentException("Invalid reference type: {$refType}");
    }

    $isLocalTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $isLocalTransaction = true;
    }

    try {
        // Lock row with FOR UPDATE to prevent race conditions & duplicate movements
        $lockStmt = $pdo->prepare("
            SELECT id, name, unit_cost, quantity_on_hand, location_id, is_asset 
            FROM `items` 
            WHERE id = ? 
            FOR UPDATE
        ");
        $lockStmt->execute([$itemId]);
        $item = $lockStmt->fetch();

        if (!$item) {
            throw new RuntimeException("Item #{$itemId} not found in inventory.");
        }

        $currentStock = (float)$item['quantity_on_hand'];
        $unitCost = isset($data['unit_cost']) ? (float)$data['unit_cost'] : (float)$item['unit_cost'];
        $locationId = isset($data['location_id']) ? (int)$data['location_id'] : ($item['location_id'] ? (int)$item['location_id'] : null);

        // Calculate new balance
        if ($movementType === 'in') {
            $newBalance = $currentStock + $qty;
        } elseif ($movementType === 'out') {
            if ($currentStock < $qty) {
                throw new UnderflowException("Insufficient stock on hand for item '{$item['name']}'. Available: {$currentStock}, requested: {$qty}.");
            }
            $newBalance = $currentStock - $qty;
        } else {
            // Adjustment: positive qty adds, negative qty subtracts, or exact balance set
            if (isset($data['is_absolute_set']) && $data['is_absolute_set']) {
                $newBalance = max(0.0, $qty);
                $diff = $newBalance - $currentStock;
                $qty = abs($diff);
                $movementType = ($diff >= 0) ? 'in' : 'out';
            } else {
                $newBalance = max(0.0, $currentStock + $qty);
            }
        }

        // 1. Update item stock on hand
        $updateStmt = $pdo->prepare("
            UPDATE `items` 
            SET `quantity_on_hand` = ?, `updated_at` = NOW() 
            WHERE `id` = ?
        ");
        $updateStmt->execute([$newBalance, $itemId]);

        // 2. Insert into stock_movements ledger
        $moveStmt = $pdo->prepare("
            INSERT INTO `stock_movements` (
                `reference_type`, `reference_id`, `reference_number`,
                `item_id`, `location_id`, `movement_type`,
                `quantity`, `unit_cost`, `balance_after`,
                `performed_by`, `notes`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $moveStmt->execute([
            $refType,
            $refId,
            $refNumber,
            $itemId,
            $locationId,
            $movementType,
            $qty,
            $unitCost,
            $newBalance,
            $performedBy,
            $notes
        ]);
        $movementId = (int)$pdo->lastInsertId();

        // 3. Record Audit Log
        log_audit_action(
            $pdo,
            'STOCK_MOVEMENT',
            'items',
            $itemId,
            [
                'movement_id'   => $movementId,
                'movement_type' => $movementType,
                'reference'     => $refNumber,
                'quantity'      => $qty,
                'prev_balance'  => $currentStock,
                'balance_after' => $newBalance
            ]
        );

        if ($isLocalTransaction) {
            $pdo->commit();
        }

        return [
            'success'       => true,
            'balance_after' => $newBalance,
            'movement_id'   => $movementId,
            'message'       => "Stock movement recorded successfully. New balance: {$newBalance}."
        ];
    } catch (Exception $e) {
        if ($isLocalTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Stock Movement Error: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Generate Next Item Code (e.g. ITM-0001 or CAT-based)
 * 
 * @param PDO $pdo
 * @param string $prefix
 * @return string
 */
function generate_item_code(PDO $pdo, string $prefix = 'ITM'): string {
    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', $prefix)) ?: 'ITM';
    $like = $prefix . '-%';
    
    $stmt = $pdo->prepare("SELECT item_code FROM `items` WHERE item_code LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$like]);
    $lastCode = $stmt->fetchColumn();

    if ($lastCode && preg_match('/-(\d+)$/', $lastCode, $matches)) {
        $nextNum = (int)$matches[1] + 1;
    } else {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM `items`");
        $nextNum = ((int)$countStmt->fetchColumn()) + 1;
    }

    return sprintf('%s-%04d', $prefix, $nextNum);
}
