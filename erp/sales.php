<?php
require_once 'includes/layout.php';
requireLogin();

$customers = getCustomers();
$products  = getProducts();
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $data = [
            'customer_id'   => (int)($_POST['customer_id'] ?? 0),
            'date'          => sanitize($_POST['date'] ?? date('Y-m-d')),
            'delivery_date' => sanitize($_POST['delivery_date'] ?? '') ?: null,
            'subtotal'      => (float)($_POST['subtotal'] ?? 0),
            'discount'      => (float)($_POST['discount'] ?? 0),
            'tax'           => (float)($_POST['tax'] ?? 0),
            'total'         => (float)($_POST['total'] ?? 0),
            'paid_amount'   => (float)($_POST['paid_amount'] ?? 0),
            'payment_mode'  => sanitize($_POST['payment_mode'] ?? 'cash'),
            'notes'         => sanitize($_POST['notes'] ?? ''),
            'status'        => 'confirmed',
        ];
        $data['due_amount']     = $data['total'] - $data['paid_amount'];
        $data['payment_status'] = $data['paid_amount'] >= $data['total'] ? 'paid' : ($data['paid_amount'] > 0 ? 'partial' : 'unpaid');

        $errors = validateRequired($data, ['customer_id','total']);
        if (!$errors) {
            $data['order_number'] = generateCode('SO', 'sales_orders', 'order_number', 6);
            $data['created_by']   = userId();
            $oid = dbInsert('sales_orders', $data);

            // Save items
            $items = $_POST['items'] ?? [];
            foreach ($items as $item) {
                if (empty($item['description'])) continue;
                $qty   = (float)($item['quantity'] ?? 0);
                $price = (float)($item['unit_price'] ?? 0);
                dbInsert('sales_order_items', [
                    'order_id'   => $oid,
                    'product_id' => (int)($item['product_id'] ?? 0) ?: null,
                    'description'=> sanitize($item['description']),
                    'quantity'   => $qty,
                    'unit_price' => $price,
                    'discount'   => (float)($item['discount'] ?? 0),
                    'total'      => $qty * $price,
                ]);
                if (!empty($item['product_id']) && $qty > 0) {
                    updateStock((int)$item['product_id'], $qty, 'out', 'sales_order', $oid, $price);
                }
            }

            // Auto-generate invoice
            $invData = [
                'invoice_number' => generateCode('INV','invoices','invoice_number',6),
                'customer_id'    => $data['customer_id'],
                'order_id'       => $oid,
                'date'           => $data['date'],
                'subtotal'       => $data['subtotal'],
                'discount'       => $data['discount'],
                'tax'            => $data['tax'],
                'total'          => $data['total'],
                'paid_amount'    => $data['paid_amount'],
                'due_amount'     => $data['due_amount'],
                'status'         => $data['payment_status'] === 'paid' ? 'paid' : ($data['paid_amount'] > 0 ? 'partial' : 'sent'),
                'payment_mode'   => $data['payment_mode'],
                'created_by'     => userId(),
            ];
            $invId = dbInsert('invoices', $invData);

            // Ledger
            $order = dbFetch('SELECT order_number FROM sales_orders WHERE id=?', [$oid]);
            addCustomerLedger($data['customer_id'], $data['date'], 'debit', "Sales Order {$order['order_number']}", $data['total'], 'sales_order', $oid);
            if ($data['paid_amount'] > 0) {
                addCustomerLedger($data['customer_id'], $data['date'], 'credit', "Payment SO {$order['order_number']}", $data['paid_amount'], 'sales_order', $oid);
                addDailyLedger($data['date'], 'income', 'Sales', "Sales Order {$order['order_number']}", $data['paid_amount'], $data['payment_mode'], 'sales_order', $oid);
            }

            logActivity('create', 'sales_orders', $oid, "Created SO {$order['order_number']}");
            redirect('sales.php', "Sales order {$order['order_number']} created. Invoice {$invData['invoice_number']} generated.");
        }
    }
}

$orders = dbFetchAll(
    "SELECT so.*, c.name AS customer_name FROM sales_orders so JOIN customers c ON c.id=so.customer_id ORDER BY so.created_at DESC LIMIT 200"
);

pageStart('Sales Orders');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-cart-shopping me-2"></i>Sales Orders</h1>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#salesModal"><i class="fas fa-plus me-2"></i>New Sales Order</button>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<div class="cj-card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>Order #</th><th>Customer</th><th>Date</th><th>Total</th><th>Paid</th><th>Due</th><th>Status</th><th>Payment</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($orders as $o): ?>
          <tr>
            <td class="fw-600"><?= h($o['order_number']) ?></td>
            <td><?= h($o['customer_name']) ?></td>
            <td><?= formatDate($o['date']) ?></td>
            <td class="fw-600"><?= money($o['total']) ?></td>
            <td class="text-success"><?= money($o['paid_amount']) ?></td>
            <td class="<?= $o['due_amount']>0?'text-danger fw-600':'' ?>"><?= money($o['due_amount']) ?></td>
            <td><?= statusBadge($o['status']) ?></td>
            <td><?= statusBadge($o['payment_status']) ?></td>
            <td class="table-actions">
              <a href="invoices.php?order_id=<?= $o['id'] ?>" class="btn btn-sm btn-outline-info" title="View Invoice"><i class="fas fa-file-invoice"></i></a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$orders): ?><tr><td colspan="9" class="text-center py-4 text-muted">No orders found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Sales Modal -->
<div class="modal fade" id="salesModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">New Sales Order</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-md-4"><label class="form-label">Customer <span class="text-danger">*</span></label>
              <select name="customer_id" class="form-select" required>
                <option value="">--</option>
                <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?> — <?= h($c['phone']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label">Date</label><input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-3"><label class="form-label">Delivery Date</label><input type="date" name="delivery_date" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">Payment Mode</label>
              <select name="payment_mode" class="form-select"><option value="cash">Cash</option><option value="bank">Bank</option><option value="cheque">Cheque</option></select>
            </div>
          </div>
          <table class="table table-sm" id="soItemsTable">
            <thead><tr><th>Product</th><th>Description</th><th>Qty</th><th>Unit Price</th><th>Total</th><th></th></tr></thead>
            <tbody id="soItemsBody">
              <tr class="so-item-row">
                <td><select name="items[0][product_id]" class="form-select form-select-sm" onchange="soFillProduct(this,0)"><option value="">--</option><?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>" data-price="<?= $p['selling_price'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></td>
                <td><input type="text" name="items[0][description]" id="so_desc_0" class="form-control form-control-sm"></td>
                <td><input type="number" name="items[0][quantity]" id="so_qty_0" class="form-control form-control-sm" value="1" min="0.001" step="0.001" oninput="soCalcRow(0)"></td>
                <td><input type="number" name="items[0][unit_price]" id="so_price_0" class="form-control form-control-sm" value="0" step="0.01" oninput="soCalcRow(0)"></td>
                <td><input type="number" name="items[0][total]" id="so_total_0" class="form-control form-control-sm bg-light" readonly value="0"></td>
                <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove();soCalcTotal()"><i class="fas fa-trash"></i></button></td>
              </tr>
            </tbody>
          </table>
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="soAddRow()"><i class="fas fa-plus me-1"></i>Add Item</button>
          <div class="row mt-3">
            <div class="col-md-6"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
            <div class="col-md-6">
              <table class="table table-sm">
                <tr><td>Subtotal:</td><td><input type="number" name="subtotal" id="so_subtotal" class="form-control form-control-sm bg-light" readonly value="0"></td></tr>
                <tr><td>Discount (৳):</td><td><input type="number" name="discount" id="so_discount" class="form-control form-control-sm" value="0" oninput="soCalcTotal()"></td></tr>
                <tr><td>Tax (৳):</td><td><input type="number" name="tax" id="so_tax" class="form-control form-control-sm" value="0" oninput="soCalcTotal()"></td></tr>
                <tr class="fw-bold"><td>Total:</td><td><input type="number" name="total" id="so_total" class="form-control form-control-sm" readonly value="0"></td></tr>
                <tr><td>Paid (৳):</td><td><input type="number" name="paid_amount" id="so_paid" class="form-control form-control-sm" value="0" step="0.01" oninput="soCalcTotal()"></td></tr>
              </table>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Create Order & Invoice</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
let soRowCount = 1;
function soFillProduct(sel, idx) {
  const opt = sel.options[sel.selectedIndex];
  document.getElementById('so_desc_' + idx).value = opt.text;
  document.getElementById('so_price_' + idx).value = opt.dataset.price || 0;
  soCalcRow(idx);
}
function soCalcRow(idx) {
  const qty   = parseFloat(document.getElementById('so_qty_' + idx)?.value || 0);
  const price = parseFloat(document.getElementById('so_price_' + idx)?.value || 0);
  const el    = document.getElementById('so_total_' + idx);
  if (el) el.value = (qty * price).toFixed(2);
  soCalcTotal();
}
function soCalcTotal() {
  let sub = 0;
  document.querySelectorAll('[id^="so_total_"]').forEach(el => sub += parseFloat(el.value || 0));
  document.getElementById('so_subtotal').value = sub.toFixed(2);
  const disc = parseFloat(document.getElementById('so_discount').value || 0);
  const tax  = parseFloat(document.getElementById('so_tax').value || 0);
  const tot  = sub - disc + tax;
  document.getElementById('so_total').value = tot.toFixed(2);
}
function soAddRow() {
  const i = soRowCount++;
  const row = document.createElement('tr');
  row.className = 'so-item-row';
  row.innerHTML = `<td><select name="items[${i}][product_id]" class="form-select form-select-sm" onchange="soFillProduct(this,${i})"><option value="">--</option><?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>" data-price="<?= $p['selling_price'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></td><td><input type="text" name="items[${i}][description]" id="so_desc_${i}" class="form-control form-control-sm"></td><td><input type="number" name="items[${i}][quantity]" id="so_qty_${i}" class="form-control form-control-sm" value="1" min="0.001" step="0.001" oninput="soCalcRow(${i})"></td><td><input type="number" name="items[${i}][unit_price]" id="so_price_${i}" class="form-control form-control-sm" value="0" step="0.01" oninput="soCalcRow(${i})"></td><td><input type="number" name="items[${i}][total]" id="so_total_${i}" class="form-control form-control-sm bg-light" readonly value="0"></td><td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove();soCalcTotal()"><i class="fas fa-trash"></i></button></td>`;
  document.getElementById('soItemsBody').appendChild(row);
}
</script>

<?php pageEnd(); ?>
