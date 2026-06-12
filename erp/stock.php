<?php
require_once 'includes/layout.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'adjust') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $type      = in_array($_POST['type']??'in',['in','out','adjustment']) ? $_POST['type'] : 'in';
        $qty       = (float)($_POST['quantity'] ?? 0);
        $notes     = sanitize($_POST['notes'] ?? '');
        if ($productId && $qty > 0) {
            updateStock($productId, $qty, $type, 'manual', 0, (float)($_POST['unit_cost']??0));
            dbQuery("UPDATE products SET updated_at=NOW() WHERE id=?", [$productId]);
            logActivity('stock_'.$type, 'stock', $productId, "Stock adjustment: $qty units");
            redirect('stock.php', 'Stock updated successfully.');
        }
    }
}

$productFilter = (int)($_GET['product_id'] ?? 0);
$typeFilter    = sanitize($_GET['type'] ?? '');
$dateFrom      = sanitize($_GET['from'] ?? date('Y-m-01'));
$dateTo        = sanitize($_GET['to'] ?? date('Y-m-d'));

$where  = 'WHERE sm.date BETWEEN ? AND ?';
$params = [$dateFrom, $dateTo];
if ($productFilter) { $where .= ' AND sm.product_id=?'; $params[] = $productFilter; }
if ($typeFilter) { $where .= ' AND sm.type=?'; $params[] = $typeFilter; }

$movements = dbFetchAll(
    "SELECT sm.*, p.name AS product_name, p.sku, p.unit, u.name AS user_name
     FROM stock_movements sm 
     JOIN products p ON p.id=sm.product_id 
     LEFT JOIN users u ON u.id=sm.created_by
     $where
     ORDER BY sm.created_at DESC LIMIT 300",
    $params
);

$lowStockProducts = dbFetchAll("SELECT p.*, pc.name AS cat_name FROM products p LEFT JOIN product_categories pc ON pc.id=p.category_id WHERE p.current_stock <= p.low_stock_alert AND p.is_active=1 ORDER BY p.current_stock ASC");

$products = getProducts();

pageStart('Stock Management');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-warehouse me-2"></i>Stock Management</h1>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#adjustModal"><i class="fas fa-arrows-rotate me-2"></i>Adjust Stock</button>
</div>

<!-- Low Stock Alert -->
<?php if ($lowStockProducts): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
  <i class="fas fa-exclamation-triangle fa-2x"></i>
  <div><strong><?= count($lowStockProducts) ?> products</strong> are at or below low stock threshold. <a href="#low-stock">View below</a></div>
</div>
<?php endif; ?>

<!-- Current Stock Summary -->
<div class="cj-card mb-4">
  <div class="card-header">
    <span class="card-title">Current Stock Summary</span>
    <button class="btn btn-sm btn-outline-success" onclick="CJ.exportCSV('stockTable','current_stock')"><i class="fas fa-download me-1"></i>Export</button>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="stockTable">
        <thead>
          <tr><th>SKU</th><th>Product</th><th>Unit</th><th>Current Stock</th><th>Low Alert</th><th>Buy Price</th><th>Stock Value</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php
          $allProducts = dbFetchAll("SELECT p.*, pc.name AS cat_name FROM products p LEFT JOIN product_categories pc ON pc.id=p.category_id WHERE p.is_active=1 ORDER BY p.name");
          $totalValue = 0;
          foreach ($allProducts as $p):
            $val = (float)$p['current_stock'] * (float)$p['purchase_price'];
            $totalValue += $val;
            $low = $p['current_stock'] <= $p['low_stock_alert'];
          ?>
          <tr>
            <td><code><?= h($p['sku']) ?></code></td>
            <td><div class="fw-600"><?= h($p['name']) ?></div><small class="text-muted"><?= h($p['cat_name'] ?: '') ?></small></td>
            <td><?= h($p['unit']) ?></td>
            <td class="<?= $low ? 'text-danger fw-bold' : 'fw-600' ?>"><?= $p['current_stock'] ?></td>
            <td><?= $p['low_stock_alert'] ?></td>
            <td><?= money($p['purchase_price']) ?></td>
            <td><?= money($val) ?></td>
            <td><?= $low ? '<span class="badge bg-danger">Low Stock</span>' : '<span class="badge bg-success">OK</span>' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="fw-bold table-light"><td colspan="6">Total Stock Value</td><td><?= money($totalValue) ?></td><td></td></tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<!-- Stock Movements -->
<div class="cj-card mb-4">
  <div class="card-header">
    <span class="card-title">Stock Movements</span>
    <form class="d-flex gap-2" method="GET">
      <input type="date" name="from" class="form-control form-control-sm" value="<?= h($dateFrom) ?>">
      <input type="date" name="to" class="form-control form-control-sm" value="<?= h($dateTo) ?>">
      <select name="product_id" class="form-select form-select-sm">
        <option value="">All Products</option>
        <?php foreach ($products as $p): ?>
        <option value="<?= $p['id'] ?>" <?= $p['id']==$productFilter?'selected':'' ?>><?= h($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="type" class="form-select form-select-sm">
        <option value="">All Types</option>
        <option value="in">In</option><option value="out">Out</option><option value="adjustment">Adjustment</option>
      </select>
      <button class="btn btn-sm btn-outline-primary">Filter</button>
    </form>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr><th>Date</th><th>Product</th><th>Type</th><th>Qty</th><th>Unit Cost</th><th>Reference</th><th>By</th></tr>
        </thead>
        <tbody>
          <?php foreach ($movements as $m): ?>
          <tr>
            <td><?= formatDateTime($m['created_at']) ?></td>
            <td><?= h($m['product_name']) ?> <small class="text-muted"><?= h($m['sku']) ?></small></td>
            <td>
              <span class="badge bg-<?= in_array($m['type'],['in','opening'])?'success':($m['type']==='out'?'danger':'warning') ?>">
                <?= ucfirst($m['type']) ?>
              </span>
            </td>
            <td class="fw-600"><?= $m['quantity'] ?> <?= h($m['unit']) ?></td>
            <td><?= $m['unit_cost'] > 0 ? money($m['unit_cost']) : '—' ?></td>
            <td><small><?= h($m['reference_type'] ? $m['reference_type'].'#'.$m['reference_id'] : '—') ?></small></td>
            <td class="text-muted small"><?= h($m['user_name'] ?: '—') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$movements): ?>
          <tr><td colspan="7" class="text-center py-4 text-muted">No stock movements found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Low Stock -->
<?php if ($lowStockProducts): ?>
<div class="cj-card" id="low-stock">
  <div class="card-header"><span class="card-title text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Low Stock Products (<?= count($lowStockProducts) ?>)</span></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>SKU</th><th>Product</th><th>Category</th><th>Current Stock</th><th>Alert Level</th><th>Action</th></tr></thead>
        <tbody>
          <?php foreach ($lowStockProducts as $p): ?>
          <tr class="table-warning">
            <td><code><?= h($p['sku']) ?></code></td>
            <td><?= h($p['name']) ?></td>
            <td><?= h($p['cat_name'] ?: '—') ?></td>
            <td class="text-danger fw-bold"><?= $p['current_stock'] ?> <?= h($p['unit']) ?></td>
            <td><?= $p['low_stock_alert'] ?> <?= h($p['unit']) ?></td>
            <td>
              <button class="btn btn-sm btn-outline-primary" onclick="stockIn(<?= $p['id'] ?>, '<?= h($p['name']) ?>')">
                <i class="fas fa-plus me-1"></i>Stock In
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Adjust Stock Modal -->
<div class="modal fade" id="adjustModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="adjust">
        <div class="modal-header"><h5 class="modal-title">Stock Adjustment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Product <span class="text-danger">*</span></label>
              <select name="product_id" id="adj_product" class="form-select" required>
                <option value="">-- Select Product --</option>
                <?php foreach ($products as $p): ?>
                <option value="<?= $p['id'] ?>"><?= h($p['name']) ?> (<?= h($p['sku']) ?>) — <?= $p['current_stock'] ?> <?= h($p['unit']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Type</label>
              <select name="type" class="form-select">
                <option value="in">Stock In</option>
                <option value="out">Stock Out</option>
                <option value="adjustment">Adjustment</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Quantity</label>
              <input type="number" name="quantity" class="form-control" step="0.001" min="0.001" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Unit Cost (৳)</label>
              <input type="number" name="unit_cost" class="form-control" step="0.01" min="0" value="0">
            </div>
            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
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
function stockIn(productId, name) {
  document.getElementById('adj_product').value = productId;
  document.querySelector('[name="type"]').value = 'in';
  new bootstrap.Modal(document.getElementById('adjustModal')).show();
}
</script>

<?php pageEnd(); ?>
