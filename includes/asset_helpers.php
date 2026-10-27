<?php
/**
 * Fixed Asset Register Helpers
 * Individual Asset Lifecycle, Tracking, Transfers, Maintenance, and Disposal
 * St. Jude High School Inventory Management System
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

/**
 * Generate Next Unique Asset Tag / ID (e.g. SJHS/AST/2026/0001)
 *
 * @param PDO $pdo
 * @param string $categoryCode (e.g. ICT, SCI, VEH, FUR, AST)
 * @return string
 */
function generate_asset_tag(PDO $pdo, string $categoryCode = 'AST'): string {
    $year = date('Y');
    $cleanCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', $categoryCode)) ?: 'AST';
    $prefix = "SJHS/{$cleanCode}/{$year}/";

    $stmt = $pdo->prepare("SELECT asset_tag FROM `fixed_assets` WHERE asset_tag LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    if ($last && preg_match('/\/(\d+)$/', $last, $matches)) {
        $next = (int)$matches[1] + 1;
    } else {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM `fixed_assets`");
        $next = ((int)$countStmt->fetchColumn()) + 1;
    }

    return sprintf('%s%04d', $prefix, $next);
}

/**
 * Create a New Individual Fixed Asset
 *
 * @param PDO $pdo
 * @param array $data
 * @param int $userId
 * @return array [success => bool, asset_id => int, asset_tag => string]
 * @throws Exception
 */
function create_fixed_asset(PDO $pdo, array $data, int $userId): array {
    $name = trim($data['name'] ?? '');
    $categoryId = (int)($data['category_id'] ?? 0);
    $departmentId = (int)($data['department_id'] ?? 0);
    $locationId = (int)($data['location_id'] ?? 0);
    $itemId = !empty($data['item_id']) ? (int)$data['item_id'] : null;
    $serialNumber = trim($data['serial_number'] ?? '') ?: null;
    $model = trim($data['model'] ?? '') ?: null;
    $manufacturer = trim($data['manufacturer'] ?? '') ?: null;
    $purchaseDate = trim($data['purchase_date'] ?? date('Y-m-d'));
    $purchaseCost = (float)($data['purchase_cost'] ?? 0.0);
    $conditionStatus = in_array($data['condition_status'] ?? '', ['new', 'good', 'fair', 'damaged', 'disposed'], true) 
        ? $data['condition_status'] : 'good';
    $status = in_array($data['status'] ?? '', ['in_use', 'under_maintenance', 'disposed', 'written_off'], true) 
        ? $data['status'] : 'in_use';
    $assignedTo = trim($data['assigned_to'] ?? '') ?: null;
    $notes = trim($data['notes'] ?? '') ?: null;

    if (empty($name)) {
        throw new InvalidArgumentException('Asset name is required.');
    }
    if ($categoryId <= 0) {
        throw new InvalidArgumentException('Please select an asset category.');
    }
    if ($departmentId <= 0) {
        throw new InvalidArgumentException('Please select an assigned department.');
    }
    if ($locationId <= 0) {
        throw new InvalidArgumentException('Please select a storage/deployment location.');
    }
    if ($purchaseCost < 0) {
        throw new InvalidArgumentException('Acquisition cost cannot be negative.');
    }

    // Determine asset tag
    $assetTag = trim($data['asset_tag'] ?? '');
    if (empty($assetTag)) {
        // Fetch category code
        $catStmt = $pdo->prepare("SELECT name FROM `categories` WHERE id = ?");
        $catStmt->execute([$categoryId]);
        $catName = $catStmt->fetchColumn() ?: 'AST';
        $code = 'AST';
        if (stripos($catName, 'computer') !== false || stripos($catName, 'it') !== false) {
            $code = 'ICT';
        } elseif (stripos($catName, 'science') !== false || stripos($catName, 'lab') !== false) {
            $code = 'SCI';
        } elseif (stripos($catName, 'vehicle') !== false || stripos($catName, 'transport') !== false) {
            $code = 'VEH';
        } elseif (stripos($catName, 'furniture') !== false) {
            $code = 'FUR';
        }
        $assetTag = generate_asset_tag($pdo, $code);
    } else {
        // Ensure uniqueness
        $dupStmt = $pdo->prepare("SELECT id FROM `fixed_assets` WHERE asset_tag = ?");
        $dupStmt->execute([$assetTag]);
        if ($dupStmt->fetch()) {
            throw new InvalidArgumentException("Asset tag '{$assetTag}' is already registered to another asset.");
        }
    }

    // Check serial number uniqueness if provided
    if ($serialNumber !== null) {
        $serStmt = $pdo->prepare("SELECT id, asset_tag FROM `fixed_assets` WHERE serial_number = ?");
        $serStmt->execute([$serialNumber]);
        $existing = $serStmt->fetch();
        if ($existing) {
            throw new InvalidArgumentException("Serial number '{$serialNumber}' is already registered under asset tag '{$existing['asset_tag']}'.");
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO `fixed_assets` (
            `asset_tag`, `name`, `category_id`, `department_id`, `location_id`,
            `item_id`, `serial_number`, `model`, `manufacturer`, `purchase_date`,
            `purchase_cost`, `condition_status`, `status`, `assigned_to`, `notes`,
            `created_at`, `updated_at`
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");

    $stmt->execute([
        $assetTag,
        $name,
        $categoryId,
        $departmentId,
        $locationId,
        $itemId,
        $serialNumber,
        $model,
        $manufacturer,
        $purchaseDate,
        $purchaseCost,
        $conditionStatus,
        $status,
        $assignedTo,
        $notes
    ]);

    $assetId = (int)$pdo->lastInsertId();

    log_audit_action($pdo, 'ASSET_CREATED', 'fixed_assets', $assetId, [
        'asset_tag'     => $assetTag,
        'name'          => $name,
        'purchase_cost' => $purchaseCost,
        'department_id' => $departmentId,
        'location_id'   => $locationId
    ]);

    return [
        'success'   => true,
        'asset_id'  => $assetId,
        'asset_tag' => $assetTag
    ];
}

/**
 * Update Fixed Asset Metadata
 *
 * @param PDO $pdo
 * @param int $assetId
 * @param array $data
 * @param int $userId
 * @return bool
 * @throws Exception
 */
function update_fixed_asset(PDO $pdo, int $assetId, array $data, int $userId): bool {
    $name = trim($data['name'] ?? '');
    $categoryId = (int)($data['category_id'] ?? 0);
    $departmentId = (int)($data['department_id'] ?? 0);
    $locationId = (int)($data['location_id'] ?? 0);
    $itemId = !empty($data['item_id']) ? (int)$data['item_id'] : null;
    $serialNumber = trim($data['serial_number'] ?? '') ?: null;
    $model = trim($data['model'] ?? '') ?: null;
    $manufacturer = trim($data['manufacturer'] ?? '') ?: null;
    $purchaseDate = trim($data['purchase_date'] ?? date('Y-m-d'));
    $purchaseCost = (float)($data['purchase_cost'] ?? 0.0);
    $conditionStatus = in_array($data['condition_status'] ?? '', ['new', 'good', 'fair', 'damaged', 'disposed'], true) 
        ? $data['condition_status'] : 'good';
    $status = in_array($data['status'] ?? '', ['in_use', 'under_maintenance', 'disposed', 'written_off'], true) 
        ? $data['status'] : 'in_use';
    $assignedTo = trim($data['assigned_to'] ?? '') ?: null;
    $notes = trim($data['notes'] ?? '') ?: null;

    if ($assetId <= 0) {
        throw new InvalidArgumentException('Invalid asset ID.');
    }
    if (empty($name)) {
        throw new InvalidArgumentException('Asset name is required.');
    }

    // Check serial uniqueness if changing
    if ($serialNumber !== null) {
        $serStmt = $pdo->prepare("SELECT id, asset_tag FROM `fixed_assets` WHERE serial_number = ? AND id != ?");
        $serStmt->execute([$serialNumber, $assetId]);
        $existing = $serStmt->fetch();
        if ($existing) {
            throw new InvalidArgumentException("Serial number '{$serialNumber}' is already registered under asset tag '{$existing['asset_tag']}'.");
        }
    }

    $stmt = $pdo->prepare("
        UPDATE `fixed_assets` SET
            `name` = ?,
            `category_id` = ?,
            `department_id` = ?,
            `location_id` = ?,
            `item_id` = ?,
            `serial_number` = ?,
            `model` = ?,
            `manufacturer` = ?,
            `purchase_date` = ?,
            `purchase_cost` = ?,
            `condition_status` = ?,
            `status` = ?,
            `assigned_to` = ?,
            `notes` = ?,
            `updated_at` = NOW()
        WHERE `id` = ?
    ");

    $stmt->execute([
        $name,
        $categoryId,
        $departmentId,
        $locationId,
        $itemId,
        $serialNumber,
        $model,
        $manufacturer,
        $purchaseDate,
        $purchaseCost,
        $conditionStatus,
        $status,
        $assignedTo,
        $notes,
        $assetId
    ]);

    log_audit_action($pdo, 'ASSET_UPDATED', 'fixed_assets', $assetId, [
        'name'          => $name,
        'department_id' => $departmentId,
        'location_id'   => $locationId,
        'condition'     => $conditionStatus,
        'status'        => $status
    ]);

    return true;
}

/**
 * Transfer Fixed Asset to New Department / Location / Custodian
 *
 * @param PDO $pdo
 * @param int $assetId
 * @param array $transferData [to_department_id, to_location_id, to_assigned_to, transfer_date, reason]
 * @param int $userId
 * @return array [success => bool, transfer_id => int]
 * @throws Exception
 */
function transfer_fixed_asset(PDO $pdo, int $assetId, array $transferData, int $userId): array {
    $toDeptId = (int)($transferData['to_department_id'] ?? 0);
    $toLocId = (int)($transferData['to_location_id'] ?? 0);
    $toAssignedTo = trim($transferData['to_assigned_to'] ?? '') ?: null;
    $transferDate = trim($transferData['transfer_date'] ?? date('Y-m-d'));
    $reason = trim($transferData['reason'] ?? '');

    if ($assetId <= 0) {
        throw new InvalidArgumentException('Invalid asset ID.');
    }
    if ($toDeptId <= 0) {
        throw new InvalidArgumentException('Target department is required.');
    }
    if ($toLocId <= 0) {
        throw new InvalidArgumentException('Target location is required.');
    }
    if (empty($reason)) {
        throw new InvalidArgumentException('Please provide a reason or authorization note for the asset transfer.');
    }

    $isLocalTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $isLocalTx = true;
    }

    try {
        $lockStmt = $pdo->prepare("SELECT * FROM `fixed_assets` WHERE id = ? FOR UPDATE");
        $lockStmt->execute([$assetId]);
        $asset = $lockStmt->fetch();

        if (!$asset) {
            throw new RuntimeException("Asset #{$assetId} not found.");
        }

        if (in_array($asset['status'], ['disposed', 'written_off'], true)) {
            throw new InvalidArgumentException("Cannot transfer asset in '{$asset['status']}' status.");
        }

        $fromDeptId = (int)$asset['department_id'];
        $fromLocId = (int)$asset['location_id'];
        $fromAssignedTo = $asset['assigned_to'];

        // Insert transfer record
        $insStmt = $pdo->prepare("
            INSERT INTO `asset_transfers` (
                `asset_id`, `transfer_date`, `from_department_id`, `to_department_id`,
                `from_location_id`, `to_location_id`, `from_assigned_to`, `to_assigned_to`,
                `transferred_by`, `reason`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $insStmt->execute([
            $assetId,
            $transferDate,
            $fromDeptId,
            $toDeptId,
            $fromLocId,
            $toLocId,
            $fromAssignedTo,
            $toAssignedTo,
            $userId,
            $reason
        ]);
        $transferId = (int)$pdo->lastInsertId();

        // Update fixed asset location and custodian
        $updStmt = $pdo->prepare("
            UPDATE `fixed_assets` SET
                `department_id` = ?,
                `location_id` = ?,
                `assigned_to` = ?,
                `updated_at` = NOW()
            WHERE `id` = ?
        ");
        $updStmt->execute([$toDeptId, $toLocId, $toAssignedTo, $assetId]);

        log_audit_action($pdo, 'ASSET_TRANSFERRED', 'fixed_assets', $assetId, [
            'transfer_id'   => $transferId,
            'from_dept'     => $fromDeptId,
            'to_dept'       => $toDeptId,
            'from_loc'      => $fromLocId,
            'to_loc'        => $toLocId,
            'reason'        => $reason
        ]);

        if ($isLocalTx) {
            $pdo->commit();
        }

        return ['success' => true, 'transfer_id' => $transferId];
    } catch (Exception $e) {
        if ($isLocalTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Record Maintenance / Servicing Log for an Asset
 *
 * @param PDO $pdo
 * @param int $assetId
 * @param array $maintData [maintenance_date, completion_date, maintenance_type, service_provider, cost, description, condition_after, status]
 * @param int $userId
 * @return array [success => bool, maintenance_id => int]
 * @throws Exception
 */
function record_asset_maintenance(PDO $pdo, int $assetId, array $maintData, int $userId): array {
    $maintDate = trim($maintData['maintenance_date'] ?? date('Y-m-d'));
    $compDate = !empty($maintData['completion_date']) ? trim($maintData['completion_date']) : null;
    $type = in_array($maintData['maintenance_type'] ?? '', ['preventive', 'repair', 'servicing', 'inspection', 'calibration'], true)
        ? $maintData['maintenance_type'] : 'servicing';
    $serviceProvider = trim($maintData['service_provider'] ?? '');
    $cost = (float)($maintData['cost'] ?? 0.0);
    $status = in_array($maintData['status'] ?? '', ['scheduled', 'in_progress', 'completed'], true)
        ? $maintData['status'] : 'completed';
    $description = trim($maintData['description'] ?? '');
    $conditionAfter = in_array($maintData['condition_after'] ?? '', ['new', 'good', 'fair', 'damaged'], true)
        ? $maintData['condition_after'] : 'good';

    if ($assetId <= 0) {
        throw new InvalidArgumentException('Invalid asset ID.');
    }
    if (empty($serviceProvider)) {
        throw new InvalidArgumentException('Service provider / technician name is required.');
    }
    if ($cost < 0) {
        throw new InvalidArgumentException('Maintenance cost cannot be negative.');
    }
    if (empty($description)) {
        throw new InvalidArgumentException('Description of maintenance or repair work is required.');
    }

    $isLocalTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $isLocalTx = true;
    }

    try {
        $lockStmt = $pdo->prepare("SELECT * FROM `fixed_assets` WHERE id = ? FOR UPDATE");
        $lockStmt->execute([$assetId]);
        $asset = $lockStmt->fetch();

        if (!$asset) {
            throw new RuntimeException("Asset #{$assetId} not found.");
        }

        $insStmt = $pdo->prepare("
            INSERT INTO `asset_maintenance` (
                `asset_id`, `maintenance_date`, `completion_date`, `maintenance_type`,
                `service_provider`, `cost`, `status`, `description`, `condition_after`,
                `performed_by`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $insStmt->execute([
            $assetId,
            $maintDate,
            $compDate,
            $type,
            $serviceProvider,
            $cost,
            $status,
            $description,
            $conditionAfter,
            $userId
        ]);
        $maintId = (int)$pdo->lastInsertId();

        // Update asset status and condition
        $newOperationalStatus = ($status === 'in_progress') ? 'under_maintenance' : 'in_use';
        $updStmt = $pdo->prepare("
            UPDATE `fixed_assets` SET
                `status` = ?,
                `condition_status` = ?,
                `updated_at` = NOW()
            WHERE `id` = ?
        ");
        $updStmt->execute([$newOperationalStatus, $conditionAfter, $assetId]);

        log_audit_action($pdo, 'ASSET_MAINTENANCE_LOGGED', 'fixed_assets', $assetId, [
            'maintenance_id'   => $maintId,
            'type'             => $type,
            'cost'             => $cost,
            'service_provider' => $serviceProvider,
            'status'           => $status
        ]);

        if ($isLocalTx) {
            $pdo->commit();
        }

        return ['success' => true, 'maintenance_id' => $maintId];
    } catch (Exception $e) {
        if ($isLocalTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Dispose / Write-Off Fixed Asset
 *
 * @param PDO $pdo
 * @param int $assetId
 * @param array $disposalData [disposal_date, disposal_method, proceeds, reason]
 * @param int $userId
 * @return array [success => bool, disposal_id => int]
 * @throws Exception
 */
function dispose_fixed_asset(PDO $pdo, int $assetId, array $disposalData, int $userId): array {
    $disposalDate = trim($disposalData['disposal_date'] ?? date('Y-m-d'));
    $method = in_array($disposalData['disposal_method'] ?? '', ['sold', 'scrapped', 'donated', 'written_off'], true)
        ? $disposalData['disposal_method'] : 'scrapped';
    $proceeds = (float)($disposalData['proceeds'] ?? 0.0);
    $reason = trim($disposalData['reason'] ?? '');

    if ($assetId <= 0) {
        throw new InvalidArgumentException('Invalid asset ID.');
    }
    if ($proceeds < 0) {
        throw new InvalidArgumentException('Disposal proceeds cannot be negative.');
    }
    if (empty($reason)) {
        throw new InvalidArgumentException('Please provide an official disposal reason and Board of Management (BOM) approval notes.');
    }

    $isLocalTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $isLocalTx = true;
    }

    try {
        $lockStmt = $pdo->prepare("SELECT * FROM `fixed_assets` WHERE id = ? FOR UPDATE");
        $lockStmt->execute([$assetId]);
        $asset = $lockStmt->fetch();

        if (!$asset) {
            throw new RuntimeException("Asset #{$assetId} not found.");
        }

        if ($asset['status'] === 'disposed' || $asset['status'] === 'written_off') {
            throw new InvalidArgumentException("Asset is already disposed or written off.");
        }

        $insStmt = $pdo->prepare("
            INSERT INTO `asset_disposals` (
                `asset_id`, `disposal_date`, `disposal_method`, `proceeds`,
                `reason`, `authorized_by`, `created_at`
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $insStmt->execute([
            $assetId,
            $disposalDate,
            $method,
            $proceeds,
            $reason,
            $userId
        ]);
        $disposalId = (int)$pdo->lastInsertId();

        $newStatus = ($method === 'written_off') ? 'written_off' : 'disposed';

        $updStmt = $pdo->prepare("
            UPDATE `fixed_assets` SET
                `status` = ?,
                `condition_status` = 'disposed',
                `updated_at` = NOW()
            WHERE `id` = ?
        ");
        $updStmt->execute([$newStatus, $assetId]);

        log_audit_action($pdo, 'ASSET_DISPOSED', 'fixed_assets', $assetId, [
            'disposal_id' => $disposalId,
            'method'      => $method,
            'proceeds'    => $proceeds,
            'reason'      => $reason
        ]);

        if ($isLocalTx) {
            $pdo->commit();
        }

        return ['success' => true, 'disposal_id' => $disposalId];
    } catch (Exception $e) {
        if ($isLocalTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Get Full Asset Details with Category, Department, Location, and Disposal
 *
 * @param PDO $pdo
 * @param int $assetId
 * @return array|null
 */
function get_asset_details(PDO $pdo, int $assetId): ?array {
    $stmt = $pdo->prepare("
        SELECT fa.*, c.name AS category_name,
               d.name AS department_name, d.code AS department_code,
               l.name AS location_name, l.code AS location_code, l.type AS location_type,
               i.name AS catalog_item_name, i.item_code AS catalog_item_code,
               ad.id AS disposal_id, ad.disposal_date, ad.disposal_method, ad.proceeds,
               ad.reason AS disposal_reason, u_disp.full_name AS authorized_by_name
        FROM `fixed_assets` fa
        JOIN `categories` c ON fa.category_id = c.id
        JOIN `departments` d ON fa.department_id = d.id
        JOIN `locations` l ON fa.location_id = l.id
        LEFT JOIN `items` i ON fa.item_id = i.id
        LEFT JOIN `asset_disposals` ad ON fa.id = ad.asset_id
        LEFT JOIN `users` u_disp ON ad.authorized_by = u_disp.id
        WHERE fa.id = ?
    ");
    $stmt->execute([$assetId]);
    $res = $stmt->fetch();
    return $res ?: null;
}

/**
 * Get Asset Transfer History
 *
 * @param PDO $pdo
 * @param int $assetId
 * @return array
 */
function get_asset_transfers(PDO $pdo, int $assetId): array {
    $stmt = $pdo->prepare("
        SELECT at.*,
               fd.name AS from_dept_name, td.name AS to_dept_name,
               fl.name AS from_loc_name, tl.name AS to_loc_name,
               u.full_name AS transferred_by_name
        FROM `asset_transfers` at
        JOIN `departments` fd ON at.from_department_id = fd.id
        JOIN `departments` td ON at.to_department_id = td.id
        JOIN `locations` fl ON at.from_location_id = fl.id
        JOIN `locations` tl ON at.to_location_id = tl.id
        LEFT JOIN `users` u ON at.transferred_by = u.id
        WHERE at.asset_id = ?
        ORDER BY at.id DESC
    ");
    $stmt->execute([$assetId]);
    return $stmt->fetchAll();
}

/**
 * Get Asset Maintenance History
 *
 * @param PDO $pdo
 * @param int $assetId
 * @return array
 */
function get_asset_maintenance_history(PDO $pdo, int $assetId): array {
    $stmt = $pdo->prepare("
        SELECT am.*, u.full_name AS recorded_by_name
        FROM `asset_maintenance` am
        LEFT JOIN `users` u ON am.performed_by = u.id
        WHERE am.asset_id = ?
        ORDER BY am.id DESC
    ");
    $stmt->execute([$assetId]);
    return $stmt->fetchAll();
}

/**
 * Get Summary Stats for Fixed Assets Dashboard
 *
 * @param PDO $pdo
 * @return array
 */
function get_asset_summary_stats(PDO $pdo): array {
    $stats = $pdo->query("
        SELECT 
            COUNT(*) AS total_assets,
            SUM(CASE WHEN status = 'in_use' THEN 1 ELSE 0 END) AS in_use_count,
            SUM(CASE WHEN status = 'under_maintenance' THEN 1 ELSE 0 END) AS under_maintenance_count,
            SUM(CASE WHEN status IN ('disposed', 'written_off') THEN 1 ELSE 0 END) AS disposed_count,
            SUM(purchase_cost) AS total_acquisition_cost
        FROM `fixed_assets`
    ")->fetch();

    $maintCost = $pdo->query("SELECT SUM(cost) FROM `asset_maintenance`")->fetchColumn();

    return [
        'total_assets'             => (int)($stats['total_assets'] ?? 0),
        'in_use_count'             => (int)($stats['in_use_count'] ?? 0),
        'under_maintenance_count'  => (int)($stats['under_maintenance_count'] ?? 0),
        'disposed_count'           => (int)($stats['disposed_count'] ?? 0),
        'total_acquisition_cost'   => (float)($stats['total_acquisition_cost'] ?? 0.0),
        'total_maintenance_cost'   => (float)($maintCost ?? 0.0)
    ];
}

/**
 * Condition Status Badge CSS class
 */
function asset_condition_badge_class(string $condition): string {
    return match (strtolower($condition)) {
        'new'     => 'bg-success-subtle text-success border border-success',
        'good'    => 'bg-primary-subtle text-primary border border-primary',
        'fair'    => 'bg-warning-subtle text-warning border border-warning',
        'damaged' => 'bg-danger-subtle text-danger border border-danger',
        'disposed'=> 'bg-secondary-subtle text-secondary border border-secondary',
        default   => 'bg-light text-dark'
    };
}

/**
 * Operational Status Badge CSS class
 */
function asset_status_badge_class(string $status): string {
    return match (strtolower($status)) {
        'in_use'            => 'bg-success text-white',
        'under_maintenance' => 'bg-warning text-dark',
        'disposed'          => 'bg-secondary text-white',
        'written_off'       => 'bg-dark text-white',
        default             => 'bg-info text-white'
    };
}
