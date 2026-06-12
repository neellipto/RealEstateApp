<?php
/**
 * COLORJET ERP - Layout (Header + Sidebar + Footer)
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

function renderHead(string $title = 'COLORJET ERP', string $extraCss = ''): void {
    $csrfToken = csrfToken();
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <meta name="csrf-token" content="{$csrfToken}">
  <title>{$title} — COLORJET ERP</title>
  <link rel="icon" type="image/png" href="assets/img/favicon.png">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
  {$extraCss}
</head>
<body>
<div id="loading-overlay" class="loading-overlay" style="display:none">
  <div class="spinner-border text-primary"></div>
</div>
<div id="flash-container" style="position:fixed;top:70px;right:16px;z-index:9999;min-width:300px;max-width:420px"></div>
<div id="sidebar-overlay" class="sidebar-overlay"></div>
HTML;
}

function renderSidebar(): void {
    $u = currentUser();
    if (!$u) return;
    $role = $u['role_name'];
    $initials = strtoupper(substr($u['name'], 0, 2));

    $isOwnerAdmin    = in_array($role, ['OWNER','ADMIN','MANAGER']);
    $isAccounts      = in_array($role, ['OWNER','ADMIN','MANAGER','ACCOUNTS']);
    $isSales         = in_array($role, ['OWNER','ADMIN','MANAGER','SALES']);
    $isService       = in_array($role, ['OWNER','ADMIN','MANAGER','SERVICE_MANAGER','ENGINEER']);
    $isStore         = in_array($role, ['OWNER','ADMIN','MANAGER','STORE']);
    $isEngineer      = $role === 'ENGINEER';
    $isServiceMgr    = in_array($role, ['SERVICE_MANAGER','OWNER','ADMIN','MANAGER']);
    $isSupplier      = in_array($role, ['OWNER','ADMIN','MANAGER','ACCOUNTS']);

    echo <<<HTML
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <img src="assets/img/logo.png" alt="COLORJET" onerror="this.style.display='none'">
    <div class="sidebar-brand-text">
      <div class="app-name">COLORJET ERP</div>
      <div class="app-slogan">Quality • Commitment • Service</div>
    </div>
  </div>

  <nav class="py-2" style="flex:1">
HTML;

    // Dashboard
    echo '<div class="sidebar-section"><span class="sidebar-section-title">Main</span></div>';
    navLink('index.php', 'fa-gauge-high', 'Dashboard');

    if ($isOwnerAdmin) {
        navLink('office-tasks.php', 'fa-list-check', 'Office Tasks');
    }

    // Engineer
    if ($isEngineer) {
        echo '<div class="sidebar-section"><span class="sidebar-section-title">Engineer</span></div>';
        navLink('engineer-dashboard.php', 'fa-hard-hat', 'My Dashboard');
        navLink('engineer-schedule.php', 'fa-calendar-days', 'My Schedule');
    }
    if ($isServiceMgr && !$isEngineer) {
        echo '<div class="sidebar-section"><span class="sidebar-section-title">Field Service</span></div>';
        navLink('engineer-schedule.php', 'fa-calendar-days', 'Engineer Schedule');
        navLink('engineer-dashboard.php', 'fa-hard-hat', 'Engineer Panel');
    }

    // Service / Warranty
    if ($isService) {
        echo '<div class="sidebar-section"><span class="sidebar-section-title">Service</span></div>';
        navLink('service-tickets.php', 'fa-ticket', 'Service Tickets');
        navLink('warranty.php', 'fa-shield-check', 'Warranty / AMC');
    }

    // Sales / Customers
    if ($isSales) {
        echo '<div class="sidebar-section"><span class="sidebar-section-title">Sales & CRM</span></div>';
        navLink('customers.php', 'fa-users', 'Customers');
        navLink('sales.php', 'fa-cart-shopping', 'Sales Orders');
        navLink('invoices.php', 'fa-file-invoice', 'Invoices');
        navLink('payments.php', 'fa-money-bill-transfer', 'Payments');
        navLink('agreement-generator.php', 'fa-file-contract', 'Agreement Generator');
        navLink('agreement-records.php', 'fa-folder-open', 'Agreements');
    }

    // Accounts
    if ($isAccounts) {
        echo '<div class="sidebar-section"><span class="sidebar-section-title">Accounts</span></div>';
        navLink('daily-ledger.php', 'fa-book', 'Daily Ledger');
        navLink('customer-ledger.php', 'fa-user-check', 'Customer Ledger');
        navLink('supplier-ledger.php', 'fa-truck-ramp-box', 'Supplier Ledger');
    }

    // Stock / Parts
    if ($isStore || $isOwnerAdmin) {
        echo '<div class="sidebar-section"><span class="sidebar-section-title">Inventory</span></div>';
        navLink('products.php', 'fa-boxes-stacked', 'Products');
        navLink('stock.php', 'fa-warehouse', 'Stock Management');
        navLink('parts-stock.php', 'fa-screwdriver-wrench', 'Parts Stock');
    }

    // Supplier / LC
    if ($isSupplier) {
        echo '<div class="sidebar-section"><span class="sidebar-section-title">Procurement</span></div>';
        navLink('suppliers.php', 'fa-handshake', 'Suppliers');
        navLink('foreign-purchase.php', 'fa-plane-arrival', 'Foreign Purchase');
        navLink('lc-tt.php', 'fa-building-columns', 'LC / TT');
        navLink('landed-cost.php', 'fa-calculator', 'Landed Cost');
    }

    // Reports
    if ($isOwnerAdmin || $isAccounts) {
        echo '<div class="sidebar-section"><span class="sidebar-section-title">Reports</span></div>';
        navLink('reports.php', 'fa-chart-bar', 'Reports');
    }

    // System
    if ($isOwnerAdmin) {
        echo '<div class="sidebar-section"><span class="sidebar-section-title">System</span></div>';
        navLink('users.php', 'fa-user-gear', 'User Management');
        navLink('file-import.php', 'fa-file-arrow-up', 'File Import');
        navLink('backup.php', 'fa-database', 'Backup');
        navLink('settings.php', 'fa-gear', 'Settings');
    }

    echo <<<HTML
  </nav>

  <div style="padding:12px 16px;border-top:1px solid rgba(255,255,255,0.08)">
    <div class="d-flex align-items-center gap-2">
      <div class="user-avatar">{$initials}</div>
      <div style="overflow:hidden">
        <div style="color:#fff;font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{$u['name']}</div>
        <div style="color:#64748b;font-size:11px">{$u['role_name']}</div>
      </div>
    </div>
    <a href="logout.php" class="d-flex align-items-center gap-2 mt-2 nav-item-link" style="color:#ef4444">
      <i class="fas fa-sign-out-alt"></i><span>Logout</span>
    </a>
  </div>
</aside>
HTML;
}

function navLink(string $href, string $icon, string $label): void {
    $active = basename($_SERVER['PHP_SELF']) === $href ? ' active' : '';
    echo "<a href='{$href}' class='nav-item-link{$active}'><i class='fas {$icon}'></i><span>{$label}</span></a>";
}

function renderTopbar(string $title, string $breadcrumb = ''): void {
    $u = currentUser();
    $initials = strtoupper(substr($u['name'] ?? 'U', 0, 2));
    echo <<<HTML
<div class="main-wrapper" id="main-wrapper">
  <div class="topbar">
    <div class="d-flex align-items-center gap-3">
      <button class="sidebar-toggle" id="sidebar-toggle"><i class="fas fa-bars"></i></button>
      <div>
        <div class="topbar-title">{$title}</div>
        {$breadcrumb}
      </div>
    </div>
    <div class="topbar-right">
      <div class="dropdown">
        <button class="btn btn-sm btn-light position-relative" data-bs-toggle="dropdown">
          <i class="fas fa-bell"></i>
          <span id="notif-count" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:9px">0</span>
        </button>
        <div class="dropdown-menu dropdown-menu-end" style="width:300px;max-height:400px;overflow-y:auto" id="notif-list">
          <div class="dropdown-item text-muted text-center py-3">Loading...</div>
        </div>
      </div>
      <div class="dropdown">
        <div class="user-avatar cursor-pointer" data-bs-toggle="dropdown">{$initials}</div>
        <div class="dropdown-menu dropdown-menu-end">
          <div class="dropdown-item-text py-2 px-3">
            <div class="fw-600">{$u['name']}</div>
            <div class="small text-muted">{$u['role_name']}</div>
          </div>
          <div class="dropdown-divider"></div>
          <a href="settings.php?tab=profile" class="dropdown-item"><i class="fas fa-user me-2"></i>Profile</a>
          <a href="settings.php?tab=password" class="dropdown-item"><i class="fas fa-key me-2"></i>Change Password</a>
          <div class="dropdown-divider"></div>
          <a href="logout.php" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt me-2"></i>Logout</a>
        </div>
      </div>
    </div>
  </div>
  <div class="page-content">
HTML;
}

function renderFooter(): void {
    $y = date('Y');
    echo <<<HTML
  </div><!-- /page-content -->
</div><!-- /main-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
<script>
// Show flash from PHP session
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.auto-flash').forEach(el => {
    setTimeout(() => el.remove(), 5000);
  });
});
</script>
</body>
</html>
HTML;
}

function pageStart(string $title, string $breadcrumb = '', string $extraCss = ''): void {
    renderHead($title, $extraCss);
    renderSidebar();
    renderTopbar($title, $breadcrumb);
}

function pageEnd(): void {
    renderFooter();
}
