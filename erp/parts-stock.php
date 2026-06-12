<?php
require_once 'includes/layout.php';
requireLogin();

$errors   = [];
$products = dbFetchAll("SELECT p.*, pc.name AS cat_name FROM products p LEFT JOIN product_categories pc ON pc.id=p.category_id WHERE pc.name='Spare Parts' OR p.is_warranty_part=1 OR pc.name='Accessories' ORDER BY p.name");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'adjust') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $type      = sanitize($_POST['type'] ?? 'in');
        $qty       = (float)($_POST['quantity'] ?? 0);
        if ($productId && $qty > 0) {
            updateStock($productId, $qty, in_array($type,['in','out','adjustment']) ? $type : 'in', 'parts', 0, (float)($_POST['unit_cost']??0));
            redirect('parts-stock.php', 'Parts stock updated.');
        }
    }
}

pageStart('Parts Stock');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-screwdriver-wrench me-2"></i>Parts Stock</h1>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#partsModal"><i class="fas fa-plus me-2"></i>Adjust Parts</button>
</div>

<div class="cj-card">
  <div class="card-header">
    <span class="card-title">Parts & Spare Parts Inventory</span>
    <button class="btn btn-sm btn-outline-success" onclick="CJ.exportCSV('partsTable','parts_inventory')"><i class="fas fa-download me-1"></i>Export</button>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="partsTable">
        <thead><tr><th>SKU</th><th>Part Name</th><th>Category</th><th>Unit</th><th>In Stock</th><th>Low Alert</th><th>Buy Price</th><th>Warranty Part</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($products as $p): ?>
          <?php $low = $p['current_stock'] <= $p['low_stock_alert']; ?>
          <tr class="<?= $low ? 'table-warning' : '' ?>">
            <td><code><?= h($p['sku']) ?></code></td>
            <td>
              <div class="fw-600"><?= h($p['name']) ?></div>
              <?php if ($p['description']): ?><small class="text-muted"><?= h($p['description']) ?></small><?php endif; ?>
            </td>
            <td><?= h($p['cat_name'] ?: '—') ?></td>
            <td><?= h($p['unit']) ?></td>
            <td class="<?= $low ? 'text-danger fw-bold' : 'fw-600' ?>"><?= $p['current_stock'] ?></td>
            <td><?= $p['low_stock_alert'] ?></td>
            <td><?= money($p['purchase_price']) ?></td>
            <td><?= $p['is_warranty_part'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
            <td class="table-actions">
              <button class="btn btn-sm btn-outline-success" onclick="quickStockIn(<?= $p['id'] ?>, '<?= h($p['name']) ?>')"><i class="fas fa-plus"></i></button>
              <button class="btn btn-sm btn-outline-danger" onclick="quickStockOut(<?= $p['id'] ?>, '<?= h($p['name']) ?>')"><i class="fas fa-minus"></i></button>
              <a href="stock.php?product_id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-info"><i class="fas fa-history"></i></a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$products): ?><tr><td colspan="9" class="text-center py-4 text-muted">No parts found. Add spare parts products first.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="partsModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="adjust">
        <div class="modal-header"><h5 class="modal-title" id="partsModalTitle">Adjust Parts Stock</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12"><label class="form-label">Part <span class="text-danger">*</span></label>
              <select name="product_id" id="parts_product" class="form-select" required>
                <option value="">-- Select Part --</option>
                <?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>"><?= h($p['name']) ?> — Stock: <?= $p['current_stock'] ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Type</label>
              <select name="type" id="parts_type" class="form-select"><option value="in">Stock In</option><option value="out">Stock Out</option></select>
            </div>
            <div class="col-md-6"><label class="form-label">Quantity</label><input type="number" name="quantity" class="form-control" step="0.001" min="0.001" required></div>
            <div class="col-md-6"><label class="form-label">Unit Cost (৳)</label><input type="number" name="unit_cost" class="form-control" step="0.01" min="0" value="0"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Update Stock</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function quickStockIn(id, name) {
  document.getElementById('parts_product').value = id;
  document.getElementById('parts_type').value = 'in';
  document.getElementById('partsModalTitle').textContent = 'Stock In: ' + name;
  new bootstrap.Modal(document.getElementById('partsModal')).show();
}
function quickStockOut(id, name) {
  document.getElementById('parts_product').value = id;
  document.getElementById('parts_type').value = 'out';
  document.getElementById('partsModalTitle').textContent = 'Stock Out: ' + name;
  new bootstrap.Modal(document.getElementById('partsModal')).show();
}
</script>

<?php pageEnd(); ?>
