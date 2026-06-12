<?php
require_once 'includes/layout.php';
requireLogin();

$stats = getDashboardStats();
$user  = currentUser();

// Recent activities
$recentActivities = dbFetchAll(
    "SELECT al.*, u.name AS user_name FROM activity_log al LEFT JOIN users u ON u.id=al.user_id ORDER BY al.created_at DESC LIMIT 10"
);

// Recent tasks
$todayTasks = dbFetchAll(
    "SELECT t.*, u.name AS assigned_name FROM office_tasks t LEFT JOIN users u ON u.id=t.assigned_to 
     WHERE t.status NOT IN ('completed','cancelled') ORDER BY t.priority DESC, t.due_date ASC LIMIT 8"
);

// Overdue EMIs
$overdueEmis = dbFetchAll(
    "SELECT e.*, c.name AS customer_name, a.agreement_number, a.machine_model 
     FROM emi_schedules e JOIN customers c ON c.id=e.customer_id JOIN agreements a ON a.id=e.agreement_id
     WHERE e.due_date < CURDATE() AND e.status='pending' ORDER BY e.due_date ASC LIMIT 6"
);

// Low stock
$lowStock = dbFetchAll(
    "SELECT id, name, sku, current_stock, low_stock_alert, unit FROM products WHERE current_stock <= low_stock_alert AND is_active=1 ORDER BY current_stock ASC LIMIT 8"
);

// Open service tickets
$openTickets = dbFetchAll(
    "SELECT st.*, c.name AS customer_name, u.name AS engineer_name FROM service_tickets st 
     LEFT JOIN customers c ON c.id=st.customer_id LEFT JOIN users u ON u.id=st.assigned_engineer
     WHERE st.status NOT IN ('resolved','closed') ORDER BY st.priority DESC, st.created_at DESC LIMIT 6"
);

pageStart('Owner Dashboard');
?>
<?= flash() ?>

<!-- Stats Grid -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <a href="invoices.php" class="stat-card blue text-decoration-none">
      <div class="stat-icon"><i class="fas fa-coins"></i></div>
      <div><div class="stat-value"><?= money($stats['today_sales']) ?></div><div class="stat-label">Today Sales</div></div>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <a href="payments.php" class="stat-card green text-decoration-none">
      <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
      <div><div class="stat-value"><?= money($stats['today_collection']) ?></div><div class="stat-label">Today Collection</div></div>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <a href="invoices.php?filter=due" class="stat-card red text-decoration-none">
      <div class="stat-icon"><i class="fas fa-triangle-exclamation"></i></div>
      <div><div class="stat-value"><?= money($stats['total_due']) ?></div><div class="stat-label">Total Due</div></div>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <a href="daily-ledger.php" class="stat-card teal text-decoration-none">
      <div class="stat-icon"><i class="fas fa-wallet"></i></div>
      <div><div class="stat-value"><?= money($stats['cash_balance']) ?></div><div class="stat-label">Cash Balance</div></div>
    </a>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-2">
    <a href="office-tasks.php" class="stat-card orange text-decoration-none">
      <div class="stat-icon"><i class="fas fa-list-check"></i></div>
      <div><div class="stat-value"><?= $stats['pending_tasks'] ?></div><div class="stat-label">Pending Tasks</div></div>
    </a>
  </div>
  <div class="col-6 col-md-2">
    <a href="engineer-schedule.php" class="stat-card blue text-decoration-none">
      <div class="stat-icon"><i class="fas fa-hard-hat"></i></div>
      <div><div class="stat-value"><?= $stats['today_jobs'] ?></div><div class="stat-label">Today Jobs</div></div>
    </a>
  </div>
  <div class="col-6 col-md-2">
    <a href="service-tickets.php" class="stat-card red text-decoration-none">
      <div class="stat-icon"><i class="fas fa-ticket"></i></div>
      <div><div class="stat-value"><?= $stats['open_tickets'] ?></div><div class="stat-label">Open Tickets</div></div>
    </a>
  </div>
  <div class="col-6 col-md-2">
    <a href="stock.php?filter=low" class="stat-card orange text-decoration-none">
      <div class="stat-icon"><i class="fas fa-boxes-stacked"></i></div>
      <div><div class="stat-value"><?= $stats['low_stock'] ?></div><div class="stat-label">Low Stock</div></div>
    </a>
  </div>
  <div class="col-6 col-md-2">
    <a href="lc-tt.php" class="stat-card purple text-decoration-none">
      <div class="stat-icon"><i class="fas fa-building-columns"></i></div>
      <div><div class="stat-value"><?= $stats['open_lc'] ?></div><div class="stat-label">Open LC/TT</div></div>
    </a>
  </div>
  <div class="col-6 col-md-2">
    <a href="agreement-records.php?filter=emi_overdue" class="stat-card red text-decoration-none">
      <div class="stat-icon"><i class="fas fa-credit-card"></i></div>
      <div><div class="stat-value"><?= $stats['emi_overdue'] ?></div><div class="stat-label">EMI Overdue</div></div>
    </a>
  </div>
</div>

<!-- Main Dashboard Grid -->
<div class="row g-4">

  <!-- Pending Tasks -->
  <div class="col-lg-6">
    <div class="cj-card">
      <div class="card-header">
        <span class="card-title"><i class="fas fa-list-check me-2 text-warning"></i>Pending Tasks</span>
        <a href="office-tasks.php" class="btn btn-sm btn-outline-primary">View All</a>
      </div>
      <div class="card-body p-0">
        <?php if ($todayTasks): ?>
        <div class="list-group list-group-flush">
          <?php foreach ($todayTasks as $t): ?>
          <a href="office-tasks.php?id=<?= $t['id'] ?>" class="list-group-item list-group-item-action py-2 px-3">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="fw-600 small"><?= h($t['title']) ?></div>
                <div class="text-muted" style="font-size:11px">
                  <?= h($t['assigned_name'] ?? 'Unassigned') ?> • 
                  <?= $t['due_date'] ? formatDateTime($t['due_date'], 'd M H:i') : 'No due date' ?>
                </div>
              </div>
              <div class="ms-2"><?= priorityBadge($t['priority']) ?></div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="text-center py-4 text-muted"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><div>All tasks completed!</div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Open Service Tickets -->
  <div class="col-lg-6">
    <div class="cj-card">
      <div class="card-header">
        <span class="card-title"><i class="fas fa-ticket me-2 text-danger"></i>Open Service Tickets</span>
        <a href="service-tickets.php" class="btn btn-sm btn-outline-primary">View All</a>
      </div>
      <div class="card-body p-0">
        <?php if ($openTickets): ?>
        <div class="list-group list-group-flush">
          <?php foreach ($openTickets as $t): ?>
          <a href="service-tickets.php?id=<?= $t['id'] ?>" class="list-group-item list-group-item-action py-2 px-3">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="fw-600 small"><?= h($t['ticket_number']) ?> — <?= h($t['customer_name']) ?></div>
                <div class="text-muted" style="font-size:11px">
                  <?= h($t['issue_category'] ?: 'General') ?> • <?= h($t['engineer_name'] ?? 'Unassigned') ?>
                </div>
              </div>
              <div class="ms-2"><?= statusBadge($t['status']) ?></div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="text-center py-4 text-muted"><i class="fas fa-smile fa-2x mb-2 text-success"></i><div>No open tickets</div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Overdue EMIs -->
  <div class="col-lg-6">
    <div class="cj-card">
      <div class="card-header">
        <span class="card-title"><i class="fas fa-credit-card me-2 text-danger"></i>Overdue EMI Installments</span>
        <a href="agreement-records.php" class="btn btn-sm btn-outline-danger">View All</a>
      </div>
      <div class="card-body p-0">
        <?php if ($overdueEmis): ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>Agreement</th><th>Customer</th><th>EMI</th><th>Due Date</th><th>Amount</th></tr></thead>
            <tbody>
              <?php foreach ($overdueEmis as $e): ?>
              <tr>
                <td><a href="agreement-records.php?id=<?= $e['agreement_id'] ?>"><?= h($e['agreement_number']) ?></a></td>
                <td><?= h($e['customer_name']) ?></td>
                <td>#<?= $e['installment_number'] ?></td>
                <td><span class="text-danger"><?= formatDate($e['due_date']) ?></span></td>
                <td class="fw-600"><?= money($e['amount']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="text-center py-4 text-muted"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><div>No overdue EMIs</div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Low Stock Alert -->
  <div class="col-lg-6">
    <div class="cj-card">
      <div class="card-header">
        <span class="card-title"><i class="fas fa-exclamation-triangle me-2 text-warning"></i>Low Stock Alert</span>
        <a href="stock.php" class="btn btn-sm btn-outline-warning">View Stock</a>
      </div>
      <div class="card-body p-0">
        <?php if ($lowStock): ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead><tr><th>Product</th><th>SKU</th><th>Stock</th><th>Threshold</th></tr></thead>
            <tbody>
              <?php foreach ($lowStock as $p): ?>
              <tr>
                <td><?= h($p['name']) ?></td>
                <td><code><?= h($p['sku']) ?></code></td>
                <td><span class="text-danger fw-600"><?= $p['current_stock'] ?> <?= h($p['unit']) ?></span></td>
                <td><?= $p['low_stock_alert'] ?> <?= h($p['unit']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="text-center py-4 text-muted"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><div>All stock levels OK</div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent Activity -->
  <div class="col-12">
    <div class="cj-card">
      <div class="card-header">
        <span class="card-title"><i class="fas fa-clock-rotate-left me-2 text-info"></i>Recent Activity</span>
      </div>
      <div class="card-body">
        <div class="timeline">
          <?php foreach ($recentActivities as $a): ?>
          <div class="timeline-item">
            <div class="timeline-time"><?= formatDateTime($a['created_at']) ?> — <?= h($a['user_name'] ?? 'System') ?></div>
            <div class="timeline-content"><strong><?= ucwords(str_replace('_',' ',$a['action'])) ?></strong><?= $a['description'] ? ' — ' . h($a['description']) : '' ?></div>
          </div>
          <?php endforeach; ?>
          <?php if (!$recentActivities): ?>
          <div class="text-muted">No recent activity recorded.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- Monthly Summary row -->
<div class="row g-3 mt-2">
  <div class="col-md-4">
    <div class="cj-card">
      <div class="card-body text-center">
        <div class="text-muted small mb-1">Monthly Sales</div>
        <div style="font-size:24px;font-weight:700;color:var(--cj-blue)"><?= money($stats['month_sales']) ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="cj-card">
      <div class="card-body text-center">
        <div class="text-muted small mb-1">Supplier Due</div>
        <div style="font-size:24px;font-weight:700;color:var(--cj-danger)"><?= money($stats['supplier_due']) ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="cj-card">
      <div class="card-body text-center">
        <div class="text-muted small mb-1">In Transit Shipments</div>
        <div style="font-size:24px;font-weight:700;color:var(--cj-warning)"><?= $stats['in_transit'] ?></div>
      </div>
    </div>
  </div>
</div>

<?php pageEnd(); ?>
