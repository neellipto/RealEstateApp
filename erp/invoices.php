<?php
require_once 'includes/layout.php';
requireLogin();

$errors    = [];
$customers = getCustomers();
$products  = getProducts();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $data = [
            'customer_id'  => (int)($_POST['customer_id'] ?? 0),
            'date'         => sanitize($_POST['date'] ?? date('Y-m-d')),
            'due_date'     => sanitize($_POST['due_date'] ?? '') ?: null,
            'subtotal'     => (float)($_POST['subtotal'] ?? 0),
            'discount'     => (float)($_POST['discount'] ?? 0),
            'tax'          => (float)($_POST['tax'] ?? 0),
            'total'        => (float)($_POST['total'] ?? 0),
            'paid_amount'  => (float)($_POST['paid_amount'] ?? 0),
            'payment_mode' => sanitize($_POST['payment_mode'] ?? 'cash'),
            'notes'        => sanitize($_POST['notes'] ?? ''),
        ];
        $data['due_amount'] = $data['total'] - $data['paid_amount'];
        $data['status']     = $data['paid_amount'] >= $data['total'] ? 'paid' : ($data['paid_amount'] > 0 ? 'partial' : 'sent');
        $errors = validateRequired($data, ['customer_id','total']);

        if (!$errors) {
            $iid = (int)($_POST['id'] ?? 0);
            $items = $_POST['items'] ?? [];

            if ($iid) {
                dbUpdate('invoices', $data, ['id' => $iid]);
                dbDelete('invoice_items', ['invoice_id' => $iid]);
            } else {
                $data['invoice_number'] = generateCode('INV', 'invoices', 'invoice_number', 6);
                $data['created_by']     = userId();
                $iid = dbInsert('invoices', $data);

                // Ledger entries
                $inv = dbFetch('SELECT invoice_number FROM invoices WHERE id=?', [$iid]);
                addCustomerLedger($data['customer_id'], $data['date'], 'debit', "Invoice {$inv['invoice_number']}", $data['total'], 'invoice', $iid);
                if ($data['paid_amount'] > 0) {
                    addCustomerLedger($data['customer_id'], $data['date'], 'credit', "Payment — Invoice {$inv['invoice_number']}", $data['paid_amount'], 'invoice', $iid);
                    addDailyLedger($data['date'], 'income', 'Sales', "Invoice {$inv['invoice_number']}", $data['paid_amount'], $data['payment_mode'], 'invoice', $iid);
                }
            }

            foreach ($items as $item) {
                if (empty($item['description']) || !isset($item['quantity'])) continue;
                $qty   = (float)($item['quantity'] ?? 0);
                $price = (float)($item['unit_price'] ?? 0);
                dbInsert('invoice_items', [
                    'invoice_id'  => $iid,
                    'product_id'  => (int)($item['product_id'] ?? 0) ?: null,
                    'description' => sanitize($item['description']),
                    'quantity'    => $qty,
                    'unit_price'  => $price,
                    'discount'    => (float)($item['discount'] ?? 0),
                    'total'       => $qty * $price - (float)($item['discount'] ?? 0),
                ]);
                // Reduce stock
                if (!empty($item['product_id']) && $qty > 0) {
                    updateStock((int)$item['product_id'], $qty, 'out', 'invoice', $iid, $price);
                }
            }
            redirect('invoices.php', 'Invoice saved.');
        }
    }
}

$filter = sanitize($_GET['filter'] ?? 'all');
$where  = match($filter) {
    'due'  => "WHERE i.status NOT IN ('paid','cancelled')",
    'paid' => "WHERE i.status='paid'",
    'today'=> "WHERE DATE(i.created_at)=CURDATE()",
    default=> 'WHERE 1=1',
};

$invoices = dbFetchAll(
    "SELECT i.*, c.name AS customer_name, c.phone FROM invoices i JOIN customers c ON c.id=i.customer_id $where ORDER BY i.created_at DESC LIMIT 200"
);

$totalSales = array_sum(array_column($invoices, 'total'));
$totalDue   = array_sum(array_column($invoices, 'due_amount'));

$viewId = (int)($_GET['id'] ?? 0);
$viewInvoice = null;
$viewItems   = [];
if ($viewId) {
    $viewInvoice = dbFetch("SELECT i.*, c.name AS customer_name, c.phone, c.address FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.id=?", [$viewId]);
    $viewItems   = dbFetchAll("SELECT ii.*, p.name AS product_name FROM invoice_items ii LEFT JOIN products p ON p.id=ii.product_id WHERE ii.invoice_id=?", [$viewId]);
}

pageStart('Invoices');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-file-invoice me-2"></i>Invoices</h1>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#invoiceModal"><i class="fas fa-plus me-2"></i>New Invoice</button>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $filter==='all'?'active':'' ?>" href="?filter=all">All</a></li>
  <li class="nav-item"><a class="nav-link <?= $filter==='due'?'active':'' ?>" href="?filter=due">Due/Unpaid</a></li>
  <li class="nav-item"><a class="nav-link <?= $filter==='paid'?'active':'' ?>" href="?filter=paid">Paid</a></li>
  <li class="nav-item"><a class="nav-link <?= $filter==='today'?'active':'' ?>" href="?filter=today">Today</a></li>
</ul>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="stat-card blue"><div class="stat-icon"><i class="fas fa-file-invoice-dollar"></i></div><div><div class="stat-value"><?= money($totalSales) ?></div><div class="stat-label">Total Sales</div></div></div></div>
  <div class="col-md-4"><div class="stat-card red"><div class="stat-icon"><i class="fas fa-hourglass-half"></i></div><div><div class="stat-value"><?= money($totalDue) ?></div><div class="stat-label">Total Due</div></div></div></div>
  <div class="col-md-4"><div class="stat-card green"><div class="stat-icon"><i class="fas fa-check-double"></i></div><div><div class="stat-value"><?= money($totalSales - $totalDue) ?></div><div class="stat-label">Total Collected</div></div></div></div>
</div>

<div class="row g-4">
  <div class="<?= $viewInvoice ? 'col-lg-6' : 'col-12' ?>">
    <div class="cj-card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>Invoice #</th><th>Customer</th><th>Date</th><th>Total</th><th>Paid</th><th>Due</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($invoices as $inv): ?>
              <tr>
                <td><a href="?id=<?= $inv['id'] ?>" class="fw-600"><?= h($inv['invoice_number']) ?></a></td>
                <td><?= h($inv['customer_name']) ?></td>
                <td><?= formatDate($inv['date']) ?></td>
                <td class="fw-600"><?= money($inv['total']) ?></td>
                <td class="text-success"><?= money($inv['paid_amount']) ?></td>
                <td class="<?= $inv['due_amount'] > 0 ? 'text-danger fw-600' : '' ?>"><?= money($inv['due_amount']) ?></td>
                <td><?= statusBadge($inv['status']) ?></td>
                <td class="table-actions">
                  <a href="?id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-info"><i class="fas fa-eye"></i></a>
                  <a href="print-invoice.php?id=<?= $inv['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fas fa-print"></i></a>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$invoices): ?><tr><td colspan="8" class="text-center py-4 text-muted">No invoices found.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <?php if ($viewInvoice): ?>
  <div class="col-lg-6">
    <div class="cj-card" id="print-area">
      <div class="card-header">
        <span class="card-title">Invoice <?= h($viewInvoice['invoice_number']) ?></span>
        <div class="d-flex gap-2">
          <a href="print-invoice.php?id=<?= $viewId ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fas fa-print me-1"></i>Print</a>
          <a href="invoices.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times"></i></a>
        </div>
      </div>
      <div class="card-body">
        <div class="row mb-3">
          <div class="col-6">
            <strong>COLORJET Bangladesh</strong><br>
            <small class="text-muted">Quality • Commitment • Service</small>
          </div>
          <div class="col-6 text-end">
            <div class="fw-bold">Invoice #<?= h($viewInvoice['invoice_number']) ?></div>
            <div class="text-muted small">Date: <?= formatDate($viewInvoice['date']) ?></div>
          </div>
        </div>
        <div class="mb-3 p-2 bg-sky rounded">
          <strong><?= h($viewInvoice['customer_name']) ?></strong><br>
          <small><?= h($viewInvoice['phone']) ?></small><br>
          <?php if ($viewInvoice['address']): ?><small class="text-muted"><?= h($viewInvoice['address']) ?></small><?php endif; ?>
        </div>
        <table class="table table-sm">
          <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead>
          <tbody>
            <?php foreach ($viewItems as $item): ?>
            <tr>
              <td><?= h($item['product_name'] ?: $item['description']) ?></td>
              <td><?= $item['quantity'] ?></td>
              <td><?= money($item['unit_price']) ?></td>
              <td><?= money($item['total']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr><td colspan="3">Subtotal</td><td><?= money($viewInvoice['subtotal']) ?></td></tr>
            <?php if ($viewInvoice['discount'] > 0): ?><tr><td colspan="3">Discount</td><td class="text-danger">-<?= money($viewInvoice['discount']) ?></td></tr><?php endif; ?>
            <?php if ($viewInvoice['tax'] > 0): ?><tr><td colspan="3">Tax</td><td><?= money($viewInvoice['tax']) ?></td></tr><?php endif; ?>
            <tr class="fw-bold"><td colspan="3">Total</td><td class="fs-5"><?= money($viewInvoice['total']) ?></td></tr>
            <tr class="text-success"><td colspan="3">Paid</td><td><?= money($viewInvoice['paid_amount']) ?></td></tr>
            <tr class="<?= $viewInvoice['due_amount'] > 0 ? 'text-danger fw-bold' : 'text-success' ?>"><td colspan="3">Due</td><td><?= money($viewInvoice['due_amount']) ?></td></tr>
          </tfoot>
        </table>
        <?= statusBadge($viewInvoice['status']) ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Invoice Modal -->
<div class="modal fade" id="invoiceModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Create Invoice</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
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
            <div class="col-md-3"><label class="form-label">Due Date</label><input type="date" name="due_date" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">Payment Mode</label>
              <select name="payment_mode" class="form-select">
                <option value="cash">Cash</option><option value="bank">Bank</option><option value="cheque">Cheque</option><option value="bkash">bKash</option>
              </select>
            </div>
          </div>
          <!-- Line Items -->
          <table class="table table-sm" id="invoiceItemsTable">
            <thead><tr><th>Product</th><th>Description</th><th>Qty</th><th>Unit Price</th><th>Discount</th><th>Total</th><th></th></tr></thead>
            <tbody id="invoiceItemsBody">
              <tr class="inv-item-row">
                <td><select name="items[0][product_id]" class="form-select form-select-sm" onchange="fillProduct(this,0)"><option value="">--</option><?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>" data-price="<?= $p['selling_price'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></td>
                <td><input type="text" name="items[0][description]" id="desc_0" class="form-control form-control-sm" placeholder="Description"></td>
                <td><input type="number" name="items[0][quantity]" id="qty_0" class="form-control form-control-sm" value="1" min="0.001" step="0.001" oninput="calcRow(0)"></td>
                <td><input type="number" name="items[0][unit_price]" id="price_0" class="form-control form-control-sm" value="0" step="0.01" oninput="calcRow(0)"></td>
                <td><input type="number" name="items[0][discount]" id="disc_0" class="form-control form-control-sm" value="0" step="0.01" oninput="calcRow(0)"></td>
                <td><input type="number" name="items[0][total]" id="total_0" class="form-control form-control-sm bg-light" readonly value="0"></td>
                <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)"><i class="fas fa-trash"></i></button></td>
              </tr>
            </tbody>
          </table>
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="addInvRow()"><i class="fas fa-plus me-1"></i>Add Item</button>
          <div class="row mt-3">
            <div class="col-md-6">
              <label class="form-label">Notes</label>
              <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-md-6">
              <table class="table table-sm">
                <tr><td>Subtotal:</td><td><input type="number" name="subtotal" id="inv_subtotal" class="form-control form-control-sm bg-light" readonly value="0"></td></tr>
                <tr><td>Discount (৳):</td><td><input type="number" name="discount" id="inv_discount" class="form-control form-control-sm" value="0" oninput="calcInvTotal()"></td></tr>
                <tr><td>Tax (৳):</td><td><input type="number" name="tax" id="inv_tax" class="form-control form-control-sm" value="0" oninput="calcInvTotal()"></td></tr>
                <tr class="fw-bold"><td>Total:</td><td><input type="number" name="total" id="inv_total" class="form-control form-control-sm bg-sky fw-bold" readonly value="0"></td></tr>
                <tr><td>Paid Amount (৳):</td><td><input type="number" name="paid_amount" id="inv_paid" class="form-control form-control-sm" value="0" step="0.01" oninput="calcInvTotal()"></td></tr>
                <tr class="text-danger"><td>Due:</td><td><input type="number" name="due_amount" id="inv_due" class="form-control form-control-sm bg-light" readonly value="0"></td></tr>
              </table>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Invoice</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
let invRowCount = 1;

function fillProduct(sel, idx) {
  const opt = sel.options[sel.selectedIndex];
  document.getElementById('desc_' + idx).value = opt.text;
  document.getElementById('price_' + idx).value = opt.dataset.price || 0;
  calcRow(idx);
}

function calcRow(idx) {
  const qty   = parseFloat(document.getElementById('qty_' + idx)?.value || 0);
  const price = parseFloat(document.getElementById('price_' + idx)?.value || 0);
  const disc  = parseFloat(document.getElementById('disc_' + idx)?.value || 0);
  const total = qty * price - disc;
  const totalEl = document.getElementById('total_' + idx);
  if (totalEl) totalEl.value = total.toFixed(2);
  calcInvTotal();
}

function calcInvTotal() {
  let sub = 0;
  document.querySelectorAll('[id^="total_"]').forEach(el => sub += parseFloat(el.value || 0));
  document.getElementById('inv_subtotal').value = sub.toFixed(2);
  const disc = parseFloat(document.getElementById('inv_discount').value || 0);
  const tax  = parseFloat(document.getElementById('inv_tax').value || 0);
  const total = sub - disc + tax;
  document.getElementById('inv_total').value = total.toFixed(2);
  const paid = parseFloat(document.getElementById('inv_paid').value || 0);
  document.getElementById('inv_due').value = (total - paid).toFixed(2);
}

function addInvRow() {
  const idx = invRowCount++;
  const tbody = document.getElementById('invoiceItemsBody');
  const row = document.createElement('tr');
  row.className = 'inv-item-row';
  row.innerHTML = `
    <td><select name="items[${idx}][product_id]" class="form-select form-select-sm" onchange="fillProduct(this,${idx})"><option value="">--</option><?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>" data-price="<?= $p['selling_price'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></td>
    <td><input type="text" name="items[${idx}][description]" id="desc_${idx}" class="form-control form-control-sm"></td>
    <td><input type="number" name="items[${idx}][quantity]" id="qty_${idx}" class="form-control form-control-sm" value="1" min="0.001" step="0.001" oninput="calcRow(${idx})"></td>
    <td><input type="number" name="items[${idx}][unit_price]" id="price_${idx}" class="form-control form-control-sm" value="0" step="0.01" oninput="calcRow(${idx})"></td>
    <td><input type="number" name="items[${idx}][discount]" id="disc_${idx}" class="form-control form-control-sm" value="0" step="0.01" oninput="calcRow(${idx})"></td>
    <td><input type="number" name="items[${idx}][total]" id="total_${idx}" class="form-control form-control-sm bg-light" readonly value="0"></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)"><i class="fas fa-trash"></i></button></td>`;
  tbody.appendChild(row);
}

function removeRow(btn) { btn.closest('tr').remove(); calcInvTotal(); }
</script>

<?php pageEnd(); ?>
