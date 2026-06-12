<?php
// COLORJET ERP - Sample Configuration
// Copy this to config.php and fill in your values
// DO NOT commit config.php to version control

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'colorjet_erp');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'COLORJET ERP');
define('APP_URL', 'https://erp.colorjetbd.com');
define('APP_ENV', 'production'); // development | production
define('APP_DEBUG', false);
define('APP_KEY', 'change_this_to_a_random_32_char_string');
define('APP_TIMEZONE', 'Asia/Dhaka');

define('SESSION_NAME', 'COLORJET_ERP');
define('SESSION_LIFETIME', 480); // minutes
define('CSRF_TOKEN_NAME', 'cj_csrf');

define('UPLOAD_PATH', __DIR__ . '/uploads/');
define('UPLOAD_MAX_SIZE', 10485760); // 10MB
define('ALLOWED_FILE_TYPES', ['jpg','jpeg','png','gif','pdf','doc','docx','xls','xlsx','csv','txt']);

define('LOG_PATH', __DIR__ . '/logs/');
define('BACKUP_PATH', __DIR__ . '/backups/');

// Email (optional)
define('MAIL_HOST', '');
define('MAIL_PORT', 587);
define('MAIL_USER', '');
define('MAIL_PASS', '');
define('MAIL_FROM', 'noreply@colorjetbd.com');
define('MAIL_FROM_NAME', 'COLORJET ERP');

// Currency
define('DEFAULT_CURRENCY', 'BDT');
define('DEFAULT_CURRENCY_SYMBOL', '৳');
