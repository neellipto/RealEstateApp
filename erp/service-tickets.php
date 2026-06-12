<?php
require_once 'includes/layout.php';
requireLogin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $data = [
            'customer_id'       => (int)($_POST['customer_id'] ?? 0),
            'serial_number'     => sanitize($_POST['serial_number'] ?? ''),
            'warranty_start'    => $_POST['warranty_start'] ?: null,
            'warranty_end'      => $_POST['warranty_end'] ?: null,
            'is_warranty'       => isset($_POST['is_warranty']) ? 1 : 0,
            'issue_category'    => sanitize($_POST['issue_category'] ?? ''),
            'problem_description'=> sanitize($_POST['problem_description'] ?? ''),
            'priority'          => in_array($_POST['priority']??'normal',['low','normal','high','urgent']) ? $_POST['priority'] : 'normal',
            'status'            => in_array($_POST['status']??'open',['open','assigned','in_progress','parts_required','resolved','closed']) ? $_POST['status'] : 'open',
            'assigned_engineer' => (int)($_POST['assigned_engineer'] ?? 0) ?: null,
            'service_cost'      => (float)($_POST['service_cost'] ?? 0),
        ];

        $errors = validateRequired($data, ['customer_id','problem_description']);

        if (!$errors) {
            $tid = (int)($_POST['id'] ?? 0);
            if ($tid) {
                dbUpdate('service_tickets', $data, ['id' => $tid]);
                logActivity('update', 'service_tickets', $tid, "Updated ticket");
                redirect('service-tickets.php', 'Service ticket updated.');
            } else {
                $data['ticket_number'] = generateCode('TKT', 'service_tickets', 'ticket_number', 6);
                $data['opened_at']     = date('Y-m-d H:i:s');
                $data['created_by']    = userId();
                if ($data['assigned_engineer']) $data['assigned_at'] = date('Y-m-d H:i:s');
                $newId = dbInsert('service_tickets', $data);
                logActivity('create', 'service_tickets', $newId, "Created ticket {$data['ticket_number']}");
                redirect('service-tickets.php', 'Service ticket created: ' . $data['ticket_number']);
            }
        }
    } elseif ($act === 'resolve') {
        $tid = (int)($_POST['id'] ?? 0);
        if ($tid) {
            dbUpdate('service_tickets', [
                'status'           => 'resolved',
                'resolution_notes' => sanitize($_POST['resolution_notes'] ?? ''),
                'resolved_at'      => date('Y-m-d H:i:s'),
            ], ['id' => $tid]);
            jsonSuccess(null, 'Ticket resolved.');
        }
    }
}

$filter = $_GET['filter'] ?? 'open';
$filterWhere = match($filter) {
    'open'       => "WHERE st.status NOT IN ('resolved','closed')",
    'resolved'   => "WHERE st.status IN ('resolved','closed')",
    'warranty'   => "WHERE st.is_warranty=1 AND st.status NOT IN ('resolved','closed')",
    'my'         => "WHERE st.assigned_engineer = " . userId() . " AND st.status NOT IN ('resolved','closed')",
    default      => 'WHERE 1=1',
};

$tickets = dbFetchAll(
    "SELECT st.*, c.name AS customer_name, c.phone AS customer_phone, 
            u.name AS engineer_name, p.name AS product_name
     FROM service_tickets st
     LEFT JOIN customers c ON c.id=st.customer_id
     LEFT JOIN users u ON u.id=st.assigned_engineer
     LEFT JOIN products p ON p.id=st.product_id
     $filterWhere
     ORDER BY FIELD(st.priority,'urgent','high','normal','low'), st.created_at DESC
     LIMIT 200"
);

$customers = getCustomers();
$engineers = getEngineers();
$products  = getProducts();

$viewId = (int)($_GET['id'] ?? 0);
$viewTicket = null;
if ($viewId) {
    $viewTicket = dbFetch(
        "SELECT st.*, c.name AS customer_name, c.phone AS cphone, c.address AS caddress,
                u.name AS engineer_name
         FROM service_tickets st
         LEFT JOIN customers c ON c.id=st.customer_id
         LEFT JOIN users u ON u.id=st.assigned_engineer
         WHERE st.id=?", [$viewId]
    );
    $partsUsed = dbFetchAll("SELECT tp.*, p.name AS part_name FROM ticket_parts_used tp JOIN products p ON p.id=tp.product_id WHERE tp.ticket_id=?", [$viewId]);
}

$counts = [
    'open'    => (int)(dbFetch("SELECT COUNT(*) AS v FROM service_tickets WHERE status NOT IN ('resolved','closed')")['v'] ?? 0),
    'resolved'=> (int)(dbFetch("SELECT COUNT(*) AS v FROM service_tickets WHERE status IN ('resolved','closed')")['v'] ?? 0),
    'warranty'=> (int)(dbFetch("SELECT COUNT(*) AS v FROM service_tickets WHERE is_warranty=1 AND status NOT IN ('resolved','closed')")['v'] ?? 0),
];

pageStart('Service Tickets');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-ticket me-2"></i>Service Tickets</h1>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#ticketModal">
    <i class="fas fa-plus me-2"></i>New Ticket
  </button>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $filter==='open'?'active':'' ?>" href="?filter=open">Open <span class="badge bg-warning ms-1"><?= $counts['open'] ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $filter==='resolved'?'active':'' ?>" href="?filter=resolved">Resolved <span class="badge bg-success ms-1"><?= $counts['resolved'] ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $filter==='warranty'?'active':'' ?>" href="?filter=warranty">Warranty <span class="badge bg-info ms-1"><?= $counts['warranty'] ?></span></a></li>
  <?php if (hasRole('ENGINEER')): ?>
  <li class="nav-item"><a class="nav-link <?= $filter==='my'?'active':'' ?>" href="?filter=my">My Jobs</a></li>
  <?php endif; ?>
  <li class="nav-item"><a class="nav-link <?= $filter==='all'?'active':'' ?>" href="?filter=all">All</a></li>
</ul>

<div class="row g-4">
  <div class="<?= $viewTicket ? 'col-lg-7' : 'col-12' ?>">
    <div class="cj-card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr><th>Ticket #</th><th>Customer</th><th>Issue</th><th>Priority</th><th>Engineer</th><th>Warranty</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($tickets as $t): ?>
              <tr>
                <td><a href="?id=<?= $t['id'] ?>" class="fw-600"><?= h($t['ticket_number']) ?></a></td>
                <td>
                  <div><?= h($t['customer_name']) ?></div>
                  <div class="text-muted small"><?= h($t['customer_phone']) ?></div>
                </td>
                <td>
                  <div><?= h($t['issue_category'] ?: 'General') ?></div>
                  <div class="text-muted small" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($t['problem_description']) ?></div>
                </td>
                <td><?= priorityBadge($t['priority']) ?></td>
                <td><?= h($t['engineer_name'] ?? '<span class="text-warning">Unassigned</span>') ?></td>
                <td><?= $t['is_warranty'] ? '<span class="badge bg-info">Warranty</span>' : '<span class="badge bg-secondary">Out-of-Warranty</span>' ?></td>
                <td><?= statusBadge($t['status']) ?></td>
                <td class="table-actions">
                  <a href="?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-info"><i class="fas fa-eye"></i></a>
                  <button class="btn btn-sm btn-outline-primary" onclick="editTicket(<?= htmlspecialchars(json_encode($t)) ?>)"><i class="fas fa-edit"></i></button>
                  <?php if ($t['status'] !== 'resolved' && $t['status'] !== 'closed'): ?>
                  <button class="btn btn-sm btn-outline-success" onclick="resolveTicket(<?= $t['id'] ?>)"><i class="fas fa-check"></i></button>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$tickets): ?>
              <tr><td colspan="8" class="text-center py-4 text-muted">No tickets found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <?php if ($viewTicket): ?>
  <div class="col-lg-5">
    <div class="cj-card">
      <div class="card-header">
        <span class="card-title"><?= h($viewTicket['ticket_number']) ?></span>
        <div>
          <button class="btn btn-sm btn-outline-primary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
          <a href="service-tickets.php" class="btn btn-sm btn-outline-secondary ms-1"><i class="fas fa-times"></i></a>
        </div>
      </div>
      <div class="card-body">
        <div class="mb-2"><?= priorityBadge($viewTicket['priority']) ?> <?= statusBadge($viewTicket['status']) ?> <?= $viewTicket['is_warranty'] ? '<span class="badge bg-info">WARRANTY</span>' : '' ?></div>
        <table class="table table-sm">
          <tr><td class="text-muted">Customer</td><td><strong><?= h($viewTicket['customer_name']) ?></strong><br><small><?= h($viewTicket['cphone']) ?></small></td></tr>
          <tr><td class="text-muted">Serial No.</td><td><?= h($viewTicket['serial_number'] ?: '—') ?></td></tr>
          <tr><td class="text-muted">Engineer</td><td><?= h($viewTicket['engineer_name'] ?? '<em>Unassigned</em>') ?></td></tr>
          <tr><td class="text-muted">Issue</td><td><?= h($viewTicket['issue_category'] ?: '—') ?></td></tr>
          <tr><td class="text-muted">Warranty</td><td><?= $viewTicket['warranty_start'] ? formatDate($viewTicket['warranty_start']) . ' – ' . formatDate($viewTicket['warranty_end']) : '—' ?></td></tr>
          <tr><td class="text-muted">Opened</td><td><?= formatDateTime($viewTicket['opened_at'] ?: $viewTicket['created_at']) ?></td></tr>
          <tr><td class="text-muted">Service Cost</td><td class="fw-600"><?= money($viewTicket['service_cost']) ?></td></tr>
        </table>
        <div class="mb-3">
          <strong>Problem:</strong>
          <div class="mt-1 p-2 bg-light rounded small"><?= nl2br(h($viewTicket['problem_description'])) ?></div>
        </div>
        <?php if ($viewTicket['resolution_notes']): ?>
        <div class="mb-3">
          <strong>Resolution:</strong>
          <div class="mt-1 p-2 bg-success bg-opacity-10 rounded small"><?= nl2br(h($viewTicket['resolution_notes'])) ?></div>
        </div>
        <?php endif; ?>
        <?php if ($partsUsed): ?>
        <strong>Parts Used:</strong>
        <table class="table table-sm mt-1">
          <thead><tr><th>Part</th><th>Qty</th><th>Cost</th><th>Warranty</th></tr></thead>
          <tbody>
            <?php foreach ($partsUsed as $p): ?>
            <tr>
              <td><?= h($p['part_name']) ?></td>
              <td><?= $p['quantity'] ?></td>
              <td><?= money($p['unit_cost']) ?></td>
              <td><?= $p['is_warranty'] ? '<span class="badge bg-info">W</span>' : '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Ticket Modal -->
<div class="modal fade" id="ticketModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="ticketModalLabel">New Service Ticket</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="ticket_id" value="">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Customer <span class="text-danger">*</span></label>
              <select name="customer_id" id="st_customer" class="form-select" required>
                <option value="">-- Select Customer --</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>"><?= h($c['name']) ?> — <?= h($c['phone']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Serial Number</label>
              <input type="text" name="serial_number" id="st_serial" class="form-control" placeholder="Machine serial #">
            </div>
            <div class="col-md-6">
              <label class="form-label">Warranty Start</label>
              <input type="date" name="warranty_start" id="st_ws" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Warranty End</label>
              <input type="date" name="warranty_end" id="st_we" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Issue Category</label>
              <select name="issue_category" id="st_cat" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach (['Mainboard','Headboard','Servo Motor','Driver','Printhead','Ink System','Software','Hardware','Calibration','Other'] as $cat): ?>
                <option value="<?= $cat ?>"><?= $cat ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Priority</label>
              <select name="priority" id="st_priority" class="form-select">
                <option value="low">Low</option>
                <option value="normal" selected>Normal</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Problem Description <span class="text-danger">*</span></label>
              <textarea name="problem_description" id="st_problem" class="form-control" rows="3" required></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Assign Engineer</label>
              <select name="assigned_engineer" id="st_eng" class="form-select">
                <option value="">-- Assign Later --</option>
                <?php foreach ($engineers as $e): ?>
                <option value="<?= $e['id'] ?>"><?= h($e['name']) ?> <?= $e['is_available'] ? '✓' : '(busy)' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Status</label>
              <select name="status" id="st_status" class="form-select">
                <option value="open">Open</option>
                <option value="assigned">Assigned</option>
                <option value="in_progress">In Progress</option>
                <option value="parts_required">Parts Required</option>
                <option value="resolved">Resolved</option>
                <option value="closed">Closed</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Service Cost (৳)</label>
              <input type="number" name="service_cost" id="st_cost" class="form-control" value="0" min="0" step="0.01">
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_warranty" id="st_warranty" value="1">
                <label class="form-check-label" for="st_warranty">Under Warranty</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Ticket</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Resolve Modal -->
<div class="modal fade" id="resolveModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="resolve">
        <input type="hidden" name="id" id="resolve_id">
        <div class="modal-header"><h5 class="modal-title">Resolve Ticket</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <label class="form-label">Resolution Notes</label>
          <textarea name="resolution_notes" class="form-control" rows="4" placeholder="Describe what was done to resolve the issue..."></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-check me-2"></i>Mark Resolved</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editTicket(t) {
  document.getElementById('ticketModalLabel').textContent = 'Edit Ticket: ' + t.ticket_number;
  document.getElementById('ticket_id').value = t.id;
  document.getElementById('st_customer').value = t.customer_id || '';
  document.getElementById('st_serial').value = t.serial_number || '';
  document.getElementById('st_ws').value = t.warranty_start || '';
  document.getElementById('st_we').value = t.warranty_end || '';
  document.getElementById('st_cat').value = t.issue_category || '';
  document.getElementById('st_priority').value = t.priority || 'normal';
  document.getElementById('st_problem').value = t.problem_description || '';
  document.getElementById('st_eng').value = t.assigned_engineer || '';
  document.getElementById('st_status').value = t.status || 'open';
  document.getElementById('st_cost').value = t.service_cost || 0;
  document.getElementById('st_warranty').checked = t.is_warranty == 1;
  new bootstrap.Modal(document.getElementById('ticketModal')).show();
}

function resolveTicket(id) {
  document.getElementById('resolve_id').value = id;
  new bootstrap.Modal(document.getElementById('resolveModal')).show();
}
</script>

<?php pageEnd(); ?>
