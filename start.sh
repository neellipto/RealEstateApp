#!/bin/bash
# COLORJET ERP — Start MariaDB + PHP together

MYSQL_BASEDIR=$(dirname "$(dirname "$(which mysqld)")")
MYSQL_DATADIR="$HOME/.mysql/data"
MYSQL_SOCKET="/tmp/mysql.sock"
MYSQL_CONF="/tmp/mysql_erp.cnf"
PHP_PORT=5000

# ── 1. Write MariaDB config ─────────────────────────────────────────────────
mkdir -p "$MYSQL_DATADIR" /tmp

cat > "$MYSQL_CONF" << EOF
[mysqld]
datadir=$MYSQL_DATADIR
socket=$MYSQL_SOCKET
pid-file=/tmp/mysql_erp.pid
port=3306
bind-address=127.0.0.1
skip-name-resolve
innodb_buffer_pool_size=32M
max_allowed_packet=64M
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci

[client]
socket=$MYSQL_SOCKET
EOF

# ── 2. Initialize data dir if needed ────────────────────────────────────────
if [ ! -f "$MYSQL_DATADIR/ibdata1" ]; then
    echo "[startup] Initializing MariaDB data directory..."
    mysql_install_db \
        --basedir="$MYSQL_BASEDIR" \
        --datadir="$MYSQL_DATADIR" \
        --mysqld="$MYSQL_BASEDIR/bin/mysqld" \
        --auth-root-authentication-method=normal \
        --skip-test-db \
        2>&1 | grep -v "^$" | grep -v "\[Note\]" | head -20
fi

# ── 3. Start MariaDB ─────────────────────────────────────────────────────────
echo "[startup] Starting MariaDB..."
# Remove stale socket from any previous run
rm -f "$MYSQL_SOCKET"

mysqld --defaults-file="$MYSQL_CONF" --skip-grant-tables &
MYSQLD_PID=$!

# Wait up to 30s for MariaDB to accept connections (not just socket file existence)
READY=0
for i in $(seq 1 30); do
    if mysqladmin --socket="$MYSQL_SOCKET" ping --silent 2>/dev/null; then
        echo "[startup] MariaDB ready (${i}s)"
        READY=1
        break
    fi
    sleep 1
done

if [ "$READY" -eq 0 ]; then
    echo "[startup] ERROR: MariaDB failed to start within 30s" >&2
    exit 1
fi

# ── 4. Create database + user if they don't exist ────────────────────────────
DB_EXISTS=$(mysql --socket="$MYSQL_SOCKET" -u root -e "SHOW DATABASES LIKE 'colorjet_erp';" 2>/dev/null | grep -c colorjet_erp || true)

if [ "$DB_EXISTS" -eq 0 ]; then
    echo "[startup] Creating database and importing schema..."
    mysql --socket="$MYSQL_SOCKET" -u root -e \
        "CREATE DATABASE IF NOT EXISTS colorjet_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql --socket="$MYSQL_SOCKET" -u root colorjet_erp < /home/runner/workspace/erp/database/schema.sql
    echo "[startup] Schema imported successfully."
else
    echo "[startup] Database already exists, skipping import."
fi

# ── 5. Start PHP development server ──────────────────────────────────────────
echo "[startup] Starting PHP on port $PHP_PORT..."
exec php -S "0.0.0.0:$PHP_PORT" -t /home/runner/workspace/erp /home/runner/workspace/erp/router.php
