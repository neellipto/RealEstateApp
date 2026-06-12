<?php
require_once 'includes/layout.php';
requireLogin();

$errors   = [];
$suppliers = getSuppliers();
$poId     = (int)($_GET['po_id'] ?? 0);

// Fetch POs for dropdown
$purchaseOrders = dbFetchAll("SELECT po.id, po.po_number, s.name AS supplier_name FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id ORDER BY po.created_at DESC LIMIT 100");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'save_lc') {
        $data = [
            'po_id'           => (int)($_POST['po_id'] ?? 0) ?: null,
            'supplier_id'     => (int)($_POST['supplier_id'] ?? 0),
            'type'            => in_array($_POST['type']??'LC',['LC','TT','DP']) ? $_POST['type'] : 'LC',
            'lc_number'       => sanitize($_POST['lc_number'] ?? ''),
            'bank_name'       => sanitize($_POST['bank_name'] ?? ''),
            'lc_value'        => (float)($_POST['lc_value'] ?? 0),
            'currency'        => sanitize($_POST['currency'] ?? 'USD'),
            'exchange_rate'   => (float)($_POST['exchange_rate'] ?? 110),
            'lc_date'         => sanitize($_POST['lc_date'] ?? date('Y-m-d')),
            'expiry_date'     => sanitize($_POST['expiry_date'] ?? '') ?: null,
            'shipment_deadline'=> sanitize($_POST['shipment_deadline'] ?? '') ?: null,
            'lc_charge'       => (float)($_POST['lc_charge'] ?? 0),
            'amendment_charge'=> (float)($_POST['amendment_charge'] ?? 0),
            'bank_charge'     => (float)($_POST['bank_charge'] ?? 0),
            'status'          => sanitize($_POST['status'] ?? 'draft'),
            'notes'           => sanitize($_POST['notes'] ?? ''),
            'created_by'      => userId(),
        ];
        $errors = validateRequired($data, ['supplier_id','lc_value']);
        if (!$errors) {
            $lcid = (int)($_POST['id'] ?? 0);
            if ($lcid) {
                unset($data['created_by']);
                dbUpdate('lc_records', $data, ['id' => $lcid]);
                redirect('lc-tt.php', 'LC/TT updated.');
            } else {
                $newId = dbInsert('lc_records', $data);
                if ($data['po_id']) dbUpdate('purchase_orders', ['lc_id' => $newId], ['id' => $data['po_id']]);
                logActivity('create', 'lc_records', $newId, "Created LC/TT");
                redirect('lc-tt.php', 'LC/TT created.');
            }
        }
    } elseif ($act === 'save_tt') {
        $data = [
            'po_id'         => (int)($_POST['po_id'] ?? 0) ?: null,
            'lc_id'         => (int)($_POST['lc_id'] ?? 0) ?: null,
            'supplier_id'   => (int)($_POST['supplier_id'] ?? 0),
            'date'          => sanitize($_POST['date'] ?? date('Y-m-d')),
            'amount'        => (float)($_POST['amount'] ?? 0),
            'currency'      => sanitize($_POST['currency'] ?? 'USD'),
            'exchange_rate' => (float)($_POST['exchange_rate'] ?? 110),
            'amount_bdt'    => (float)($_POST['amount'] ?? 0) * (float)($_POST['exchange_rate'] ?? 110),
            'bank_name'     => sanitize($_POST['bank_name'] ?? ''),
            'transaction_ref'=> sanitize($_POST['transaction_ref'] ?? ''),
            'purpose'       => sanitize($_POST['purpose'] ?? ''),
            'notes'         => sanitize($_POST['notes'] ?? ''),
            'created_by'    => userId(),
        ];
        $errors = validateRequired($data, ['supplier_id','amount','date']);
        if (!$errors) {
            $newId = dbInsert('tt_payments', $data);
            addSupplierLedger($data['supplier_id'], $data['date'], 'credit', "TT Payment — " . ($data['purpose'] ?: 'Supplier payment'), $data['amount_bdt'], 'BDT', 'tt_payments', $newId);
            addDailyLedger($data['date'], 'expense', 'Supplier Payment', "TT to supplier — " . ($data['purpose'] ?: ''), $data['amount_bdt'], 'bank', 'tt_payments', $newId);
            redirect('lc-tt.php', 'TT payment recorded.');
        }
    } elseif ($act === 'save_shipment') {
        $poid = (int)($_POST['po_id'] ?? 0);
        if ($poid) {
            $data = [
                'po_id'            => $poid,
                'bl_awb_number'    => sanitize($_POST['bl_awb_number'] ?? ''),
                'container_number' => sanitize($_POST['container_number'] ?? ''),
                'vessel_name'      => sanitize($_POST['vessel_name'] ?? ''),
                'port_of_origin'   => sanitize($_POST['port_of_origin'] ?? ''),
                'port_of_arrival'  => sanitize($_POST['port_of_arrival'] ?? ''),
                'shipping_date'    => sanitize($_POST['shipping_date'] ?? '') ?: null,
                'eta'              => sanitize($_POST['eta'] ?? '') ?: null,
                'cnf_agent'        => sanitize($_POST['cnf_agent'] ?? ''),
                'notes'            => sanitize($_POST['notes'] ?? ''),
            ];
            $existing = dbFetch('SELECT id FROM shipment_clearance WHERE po_id=?', [$poid]);
            if ($existing) dbUpdate('shipment_clearance', $data, ['po_id' => $poid]);
            else dbInsert('shipment_clearance', $data);
            redirect('lc-tt.php', 'Shipment details saved.');
        }
    }
}

$tab = sanitize($_GET['tab'] ?? 'lc');

$lcs = dbFetchAll("SELECT lc.*, s.name AS supplier_name, po.po_number FROM lc_records lc JOIN suppliers s ON s.id=lc.supplier_id LEFT JOIN purchase_orders po ON po.id=lc.po_id ORDER BY lc.created_at DESC LIMIT 100");
$tts = dbFetchAll("SELECT tt.*, s.name AS supplier_name FROM tt_payments tt JOIN suppliers s ON s.id=tt.supplier_id ORDER BY tt.date DESC LIMIT 100");
$shipments = dbFetchAll("SELECT sc.*, po.po_number, s.name AS supplier_name FROM shipment_clearance sc JOIN purchase_orders po ON po.id=sc.po_id JOIN suppliers s ON s.id=po.supplier_id ORDER BY sc.created_at DESC LIMIT 100");

pageStart('LC / TT Management');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-building-columns me-2"></i>LC / TT Management</h1>
  <div class="d-flex gap-2">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#lcModal"><i class="fas fa-plus me-2"></i>New LC</button>
    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#ttModal"><i class="fas fa-money-bill-transfer me-2"></i>TT Payment</button>
    <button class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#shipmentModal"><i class="fas fa-ship me-2"></i>Shipment</button>
  </div>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab==='lc'?'active':'' ?>" href="?tab=lc">LC/TT Records (<?= count($lcs) ?>)</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='tt'?'active':'' ?>" href="?tab=tt">TT Payments (<?= count($tts) ?>)</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='shipment'?'active':'' ?>" href="?tab=shipment">Shipments (<?= count($shipments) ?>)</a></li>
</ul>

<?php if ($tab === 'lc'): ?>
<div class="cj-card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>Type</th><th>LC Number</th><th>Supplier</th><th>PO #</th><th>Value</th><th>Bank</th><th>Status</th><th>Expiry</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($lcs as $lc): ?>
          <tr>
            <td><span class="badge bg-<?= $lc['type']==='LC'?'primary':'warning' ?>"><?= $lc['type'] ?></span></td>
            <td><span class="fw-600"><?= h($lc['lc_number'] ?: '—') ?></span></td>
            <td><?= h($lc['supplier_name']) ?></td>
            <td><?= h($lc['po_number'] ?: '—') ?></td>
            <td><?= h($lc['currency']) ?> <?= number_format($lc['lc_value'],2) ?></td>
            <td><?= h($lc['bank_name'] ?: '—') ?></td>
            <td><?= statusBadge($lc['status']) ?></td>
            <td><?= $lc['expiry_date'] ? formatDate($lc['expiry_date']) : '—' ?></td>
            <td><button class="btn btn-sm btn-outline-primary" onclick="editLC(<?= htmlspecialchars(json_encode($lc)) ?>)"><i class="fas fa-edit"></i></button></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$lcs): ?><tr><td colspan="9" class="text-center py-4 text-muted">No LC records found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php elseif ($tab === 'tt'): ?>
<div class="cj-card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>Date</th><th>Supplier</th><th>Amount</th><th>BDT Amount</th><th>Bank</th><th>Reference</th><th>Purpose</th></tr></thead>
        <tbody>
          <?php foreach ($tts as $tt): ?>
          <tr>
            <td><?= formatDate($tt['date']) ?></td>
            <td><?= h($tt['supplier_name']) ?></td>
            <td class="fw-600"><?= h($tt['currency']) ?> <?= number_format($tt['amount'],2) ?></td>
            <td><?= money($tt['amount_bdt']) ?></td>
            <td><?= h($tt['bank_name'] ?: '—') ?></td>
            <td><?= h($tt['transaction_ref'] ?: '—') ?></td>
            <td><?= h($tt['purpose'] ?: '—') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$tts): ?><tr><td colspan="7" class="text-center py-4 text-muted">No TT payments found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php else: ?>
<div class="cj-card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>PO #</th><th>Supplier</th><th>BL/AWB</th><th>Container</th><th>Ship Date</th><th>ETA</th><th>CNF</th></tr></thead>
        <tbody>
          <?php foreach ($shipments as $sc): ?>
          <tr>
            <td><?= h($sc['po_number']) ?></td>
            <td><?= h($sc['supplier_name']) ?></td>
            <td><?= h($sc['bl_awb_number'] ?: '—') ?></td>
            <td><?= h($sc['container_number'] ?: '—') ?></td>
            <td><?= $sc['shipping_date'] ? formatDate($sc['shipping_date']) : '—' ?></td>
            <td><?= $sc['eta'] ? formatDate($sc['eta']) : '—' ?></td>
            <td><?= h($sc['cnf_agent'] ?: '—') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$shipments): ?><tr><td colspan="7" class="text-center py-4 text-muted">No shipments found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- LC Modal -->
<div class="modal fade" id="lcModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="lcModalLabel">New LC/TT Record</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save_lc">
        <input type="hidden" name="id" id="lc_id_input" value="">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-3"><label class="form-label">Type</label>
              <select name="type" class="form-select"><option value="LC">LC</option><option value="TT">TT</option><option value="DP">DP</option></select>
            </div>
            <div class="col-md-5"><label class="form-label">Supplier <span class="text-danger">*</span></label>
              <select name="supplier_id" id="lc_sup" class="form-select" required>
                <option value="">--</option>
                <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label">Purchase Order</label>
              <select name="po_id" id="lc_po" class="form-select">
                <option value="">--None--</option>
                <?php foreach ($purchaseOrders as $po): ?><option value="<?= $po['id'] ?>" <?= $po['id']==$poId?'selected':'' ?>><?= h($po['po_number']) ?> — <?= h($po['supplier_name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">LC Number</label><input type="text" name="lc_number" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Bank Name</label><input type="text" name="bank_name" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">LC Value</label><input type="number" name="lc_value" class="form-control" step="0.01" min="0" required></div>
            <div class="col-md-4"><label class="form-label">Currency</label>
              <select name="currency" class="form-select"><?php foreach (['USD','EUR','CNY','JPY'] as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-md-4"><label class="form-label">Exchange Rate</label><input type="number" name="exchange_rate" class="form-control" value="110" step="0.01"></div>
            <div class="col-md-4"><label class="form-label">LC Date</label><input type="date" name="lc_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-4"><label class="form-label">Expiry Date</label><input type="date" name="expiry_date" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Shipment Deadline</label><input type="date" name="shipment_deadline" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">LC Charge (৳)</label><input type="number" name="lc_charge" class="form-control" value="0" step="0.01"></div>
            <div class="col-md-4"><label class="form-label">Amendment Charge (৳)</label><input type="number" name="amendment_charge" class="form-control" value="0" step="0.01"></div>
            <div class="col-md-4"><label class="form-label">Bank Charge (৳)</label><input type="number" name="bank_charge" class="form-control" value="0" step="0.01"></div>
            <div class="col-md-6"><label class="form-label">Status</label>
              <select name="status" class="form-select">
                <?php foreach (['draft','pi_received','lc_opened','tt_paid','production','shipped','port_arrived','customs','released','warehouse','closed'] as $st): ?>
                <option value="<?= $st ?>"><?= ucwords(str_replace('_',' ',$st)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save LC/TT</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- TT Modal -->
<div class="modal fade" id="ttModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Record TT Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save_tt">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12"><label class="form-label">Supplier <span class="text-danger">*</span></label>
              <select name="supplier_id" class="form-select" required>
                <option value="">--</option>
                <?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Date</label><input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-3"><label class="form-label">Amount</label><input type="number" name="amount" class="form-control" step="0.01" min="0" required></div>
            <div class="col-md-3"><label class="form-label">Currency</label>
              <select name="currency" class="form-select"><?php foreach (['USD','EUR','CNY'] as $c): ?><option value="<?= $c ?>"><?= $c ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-md-6"><label class="form-label">Exchange Rate</label><input type="number" name="exchange_rate" class="form-control" value="110" step="0.01"></div>
            <div class="col-md-6"><label class="form-label">Bank Name</label><input type="text" name="bank_name" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Transaction Ref</label><input type="text" name="transaction_ref" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Purpose</label><input type="text" name="purpose" class="form-control"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Record TT</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Shipment Modal -->
<div class="modal fade" id="shipmentModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Shipment Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save_shipment">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12"><label class="form-label">Purchase Order</label>
              <select name="po_id" class="form-select">
                <option value="">--</option>
                <?php foreach ($purchaseOrders as $po): ?><option value="<?= $po['id'] ?>"><?= h($po['po_number']) ?> — <?= h($po['supplier_name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">BL/AWB Number</label><input type="text" name="bl_awb_number" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Container Number</label><input type="text" name="container_number" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Vessel Name</label><input type="text" name="vessel_name" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">CNF Agent</label><input type="text" name="cnf_agent" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Port of Origin</label><input type="text" name="port_of_origin" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Port of Arrival</label><input type="text" name="port_of_arrival" class="form-control" value="Chittagong"></div>
            <div class="col-md-4"><label class="form-label">Shipping Date</label><input type="date" name="shipping_date" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">ETA</label><input type="date" name="eta" class="form-control"></div>
            <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Shipment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editLC(lc) {
  document.getElementById('lcModalLabel').textContent = 'Edit LC/TT';
  document.getElementById('lc_id_input').value = lc.id;
  document.getElementById('lc_sup').value = lc.supplier_id || '';
  document.getElementById('lc_po').value = lc.po_id || '';
  new bootstrap.Modal(document.getElementById('lcModal')).show();
}
<?php if ($poId): ?>
document.addEventListener('DOMContentLoaded', () => {
  new bootstrap.Modal(document.getElementById('lcModal')).show();
});
<?php endif; ?>
</script>

<?php pageEnd(); ?>
