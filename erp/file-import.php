<?php
require_once 'includes/layout.php';
requireLogin();
requireRole('OWNER','ADMIN','MANAGER');

$errors = [];
$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $module = sanitize($_POST['module'] ?? '');
    $file   = $_FILES['import_file'] ?? null;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please select a valid CSV file.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $errors[] = 'Only CSV files are supported.';
        } else {
            $batchId = dbInsert('import_batches', [
                'module'   => $module,
                'filename' => $file['name'],
                'status'   => 'processing',
                'created_by' => userId(),
            ]);

            $handle  = fopen($file['tmp_name'], 'r');
            $headers = fgetcsv($handle);
            $headers = array_map('trim', array_map('strtolower', $headers));

            $imported = 0; $failed = 0; $row = 1;
            while (($line = fgetcsv($handle)) !== false) {
                $row++;
                $data = array_combine($headers, $line);
                $msg  = null;
                $stat = 'success';
                try {
                    switch ($module) {
                        case 'customers':
                            if (empty($data['name'])) throw new Exception('Name required');
                            dbInsert('customers', [
                                'customer_code' => generateCode('CUST','customers','customer_code'),
                                'name'          => sanitize($data['name']),
                                'phone'         => sanitize($data['phone'] ?? ''),
                                'email'         => sanitize($data['email'] ?? ''),
                                'address'       => sanitize($data['address'] ?? ''),
                                'city'          => sanitize($data['city'] ?? ''),
                                'district'      => sanitize($data['district'] ?? ''),
                                'created_by'    => userId(),
                            ]);
                            break;
                        case 'products':
                            if (empty($data['name'])) throw new Exception('Name required');
                            dbInsert('products', [
                                'sku'           => $data['sku'] ? sanitize($data['sku']) : generateCode('SKU','products','sku'),
                                'name'          => sanitize($data['name']),
                                'purchase_price'=> (float)($data['purchase_price'] ?? 0),
                                'selling_price' => (float)($data['selling_price'] ?? 0),
                                'current_stock' => (float)($data['opening_stock'] ?? 0),
                                'opening_stock' => (float)($data['opening_stock'] ?? 0),
                                'unit'          => sanitize($data['unit'] ?? 'pcs'),
                                'created_by'    => userId(),
                            ]);
                            break;
                        case 'stock':
                            $p = dbFetch('SELECT id FROM products WHERE sku=?', [sanitize($data['sku'] ?? '')]);
                            if (!$p) throw new Exception("SKU not found: " . $data['sku']);
                            updateStock($p['id'], (float)($data['quantity'] ?? 0), 'in', 'import', $batchId, (float)($data['unit_cost'] ?? 0));
                            break;
                        default:
                            throw new Exception('Unknown module');
                    }
                    $imported++;
                } catch (Exception $e) {
                    $stat = 'failed'; $msg = $e->getMessage(); $failed++;
                }
                dbInsert('import_audit', ['batch_id'=>$batchId,'row_number'=>$row,'status'=>$stat,'data'=>json_encode($data),'message'=>$msg]);
            }
            fclose($handle);

            dbUpdate('import_batches', ['status'=>'completed','total_rows'=>$imported+$failed,'imported_rows'=>$imported,'failed_rows'=>$failed], ['id'=>$batchId]);
            $results = ['imported'=>$imported, 'failed'=>$failed, 'batch_id'=>$batchId];
        }
    }
}

$batches = dbFetchAll("SELECT ib.*, u.name AS user_name FROM import_batches ib LEFT JOIN users u ON u.id=ib.created_by ORDER BY ib.created_at DESC LIMIT 20");

pageStart('File Import');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-file-arrow-up me-2"></i>Data Import</h1>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<?php if ($results): ?>
<div class="alert alert-<?= $results['failed']>0?'warning':'success' ?>">
  <strong>Import Complete!</strong> Imported: <strong><?= $results['imported'] ?></strong> records.
  <?php if ($results['failed']): ?> Failed: <strong class="text-danger"><?= $results['failed'] ?></strong> rows.<?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="cj-card">
      <div class="card-header"><span class="card-title"><i class="fas fa-upload me-2"></i>Upload CSV File</span></div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Import Module</label>
            <select name="module" class="form-select" onchange="showTemplate(this.value)">
              <option value="customers">Customers</option>
              <option value="products">Products</option>
              <option value="stock">Stock Adjustment</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">CSV File</label>
            <input type="file" name="import_file" class="form-control" accept=".csv" required>
            <div class="form-text">Maximum 5MB. Must be UTF-8 encoded CSV with header row.</div>
          </div>
          <div id="template-info" class="alert alert-info py-2 small">
            <strong>Customer CSV headers:</strong> name, phone, email, address, city, district
          </div>
        </div>
        <div class="card-footer d-flex justify-content-between">
          <button type="button" class="btn btn-outline-secondary" onclick="downloadTemplate()">
            <i class="fas fa-download me-1"></i>Download Template
          </button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-upload me-2"></i>Import Data
          </button>
        </div>
      </form>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="cj-card">
      <div class="card-header"><span class="card-title"><i class="fas fa-history me-2"></i>Import History</span></div>
      <div class="card-body p-0">
        <div class="list-group list-group-flush">
          <?php foreach ($batches as $b): ?>
          <div class="list-group-item py-2">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <div class="fw-600"><?= ucfirst(h($b['module'])) ?> Import</div>
                <div class="text-muted small"><?= h($b['filename']) ?> · <?= formatDateTime($b['created_at']) ?></div>
              </div>
              <div class="text-end">
                <div class="text-success small"><?= $b['imported_rows'] ?> OK</div>
                <?php if ($b['failed_rows']): ?><div class="text-danger small"><?= $b['failed_rows'] ?> failed</div><?php endif; ?>
                <?= statusBadge($b['status']) ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (!$batches): ?><div class="list-group-item text-center py-4 text-muted">No imports yet.</div><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const templates = {
  customers: { headers: 'name,phone,email,address,city,district', desc: 'Customer CSV headers: name, phone, email, address, city, district' },
  products: { headers: 'name,sku,purchase_price,selling_price,opening_stock,unit,description', desc: 'Product CSV headers: name, sku, purchase_price, selling_price, opening_stock, unit, description' },
  stock: { headers: 'sku,quantity,unit_cost', desc: 'Stock CSV headers: sku (existing SKU), quantity, unit_cost' },
};

function showTemplate(module) {
  const t = templates[module];
  document.getElementById('template-info').innerHTML = '<strong>' + module.charAt(0).toUpperCase() + module.slice(1) + ' CSV headers:</strong> ' + t.desc.split(': ')[1];
}

function downloadTemplate() {
  const module = document.querySelector('[name="module"]').value;
  const t = templates[module];
  const blob = new Blob([t.headers + '\n'], { type: 'text/csv' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = module + '_template.csv';
  a.click();
}
</script>

<?php pageEnd(); ?>
