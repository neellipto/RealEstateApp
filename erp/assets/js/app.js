/* COLORJET ERP - Main JavaScript */

const CJ = {
  // API helpers
  async get(url) {
    const r = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    return r.json();
  },
  async post(url, data) {
    const fd = data instanceof FormData ? data : (() => {
      const f = new FormData();
      Object.entries(data).forEach(([k, v]) => f.append(k, v));
      return f;
    })();
    // Add CSRF token
    if (!fd.has('_csrf')) {
      const csrf = document.querySelector('meta[name="csrf-token"]');
      if (csrf) fd.append('_csrf', csrf.content);
    }
    const r = await fetch(url, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    return r.json();
  },

  // Flash messages
  flash(msg, type = 'success', duration = 4000) {
    const el = document.getElementById('flash-container');
    if (!el) return;
    const div = document.createElement('div');
    div.className = `alert alert-${type} alert-dismissible fade show`;
    div.innerHTML = `${msg}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
    el.appendChild(div);
    setTimeout(() => div.remove(), duration);
  },

  // Confirm dialog
  confirm(msg, cb) {
    if (window.confirm(msg)) cb();
  },

  // Format currency
  money(n, sym = '৳') {
    return sym + parseFloat(n || 0).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  },

  // Format date
  date(d) {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
  },

  // Loading overlay
  loading(show = true) {
    const el = document.getElementById('loading-overlay');
    if (el) el.style.display = show ? 'flex' : 'none';
  },

  // Initialize sidebar toggle
  initSidebar() {
    const toggle = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    if (!toggle || !sidebar) return;
    toggle.addEventListener('click', () => {
      sidebar.classList.toggle('open');
      overlay?.classList.toggle('open');
    });
    overlay?.addEventListener('click', () => {
      sidebar.classList.remove('open');
      overlay.classList.remove('open');
    });
  },

  // Highlight active nav
  initActiveNav() {
    const path = window.location.pathname.split('/').pop();
    document.querySelectorAll('.nav-item-link').forEach(a => {
      if (a.getAttribute('href') === path || a.getAttribute('href') === './' + path) {
        a.classList.add('active');
        // Expand parent collapse
        const parent = a.closest('.collapse');
        if (parent) {
          parent.classList.add('show');
          const trigger = document.querySelector(`[data-bs-target="#${parent.id}"]`);
          if (trigger) trigger.setAttribute('aria-expanded', 'true');
        }
      }
    });
  },

  // DataTable helper (uses vanilla JS, no library)
  initSearch(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    if (!input || !table) return;
    input.addEventListener('input', () => {
      const q = input.value.toLowerCase();
      table.querySelectorAll('tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
      });
    });
  },

  // EMI Calculator
  calcEmi(cashPrice, downPayment, months) {
    const remaining = parseFloat(cashPrice) - parseFloat(downPayment);
    if (months <= 0) return 0;
    return Math.ceil(remaining / months);
  },

  // Agreement text generator
  async generateAgreement(formId) {
    const form = document.getElementById(formId);
    if (!form) return;
    const data = new FormData(form);
    data.append('action', 'generate_text');
    CJ.loading(true);
    const r = await CJ.post('api/index.php?module=agreements', data);
    CJ.loading(false);
    if (r.ok) {
      const preview = document.getElementById('agreement-preview');
      if (preview) preview.textContent = r.data.text;
    } else {
      CJ.flash(r.message, 'danger');
    }
  },

  // Delete record
  async deleteRecord(url, rowId) {
    CJ.confirm('Are you sure you want to delete this record? This cannot be undone.', async () => {
      const r = await CJ.post(url, { action: 'delete', id: rowId });
      if (r.ok) {
        const row = document.getElementById('row-' + rowId);
        if (row) row.remove();
        CJ.flash('Record deleted successfully.');
      } else {
        CJ.flash(r.message || 'Delete failed.', 'danger');
      }
    });
  },

  // Print
  print(selector) {
    const content = document.querySelector(selector)?.innerHTML;
    if (!content) { window.print(); return; }
    const w = window.open('', '_blank');
    w.document.write(`<!DOCTYPE html><html><head><title>Print - COLORJET ERP</title>
      <link rel="stylesheet" href="assets/css/app.css">
      <style>body{padding:20px;background:#fff}</style>
      </head><body>${content}</body></html>`);
    w.document.close();
    setTimeout(() => { w.print(); w.close(); }, 500);
  },

  // Export table to CSV
  exportCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const rows = [...table.querySelectorAll('tr')].map(row =>
      [...row.querySelectorAll('th,td')].map(cell => `"${cell.textContent.trim().replace(/"/g, '""')}"`).join(',')
    );
    const csv = rows.join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = (filename || 'export') + '_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
  },

  // Notifications
  async loadNotifications() {
    const r = await CJ.get('api/index.php?module=notifications');
    const badge = document.getElementById('notif-count');
    const list  = document.getElementById('notif-list');
    if (!r.ok || !r.data) return;
    if (badge) badge.textContent = r.data.unread;
    if (list && r.data.items) {
      list.innerHTML = r.data.items.length
        ? r.data.items.map(n => `<a class="dropdown-item py-2 ${n.is_read?'':'fw-bold'}" href="#">
            <div class="small text-muted">${n.type}</div>
            <div>${n.title}</div>
            <div class="small text-muted">${n.created_at}</div></a>`).join('')
        : '<div class="dropdown-item text-muted text-center py-3">No notifications</div>';
    }
  },

  // Number input formatter
  initNumberInputs() {
    document.querySelectorAll('input[type="number"]').forEach(el => {
      el.addEventListener('wheel', e => e.preventDefault());
    });
  },

  // Auto-calculate remaining amount in agreement form
  initAgreementCalc() {
    const cp = document.getElementById('cash_price');
    const dp = document.getElementById('down_payment');
    const ra = document.getElementById('remaining_amount');
    const em = document.getElementById('emi_amount');
    const mo = document.getElementById('emi_months');
    if (!cp || !dp) return;
    const calc = () => {
      const remaining = parseFloat(cp.value||0) - parseFloat(dp.value||0);
      if (ra) ra.value = remaining.toFixed(2);
      const months = parseInt(mo?.value || 0);
      if (em && months > 0) em.value = Math.ceil(remaining / months).toFixed(2);
    };
    [cp, dp, mo].forEach(el => el?.addEventListener('input', calc));
  },

  // Landed cost allocation
  calcLandedCost(method) {
    const rows = document.querySelectorAll('.lc-item-row');
    const totalCost = parseFloat(document.getElementById('total_landed_cost')?.value || 0);
    if (!rows.length || !totalCost) return;

    let total = 0;
    rows.forEach(row => {
      const val = parseFloat(row.querySelector('[data-field="' + method + '"]')?.value || 0);
      total += val;
    });

    rows.forEach(row => {
      const val = parseFloat(row.querySelector('[data-field="' + method + '"]')?.value || 0);
      const alloc = total > 0 ? (val / total) * totalCost : 0;
      const qty = parseFloat(row.querySelector('[data-field="quantity"]')?.value || 1);
      const pv  = parseFloat(row.querySelector('[data-field="purchase_value"]')?.value || 0);
      const landed = pv + alloc;
      const perUnit = qty > 0 ? landed / qty : 0;
      row.querySelector('[data-field="allocated_cost"]').value = alloc.toFixed(2);
      row.querySelector('[data-field="landed_cost"]').value = landed.toFixed(2);
      row.querySelector('[data-field="per_unit_cost"]').value = perUnit.toFixed(4);
    });
  },

  init() {
    this.initSidebar();
    this.initActiveNav();
    this.initNumberInputs();
    this.initAgreementCalc();
    // Load notifications periodically
    if (document.getElementById('notif-count')) {
      this.loadNotifications();
      setInterval(() => this.loadNotifications(), 60000);
    }
    // Initialize tooltips
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
      new bootstrap.Tooltip(el, { trigger: 'hover' });
    });
    // Search tables
    document.querySelectorAll('[data-search-table]').forEach(input => {
      const tableId = input.getAttribute('data-search-table');
      CJ.initSearch(input.id, tableId);
    });
  }
};

document.addEventListener('DOMContentLoaded', () => CJ.init());
