<?php
declare(strict_types=1);

/**
 * MySQL settings for local XAMPP. Override these with environment variables
 * when the app is deployed to another server.
 */
function mutqinDb(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $host = getenv('MUTQIN_DB_HOST') ?: '127.0.0.1';
    $port = getenv('MUTQIN_DB_PORT') ?: '3306';
    $database = getenv('MUTQIN_DB_NAME') ?: 'mutqinspace';
    $username = getenv('MUTQIN_DB_USER') ?: 'root';
    $password = getenv('MUTQIN_DB_PASSWORD');
    if ($password === false) $password = '';

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
    );
    return $pdo;
}
