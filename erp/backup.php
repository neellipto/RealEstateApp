<?php
require_once 'includes/layout.php';
requireLogin();
requireRole('OWNER','ADMIN');

$msg = '';
$type = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'backup') {
        $backupDir = dirname(__DIR__) . '/erp/backups/';
        if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

        $filename  = 'colorjet_erp_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath  = $backupDir . $filename;

        try {
            $pdo = getDB();
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $sql = "-- COLORJET ERP Database Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";

            foreach ($tables as $table) {
                $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
                $sql .= "DROP TABLE IF EXISTS `$table`;\n";
                $sql .= $create['Create Table'] . ";\n\n";
                $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                if ($rows) {
                    $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
                    $sql .= "INSERT INTO `$table` ($cols) VALUES\n";
                    $vals = [];
                    foreach ($rows as $row) {
                        $escaped = array_map(fn($v) => is_null($v) ? 'NULL' : $pdo->quote($v), array_values($row));
                        $vals[] = '(' . implode(', ', $escaped) . ')';
                    }
                    $sql .= implode(",\n", $vals) . ";\n\n";
                }
            }
            $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
            file_put_contents($filepath, $sql);
            logActivity('backup', 'system', 0, "Database backup: $filename");

            // Trigger download
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            exit;
        } catch (Exception $e) {
            $msg  = 'Backup failed: ' . $e->getMessage();
            $type = 'danger';
        }
    }
}

// List existing backups
$backupDir = dirname(__FILE__) . '/backups/';
$backups   = [];
if (is_dir($backupDir)) {
    $files = glob($backupDir . '*.sql');
    rsort($files);
    foreach ($files as $f) {
        $backups[] = ['name' => basename($f), 'size' => filesize($f), 'date' => filemtime($f)];
    }
}

pageStart('Database Backup');
?>
<?= flash() ?>
<?php if ($msg): ?><div class="alert alert-<?= $type ?>"><?= h($msg) ?></div><?php endif; ?>

<div class="page-header">
  <h1><i class="fas fa-database me-2"></i>Database Backup</h1>
</div>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="cj-card">
      <div class="card-header"><span class="card-title"><i class="fas fa-download me-2"></i>Create Backup</span></div>
      <div class="card-body">
        <p class="text-muted">Create a full SQL dump of the COLORJET ERP database. The backup will be downloaded immediately and also saved to the server.</p>
        <div class="alert alert-info py-2">
          <i class="fas fa-info-circle me-2"></i>
          <strong>Tip:</strong> Take regular backups before major data imports or system updates.
        </div>
        <form method="POST">
          <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
          <input type="hidden" name="action" value="backup">
          <button type="submit" class="btn btn-primary btn-lg w-100">
            <i class="fas fa-database me-2"></i>Backup Now &amp; Download
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="cj-card">
      <div class="card-header"><span class="card-title"><i class="fas fa-clock-rotate-left me-2"></i>Backup History</span></div>
      <div class="card-body p-0">
        <?php if ($backups): ?>
        <div class="list-group list-group-flush">
          <?php foreach ($backups as $b): ?>
          <div class="list-group-item d-flex justify-content-between align-items-center py-2">
            <div>
              <div class="fw-600 small"><?= h($b['name']) ?></div>
              <div class="text-muted" style="font-size:11px"><?= date('d M Y H:i', $b['date']) ?></div>
            </div>
            <div class="d-flex gap-2 align-items-center">
              <span class="badge bg-secondary"><?= round($b['size']/1024) ?> KB</span>
              <a href="backups/<?= urlencode($b['name']) ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-download"></i></a>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="text-center py-4 text-muted">No backups found. Create your first backup!</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="cj-card mt-4">
  <div class="card-header"><span class="card-title"><i class="fas fa-info-circle me-2"></i>Database Statistics</span></div>
  <div class="card-body">
    <div class="row g-3">
      <?php
      $tables = ['users','customers','products','invoices','agreements','emi_schedules','service_tickets','engineer_schedule','office_tasks','purchase_orders','lc_records','daily_ledger','customer_ledger'];
      foreach ($tables as $table):
        try { $count = dbFetch("SELECT COUNT(*) AS v FROM `$table`")['v'] ?? 0; } catch(Exception $e) { $count = 'N/A'; }
      ?>
      <div class="col-md-2 col-4">
        <div class="text-center p-2 bg-light rounded">
          <div class="fw-bold"><?= $count ?></div>
          <div class="text-muted" style="font-size:11px"><?= ucwords(str_replace('_',' ',$table)) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php pageEnd(); ?>
