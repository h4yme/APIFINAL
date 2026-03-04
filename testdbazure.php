<?php

// Simple test file for Azure SQL using PDO_SQLSRV

function loadEnv($path) {
    if (!file_exists($path)) {
        die(json_encode(["error" => ".env file not found"]));
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);

        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }

        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value, '"\'');
        }
    }
}

header('Content-Type: application/json');

loadEnv(__DIR__ . '/.env');

$server   = $_ENV['DB_SERVER'];
$database = $_ENV['DB_NAME'];
$username = $_ENV['DB_USER'];
$password = $_ENV['DB_PASSWORD'];

$encrypt  = strtolower($_ENV['DB_ENCRYPT'] ?? 'true') === 'true';
$trust    = strtolower($_ENV['DB_TRUST_CERT'] ?? 'false') === 'true';

try {

    $dsn = "sqlsrv:Server=$server;Database=$database";

    if ($encrypt) {
        $dsn .= ";Encrypt=1";
        $dsn .= $trust ? ";TrustServerCertificate=1" : ";TrustServerCertificate=0";
    }

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Test query
    $stmt = $pdo->query("SELECT TOP 1 name FROM sys.tables");
    $table = $stmt->fetch();

    echo json_encode([
        "success" => true,
        "message" => "Connected to Azure SQL successfully!",
        "sample_table" => $table ? $table['name'] : null
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {

    echo json_encode([
        "success" => false,
        "error" => "Connection failed",
        "message" => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}