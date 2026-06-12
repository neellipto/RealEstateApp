<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

startSession();
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = sanitize($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';
    if (!$identifier || !$password) {
        $error = 'Please enter your login credentials.';
    } else {
        $result = login($identifier, $password);
        if ($result['ok']) {
            header('Location: ' . roleDashboard($result['role']));
            exit;
        }
        $error = $result['message'];
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — COLORJET ERP</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="login-logo">
      <img src="assets/img/logo.png" alt="COLORJET" style="height:60px" onerror="this.outerHTML='<div style=\'font-size:28px;font-weight:800;color:#0d6efd;letter-spacing:1px\'>COLORJET</div>'">
    </div>
    <div class="login-title">COLORJET ERP</div>
    <div class="login-sub">Quality • Commitment • Service<br><small>Sign in to continue</small></div>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <i class="fas fa-exclamation-circle me-2"></i><?= h($error) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <form method="POST" action="login.php" novalidate>
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

      <div class="mb-3">
        <label class="form-label"><i class="fas fa-user me-1 text-muted"></i> Email / Phone / Employee ID</label>
        <input type="text" name="identifier" class="form-control form-control-lg"
               value="<?= h($identifier) ?>" placeholder="Enter your login ID" required autofocus>
      </div>
      <div class="mb-4">
        <label class="form-label"><i class="fas fa-lock me-1 text-muted"></i> Password</label>
        <div class="input-group">
          <input type="password" name="password" id="password" class="form-control form-control-lg" placeholder="Enter password" required>
          <button type="button" class="btn btn-outline-secondary" onclick="togglePwd()">
            <i class="fas fa-eye" id="pwd-eye"></i>
          </button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-lg w-100">
        <i class="fas fa-sign-in-alt me-2"></i>Sign In
      </button>
    </form>

    <div class="mt-4 text-center text-muted" style="font-size:12px">
      <i class="fas fa-shield-alt me-1"></i>Secured by COLORJET ERP Security System<br>
      <a href="install.php" class="mt-2 d-inline-block">First time? Run Installer</a>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePwd() {
  const p = document.getElementById('password');
  const e = document.getElementById('pwd-eye');
  if (p.type === 'password') { p.type = 'text'; e.classList.replace('fa-eye','fa-eye-slash'); }
  else { p.type = 'password'; e.classList.replace('fa-eye-slash','fa-eye'); }
}
</script>
</body>
</html>
