<?php
require_once 'includes/layout.php';
requireLogin();

$report   = sanitize($_GET['report'] ?? 'overview');
$dateFrom = sanitize($_GET['from'] ?? date('Y-m-01'));
$dateTo   = sanitize($_GET['to']   ?? date('Y-m-d'));

$reports = [
    'overview'       => ['Sales & Revenue Overview', 'fas fa-chart-line'],
    'sales'          => ['Sales Report', 'fas fa-shopping-cart'],
    'collection'     => ['Collection Report', 'fas fa-money-bill-wave'],
    'due'            => ['Due Report', 'fas fa-exclamation-triangle'],
    'customer_ledger'=> ['Customer Ledger Summary', 'fas fa-user-check'],
    'supplier_ledger'=> ['Supplier Ledger Summary', 'fas fa-handshake'],
    'stock'          => ['Stock Report', 'fas fa-boxes-stacked'],
    'low_stock'      => ['Low Stock Report', 'fas fa-exclamation'],
    'purchase'       => ['Purchase Report', 'fas fa-plane-arrival'],
    'lc_tt'          => ['LC/TT Report', 'fas fa-building-columns'],
    'service'        => ['Service Report', 'fas fa-ticket'],
    'engineer'       => ['Engineer Performance', 'fas fa-hard-hat'],
    'emi'            => ['EMI / Agreement Report', 'fas fa-file-contract'],
    'profit_loss'    => ['Profit & Loss Summary', 'fas fa-scale-balanced'],
];

pageStart('Reports');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-chart-bar me-2"></i>Reports</h1>
  <div class="d-flex gap-2 no-print">
    <button class="btn btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
    <button class="btn btn-outline-success" onclick="CJ.exportCSV('reportTable','report_<?= $report ?>_<?= date('Ymd') ?>')"><i class="fas fa-download me-1"></i>CSV</button>
  </div>
</div>

<div class="row g-4">
  <!-- Report Menu -->
  <div class="col-lg-2 no-print">
    <div class="cj-card">
      <div class="card-body p-2">
        <?php foreach ($reports as $key => [$label, $icon]): ?>
        <a href="?report=<?= $key ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>"
           class="nav-item-link <?= $report===$key?'active':'' ?> d-flex align-items-center gap-2 mb-1">
          <i class="fas <?= $icon ?>"></i><span class="small"><?= $label ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Report Content -->
  <div class="col-lg-10">
    <!-- Date Filter -->
    <div class="cj-card mb-4 no-print">
      <div class="card-body">
        <form class="row g-3" method="GET">
          <input type="hidden" name="report" value="<?= h($report) ?>">
          <div class="col-md-4"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= h($dateFrom) ?>"></div>
          <div class="col-md-4"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= h($dateTo) ?>"></div>
          <div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary w-100">Generate Report</button></div>
        </form>
      </div>
    </div>

    <!-- Report Title -->
    <div class="cj-card">
      <div class="card-header">
        <span class="card-title"><i class="fas <?= $reports[$report][1] ?? 'fa-chart-bar' ?> me-2"></i><?= $reports[$report][0] ?? 'Report' ?></span>
        <span class="text-muted small"><?= formatDate($dateFrom) ?> — <?= formatDate($dateTo) ?></span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0" id="reportTable">
            <?php

            switch ($report) {
                case 'overview':
                    $sales  = dbFetch("SELECT COALESCE(SUM(total),0) AS t, COALESCE(SUM(paid_amount),0) AS p, COALESCE(SUM(due_amount),0) AS d, COUNT(*) AS c FROM invoices WHERE date BETWEEN ? AND ?", [$dateFrom,$dateTo]);
                    $expenses = dbFetch("SELECT COALESCE(SUM(amount),0) AS t FROM daily_ledger WHERE type='expense' AND date BETWEEN ? AND ?", [$dateFrom,$dateTo]);
                    $collections = dbFetch("SELECT COALESCE(SUM(amount),0) AS t FROM payments WHERE type='receive' AND date BETWEEN ? AND ?", [$dateFrom,$dateTo]);
                    echo "<thead><tr><th>Metric</th><th>Value</th></tr></thead><tbody>";
                    echo "<tr><td>Total Invoices</td><td class='fw-600'>{$sales['c']}</td></tr>";
                    echo "<tr><td>Total Sales</td><td class='fw-600'>" . money($sales['t']) . "</td></tr>";
                    echo "<tr><td>Total Collected</td><td class='fw-600 text-success'>" . money($sales['p']) . "</td></tr>";
                    echo "<tr><td>Total Due</td><td class='fw-600 text-danger'>" . money($sales['d']) . "</td></tr>";
                    echo "<tr><td>Total Expenses</td><td class='fw-600 text-danger'>" . money($expenses['t']) . "</td></tr>";
                    echo "<tr><td>Cash Collections</td><td class='fw-600 text-success'>" . money($collections['t']) . "</td></tr>";
                    echo "<tr class='table-success fw-bold'><td>Estimated Profit</td><td>" . money($sales['t'] - $expenses['t']) . "</td></tr>";
                    echo "</tbody>";
                    break;

                case 'sales':
                    $rows = dbFetchAll("SELECT i.*, c.name AS customer_name FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.date BETWEEN ? AND ? ORDER BY i.date DESC", [$dateFrom,$dateTo]);
                    echo "<thead><tr><th>Date</th><th>Invoice #</th><th>Customer</th><th>Total</th><th>Paid</th><th>Due</th><th>Status</th></tr></thead><tbody>";
                    foreach ($rows as $r) {
                        echo "<tr><td>" . formatDate($r['date']) . "</td><td>{$r['invoice_number']}</td><td>" . h($r['customer_name']) . "</td><td class='fw-600'>" . money($r['total']) . "</td><td class='text-success'>" . money($r['paid_amount']) . "</td><td class='text-danger'>" . money($r['due_amount']) . "</td><td>" . statusBadge($r['status']) . "</td></tr>";
                    }
                    if (!$rows) echo "<tr><td colspan='7' class='text-center py-4 text-muted'>No data.</td></tr>";
                    $tot = array_sum(array_column($rows,'total'));
                    echo "<tr class='fw-bold table-light'><td colspan='3'>Total</td><td>" . money($tot) . "</td><td></td><td></td><td></td></tr>";
                    echo "</tbody>";
                    break;

                case 'collection':
                    $rows = dbFetchAll("SELECT p.*, CASE WHEN p.party_type='customer' THEN c.name ELSE 'Other' END AS party_name FROM payments p LEFT JOIN customers c ON c.id=p.party_id WHERE p.type='receive' AND p.date BETWEEN ? AND ? ORDER BY p.date DESC", [$dateFrom,$dateTo]);
                    echo "<thead><tr><th>Date</th><th>Ref #</th><th>Party</th><th>Mode</th><th>Amount</th></tr></thead><tbody>";
                    foreach ($rows as $r) {
                        echo "<tr><td>" . formatDate($r['date']) . "</td><td>{$r['payment_number']}</td><td>" . h($r['party_name']) . "</td><td>" . h($r['payment_mode']) . "</td><td class='fw-600 text-success'>" . money($r['amount']) . "</td></tr>";
                    }
                    if (!$rows) echo "<tr><td colspan='5' class='text-center py-4 text-muted'>No data.</td></tr>";
                    $tot = array_sum(array_column($rows,'amount'));
                    echo "<tr class='fw-bold table-light'><td colspan='4'>Total</td><td>" . money($tot) . "</td></tr>";
                    echo "</tbody>";
                    break;

                case 'due':
                    $rows = dbFetchAll("SELECT i.*, c.name AS customer_name, c.phone FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.due_amount > 0 AND i.status NOT IN ('paid','cancelled') ORDER BY i.due_amount DESC");
                    echo "<thead><tr><th>Invoice #</th><th>Customer</th><th>Phone</th><th>Invoice Total</th><th>Due Amount</th><th>Due Date</th><th>Status</th></tr></thead><tbody>";
                    foreach ($rows as $r) {
                        echo "<tr><td>{$r['invoice_number']}</td><td>" . h($r['customer_name']) . "</td><td>" . h($r['phone']) . "</td><td>" . money($r['total']) . "</td><td class='text-danger fw-bold'>" . money($r['due_amount']) . "</td><td>" . ($r['due_date'] ? formatDate($r['due_date']) : '—') . "</td><td>" . statusBadge($r['status']) . "</td></tr>";
                    }
                    if (!$rows) echo "<tr><td colspan='7' class='text-center py-4 text-success'>All invoices paid!</td></tr>";
                    $tot = array_sum(array_column($rows,'due_amount'));
                    echo "<tr class='fw-bold table-danger'><td colspan='4'>Total Due</td><td>" . money($tot) . "</td><td colspan='2'></td></tr>";
                    echo "</tbody>";
                    break;

                case 'stock':
                    $rows = dbFetchAll("SELECT p.sku, p.name, pc.name AS cat, p.unit, p.current_stock, p.purchase_price, p.selling_price, (p.current_stock * p.purchase_price) AS stock_value FROM products p LEFT JOIN product_categories pc ON pc.id=p.category_id WHERE p.is_active=1 ORDER BY p.name");
                    echo "<thead><tr><th>SKU</th><th>Product</th><th>Category</th><th>Unit</th><th>Stock</th><th>Buy Price</th><th>Sell Price</th><th>Stock Value</th></tr></thead><tbody>";
                    $totalVal = 0;
                    foreach ($rows as $r) {
                        $totalVal += $r['stock_value'];
                        echo "<tr><td><code>{$r['sku']}</code></td><td>" . h($r['name']) . "</td><td>" . h($r['cat']) . "</td><td>" . h($r['unit']) . "</td><td class='fw-600'>{$r['current_stock']}</td><td>" . money($r['purchase_price']) . "</td><td>" . money($r['selling_price']) . "</td><td>" . money($r['stock_value']) . "</td></tr>";
                    }
                    echo "<tr class='fw-bold table-light'><td colspan='7'>Total Stock Value</td><td>" . money($totalVal) . "</td></tr>";
                    echo "</tbody>";
                    break;

                case 'low_stock':
                    $rows = dbFetchAll("SELECT p.sku, p.name, pc.name AS cat, p.unit, p.current_stock, p.low_stock_alert FROM products p LEFT JOIN product_categories pc ON pc.id=p.category_id WHERE p.current_stock <= p.low_stock_alert AND p.is_active=1 ORDER BY p.current_stock ASC");
                    echo "<thead><tr><th>SKU</th><th>Product</th><th>Category</th><th>Unit</th><th>Current Stock</th><th>Alert Level</th></tr></thead><tbody>";
                    foreach ($rows as $r) {
                        echo "<tr class='table-warning'><td><code>{$r['sku']}</code></td><td>" . h($r['name']) . "</td><td>" . h($r['cat']) . "</td><td>" . h($r['unit']) . "</td><td class='text-danger fw-bold'>{$r['current_stock']}</td><td>{$r['low_stock_alert']}</td></tr>";
                    }
                    if (!$rows) echo "<tr><td colspan='6' class='text-center py-4 text-success'><i class='fas fa-check-circle me-2'></i>All stock levels OK!</td></tr>";
                    echo "</tbody>";
                    break;

                case 'service':
                    $rows = dbFetchAll("SELECT st.ticket_number, c.name AS customer_name, c.phone, st.issue_category, st.priority, st.status, st.service_cost, u.name AS engineer_name, st.created_at FROM service_tickets st LEFT JOIN customers c ON c.id=st.customer_id LEFT JOIN users u ON u.id=st.assigned_engineer WHERE DATE(st.created_at) BETWEEN ? AND ? ORDER BY st.created_at DESC", [$dateFrom,$dateTo]);
                    echo "<thead><tr><th>Ticket #</th><th>Customer</th><th>Category</th><th>Priority</th><th>Engineer</th><th>Status</th><th>Cost</th><th>Date</th></tr></thead><tbody>";
                    foreach ($rows as $r) {
                        echo "<tr><td>{$r['ticket_number']}</td><td>" . h($r['customer_name']) . "</td><td>" . h($r['issue_category']) . "</td><td>" . priorityBadge($r['priority']) . "</td><td>" . h($r['engineer_name'] ?? '—') . "</td><td>" . statusBadge($r['status']) . "</td><td>" . money($r['service_cost']) . "</td><td>" . formatDate($r['created_at']) . "</td></tr>";
                    }
                    if (!$rows) echo "<tr><td colspan='8' class='text-center py-4 text-muted'>No data.</td></tr>";
                    echo "</tbody>";
                    break;

                case 'engineer':
                    $rows = dbFetchAll("SELECT u.name, COUNT(es.id) AS total_jobs, SUM(es.status='completed') AS completed, SUM(es.status='scheduled') AS pending, SUM(es.status='cancelled') AS cancelled FROM engineer_profiles ep JOIN users u ON u.id=ep.user_id LEFT JOIN engineer_schedule es ON es.engineer_id=ep.id AND es.scheduled_date BETWEEN ? AND ? GROUP BY ep.id, u.name ORDER BY completed DESC", [$dateFrom,$dateTo]);
                    echo "<thead><tr><th>Engineer</th><th>Total Jobs</th><th>Completed</th><th>Pending</th><th>Cancelled</th><th>Completion Rate</th></tr></thead><tbody>";
                    foreach ($rows as $r) {
                        $rate = $r['total_jobs'] > 0 ? round($r['completed'] / $r['total_jobs'] * 100) : 0;
                        echo "<tr><td><strong>" . h($r['name']) . "</strong></td><td>{$r['total_jobs']}</td><td class='text-success fw-600'>{$r['completed']}</td><td class='text-warning'>{$r['pending']}</td><td class='text-danger'>{$r['cancelled']}</td><td><div class='progress'><div class='progress-bar bg-success' style='width:{$rate}%'>{$rate}%</div></div></td></tr>";
                    }
                    if (!$rows) echo "<tr><td colspan='6' class='text-center py-4 text-muted'>No data.</td></tr>";
                    echo "</tbody>";
                    break;

                case 'emi':
                    $rows = dbFetchAll("SELECT a.agreement_number, c.name AS customer_name, a.machine_model, a.cash_price, a.emi_amount, a.emi_months, (SELECT COUNT(*) FROM emi_schedules WHERE agreement_id=a.id AND status='paid') AS paid_count, (SELECT COUNT(*) FROM emi_schedules WHERE agreement_id=a.id AND status='pending' AND due_date < CURDATE()) AS overdue_count FROM agreements a JOIN customers c ON c.id=a.customer_id WHERE a.status='active' ORDER BY a.created_at DESC");
                    echo "<thead><tr><th>Agreement #</th><th>Customer</th><th>Machine</th><th>Total Value</th><th>EMI</th><th>Months</th><th>Paid</th><th>Overdue</th></tr></thead><tbody>";
                    foreach ($rows as $r) {
                        echo "<tr><td>{$r['agreement_number']}</td><td>" . h($r['customer_name']) . "</td><td>" . h($r['machine_model']) . "</td><td>" . money($r['cash_price']) . "</td><td>" . money($r['emi_amount']) . "</td><td>{$r['emi_months']}</td><td class='text-success'>{$r['paid_count']}</td><td class='text-danger fw-600'>{$r['overdue_count']}</td></tr>";
                    }
                    if (!$rows) echo "<tr><td colspan='8' class='text-center py-4 text-muted'>No active agreements.</td></tr>";
                    echo "</tbody>";
                    break;

                case 'purchase':
                    $rows = dbFetchAll("SELECT po.po_number, s.name AS supplier_name, po.currency, po.pi_value, po.pi_value_bdt, po.advance_payment, po.balance_payment, po.status, po.date FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id WHERE po.date BETWEEN ? AND ? ORDER BY po.date DESC", [$dateFrom,$dateTo]);
                    echo "<thead><tr><th>PO #</th><th>Supplier</th><th>Currency</th><th>PI Value</th><th>BDT</th><th>Advance</th><th>Balance Due</th><th>Status</th><th>Date</th></tr></thead><tbody>";
                    foreach ($rows as $r) {
                        echo "<tr><td>{$r['po_number']}</td><td>" . h($r['supplier_name']) . "</td><td>{$r['currency']}</td><td>" . number_format($r['pi_value'],2) . "</td><td>" . money($r['pi_value_bdt']) . "</td><td class='text-success'>" . number_format($r['advance_payment'],2) . "</td><td class='text-danger fw-600'>" . number_format($r['balance_payment'],2) . "</td><td>" . statusBadge($r['status']) . "</td><td>" . formatDate($r['date']) . "</td></tr>";
                    }
                    if (!$rows) echo "<tr><td colspan='9' class='text-center py-4 text-muted'>No data.</td></tr>";
                    echo "</tbody>";
                    break;

                case 'lc_tt':
                    $rows = dbFetchAll("SELECT lc.lc_number, lc.type, s.name AS supplier_name, lc.lc_value, lc.currency, lc.lc_charge, lc.amendment_charge, lc.bank_charge, lc.status, lc.lc_date, lc.expiry_date FROM lc_records lc JOIN suppliers s ON s.id=lc.supplier_id WHERE lc.lc_date BETWEEN ? AND ? ORDER BY lc.lc_date DESC", [$dateFrom,$dateTo]);
                    echo "<thead><tr><th>LC #</th><th>Type</th><th>Supplier</th><th>Value</th><th>Currency</th><th>LC Charge</th><th>Amend</th><th>Bank</th><th>Status</th><th>Date</th></tr></thead><tbody>";
                    foreach ($rows as $r) {
                        echo "<tr><td>{$r['lc_number']}</td><td>{$r['type']}</td><td>" . h($r['supplier_name']) . "</td><td>" . number_format($r['lc_value'],2) . "</td><td>{$r['currency']}</td><td>" . money($r['lc_charge']) . "</td><td>" . money($r['amendment_charge']) . "</td><td>" . money($r['bank_charge']) . "</td><td>" . statusBadge($r['status']) . "</td><td>" . formatDate($r['lc_date']) . "</td></tr>";
                    }
                    if (!$rows) echo "<tr><td colspan='10' class='text-center py-4 text-muted'>No data.</td></tr>";
                    echo "</tbody>";
                    break;

                case 'profit_loss':
                    $income_rows = dbFetchAll("SELECT category, SUM(amount) AS total FROM daily_ledger WHERE type='income' AND date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC", [$dateFrom,$dateTo]);
                    $expense_rows = dbFetchAll("SELECT category, SUM(amount) AS total FROM daily_ledger WHERE type='expense' AND date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC", [$dateFrom,$dateTo]);
                    $totalIncome = array_sum(array_column($income_rows,'total'));
                    $totalExpense = array_sum(array_column($expense_rows,'total'));
                    echo "<thead><tr><th>Income Category</th><th>Amount</th><th>Expense Category</th><th>Amount</th></tr></thead><tbody>";
                    $maxRows = max(count($income_rows), count($expense_rows));
                    for ($i = 0; $i < $maxRows; $i++) {
                        $inc = $income_rows[$i] ?? null;
                        $exp = $expense_rows[$i] ?? null;
                        echo "<tr><td class='text-success'>" . h($inc['category'] ?? '') . "</td><td class='text-success fw-600'>" . ($inc ? money($inc['total']) : '') . "</td><td class='text-danger'>" . h($exp['category'] ?? '') . "</td><td class='text-danger fw-600'>" . ($exp ? money($exp['total']) : '') . "</td></tr>";
                    }
                    echo "<tr class='fw-bold table-success'><td>Total Income</td><td>" . money($totalIncome) . "</td><td class='table-danger'>Total Expense</td><td>" . money($totalExpense) . "</td></tr>";
                    echo "<tr class='fw-bold table-primary'><td colspan='2'>Net Profit/Loss</td><td colspan='2'>" . money($totalIncome - $totalExpense) . ($totalIncome >= $totalExpense ? ' <span class=\"badge bg-success\">Profit</span>' : ' <span class=\"badge bg-danger\">Loss</span>') . "</td></tr>";
                    echo "</tbody>";
                    break;

                default:
                    echo "<thead><tr><th>Report</th></tr></thead><tbody><tr><td class='text-center py-4 text-muted'>Select a report from the menu.</td></tr></tbody>";
            }
            ?>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php pageEnd(); ?>
