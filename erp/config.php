<?php
// COLORJET ERP - Development Configuration
// For production deployment, run install.php or set real DB credentials

define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'colorjet_erp');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_SOCKET',  '/tmp/mysql.sock');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME',     'COLORJET ERP');
define('APP_URL',      '');
define('APP_ENV',      'development');
define('APP_DEBUG',    true);
define('APP_KEY',      'dev_key_colorjet_2024_bd_erp_v1');
define('APP_TIMEZONE', 'Asia/Dhaka');

define('SESSION_NAME',     'COLORJET_ERP');
define('SESSION_LIFETIME', 480);
define('CSRF_TOKEN_NAME',  'cj_csrf');

define('UPLOAD_PATH',      __DIR__ . '/uploads/');
define('UPLOAD_MAX_SIZE',  10485760);
define('ALLOWED_FILE_TYPES', ['jpg','jpeg','png','gif','pdf','doc','docx','xls','xlsx','csv','txt']);

define('LOG_PATH',    __DIR__ . '/logs/');
define('BACKUP_PATH', __DIR__ . '/backups/');

define('DEFAULT_CURRENCY',        'BDT');
define('DEFAULT_CURRENCY_SYMBOL', '৳');
