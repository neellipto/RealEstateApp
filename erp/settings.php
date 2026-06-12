<?php
require_once 'includes/layout.php';
requireLogin();

$tab = sanitize($_GET['tab'] ?? 'general');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'save_settings' && hasRole('OWNER','ADMIN')) {
        $fields = ['company_name','company_address','company_phone','company_email','company_website','currency','fiscal_year_start','low_stock_threshold','emi_late_fee_percent','timezone'];
        foreach ($fields as $f) {
            $val = sanitize($_POST[$f] ?? '');
            dbQuery("INSERT INTO settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value=?", [$f, $val, $val]);
        }
        redirect('settings.php?tab=general', 'Settings saved.');
    } elseif ($act === 'change_password') {
        $curr = $_POST['current_password'] ?? '';
        $new  = $_POST['new_password'] ?? '';
        $conf = $_POST['confirm_password'] ?? '';
        if ($new !== $conf) {
            redirect('settings.php?tab=password', 'Passwords do not match.', 'error');
        }
        if (strlen($new) < 6) {
            redirect('settings.php?tab=password', 'Password must be at least 6 characters.', 'error');
        }
        $u = dbFetch('SELECT password_hash FROM users WHERE id=?', [userId()]);
        if (!password_verify($curr, $u['password_hash'])) {
            redirect('settings.php?tab=password', 'Current password is incorrect.', 'error');
        }
        dbUpdate('users', ['password_hash' => password_hash($new, PASSWORD_BCRYPT, ['cost'=>12])], ['id' => userId()]);
        logActivity('change_password', 'auth', userId(), 'Password changed');
        redirect('settings.php?tab=password', 'Password changed successfully.');
    } elseif ($act === 'save_profile') {
        $data = [
            'name'        => sanitize($_POST['name'] ?? ''),
            'phone'       => sanitize($_POST['phone'] ?? ''),
            'designation' => sanitize($_POST['designation'] ?? ''),
        ];
        if ($data['name']) {
            dbUpdate('users', $data, ['id' => userId()]);
            unset($_SESSION['user']);
            redirect('settings.php?tab=profile', 'Profile updated.');
        }
    }
}

$cfg  = getSettings();
$user = currentUser();

pageStart('Settings');
?>
<?= flash() ?>

<div class="page-header"><h1><i class="fas fa-gear me-2"></i>Settings</h1></div>

<ul class="nav nav-tabs mb-4">
  <li class="nav-item"><a class="nav-link <?= $tab==='general'?'active':'' ?>" href="?tab=general"><i class="fas fa-building me-1"></i>Company</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='profile'?'active':'' ?>" href="?tab=profile"><i class="fas fa-user me-1"></i>My Profile</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='password'?'active':'' ?>" href="?tab=password"><i class="fas fa-key me-1"></i>Password</a></li>
  <?php if (hasRole('OWNER','ADMIN')): ?>
  <li class="nav-item"><a class="nav-link <?= $tab==='system'?'active':'' ?>" href="?tab=system"><i class="fas fa-server me-1"></i>System</a></li>
  <?php endif; ?>
</ul>

<?php if ($tab === 'general' && hasRole('OWNER','ADMIN')): ?>
<div class="cj-card">
  <div class="card-header"><span class="card-title">Company Settings</span></div>
  <form method="POST">
    <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
    <input type="hidden" name="action" value="save_settings">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Company Name</label><input type="text" name="company_name" class="form-control" value="<?= h($cfg['company_name'] ?? 'COLORJET Bangladesh') ?>"></div>
        <div class="col-md-6"><label class="form-label">Company Phone</label><input type="text" name="company_phone" class="form-control" value="<?= h($cfg['company_phone'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">Company Email</label><input type="email" name="company_email" class="form-control" value="<?= h($cfg['company_email'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">Website</label><input type="text" name="company_website" class="form-control" value="<?= h($cfg['company_website'] ?? '') ?>"></div>
        <div class="col-12"><label class="form-label">Address</label><textarea name="company_address" class="form-control" rows="2"><?= h($cfg['company_address'] ?? '') ?></textarea></div>
        <div class="col-md-4"><label class="form-label">Default Currency</label>
          <select name="currency" class="form-select">
            <?php foreach (['BDT','USD','EUR'] as $c): ?><option value="<?= $c ?>" <?= ($cfg['currency']??'BDT')===$c?'selected':'' ?>><?= $c ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4"><label class="form-label">Low Stock Alert (threshold)</label><input type="number" name="low_stock_threshold" class="form-control" value="<?= h($cfg['low_stock_threshold'] ?? 5) ?>"></div>
        <div class="col-md-4"><label class="form-label">EMI Late Fee (%)</label><input type="number" name="emi_late_fee_percent" class="form-control" value="<?= h($cfg['emi_late_fee_percent'] ?? 2) ?>" step="0.1" min="0"></div>
        <div class="col-md-6"><label class="form-label">Timezone</label><input type="text" name="timezone" class="form-control" value="<?= h($cfg['timezone'] ?? 'Asia/Dhaka') ?>"></div>
      </div>
    </div>
    <div class="card-footer"><button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Settings</button></div>
  </form>
</div>

<?php elseif ($tab === 'profile'): ?>
<div class="cj-card">
  <div class="card-header"><span class="card-title">My Profile</span></div>
  <form method="POST">
    <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
    <input type="hidden" name="action" value="save_profile">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Full Name</label><input type="text" name="name" class="form-control" value="<?= h($user['name']) ?>" required></div>
        <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= h($user['phone']) ?>"></div>
        <div class="col-md-6"><label class="form-label">Designation</label><input type="text" name="designation" class="form-control" value="<?= h($user['designation']) ?>"></div>
        <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" value="<?= h($user['email']) ?>" disabled><small class="text-muted">Contact admin to change email.</small></div>
        <div class="col-md-6"><label class="form-label">Role</label><input type="text" class="form-control" value="<?= h($user['role_name']) ?>" disabled></div>
        <div class="col-md-6"><label class="form-label">Employee ID</label><input type="text" class="form-control" value="<?= h($user['employee_id']) ?>" disabled></div>
      </div>
    </div>
    <div class="card-footer"><button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Update Profile</button></div>
  </form>
</div>

<?php elseif ($tab === 'password'): ?>
<div class="cj-card" style="max-width:500px">
  <div class="card-header"><span class="card-title">Change Password</span></div>
  <form method="POST">
    <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
    <input type="hidden" name="action" value="change_password">
    <div class="card-body">
      <div class="mb-3"><label class="form-label">Current Password</label><input type="password" name="current_password" class="form-control" required></div>
      <div class="mb-3"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control" required minlength="6"></div>
      <div class="mb-3"><label class="form-label">Confirm New Password</label><input type="password" name="confirm_password" class="form-control" required></div>
    </div>
    <div class="card-footer"><button type="submit" class="btn btn-warning"><i class="fas fa-key me-2"></i>Change Password</button></div>
  </form>
</div>

<?php elseif ($tab === 'system' && hasRole('OWNER','ADMIN')): ?>
<div class="row g-4">
  <div class="col-md-6">
    <div class="cj-card">
      <div class="card-header"><span class="card-title">System Information</span></div>
      <div class="card-body">
        <table class="table table-sm">
          <tr><td>PHP Version</td><td><code><?= PHP_VERSION ?></code></td></tr>
          <tr><td>Server OS</td><td><code><?= PHP_OS ?></code></td></tr>
          <tr><td>App Version</td><td><code><?= $cfg['app_version'] ?? '1.0.0' ?></code></td></tr>
          <tr><td>Current Time</td><td><?= date('Y-m-d H:i:s') ?></td></tr>
          <tr><td>Database</td><td><?php try { $v=dbFetch('SELECT VERSION() AS v'); echo '<code>MySQL ' . $v['v'] . '</code>'; } catch(Exception $e) { echo '<span class="text-danger">Error</span>'; } ?></td></tr>
          <tr><td>Session</td><td><code><?= session_id() ?></code></td></tr>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="cj-card">
      <div class="card-header"><span class="card-title">Quick Links</span></div>
      <div class="card-body">
        <div class="d-grid gap-2">
          <a href="backup.php" class="btn btn-outline-primary"><i class="fas fa-database me-2"></i>Database Backup</a>
          <a href="users.php" class="btn btn-outline-primary"><i class="fas fa-users me-2"></i>Manage Users</a>
          <a href="file-import.php" class="btn btn-outline-primary"><i class="fas fa-file-import me-2"></i>Data Import</a>
          <a href="reports.php" class="btn btn-outline-primary"><i class="fas fa-chart-bar me-2"></i>Reports</a>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php pageEnd(); ?>
