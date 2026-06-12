<?php
require_once 'includes/layout.php';
requireLogin();

$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);
$errors = [];
$success = '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $data = [
            'name'           => sanitize($_POST['name'] ?? ''),
            'father_name'    => sanitize($_POST['father_name'] ?? ''),
            'phone'          => sanitize($_POST['phone'] ?? ''),
            'phone2'         => sanitize($_POST['phone2'] ?? ''),
            'email'          => sanitize($_POST['email'] ?? ''),
            'address'        => sanitize($_POST['address'] ?? ''),
            'city'           => sanitize($_POST['city'] ?? ''),
            'district'       => sanitize($_POST['district'] ?? ''),
            'nid_number'     => sanitize($_POST['nid_number'] ?? ''),
            'business_name'  => sanitize($_POST['business_name'] ?? ''),
            'credit_limit'   => (float)($_POST['credit_limit'] ?? 0),
            'opening_balance'=> (float)($_POST['opening_balance'] ?? 0),
            'balance_type'   => in_array($_POST['balance_type']??'DR',['DR','CR']) ? $_POST['balance_type'] : 'DR',
            'status'         => in_array($_POST['status']??'active',['active','inactive','blacklisted']) ? $_POST['status'] : 'active',
            'notes'          => sanitize($_POST['notes'] ?? ''),
        ];

        $errors = validateRequired($data, ['name','phone']);

        if (!$errors) {
            $cid = (int)($_POST['id'] ?? 0);
            if ($cid) {
                dbUpdate('customers', $data, ['id' => $cid]);
                logActivity('update', 'customers', $cid, "Updated customer: {$data['name']}");
                redirect('customers.php', 'Customer updated successfully.');
            } else {
                $data['customer_code'] = generateCode('CUST', 'customers', 'customer_code');
                $data['created_by'] = userId();
                $newId = dbInsert('customers', $data);
                if ((float)$data['opening_balance'] > 0) {
                    addCustomerLedger($newId, date('Y-m-d'), $data['balance_type'] === 'DR' ? 'debit' : 'credit', 'Opening Balance', $data['opening_balance'], 'opening', $newId);
                }
                logActivity('create', 'customers', $newId, "Created customer: {$data['name']}");
                redirect('customers.php', 'Customer created successfully.');
            }
        }
    } elseif ($act === 'delete') {
        $did = (int)($_POST['id'] ?? 0);
        if ($did) {
            dbUpdate('customers', ['status' => 'inactive'], ['id' => $did]);
            jsonSuccess(null, 'Customer deactivated.');
        }
        jsonError('Invalid request.');
    }
}

// Fetch customer for edit
$editCustomer = $id ? dbFetch('SELECT * FROM customers WHERE id=?', [$id]) : null;

// List
$search = sanitize($_GET['q'] ?? '');
$params = [];
$where  = '';
if ($search) {
    $where = "WHERE (c.name LIKE ? OR c.phone LIKE ? OR c.customer_code LIKE ? OR c.email LIKE ?)";
    $params = ["%$search%","%$search%","%$search%","%$search%"];
}
$customers = dbFetchAll("SELECT c.* FROM customers c $where ORDER BY c.created_at DESC LIMIT 200", $params);

pageStart('Customers', '<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="index.php">Dashboard</a></li><li class="breadcrumb-item active">Customers</li></ol></nav>');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-users me-2"></i>Customers</h1>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#customerModal">
    <i class="fas fa-plus me-2"></i>Add Customer
  </button>
</div>

<div class="cj-card">
  <div class="card-header">
    <span class="card-title">Customer List (<?= count($customers) ?>)</span>
    <div class="d-flex gap-2">
      <form class="d-flex gap-2" method="GET">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name/phone..." value="<?= h($search) ?>">
        <button class="btn btn-sm btn-outline-primary">Search</button>
      </form>
      <button class="btn btn-sm btn-outline-success" onclick="CJ.exportCSV('customersTable','customers')">
        <i class="fas fa-download me-1"></i>CSV
      </button>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="customersTable">
        <thead>
          <tr>
            <th>Code</th><th>Name</th><th>Phone</th><th>City</th>
            <th>Balance</th><th>Status</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($customers as $c): ?>
          <tr id="row-<?= $c['id'] ?>">
            <td><code><?= h($c['customer_code']) ?></code></td>
            <td>
              <div class="fw-600"><?= h($c['name']) ?></div>
              <?php if ($c['business_name']): ?><div class="text-muted small"><?= h($c['business_name']) ?></div><?php endif; ?>
            </td>
            <td><?= h($c['phone']) ?></td>
            <td><?= h($c['city'] ?: '-') ?></td>
            <td>
              <?php
              $bal = dbFetch("SELECT COALESCE(SUM(CASE WHEN type='debit' THEN amount ELSE -amount END),0) AS bal FROM customer_ledger WHERE customer_id=?", [$c['id']]);
              $b = (float)($bal['bal'] ?? 0);
              echo '<span class="' . ($b > 0 ? 'text-danger' : 'text-success') . ' fw-600">' . money(abs($b)) . ($b > 0 ? ' DR' : ' CR') . '</span>';
              ?>
            </td>
            <td><?= statusBadge($c['status']) ?></td>
            <td class="table-actions">
              <a href="customers.php?id=<?= $c['id'] ?>&action=view" class="btn btn-sm btn-outline-info" title="View Profile"><i class="fas fa-eye"></i></a>
              <button class="btn btn-sm btn-outline-primary" onclick="editCustomer(<?= $c['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
              <a href="customer-ledger.php?customer_id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-warning" title="Ledger"><i class="fas fa-book"></i></a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$customers): ?>
          <tr><td colspan="7" class="text-center py-4 text-muted">No customers found. Add your first customer!</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Customer Modal -->
<div class="modal fade" id="customerModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="customerModalLabel">Add Customer</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="customers.php" id="customerForm">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="edit_id" value="">
        <div class="modal-body">
          <?php if ($errors): ?>
          <div class="alert alert-danger"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
          <?php endif; ?>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="f_name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Father/Mother Name</label>
              <input type="text" name="father_name" id="f_father" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone <span class="text-danger">*</span></label>
              <input type="text" name="phone" id="f_phone" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone 2</label>
              <input type="text" name="phone2" id="f_phone2" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" id="f_email" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">NID Number</label>
              <input type="text" name="nid_number" id="f_nid" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Address</label>
              <textarea name="address" id="f_address" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label">City</label>
              <input type="text" name="city" id="f_city" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">District</label>
              <input type="text" name="district" id="f_district" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Business Name</label>
              <input type="text" name="business_name" id="f_business" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Opening Balance (৳)</label>
              <input type="number" name="opening_balance" id="f_ob" class="form-control" value="0" min="0" step="0.01">
            </div>
            <div class="col-md-4">
              <label class="form-label">Balance Type</label>
              <select name="balance_type" id="f_bt" class="form-select">
                <option value="DR">DR (Customer owes us)</option>
                <option value="CR">CR (We owe customer)</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" id="f_status" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="blacklisted">Blacklisted</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" id="f_notes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Customer</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const customersData = <?= json_encode(array_column($customers, null, 'id')) ?>;

function editCustomer(id) {
  const c = customersData[id];
  if (!c) return;
  document.getElementById('customerModalLabel').textContent = 'Edit Customer';
  document.getElementById('edit_id').value = id;
  document.getElementById('f_name').value = c.name || '';
  document.getElementById('f_father').value = c.father_name || '';
  document.getElementById('f_phone').value = c.phone || '';
  document.getElementById('f_phone2').value = c.phone2 || '';
  document.getElementById('f_email').value = c.email || '';
  document.getElementById('f_nid').value = c.nid_number || '';
  document.getElementById('f_address').value = c.address || '';
  document.getElementById('f_city').value = c.city || '';
  document.getElementById('f_district').value = c.district || '';
  document.getElementById('f_business').value = c.business_name || '';
  document.getElementById('f_ob').value = c.opening_balance || 0;
  document.getElementById('f_bt').value = c.balance_type || 'DR';
  document.getElementById('f_status').value = c.status || 'active';
  document.getElementById('f_notes').value = c.notes || '';
  new bootstrap.Modal(document.getElementById('customerModal')).show();
}

<?php if ($editCustomer && $action === 'edit'): ?>
document.addEventListener('DOMContentLoaded', () => editCustomer(<?= $editCustomer['id'] ?>));
<?php endif; ?>
</script>

<?php pageEnd(); ?>
