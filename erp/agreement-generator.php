<?php
require_once 'includes/layout.php';
requireLogin();

$errors = [];
$customers = getCustomers();
$products  = getProducts();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? 'save';

    $data = [
        'customer_id'    => (int)($_POST['customer_id'] ?? 0),
        'date'           => sanitize($_POST['date'] ?? date('Y-m-d')),
        'machine_model'  => sanitize($_POST['machine_model'] ?? ''),
        'serial_number'  => sanitize($_POST['serial_number'] ?? ''),
        'accessories'    => sanitize($_POST['accessories'] ?? ''),
        'cash_price'     => (float)($_POST['cash_price'] ?? 0),
        'down_payment'   => (float)($_POST['down_payment'] ?? 0),
        'remaining_amount'=> (float)($_POST['remaining_amount'] ?? 0),
        'emi_amount'     => (float)($_POST['emi_amount'] ?? 0),
        'emi_months'     => (int)($_POST['emi_months'] ?? 0),
        'emi_day'        => (int)($_POST['emi_day'] ?? 1),
        'payment_mode'   => sanitize($_POST['payment_mode'] ?? ''),
        'bank_name'      => sanitize($_POST['bank_name'] ?? ''),
        'account_number' => sanitize($_POST['account_number'] ?? ''),
        'cheque_numbers' => sanitize($_POST['cheque_numbers'] ?? ''),
        'warranty_terms' => sanitize($_POST['warranty_terms'] ?? "1 year engineer service warranty. Covers: Mainboard, Headboard, Servo Motor, Driver from delivery date. Does NOT cover: Printhead and small spare parts."),
        'special_terms'  => sanitize($_POST['special_terms'] ?? ''),
        'witness1_name'  => sanitize($_POST['witness1_name'] ?? ''),
        'witness1_phone' => sanitize($_POST['witness1_phone'] ?? ''),
        'witness1_address'=> sanitize($_POST['witness1_address'] ?? ''),
        'witness2_name'  => sanitize($_POST['witness2_name'] ?? ''),
        'witness2_phone' => sanitize($_POST['witness2_phone'] ?? ''),
        'witness2_address'=> sanitize($_POST['witness2_address'] ?? ''),
        'status'         => 'active',
    ];

    $errors = validateRequired($data, ['customer_id','machine_model','cash_price']);

    if ($act === 'generate') {
        if ($data['customer_id']) {
            $cust = dbFetch('SELECT * FROM customers WHERE id=?', [$data['customer_id']]);
            $text = generateAgreementText($data, $cust ?: []);
            jsonSuccess(['text' => $text]);
        }
        jsonError('Customer required for text generation.');
    }

    if (!$errors) {
        $cust  = dbFetch('SELECT * FROM customers WHERE id=?', [$data['customer_id']]);
        $data['agreement_text'] = generateAgreementText($data, $cust ?: []);
        $data['agreement_number'] = generateCode('AGR', 'agreements', 'agreement_number', 6);
        $data['created_by'] = userId();

        $agId = dbInsert('agreements', $data);

        // Generate EMI schedule
        if ($data['emi_months'] > 0 && $data['emi_amount'] > 0) {
            generateEmiSchedule($agId, $data['customer_id'], $data['emi_amount'], $data['emi_months'], $data['date'], $data['emi_day']);
        }

        // Customer ledger entry - machine sale
        addCustomerLedger($data['customer_id'], $data['date'], 'debit', "Agreement {$data['agreement_number']}: Machine {$data['machine_model']}", $data['cash_price'], 'agreement', $agId);
        if ($data['down_payment'] > 0) {
            addCustomerLedger($data['customer_id'], $data['date'], 'credit', "Down payment — Agreement {$data['agreement_number']}", $data['down_payment'], 'agreement', $agId);
            addDailyLedger($data['date'], 'income', 'Sales', "Down payment: {$cust['name']} — Agreement {$data['agreement_number']}", $data['down_payment'], $data['payment_mode'] ?: 'cash', 'agreement', $agId);
        }

        logActivity('create', 'agreements', $agId, "Created agreement {$data['agreement_number']}");
        redirect('agreement-records.php?id=' . $agId, 'Agreement created: ' . $data['agreement_number']);
    }
}

pageStart('Agreement Generator', '<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="index.php">Dashboard</a></li><li class="breadcrumb-item active">Agreement Generator</li></ol></nav>');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-file-contract me-2"></i>Installment Sale Agreement Generator</h1>
  <a href="agreement-records.php" class="btn btn-outline-primary"><i class="fas fa-folder-open me-2"></i>All Agreements</a>
</div>

<form method="POST" id="agreementForm">
  <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
  <input type="hidden" name="action" value="save">

  <?php if ($errors): ?>
  <div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- Left Column -->
    <div class="col-lg-7">

      <!-- Customer Info -->
      <div class="cj-card mb-4">
        <div class="card-header"><span class="card-title"><i class="fas fa-user me-2"></i>Customer Information</span></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Customer <span class="text-danger">*</span></label>
              <select name="customer_id" id="customer_id" class="form-select" required onchange="loadCustomerInfo(this.value)">
                <option value="">-- Select Customer --</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>"><?= h($c['name']) ?> — <?= h($c['phone']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Agreement Date</label>
              <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div id="customer-info-box" class="col-12" style="display:none">
              <div class="p-3 bg-sky rounded">
                <div class="row g-2" id="customer-info-content"></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Machine Details -->
      <div class="cj-card mb-4">
        <div class="card-header"><span class="card-title"><i class="fas fa-print me-2"></i>Machine Details</span></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Machine Model <span class="text-danger">*</span></label>
              <input type="text" name="machine_model" class="form-control" placeholder="e.g. COLORJET 3.2M UV Flatbed" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Serial Number</label>
              <input type="text" name="serial_number" class="form-control" placeholder="Machine serial number">
            </div>
            <div class="col-12">
              <label class="form-label">Included Accessories</label>
              <textarea name="accessories" class="form-control" rows="2" placeholder="RIP Software, Power Cable, USB Dongle, etc."></textarea>
            </div>
          </div>
        </div>
      </div>

      <!-- Payment Terms -->
      <div class="cj-card mb-4">
        <div class="card-header"><span class="card-title"><i class="fas fa-money-bill me-2"></i>Payment Terms</span></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Cash Price (৳) <span class="text-danger">*</span></label>
              <input type="number" name="cash_price" id="cash_price" class="form-control" placeholder="0.00" step="0.01" min="0" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Down Payment (৳)</label>
              <input type="number" name="down_payment" id="down_payment" class="form-control" placeholder="0.00" step="0.01" min="0">
            </div>
            <div class="col-md-4">
              <label class="form-label">Remaining Amount (৳)</label>
              <input type="number" name="remaining_amount" id="remaining_amount" class="form-control" placeholder="Auto calculated" readonly>
            </div>
            <div class="col-md-4">
              <label class="form-label">Number of Months</label>
              <input type="number" name="emi_months" id="emi_months" class="form-control" placeholder="12" min="1" max="60">
            </div>
            <div class="col-md-4">
              <label class="form-label">Monthly EMI (৳)</label>
              <input type="number" name="emi_amount" id="emi_amount" class="form-control" placeholder="Auto calculated">
            </div>
            <div class="col-md-4">
              <label class="form-label">EMI Due Day</label>
              <input type="number" name="emi_day" class="form-control" placeholder="5" min="1" max="28" value="5">
            </div>
            <div class="col-md-6">
              <label class="form-label">Payment Mode</label>
              <select name="payment_mode" class="form-select">
                <option value="cheque">Cheque</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="bkash">bKash</option>
                <option value="cash">Cash</option>
                <option value="mixed">Mixed</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Bank Name</label>
              <input type="text" name="bank_name" class="form-control" placeholder="Bank name (if applicable)">
            </div>
            <div class="col-md-6">
              <label class="form-label">Account Number</label>
              <input type="text" name="account_number" class="form-control" placeholder="Bank account number">
            </div>
            <div class="col-md-6">
              <label class="form-label">Cheque Numbers</label>
              <input type="text" name="cheque_numbers" class="form-control" placeholder="e.g. 001-012">
            </div>
          </div>
        </div>
      </div>

      <!-- Witness & Terms -->
      <div class="cj-card mb-4">
        <div class="card-header"><span class="card-title"><i class="fas fa-users me-2"></i>Witnesses</span></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Witness 1 Name</label><input type="text" name="witness1_name" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Witness 1 Phone</label><input type="text" name="witness1_phone" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Witness 1 Address</label><input type="text" name="witness1_address" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Witness 2 Name</label><input type="text" name="witness2_name" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Witness 2 Phone</label><input type="text" name="witness2_phone" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Witness 2 Address</label><input type="text" name="witness2_address" class="form-control"></div>
          </div>
        </div>
      </div>

      <div class="cj-card mb-4">
        <div class="card-header"><span class="card-title"><i class="fas fa-scroll me-2"></i>Terms & Conditions</span></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Warranty Terms</label>
              <textarea name="warranty_terms" class="form-control" rows="3">1 year engineer service warranty. Covers: Mainboard, Headboard, Servo Motor, Driver from delivery date. Does NOT cover: Printhead and small spare parts.</textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Special Terms / Conditions</label>
              <textarea name="special_terms" class="form-control" rows="3" placeholder="Any additional terms..."></textarea>
            </div>
          </div>
        </div>
      </div>

      <div class="d-flex gap-3">
        <button type="button" class="btn btn-outline-secondary btn-lg" onclick="previewAgreement()">
          <i class="fas fa-eye me-2"></i>Preview Agreement Text
        </button>
        <button type="submit" class="btn btn-primary btn-lg">
          <i class="fas fa-save me-2"></i>Save Agreement & Generate EMI
        </button>
      </div>
    </div>

    <!-- Right Column: Preview -->
    <div class="col-lg-5">
      <div class="cj-card sticky-top" style="top:70px">
        <div class="card-header">
          <span class="card-title"><i class="fas fa-file-alt me-2"></i>Agreement Preview</span>
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
        </div>
        <div class="card-body p-0">
          <div id="agreement-preview" class="agreement-preview" style="font-size:11pt;max-height:600px;overflow-y:auto">
            Fill in the form and click <strong>Preview Agreement Text</strong> to generate the agreement.
          </div>
        </div>
      </div>
    </div>
  </div>
</form>

<script>
const customersRaw = <?= json_encode(array_column(dbFetchAll('SELECT * FROM customers WHERE status="active"'), null, 'id')) ?>;

function loadCustomerInfo(id) {
  const c = customersRaw[id];
  const box = document.getElementById('customer-info-box');
  const content = document.getElementById('customer-info-content');
  if (!c) { box.style.display='none'; return; }
  content.innerHTML = `
    <div class="col-md-6"><small class="text-muted">NID</small><div class="fw-600">${c.nid_number||'—'}</div></div>
    <div class="col-md-6"><small class="text-muted">Phone</small><div>${c.phone}</div></div>
    <div class="col-12"><small class="text-muted">Address</small><div>${c.address||'—'}</div></div>
    <div class="col-md-6"><small class="text-muted">Father/Mother</small><div>${c.father_name||'—'}</div></div>
  `;
  box.style.display='block';
}

async function previewAgreement() {
  const form = document.getElementById('agreementForm');
  const fd = new FormData(form);
  fd.set('action', 'generate');
  CJ.loading(true);
  try {
    const r = await fetch('agreement-generator.php', { method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'} });
    const res = await r.json();
    if (res.ok) document.getElementById('agreement-preview').textContent = res.data.text;
    else CJ.flash(res.message, 'danger');
  } catch(e) { CJ.flash('Error generating preview.','danger'); }
  CJ.loading(false);
}
</script>

<?php pageEnd(); ?>
