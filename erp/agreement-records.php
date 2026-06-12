<?php
require_once 'includes/layout.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$viewAgreement = null;
$emiSchedule = [];

if ($id) {
    $viewAgreement = dbFetch(
        "SELECT a.*, c.name AS customer_name, c.phone, c.address, c.nid_number, c.father_name
         FROM agreements a JOIN customers c ON c.id=a.customer_id WHERE a.id=?", [$id]
    );
    if ($viewAgreement) {
        $emiSchedule = dbFetchAll(
            "SELECT * FROM emi_schedules WHERE agreement_id=? ORDER BY installment_number ASC", [$id]
        );
    }
}

// Handle EMI payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'pay_emi') {
        $emiId   = (int)($_POST['emi_id'] ?? 0);
        $amount  = (float)($_POST['amount'] ?? 0);
        $mode    = sanitize($_POST['payment_mode'] ?? 'cash');
        $date    = sanitize($_POST['pay_date'] ?? date('Y-m-d'));
        if ($emiId && $amount > 0) {
            $emi = dbFetch('SELECT * FROM emi_schedules WHERE id=?', [$emiId]);
            if ($emi) {
                $newPaid  = (float)$emi['paid_amount'] + $amount;
                $newStatus = $newPaid >= (float)$emi['amount'] ? 'paid' : 'partial';
                dbUpdate('emi_schedules', ['paid_amount'=>$newPaid,'paid_date'=>$date,'status'=>$newStatus], ['id'=>$emiId]);
                $ag = dbFetch('SELECT * FROM agreements WHERE id=?', [$emi['agreement_id']]);
                if ($ag) {
                    addCustomerLedger($emi['customer_id'], $date, 'credit', "EMI #{$emi['installment_number']} — Agr {$ag['agreement_number']}", $amount, 'emi', $emiId);
                    addDailyLedger($date, 'income', 'EMI Collection', "EMI #{$emi['installment_number']} — {$ag['agreement_number']}", $amount, $mode, 'emi', $emiId);
                }
                jsonSuccess(null, 'EMI payment recorded.');
            }
        }
        jsonError('Invalid EMI payment.');
    }
}

// List
$filter = $_GET['filter'] ?? 'all';
$where  = $filter === 'active' ? "WHERE a.status='active'" :
          ($filter === 'emi_overdue' ? "WHERE EXISTS (SELECT 1 FROM emi_schedules e WHERE e.agreement_id=a.id AND e.due_date < CURDATE() AND e.status='pending')" : 'WHERE 1=1');

$agreements = dbFetchAll(
    "SELECT a.*, c.name AS customer_name, c.phone
     FROM agreements a JOIN customers c ON c.id=a.customer_id
     $where ORDER BY a.created_at DESC LIMIT 200"
);

pageStart('Agreement Records');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-folder-open me-2"></i>Agreement Records</h1>
  <a href="agreement-generator.php" class="btn btn-primary"><i class="fas fa-plus me-2"></i>New Agreement</a>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $filter==='all'?'active':'' ?>" href="?filter=all">All</a></li>
  <li class="nav-item"><a class="nav-link <?= $filter==='active'?'active':'' ?>" href="?filter=active">Active</a></li>
  <li class="nav-item"><a class="nav-link <?= $filter==='emi_overdue'?'active':'' ?>" href="?filter=emi_overdue">EMI Overdue</a></li>
</ul>

<div class="row g-4">
  <div class="<?= $viewAgreement ? 'col-lg-5' : 'col-12' ?>">
    <div class="cj-card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr><th>Agr #</th><th>Customer</th><th>Machine</th><th>Amount</th><th>EMI</th><th>Status</th><th>Date</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($agreements as $a): ?>
              <tr>
                <td><a href="?id=<?= $a['id'] ?>" class="fw-600"><?= h($a['agreement_number']) ?></a></td>
                <td>
                  <div><?= h($a['customer_name']) ?></div>
                  <div class="text-muted small"><?= h($a['phone']) ?></div>
                </td>
                <td><?= h($a['machine_model']) ?></td>
                <td>
                  <div><?= money($a['cash_price']) ?></div>
                  <div class="text-muted small">DP: <?= money($a['down_payment']) ?></div>
                </td>
                <td>
                  <?php if ($a['emi_months'] > 0): ?>
                  <?= money($a['emi_amount']) ?>/mo × <?= $a['emi_months'] ?>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= statusBadge($a['status']) ?></td>
                <td><?= formatDate($a['date']) ?></td>
                <td class="table-actions">
                  <a href="?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-info" title="View"><i class="fas fa-eye"></i></a>
                  <button class="btn btn-sm btn-outline-secondary" onclick="printAgreement(<?= $a['id'] ?>)" title="Print"><i class="fas fa-print"></i></button>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$agreements): ?>
              <tr><td colspan="8" class="text-center py-4 text-muted">No agreements found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <?php if ($viewAgreement): ?>
  <div class="col-lg-7">
    <div class="cj-card mb-4">
      <div class="card-header">
        <span class="card-title">Agreement: <?= h($viewAgreement['agreement_number']) ?></span>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-primary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
          <a href="agreement-records.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times"></i></a>
        </div>
      </div>
      <div class="card-body">
        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <table class="table table-sm">
              <tr><td class="text-muted">Customer</td><td><strong><?= h($viewAgreement['customer_name']) ?></strong></td></tr>
              <tr><td class="text-muted">Phone</td><td><?= h($viewAgreement['phone']) ?></td></tr>
              <tr><td class="text-muted">NID</td><td><?= h($viewAgreement['nid_number'] ?: '—') ?></td></tr>
              <tr><td class="text-muted">Machine</td><td><?= h($viewAgreement['machine_model']) ?></td></tr>
              <tr><td class="text-muted">Serial</td><td><?= h($viewAgreement['serial_number'] ?: '—') ?></td></tr>
            </table>
          </div>
          <div class="col-md-6">
            <table class="table table-sm">
              <tr><td class="text-muted">Cash Price</td><td><strong><?= money($viewAgreement['cash_price']) ?></strong></td></tr>
              <tr><td class="text-muted">Down Payment</td><td><?= money($viewAgreement['down_payment']) ?></td></tr>
              <tr><td class="text-muted">Remaining</td><td><?= money($viewAgreement['remaining_amount']) ?></td></tr>
              <tr><td class="text-muted">EMI</td><td><?= money($viewAgreement['emi_amount']) ?>/mo × <?= $viewAgreement['emi_months'] ?></td></tr>
              <tr><td class="text-muted">Status</td><td><?= statusBadge($viewAgreement['status']) ?></td></tr>
            </table>
          </div>
        </div>

        <!-- EMI Schedule -->
        <?php if ($emiSchedule): ?>
        <h6><i class="fas fa-calendar me-2"></i>EMI Schedule</h6>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead>
              <tr><th>#</th><th>Due Date</th><th>Amount</th><th>Paid</th><th>Status</th><th>Action</th></tr>
            </thead>
            <tbody>
              <?php
              $totalPaid = 0; $totalDue = 0;
              foreach ($emiSchedule as $e):
                $isOverdue = $e['status'] === 'pending' && strtotime($e['due_date']) < time();
                $totalPaid += (float)$e['paid_amount'];
                $totalDue  += (float)$e['amount'] - (float)$e['paid_amount'];
              ?>
              <tr class="<?= $isOverdue ? 'table-warning' : '' ?>">
                <td><?= $e['installment_number'] ?></td>
                <td><?= formatDate($e['due_date']) ?></td>
                <td><?= money($e['amount']) ?></td>
                <td><?= money($e['paid_amount']) ?></td>
                <td>
                  <?= statusBadge($e['status']) ?>
                  <?php if ($isOverdue): ?><span class="badge bg-danger ms-1">Overdue</span><?php endif; ?>
                </td>
                <td>
                  <?php if (in_array($e['status'], ['pending','partial','overdue'])): ?>
                  <button class="btn btn-sm btn-outline-success" onclick="payEmi(<?= $e['id'] ?>, <?= $e['amount'] - $e['paid_amount'] ?>)">
                    <i class="fas fa-money-bill"></i> Pay
                  </button>
                  <?php else: ?>
                  <span class="text-success"><i class="fas fa-check-circle"></i></span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr class="fw-bold">
                <td colspan="3">Total</td>
                <td><?= money($totalPaid) ?></td>
                <td><?= money($totalDue) ?> remaining</td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <?php endif; ?>

        <!-- Agreement Text -->
        <?php if ($viewAgreement['agreement_text']): ?>
        <div class="mt-4">
          <h6>Agreement Text</h6>
          <div class="agreement-preview" style="max-height:400px;overflow-y:auto;font-size:10pt">
            <?= nl2br(h($viewAgreement['agreement_text'])) ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Pay EMI Modal -->
<div class="modal fade" id="payEmiModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" id="payEmiForm">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="pay_emi">
        <input type="hidden" name="emi_id" id="emi_id_input">
        <div class="modal-header"><h5 class="modal-title">Record EMI Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Amount (৳)</label>
            <input type="number" name="amount" id="emi_amount_input" class="form-control" step="0.01" min="0.01" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Payment Date</label>
            <input type="date" name="pay_date" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Payment Mode</label>
            <select name="payment_mode" class="form-select">
              <option value="cash">Cash</option>
              <option value="bank">Bank Transfer</option>
              <option value="cheque">Cheque</option>
              <option value="bkash">bKash</option>
              <option value="nagad">Nagad</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-money-bill me-2"></i>Record Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function payEmi(id, dueAmount) {
  document.getElementById('emi_id_input').value = id;
  document.getElementById('emi_amount_input').value = dueAmount.toFixed(2);
  new bootstrap.Modal(document.getElementById('payEmiModal')).show();
}

document.getElementById('payEmiForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const r = await CJ.post('agreement-records.php', new FormData(e.target));
  if (r.ok) { CJ.flash('Payment recorded!'); setTimeout(() => location.reload(), 1000); }
  else CJ.flash(r.message, 'danger');
  bootstrap.Modal.getInstance(document.getElementById('payEmiModal'))?.hide();
});

function printAgreement(id) {
  window.open('print-agreement.php?id=' + id, '_blank');
}
</script>

<?php pageEnd(); ?>
