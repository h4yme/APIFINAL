<?php
$server = $_ENV['DB_SERVER'] ?? 'DESKTOP-PEM6F9E\SQLEXPRESS';
$db     = $_ENV['DB_NAME'] ?? 'LiteRiseDB';
$user   = $_ENV['DB_USER'] ?? 'sa';
$pass   = $_ENV['DB_PASSWORD'] ?? 'p@ssw0rd';

$dsn = "sqlsrv:Server=$server;Database=$db";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "✅ Connected via PDO sqlsrv!";
} catch (PDOException $e) {
    http_response_code(500);
    echo "❌ DB connect failed: " . $e->getMessage();
}