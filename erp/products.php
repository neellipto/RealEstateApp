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
            'sku'             => sanitize($_POST['sku'] ?? '') ?: null,
            'category_id'     => (int)($_POST['category_id'] ?? 0) ?: null,
            'brand'           => sanitize($_POST['brand'] ?? ''),
            'unit'            => in_array($_POST['unit']??'pcs',['pcs','meter','kg','set','liter','box','roll','sheet']) ? $_POST['unit'] : 'pcs',
            'purchase_price'  => (float)($_POST['purchase_price'] ?? 0),
            'selling_price'   => (float)($_POST['selling_price'] ?? 0),
            'opening_stock'   => (float)($_POST['opening_stock'] ?? 0),
            'current_stock'   => (float)($_POST['opening_stock'] ?? 0),
            'low_stock_alert' => (float)($_POST['low_stock_alert'] ?? 5),
            'warranty_months' => (int)($_POST['warranty_months'] ?? 0),
            'is_serialized'   => isset($_POST['is_serialized']) ? 1 : 0,
            'is_warranty_part'=> isset($_POST['is_warranty_part']) ? 1 : 0,
            'is_active'       => 1,
            'description'     => sanitize($_POST['description'] ?? ''),
        ];
        $errors = validateRequired($data, ['name']);
        if (!$errors) {
            $pid = (int)($_POST['id'] ?? 0);
            if ($pid) {
                unset($data['current_stock']); // Don't overwrite stock on edit
                dbUpdate('products', $data, ['id' => $pid]);
                logActivity('update', 'products', $pid, "Updated product: {$data['name']}");
                redirect('products.php', 'Product updated.');
            } else {
                $data['created_by'] = userId();
                if (!$data['sku']) $data['sku'] = generateCode('SKU', 'products', 'sku');
                $newId = dbInsert('products', $data);
                if ($data['opening_stock'] > 0) {
                    dbInsert('stock_movements', ['product_id'=>$newId,'type'=>'opening','quantity'=>$data['opening_stock'],'created_by'=>userId()]);
                }
                logActivity('create', 'products', $newId, "Created product: {$data['name']}");
                redirect('products.php', 'Product created: ' . $data['name']);
            }
        }
    }
}

$search = sanitize($_GET['q'] ?? '');
$catFilter = (int)($_GET['cat'] ?? 0);
$params = [];
$where  = 'WHERE 1=1';
if ($search) { $where .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR p.brand LIKE ?)'; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
if ($catFilter) { $where .= ' AND p.category_id=?'; $params[] = $catFilter; }

$products   = dbFetchAll("SELECT p.*, pc.name AS category_name FROM products p LEFT JOIN product_categories pc ON pc.id=p.category_id $where ORDER BY p.name LIMIT 300", $params);
$categories = dbFetchAll('SELECT * FROM product_categories ORDER BY name');

pageStart('Products');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-boxes-stacked me-2"></i>Products</h1>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal"><i class="fas fa-plus me-2"></i>Add Product</button>
</div>

<div class="cj-card">
  <div class="card-header">
    <span class="card-title">Product List (<?= count($products) ?>)</span>
    <div class="d-flex gap-2">
      <form class="d-flex gap-2" method="GET">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search..." value="<?= h($search) ?>">
        <select name="cat" class="form-select form-select-sm">
          <option value="">All Categories</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $c['id']==$catFilter?'selected':'' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary">Filter</button>
      </form>
      <button class="btn btn-sm btn-outline-success" onclick="CJ.exportCSV('productsTable','products')"><i class="fas fa-download me-1"></i>CSV</button>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="productsTable">
        <thead>
          <tr><th>SKU</th><th>Name</th><th>Category</th><th>Unit</th><th>Buy Price</th><th>Sell Price</th><th>Stock</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($products as $p): ?>
          <?php $lowStock = $p['current_stock'] <= $p['low_stock_alert']; ?>
          <tr>
            <td><code><?= h($p['sku']) ?></code></td>
            <td>
              <div class="fw-600"><?= h($p['name']) ?></div>
              <?php if ($p['brand']): ?><div class="text-muted small"><?= h($p['brand']) ?></div><?php endif; ?>
              <?php if ($p['is_warranty_part']): ?><span class="badge bg-info">Warranty Part</span><?php endif; ?>
            </td>
            <td><?= h($p['category_name'] ?: '—') ?></td>
            <td><?= h($p['unit']) ?></td>
            <td><?= money($p['purchase_price']) ?></td>
            <td><?= money($p['selling_price']) ?></td>
            <td>
              <span class="<?= $lowStock ? 'text-danger fw-bold' : '' ?>">
                <?= $p['current_stock'] ?> <?= h($p['unit']) ?>
              </span>
              <?php if ($lowStock): ?><span class="badge bg-danger ms-1">Low</span><?php endif; ?>
            </td>
            <td><?= $p['is_active'] ? statusBadge('active') : statusBadge('inactive') ?></td>
            <td class="table-actions">
              <button class="btn btn-sm btn-outline-primary" onclick="editProduct(<?= htmlspecialchars(json_encode($p)) ?>)"><i class="fas fa-edit"></i></button>
              <a href="stock.php?product_id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-info" title="Stock History"><i class="fas fa-history"></i></a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$products): ?>
          <tr><td colspan="9" class="text-center py-4 text-muted">No products found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Product Modal -->
<div class="modal fade" id="productModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="productModalLabel">Add Product</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="prod_id" value="">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8"><label class="form-label">Product Name <span class="text-danger">*</span></label><input type="text" name="name" id="p_name" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">SKU (auto if blank)</label><input type="text" name="sku" id="p_sku" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Category</label>
              <select name="category_id" id="p_cat" class="form-select">
                <option value="">-- None --</option>
                <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label">Brand</label><input type="text" name="brand" id="p_brand" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Unit</label>
              <select name="unit" id="p_unit" class="form-select">
                <?php foreach (['pcs','meter','kg','set','liter','box','roll','sheet'] as $u): ?>
                <option value="<?= $u ?>"><?= $u ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label">Purchase Price (৳)</label><input type="number" name="purchase_price" id="p_buy" class="form-control" step="0.01" min="0" value="0"></div>
            <div class="col-md-4"><label class="form-label">Selling Price (৳)</label><input type="number" name="selling_price" id="p_sell" class="form-control" step="0.01" min="0" value="0"></div>
            <div class="col-md-4"><label class="form-label">Opening Stock</label><input type="number" name="opening_stock" id="p_stock" class="form-control" step="0.001" min="0" value="0"></div>
            <div class="col-md-4"><label class="form-label">Low Stock Alert</label><input type="number" name="low_stock_alert" id="p_low" class="form-control" step="0.001" min="0" value="5"></div>
            <div class="col-md-4"><label class="form-label">Warranty (months)</label><input type="number" name="warranty_months" id="p_warranty" class="form-control" min="0" value="0"></div>
            <div class="col-12">
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="is_serialized" id="p_serial" value="1">
                <label class="form-check-label">Track Serial Numbers</label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="is_warranty_part" id="p_wpart" value="1">
                <label class="form-check-label">Warranty Part</label>
              </div>
            </div>
            <div class="col-12"><label class="form-label">Description</label><textarea name="description" id="p_desc" class="form-control" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Product</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editProduct(p) {
  document.getElementById('productModalLabel').textContent = 'Edit Product';
  document.getElementById('prod_id').value = p.id;
  document.getElementById('p_name').value = p.name || '';
  document.getElementById('p_sku').value = p.sku || '';
  document.getElementById('p_cat').value = p.category_id || '';
  document.getElementById('p_brand').value = p.brand || '';
  document.getElementById('p_unit').value = p.unit || 'pcs';
  document.getElementById('p_buy').value = p.purchase_price || 0;
  document.getElementById('p_sell').value = p.selling_price || 0;
  document.getElementById('p_stock').value = p.current_stock || 0;
  document.getElementById('p_low').value = p.low_stock_alert || 5;
  document.getElementById('p_warranty').value = p.warranty_months || 0;
  document.getElementById('p_serial').checked = p.is_serialized == 1;
  document.getElementById('p_wpart').checked = p.is_warranty_part == 1;
  document.getElementById('p_desc').value = p.description || '';
  new bootstrap.Modal(document.getElementById('productModal')).show();
}
</script>

<?php pageEnd(); ?>
