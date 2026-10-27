<?php
/**
 * Create Purchase Order
 * St. Jude High School Inventory Management System
 */

$pageTitle = 'Create Purchase Order';

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

$errors = [];

// Fetch active suppliers
$suppliersStmt = $pdo->query("SELECT id, supplier_code, company_name, contact_person, phone FROM `suppliers` WHERE status = 'active' ORDER BY company_name ASC");
$suppliers = $suppliersStmt->fetchAll();

// Fetch items for line items
$itemsStmt = $pdo->query("SELECT id, item_code, name, unit_of_measure, unit_cost, quantity_on_hand FROM `items` ORDER BY name ASC");
$items = $itemsStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Security token invalid or expired. Please submit the form again.';
    } else {
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $orderDate = trim($_POST['order_date'] ?? date('Y-m-d'));
        $expectedDate = !empty($_POST['expected_date']) ? trim($_POST['expected_date']) : null;
        $status = in_array($_POST['status'] ?? '', ['draft', 'ordered'], true) ? $_POST['status'] : 'ordered';
        $notes = trim($_POST['notes'] ?? '');

        $itemIds = $_POST['item_id'] ?? [];
        $quantities = $_POST['quantity_ordered'] ?? [];
        $unitPrices = $_POST['unit_price'] ?? [];

        $poItems = [];
        if (is_array($itemIds) && count($itemIds) > 0) {
            for ($i = 0; $i < count($itemIds); $i++) {
                $itemId = (int)($itemIds[$i] ?? 0);
                $qty = (float)($quantities[$i] ?? 0);
                $price = (float)($unitPrices[$i] ?? 0);

                if ($itemId > 0 && $qty > 0) {
                    $poItems[] = [
                        'item_id' => $itemId,
                        'quantity_ordered' => $qty,
                        'unit_price' => $price
                    ];
                }
            }
        }

        if ($supplierId <= 0) {
            $errors[] = 'Please select a registered supplier.';
        }
        if (empty($orderDate)) {
            $errors[] = 'Order date is required.';
        }
        if (empty($poItems)) {
            $errors[] = 'Please add at least one line item with a quantity greater than zero.';
        }

        if (empty($errors)) {
            try {
                $result = create_purchase_order($pdo, [
                    'supplier_id'   => $supplierId,
                    'order_date'    => $orderDate,
                    'expected_date' => $expectedDate,
                    'status'        => $status,
                    'notes'         => $notes
                ], $poItems, (int)$user['id']);

                flash_set('success', "Purchase Order {$result['po_number']} created successfully.");
                redirect(url('public/po_view.php?id=' . $result['po_id']));
            } catch (Exception $e) {
                $errors[] = 'Error saving Purchase Order: ' . $e->getMessage();
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
                <li class="breadcrumb-item"><a href="<?= e(url('public/purchase_orders.php')) ?>" class="text-decoration-none">Purchase Orders</a></li>
                <li class="breadcrumb-item active">New Order</li>
            </ol>
        </nav>
        <h2 class="h3 fw-bold mb-0">Create Purchase Order</h2>
        <p class="text-muted small mb-0">Procure inventory supplies with real-time Kenyan Shillings (KSh) line calculations.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <a href="<?= e(url('public/purchase_orders.php')) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back to Orders
        </a>
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

<form action="<?= e(url('public/po_create.php')) ?>" method="POST" id="poForm">
    <?= csrf_input() ?>

    <div class="row g-4">
        <!-- Order Header Details -->
        <div class="col-lg-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="card-title mb-0 fw-bold text-primary">
                        <i class="bi bi-info-circle me-2"></i>Order Information
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="supplier_id" class="form-label fw-semibold">Supplier <span class="text-danger">*</span></label>
                            <select name="supplier_id" id="supplier_id" class="form-select" required>
                                <option value="">-- Select Approved Supplier --</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>" <?= (isset($_POST['supplier_id']) && (int)$_POST['supplier_id'] === (int)$s['id']) ? 'selected' : '' ?>>
                                        <?= e($s['company_name']) ?> (<?= e($s['supplier_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label for="order_date" class="form-label fw-semibold">Order Date <span class="text-danger">*</span></label>
                            <input type="date" name="order_date" id="order_date" class="form-control" 
                                   value="<?= e($_POST['order_date'] ?? date('Y-m-d')) ?>" required>
                        </div>

                        <div class="col-md-3">
                            <label for="expected_date" class="form-label fw-semibold">Expected Delivery Date</label>
                            <input type="date" name="expected_date" id="expected_date" class="form-control" 
                                   value="<?= e($_POST['expected_date'] ?? '') ?>">
                        </div>

                        <div class="col-md-2">
                            <label for="status" class="form-label fw-semibold">Initial Status</label>
                            <select name="status" id="status" class="form-select">
                                <option value="ordered" <?= (($_POST['status'] ?? 'ordered') === 'ordered') ? 'selected' : '' ?>>Ordered (Confirmed)</option>
                                <option value="draft" <?= (($_POST['status'] ?? '') === 'draft') ? 'selected' : '' ?>>Draft</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label for="notes" class="form-label fw-semibold">Order Instructions / Delivery Terms</label>
                            <textarea name="notes" id="notes" rows="2" class="form-control" 
                                      placeholder="e.g. Delivery required at Main Store between 8am and 3pm. Inspection upon arrival."><?= e($_POST['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Line Items Section -->
        <div class="col-lg-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0 fw-bold text-primary">
                        <i class="bi bi-list-check me-2"></i>Order Line Items
                    </h5>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddRow">
                        <i class="bi bi-plus-circle me-1"></i> Add Item Row
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="itemsTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 40%;">Item Description</th>
                                    <th style="width: 15%;">Unit of Measure</th>
                                    <th style="width: 15%;">Quantity</th>
                                    <th style="width: 15%;">Unit Price (KSh)</th>
                                    <th style="width: 15%;" class="text-end">Subtotal (KSh)</th>
                                    <th style="width: 50px;" class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody id="poItemsBody">
                                <!-- Dynamic rows injected here -->
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td colspan="4" class="text-end">Total Order Valuation:</td>
                                    <td class="text-end text-success fs-5" id="grandTotalText">KSh 0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white py-3 text-muted small border-top">
                    <i class="bi bi-shield-lock-fill text-primary me-1"></i> <strong>Business Rule:</strong> Stock will <em>only</em> increase when physical goods are officially received and entered under a Goods Received Note (GRN).
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="col-12 text-end">
            <a href="<?= e(url('public/purchase_orders.php')) ?>" class="btn btn-outline-secondary me-2">Cancel</a>
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-check-circle me-1"></i> Save Purchase Order
            </button>
        </div>
    </div>
</form>

<template id="rowTemplate">
    <tr class="item-row">
        <td>
            <select name="item_id[]" class="form-select item-select" required>
                <option value="">-- Choose Item --</option>
                <?php foreach ($items as $it): ?>
                    <option value="<?= (int)$it['id'] ?>" 
                            data-price="<?= (float)$it['unit_cost'] ?>" 
                            data-uom="<?= e($it['unit_of_measure']) ?>"
                            data-stock="<?= (float)$it['quantity_on_hand'] ?>">
                        <?= e($it['name']) ?> (<?= e($it['item_code']) ?>) [Stock: <?= (float)$it['quantity_on_hand'] ?>]
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="text" class="form-control bg-light uom-display" readonly value="-">
        </td>
        <td>
            <input type="number" step="0.01" min="0.01" name="quantity_ordered[]" class="form-control qty-input" placeholder="0.00" required>
        </td>
        <td>
            <input type="number" step="0.01" min="0.00" name="unit_price[]" class="form-control price-input" placeholder="0.00" required>
        </td>
        <td class="text-end fw-semibold subtotal-display">
            0.00
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-outline-danger btn-sm btn-remove-row" title="Remove item">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>
</template>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tbody = document.getElementById('poItemsBody');
    const template = document.getElementById('rowTemplate');
    const btnAddRow = document.getElementById('btnAddRow');
    const grandTotalText = document.getElementById('grandTotalText');

    function calculateRow(row) {
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const price = parseFloat(row.querySelector('.price-input').value) || 0;
        const subtotal = qty * price;
        row.querySelector('.subtotal-display').textContent = subtotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        calculateTotal();
    }

    function calculateTotal() {
        let total = 0;
        tbody.querySelectorAll('.item-row').forEach(row => {
            const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
            const price = parseFloat(row.querySelector('.price-input').value) || 0;
            total += (qty * price);
        });
        grandTotalText.textContent = 'KSh ' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function addRow() {
        const clone = template.content.cloneNode(true);
        const row = clone.querySelector('.item-row');
        
        const select = row.querySelector('.item-select');
        const uomDisplay = row.querySelector('.uom-display');
        const priceInput = row.querySelector('.price-input');
        const qtyInput = row.querySelector('.qty-input');
        const btnRemove = row.querySelector('.btn-remove-row');

        select.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            if (opt && opt.value) {
                uomDisplay.value = opt.getAttribute('data-uom') || '-';
                if (!priceInput.value || parseFloat(priceInput.value) === 0) {
                    priceInput.value = opt.getAttribute('data-price') || '0.00';
                }
            } else {
                uomDisplay.value = '-';
            }
            calculateRow(row);
        });

        qtyInput.addEventListener('input', () => calculateRow(row));
        priceInput.addEventListener('input', () => calculateRow(row));

        btnRemove.addEventListener('click', function() {
            if (tbody.querySelectorAll('.item-row').length > 1) {
                row.remove();
                calculateTotal();
            } else {
                alert('A purchase order must have at least one line item.');
            }
        });

        tbody.appendChild(clone);
    }

    btnAddRow.addEventListener('click', addRow);

    // Initial first row
    addRow();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
