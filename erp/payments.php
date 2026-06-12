<?php
require_once 'includes/layout.php';
requireLogin();

$errors    = [];
$customers = getCustomers();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $data = [
            'type'           => in_array($_POST['type']??'receive',['receive','pay']) ? $_POST['type'] : 'receive',
            'party_type'     => in_array($_POST['party_type']??'customer',['customer','supplier','other']) ? $_POST['party_type'] : 'customer',
            'party_id'       => (int)($_POST['party_id'] ?? 0) ?: null,
            'date'           => sanitize($_POST['date'] ?? date('Y-m-d')),
            'amount'         => (float)($_POST['amount'] ?? 0),
            'payment_mode'   => sanitize($_POST['payment_mode'] ?? 'cash'),
            'bank_account'   => sanitize($_POST['bank_account'] ?? ''),
            'cheque_number'  => sanitize($_POST['cheque_number'] ?? ''),
            'transaction_id' => sanitize($_POST['transaction_id'] ?? ''),
            'notes'          => sanitize($_POST['notes'] ?? ''),
            'created_by'     => userId(),
        ];
        $errors = validateRequired($data, ['date','amount']);
        if ($data['amount'] <= 0) $errors[] = 'Amount must be greater than 0.';

        if (!$errors) {
            $data['payment_number'] = generateCode('PAY', 'payments', 'payment_number', 6);
            $pid = dbInsert('payments', $data);

            // Ledger
            if ($data['party_type'] === 'customer' && $data['party_id'] && $data['type'] === 'receive') {
                $cust = dbFetch('SELECT name FROM customers WHERE id=?', [$data['party_id']]);
                addCustomerLedger($data['party_id'], $data['date'], 'credit', "Payment received — {$data['payment_number']}", $data['amount'], 'payment', $pid);
                addDailyLedger($data['date'], 'income', 'Collection', "Payment from {$cust['name']} — {$data['payment_number']}", $data['amount'], $data['payment_mode'], 'payment', $pid);

                // Money receipt
                $rn = generateCode('MR', 'money_receipts', 'receipt_number', 6);
                dbInsert('money_receipts', ['receipt_number'=>$rn,'payment_id'=>$pid,'customer_id'=>$data['party_id'],'date'=>$data['date'],'amount'=>$data['amount'],'payment_mode'=>$data['payment_mode'],'created_by'=>userId()]);
            }

            logActivity('create', 'payments', $pid, "Payment {$data['payment_number']}");
            redirect('payments.php', 'Payment recorded: ' . $data['payment_number']);
        }
    }
}

$filter = sanitize($_GET['filter'] ?? 'all');
$where  = match($filter) {
    'receive' => "WHERE p.type='receive'",
    'pay'     => "WHERE p.type='pay'",
    'today'   => "WHERE DATE(p.created_at)=CURDATE()",
    default   => 'WHERE 1=1',
};

$payments = dbFetchAll(
    "SELECT p.*, u.name AS created_by_name FROM payments p LEFT JOIN users u ON u.id=p.created_by $where ORDER BY p.created_at DESC LIMIT 200"
);

$totalIn  = array_sum(array_column(array_filter($payments, fn($p) => $p['type']==='receive'), 'amount'));
$totalOut = array_sum(array_column(array_filter($payments, fn($p) => $p['type']==='pay'), 'amount'));

pageStart('Payments');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-money-bill-transfer me-2"></i>Payments</h1>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#paymentModal"><i class="fas fa-plus me-2"></i>Record Payment</button>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $filter==='all'?'active':'' ?>" href="?filter=all">All</a></li>
  <li class="nav-item"><a class="nav-link <?= $filter==='receive'?'active':'' ?>" href="?filter=receive">Received</a></li>
  <li class="nav-item"><a class="nav-link <?= $filter==='pay'?'active':'' ?>" href="?filter=pay">Paid</a></li>
  <li class="nav-item"><a class="nav-link <?= $filter==='today'?'active':'' ?>" href="?filter=today">Today</a></li>
</ul>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="stat-card green"><div class="stat-icon"><i class="fas fa-arrow-down"></i></div><div><div class="stat-value"><?= money($totalIn) ?></div><div class="stat-label">Total Received</div></div></div></div>
  <div class="col-md-4"><div class="stat-card red"><div class="stat-icon"><i class="fas fa-arrow-up"></i></div><div><div class="stat-value"><?= money($totalOut) ?></div><div class="stat-label">Total Paid</div></div></div></div>
  <div class="col-md-4"><div class="stat-card blue"><div class="stat-icon"><i class="fas fa-scale-balanced"></i></div><div><div class="stat-value"><?= money($totalIn - $totalOut) ?></div><div class="stat-label">Net Flow</div></div></div></div>
</div>

<div class="cj-card">
  <div class="card-header">
    <span class="card-title">Payment Transactions</span>
    <button class="btn btn-sm btn-outline-success" onclick="CJ.exportCSV('paymentsTable','payments')"><i class="fas fa-download me-1"></i>CSV</button>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="paymentsTable">
        <thead><tr><th>Ref #</th><th>Date</th><th>Type</th><th>Party</th><th>Amount</th><th>Mode</th><th>Notes</th><th>By</th></tr></thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
          <tr>
            <td><span class="fw-600"><?= h($p['payment_number']) ?></span></td>
            <td><?= formatDate($p['date']) ?></td>
            <td><span class="badge bg-<?= $p['type']==='receive'?'success':'danger' ?>"><?= ucfirst($p['type']) ?></span></td>
            <td>
              <?php
              if ($p['party_id'] && $p['party_type'] === 'customer') {
                  $party = dbFetch('SELECT name FROM customers WHERE id=?', [$p['party_id']]);
                  echo h($party['name'] ?? '—');
              } elseif ($p['party_id'] && $p['party_type'] === 'supplier') {
                  $party = dbFetch('SELECT name FROM suppliers WHERE id=?', [$p['party_id']]);
                  echo h($party['name'] ?? '—');
              } else echo h($p['party_type']);
              ?>
            </td>
            <td class="fw-600 <?= $p['type']==='receive'?'text-success':'text-danger' ?>"><?= money($p['amount']) ?></td>
            <td><span class="badge bg-secondary"><?= ucfirst($p['payment_mode']) ?></span></td>
            <td class="text-muted small"><?= h($p['notes'] ?: '—') ?></td>
            <td class="text-muted small"><?= h($p['created_by_name'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$payments): ?><tr><td colspan="8" class="text-center py-4 text-muted">No payments found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Record Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Payment Type</label>
              <select name="type" class="form-select" onchange="toggleParty(this)">
                <option value="receive">Receive (from customer)</option>
                <option value="pay">Pay (to supplier/other)</option>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Date</label><input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-6" id="party_type_row"><label class="form-label">Party Type</label>
              <select name="party_type" id="party_type" class="form-select" onchange="loadParties()">
                <option value="customer">Customer</option>
                <option value="supplier">Supplier</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-md-6" id="party_row"><label class="form-label">Customer</label>
              <select name="party_id" id="party_id" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Amount (৳) <span class="text-danger">*</span></label><input type="number" name="amount" class="form-control" step="0.01" min="0.01" required></div>
            <div class="col-md-6"><label class="form-label">Payment Mode</label>
              <select name="payment_mode" class="form-select">
                <option value="cash">Cash</option><option value="bank">Bank Transfer</option><option value="cheque">Cheque</option><option value="bkash">bKash</option><option value="nagad">Nagad</option><option value="rocket">Rocket</option>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Transaction ID / Cheque #</label><input type="text" name="transaction_id" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Bank Account</label><input type="text" name="bank_account" class="form-control"></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Record Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const suppliersList = <?= json_encode(array_map(fn($s) => ['id'=>$s['id'],'name'=>$s['name']], dbFetchAll('SELECT id, name FROM suppliers WHERE status="active" ORDER BY name'))) ?>;

function loadParties() {
  const type = document.getElementById('party_type').value;
  const sel = document.getElementById('party_id');
  const label = sel.previousElementSibling;
  sel.innerHTML = '<option value="">-- Select --</option>';
  if (type === 'customer') {
    label.textContent = 'Customer';
    <?php foreach ($customers as $c): ?>
    sel.add(new Option('<?= h($c['name']) ?>', '<?= $c['id'] ?>'));
    <?php endforeach; ?>
  } else if (type === 'supplier') {
    label.textContent = 'Supplier';
    suppliersList.forEach(s => sel.add(new Option(s.name, s.id)));
  } else {
    label.textContent = 'Party (N/A)';
  }
}
</script>

<?php pageEnd(); ?>
