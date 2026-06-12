# COLORJET ERP — Deployment Guide

## Overview
COLORJET ERP is a full-stack PHP 8.2 + MySQL ERP system built for COLORJET Bangladesh.

**Production URL:** https://erp.colorjetbd.com/

---

## Requirements
- PHP 8.0+ (8.2 recommended)
- MySQL 5.7+ or MariaDB 10.3+
- Apache 2.4+ with mod_rewrite enabled
- SSL Certificate (HTTPS required for production)
- Extensions: PDO, PDO_MySQL, mbstring, json, openssl, fileinfo

---

## cPanel Deployment Steps

### 1. Upload Files
Upload all files from `/erp/` to your cPanel public_html or a subdomain folder via:
- File Manager
- FTP (FileZilla recommended)
- Git Clone

```
public_html/erp/  (or set up as subdomain: erp.colorjetbd.com)
```

### 2. Create Database
In cPanel → MySQL Databases:
1. Create database: `colorjetbd_erp`
2. Create user: `colorjetbd_erp_user` (strong password)
3. Assign user to database with ALL PRIVILEGES

### 3. Run Installer
Visit: `https://erp.colorjetbd.com/install.php`

Fill in:
- Database host: `localhost`
- Database name: `colorjetbd_erp`
- Database user: `colorjetbd_erp_user`
- Database password: (your chosen password)
- App URL: `https://erp.colorjetbd.com`

Click **Install Database** — this runs `database/schema.sql` automatically.

### 4. Post-Installation Security
1. **Delete** or **protect** `install.php` via .htaccess
2. Change default admin password: admin@colorjetbd.com
3. Change default owner password: owner@colorjetbd.com
4. Review PHP error reporting settings in `config.php`

### 5. File Permissions
```bash
chmod 755 erp/
chmod 644 erp/*.php
chmod 755 erp/uploads/
chmod 755 erp/backups/
chmod 644 erp/config.php
chmod 644 erp/.htaccess
```

### 6. Subdomain Setup (cPanel)
1. cPanel → Subdomains
2. Subdomain: `erp`
3. Domain: `colorjetbd.com`
4. Root: `/public_html/erp`
5. Enable SSL via AutoSSL

---

## Default Login Credentials

| Role  | Email | Password |
|-------|-------|----------|
| Owner | owner@colorjetbd.com | password |
| Admin | admin@colorjetbd.com | password |

⚠️ **Change these passwords immediately after first login!**

---

## Directory Structure
```
erp/
├── index.php              # Owner dashboard
├── login.php              # Login page
├── install.php            # Web installer (delete after use)
├── config.php             # Auto-generated config (PROTECT THIS)
├── config.sample.php      # Sample config file
├── .htaccess              # Apache security & cache rules
├── includes/
│   ├── db.php             # PDO database helpers
│   ├── auth.php           # Session, CSRF, login/logout
│   ├── functions.php      # Helper functions
│   └── layout.php         # Header, sidebar, footer
├── assets/
│   ├── css/app.css        # Main stylesheet
│   └── js/app.js          # Main JavaScript
├── database/
│   └── schema.sql         # Full database schema (35+ tables)
├── api/
│   └── index.php          # AJAX API endpoints
├── uploads/               # User uploads (protected)
└── backups/               # Database backups (protected)
```

---

## Module Pages

| Page | Description |
|------|-------------|
| index.php | Owner dashboard with stats |
| customers.php | Customer management |
| sales.php | Sales orders |
| invoices.php | Invoice generation |
| payments.php | Payment tracking |
| agreement-generator.php | Installment agreement creator |
| agreement-records.php | Agreements + EMI payment |
| service-tickets.php | Service request management |
| warranty.php | Warranty/AMC register |
| engineer-schedule.php | Engineer job calendar |
| engineer-dashboard.php | Engineer personal dashboard |
| office-tasks.php | Task management |
| daily-ledger.php | Cash/bank ledger |
| customer-ledger.php | Per-customer ledger |
| supplier-ledger.php | Per-supplier ledger |
| products.php | Product catalog |
| stock.php | Stock management |
| parts-stock.php | Spare parts inventory |
| suppliers.php | Supplier management |
| foreign-purchase.php | Purchase orders |
| lc-tt.php | LC/TT/Shipment tracking |
| landed-cost.php | Landed cost calculator |
| reports.php | Business reports (12+ types) |
| users.php | User/role management |
| settings.php | Company & system settings |
| file-import.php | CSV data import |
| backup.php | Database backup |
| print-invoice.php | Printable invoice |
| print-agreement.php | Printable agreement |

---

## Mobile / WebView

The ERP is fully responsive (Bootstrap 5.3) and works in:
- Mobile browsers (Chrome, Firefox, Safari)
- Android WebView apps
- Tablet devices

For WebView apps, use URL: `https://erp.colorjetbd.com/login.php`

---

## Backup Strategy
1. Use the built-in backup at: `https://erp.colorjetbd.com/backup.php`
2. Set up cPanel automated backups (daily/weekly)
3. Download backups to local storage regularly

---

## Security Checklist
- [ ] SSL/HTTPS enabled
- [ ] Default passwords changed
- [ ] `install.php` deleted or restricted
- [ ] `config.php` not web-accessible (protected by .htaccess)
- [ ] `database/` directory protected
- [ ] `backups/` directory protected
- [ ] PHP error display disabled in production
- [ ] Regular database backups scheduled

---

## Support
For technical support or customization, contact the development team.

**COLORJET Bangladesh** — Quality • Commitment • Service
