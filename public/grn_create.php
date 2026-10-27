<?php
/**
 * Goods Receiving Note (GRN) Creation
 * Physical stock entry with partial delivery support
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Receive Goods (GRN)';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/procurement_helpers.php';

require_role(['admin', 'storekeeper']);

$user = current_user();
$pdo = Database::getConnection();

if (!$pdo) {
    die("Database connection error: " . Database::getError());
}

$poId = (int)($_GET['po_id'] ?? $_POST['po_id'] ?? 0);
$errors = [];

// Fetch eligible POs for receiving
$eligiblePosStmt = $pdo->query("
    SELECT po.id, po.po_number, po.order_date, s.company_name
    FROM `purchase_orders` po
    JOIN `suppliers` s ON po.supplier_id = s.id
    WHERE po.status IN ('ordered', 'partially_received')
    ORDER BY po.id DESC
");
$eligiblePos = $eligiblePosStmt->fetchAll();

$selectedPo = null;
$poItems = [];

if ($poId > 0) {
    $poStmt = $pdo->prepare("
        SELECT po.*, s.company_name, s.supplier_code
        FROM `purchase_orders` po
        JOIN `suppliers` s ON po.supplier_id = s.id
        WHERE po.id = ?
    ");
    $poStmt->execute([$poId]);
    $selectedPo = $poStmt->fetch();

    if ($selectedPo) {
        $itemsStmt = $pdo->prepare("
            SELECT poi.*, i.name AS item_name, i.item_code, i.unit_of_measure, i.quantity_on_hand
            FROM `purchase_order_items` poi
            JOIN `items` i ON poi.item_id = i.id
            WHERE poi.po_id = ?
            ORDER BY poi.id ASC
        ");
        $itemsStmt->execute([$poId]);
        $poItems = $itemsStmt->fetchAll();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Security token invalid or expired. Please submit the form again.';
    } elseif (!$selectedPo) {
        $errors[] = 'Please select a valid purchase order to receive goods for.';
    } elseif (!in_array($selectedPo['status'], ['ordered', 'partially_received'], true)) {
        $errors[] = "Cannot receive goods on PO in '{$selectedPo['status']}' status.";
    } else {
        $deliveryNote = trim($_POST['delivery_note_number'] ?? '');
        $receivedDate = trim($_POST['received_date'] ?? date('Y-m-d'));
        $remarks = trim($_POST['remarks'] ?? '');

        $poItemIds = $_POST['po_item_id'] ?? [];
        $itemIds = $_POST['item_id'] ?? [];
        $recvQuantities = $_POST['quantity_received'] ?? [];
        $unitCosts = $_POST['unit_cost'] ?? [];
        $lineRemarks = $_POST['line_remarks'] ?? [];

        $receivedLines = [];
        $hasAnyQty = false;

        if (is_array($poItemIds) && count($poItemIds) > 0) {
            for ($i = 0; $i < count($poItemIds); $i++) {
                $poiId = (int)($poItemIds[$i] ?? 0);
                $itemId = (int)($itemIds[$i] ?? 0);
                $recvQty = (float)($recvQuantities[$i] ?? 0.0);
                $unitCost = (float)($unitCosts[$i] ?? 0.0);
                $lRemark = trim($lineRemarks[$i] ?? '');

                if ($recvQty > 0) {
                    $hasAnyQty = true;
                    $receivedLines[] = [
                        'po_item_id'        => $poiId,
                        'item_id'           => $itemId,
                        'quantity_received' => $recvQty,
                        'unit_cost'         => $unitCost,
                        'remarks'           => $lRemark
                    ];
                }
            }
        }

        if (!$hasAnyQty) {
            $errors[] = 'Please specify a received quantity greater than 0 for at least one line item.';
        }

        if (empty($errors)) {
            try {
                $res = receive_goods_grn($pdo, $poId, [
                    'delivery_note_number' => $deliveryNote,
                    'received_date'        => $receivedDate,
                    'remarks'              => $remarks
                ], $receivedLines, (int)$user['id']);

                flash_set('success', "Goods Received Note {$res['grn_number']} recorded successfully! Inventory has been updated. PO Status: " . ucwords(str_replace('_', ' ', $res['po_status'])) . ".");
                redirect(url('public/grn_view.php?id=' . $res['grn_id']));
            } catch (Exception $e) {
                $errors[] = 'Error processing GRN: ' . $e->getMessage();
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4 align-items-center">
    <div class="col-md-7">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= e(url('public/dashboard.php')) ?>" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= e(url('public/grns.php')) ?>" class="text-decoration-none">Goods Received Notes</a></li>
                <li class="breadcrumb-item active">New Delivery (GRN)</li>
            </ol>
        </nav>
        <h2 class="h3 fw-bold mb-0">Record Goods Received Note (GRN)</h2>
        <p class="text-muted small mb-0">Receive physical stock into stores. Supports partial shipments, delivery note tracking, and inventory updates.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <?php if ($selectedPo): ?>
            <a href="<?= e(url('public/po_view.php?id=' . $selectedPo['id'])) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to PO
            </a>
        <?php else: ?>
            <a href="<?= e(url('public/grns.php')) ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back to GRNs
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill me-2"></i>Please correct the following:</div>
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Step 1: Select Purchase Order if not selected -->
<?php if (!$selectedPo): ?>
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="card-title mb-0 fw-bold text-primary">
                <i class="bi bi-cart-check me-2"></i>Select Purchase Order to Receive
            </h5>
        </div>
        <div class="card-body">
            <form method="GET" action="<?= e(url('public/grn_create.php')) ?>" class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label for="select_po" class="form-label fw-semibold">Outstanding Purchase Orders</label>
                    <select name="po_id" id="select_po" class="form-select" required>
                        <option value="">-- Choose Purchase Order --</option>
                        <?php foreach ($eligiblePos as $epo): ?>
                            <option value="<?= (int)$epo['id'] ?>">
                                <?= e($epo['po_number']) ?> &mdash; <?= e($epo['company_name']) ?> (Date: <?= e(date('d M Y', strtotime($epo['order_date']))) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-arrow-right-circle me-1"></i> Load Order Details
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>

    <form action="<?= e(url('public/grn_create.php?po_id=' . $selectedPo['id'])) ?>" method="POST" id="grnForm">
        <?= csrf_input() ?>
        <input type="hidden" name="po_id" value="<?= (int)$selectedPo['id'] ?>">

        <!-- Header Information -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0 fw-bold text-primary">
                    <i class="bi bi-truck me-2"></i>Delivery & Dispatch Information
                </h5>
                <span class="badge bg-light text-dark border">
                    Order: <strong><?= e($selectedPo['po_number']) ?></strong> &bull; <?= e($selectedPo['company_name']) ?>
                </span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="delivery_note_number" class="form-label fw-semibold">Delivery Note / Invoice # <span class="text-danger">*</span></label>
                        <input type="text" name="delivery_note_number" id="delivery_note_number" class="form-control" 
                               placeholder="e.g. DN-84920 or INV-10293" 
                               value="<?= e($_POST['delivery_note_number'] ?? '') ?>" required>
                    </div>

                    <div class="col-md-4">
                        <label for="received_date" class="form-label fw-semibold">Physical Date Received <span class="text-danger">*</span></label>
                        <input type="date" name="received_date" id="received_date" class="form-control" 
                               value="<?= e($_POST['received_date'] ?? date('Y-m-d')) ?>" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Receiving Officer</label>
                        <input type="text" class="form-control bg-light" readonly value="<?= e($user['full_name']) ?> (Storekeeper)">
                    </div>

                    <div class="col-12">
                        <label for="remarks" class="form-label fw-semibold">General Delivery Remarks / Condition</label>
                        <textarea name="remarks" id="remarks" rows="2" class="form-control" 
                                  placeholder="e.g. Delivered by driver Patrick. All seals intact; partial delivery of 30 reams paper."><?= e($_POST['remarks'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Line Items Receiving Table -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="card-title mb-0 fw-bold text-primary">
                    <i class="bi bi-list-check me-2"></i>Verify Delivered Quantities
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 25%;">Item Description</th>
                                <th style="width: 10%;" class="text-center">Ordered</th>
                                <th style="width: 10%;" class="text-center">Prev Recv</th>
                                <th style="width: 10%;" class="text-center text-danger">Outstanding</th>
                                <th style="width: 15%;" class="text-center">Now Receiving <span class="text-danger">*</span></th>
                                <th style="width: 15%;" class="text-end">Unit Cost (KSh)</th>
                                <th style="width: 15%;">Remarks / Batch</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($poItems as $i => $item): 
                                $ordered = (float)$item['quantity_ordered'];
                                $received = (float)$item['quantity_received'];
                                $outstanding = max(0.0, $ordered - $received);
                                $isFullyReceived = ($outstanding <= 0);
                            ?>
                                <tr class="<?= $isFullyReceived ? 'table-light text-muted' : '' ?>">
                                    <td>
                                        <div class="fw-semibold"><?= e($item['item_name']) ?></div>
                                        <span class="badge bg-light text-dark font-monospace"><?= e($item['item_code']) ?></span>
                                        <span class="text-muted small ms-1">(<?= e($item['unit_of_measure']) ?>)</span>
                                        <input type="hidden" name="po_item_id[]" value="<?= (int)$item['id'] ?>">
                                        <input type="hidden" name="item_id[]" value="<?= (int)$item['item_id'] ?>">
                                    </td>
                                    <td class="text-center fw-semibold"><?= number_format($ordered, 1) ?></td>
                                    <td class="text-center text-success"><?= number_format($received, 1) ?></td>
                                    <td class="text-center fw-bold <?= ($outstanding > 0) ? 'text-danger' : 'text-muted' ?>">
                                        <?= number_format($outstanding, 1) ?>
                                    </td>
                                    <td>
                                        <?php if ($isFullyReceived): ?>
                                            <div class="text-center text-success small fw-semibold">
                                                <i class="bi bi-check-circle-fill me-1"></i> Complete
                                            </div>
                                            <input type="hidden" name="quantity_received[]" value="0">
                                        <?php else: ?>
                                            <div class="input-group">
                                                <input type="number" step="0.01" min="0" max="<?= $outstanding ?>" 
                                                       name="quantity_received[]" 
                                                       class="form-control text-center fw-bold recv-input" 
                                                       placeholder="0.00" 
                                                       value="<?= (isset($_POST['quantity_received'][$i])) ? e($_POST['quantity_received'][$i]) : $outstanding ?>" 
                                                       required>
                                                <button type="button" class="btn btn-outline-secondary btn-sm btn-fill-all" 
                                                        data-max="<?= $outstanding ?>" title="Fill outstanding amount">
                                                    All
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0" name="unit_cost[]" 
                                               class="form-control text-end" 
                                               value="<?= (isset($_POST['unit_cost'][$i])) ? e($_POST['unit_cost'][$i]) : (float)$item['unit_price'] ?>" 
                                               <?= $isFullyReceived ? 'readonly' : '' ?>>
                                    </td>
                                    <td>
                                        <input type="text" name="line_remarks[]" class="form-control form-control-sm" 
                                               placeholder="e.g. Batch #1" 
                                               value="<?= e($_POST['line_remarks'][$i] ?? '') ?>"
                                               <?= $isFullyReceived ? 'readonly' : '' ?>>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white py-3 border-top d-flex justify-content-between align-items-center">
                <div class="text-muted small">
                    <i class="bi bi-shield-check text-success me-1"></i> <strong>Real-time Stock Updates:</strong> Entering quantities here will atomically increment the physical store balance and post stock ledger entries.
                </div>
                <div>
                    <a href="<?= e(url('public/po_view.php?id=' . $selectedPo['id'])) ?>" class="btn btn-outline-secondary me-2">Cancel</a>
                    <button type="submit" class="btn btn-success px-4" onclick="return confirm('Confirm receipt of these goods? This will update inventory balances immediately.');">
                        <i class="bi bi-check-circle me-1"></i> Confirm & Receive Stock
                    </button>
                </div>
            </div>
        </div>
    </form>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.btn-fill-all').forEach(btn => {
            btn.addEventListener('click', function() {
                const maxVal = this.getAttribute('data-max');
                const input = this.parentElement.querySelector('.recv-input');
                if (input) {
                    input.value = maxVal;
                }
            });
        });
    });
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
