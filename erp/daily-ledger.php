<?php
require_once 'includes/layout.php';
requireLogin();

$dateFrom = sanitize($_GET['from'] ?? date('Y-m-01'));
$dateTo   = sanitize($_GET['to']   ?? date('Y-m-d'));
$mode     = sanitize($_GET['mode'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $data = [
            'date'         => sanitize($_POST['date'] ?? date('Y-m-d')),
            'type'         => in_array($_POST['type']??'income',['income','expense','transfer']) ? $_POST['type'] : 'income',
            'category'     => sanitize($_POST['category'] ?? ''),
            'description'  => sanitize($_POST['description'] ?? ''),
            'amount'       => (float)($_POST['amount'] ?? 0),
            'payment_mode' => sanitize($_POST['payment_mode'] ?? 'cash'),
            'created_by'   => userId(),
        ];
        $errors = validateRequired($data, ['description','amount']);
        if ($data['amount'] <= 0) $errors[] = 'Amount must be greater than 0.';
        if (!$errors) {
            dbInsert('daily_ledger', $data);
            redirect('daily-ledger.php?from=' . $dateFrom . '&to=' . $dateTo, 'Entry added successfully.');
        }
    }
}

$whereMode = $mode ? "AND payment_mode='$mode'" : '';

$entries = dbFetchAll(
    "SELECT dl.*, u.name AS user_name FROM daily_ledger dl LEFT JOIN users u ON u.id=dl.created_by
     WHERE dl.date BETWEEN ? AND ? $whereMode ORDER BY dl.date DESC, dl.id DESC",
    [$dateFrom, $dateTo]
);

// Totals
$totalIn  = array_sum(array_column(array_filter($entries, fn($e) => $e['type']==='income'), 'amount'));
$totalOut = array_sum(array_column(array_filter($entries, fn($e) => $e['type']==='expense'), 'amount'));
$closing  = $totalIn - $totalOut;

// Opening balance (sum of all before dateFrom)
$obRow   = dbFetch("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) AS ob FROM daily_ledger WHERE date < ?", [$dateFrom]);
$opening = (float)($obRow['ob'] ?? 0);

pageStart('Daily Ledger');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-book me-2"></i>Daily Ledger</h1>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#entryModal">
    <i class="fas fa-plus me-2"></i>Add Entry
  </button>
</div>

<!-- Filter -->
<div class="cj-card mb-4">
  <div class="card-body">
    <form class="row g-3" method="GET">
      <div class="col-md-4">
        <label class="form-label">From Date</label>
        <input type="date" name="from" class="form-control" value="<?= h($dateFrom) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">To Date</label>
        <input type="date" name="to" class="form-control" value="<?= h($dateTo) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Payment Mode</label>
        <select name="mode" class="form-select">
          <option value="">All Modes</option>
          <?php foreach (['cash','bank','cheque','bkash','nagad','rocket','online','other'] as $m): ?>
          <option value="<?= $m ?>" <?= $mode===$m?'selected':'' ?>><?= ucfirst($m) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1 d-flex align-items-end">
        <button class="btn btn-primary w-100">Filter</button>
      </div>
    </form>
  </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="stat-card teal">
      <div class="stat-icon"><i class="fas fa-wallet"></i></div>
      <div><div class="stat-value"><?= money($opening) ?></div><div class="stat-label">Opening Balance</div></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card green">
      <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
      <div><div class="stat-value"><?= money($totalIn) ?></div><div class="stat-label">Total Income (IN)</div></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card red">
      <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
      <div><div class="stat-value"><?= money($totalOut) ?></div><div class="stat-label">Total Expense (OUT)</div></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card <?= ($opening + $closing) >= 0 ? 'blue' : 'red' ?>">
      <div class="stat-icon"><i class="fas fa-scale-balanced"></i></div>
      <div><div class="stat-value"><?= money($opening + $closing) ?></div><div class="stat-label">Closing Balance</div></div>
    </div>
  </div>
</div>

<div class="cj-card">
  <div class="card-header">
    <span class="card-title">Ledger Entries (<?= formatDate($dateFrom) ?> — <?= formatDate($dateTo) ?>)</span>
    <div class="d-flex gap-2">
      <button class="btn btn-sm btn-outline-success" onclick="CJ.exportCSV('ledgerTable','daily_ledger')"><i class="fas fa-download me-1"></i>CSV</button>
      <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="ledgerTable">
        <thead>
          <tr>
            <th>Date</th><th>Description</th><th>Category</th><th>Mode</th>
            <th class="text-success">IN (৳)</th><th class="text-danger">OUT (৳)</th>
            <th>Added By</th>
          </tr>
        </thead>
        <tbody>
          <tr class="table-info fw-bold">
            <td><?= formatDate($dateFrom) ?></td>
            <td>Opening Balance</td>
            <td>—</td><td>—</td>
            <td><?= $opening >= 0 ? money($opening) : '—' ?></td>
            <td><?= $opening < 0 ? money(abs($opening)) : '—' ?></td>
            <td>System</td>
          </tr>
          <?php foreach ($entries as $e): ?>
          <tr>
            <td><?= formatDate($e['date']) ?></td>
            <td><?= h($e['description']) ?></td>
            <td><?= h($e['category'] ?: '—') ?></td>
            <td><span class="badge bg-secondary"><?= ucfirst($e['payment_mode']) ?></span></td>
            <td class="text-success fw-600"><?= $e['type']==='income' ? money($e['amount']) : '—' ?></td>
            <td class="text-danger fw-600"><?= $e['type']==='expense' ? money($e['amount']) : '—' ?></td>
            <td class="text-muted small"><?= h($e['user_name'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
          <tr class="table-success fw-bold cost-summary-row">
            <td colspan="4">Closing Balance (<?= formatDate($dateTo) ?>)</td>
            <td><?= money($totalIn) ?></td>
            <td><?= money($totalOut) ?></td>
            <td><?= money($opening + $closing) ?></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add Entry Modal -->
<div class="modal fade" id="entryModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Add Ledger Entry</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Date</label>
              <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Type</label>
              <select name="type" class="form-select" id="entry_type">
                <option value="income">Income (IN)</option>
                <option value="expense">Expense (OUT)</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Category</label>
              <select name="category" class="form-select">
                <option value="">-- Select --</option>
                <optgroup label="Income">
                  <option>Sales</option><option>EMI Collection</option><option>Service Income</option><option>Commission</option><option>Other Income</option>
                </optgroup>
                <optgroup label="Expense">
                  <option>Rent</option><option>Salary</option><option>Utilities</option><option>Transport</option><option>Supplier Payment</option><option>Bank Charge</option><option>Office Expense</option><option>Other Expense</option>
                </optgroup>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Payment Mode</label>
              <select name="payment_mode" class="form-select">
                <option value="cash">Cash</option>
                <option value="bank">Bank Transfer</option>
                <option value="cheque">Cheque</option>
                <option value="bkash">bKash</option>
                <option value="nagad">Nagad</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Description <span class="text-danger">*</span></label>
              <input type="text" name="description" class="form-control" required placeholder="Description of this entry">
            </div>
            <div class="col-12">
              <label class="form-label">Amount (৳) <span class="text-danger">*</span></label>
              <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Add Entry</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php pageEnd(); ?>
