<?php
/**
 * COLORJET ERP - Authentication & Session
 */

require_once __DIR__ . '/db.php';

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $name = defined('SESSION_NAME') ? SESSION_NAME : 'COLORJET_ERP';
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function requireLogin(string $redirect = '/login.php'): void {
    startSession();
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . $redirect);
        exit;
    }
    // Refresh user data
    if (empty($_SESSION['user'])) {
        $u = dbFetch(
            'SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.is_active=1',
            [$_SESSION['user_id']]
        );
        if (!$u) { logout(); }
        $_SESSION['user'] = $u;
    }
}

function currentUser(): ?array {
    startSession();
    return $_SESSION['user'] ?? null;
}

function userId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function hasRole(string ...$roles): bool {
    $u = currentUser();
    if (!$u) return false;
    return in_array($u['role_name'], $roles, true);
}

function requireRole(string ...$roles): void {
    if (!hasRole(...$roles)) {
        http_response_code(403);
        die('<div style="text-align:center;padding:60px;font-family:sans-serif"><h2>Access Denied</h2><p>You do not have permission to view this page.</p><a href="index.php">Go Back</a></div>');
    }
}

function login(string $identifier, string $password): array {
    startSession();
    $user = dbFetch(
        'SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id
         WHERE (u.email=? OR u.phone=? OR u.employee_id=?) AND u.is_active=1',
        [$identifier, $identifier, $identifier]
    );

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if (!$user || !password_verify($password, $user['password_hash'])) {
        dbInsert('login_audit', [
            'user_id'          => $user['id'] ?? null,
            'login_identifier' => $identifier,
            'ip_address'       => $ip,
            'user_agent'       => $ua,
            'status'           => 'failed',
        ]);
        return ['ok' => false, 'message' => 'Invalid credentials. Please check your email/phone/ID and password.'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user']    = $user;

    dbInsert('login_audit', [
        'user_id'          => $user['id'],
        'login_identifier' => $identifier,
        'ip_address'       => $ip,
        'user_agent'       => $ua,
        'status'           => 'success',
    ]);

    dbUpdate('users', ['last_login' => date('Y-m-d H:i:s')], ['id' => $user['id']]);
    logActivity('login', 'auth', $user['id'], 'User logged in from ' . $ip);

    return ['ok' => true, 'role' => $user['role_name']];
}

function logout(): void {
    startSession();
    if (!empty($_SESSION['user_id'])) {
        dbInsert('login_audit', [
            'user_id'    => $_SESSION['user_id'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'status'     => 'logout',
        ]);
        logActivity('logout', 'auth', $_SESSION['user_id'], 'User logged out');
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

function csrfToken(): string {
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        jsonError('CSRF token mismatch. Please refresh the page.');
    }
}

function logActivity(string $action, string $module, int $recordId = 0, string $desc = ''): void {
    try {
        dbInsert('activity_log', [
            'user_id'     => userId(),
            'action'      => $action,
            'module'      => $module,
            'record_id'   => $recordId,
            'description' => $desc,
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    } catch (Throwable) {}
}

function roleDashboard(string $role): string {
    return match($role) {
        'OWNER'           => 'index.php',
        'ADMIN'           => 'index.php',
        'MANAGER'         => 'index.php',
        'ENGINEER'        => 'engineer-dashboard.php',
        'SERVICE_MANAGER' => 'service-tickets.php',
        'ACCOUNTS'        => 'daily-ledger.php',
        'SALES'           => 'customers.php',
        'STORE'           => 'stock.php',
        'CUSTOMER'        => 'index.php',
        default           => 'index.php',
    };
}

function jsonSuccess(mixed $data = null, string $message = 'OK'): never {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'message' => $message, 'data' => $data]);
    exit;
}

function jsonError(string $message, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => $message]);
    exit;
}
