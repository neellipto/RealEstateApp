<?php
require_once 'includes/layout.php';
requireLogin();

$errors = [];
$poId = (int)($_GET['po_id'] ?? 0);

$purchaseOrders = dbFetchAll(
    "SELECT po.id, po.po_number, s.name AS supplier_name, po.pi_value, po.currency FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id ORDER BY po.created_at DESC LIMIT 100"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'calculate') {
        $summaryData = [
            'po_id'            => (int)($_POST['po_id'] ?? 0),
            'calculation_date' => sanitize($_POST['calc_date'] ?? date('Y-m-d')),
            'customs_duty'     => (float)($_POST['customs_duty'] ?? 0),
            'shipping_cost'    => (float)($_POST['shipping_cost'] ?? 0),
            'cnf_cost'         => (float)($_POST['cnf_cost'] ?? 0),
            'transport_cost'   => (float)($_POST['transport_cost'] ?? 0),
            'labour_cost'      => (float)($_POST['labour_cost'] ?? 0),
            'warehouse_cost'   => (float)($_POST['warehouse_cost'] ?? 0),
            'bank_charge'      => (float)($_POST['bank_charge'] ?? 0),
            'lc_charge'        => (float)($_POST['lc_charge'] ?? 0),
            'amendment_charge' => (float)($_POST['amendment_charge'] ?? 0),
            'container_charge' => (float)($_POST['container_charge'] ?? 0),
            'other_cost'       => (float)($_POST['other_cost'] ?? 0),
            'allocation_method'=> in_array($_POST['allocation_method']??'value',['value','weight','quantity','cbm','manual']) ? $_POST['allocation_method'] : 'value',
            'exchange_rate'    => (float)($_POST['exchange_rate'] ?? 110),
            'notes'            => sanitize($_POST['notes'] ?? ''),
            'created_by'       => userId(),
        ];

        $summaryData['total_cost'] = $summaryData['customs_duty'] + $summaryData['shipping_cost'] + $summaryData['cnf_cost'] + $summaryData['transport_cost'] + $summaryData['labour_cost'] + $summaryData['warehouse_cost'] + $summaryData['bank_charge'] + $summaryData['lc_charge'] + $summaryData['amendment_charge'] + $summaryData['container_charge'] + $summaryData['other_cost'];

        $errors = validateRequired($summaryData, ['po_id']);

        if (!$errors) {
            // Save summary
            $existingSum = dbFetch('SELECT id FROM landed_cost_summary WHERE po_id=?', [$summaryData['po_id']]);
            if ($existingSum) {
                dbUpdate('landed_cost_summary', $summaryData, ['id' => $existingSum['id']]);
                $sumId = $existingSum['id'];
                dbDelete('landed_cost_items', ['summary_id' => $sumId]);
            } else {
                $sumId = dbInsert('landed_cost_summary', $summaryData);
            }

            // Save items
            $items      = $_POST['items'] ?? [];
            $method     = $summaryData['allocation_method'];
            $totalValue = 0;
            foreach ($items as $item) {
                $totalValue += (float)($item[$method === 'value' ? 'purchase_value' : ($method === 'weight' ? 'weight' : ($method === 'quantity' ? 'quantity' : 'cbm'))] ?? $item['purchase_value'] ?? 0);
            }

            foreach ($items as $item) {
                $pv  = (float)($item['purchase_value'] ?? 0);
                $qty = (float)($item['quantity'] ?? 1);
                $metricVal = (float)($item[$method === 'value' ? 'purchase_value' : $method] ?? $pv);
                $alloc = $totalValue > 0 ? ($metricVal / $totalValue) * $summaryData['total_cost'] : 0;
                $landed = $pv + $alloc;
                $perUnit = $qty > 0 ? $landed / $qty : $landed;
                $suggestedPrice = $perUnit * 1.25; // 25% markup default

                dbInsert('landed_cost_items', [
                    'summary_id'      => $sumId,
                    'product_id'      => (int)($item['product_id'] ?? 0) ?: null,
                    'description'     => sanitize($item['description'] ?? ''),
                    'quantity'        => $qty,
                    'unit'            => sanitize($item['unit'] ?? 'pcs'),
                    'purchase_value'  => $pv,
                    'allocated_cost'  => round($alloc, 2),
                    'landed_cost'     => round($landed, 2),
                    'per_unit_cost'   => round($perUnit, 4),
                    'suggested_price' => round($suggestedPrice, 2),
                    'profit_amount'   => round($suggestedPrice - $perUnit, 2),
                    'profit_percent'  => $perUnit > 0 ? round(($suggestedPrice - $perUnit) / $perUnit * 100, 2) : 0,
                ]);

                // Update product landed cost if product_id specified
                if (!empty($item['product_id'])) {
                    dbUpdate('products', ['landed_cost' => $perUnit, 'purchase_price' => $perUnit], ['id' => (int)$item['product_id']]);
                }
            }

            logActivity('create', 'landed_cost', $sumId, "Calculated landed cost for PO");
            redirect("landed-cost.php?view=$sumId", 'Landed cost calculated and saved.');
        }
    }
}

// View existing
$viewId = (int)($_GET['view'] ?? 0);
$viewSummary = $viewId ? dbFetch("SELECT lcs.*, po.po_number, s.name AS supplier_name FROM landed_cost_summary lcs JOIN purchase_orders po ON po.id=lcs.po_id JOIN suppliers s ON s.id=po.supplier_id WHERE lcs.id=?", [$viewId]) : null;
$viewItems = $viewId ? dbFetchAll("SELECT lci.*, p.name AS product_name FROM landed_cost_items lci LEFT JOIN products p ON p.id=lci.product_id WHERE lci.summary_id=?", [$viewId]) : [];

// Previous calculations
$calculations = dbFetchAll(
    "SELECT lcs.*, po.po_number, s.name AS supplier_name FROM landed_cost_summary lcs JOIN purchase_orders po ON po.id=lcs.po_id JOIN suppliers s ON s.id=po.supplier_id ORDER BY lcs.created_at DESC LIMIT 50"
);

// Load PO items for pre-fill
$selectedPO = null;
$poItems = [];
if ($poId) {
    $selectedPO = dbFetch("SELECT po.*, s.name AS supplier_name FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id WHERE po.id=?", [$poId]);
    $poItems = dbFetchAll("SELECT poi.*, p.name AS product_name FROM purchase_order_items poi LEFT JOIN products p ON p.id=poi.product_id WHERE poi.po_id=?", [$poId]);
}

$products = getProducts();

pageStart('Landed Cost Calculator');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-calculator me-2"></i>Landed Cost Calculator</h1>
</div>

<div class="row g-4">
  <!-- Calculator Form -->
  <div class="col-lg-7">
    <form method="POST" id="lcForm">
      <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
      <input type="hidden" name="action" value="calculate">

      <div class="cj-card mb-4">
        <div class="card-header"><span class="card-title"><i class="fas fa-file-invoice me-2"></i>Purchase Order Info</span></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Purchase Order <span class="text-danger">*</span></label>
              <select name="po_id" class="form-select" required>
                <option value="">-- Select PO --</option>
                <?php foreach ($purchaseOrders as $po): ?>
                <option value="<?= $po['id'] ?>" <?= $po['id']==$poId?'selected':'' ?>><?= h($po['po_number']) ?> — <?= h($po['supplier_name']) ?> (<?= h($po['currency']) ?> <?= number_format($po['pi_value'],2) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label">Calc Date</label><input type="date" name="calc_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-3"><label class="form-label">Exchange Rate</label><input type="number" name="exchange_rate" id="exchange_rate" class="form-control" value="110" step="0.01"></div>
            <div class="col-md-4">
              <label class="form-label">Allocation Method</label>
              <select name="allocation_method" id="allocation_method" class="form-select" onchange="updateAllocation()">
                <option value="value">By Purchase Value</option>
                <option value="weight">By Weight (kg)</option>
                <option value="quantity">By Quantity</option>
                <option value="cbm">By CBM</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="cj-card mb-4">
        <div class="card-header"><span class="card-title"><i class="fas fa-coins me-2"></i>Cost Inputs (all in BDT ৳)</span></div>
        <div class="card-body">
          <div class="row g-3">
            <?php $costFields = ['customs_duty'=>'Customs Duty','shipping_cost'=>'Shipping Cost','cnf_cost'=>'CNF Cost','transport_cost'=>'Transport Cost','labour_cost'=>'Labour Cost','warehouse_cost'=>'Warehouse Cost','bank_charge'=>'Bank Charge','lc_charge'=>'LC Charge','amendment_charge'=>'Amendment Charge','container_charge'=>'Container Charge','other_cost'=>'Other Cost']; ?>
            <?php foreach ($costFields as $field => $label): ?>
            <div class="col-md-4">
              <label class="form-label"><?= $label ?> (৳)</label>
              <input type="number" name="<?= $field ?>" id="<?= $field ?>" class="form-control cost-input" step="0.01" min="0" value="0" oninput="calcTotal()">
            </div>
            <?php endforeach; ?>
            <div class="col-12">
              <div class="p-3 bg-sky rounded d-flex justify-content-between align-items-center">
                <span class="fw-bold">Total Landed Cost:</span>
                <span class="fw-bold text-primary" style="font-size:20px" id="total_display">৳0.00</span>
                <input type="hidden" name="total_cost" id="total_cost" value="0">
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Product Items -->
      <div class="cj-card mb-4">
        <div class="card-header">
          <span class="card-title"><i class="fas fa-boxes-stacked me-2"></i>Product Items</span>
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="addItem()"><i class="fas fa-plus me-1"></i>Add Item</button>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table landed-cost-table mb-0" id="itemsTable">
              <thead>
                <tr>
                  <th>Product</th><th>Desc</th><th>Qty</th><th>Unit</th>
                  <th>Buy Value (৳)</th><th>Weight(kg)</th><th>CBM</th>
                  <th>Alloc. Cost</th><th>Per Unit</th><th></th>
                </tr>
              </thead>
              <tbody id="itemsBody">
                <?php if ($poItems): ?>
                <?php foreach ($poItems as $i => $item): ?>
                <tr class="lc-item-row" id="item_row_<?= $i ?>">
                  <td><select name="items[<?= $i ?>][product_id]" class="form-select form-select-sm">
                    <option value="">—</option>
                    <?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>" <?= $p['id']==$item['product_id']?'selected':'' ?>><?= h($p['name']) ?></option><?php endforeach; ?>
                  </select></td>
                  <td><input type="text" name="items[<?= $i ?>][description]" class="form-control form-control-sm" value="<?= h($item['description']) ?>"></td>
                  <td><input type="number" name="items[<?= $i ?>][quantity]" data-field="quantity" class="form-control form-control-sm" step="0.001" value="<?= $item['quantity'] ?>" oninput="recalcRow(<?= $i ?>)"></td>
                  <td><input type="text" name="items[<?= $i ?>][unit]" data-field="unit" class="form-control form-control-sm" value="<?= h($item['unit']) ?>" style="width:60px"></td>
                  <td><input type="number" name="items[<?= $i ?>][purchase_value]" data-field="purchase_value" class="form-control form-control-sm" step="0.01" value="<?= $item['unit_price']*$item['quantity'] ?>" oninput="updateAllocation()"></td>
                  <td><input type="number" name="items[<?= $i ?>][weight]" data-field="weight" class="form-control form-control-sm" step="0.01" value="<?= $item['weight_kg'] ?>" oninput="updateAllocation()"></td>
                  <td><input type="number" name="items[<?= $i ?>][cbm]" data-field="cbm" class="form-control form-control-sm" step="0.0001" value="<?= $item['cbm'] ?>" oninput="updateAllocation()"></td>
                  <td><input type="number" name="items[<?= $i ?>][allocated_cost]" data-field="allocated_cost" class="form-control form-control-sm bg-light" readonly></td>
                  <td><input type="number" name="items[<?= $i ?>][per_unit_cost]" data-field="per_unit_cost" class="form-control form-control-sm bg-light" readonly></td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="fas fa-trash"></i></button></td>
                </tr>
                <?php endforeach; ?>
                <?php else: ?>
                <!-- Empty row to start -->
                <tr class="lc-item-row" id="item_row_0">
                  <td><select name="items[0][product_id]" class="form-select form-select-sm"><option value="">—</option><?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></td>
                  <td><input type="text" name="items[0][description]" class="form-control form-control-sm" placeholder="Description"></td>
                  <td><input type="number" name="items[0][quantity]" data-field="quantity" class="form-control form-control-sm" step="0.001" value="1" oninput="updateAllocation()"></td>
                  <td><input type="text" name="items[0][unit]" data-field="unit" class="form-control form-control-sm" value="pcs" style="width:60px"></td>
                  <td><input type="number" name="items[0][purchase_value]" data-field="purchase_value" class="form-control form-control-sm" step="0.01" value="0" oninput="updateAllocation()"></td>
                  <td><input type="number" name="items[0][weight]" data-field="weight" class="form-control form-control-sm" step="0.01" value="0" oninput="updateAllocation()"></td>
                  <td><input type="number" name="items[0][cbm]" data-field="cbm" class="form-control form-control-sm" step="0.0001" value="0" oninput="updateAllocation()"></td>
                  <td><input type="number" name="items[0][allocated_cost]" data-field="allocated_cost" class="form-control form-control-sm bg-light" readonly></td>
                  <td><input type="number" name="items[0][per_unit_cost]" data-field="per_unit_cost" class="form-control form-control-sm bg-light" readonly></td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="fas fa-trash"></i></button></td>
                </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="cj-card mb-4">
        <div class="card-body">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="2"></textarea>
        </div>
      </div>

      <button type="submit" class="btn btn-primary btn-lg w-100"><i class="fas fa-calculator me-2"></i>Calculate & Save Landed Cost</button>
    </form>
  </div>

  <!-- Right: History & View -->
  <div class="col-lg-5">
    <!-- If viewing a calculation -->
    <?php if ($viewSummary && $viewItems): ?>
    <div class="cj-card mb-4">
      <div class="card-header">
        <span class="card-title">Landed Cost Result — <?= h($viewSummary['po_number']) ?></span>
        <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print"></i></button>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Product</th><th>Qty</th><th>Per Unit Cost</th><th>Suggested Price</th><th>Profit%</th></tr></thead>
          <tbody>
            <?php foreach ($viewItems as $vi): ?>
            <tr>
              <td><?= h($vi['product_name'] ?: $vi['description']) ?></td>
              <td><?= $vi['quantity'] ?> <?= h($vi['unit']) ?></td>
              <td class="fw-600"><?= money($vi['per_unit_cost']) ?></td>
              <td class="text-success"><?= money($vi['suggested_price']) ?></td>
              <td><?= $vi['profit_percent'] ?>%</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="p-3 border-top">
          <div class="d-flex justify-content-between"><span>Total Cost Added:</span><strong><?= money($viewSummary['total_cost']) ?></strong></div>
          <div class="d-flex justify-content-between"><span>Exchange Rate:</span><strong><?= $viewSummary['exchange_rate'] ?></strong></div>
          <div class="d-flex justify-content-between"><span>Allocation Method:</span><strong><?= ucfirst($viewSummary['allocation_method']) ?></strong></div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- History -->
    <div class="cj-card">
      <div class="card-header"><span class="card-title">Previous Calculations</span></div>
      <div class="list-group list-group-flush">
        <?php foreach ($calculations as $calc): ?>
        <a href="?view=<?= $calc['id'] ?>" class="list-group-item list-group-item-action py-2 px-3 <?= $viewId===$calc['id']?'active':'' ?>">
          <div class="d-flex justify-content-between">
            <span class="fw-600"><?= h($calc['po_number']) ?></span>
            <span class="badge bg-primary"><?= money($calc['total_cost']) ?></span>
          </div>
          <small><?= h($calc['supplier_name']) ?> · <?= formatDate($calc['calculation_date']) ?></small>
        </a>
        <?php endforeach; ?>
        <?php if (!$calculations): ?>
        <div class="list-group-item text-muted text-center py-3">No calculations yet.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
let itemCount = <?= max(count($poItems), 1) ?>;

function calcTotal() {
  let total = 0;
  document.querySelectorAll('.cost-input').forEach(el => total += parseFloat(el.value||0));
  document.getElementById('total_display').textContent = '৳' + total.toLocaleString('en-BD', {minimumFractionDigits:2});
  document.getElementById('total_cost').value = total;
  updateAllocation();
}

function updateAllocation() {
  const method = document.getElementById('allocation_method').value;
  const totalCost = parseFloat(document.getElementById('total_cost').value || 0);
  const rows = document.querySelectorAll('.lc-item-row');

  let totalMetric = 0;
  rows.forEach(row => {
    const field = method === 'value' ? 'purchase_value' : method;
    totalMetric += parseFloat(row.querySelector(`[data-field="${field}"]`)?.value || 0);
  });

  rows.forEach(row => {
    const field = method === 'value' ? 'purchase_value' : method;
    const metricVal = parseFloat(row.querySelector(`[data-field="${field}"]`)?.value || 0);
    const alloc = totalMetric > 0 ? (metricVal / totalMetric) * totalCost : 0;
    const pv = parseFloat(row.querySelector('[data-field="purchase_value"]')?.value || 0);
    const qty = parseFloat(row.querySelector('[data-field="quantity"]')?.value || 1);
    const landed = pv + alloc;
    const perUnit = qty > 0 ? landed / qty : 0;
    const allocEl = row.querySelector('[data-field="allocated_cost"]');
    const perEl = row.querySelector('[data-field="per_unit_cost"]');
    if (allocEl) allocEl.value = alloc.toFixed(2);
    if (perEl) perEl.value = perUnit.toFixed(4);
  });
}

function addItem() {
  const tbody = document.getElementById('itemsBody');
  const i = itemCount++;
  const row = document.createElement('tr');
  row.className = 'lc-item-row';
  row.id = 'item_row_' + i;
  row.innerHTML = `
    <td><select name="items[${i}][product_id]" class="form-select form-select-sm"><option value="">—</option><?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></td>
    <td><input type="text" name="items[${i}][description]" class="form-control form-control-sm" placeholder="Description"></td>
    <td><input type="number" name="items[${i}][quantity]" data-field="quantity" class="form-control form-control-sm" step="0.001" value="1" oninput="updateAllocation()"></td>
    <td><input type="text" name="items[${i}][unit]" data-field="unit" class="form-control form-control-sm" value="pcs" style="width:60px"></td>
    <td><input type="number" name="items[${i}][purchase_value]" data-field="purchase_value" class="form-control form-control-sm" step="0.01" value="0" oninput="updateAllocation()"></td>
    <td><input type="number" name="items[${i}][weight]" data-field="weight" class="form-control form-control-sm" step="0.01" value="0" oninput="updateAllocation()"></td>
    <td><input type="number" name="items[${i}][cbm]" data-field="cbm" class="form-control form-control-sm" step="0.0001" value="0" oninput="updateAllocation()"></td>
    <td><input type="number" name="items[${i}][allocated_cost]" data-field="allocated_cost" class="form-control form-control-sm bg-light" readonly></td>
    <td><input type="number" name="items[${i}][per_unit_cost]" data-field="per_unit_cost" class="form-control form-control-sm bg-light" readonly></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); updateAllocation()"><i class="fas fa-trash"></i></button></td>
  `;
  tbody.appendChild(row);
}
</script>

<?php pageEnd(); ?>
