<?php
require_once 'includes/layout.php';
requireLogin();

$errors   = [];
$engineers = getEngineers();
$customers = getCustomers();
$tickets   = dbFetchAll("SELECT id, ticket_number, customer_id FROM service_tickets WHERE status NOT IN ('resolved','closed') ORDER BY ticket_number DESC LIMIT 100");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $data = [
            'engineer_id'    => (int)($_POST['engineer_id'] ?? 0),
            'ticket_id'      => (int)($_POST['ticket_id'] ?? 0) ?: null,
            'customer_id'    => (int)($_POST['customer_id'] ?? 0) ?: null,
            'scheduled_date' => sanitize($_POST['scheduled_date'] ?? date('Y-m-d')),
            'scheduled_time' => sanitize($_POST['scheduled_time'] ?? '09:00'),
            'end_time'       => sanitize($_POST['end_time'] ?? '') ?: null,
            'job_type'       => sanitize($_POST['job_type'] ?? ''),
            'address'        => sanitize($_POST['address'] ?? ''),
            'customer_phone' => sanitize($_POST['customer_phone'] ?? ''),
            'machine_model'  => sanitize($_POST['machine_model'] ?? ''),
            'serial_number'  => sanitize($_POST['serial_number'] ?? ''),
            'priority'       => in_array($_POST['priority']??'normal',['low','normal','high','urgent']) ? $_POST['priority'] : 'normal',
            'status'         => sanitize($_POST['status'] ?? 'scheduled'),
            'job_description'=> sanitize($_POST['job_description'] ?? ''),
            'created_by'     => userId(),
        ];
        $errors = validateRequired($data, ['engineer_id','scheduled_date']);
        if (!$errors) {
            $jid = (int)($_POST['id'] ?? 0);
            if ($jid) {
                unset($data['created_by']);
                dbUpdate('engineer_schedule', $data, ['id' => $jid]);
                if ($data['ticket_id']) dbUpdate('service_tickets', ['assigned_engineer' => $data['engineer_id'], 'status' => 'assigned'], ['id' => $data['ticket_id']]);
                redirect('engineer-schedule.php', 'Schedule updated.');
            } else {
                $newId = dbInsert('engineer_schedule', $data);
                if ($data['ticket_id']) dbUpdate('service_tickets', ['assigned_engineer' => $data['engineer_id'], 'status' => 'assigned'], ['id' => $data['ticket_id']]);
                redirect('engineer-schedule.php', 'Job scheduled.');
            }
        }
    } elseif ($act === 'complete') {
        $jid = (int)($_POST['id'] ?? 0);
        if ($jid) {
            dbUpdate('engineer_schedule', [
                'status'          => 'completed',
                'end_time_actual' => date('Y-m-d H:i:s'),
                'engineer_notes'  => sanitize($_POST['engineer_notes'] ?? ''),
                'parts_used'      => sanitize($_POST['parts_used'] ?? ''),
            ], ['id' => $jid]);
            $job = dbFetch('SELECT * FROM engineer_schedule WHERE id=?', [$jid]);
            if ($job['ticket_id']) {
                dbUpdate('service_tickets', ['status' => 'resolved', 'resolved_at' => date('Y-m-d H:i:s'), 'resolution_notes' => sanitize($_POST['engineer_notes'] ?? '')], ['id' => $job['ticket_id']]);
            }
            jsonSuccess(null, 'Job marked complete.');
        }
        jsonError('Invalid request.');
    }
}

// Date range
$viewDate = sanitize($_GET['date'] ?? date('Y-m-d'));
$engFilter = hasRole('ENGINEER') ? dbFetch('SELECT id FROM engineer_profiles WHERE user_id=?', [userId()])['id'] ?? 0 : (int)($_GET['engineer'] ?? 0);

$where  = 'WHERE 1=1';
$params = [];
if ($engFilter) { $where .= ' AND es.engineer_id=?'; $params[] = $engFilter; }

// Weekly schedule
$weekStart = date('Y-m-d', strtotime('monday this week', strtotime($viewDate)));
$weekEnd   = date('Y-m-d', strtotime('sunday this week', strtotime($viewDate)));
$weekJobs  = dbFetchAll(
    "SELECT es.*, u.name AS engineer_name, c.name AS customer_name, c.phone AS customer_phone_2 
     FROM engineer_schedule es 
     JOIN engineer_profiles ep ON ep.id=es.engineer_id
     JOIN users u ON u.id=ep.user_id
     LEFT JOIN customers c ON c.id=es.customer_id
     $where AND es.scheduled_date BETWEEN ? AND ?
     ORDER BY es.scheduled_date ASC, es.scheduled_time ASC",
    [...$params, $weekStart, $weekEnd]
);

// Group by date
$byDate = [];
foreach ($weekJobs as $j) {
    $byDate[$j['scheduled_date']][] = $j;
}

// List view
$allJobs = dbFetchAll(
    "SELECT es.*, u.name AS engineer_name, c.name AS customer_name
     FROM engineer_schedule es 
     JOIN engineer_profiles ep ON ep.id=es.engineer_id
     JOIN users u ON u.id=ep.user_id
     LEFT JOIN customers c ON c.id=es.customer_id
     $where
     ORDER BY es.scheduled_date DESC, es.scheduled_time DESC
     LIMIT 100",
    $params
);

pageStart('Engineer Schedule');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-calendar-days me-2"></i>Engineer Schedule</h1>
  <div class="d-flex gap-2">
    <?php if (!hasRole('ENGINEER')): ?>
    <form class="d-flex gap-2" method="GET">
      <select name="engineer" class="form-select form-select-sm">
        <option value="">All Engineers</option>
        <?php foreach ($engineers as $e): ?>
        <option value="<?= $e['id'] ?>" <?= $e['id']==$engFilter?'selected':'' ?>><?= h($e['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="date" name="date" class="form-control form-control-sm" value="<?= h($viewDate) ?>">
      <button class="btn btn-sm btn-outline-primary">Filter</button>
    </form>
    <?php endif; ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#scheduleModal"><i class="fas fa-plus me-2"></i>New Job</button>
  </div>
</div>

<!-- Weekly Calendar -->
<div class="cj-card mb-4">
  <div class="card-header">
    <span class="card-title">Week: <?= formatDate($weekStart) ?> — <?= formatDate($weekEnd) ?></span>
    <div class="d-flex gap-2">
      <a href="?date=<?= date('Y-m-d', strtotime($weekStart . ' -7 days')) ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-left"></i></a>
      <a href="?date=<?= date('Y-m-d') ?>" class="btn btn-sm btn-outline-primary">Today</a>
      <a href="?date=<?= date('Y-m-d', strtotime($weekEnd . ' +1 day')) ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-right"></i></a>
    </div>
  </div>
  <div class="card-body">
    <div class="schedule-grid">
      <?php
      $today = date('Y-m-d');
      for ($i = 0; $i < 7; $i++):
        $day = date('Y-m-d', strtotime($weekStart . " +$i days"));
        $isToday = $day === $today;
        $dayJobs = $byDate[$day] ?? [];
      ?>
      <div class="schedule-day <?= $isToday ? 'today' : '' ?>">
        <div class="fw-bold mb-1" style="font-size:12px"><?= date('D', strtotime($day)) ?><br><?= date('d', strtotime($day)) ?></div>
        <?php foreach ($dayJobs as $j): ?>
        <div class="schedule-event" title="<?= h($j['engineer_name']) ?>: <?= h($j['customer_name'] ?: $j['job_type']) ?>">
          <?= date('H:i', strtotime($j['scheduled_time'])) ?> <?= h(mb_substr($j['customer_name'] ?: $j['job_type'] ?: 'Job', 0, 12)) ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$dayJobs): ?><div class="text-muted" style="font-size:10px">Free</div><?php endif; ?>
      </div>
      <?php endfor; ?>
    </div>
  </div>
</div>

<!-- Job List -->
<div class="cj-card">
  <div class="card-header"><span class="card-title">All Jobs</span></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr><th>Date/Time</th><th>Engineer</th><th>Customer</th><th>Machine</th><th>Job Type</th><th>Priority</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($allJobs as $j): ?>
          <tr>
            <td>
              <div class="fw-600"><?= formatDate($j['scheduled_date']) ?></div>
              <small class="text-muted"><?= $j['scheduled_time'] ? date('H:i', strtotime($j['scheduled_time'])) : '—' ?></small>
            </td>
            <td><?= h($j['engineer_name']) ?></td>
            <td>
              <div><?= h($j['customer_name'] ?: '—') ?></div>
              <?php if ($j['customer_phone']): ?><small class="text-muted"><?= h($j['customer_phone']) ?></small><?php endif; ?>
            </td>
            <td>
              <div><?= h($j['machine_model'] ?: '—') ?></div>
              <?php if ($j['serial_number']): ?><small class="text-muted">S/N: <?= h($j['serial_number']) ?></small><?php endif; ?>
            </td>
            <td><?= h($j['job_type'] ?: '—') ?></td>
            <td><?= priorityBadge($j['priority']) ?></td>
            <td><?= statusBadge($j['status']) ?></td>
            <td class="table-actions">
              <button class="btn btn-sm btn-outline-primary" onclick="editJob(<?= htmlspecialchars(json_encode($j)) ?>)"><i class="fas fa-edit"></i></button>
              <?php if ($j['status'] !== 'completed' && $j['status'] !== 'cancelled'): ?>
              <button class="btn btn-sm btn-outline-success" onclick="completeJob(<?= $j['id'] ?>)" title="Mark Complete"><i class="fas fa-check"></i></button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$allJobs): ?>
          <tr><td colspan="8" class="text-center py-4 text-muted">No jobs scheduled.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Schedule Modal -->
<div class="modal fade" id="scheduleModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="schedModalLabel">Schedule New Job</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="job_id" value="">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Engineer <span class="text-danger">*</span></label>
              <select name="engineer_id" id="j_eng" class="form-select" required>
                <option value="">-- Select Engineer --</option>
                <?php foreach ($engineers as $e): ?><option value="<?= $e['id'] ?>"><?= h($e['name']) ?> <?= $e['is_available']?'✓':'(busy)' ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Service Ticket</label>
              <select name="ticket_id" id="j_ticket" class="form-select">
                <option value="">-- No Ticket --</option>
                <?php foreach ($tickets as $t): ?><option value="<?= $t['id'] ?>"><?= h($t['ticket_number']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Customer</label>
              <select name="customer_id" id="j_cust" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?> — <?= h($c['phone']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Job Type</label>
              <select name="job_type" id="j_type" class="form-select">
                <?php foreach (['Installation','Preventive Maintenance','Repair','Training','Calibration','Inspection','Other'] as $jt): ?><option value="<?= $jt ?>"><?= $jt ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label">Date <span class="text-danger">*</span></label><input type="date" name="scheduled_date" id="j_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-4"><label class="form-label">Start Time</label><input type="time" name="scheduled_time" id="j_stime" class="form-control" value="09:00"></div>
            <div class="col-md-4"><label class="form-label">End Time</label><input type="time" name="end_time" id="j_etime" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Machine Model</label><input type="text" name="machine_model" id="j_machine" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Serial Number</label><input type="text" name="serial_number" id="j_serial" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Customer Phone</label><input type="text" name="customer_phone" id="j_phone" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Priority</label>
              <select name="priority" id="j_priority" class="form-select">
                <option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option>
              </select>
            </div>
            <div class="col-12"><label class="form-label">Address / Location</label><textarea name="address" id="j_addr" class="form-control" rows="2"></textarea></div>
            <div class="col-12"><label class="form-label">Job Description</label><textarea name="job_description" id="j_desc" class="form-control" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Job</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Complete Modal -->
<div class="modal fade" id="completeModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="complete">
        <input type="hidden" name="id" id="complete_job_id">
        <div class="modal-header"><h5 class="modal-title">Complete Job</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Engineer Notes / Work Done</label><textarea name="engineer_notes" class="form-control" rows="3" required></textarea></div>
          <div class="mb-3"><label class="form-label">Parts Used</label><textarea name="parts_used" class="form-control" rows="2" placeholder="List parts used..."></textarea></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-check me-2"></i>Mark Complete</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editJob(j) {
  document.getElementById('schedModalLabel').textContent = 'Edit Job';
  document.getElementById('job_id').value = j.id;
  document.getElementById('j_eng').value = j.engineer_id || '';
  document.getElementById('j_ticket').value = j.ticket_id || '';
  document.getElementById('j_cust').value = j.customer_id || '';
  document.getElementById('j_type').value = j.job_type || '';
  document.getElementById('j_date').value = j.scheduled_date || '';
  document.getElementById('j_stime').value = j.scheduled_time || '09:00';
  document.getElementById('j_etime').value = j.end_time || '';
  document.getElementById('j_machine').value = j.machine_model || '';
  document.getElementById('j_serial').value = j.serial_number || '';
  document.getElementById('j_phone').value = j.customer_phone || '';
  document.getElementById('j_priority').value = j.priority || 'normal';
  document.getElementById('j_addr').value = j.address || '';
  document.getElementById('j_desc').value = j.job_description || '';
  new bootstrap.Modal(document.getElementById('scheduleModal')).show();
}

function completeJob(id) {
  document.getElementById('complete_job_id').value = id;
  new bootstrap.Modal(document.getElementById('completeModal')).show();
}
</script>

<?php pageEnd(); ?>
