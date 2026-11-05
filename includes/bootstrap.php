<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

function app_config(): array
{
    static $config;
    if ($config === null) {
        $config = require APP_ROOT . '/config/database.php';
    }
    return $config;
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    ensure_database();
    $c = app_config();
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $c['host'],
        $c['port'],
        $c['dbname'],
        $c['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function ensure_database(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $c = app_config();
    $name = (string) ($c['dbname'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('Invalid database name.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;charset=%s',
        $c['host'],
        $c['port'],
        $c['charset'] ?? 'utf8mb4'
    );
    $server = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $server->exec(
        "CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    $server->exec("USE `{$name}`");

    $haveFolders = (bool) $server->query("SHOW TABLES LIKE 'folders'")->fetch();
    $haveBookmarks = (bool) $server->query("SHOW TABLES LIKE 'bookmarks'")->fetch();
    if (!$haveFolders || !$haveBookmarks) {
        $sql = file_get_contents(APP_ROOT . '/sql/schema.sql');
        if ($sql === false) {
            throw new RuntimeException('Could not read the database schema.');
        }
        foreach (split_sql($sql) as $statement) {
            if (preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $statement)) {
                continue;
            }
            $server->exec($statement);
        }
    }

    $ready = true;
}

function split_sql(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $parts = preg_split('/;\s*(?:\R|$)/', $sql) ?: [];
    $statements = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $statements[] = $part;
        }
    }
    return $statements;
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function clean_text(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function clip(string $value, int $max): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function normalize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
        $url = 'https://' . $url;
    }
    return $url;
}

function valid_http_url(string $url): bool
{
    if ($url === '' || strlen($url) > 2048) {
        return false;
    }
    if (preg_match('/[\x00-\x20]/', $url)) {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return false;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    return $scheme === 'http' || $scheme === 'https';
}

function display_host(string $url): string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host) ?? $host;
    return $host !== '' ? $host : $url;
}

function format_bookmark_date(string $dt): string
{
    $t = DateTime::createFromFormat('Y-m-d H:i:s', substr($dt, 0, 19));
    if (!$t) {
        return '';
    }
    $now = new DateTimeImmutable('now');
    return $t->format('Y') === $now->format('Y') ? $t->format('M j') : $t->format('M j, Y');
}

function uploads_dir(): string
{
    $dir = APP_ROOT . '/uploads';
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the uploads folder.');
    }
    if (!is_writable($dir)) {
        @chmod($dir, 0777);
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('The uploads folder is not writable.');
    }
    return $dir;
}

function db_error_message(Throwable $e): string
{
    error_log('Bookmarks DB: ' . $e->getMessage());
    $msg = $e->getMessage();
    if (
        str_contains($msg, '2002')
        || str_contains($msg, '2003')
        || str_contains($msg, 'refused')
        || str_contains($msg, 'No such file')
        || str_contains($msg, 'actively refused')
    ) {
        return 'MySQL is not running. In the XAMPP manager, start MySQL, then refresh this page.';
    }
    if (str_contains($msg, 'Access denied')) {
        return 'MySQL refused the login in config/database.php. Update the user or password, then refresh.';
    }
    return 'Could not set up the bookmarks database.';
}

function public_error(Throwable $e): string
{
    if ($e instanceof PDOException) {
        return db_error_message($e);
    }
    if ($e instanceof RuntimeException) {
        return $e->getMessage();
    }
    error_log('Bookmarks: ' . $e->getMessage());
    return 'Something went wrong.';
}

function assert_same_origin(): void
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return;
    }
    $originHost = parse_url($origin, PHP_URL_HOST);
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (!is_string($originHost) || $host === '' || strcasecmp($originHost, $host) !== 0) {
        throw new RuntimeException('Request blocked.');
    }
}
