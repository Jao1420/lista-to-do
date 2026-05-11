<?php

declare(strict_types=1);

function loadEnv(string $filePath): array
{
    if (!is_file($filePath)) {
        throw new RuntimeException('.env nao encontrado.');
    }

    $vars = [];
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        throw new RuntimeException('Falha ao ler .env.');
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);
        $vars[$key] = $value;
    }

    return $vars;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $env = loadEnv(__DIR__ . '/../.env');

    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $port = (int) ($env['DB_PORT'] ?? 3306);
    $username = $env['DB_USERNAME'] ?? 'root';
    $password = $env['DB_PASSWORD'] ?? '';
    $database = $env['DB_NAME'] ?? 'auditoria';

    $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);

    $pdo = new PDO($serverDsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', str_replace('`', '', $database)));
    $pdo->exec(sprintf('USE `%s`', str_replace('`', '', $database)));

    return $pdo;
}

function ensureSchema(PDO $pdo): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $stmt = $pdo->query("SHOW TABLES LIKE 'audits'");
    $exists = $stmt !== false ? $stmt->fetch() : false;

    if (!$exists) {
        $sql = file_get_contents(__DIR__ . '/../database/schema.sql');
        if ($sql === false) {
            throw new RuntimeException('Nao foi possivel carregar database/schema.sql');
        }

        $pdo->exec($sql);
    }

    // Migration: add finalized column if not present
    $col = $pdo->query("SHOW COLUMNS FROM audits LIKE 'finalizado'");
    if ($col === false || !$col->fetch()) {
        $pdo->exec("ALTER TABLE audits ADD COLUMN finalizado TINYINT(1) NOT NULL DEFAULT 0");
    }

    // Migration: add arquivo_pdf column if not present
    $col = $pdo->query("SHOW COLUMNS FROM audits LIKE 'arquivo_pdf'");
    if ($col === false || !$col->fetch()) {
        $pdo->exec("ALTER TABLE audits ADD COLUMN arquivo_pdf VARCHAR(255) NULL");
    }

    // Migration: add arquivo_pdf column to audit_items if not present
    $col = $pdo->query("SHOW COLUMNS FROM audit_items LIKE 'arquivo_pdf'");
    if ($col === false || !$col->fetch()) {
        $pdo->exec("ALTER TABLE audit_items ADD COLUMN arquivo_pdf VARCHAR(255) NULL");
    }

    $loaded = true;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatDate(?string $date): string
{
    if ($date === null || $date === '') {
        return '-';
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$dt) {
        return $date;
    }

    return $dt->format('d/m/Y');
}
