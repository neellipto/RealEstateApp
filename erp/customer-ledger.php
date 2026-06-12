<?php
require_once 'includes/layout.php';
requireLogin();

$customerId = (int)($_GET['customer_id'] ?? 0);
$dateFrom   = sanitize($_GET['from'] ?? date('Y-m-01'));
$dateTo     = sanitize($_GET['to']   ?? date('Y-m-d'));
$customers  = getCustomers();

$customer = $customerId ? dbFetch('SELECT * FROM customers WHERE id=?', [$customerId]) : null;

$entries = [];
$totalDebit = $totalCredit = 0;
if ($customerId) {
    $entries = dbFetchAll(
        "SELECT cl.*, u.name AS user_name FROM customer_ledger cl LEFT JOIN users u ON u.id=cl.created_by
         WHERE cl.customer_id=? AND cl.date BETWEEN ? AND ? ORDER BY cl.date ASC, cl.id ASC",
        [$customerId, $dateFrom, $dateTo]
    );
    $totalDebit  = array_sum(array_column(array_filter($entries, fn($e) => $e['type']==='debit'), 'amount'));
    $totalCredit = array_sum(array_column(array_filter($entries, fn($e) => $e['type']==='credit'), 'amount'));
}

// Post manual ledger entry
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $data = [
        'customer_id' => (int)($_POST['customer_id'] ?? 0),
        'date'        => sanitize($_POST['date'] ?? date('Y-m-d')),
        'type'        => in_array($_POST['type']??'debit',['debit','credit']) ? $_POST['type'] : 'debit',
        'description' => sanitize($_POST['description'] ?? ''),
        'amount'      => (float)($_POST['amount'] ?? 0),
        'created_by'  => userId(),
    ];
    if ($data['customer_id'] && $data['description'] && $data['amount'] > 0) {
        dbInsert('customer_ledger', $data);
        redirect("customer-ledger.php?customer_id={$data['customer_id']}&from=$dateFrom&to=$dateTo", 'Entry added.');
    }
    redirect("customer-ledger.php?customer_id={$data['customer_id']}", 'Invalid data.', 'error');
}

// Running balance
$running = 0;
$runningEntries = [];
foreach ($entries as $e) {
    $running += $e['type'] === 'debit' ? $e['amount'] : -$e['amount'];
    $e['running_balance'] = $running;
    $runningEntries[] = $e;
}

pageStart('Customer Ledger');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-user-check me-2"></i>Customer Ledger</h1>
  <?php if ($customer): ?>
  <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addEntryModal">
    <i class="fas fa-plus me-2"></i>Manual Entry
  </button>
  <?php endif; ?>
</div>

<!-- Filter -->
<div class="cj-card mb-4">
  <div class="card-body">
    <form class="row g-3" method="GET">
      <div class="col-md-4">
        <label class="form-label">Customer</label>
        <select name="customer_id" class="form-select">
          <option value="">-- Select Customer --</option>
          <?php foreach ($customers as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $c['id']==$customerId?'selected':'' ?>><?= h($c['name']) ?> — <?= h($c['phone']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">From</label>
        <input type="date" name="from" class="form-control" value="<?= h($dateFrom) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">To</label>
        <input type="date" name="to" class="form-control" value="<?= h($dateTo) ?>">
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-primary w-100">Load Ledger</button>
      </div>
    </form>
  </div>
</div>

<?php if ($customer): ?>
<!-- Customer Info -->
<div class="cj-card mb-4">
  <div class="card-body">
    <div class="row">
      <div class="col-md-8">
        <h5><?= h($customer['name']) ?></h5>
        <div class="text-muted"><?= h($customer['phone']) ?> · <?= h($customer['address'] ?: '—') ?></div>
      </div>
      <div class="col-md-4 text-end">
        <?php $balance = $totalDebit - $totalCredit; ?>
        <div style="font-size:24px;font-weight:700" class="<?= $balance > 0 ? 'text-danger' : 'text-success' ?>">
          <?= money(abs($balance)) ?> <?= $balance > 0 ? 'DR' : 'CR' ?>
        </div>
        <div class="text-muted small">Current Balance (<?= formatDate($dateFrom) ?>–<?= formatDate($dateTo) ?>)</div>
      </div>
    </div>
  </div>
</div>

<!-- Summary -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="stat-card red"><div class="stat-icon"><i class="fas fa-arrow-right"></i></div>
      <div><div class="stat-value"><?= money($totalDebit) ?></div><div class="stat-label">Total Debit (Dr)</div></div></div>
  </div>
  <div class="col-md-4">
    <div class="stat-card green"><div class="stat-icon"><i class="fas fa-arrow-left"></i></div>
      <div><div class="stat-value"><?= money($totalCredit) ?></div><div class="stat-label">Total Credit (Cr)</div></div></div>
  </div>
  <div class="col-md-4">
    <div class="stat-card <?= ($totalDebit-$totalCredit)>0?'red':'green' ?>"><div class="stat-icon"><i class="fas fa-scale-balanced"></i></div>
      <div><div class="stat-value"><?= money(abs($totalDebit-$totalCredit)) ?></div><div class="stat-label">Balance</div></div></div>
  </div>
</div>

<div class="cj-card">
  <div class="card-header">
    <span class="card-title">Ledger — <?= h($customer['name']) ?></span>
    <div class="d-flex gap-2">
      <button class="btn btn-sm btn-outline-success" onclick="CJ.exportCSV('clTable','customer_ledger_<?= $customerId ?>')"><i class="fas fa-download me-1"></i>CSV</button>
      <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="clTable">
        <thead>
          <tr><th>Date</th><th>Description</th><th>Ref</th><th class="text-danger">Debit (৳)</th><th class="text-success">Credit (৳)</th><th>Balance</th></tr>
        </thead>
        <tbody>
          <?php foreach ($runningEntries as $e): ?>
          <tr>
            <td><?= formatDate($e['date']) ?></td>
            <td><?= h($e['description']) ?></td>
            <td><small class="text-muted"><?= h($e['reference_type'] ? $e['reference_type'].'#'.$e['reference_id'] : '—') ?></small></td>
            <td class="text-danger fw-600"><?= $e['type']==='debit' ? money($e['amount']) : '—' ?></td>
            <td class="text-success fw-600"><?= $e['type']==='credit' ? money($e['amount']) : '—' ?></td>
            <td class="fw-600 <?= $e['running_balance'] > 0 ? 'text-danger' : 'text-success' ?>">
              <?= money(abs($e['running_balance'])) ?> <?= $e['running_balance'] > 0 ? 'Dr' : 'Cr' ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$runningEntries): ?>
          <tr><td colspan="6" class="text-center py-4 text-muted">No ledger entries found for this period.</td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr class="fw-bold table-light">
            <td colspan="3">Total</td>
            <td class="text-danger"><?= money($totalDebit) ?></td>
            <td class="text-success"><?= money($totalCredit) ?></td>
            <td class="<?= ($totalDebit-$totalCredit)>0?'text-danger':'text-success' ?>">
              <?= money(abs($totalDebit-$totalCredit)) ?> <?= ($totalDebit-$totalCredit)>0?'Dr':'Cr' ?>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<!-- Add Entry Modal -->
<div class="modal fade" id="addEntryModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="customer_id" value="<?= $customerId ?>">
        <div class="modal-header"><h5 class="modal-title">Manual Ledger Entry</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Date</label><input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-6"><label class="form-label">Type</label>
              <select name="type" class="form-select"><option value="debit">Debit (Dr)</option><option value="credit">Credit (Cr)</option></select>
            </div>
            <div class="col-12"><label class="form-label">Description</label><input type="text" name="description" class="form-control" required></div>
            <div class="col-12"><label class="form-label">Amount (৳)</label><input type="number" name="amount" class="form-control" step="0.01" min="0.01" required></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Entry</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php else: ?>
<div class="cj-card"><div class="card-body text-center py-5 text-muted">
  <i class="fas fa-user-check fa-3x mb-3"></i><br>Select a customer above to view their ledger.
</div></div>
<?php endif; ?>

<?php pageEnd(); ?>
