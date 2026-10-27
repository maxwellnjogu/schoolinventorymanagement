<?php
/**
 * Stock Adjustment Controller
 * St. Jude High School Inventory Management System
 * Atomic stock reconciliation with immutable movement records
 */

$pageTitle = 'Stock Adjustment';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory_helpers.php';

require_role(['admin', 'storekeeper']);

$user = current_user();
$pdo = Database::getConnection();

if (!$pdo) {
    die("Database connection error: " . Database::getError());
}

$selectedItemId = (int)($_GET['item_id'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $itemId = (int)($_POST['item_id'] ?? 0);
    $adjMode = trim($_POST['adjustment_mode'] ?? 'add'); // 'add', 'subtract', or 'set_exact'
    $quantity = (float)($_POST['quantity'] ?? 0.0);
    $reason = trim($_POST['reason'] ?? '');

    if ($itemId <= 0) {
        $errors[] = 'Please select a valid inventory item.';
    }

    if ($quantity <= 0 && $adjMode !== 'set_exact') {
        $errors[] = 'Quantity must be greater than zero.';
    } elseif ($quantity < 0 && $adjMode === 'set_exact') {
        $errors[] = 'Physical count cannot be negative.';
    }

    if (empty($reason)) {
        $errors[] = 'Please provide an audit reason or note for this stock adjustment.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Fetch item with row lock
            $lockStmt = $pdo->prepare("
                SELECT id, name, item_code, quantity_on_hand, unit_cost, location_id, unit_of_measure
                FROM `items`
                WHERE id = ?
                FOR UPDATE
            ");
            $lockStmt->execute([$itemId]);
            $item = $lockStmt->fetch();

            if (!$item) {
                throw new RuntimeException("Selected item not found.");
            }

            $currentStock = (float)$item['quantity_on_hand'];
            $refNumber = 'ADJ-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            if ($adjMode === 'add') {
                $movementType = 'in';
                $movementQty = $quantity;
            } elseif ($adjMode === 'subtract') {
                $movementType = 'out';
                $movementQty = $quantity;
                if ($currentStock < $quantity) {
                    throw new UnderflowException("Cannot subtract {$quantity} {$item['unit_of_measure']} from current balance of {$currentStock} {$item['unit_of_measure']}.");
                }
            } else {
                // Exact physical count
                $diff = $quantity - $currentStock;
                $movementType = ($diff >= 0) ? 'in' : 'out';
                $movementQty = abs($diff);
            }

            // Record movement
            $res = record_stock_movement($pdo, [
                'item_id'          => $itemId,
                'movement_type'    => $movementType,
                'quantity'         => $movementQty,
                'reference_type'   => 'adjustment',
                'reference_number' => $refNumber,
                'reference_id'     => $itemId,
                'location_id'      => $item['location_id'],
                'unit_cost'        => $item['unit_cost'],
                'performed_by'     => $user['id'],
                'notes'            => "Stock Adjustment ({$adjMode}): " . $reason
            ]);

            $pdo->commit();

            set_flash('success', "Stock for '{$item['name']}' adjusted successfully! New balance: " . number_format($res['balance_after'], 1) . " {$item['unit_of_measure']}.");
            redirect('public/item_movements.php?id=' . $itemId);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Stock adjustment error: ' . $e->getMessage());
            $errors[] = $e->getMessage();
        }
    }
}

// Fetch all items for select dropdown
$allItems = $pdo->query("
    SELECT id, item_code, name, quantity_on_hand, unit_of_measure, is_asset
    FROM `items`
    WHERE status = 'active'
    ORDER BY name ASC
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h4 class="fw-bold mb-1"><i class="bi bi-sliders text-warning me-2"></i>Record Stock Adjustment</h4>
                <div class="text-muted small">Perform audited inventory reconciliations, physical count corrections, or damage write-offs.</div>
            </div>
            <a href="<?= e(url('public/items.php')) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to Catalog
            </a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger shadow-sm py-2 px-3 mb-4 small">
                <div class="fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Adjustment could not be processed:</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                        <li><?= e($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card card-custom shadow-sm">
            <div class="card-header fw-bold bg-light py-3">
                <i class="bi bi-journal-check text-primary me-2"></i>Adjustment Transaction Details
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= e(url('public/stock_adjustment.php')) ?>" novalidate>
                    <?= csrf_input() ?>

                    <!-- Item Selection -->
                    <div class="mb-3">
                        <label for="item_id" class="form-label small fw-semibold text-muted">Select Inventory Item <span class="text-danger">*</span></label>
                        <select class="form-select" id="item_id" name="item_id" required>
                            <option value="">-- Choose Item from Catalog --</option>
                            <?php foreach ($allItems as $it): ?>
                                <option value="<?= (int)$it['id'] ?>" <?= $selectedItemId === (int)$it['id'] ? 'selected' : '' ?> data-stock="<?= (float)$it['quantity_on_hand'] ?>" data-unit="<?= e($it['unit_of_measure']) ?>">
                                    [<?= e($it['item_code']) ?>] <?= e($it['name']) ?> (Current: <?= number_format((float)$it['quantity_on_hand'], 1) ?> <?= e($it['unit_of_measure']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Current stock indicator display -->
                    <div class="alert alert-info py-2 px-3 small d-flex align-items-center justify-content-between mb-4">
                        <span>Current Registered Balance:</span>
                        <strong class="fs-6" id="current_stock_display">Select an item above</strong>
                    </div>

                    <div class="row g-3 mb-3">
                        <!-- Adjustment Mode -->
                        <div class="col-md-6">
                            <label for="adjustment_mode" class="form-label small fw-semibold text-muted">Adjustment Action <span class="text-danger">*</span></label>
                            <select class="form-select" id="adjustment_mode" name="adjustment_mode" required>
                                <option value="add">Add Stock (Found stock / physical surplus)</option>
                                <option value="subtract">Deduct Stock (Deficit / loss / breakage write-off)</option>
                                <option value="set_exact">Set New Exact Physical Count (Stocktake reconciliation)</option>
                            </select>
                        </div>

                        <!-- Quantity -->
                        <div class="col-md-6">
                            <label for="quantity" class="form-label small fw-semibold text-muted" id="qty_label">Adjustment Quantity <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0" class="form-control" id="quantity" name="quantity" required placeholder="Enter quantity">
                        </div>
                    </div>

                    <!-- Reason / Justification -->
                    <div class="mb-4">
                        <label for="reason" class="form-label small fw-semibold text-muted">Audit Justification / Stocktake Notes <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="reason" name="reason" rows="3" required placeholder="Explain why this adjustment is being made (e.g. End of term physical stocktake count, discrepancy reconciliation, damaged during transport)..."></textarea>
                        <div class="form-text small">This note is recorded permanently in the stock movement ledger and system audit trail.</div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?= e(url('public/items.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-warning text-dark fw-semibold d-flex align-items-center gap-2 shadow-sm">
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Commit Stock Adjustment</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const itemSelect = document.getElementById('item_id');
    const stockDisplay = document.getElementById('current_stock_display');
    const modeSelect = document.getElementById('adjustment_mode');
    const qtyLabel = document.getElementById('qty_label');

    function updateStockDisplay() {
        const selected = itemSelect.options[itemSelect.selectedIndex];
        if (selected && selected.value) {
            const stock = selected.getAttribute('data-stock');
            const unit = selected.getAttribute('data-unit');
            stockDisplay.textContent = parseFloat(stock).toFixed(1) + ' ' + unit;
        } else {
            stockDisplay.textContent = 'Select an item above';
        }
    }

    function updateLabel() {
        if (modeSelect.value === 'set_exact') {
            qtyLabel.textContent = 'New Exact Physical Count *';
        } else if (modeSelect.value === 'subtract') {
            qtyLabel.textContent = 'Quantity to Deduct *';
        } else {
            qtyLabel.textContent = 'Quantity to Add *';
        }
    }

    if (itemSelect) {
        itemSelect.addEventListener('change', updateStockDisplay);
        updateStockDisplay();
    }

    if (modeSelect) {
        modeSelect.addEventListener('change', updateLabel);
        updateLabel();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
