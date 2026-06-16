<?php
/**
 * COLORJET ERP - Database Connection
 * PDO with prepared statements
 */

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (!defined('DB_HOST')) {
        $cfgFile = dirname(__DIR__) . '/config.php';
        if (file_exists($cfgFile)) require_once $cfgFile;
        else throw new RuntimeException('config.php not found. Please run install.php');
    }

    // Use Unix socket if available (faster, no TCP overhead)
    if (defined('DB_SOCKET') && DB_SOCKET && file_exists(DB_SOCKET)) {
        $dsn = sprintf(
            'mysql:unix_socket=%s;dbname=%s;charset=%s',
            DB_SOCKET, DB_NAME, DB_CHARSET
        );
    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
    }
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone='+06:00'",
    ];
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        if (defined('APP_DEBUG') && APP_DEBUG) {
            throw $e;
        }
        die(json_encode(['ok' => false, 'message' => 'Database connection failed. Please check configuration.']));
    }
    return $pdo;
}

function dbQuery(string $sql, array $params = []): PDOStatement {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function dbFetch(string $sql, array $params = []): ?array {
    return dbQuery($sql, $params)->fetch() ?: null;
}

function dbFetchAll(string $sql, array $params = []): array {
    return dbQuery($sql, $params)->fetchAll();
}

function dbInsert(string $table, array $data): int {
    $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($data)));
    $placeholders = implode(',', array_fill(0, count($data), '?'));
    dbQuery("INSERT INTO `$table` ($cols) VALUES ($placeholders)", array_values($data));
    return (int) getDB()->lastInsertId();
}

function dbUpdate(string $table, array $data, array $where): int {
    $set = implode(',', array_map(fn($c) => "`$c`=?", array_keys($data)));
    $cond = implode(' AND ', array_map(fn($c) => "`$c`=?", array_keys($where)));
    $stmt = dbQuery("UPDATE `$table` SET $set WHERE $cond", [...array_values($data), ...array_values($where)]);
    return $stmt->rowCount();
}

function dbDelete(string $table, array $where): int {
    $cond = implode(' AND ', array_map(fn($c) => "`$c`=?", array_keys($where)));
    $stmt = dbQuery("DELETE FROM `$table` WHERE $cond", array_values($where));
    return $stmt->rowCount();
}

function generateCode(string $prefix, string $table, string $column, int $pad = 5): string {
    $row = dbFetch("SELECT MAX(CAST(SUBSTRING($column, " . (strlen($prefix)+1) . ") AS UNSIGNED)) as mx FROM `$table`");
    $next = ($row['mx'] ?? 0) + 1;
    return $prefix . str_pad($next, $pad, '0', STR_PAD_LEFT);
}
