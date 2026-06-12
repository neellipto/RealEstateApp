<?php
require_once 'includes/layout.php';
requireLogin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $data = [
            'name'            => sanitize($_POST['name'] ?? ''),
            'country'         => sanitize($_POST['country'] ?? ''),
            'contact_person'  => sanitize($_POST['contact_person'] ?? ''),
            'phone'           => sanitize($_POST['phone'] ?? ''),
            'whatsapp'        => sanitize($_POST['whatsapp'] ?? ''),
            'email'           => sanitize($_POST['email'] ?? ''),
            'address'         => sanitize($_POST['address'] ?? ''),
            'bank_name'       => sanitize($_POST['bank_name'] ?? ''),
            'bank_account'    => sanitize($_POST['bank_account'] ?? ''),
            'swift_code'      => sanitize($_POST['swift_code'] ?? ''),
            'product_category'=> sanitize($_POST['product_category'] ?? ''),
            'payment_terms'   => sanitize($_POST['payment_terms'] ?? ''),
            'currency'        => sanitize($_POST['currency'] ?? 'USD'),
            'opening_balance' => (float)($_POST['opening_balance'] ?? 0),
            'status'          => 'active',
        ];
        $errors = validateRequired($data, ['name']);
        if (!$errors) {
            $sid = (int)($_POST['id'] ?? 0);
            if ($sid) {
                dbUpdate('suppliers', $data, ['id' => $sid]);
                redirect('suppliers.php', 'Supplier updated.');
            } else {
                $data['supplier_code'] = generateCode('SUP', 'suppliers', 'supplier_code');
                $data['created_by'] = userId();
                $newId = dbInsert('suppliers', $data);
                if ($data['opening_balance'] > 0) {
                    addSupplierLedger($newId, date('Y-m-d'), 'debit', 'Opening Balance', $data['opening_balance'], $data['currency']);
                }
                redirect('suppliers.php', 'Supplier created.');
            }
        }
    }
}

$search = sanitize($_GET['q'] ?? '');
$params = [];
$where  = '';
if ($search) {
    $where = 'WHERE (name LIKE ? OR country LIKE ? OR supplier_code LIKE ?)';
    $params = ["%$search%","%$search%","%$search%"];
}
$suppliers = dbFetchAll("SELECT * FROM suppliers $where ORDER BY name LIMIT 200", $params);

pageStart('Suppliers');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-handshake me-2"></i>Suppliers</h1>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#supplierModal"><i class="fas fa-plus me-2"></i>Add Supplier</button>
</div>

<div class="cj-card">
  <div class="card-header">
    <span class="card-title">Supplier List (<?= count($suppliers) ?>)</span>
    <form class="d-flex gap-2" method="GET">
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Search..." value="<?= h($search) ?>">
      <button class="btn btn-sm btn-outline-primary">Search</button>
    </form>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr><th>Code</th><th>Name</th><th>Country</th><th>Contact</th><th>Currency</th><th>Balance</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($suppliers as $s): ?>
          <?php
            $bal = dbFetch("SELECT COALESCE(SUM(CASE WHEN type='debit' THEN amount ELSE -amount END),0) AS bal FROM supplier_ledger WHERE supplier_id=?", [$s['id']]);
            $b = (float)($bal['bal'] ?? 0);
          ?>
          <tr>
            <td><code><?= h($s['supplier_code']) ?></code></td>
            <td>
              <div class="fw-600"><?= h($s['name']) ?></div>
              <?php if ($s['product_category']): ?><small class="text-muted"><?= h($s['product_category']) ?></small><?php endif; ?>
            </td>
            <td><?= h($s['country'] ?: '—') ?></td>
            <td>
              <div><?= h($s['contact_person'] ?: '—') ?></div>
              <small><?= h($s['phone'] ?: '') ?></small>
            </td>
            <td><?= h($s['currency']) ?></td>
            <td>
              <span class="<?= $b > 0 ? 'text-danger' : 'text-success' ?> fw-600">
                <?= money(abs($b)) ?> <?= $b > 0 ? 'Due' : 'Advance' ?>
              </span>
            </td>
            <td class="table-actions">
              <button class="btn btn-sm btn-outline-primary" onclick="editSupplier(<?= htmlspecialchars(json_encode($s)) ?>)"><i class="fas fa-edit"></i></button>
              <a href="supplier-ledger.php?supplier_id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-warning" title="Ledger"><i class="fas fa-book"></i></a>
              <a href="foreign-purchase.php?supplier_id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-info" title="Purchases"><i class="fas fa-shopping-cart"></i></a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$suppliers): ?>
          <tr><td colspan="7" class="text-center py-4 text-muted">No suppliers found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Supplier Modal -->
<div class="modal fade" id="supplierModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="supplierModalLabel">Add Supplier</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="sup_id" value="">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8"><label class="form-label">Supplier Name <span class="text-danger">*</span></label><input type="text" name="name" id="s_name" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Country</label><input type="text" name="country" id="s_country" class="form-control" placeholder="China, Japan..."></div>
            <div class="col-md-6"><label class="form-label">Contact Person</label><input type="text" name="contact_person" id="s_contact" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Phone</label><input type="text" name="phone" id="s_phone" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">WhatsApp</label><input type="text" name="whatsapp" id="s_wa" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" id="s_email" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Currency</label>
              <select name="currency" id="s_cur" class="form-select">
                <?php foreach (['USD','EUR','CNY','JPY','GBP','BDT'] as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-12"><label class="form-label">Address</label><textarea name="address" id="s_addr" class="form-control" rows="2"></textarea></div>
            <div class="col-md-4"><label class="form-label">Bank Name</label><input type="text" name="bank_name" id="s_bank" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Account Number</label><input type="text" name="bank_account" id="s_bacc" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">SWIFT Code</label><input type="text" name="swift_code" id="s_swift" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Product Category</label><input type="text" name="product_category" id="s_pcat" class="form-control" placeholder="Printing Machines, Ink..."></div>
            <div class="col-md-3"><label class="form-label">Payment Terms</label><input type="text" name="payment_terms" id="s_terms" class="form-control" placeholder="LC, TT, DP..."></div>
            <div class="col-md-3"><label class="form-label">Opening Balance</label><input type="number" name="opening_balance" id="s_ob" class="form-control" value="0" step="0.01" min="0"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Supplier</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editSupplier(s) {
  document.getElementById('supplierModalLabel').textContent = 'Edit Supplier';
  document.getElementById('sup_id').value = s.id;
  ['name','country','contact_person','phone','whatsapp','email','address','bank_name','bank_account','swift_code','product_category','payment_terms'].forEach(f => {
    const el = document.getElementById('s_' + f.split('_').map((w,i) => i>0?w[0]+w.slice(1):w).join('').replace('contactPerson','contact').replace('bankName','bank').replace('bankAccount','bacc').replace('swiftCode','swift').replace('productCategory','pcat').replace('paymentTerms','terms'));
    if (el) el.value = s[f] || '';
  });
  // Manual set for mapped ids
  document.getElementById('s_name').value = s.name||'';
  document.getElementById('s_country').value = s.country||'';
  document.getElementById('s_contact').value = s.contact_person||'';
  document.getElementById('s_phone').value = s.phone||'';
  document.getElementById('s_wa').value = s.whatsapp||'';
  document.getElementById('s_email').value = s.email||'';
  document.getElementById('s_cur').value = s.currency||'USD';
  document.getElementById('s_addr').value = s.address||'';
  document.getElementById('s_bank').value = s.bank_name||'';
  document.getElementById('s_bacc').value = s.bank_account||'';
  document.getElementById('s_swift').value = s.swift_code||'';
  document.getElementById('s_pcat').value = s.product_category||'';
  document.getElementById('s_terms').value = s.payment_terms||'';
  document.getElementById('s_ob').value = s.opening_balance||0;
  new bootstrap.Modal(document.getElementById('supplierModal')).show();
}
</script>

<?php pageEnd(); ?>
