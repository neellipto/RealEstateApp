<?php
require_once 'includes/layout.php';
requireLogin();

$errors = [];
$customers = getCustomers();
$products  = getProducts();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $data = [
            'customer_id'   => (int)($_POST['customer_id'] ?? 0),
            'product_id'    => (int)($_POST['product_id'] ?? 0),
            'serial_number' => sanitize($_POST['serial_number'] ?? ''),
            'purchase_date' => sanitize($_POST['purchase_date'] ?? date('Y-m-d')),
            'warranty_start'=> sanitize($_POST['warranty_start'] ?? date('Y-m-d')),
            'warranty_end'  => sanitize($_POST['warranty_end'] ?? ''),
            'warranty_type' => in_array($_POST['warranty_type']??'standard',['standard','amc','extended']) ? $_POST['warranty_type'] : 'standard',
            'covered_parts' => sanitize($_POST['covered_parts'] ?? 'Mainboard, Headboard, Servo Motor, Driver'),
            'excluded_parts'=> sanitize($_POST['excluded_parts'] ?? 'Printhead and small spare parts'),
            'status'        => 'active',
            'notes'         => sanitize($_POST['notes'] ?? ''),
        ];
        $errors = validateRequired($data, ['customer_id','product_id','warranty_start','warranty_end']);
        if (!$errors) {
            $wid = (int)($_POST['id'] ?? 0);
            if ($wid) {
                dbUpdate('warranty_register', $data, ['id' => $wid]);
                redirect('warranty.php', 'Warranty record updated.');
            } else {
                dbInsert('warranty_register', $data);
                // Update serial number record
                if ($data['serial_number']) {
                    dbQuery('UPDATE serial_numbers SET warranty_start=?, warranty_end=?, customer_id=? WHERE serial_number=?',
                        [$data['warranty_start'], $data['warranty_end'], $data['customer_id'], $data['serial_number']]);
                }
                redirect('warranty.php', 'Warranty registered.');
            }
        }
    }
}

$filter = sanitize($_GET['filter'] ?? 'active');
$where  = match($filter) {
    'active'  => "WHERE wr.status='active' AND wr.warranty_end >= CURDATE()",
    'expired' => "WHERE wr.status='expired' OR (wr.status='active' AND wr.warranty_end < CURDATE())",
    'expiring'=> "WHERE wr.status='active' AND wr.warranty_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
    default   => 'WHERE 1=1',
};

$warranties = dbFetchAll(
    "SELECT wr.*, c.name AS customer_name, c.phone, p.name AS product_name FROM warranty_register wr
     JOIN customers c ON c.id=wr.customer_id JOIN products p ON p.id=wr.product_id
     $where ORDER BY wr.warranty_end ASC LIMIT 200"
);

$counts = [
    'active'   => (int)(dbFetch("SELECT COUNT(*) AS v FROM warranty_register WHERE status='active' AND warranty_end >= CURDATE()")['v'] ?? 0),
    'expired'  => (int)(dbFetch("SELECT COUNT(*) AS v FROM warranty_register WHERE status='expired' OR (status='active' AND warranty_end < CURDATE())")['v'] ?? 0),
    'expiring' => (int)(dbFetch("SELECT COUNT(*) AS v FROM warranty_register WHERE status='active' AND warranty_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")['v'] ?? 0),
];

pageStart('Warranty / AMC');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-shield-check me-2"></i>Warranty / AMC Register</h1>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#warrantyModal"><i class="fas fa-plus me-2"></i>Register Warranty</button>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $filter==='all'?'active':'' ?>" href="?filter=all">All</a></li>
  <li class="nav-item"><a class="nav-link <?= $filter==='active'?'active':'' ?>" href="?filter=active">Active <span class="badge bg-success ms-1"><?= $counts['active'] ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $filter==='expiring'?'active':'' ?>" href="?filter=expiring">Expiring Soon <span class="badge bg-warning ms-1"><?= $counts['expiring'] ?></span></a></li>
  <li class="nav-item"><a class="nav-link <?= $filter==='expired'?'active':'' ?>" href="?filter=expired">Expired <span class="badge bg-danger ms-1"><?= $counts['expired'] ?></span></a></li>
</ul>

<div class="cj-card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr><th>Customer</th><th>Product</th><th>Serial #</th><th>Type</th><th>Start</th><th>Expiry</th><th>Covered Parts</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($warranties as $w): ?>
          <?php
            $expired  = strtotime($w['warranty_end']) < time();
            $expiring = !$expired && strtotime($w['warranty_end']) < strtotime('+30 days');
          ?>
          <tr class="<?= $expired ? 'table-secondary' : ($expiring ? 'table-warning' : '') ?>">
            <td>
              <div class="fw-600"><?= h($w['customer_name']) ?></div>
              <small class="text-muted"><?= h($w['phone']) ?></small>
            </td>
            <td><?= h($w['product_name']) ?></td>
            <td><?= h($w['serial_number'] ?: '—') ?></td>
            <td><span class="badge bg-<?= $w['warranty_type']==='amc'?'purple':'info' ?>"><?= strtoupper($w['warranty_type']) ?></span></td>
            <td><?= formatDate($w['warranty_start']) ?></td>
            <td>
              <span class="<?= $expired ? 'text-danger' : ($expiring ? 'text-warning fw-bold' : '') ?>">
                <?= formatDate($w['warranty_end']) ?>
              </span>
              <?php if ($expiring): ?><br><small class="badge bg-warning">Expiring Soon</small><?php endif; ?>
            </td>
            <td><small><?= h($w['covered_parts'] ?: '—') ?></small></td>
            <td><?= $expired ? statusBadge('expired') : statusBadge('active') ?></td>
            <td>
              <button class="btn btn-sm btn-outline-primary" onclick="editWarranty(<?= htmlspecialchars(json_encode($w)) ?>)"><i class="fas fa-edit"></i></button>
              <a href="service-tickets.php?action=new&warranty_id=<?= $w['id'] ?>" class="btn btn-sm btn-outline-warning" title="Create Service Ticket"><i class="fas fa-ticket"></i></a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$warranties): ?>
          <tr><td colspan="9" class="text-center py-4 text-muted">No warranty records found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Warranty Modal -->
<div class="modal fade" id="warrantyModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="warrantyModalLabel">Register Warranty</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="w_id" value="">
        <div class="modal-body">
          <div class="alert alert-info small mb-3">
            <strong>Default Coverage:</strong> Mainboard, Headboard, Servo Motor, Driver (1 year from delivery)<br>
            <strong>Exclusions:</strong> Printhead and small spare parts
          </div>
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Customer <span class="text-danger">*</span></label>
              <select name="customer_id" id="w_cust" class="form-select" required>
                <option value="">--</option>
                <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?> — <?= h($c['phone']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Product <span class="text-danger">*</span></label>
              <select name="product_id" id="w_prod" class="form-select" required>
                <option value="">--</option>
                <?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Serial Number</label><input type="text" name="serial_number" id="w_serial" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Warranty Type</label>
              <select name="warranty_type" id="w_type" class="form-select">
                <option value="standard">Standard (1 Year)</option>
                <option value="amc">AMC Contract</option>
                <option value="extended">Extended Warranty</option>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label">Purchase Date</label><input type="date" name="purchase_date" id="w_pdate" class="form-control" value="<?= date('Y-m-d') ?>" onchange="autoSetWarranty()"></div>
            <div class="col-md-4"><label class="form-label">Warranty Start <span class="text-danger">*</span></label><input type="date" name="warranty_start" id="w_start" class="form-control" value="<?= date('Y-m-d') ?>" required onchange="autoEndDate()"></div>
            <div class="col-md-4"><label class="form-label">Warranty End <span class="text-danger">*</span></label><input type="date" name="warranty_end" id="w_end" class="form-control" value="<?= date('Y-m-d', strtotime('+1 year')) ?>" required></div>
            <div class="col-12"><label class="form-label">Covered Parts</label><input type="text" name="covered_parts" id="w_covered" class="form-control" value="Mainboard, Headboard, Servo Motor, Driver"></div>
            <div class="col-12"><label class="form-label">Excluded Parts</label><input type="text" name="excluded_parts" id="w_excluded" class="form-control" value="Printhead and small spare parts"></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" id="w_notes" class="form-control" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-shield-check me-2"></i>Register Warranty</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function autoEndDate() {
  const start = document.getElementById('w_start').value;
  if (start) {
    const end = new Date(start);
    end.setFullYear(end.getFullYear() + 1);
    document.getElementById('w_end').value = end.toISOString().slice(0,10);
  }
}

function autoSetWarranty() {
  const pd = document.getElementById('w_pdate').value;
  if (pd) { document.getElementById('w_start').value = pd; autoEndDate(); }
}

function editWarranty(w) {
  document.getElementById('warrantyModalLabel').textContent = 'Edit Warranty';
  document.getElementById('w_id').value = w.id;
  document.getElementById('w_cust').value = w.customer_id || '';
  document.getElementById('w_prod').value = w.product_id || '';
  document.getElementById('w_serial').value = w.serial_number || '';
  document.getElementById('w_type').value = w.warranty_type || 'standard';
  document.getElementById('w_pdate').value = w.purchase_date || '';
  document.getElementById('w_start').value = w.warranty_start || '';
  document.getElementById('w_end').value = w.warranty_end || '';
  document.getElementById('w_covered').value = w.covered_parts || '';
  document.getElementById('w_excluded').value = w.excluded_parts || '';
  document.getElementById('w_notes').value = w.notes || '';
  new bootstrap.Modal(document.getElementById('warrantyModal')).show();
}
</script>

<?php pageEnd(); ?>
