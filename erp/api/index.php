<?php
/**
 * COLORJET ERP - REST API
 * Handles AJAX requests from the frontend
 */

require_once dirname(__DIR__) . '/includes/layout.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

startSession();
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

$module = sanitize($_GET['module'] ?? $_POST['module'] ?? '');
$action = sanitize($_GET['action'] ?? $_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($module) {

        // ── Notifications ──────────────────────────────────────
        case 'notifications':
            $unread = (int)(dbFetch('SELECT COUNT(*) AS v FROM notifications WHERE user_id=? AND is_read=0', [userId()])['v'] ?? 0);
            $items  = dbFetchAll('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 10', [userId()]);
            jsonSuccess(['unread' => $unread, 'items' => $items]);

        // ── Dashboard stats (JSON) ──────────────────────────────
        case 'dashboard':
            requireLogin();
            jsonSuccess(getDashboardStats());

        // ── Customers ──────────────────────────────────────────
        case 'customers':
            if ($method === 'POST') {
                verifyCsrf();
                if ($action === 'delete') {
                    $id = (int)($_POST['id'] ?? 0);
                    if ($id) {
                        dbUpdate('customers', ['status' => 'inactive'], ['id' => $id]);
                        logActivity('delete', 'customers', $id, 'Customer deactivated');
                        jsonSuccess(null, 'Customer deactivated.');
                    }
                    jsonError('Invalid ID.');
                }
                if ($action === 'search') {
                    $q   = sanitize($_POST['q'] ?? '');
                    $res = dbFetchAll("SELECT id, name, phone, customer_code FROM customers WHERE name LIKE ? OR phone LIKE ? LIMIT 20", ["%$q%", "%$q%"]);
                    jsonSuccess($res);
                }
            }
            // GET: customer details
            $id = (int)($_GET['id'] ?? 0);
            if ($id) {
                $c = dbFetch('SELECT * FROM customers WHERE id=?', [$id]);
                jsonSuccess($c);
            }
            jsonError('Invalid request.');

        // ── Products ────────────────────────────────────────────
        case 'products':
            if ($method === 'GET') {
                $id = (int)($_GET['id'] ?? 0);
                if ($id) {
                    $p = dbFetch('SELECT * FROM products WHERE id=?', [$id]);
                    jsonSuccess($p);
                }
                // Search
                $q   = sanitize($_GET['q'] ?? '');
                $res = dbFetchAll("SELECT id, sku, name, unit, current_stock, selling_price, purchase_price FROM products WHERE (name LIKE ? OR sku LIKE ?) AND is_active=1 LIMIT 20", ["%$q%", "%$q%"]);
                jsonSuccess($res);
            }
            jsonError('Invalid request.');

        // ── Stock ───────────────────────────────────────────────
        case 'stock':
            if ($method === 'POST') {
                verifyCsrf();
                $productId = (int)($_POST['product_id'] ?? 0);
                $qty       = (float)($_POST['quantity'] ?? 0);
                $type      = in_array($_POST['type'] ?? '', ['in','out','adjustment']) ? $_POST['type'] : 'in';
                $cost      = (float)($_POST['unit_cost'] ?? 0);
                if ($productId && $qty > 0) {
                    updateStock($productId, $qty, $type, 'api', 0, $cost);
                    $p = dbFetch('SELECT current_stock, name FROM products WHERE id=?', [$productId]);
                    jsonSuccess(['current_stock' => $p['current_stock']], "Stock updated for {$p['name']}");
                }
                jsonError('Invalid data.');
            }
            jsonError('Invalid request.');

        // ── Engineers ────────────────────────────────────────────
        case 'engineer':
            if ($method === 'POST') {
                verifyCsrf();
                if ($action === 'toggle_availability') {
                    $ep = dbFetch('SELECT id, is_available FROM engineer_profiles WHERE user_id=?', [userId()]);
                    if ($ep) {
                        dbUpdate('engineer_profiles', ['is_available' => $ep['is_available'] ? 0 : 1], ['id' => $ep['id']]);
                        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'engineer-dashboard.php'));
                        exit;
                    }
                }
                if ($action === 'complete_job') {
                    $jid = (int)($_POST['schedule_id'] ?? 0);
                    if ($jid) {
                        dbUpdate('engineer_schedule', [
                            'status' => 'completed',
                            'end_time_actual' => date('Y-m-d H:i:s'),
                            'engineer_notes' => sanitize($_POST['notes'] ?? ''),
                        ], ['id' => $jid]);
                        jsonSuccess(null, 'Job completed.');
                    }
                }
            }
            jsonError('Invalid request.');

        // ── Service Tickets ──────────────────────────────────────
        case 'tickets':
            if ($method === 'POST') {
                verifyCsrf();
                if ($action === 'assign') {
                    $tid = (int)($_POST['ticket_id'] ?? 0);
                    $eid = (int)($_POST['engineer_id'] ?? 0);
                    if ($tid && $eid) {
                        dbUpdate('service_tickets', ['assigned_engineer' => $eid, 'status' => 'assigned', 'assigned_at' => date('Y-m-d H:i:s')], ['id' => $tid]);
                        jsonSuccess(null, 'Engineer assigned.');
                    }
                }
                if ($action === 'update_status') {
                    $tid = (int)($_POST['id'] ?? 0);
                    $status = sanitize($_POST['status'] ?? '');
                    if ($tid && $status) {
                        dbUpdate('service_tickets', ['status' => $status], ['id' => $tid]);
                        jsonSuccess(null, 'Status updated.');
                    }
                }
            }
            // GET list
            $filter = sanitize($_GET['filter'] ?? 'open');
            $where  = $filter === 'open' ? "WHERE status NOT IN ('resolved','closed')" : 'WHERE 1=1';
            $tickets = dbFetchAll("SELECT st.id, st.ticket_number, st.priority, st.status, c.name AS customer_name FROM service_tickets st LEFT JOIN customers c ON c.id=st.customer_id $where ORDER BY st.created_at DESC LIMIT 50");
            jsonSuccess($tickets);

        // ── EMI ──────────────────────────────────────────────────
        case 'emi':
            if ($action === 'overdue') {
                $today  = date('Y-m-d');
                $emis   = dbFetchAll("SELECT e.*, c.name AS customer_name, a.agreement_number FROM emi_schedules e JOIN customers c ON c.id=e.customer_id JOIN agreements a ON a.id=e.agreement_id WHERE e.due_date < ? AND e.status='pending' ORDER BY e.due_date ASC LIMIT 50", [$today]);
                jsonSuccess($emis);
            }
            jsonError('Invalid action.');

        // ── Reports ──────────────────────────────────────────────
        case 'reports':
            requireRole('OWNER','ADMIN','MANAGER','ACCOUNTS');
            $type = sanitize($_GET['type'] ?? 'overview');
            $from = sanitize($_GET['from'] ?? date('Y-m-01'));
            $to   = sanitize($_GET['to']   ?? date('Y-m-d'));

            $data = match($type) {
                'sales_summary' => [
                    'total_sales'       => (float)(dbFetch("SELECT COALESCE(SUM(total),0) AS v FROM invoices WHERE date BETWEEN ? AND ?", [$from,$to])['v'] ?? 0),
                    'total_collected'   => (float)(dbFetch("SELECT COALESCE(SUM(paid_amount),0) AS v FROM invoices WHERE date BETWEEN ? AND ?", [$from,$to])['v'] ?? 0),
                    'total_due'         => (float)(dbFetch("SELECT COALESCE(SUM(due_amount),0) AS v FROM invoices WHERE status NOT IN ('paid','cancelled')")['v'] ?? 0),
                    'invoice_count'     => (int)(dbFetch("SELECT COUNT(*) AS v FROM invoices WHERE date BETWEEN ? AND ?", [$from,$to])['v'] ?? 0),
                ],
                'daily_summary' => dbFetchAll("SELECT date, SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS income, SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense FROM daily_ledger WHERE date BETWEEN ? AND ? GROUP BY date ORDER BY date ASC", [$from,$to]),
                default => ['message' => 'Report type not found']
            };
            jsonSuccess($data);

        // ── Agreements ───────────────────────────────────────────
        case 'agreements':
            if ($method === 'POST') {
                verifyCsrf();
                if ($action === 'generate_text') {
                    $custId = (int)($_POST['customer_id'] ?? 0);
                    if (!$custId) jsonError('Customer required.');
                    $cust = dbFetch('SELECT * FROM customers WHERE id=?', [$custId]);
                    if (!$cust) jsonError('Customer not found.');
                    $agreementData = [
                        'date'           => sanitize($_POST['date'] ?? date('Y-m-d')),
                        'machine_model'  => sanitize($_POST['machine_model'] ?? ''),
                        'serial_number'  => sanitize($_POST['serial_number'] ?? ''),
                        'accessories'    => sanitize($_POST['accessories'] ?? ''),
                        'cash_price'     => (float)($_POST['cash_price'] ?? 0),
                        'down_payment'   => (float)($_POST['down_payment'] ?? 0),
                        'remaining_amount'=> (float)($_POST['remaining_amount'] ?? 0),
                        'emi_amount'     => (float)($_POST['emi_amount'] ?? 0),
                        'emi_months'     => (int)($_POST['emi_months'] ?? 0),
                        'emi_day'        => (int)($_POST['emi_day'] ?? 5),
                        'payment_mode'   => sanitize($_POST['payment_mode'] ?? ''),
                        'bank_name'      => sanitize($_POST['bank_name'] ?? ''),
                        'account_number' => sanitize($_POST['account_number'] ?? ''),
                        'cheque_numbers' => sanitize($_POST['cheque_numbers'] ?? ''),
                        'warranty_terms' => sanitize($_POST['warranty_terms'] ?? ''),
                        'special_terms'  => sanitize($_POST['special_terms'] ?? ''),
                        'witness1_name'  => sanitize($_POST['witness1_name'] ?? ''),
                        'witness1_phone' => sanitize($_POST['witness1_phone'] ?? ''),
                        'witness1_address'=> sanitize($_POST['witness1_address'] ?? ''),
                        'witness2_name'  => sanitize($_POST['witness2_name'] ?? ''),
                        'witness2_phone' => sanitize($_POST['witness2_phone'] ?? ''),
                        'witness2_address'=> sanitize($_POST['witness2_address'] ?? ''),
                    ];
                    $text = generateAgreementText($agreementData, $cust);
                    jsonSuccess(['text' => $text]);
                }
            }
            jsonError('Invalid request.');

        // ── Search (global) ──────────────────────────────────────
        case 'search':
            $q = sanitize($_GET['q'] ?? '');
            if (strlen($q) < 2) jsonSuccess([]);
            $results = [];
            $customers = dbFetchAll("SELECT 'customer' AS type, id, name, phone AS subtitle FROM customers WHERE name LIKE ? OR phone LIKE ? LIMIT 5", ["%$q%","%$q%"]);
            $products  = dbFetchAll("SELECT 'product' AS type, id, name, sku AS subtitle FROM products WHERE name LIKE ? OR sku LIKE ? LIMIT 5", ["%$q%","%$q%"]);
            $tickets   = dbFetchAll("SELECT 'ticket' AS type, id, ticket_number AS name, status AS subtitle FROM service_tickets WHERE ticket_number LIKE ? LIMIT 3", ["%$q%"]);
            jsonSuccess([...$customers, ...$products, ...$tickets]);

        // ── Settings ─────────────────────────────────────────────
        case 'settings':
            if ($method === 'GET') {
                jsonSuccess(getSettings());
            }
            jsonError('Invalid request.');

        default:
            jsonError('Unknown module: ' . $module, 404);
    }
} catch (Throwable $e) {
    if (defined('APP_DEBUG') && APP_DEBUG) {
        jsonError($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 500);
    }
    jsonError('Server error. Please try again.', 500);
}
