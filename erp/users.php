<?php
require_once 'includes/layout.php';
requireLogin();
requireRole('OWNER','ADMIN');

$errors = [];
$roles  = dbFetchAll('SELECT * FROM roles ORDER BY id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $uid = (int)($_POST['id'] ?? 0);
        $data = [
            'name'        => sanitize($_POST['name'] ?? ''),
            'email'       => sanitize($_POST['email'] ?? '') ?: null,
            'phone'       => sanitize($_POST['phone'] ?? ''),
            'employee_id' => sanitize($_POST['employee_id'] ?? '') ?: null,
            'role_id'     => (int)($_POST['role_id'] ?? 3),
            'department'  => sanitize($_POST['department'] ?? ''),
            'designation' => sanitize($_POST['designation'] ?? ''),
            'is_active'   => isset($_POST['is_active']) ? 1 : 0,
        ];
        $errors = validateRequired($data, ['name']);

        $password = sanitize($_POST['password'] ?? '');
        if (!$uid && !$password) $errors[] = 'Password is required for new users.';
        if ($password && strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';

        if (!$errors) {
            if ($password) $data['password_hash'] = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);
            if ($uid) {
                dbUpdate('users', $data, ['id' => $uid]);
                redirect('users.php', 'User updated.');
            } else {
                $newId = dbInsert('users', $data);
                // Create engineer profile if role is ENGINEER
                $role = dbFetch('SELECT name FROM roles WHERE id=?', [$data['role_id']]);
                if ($role && $role['name'] === 'ENGINEER') {
                    dbInsert('engineer_profiles', ['user_id' => $newId]);
                }
                logActivity('create', 'users', $newId, "Created user: {$data['name']}");
                redirect('users.php', 'User created.');
            }
        }
    } elseif ($act === 'toggle') {
        $uid = (int)($_POST['id'] ?? 0);
        if ($uid && $uid !== userId()) {
            $u = dbFetch('SELECT is_active FROM users WHERE id=?', [$uid]);
            dbUpdate('users', ['is_active' => $u['is_active'] ? 0 : 1], ['id' => $uid]);
            jsonSuccess(null, 'User status toggled.');
        }
        jsonError('Cannot deactivate yourself.');
    } elseif ($act === 'change_password') {
        $uid = (int)($_POST['id'] ?? 0);
        $np  = $_POST['new_password'] ?? '';
        if ($uid && strlen($np) >= 6) {
            dbUpdate('users', ['password_hash' => password_hash($np, PASSWORD_BCRYPT, ['cost'=>12])], ['id' => $uid]);
            jsonSuccess(null, 'Password changed.');
        }
        jsonError('Password must be at least 6 characters.');
    }
}

$users = dbFetchAll('SELECT u.*, r.name AS role_name, r.label AS role_label FROM users u JOIN roles r ON r.id=u.role_id ORDER BY r.id ASC, u.name ASC');

pageStart('User Management');
?>
<?= flash() ?>

<div class="page-header">
  <h1><i class="fas fa-user-gear me-2"></i>User Management</h1>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal"><i class="fas fa-plus me-2"></i>Add User</button>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<div class="cj-card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>Name</th><th>Employee ID</th><th>Email/Phone</th><th>Role</th><th>Department</th><th>Last Login</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="user-avatar" style="width:32px;height:32px;font-size:13px"><?= strtoupper(substr($u['name'],0,2)) ?></div>
                <div class="fw-600"><?= h($u['name']) ?></div>
              </div>
            </td>
            <td><code><?= h($u['employee_id'] ?: '—') ?></code></td>
            <td>
              <div><?= h($u['email'] ?: '—') ?></div>
              <small class="text-muted"><?= h($u['phone'] ?: '—') ?></small>
            </td>
            <td><span class="badge bg-primary"><?= h($u['role_label']) ?></span></td>
            <td><?= h($u['department'] ?: '—') ?></td>
            <td class="text-muted small"><?= $u['last_login'] ? formatDateTime($u['last_login']) : 'Never' ?></td>
            <td><?= $u['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>' ?></td>
            <td class="table-actions">
              <button class="btn btn-sm btn-outline-primary" onclick="editUser(<?= htmlspecialchars(json_encode($u)) ?>)"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-warning" onclick="changePassword(<?= $u['id'] ?>, '<?= h($u['name']) ?>')"><i class="fas fa-key"></i></button>
              <?php if ($u['id'] !== userId()): ?>
              <button class="btn btn-sm btn-outline-<?= $u['is_active']?'danger':'success' ?>" onclick="toggleUser(<?= $u['id'] ?>)">
                <i class="fas fa-<?= $u['is_active']?'ban':'check' ?>"></i>
              </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- User Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="userModalLabel">Add User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="u_id" value="">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-8"><label class="form-label">Full Name <span class="text-danger">*</span></label><input type="text" name="name" id="u_name" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Employee ID</label><input type="text" name="employee_id" id="u_empid" class="form-control" placeholder="EMP001"></div>
            <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" id="u_email" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" id="u_phone" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Role</label>
              <select name="role_id" id="u_role" class="form-select">
                <?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>"><?= h($r['label']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Department</label>
              <select name="department" id="u_dept" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach (['Management','Sales','Accounts','Service','Engineering','HR','IT','Procurement','Store','Other'] as $d): ?><option value="<?= $d ?>"><?= $d ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label">Designation</label><input type="text" name="designation" id="u_desig" class="form-control" placeholder="Manager, Engineer..."></div>
            <div class="col-md-6"><label class="form-label">Password <span id="pwd_req" class="text-danger">*</span></label><input type="password" name="password" id="u_pwd" class="form-control" placeholder="Min 6 characters"></div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_active" id="u_active" value="1" checked>
                <label class="form-check-label" for="u_active">Active User</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save User</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="passwordModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="pwdModalTitle">Change Password</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">New Password</label><input type="password" id="new_pwd" class="form-control" placeholder="Min 6 characters"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-warning" onclick="submitPasswordChange()"><i class="fas fa-key me-2"></i>Change Password</button>
      </div>
    </div>
  </div>
</div>

<script>
let changePwdUserId = null;

function editUser(u) {
  document.getElementById('userModalLabel').textContent = 'Edit User: ' + u.name;
  document.getElementById('u_id').value = u.id;
  document.getElementById('u_name').value = u.name || '';
  document.getElementById('u_empid').value = u.employee_id || '';
  document.getElementById('u_email').value = u.email || '';
  document.getElementById('u_phone').value = u.phone || '';
  document.getElementById('u_role').value = u.role_id || '';
  document.getElementById('u_dept').value = u.department || '';
  document.getElementById('u_desig').value = u.designation || '';
  document.getElementById('u_active').checked = u.is_active == 1;
  document.getElementById('u_pwd').placeholder = 'Leave blank to keep current';
  document.getElementById('pwd_req').textContent = '';
  new bootstrap.Modal(document.getElementById('userModal')).show();
}

async function toggleUser(id) {
  const r = await CJ.post('users.php', { action: 'toggle', id, _csrf: document.querySelector('meta[name="csrf-token"]').content });
  if (r.ok) { CJ.flash('User status updated.'); setTimeout(() => location.reload(), 800); }
  else CJ.flash(r.message, 'danger');
}

function changePassword(id, name) {
  changePwdUserId = id;
  document.getElementById('pwdModalTitle').textContent = 'Change Password — ' + name;
  document.getElementById('new_pwd').value = '';
  new bootstrap.Modal(document.getElementById('passwordModal')).show();
}

async function submitPasswordChange() {
  const np = document.getElementById('new_pwd').value;
  const r = await CJ.post('users.php', { action: 'change_password', id: changePwdUserId, new_password: np });
  if (r.ok) { CJ.flash('Password changed!'); bootstrap.Modal.getInstance(document.getElementById('passwordModal')).hide(); }
  else CJ.flash(r.message, 'danger');
}
</script>

<?php pageEnd(); ?>
