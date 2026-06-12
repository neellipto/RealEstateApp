<?php
require_once 'includes/layout.php';
requireLogin();

$errors = [];
$suppliers = getSuppliers();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $data = [
            'supplier_id'    => (int)($_POST['supplier_id'] ?? 0),
            'pi_number'      => sanitize($_POST['pi_number'] ?? ''),
            'date'           => sanitize($_POST['date'] ?? date('Y-m-d')),
            'currency'       => sanitize($_POST['currency'] ?? 'USD'),
            'exchange_rate'  => (float)($_POST['exchange_rate'] ?? 1),
            'pi_value'       => (float)($_POST['pi_value'] ?? 0),
            'pi_value_bdt'   => (float)($_POST['pi_value'] ?? 0) * (float)($_POST['exchange_rate'] ?? 1),
            'advance_payment'=> (float)($_POST['advance_payment'] ?? 0),
            'balance_payment'=> (float)($_POST['pi_value'] ?? 0) - (float)($_POST['advance_payment'] ?? 0),
            'status'         => sanitize($_POST['status'] ?? 'draft'),
            'notes'          => sanitize($_POST['notes'] ?? ''),
        ];
        $errors = validateRequired($data, ['supplier_id','pi_value']);
        if (!$errors) {
            $poid = (int)($_POST['id'] ?? 0);
            if ($poid) {
                dbUpdate('purchase_orders', $data, ['id' => $poid]);
                redirect('foreign-purchase.php', 'Purchase order updated.');
            } else {
                $data['po_number'] = generateCode('PO', 'purchase_orders', 'po_number', 6);
                $data['created_by'] = userId();
                $newId = dbInsert('purchase_orders', $data);
                if ($data['advance_payment'] > 0) {
                    addSupplierLedger($data['supplier_id'], $data['date'], 'credit', "Advance Payment — PO {$data['po_number']}", $data['advance_payment'] * $data['exchange_rate'], 'BDT', 'purchase_orders', $newId);
                }
                logActivity('create', 'purchase_orders', $newId, "Created PO {$data['po_number']}");
                redirect('foreign-purchase.php', 'Purchase order created: ' . $data['po_number']);
            }
        }
    } elseif ($act === 'update_status') {
        $poid   = (int)($_POST['id'] ?? 0);
        $status = sanitize($_POST['status'] ?? '');
        if ($poid && $status) {
            dbUpdate('purchase_orders', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $poid]);
            jsonSuccess(null, 'Status updated to: ' . $status);
        }
        jsonError('Invalid request.');
    }
}

$suppFilter = (int)($_GET['supplier_id'] ?? 0);
$statusFilter = sanitize($_GET['status'] ?? '');

$where = 'WHERE 1=1';
$params = [];
if ($suppFilter) { $where .= ' AND po.supplier_id=?'; $params[] = $suppFilter; }
if ($statusFilter) { $where .= ' AND po.status=?'; $params[] = $statusFilter; }

$orders = dbFetchAll(
    "SELECT po.*, s.name AS supplier_name, s.country FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id $where ORDER BY po.created_at DESC LIMIT 200",
    $params
);

$viewId = (int)($_GET['id'] ?? 0);
$viewOrder = $viewId ? dbFetch("SELECT po.*, s.name AS supplier_name, s.country, s.bank_name, s.bank_account, s.swift_code FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id WHERE po.id=?", [$viewId]) : null;
$viewItems = $viewId ? dbFetchAll("SELECT poi.*, p.name AS product_name FROM purchase_order_items poi LEFT JOIN products p ON p.id=poi.product_id WHERE poi.po_id=?", [$viewId]) : [];
$shipment  = $viewId ? dbFetch("SELECT * FROM shipment_clearance WHERE po_id=?", [$viewId]) : null;
$lc        = $viewId ? dbFetch("SELECT * FROM lc_records WHERE po_id=?", [$viewId]) : null;

$statuses = ['draft','pi_received','lc_opened','tt_paid','production','shipped','port_arrived','customs','released','warehouse','closed'];

pageStart('Foreign Purchase');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-plane-arrival me-2"></i>Foreign Purchase</h1>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#poModal"><i class="fas fa-plus me-2"></i>New Purchase Order</button>
</div>

<!-- Filter -->
<div class="cj-card mb-4">
  <div class="card-body">
    <form class="row g-3" method="GET">
      <div class="col-md-4">
        <select name="supplier_id" class="form-select">
          <option value="">All Suppliers</option>
          <?php foreach ($suppliers as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $s['id']==$suppFilter?'selected':'' ?>><?= h($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <select name="status" class="form-select">
          <option value="">All Statuses</option>
          <?php foreach ($statuses as $st): ?>
          <option value="<?= $st ?>" <?= $st===$statusFilter?'selected':'' ?>><?= ucwords(str_replace('_',' ',$st)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4"><button class="btn btn-outline-primary w-100">Filter</button></div>
    </form>
  </div>
</div>

<div class="row g-4">
  <div class="<?= $viewOrder ? 'col-lg-5' : 'col-12' ?>">
    <div class="cj-card">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr><th>PO #</th><th>Supplier</th><th>PI Value</th><th>Currency</th><th>Status</th><th>Date</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($orders as $po): ?>
              <tr>
                <td><a href="?id=<?= $po['id'] ?>" class="fw-600"><?= h($po['po_number']) ?></a></td>
                <td>
                  <div><?= h($po['supplier_name']) ?></div>
                  <small class="text-muted"><?= h($po['country']) ?></small>
                </td>
                <td>
                  <div><?= h($po['currency']) ?> <?= number_format($po['pi_value'],2) ?></div>
                  <small class="text-muted">৳<?= number_format($po['pi_value_bdt'],0) ?></small>
                </td>
                <td><?= h($po['currency']) ?></td>
                <td><?= statusBadge($po['status']) ?></td>
                <td><?= formatDate($po['date']) ?></td>
                <td class="table-actions">
                  <a href="?id=<?= $po['id'] ?>" class="btn btn-sm btn-outline-info"><i class="fas fa-eye"></i></a>
                  <button class="btn btn-sm btn-outline-primary" onclick="editPO(<?= htmlspecialchars(json_encode($po)) ?>)"><i class="fas fa-edit"></i></button>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$orders): ?>
              <tr><td colspan="7" class="text-center py-4 text-muted">No purchase orders found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <?php if ($viewOrder): ?>
  <div class="col-lg-7">
    <div class="cj-card">
      <div class="card-header">
        <span class="card-title">PO: <?= h($viewOrder['po_number']) ?></span>
        <div class="d-flex gap-2">
          <?= statusBadge($viewOrder['status']) ?>
          <a href="foreign-purchase.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times"></i></a>
        </div>
      </div>
      <div class="card-body">
        <!-- Status Tracker -->
        <div class="mb-4">
          <div class="d-flex flex-wrap gap-1">
            <?php foreach ($statuses as $st): ?>
            <span class="badge <?= $viewOrder['status']===$st ? 'bg-primary' : 'bg-light text-dark' ?>"><?= ucwords(str_replace('_',' ',$st)) ?></span>
            <?php endforeach; ?>
          </div>
          <div class="mt-2 d-flex gap-2">
            <select id="statusSelect" class="form-select form-select-sm" style="max-width:200px">
              <?php foreach ($statuses as $st): ?>
              <option value="<?= $st ?>" <?= $viewOrder['status']===$st?'selected':'' ?>><?= ucwords(str_replace('_',' ',$st)) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-primary" onclick="updateStatus(<?= $viewOrder['id'] ?>)">Update Status</button>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <table class="table table-sm">
              <tr><td class="text-muted">Supplier</td><td><strong><?= h($viewOrder['supplier_name']) ?></strong></td></tr>
              <tr><td class="text-muted">Country</td><td><?= h($viewOrder['country']) ?></td></tr>
              <tr><td class="text-muted">PI Number</td><td><?= h($viewOrder['pi_number'] ?: '—') ?></td></tr>
              <tr><td class="text-muted">Date</td><td><?= formatDate($viewOrder['date']) ?></td></tr>
            </table>
          </div>
          <div class="col-md-6">
            <table class="table table-sm">
              <tr><td class="text-muted">PI Value</td><td><strong><?= h($viewOrder['currency']) ?> <?= number_format($viewOrder['pi_value'],2) ?></strong></td></tr>
              <tr><td class="text-muted">Exchange Rate</td><td><?= $viewOrder['exchange_rate'] ?></td></tr>
              <tr><td class="text-muted">BDT Value</td><td>৳<?= number_format($viewOrder['pi_value_bdt'],2) ?></td></tr>
              <tr><td class="text-muted">Advance Paid</td><td class="text-success"><?= h($viewOrder['currency']) ?> <?= number_format($viewOrder['advance_payment'],2) ?></td></tr>
              <tr><td class="text-muted">Balance Due</td><td class="text-danger fw-600"><?= h($viewOrder['currency']) ?> <?= number_format($viewOrder['balance_payment'],2) ?></td></tr>
            </table>
          </div>
        </div>

        <?php if ($lc): ?>
        <div class="alert alert-info py-2">
          <strong>LC:</strong> <?= h($lc['lc_number'] ?: '—') ?> | Bank: <?= h($lc['bank_name'] ?: '—') ?> | Expiry: <?= formatDate($lc['expiry_date']) ?>
        </div>
        <?php endif; ?>

        <?php if ($shipment): ?>
        <div class="alert alert-secondary py-2">
          <strong>Shipment:</strong> BL/AWB: <?= h($shipment['bl_awb_number'] ?: '—') ?> | Container: <?= h($shipment['container_number'] ?: '—') ?> | ETA: <?= formatDate($shipment['eta']) ?>
        </div>
        <?php endif; ?>

        <?php if ($viewOrder['notes']): ?>
        <div class="mt-2 p-2 bg-light rounded small"><?= nl2br(h($viewOrder['notes'])) ?></div>
        <?php endif; ?>

        <div class="mt-3 d-flex gap-2">
          <a href="lc-tt.php?po_id=<?= $viewOrder['id'] ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-building-columns me-1"></i>Manage LC/TT</a>
          <a href="landed-cost.php?po_id=<?= $viewOrder['id'] ?>" class="btn btn-outline-info btn-sm"><i class="fas fa-calculator me-1"></i>Landed Cost</a>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- PO Modal -->
<div class="modal fade" id="poModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="poModalLabel">New Purchase Order</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="po_id" value="">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Supplier <span class="text-danger">*</span></label>
              <select name="supplier_id" id="po_supplier" class="form-select" required>
                <option value="">-- Select Supplier --</option>
                <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?> (<?= h($s['country']) ?>)</option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label">PI Number</label><input type="text" name="pi_number" id="po_pi" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Date</label><input type="date" name="date" id="po_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-4">
              <label class="form-label">Currency</label>
              <select name="currency" id="po_cur" class="form-select">
                <?php foreach (['USD','EUR','CNY','JPY','GBP'] as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label">Exchange Rate (to BDT)</label><input type="number" name="exchange_rate" id="po_rate" class="form-control" value="110" step="0.01" min="1"></div>
            <div class="col-md-6"><label class="form-label">PI Value (foreign currency) <span class="text-danger">*</span></label><input type="number" name="pi_value" id="po_piv" class="form-control" step="0.01" min="0" required></div>
            <div class="col-md-6"><label class="form-label">Advance Payment</label><input type="number" name="advance_payment" id="po_adv" class="form-control" step="0.01" min="0" value="0"></div>
            <div class="col-md-6">
              <label class="form-label">Status</label>
              <select name="status" id="po_status" class="form-select">
                <?php foreach ($statuses as $st): ?><option value="<?= $st ?>"><?= ucwords(str_replace('_',' ',$st)) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" id="po_notes" class="form-control" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save PO</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editPO(po) {
  document.getElementById('poModalLabel').textContent = 'Edit PO: ' + po.po_number;
  document.getElementById('po_id').value = po.id;
  document.getElementById('po_supplier').value = po.supplier_id || '';
  document.getElementById('po_pi').value = po.pi_number || '';
  document.getElementById('po_date').value = po.date || '';
  document.getElementById('po_cur').value = po.currency || 'USD';
  document.getElementById('po_rate').value = po.exchange_rate || 110;
  document.getElementById('po_piv').value = po.pi_value || 0;
  document.getElementById('po_adv').value = po.advance_payment || 0;
  document.getElementById('po_status').value = po.status || 'draft';
  document.getElementById('po_notes').value = po.notes || '';
  new bootstrap.Modal(document.getElementById('poModal')).show();
}

async function updateStatus(id) {
  const status = document.getElementById('statusSelect').value;
  const r = await CJ.post('foreign-purchase.php', { action: 'update_status', id, status });
  if (r.ok) { CJ.flash('Status updated!'); setTimeout(() => location.reload(), 800); }
  else CJ.flash(r.message, 'danger');
}
</script>

<?php pageEnd(); ?>
