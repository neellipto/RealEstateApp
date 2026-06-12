<?php
require_once 'includes/layout.php';
requireLogin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $data = [
            'title'         => sanitize($_POST['title'] ?? ''),
            'department'    => sanitize($_POST['department'] ?? ''),
            'assigned_to'   => (int)($_POST['assigned_to'] ?? 0) ?: null,
            'assigned_by'   => userId(),
            'priority'      => in_array($_POST['priority']??'normal',['low','normal','high','urgent']) ? $_POST['priority'] : 'normal',
            'due_date'      => $_POST['due_date'] ? sanitize($_POST['due_date']) : null,
            'status'        => in_array($_POST['status']??'new',['new','in_progress','waiting','completed','cancelled']) ? $_POST['status'] : 'new',
            'description'   => sanitize($_POST['description'] ?? ''),
            'notes'         => sanitize($_POST['notes'] ?? ''),
            'follow_up_date'=> $_POST['follow_up_date'] ? sanitize($_POST['follow_up_date']) : null,
            'reminder_flag' => isset($_POST['reminder_flag']) ? 1 : 0,
        ];
        $errors = validateRequired($data, ['title']);
        if (!$errors) {
            $tid = (int)($_POST['id'] ?? 0);
            if ($tid) {
                if ($data['status'] === 'completed' && !dbFetch('SELECT completed_at FROM office_tasks WHERE id=? AND completed_at IS NOT NULL', [$tid])) {
                    $data['completed_at'] = date('Y-m-d H:i:s');
                }
                dbUpdate('office_tasks', $data, ['id' => $tid]);
                logActivity('update', 'tasks', $tid, "Updated task: {$data['title']}");
                redirect('office-tasks.php', 'Task updated.');
            } else {
                $data['task_number'] = generateCode('TASK', 'office_tasks', 'task_number');
                $data['created_by']  = userId();
                $newId = dbInsert('office_tasks', $data);
                logActivity('create', 'tasks', $newId, "Created task: {$data['title']}");
                redirect('office-tasks.php', 'Task created.');
            }
        }
    } elseif ($act === 'comment') {
        $tid = (int)($_POST['task_id'] ?? 0);
        $comment = sanitize($_POST['comment'] ?? '');
        if ($tid && $comment) {
            dbInsert('task_comments', ['task_id' => $tid, 'user_id' => userId(), 'comment' => $comment]);
            jsonSuccess(null, 'Comment added.');
        }
        jsonError('Comment cannot be empty.');
    } elseif ($act === 'quick_status') {
        $tid    = (int)($_POST['id'] ?? 0);
        $status = sanitize($_POST['status'] ?? '');
        if ($tid && in_array($status, ['new','in_progress','waiting','completed','cancelled'])) {
            $upd = ['status' => $status];
            if ($status === 'completed') $upd['completed_at'] = date('Y-m-d H:i:s');
            dbUpdate('office_tasks', $upd, ['id' => $tid]);
            jsonSuccess(null, 'Status updated.');
        }
        jsonError('Invalid request.');
    }
}

$filter = $_GET['filter'] ?? 'all';
$today  = date('Y-m-d');

$where = '';
if ($filter === 'today')    $where = "AND DATE(t.due_date) = '$today'";
elseif ($filter === 'overdue') $where = "AND t.due_date < NOW() AND t.status NOT IN ('completed','cancelled')";
elseif ($filter === 'my')   $where = "AND t.assigned_to = " . userId();
elseif ($filter === 'open') $where = "AND t.status NOT IN ('completed','cancelled')";

// Non-owners see only their tasks
if (!hasRole('OWNER','ADMIN','MANAGER')) {
    $where .= " AND t.assigned_to = " . userId();
}

$tasks = dbFetchAll(
    "SELECT t.*, ua.name AS assigned_name, ub.name AS creator_name 
     FROM office_tasks t
     LEFT JOIN users ua ON ua.id = t.assigned_to
     LEFT JOIN users ub ON ub.id = t.created_by
     WHERE 1=1 $where
     ORDER BY FIELD(t.priority,'urgent','high','normal','low'), t.due_date ASC
     LIMIT 200"
);

$users = getUsers();

$viewId   = (int)($_GET['id'] ?? 0);
$viewTask = null;
$comments = [];
if ($viewId) {
    $viewTask = dbFetch("SELECT t.*, ua.name AS assigned_name FROM office_tasks t LEFT JOIN users ua ON ua.id=t.assigned_to WHERE t.id=?", [$viewId]);
    $comments = dbFetchAll("SELECT tc.*, u.name AS user_name FROM task_comments tc JOIN users u ON u.id=tc.user_id WHERE tc.task_id=? ORDER BY tc.created_at ASC", [$viewId]);
}

$counts = [
    'all'     => (int)(dbFetch("SELECT COUNT(*) AS v FROM office_tasks")['v'] ?? 0),
    'open'    => (int)(dbFetch("SELECT COUNT(*) AS v FROM office_tasks WHERE status NOT IN ('completed','cancelled')")['v'] ?? 0),
    'today'   => (int)(dbFetch("SELECT COUNT(*) AS v FROM office_tasks WHERE DATE(due_date)='$today'")['v'] ?? 0),
    'overdue' => (int)(dbFetch("SELECT COUNT(*) AS v FROM office_tasks WHERE due_date < NOW() AND status NOT IN ('completed','cancelled')")['v'] ?? 0),
];

pageStart('Office Tasks');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-list-check me-2"></i>Office Tasks</h1>
  <?php if (hasRole('OWNER','ADMIN','MANAGER')): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#taskModal">
    <i class="fas fa-plus me-2"></i>New Task
  </button>
  <?php endif; ?>
</div>

<!-- Filter Tabs -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $filter==='all'?'active':'' ?>" href="?filter=all">All <span class="badge bg-secondary ms-1"><?= $counts['all'] ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $filter==='open'?'active':'' ?>" href="?filter=open">Open <span class="badge bg-warning ms-1"><?= $counts['open'] ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $filter==='today'?'active':'' ?>" href="?filter=today">Today <span class="badge bg-info ms-1"><?= $counts['today'] ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $filter==='overdue'?'active':'' ?>" href="?filter=overdue">Overdue <span class="badge bg-danger ms-1"><?= $counts['overdue'] ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $filter==='my'?'active':'' ?>" href="?filter=my">My Tasks</a></li>
</ul>

<div class="row g-4">
  <div class="<?= $viewTask ? 'col-lg-7' : 'col-12' ?>">
    <div class="cj-card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0" id="tasksTable">
            <thead>
              <tr><th>Task</th><th>Assigned To</th><th>Priority</th><th>Due</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($tasks as $t): ?>
              <?php $overdue = $t['due_date'] && strtotime($t['due_date']) < time() && !in_array($t['status'],['completed','cancelled']); ?>
              <tr id="row-<?= $t['id'] ?>" class="<?= $overdue ? 'table-warning' : '' ?>">
                <td>
                  <div class="fw-600"><?= h($t['title']) ?></div>
                  <div class="text-muted small"><?= h($t['department'] ?: '') ?> <?= $t['task_number'] ? '· '.$t['task_number'] : '' ?></div>
                </td>
                <td><?= h($t['assigned_name'] ?? 'Unassigned') ?></td>
                <td><?= priorityBadge($t['priority']) ?></td>
                <td>
                  <?php if ($t['due_date']): ?>
                  <span class="<?= $overdue ? 'text-danger fw-600' : '' ?>"><?= formatDateTime($t['due_date'], 'd M H:i') ?></span>
                  <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td>
                  <select class="form-select form-select-sm status-select" style="min-width:130px" 
                          onchange="quickStatus(<?= $t['id'] ?>, this.value, this)">
                    <?php foreach (['new','in_progress','waiting','completed','cancelled'] as $s): ?>
                    <option value="<?= $s ?>" <?= $t['status']===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td class="table-actions">
                  <a href="?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-info" title="View"><i class="fas fa-eye"></i></a>
                  <?php if (hasRole('OWNER','ADMIN','MANAGER') || $t['assigned_to'] == userId()): ?>
                  <button class="btn btn-sm btn-outline-primary" onclick="editTask(<?= htmlspecialchars(json_encode($t)) ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$tasks): ?>
              <tr><td colspan="6" class="text-center py-4 text-muted">No tasks found for this filter.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <?php if ($viewTask): ?>
  <div class="col-lg-5">
    <div class="cj-card">
      <div class="card-header">
        <span class="card-title">Task Detail</span>
        <a href="office-tasks.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times"></i></a>
      </div>
      <div class="card-body">
        <h5><?= h($viewTask['title']) ?></h5>
        <div class="mb-3">
          <?= priorityBadge($viewTask['priority']) ?> <?= statusBadge($viewTask['status']) ?>
        </div>
        <table class="table table-sm">
          <tr><td class="text-muted">Assigned To</td><td><?= h($viewTask['assigned_name'] ?? '—') ?></td></tr>
          <tr><td class="text-muted">Department</td><td><?= h($viewTask['department'] ?: '—') ?></td></tr>
          <tr><td class="text-muted">Due Date</td><td><?= $viewTask['due_date'] ? formatDateTime($viewTask['due_date']) : '—' ?></td></tr>
          <tr><td class="text-muted">Follow-up</td><td><?= $viewTask['follow_up_date'] ? formatDate($viewTask['follow_up_date']) : '—' ?></td></tr>
        </table>
        <?php if ($viewTask['description']): ?>
        <div class="mb-3"><strong>Description:</strong><div><?= nl2br(h($viewTask['description'])) ?></div></div>
        <?php endif; ?>
        <?php if ($viewTask['notes']): ?>
        <div class="mb-3"><strong>Notes:</strong><div><?= nl2br(h($viewTask['notes'])) ?></div></div>
        <?php endif; ?>

        <h6 class="mt-3">Comments (<?= count($comments) ?>)</h6>
        <div class="timeline mb-3">
          <?php foreach ($comments as $c): ?>
          <div class="timeline-item">
            <div class="timeline-time"><?= h($c['user_name']) ?> · <?= formatDateTime($c['created_at']) ?></div>
            <div class="timeline-content"><?= nl2br(h($c['comment'])) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <form id="commentForm">
          <input type="hidden" name="action" value="comment">
          <input type="hidden" name="task_id" value="<?= $viewTask['id'] ?>">
          <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
          <div class="input-group">
            <textarea name="comment" class="form-control form-control-sm" rows="2" placeholder="Add a comment..."></textarea>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-paper-plane"></i></button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Task Modal -->
<div class="modal fade" id="taskModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="taskModalLabel">New Task</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="task_id" value="">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Task Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="t_title" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Department</label>
              <select name="department" id="t_dept" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach (['Sales','Accounts','Service','HR','Management','Procurement','IT','Other'] as $d): ?>
                <option value="<?= $d ?>"><?= $d ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Assign To</label>
              <select name="assigned_to" id="t_assign" class="form-select">
                <option value="">-- Select Person --</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>"><?= h($u['name']) ?> (<?= $u['role_name'] ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Priority</label>
              <select name="priority" id="t_priority" class="form-select">
                <option value="low">Low</option>
                <option value="normal" selected>Normal</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Due Date/Time</label>
              <input type="datetime-local" name="due_date" id="t_due" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" id="t_status" class="form-select">
                <option value="new">New</option>
                <option value="in_progress">In Progress</option>
                <option value="waiting">Waiting</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea name="description" id="t_desc" class="form-control" rows="3"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Follow-up Date</label>
              <input type="date" name="follow_up_date" id="t_followup" class="form-control">
            </div>
            <div class="col-md-6 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="reminder_flag" id="t_reminder" value="1">
                <label class="form-check-label" for="t_reminder">Set Reminder</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Task</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editTask(t) {
  document.getElementById('taskModalLabel').textContent = 'Edit Task';
  document.getElementById('task_id').value = t.id;
  document.getElementById('t_title').value = t.title || '';
  document.getElementById('t_dept').value = t.department || '';
  document.getElementById('t_assign').value = t.assigned_to || '';
  document.getElementById('t_priority').value = t.priority || 'normal';
  document.getElementById('t_due').value = t.due_date ? t.due_date.replace(' ','T') : '';
  document.getElementById('t_status').value = t.status || 'new';
  document.getElementById('t_desc').value = t.description || '';
  document.getElementById('t_followup').value = t.follow_up_date || '';
  document.getElementById('t_reminder').checked = t.reminder_flag == 1;
  new bootstrap.Modal(document.getElementById('taskModal')).show();
}

async function quickStatus(id, status, el) {
  const r = await CJ.post('office-tasks.php', { action: 'quick_status', id, status });
  if (r.ok) CJ.flash('Status updated to: ' + status.replace('_',' '), 'success');
  else { CJ.flash(r.message, 'danger'); }
}

document.getElementById('commentForm')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const r = await CJ.post('office-tasks.php', new FormData(e.target));
  if (r.ok) { CJ.flash('Comment added!'); e.target.reset(); setTimeout(() => location.reload(), 1000); }
  else CJ.flash(r.message, 'danger');
});
</script>

<?php pageEnd(); ?>
