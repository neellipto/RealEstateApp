<?php
/**
 * COLORJET ERP - Helper Functions
 */

function h(mixed $val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(float $amount, string $symbol = '৳'): string {
    return $symbol . number_format($amount, 2);
}

function formatDate(string $date, string $fmt = 'd M Y'): string {
    if (!$date || $date === '0000-00-00') return '-';
    return date($fmt, strtotime($date));
}

function formatDateTime(string $dt, string $fmt = 'd M Y H:i'): string {
    if (!$dt || $dt === '0000-00-00 00:00:00') return '-';
    return date($fmt, strtotime($dt));
}

function timeAgo(string $datetime): string {
    $now  = new DateTime();
    $then = new DateTime($datetime);
    $diff = $now->diff($then);
    if ($diff->y > 0) return $diff->y . 'y ago';
    if ($diff->m > 0) return $diff->m . 'mo ago';
    if ($diff->d > 0) return $diff->d . 'd ago';
    if ($diff->h > 0) return $diff->h . 'h ago';
    if ($diff->i > 0) return $diff->i . 'm ago';
    return 'just now';
}

function priorityBadge(string $p): string {
    $map = ['low'=>'secondary','normal'=>'primary','high'=>'warning','urgent'=>'danger'];
    $cls = $map[$p] ?? 'secondary';
    return "<span class='badge bg-{$cls}'>" . ucfirst($p) . "</span>";
}

function statusBadge(string $s): string {
    $map = [
        'new'=>'info','open'=>'info','draft'=>'secondary',
        'in_progress'=>'primary','assigned'=>'primary','processing'=>'primary',
        'waiting'=>'warning','parts_required'=>'warning','partial'=>'warning',
        'completed'=>'success','resolved'=>'success','paid'=>'success','closed'=>'success','active'=>'success','delivered'=>'success','shipped'=>'success','released'=>'success','warehouse'=>'success',
        'cancelled'=>'danger','defaulted'=>'danger','overdue'=>'danger','failed'=>'danger','blacklisted'=>'danger',
        'lc_opened'=>'info','tt_paid'=>'info','production'=>'info','port_arrived'=>'warning','customs'=>'warning',
        'sent'=>'info','converted'=>'success','won'=>'success','lost'=>'danger','qualified'=>'info','proposal'=>'warning','negotiation'=>'warning','contacted'=>'secondary',
    ];
    $cls = $map[$s] ?? 'secondary';
    $label = ucwords(str_replace('_', ' ', $s));
    return "<span class='badge bg-{$cls}'>{$label}</span>";
}

function sanitize(string $val): string {
    return trim(strip_tags($val));
}

function validateRequired(array $data, array $fields): array {
    $errors = [];
    foreach ($fields as $f) {
        if (empty($data[$f])) {
            $errors[] = ucwords(str_replace('_', ' ', $f)) . ' is required.';
        }
    }
    return $errors;
}

function redirect(string $url, string $message = '', string $type = 'success'): never {
    if ($message) {
        startSession();
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }
    header("Location: $url");
    exit;
}

function flash(): string {
    startSession();
    if (empty($_SESSION['flash'])) return '';
    $f   = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $map = ['success'=>'success','error'=>'danger','warning'=>'warning','info'=>'info'];
    $cls = $map[$f['type']] ?? 'info';
    return "<div class='alert alert-{$cls} alert-dismissible fade show' role='alert'>"
         . h($f['message'])
         . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
}

function getSettings(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    try {
        $rows = dbFetchAll('SELECT key_name, value FROM settings');
        $cfg  = array_column($rows, 'value', 'key_name');
    } catch (Throwable) {
        $cfg = [];
    }
    return $cfg;
}

function getSetting(string $key, string $default = ''): string {
    return getSettings()[$key] ?? $default;
}

function getUsers(string $role = ''): array {
    if ($role) {
        return dbFetchAll(
            'SELECT u.id, u.name, u.employee_id, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name=? AND u.is_active=1 ORDER BY u.name',
            [$role]
        );
    }
    return dbFetchAll(
        'SELECT u.id, u.name, u.employee_id, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.is_active=1 ORDER BY u.name'
    );
}

function getCustomers(): array {
    return dbFetchAll('SELECT id, name, customer_code, phone FROM customers WHERE status="active" ORDER BY name');
}

function getProducts(bool $activeOnly = true): array {
    $where = $activeOnly ? 'WHERE is_active=1' : '';
    return dbFetchAll("SELECT id, sku, name, unit, current_stock, selling_price FROM products $where ORDER BY name");
}

function getSuppliers(): array {
    return dbFetchAll("SELECT id, name, supplier_code, country, currency FROM suppliers WHERE status='active' ORDER BY name");
}

function getEngineers(): array {
    return dbFetchAll(
        "SELECT ep.id, u.name, u.phone, ep.is_available FROM engineer_profiles ep JOIN users u ON u.id=ep.user_id WHERE u.is_active=1 ORDER BY u.name"
    );
}

function updateStock(int $productId, float $qty, string $type, string $refType = '', int $refId = 0, float $unitCost = 0, int $userId = 0): void {
    $sign = in_array($type, ['in', 'opening']) ? 1 : -1;
    dbInsert('stock_movements', [
        'product_id'     => $productId,
        'type'           => $type,
        'quantity'       => abs($qty),
        'unit_cost'      => $unitCost,
        'reference_type' => $refType,
        'reference_id'   => $refId,
        'created_by'     => $userId ?: userId(),
    ]);
    dbQuery(
        'UPDATE products SET current_stock = current_stock + ? WHERE id = ?',
        [$sign * abs($qty), $productId]
    );
}

function addCustomerLedger(int $custId, string $date, string $type, string $desc, float $amount, string $refType = '', int $refId = 0): void {
    dbInsert('customer_ledger', [
        'customer_id'    => $custId,
        'date'           => $date,
        'type'           => $type,
        'description'    => $desc,
        'amount'         => $amount,
        'reference_type' => $refType,
        'reference_id'   => $refId,
        'created_by'     => userId(),
    ]);
}

function addSupplierLedger(int $suppId, string $date, string $type, string $desc, float $amount, string $currency = 'BDT', string $refType = '', int $refId = 0): void {
    dbInsert('supplier_ledger', [
        'supplier_id'    => $suppId,
        'date'           => $date,
        'type'           => $type,
        'description'    => $desc,
        'amount'         => $amount,
        'currency'       => $currency,
        'reference_type' => $refType,
        'reference_id'   => $refId,
        'created_by'     => userId(),
    ]);
}

function addDailyLedger(string $date, string $type, string $category, string $desc, float $amount, string $mode = 'cash', string $refType = '', int $refId = 0): void {
    dbInsert('daily_ledger', [
        'date'           => $date,
        'type'           => $type,
        'category'       => $category,
        'description'    => $desc,
        'amount'         => $amount,
        'payment_mode'   => $mode,
        'reference_type' => $refType,
        'reference_id'   => $refId,
        'created_by'     => userId(),
    ]);
}

function generateEmiSchedule(int $agreementId, int $customerId, float $emiAmount, int $months, string $startDate, int $emiDay): void {
    $date = new DateTime($startDate);
    $date->modify('first day of next month');
    $date->setDate((int)$date->format('Y'), (int)$date->format('m'), min($emiDay, (int)$date->format('t')));

    for ($i = 1; $i <= $months; $i++) {
        dbInsert('emi_schedules', [
            'agreement_id'       => $agreementId,
            'customer_id'        => $customerId,
            'installment_number' => $i,
            'due_date'           => $date->format('Y-m-d'),
            'amount'             => $emiAmount,
        ]);
        $date->modify('+1 month');
        $day = min($emiDay, (int)$date->format('t'));
        $date->setDate((int)$date->format('Y'), (int)$date->format('m'), $day);
    }
}

function uploadFile(array $file, string $subDir = ''): array {
    $uploadPath = defined('UPLOAD_PATH') ? UPLOAD_PATH : dirname(__DIR__) . '/uploads/';
    $dir = rtrim($uploadPath . $subDir, '/') . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $allowed = defined('ALLOWED_FILE_TYPES') ? ALLOWED_FILE_TYPES : ['jpg','jpeg','png','pdf','doc','docx','xls','xlsx','csv'];
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) return ['ok' => false, 'message' => 'File type not allowed.'];
    if ($file['size'] > (defined('UPLOAD_MAX_SIZE') ? UPLOAD_MAX_SIZE : 10485760)) return ['ok' => false, 'message' => 'File too large.'];

    $filename = uniqid('cj_', true) . '.' . $ext;
    $dest     = $dir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return ['ok' => false, 'message' => 'Upload failed.'];
    return ['ok' => true, 'filename' => $filename, 'path' => ($subDir ? $subDir . '/' : '') . $filename];
}

function generateAgreementText(array $a, array $c): string {
    $date      = date('d F Y', strtotime($a['date'] ?? date('Y-m-d')));
    $emiMonths = (int)($a['emi_months'] ?? 0);
    $emiAmount = money((float)($a['emi_amount'] ?? 0));
    $cashPrice = money((float)($a['cash_price'] ?? 0));
    $downPay   = money((float)($a['down_payment'] ?? 0));
    $remaining = money((float)($a['remaining_amount'] ?? 0));

    return <<<TEXT
INSTALLMENT SALE AGREEMENT

This Agreement is made on {$date} between:

SELLER: COLORJET Bangladesh
Address: Dhaka, Bangladesh
(hereinafter referred to as "the Seller")

AND

BUYER: {$c['name']}
Father/Mother: {$c['father_name']}
Address: {$c['address']}
Phone: {$c['phone']}
NID Number: {$c['nid_number']}
(hereinafter referred to as "the Buyer")

MACHINE DETAILS:
Model: {$a['machine_model']}
Serial Number: {$a['serial_number']}
Accessories: {$a['accessories']}

PAYMENT TERMS:
Cash Price: {$cashPrice}
Down Payment: {$downPay}
Remaining Amount: {$remaining}
Monthly Installment: {$emiAmount} x {$emiMonths} months
Payment Due Date: {$a['emi_day']}th of each month
Payment Mode: {$a['payment_mode']}

TERMS AND CONDITIONS:

1. This agreement is governed by the Bangladesh Contract Act 1872 and the Sale of Goods Act 1930.

2. WARRANTY: The Seller provides a 1-year Engineer Service Warranty from delivery date. Warranty covers: Mainboard, Headboard, Servo Motor, and Driver. Warranty does NOT cover: Printhead and small spare parts.

3. INSTALLMENT OBLIGATION: The Buyer agrees to pay each installment on or before the due date. If two (2) consecutive installments remain unpaid, the Seller reserves the right to repossess the machine and initiate legal action under applicable Bangladesh law.

4. TRANSFER RESTRICTION: The Buyer shall not sell, lease, transfer, or mortgage the machine to any third party until all installments are fully paid. Any such transfer without prior written consent of the Seller shall be void.

5. LATE PAYMENT: A late fee of 2% per month shall be charged on overdue installments.

6. DEFAULT: In the event of default, the Seller may repossess the machine without further notice. Down payment and installments already paid shall be treated as usage/rental charges and shall not be refundable.

7. SPECIAL TERMS: {$a['special_terms']}

SIGNATURES:

Seller Representative: _______________________     Date: ___________

Buyer Signature: _______________________           Date: ___________

Buyer Thumbprint: □

WITNESS 1:
Name: {$a['witness1_name']}
Phone: {$a['witness1_phone']}
Signature: _______________________

WITNESS 2:
Name: {$a['witness2_name']}
Phone: {$a['witness2_phone']}
Signature: _______________________

This agreement has been read, understood and signed willingly by both parties.

COLORJET Bangladesh — Quality • Commitment • Service
TEXT;
}

function getDashboardStats(): array {
    $today     = date('Y-m-d');
    $monthStart = date('Y-m-01');

    $stats = [];

    // Sales
    $stats['today_sales']   = (float)(dbFetch("SELECT COALESCE(SUM(total),0) AS v FROM invoices WHERE DATE(created_at)=?", [$today])['v'] ?? 0);
    $stats['month_sales']   = (float)(dbFetch("SELECT COALESCE(SUM(total),0) AS v FROM invoices WHERE created_at>=?", [$monthStart])['v'] ?? 0);
    $stats['total_due']     = (float)(dbFetch("SELECT COALESCE(SUM(due_amount),0) AS v FROM invoices WHERE status NOT IN ('paid','cancelled')")['v'] ?? 0);

    // Collections
    $stats['today_collection'] = (float)(dbFetch("SELECT COALESCE(SUM(amount),0) AS v FROM payments WHERE type='receive' AND DATE(created_at)=?", [$today])['v'] ?? 0);

    // Cash/bank
    $cash_in  = (float)(dbFetch("SELECT COALESCE(SUM(amount),0) AS v FROM daily_ledger WHERE type='income' AND payment_mode='cash'")['v'] ?? 0);
    $cash_out = (float)(dbFetch("SELECT COALESCE(SUM(amount),0) AS v FROM daily_ledger WHERE type='expense' AND payment_mode='cash'")['v'] ?? 0);
    $stats['cash_balance'] = $cash_in - $cash_out;

    // Tasks
    $stats['pending_tasks']   = (int)(dbFetch("SELECT COUNT(*) AS v FROM office_tasks WHERE status NOT IN ('completed','cancelled')")['v'] ?? 0);
    $stats['today_tasks']     = (int)(dbFetch("SELECT COUNT(*) AS v FROM office_tasks WHERE DATE(due_date)=?", [$today])['v'] ?? 0);
    $stats['overdue_tasks']   = (int)(dbFetch("SELECT COUNT(*) AS v FROM office_tasks WHERE due_date < NOW() AND status NOT IN ('completed','cancelled')")['v'] ?? 0);

    // Engineer
    $stats['pending_jobs']    = (int)(dbFetch("SELECT COUNT(*) AS v FROM engineer_schedule WHERE status IN ('scheduled','in_progress')")['v'] ?? 0);
    $stats['today_jobs']      = (int)(dbFetch("SELECT COUNT(*) AS v FROM engineer_schedule WHERE scheduled_date=?", [$today])['v'] ?? 0);

    // Service
    $stats['open_tickets']    = (int)(dbFetch("SELECT COUNT(*) AS v FROM service_tickets WHERE status NOT IN ('resolved','closed')")['v'] ?? 0);
    $stats['warranty_tickets']= (int)(dbFetch("SELECT COUNT(*) AS v FROM service_tickets WHERE is_warranty=1 AND status NOT IN ('resolved','closed')")['v'] ?? 0);

    // Stock
    $stats['low_stock']       = (int)(dbFetch("SELECT COUNT(*) AS v FROM products WHERE current_stock <= low_stock_alert AND is_active=1")['v'] ?? 0);

    // Supplier
    $stats['supplier_due']    = (float)(dbFetch("SELECT COALESCE(SUM(balance_payment),0) AS v FROM purchase_orders WHERE status NOT IN ('closed','cancelled')")['v'] ?? 0);
    $stats['open_lc']         = (int)(dbFetch("SELECT COUNT(*) AS v FROM lc_records WHERE status NOT IN ('closed')")['v'] ?? 0);
    $stats['in_transit']      = (int)(dbFetch("SELECT COUNT(*) AS v FROM purchase_orders WHERE status IN ('shipped','port_arrived','customs')")['v'] ?? 0);

    // EMI overdue
    $stats['emi_overdue']     = (int)(dbFetch("SELECT COUNT(*) AS v FROM emi_schedules WHERE due_date < ? AND status='pending'", [$today])['v'] ?? 0);

    // Customers
    $stats['total_customers'] = (int)(dbFetch("SELECT COUNT(*) AS v FROM customers")['v'] ?? 0);
    $stats['active_agreements']= (int)(dbFetch("SELECT COUNT(*) AS v FROM agreements WHERE status='active'")['v'] ?? 0);

    return $stats;
}
