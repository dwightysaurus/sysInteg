<?php
/**
 * Database helpers (PDO + prepared statements only).
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** Run a query and return the statement. */
function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

function setting(string $key, string $default = ''): string
{
    $row = db_one('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
    return $row ? $row['setting_value'] : $default;
}

function current_term(): array
{
    $term = db_one('SELECT id, name FROM terms WHERE is_current = 1 LIMIT 1');
    if (!$term) {
        throw new RuntimeException('No current term is set in the terms table.');
    }
    return $term;
}

function max_units(): int
{
    return (int)setting('max_units', '21');
}
