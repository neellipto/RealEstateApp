<?php
require_once 'includes/layout.php';
requireLogin();

$supplierId = (int)($_GET['supplier_id'] ?? 0);
$dateFrom   = sanitize($_GET['from'] ?? date('Y-m-01'));
$dateTo     = sanitize($_GET['to']   ?? date('Y-m-d'));
$suppliers  = getSuppliers();

$supplier = $supplierId ? dbFetch('SELECT * FROM suppliers WHERE id=?', [$supplierId]) : null;
$entries  = [];
$totalDebit = $totalCredit = 0;

if ($supplierId) {
    $entries = dbFetchAll(
        "SELECT sl.*, u.name AS user_name FROM supplier_ledger sl LEFT JOIN users u ON u.id=sl.created_by
         WHERE sl.supplier_id=? AND sl.date BETWEEN ? AND ? ORDER BY sl.date ASC, sl.id ASC",
        [$supplierId, $dateFrom, $dateTo]
    );
    $totalDebit  = array_sum(array_column(array_filter($entries, fn($e) => $e['type']==='debit'), 'amount'));
    $totalCredit = array_sum(array_column(array_filter($entries, fn($e) => $e['type']==='credit'), 'amount'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $data = [
        'supplier_id' => (int)($_POST['supplier_id'] ?? 0),
        'date'        => sanitize($_POST['date'] ?? date('Y-m-d')),
        'type'        => in_array($_POST['type']??'debit',['debit','credit']) ? $_POST['type'] : 'debit',
        'description' => sanitize($_POST['description'] ?? ''),
        'amount'      => (float)($_POST['amount'] ?? 0),
        'currency'    => sanitize($_POST['currency'] ?? 'BDT'),
        'created_by'  => userId(),
    ];
    if ($data['supplier_id'] && $data['description'] && $data['amount'] > 0) {
        dbInsert('supplier_ledger', $data);
        redirect("supplier-ledger.php?supplier_id={$data['supplier_id']}&from=$dateFrom&to=$dateTo", 'Entry added.');
    }
}

$running = 0;
$runningEntries = [];
foreach ($entries as $e) {
    $running += $e['type'] === 'debit' ? $e['amount'] : -$e['amount'];
    $e['running_balance'] = $running;
    $runningEntries[] = $e;
}

pageStart('Supplier Ledger');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-truck-ramp-box me-2"></i>Supplier Ledger</h1>
  <?php if ($supplier): ?>
  <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addEntryModal"><i class="fas fa-plus me-2"></i>Manual Entry</button>
  <?php endif; ?>
</div>

<div class="cj-card mb-4">
  <div class="card-body">
    <form class="row g-3" method="GET">
      <div class="col-md-4">
        <select name="supplier_id" class="form-select">
          <option value="">-- Select Supplier --</option>
          <?php foreach ($suppliers as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $s['id']==$supplierId?'selected':'' ?>><?= h($s['name']) ?> (<?= h($s['country']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><input type="date" name="from" class="form-control" value="<?= h($dateFrom) ?>"></div>
      <div class="col-md-3"><input type="date" name="to" class="form-control" value="<?= h($dateTo) ?>"></div>
      <div class="col-md-2"><button class="btn btn-primary w-100">Load</button></div>
    </form>
  </div>
</div>

<?php if ($supplier): ?>
<div class="cj-card mb-4">
  <div class="card-body">
    <div class="row">
      <div class="col-md-8">
        <h5><?= h($supplier['name']) ?> <small class="text-muted">(<?= h($supplier['country']) ?>)</small></h5>
        <div class="text-muted"><?= h($supplier['contact_person'] ?: '') ?> · <?= h($supplier['phone'] ?: '') ?></div>
      </div>
      <div class="col-md-4 text-end">
        <?php $balance = $totalDebit - $totalCredit; ?>
        <div style="font-size:24px;font-weight:700" class="<?= $balance > 0 ? 'text-danger' : 'text-success' ?>">
          <?= money(abs($balance)) ?> <?= $balance > 0 ? 'Due' : 'Advance' ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="stat-card red"><div class="stat-icon"><i class="fas fa-shopping-cart"></i></div><div><div class="stat-value"><?= money($totalDebit) ?></div><div class="stat-label">Total Purchases</div></div></div></div>
  <div class="col-md-4"><div class="stat-card green"><div class="stat-icon"><i class="fas fa-money-bill"></i></div><div><div class="stat-value"><?= money($totalCredit) ?></div><div class="stat-label">Total Paid</div></div></div></div>
  <div class="col-md-4"><div class="stat-card <?= ($totalDebit-$totalCredit)>0?'red':'green' ?>"><div class="stat-icon"><i class="fas fa-balance-scale"></i></div><div><div class="stat-value"><?= money(abs($totalDebit-$totalCredit)) ?></div><div class="stat-label"><?= ($totalDebit-$totalCredit)>0?'Due':'Advance' ?></div></div></div></div>
</div>

<div class="cj-card">
  <div class="card-header">
    <span class="card-title">Supplier Ledger — <?= h($supplier['name']) ?></span>
    <button class="btn btn-sm btn-outline-success" onclick="CJ.exportCSV('slTable','supplier_ledger_<?= $supplierId ?>')"><i class="fas fa-download me-1"></i>CSV</button>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="slTable">
        <thead><tr><th>Date</th><th>Description</th><th>Currency</th><th class="text-danger">Debit</th><th class="text-success">Credit</th><th>Balance</th></tr></thead>
        <tbody>
          <?php foreach ($runningEntries as $e): ?>
          <tr>
            <td><?= formatDate($e['date']) ?></td>
            <td><?= h($e['description']) ?></td>
            <td><?= h($e['currency']) ?></td>
            <td class="text-danger fw-600"><?= $e['type']==='debit' ? money($e['amount']) : '—' ?></td>
            <td class="text-success fw-600"><?= $e['type']==='credit' ? money($e['amount']) : '—' ?></td>
            <td class="fw-600 <?= $e['running_balance']>0?'text-danger':'text-success' ?>">
              <?= money(abs($e['running_balance'])) ?> <?= $e['running_balance']>0?'Due':'Adv' ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$runningEntries): ?><tr><td colspan="6" class="text-center py-4 text-muted">No entries found.</td></tr><?php endif; ?>
        </tbody>
        <tfoot>
          <tr class="fw-bold table-light">
            <td colspan="3">Total</td>
            <td class="text-danger"><?= money($totalDebit) ?></td>
            <td class="text-success"><?= money($totalCredit) ?></td>
            <td class="<?= ($totalDebit-$totalCredit)>0?'text-danger':'text-success' ?>"><?= money(abs($totalDebit-$totalCredit)) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="addEntryModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="supplier_id" value="<?= $supplierId ?>">
        <div class="modal-header"><h5 class="modal-title">Manual Supplier Ledger Entry</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Date</label><input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-6"><label class="form-label">Type</label>
              <select name="type" class="form-select"><option value="debit">Debit (Purchase)</option><option value="credit">Credit (Payment)</option></select>
            </div>
            <div class="col-12"><label class="form-label">Description</label><input type="text" name="description" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Amount</label><input type="number" name="amount" class="form-control" step="0.01" min="0.01" required></div>
            <div class="col-md-6"><label class="form-label">Currency</label>
              <select name="currency" class="form-select"><?php foreach (['BDT','USD','EUR','CNY'] as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?></select>
            </div>
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
<div class="cj-card"><div class="card-body text-center py-5 text-muted"><i class="fas fa-truck fa-3x mb-3"></i><br>Select a supplier to view ledger.</div></div>
<?php endif; ?>

<?php pageEnd(); ?>
