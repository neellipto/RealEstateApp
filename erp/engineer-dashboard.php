<?php
require_once 'includes/layout.php';
requireLogin();

// Find engineer profile
$engProfile = null;
if (hasRole('ENGINEER')) {
    $engProfile = dbFetch('SELECT ep.*, u.name, u.phone FROM engineer_profiles ep JOIN users u ON u.id=ep.user_id WHERE ep.user_id=?', [userId()]);
}

$today = date('Y-m-d');
$engId = $engProfile['id'] ?? ((int)($_GET['engineer_id'] ?? 0));

$todayJobs = dbFetchAll(
    "SELECT es.*, c.name AS customer_name, c.phone AS cphone, c.address AS caddress FROM engineer_schedule es LEFT JOIN customers c ON c.id=es.customer_id WHERE es.engineer_id=? AND es.scheduled_date=? ORDER BY es.scheduled_time ASC",
    [$engId, $today]
);

$pendingJobs = dbFetchAll(
    "SELECT es.*, c.name AS customer_name FROM engineer_schedule es LEFT JOIN customers c ON c.id=es.customer_id WHERE es.engineer_id=? AND es.status IN ('scheduled') AND es.scheduled_date >= ? ORDER BY es.scheduled_date ASC LIMIT 20",
    [$engId, $today]
);

$completedThisMonth = (int)(dbFetch("SELECT COUNT(*) AS v FROM engineer_schedule WHERE engineer_id=? AND status='completed' AND scheduled_date >= ?", [$engId, date('Y-m-01')])['v'] ?? 0);
$totalCompleted = (int)(dbFetch("SELECT COUNT(*) AS v FROM engineer_schedule WHERE engineer_id=? AND status='completed'", [$engId])['v'] ?? 0);

pageStart('Engineer Dashboard');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-hard-hat me-2"></i>Engineer Dashboard</h1>
  <a href="engineer-schedule.php" class="btn btn-outline-primary"><i class="fas fa-calendar-days me-2"></i>Schedule</a>
</div>

<?php if ($engProfile): ?>
<div class="cj-card mb-4">
  <div class="card-body">
    <div class="row align-items-center">
      <div class="col-auto">
        <div class="user-avatar" style="width:60px;height:60px;font-size:22px"><?= strtoupper(substr($engProfile['name'],0,2)) ?></div>
      </div>
      <div class="col">
        <h4 class="mb-1"><?= h($engProfile['name']) ?></h4>
        <div class="text-muted"><?= h($engProfile['specialization'] ?: 'Field Engineer') ?></div>
        <div class="text-muted small"><?= h($engProfile['service_area'] ?: '') ?></div>
      </div>
      <div class="col-auto">
        <span class="badge <?= $engProfile['is_available'] ? 'bg-success' : 'bg-danger' ?> fs-6">
          <?= $engProfile['is_available'] ? 'Available' : 'Busy' ?>
        </span>
        <div class="mt-1">
          <form method="POST" action="api/index.php" style="display:inline">
            <input type="hidden" name="module" value="engineer">
            <input type="hidden" name="action" value="toggle_availability">
            <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
            <button type="submit" class="btn btn-sm btn-outline-<?= $engProfile['is_available'] ? 'danger' : 'success' ?>">
              <?= $engProfile['is_available'] ? 'Set Busy' : 'Set Available' ?>
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="stat-card blue"><div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
      <div><div class="stat-value"><?= count($todayJobs) ?></div><div class="stat-label">Today's Jobs</div></div></div>
  </div>
  <div class="col-md-3">
    <div class="stat-card orange"><div class="stat-icon"><i class="fas fa-clock"></i></div>
      <div><div class="stat-value"><?= count($pendingJobs) ?></div><div class="stat-label">Pending Jobs</div></div></div>
  </div>
  <div class="col-md-3">
    <div class="stat-card green"><div class="stat-icon"><i class="fas fa-check-circle"></i></div>
      <div><div class="stat-value"><?= $completedThisMonth ?></div><div class="stat-label">Completed This Month</div></div></div>
  </div>
  <div class="col-md-3">
    <div class="stat-card teal"><div class="stat-icon"><i class="fas fa-trophy"></i></div>
      <div><div class="stat-value"><?= $totalCompleted ?></div><div class="stat-label">Total Completed</div></div></div>
  </div>
</div>

<!-- Today's Jobs -->
<div class="cj-card mb-4">
  <div class="card-header"><span class="card-title"><i class="fas fa-calendar-day me-2 text-primary"></i>Today's Jobs — <?= formatDate($today) ?></span></div>
  <div class="card-body p-0">
    <?php if ($todayJobs): ?>
    <div class="list-group list-group-flush">
      <?php foreach ($todayJobs as $j): ?>
      <div class="list-group-item py-3">
        <div class="row align-items-center">
          <div class="col">
            <div class="fw-bold"><?= h($j['customer_name'] ?: 'Customer TBD') ?></div>
            <div class="text-muted small"><?= h($j['cphone'] ?: '') ?> · <?= h($j['machine_model'] ?: 'Machine not specified') ?></div>
            <?php if ($j['caddress']): ?><div class="text-muted small"><i class="fas fa-map-marker-alt me-1"></i><?= h($j['caddress']) ?></div><?php endif; ?>
            <?php if ($j['job_description']): ?><div class="mt-1 small"><?= h($j['job_description']) ?></div><?php endif; ?>
          </div>
          <div class="col-auto">
            <div class="text-center">
              <div class="fw-bold"><?= date('H:i', strtotime($j['scheduled_time'])) ?></div>
              <?= priorityBadge($j['priority']) ?>
              <div class="mt-1"><?= statusBadge($j['status']) ?></div>
            </div>
          </div>
          <div class="col-auto">
            <?php if ($j['status'] !== 'completed'): ?>
            <button class="btn btn-success btn-sm" onclick="completeJob(<?= $j['id'] ?>)">
              <i class="fas fa-check me-1"></i>Complete
            </button>
            <?php else: ?>
            <span class="badge bg-success fs-6">Done</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="text-center py-5 text-muted">
      <i class="fas fa-smile fa-3x mb-3 text-success"></i>
      <div>No jobs scheduled for today!</div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Upcoming Jobs -->
<?php if ($pendingJobs): ?>
<div class="cj-card">
  <div class="card-header"><span class="card-title"><i class="fas fa-calendar-week me-2"></i>Upcoming Jobs</span></div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Date</th><th>Customer</th><th>Machine</th><th>Type</th><th>Priority</th></tr></thead>
      <tbody>
        <?php foreach ($pendingJobs as $j): ?>
        <tr>
          <td><?= formatDate($j['scheduled_date']) ?> <?= $j['scheduled_time'] ? date('H:i', strtotime($j['scheduled_time'])) : '' ?></td>
          <td><?= h($j['customer_name'] ?: '—') ?></td>
          <td><?= h($j['machine_model'] ?: '—') ?></td>
          <td><?= h($j['job_type'] ?: '—') ?></td>
          <td><?= priorityBadge($j['priority']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Complete Modal -->
<div class="modal fade" id="completeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="engineer-schedule.php">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="complete">
        <input type="hidden" name="id" id="complete_job_id">
        <div class="modal-header"><h5 class="modal-title">Complete Job</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Work Done / Notes <span class="text-danger">*</span></label><textarea name="engineer_notes" class="form-control" rows="3" required placeholder="Describe what was done..."></textarea></div>
          <div class="mb-3"><label class="form-label">Parts Used</label><textarea name="parts_used" class="form-control" rows="2" placeholder="List any parts used..."></textarea></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-check me-2"></i>Submit & Complete</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function completeJob(id) {
  document.getElementById('complete_job_id').value = id;
  new bootstrap.Modal(document.getElementById('completeModal')).show();
}
</script>

<?php pageEnd(); ?>
