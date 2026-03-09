<?php
/**
 * LiteRise Database Connection and Helper Functions
 */

if (defined('LITERISE_DB_LOADED')) {
    return;
}
define('LITERISE_DB_LOADED', true);

/**
 * IMPORTANT:
 * - On Azure App Service, it's best to set DB_SERVER, DB_NAME, DB_USER, DB_PASSWORD in "Configuration > Application settings"
 * - .env is optional (useful locally)
 */

// CORS + JSON headers for API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Load .env (optional)
if (!function_exists('loadEnv')) {
    function loadEnv(string $path): void {
        if (!file_exists($path)) {
            error_log("Warning: .env file not found at $path");
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;

            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                $value = trim($value, "\"'");

                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

// Read env var helper (this fixes your fatal error)
if (!function_exists('envv')) {
    function envv(string $key, $default = null) {
        // Priority: getenv -> $_ENV -> $_SERVER -> default
        $val = getenv($key);
        if ($val !== false && $val !== '') return $val;

        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];

        return $default;
    }
}

// Load .env from project root (optional)
$envFile = realpath(__DIR__ . '/../.env');
if ($envFile) {
    loadEnv($envFile);
} else {
    error_log("Warning: .env file not found at " . __DIR__ . "/../.env");
}

// DB config (NO insecure local defaults for production)
$serverName      = envv('DB_SERVER',        'literise.database.windows.net');
$database        = envv('DB_NAME',          'literisedb');
$username        = envv('DB_USER',          'SAliterise');
$password        = envv('DB_PASSWORD',      'p@ssw0rd');
$debugMode       = strtolower((string)envv('DEBUG_MODE', 'false')) === 'true';
$anthropicApiKey = envv('ANTHROPIC_API_KEY', '');

// Basic validation (helps catch misconfig early)
if ($username === '' || $password === '') {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Server misconfigured",
        "message" => "Missing DB_USER or DB_PASSWORD. Set them in Azure App Service > Configuration."
    ]);
    exit;
}

// Global DB connection
$conn = null;

try {
    // Azure SQL recommended DSN settings
    // NOTE: use tcp: + port 1433, Encrypt=yes
    $dsn = "sqlsrv:Server=tcp:$serverName,1433;Database=$database;Encrypt=yes;TrustServerCertificate=no;LoginTimeout=30;";

    $conn = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    if ($debugMode) {
        error_log("✅ Database connection successful");
    }

} catch (PDOException $e) {
    http_response_code(500);
    $msg = $debugMode ? $e->getMessage() : "Database connection failed";
    echo json_encode([
        "success" => false,
        "error" => "Database connection failed",
        "message" => $msg
    ]);
    error_log("❌ Database connection failed: " . $e->getMessage());
    exit;
}

/* ---------- helpers ---------- */

if (!function_exists('sendResponse')) {
    function sendResponse($data, int $statusCode = 200): void {
        http_response_code($statusCode);
        if (is_array($data) && !isset($data['success'])) $data['success'] = true;
        echo json_encode($data);
        exit;
    }
}

if (!function_exists('sendError')) {
    function sendError(string $message, int $statusCode = 400, $details = null): void {
        http_response_code($statusCode);

        $response = ["success" => false, "error" => $message];

        $debugMode = strtolower((string)envv('DEBUG_MODE', 'false')) === 'true';
        if ($details !== null && $debugMode) $response['details'] = $details;

        echo json_encode($response);
        exit;
    }
}

if (!function_exists('validateRequired')) {
    function validateRequired(array $data, array $requiredFields): bool {
        $missing = [];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
                $missing[] = $field;
            }
        }
        if (!empty($missing)) {
            sendError("Missing required fields: " . implode(', ', $missing), 400);
        }
        return true;
    }
}

if (!function_exists('getJsonInput')) {
    function getJsonInput(): array {
        $input = file_get_contents("php://input");
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            sendError("Invalid JSON input: " . json_last_error_msg(), 400);
        }
        return $data ?? [];
    }
}

if (!function_exists('sanitizeInput')) {
    function sanitizeInput(string $input): string {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('isValidEmail')) {
    function isValidEmail(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('logActivity')) {
    function logActivity(int $studentID, string $activityType, string $activityDetails = ''): void {
        global $conn;
        try {
            $stmt = $conn->prepare(
                "INSERT INTO ActivityLog (StudentID, ActivityType, ActivityDetails) VALUES (?, ?, ?)"
            );
            $stmt->execute([$studentID, $activityType, $activityDetails]);
        } catch (Exception $e) {
            error_log("Failed to log activity: " . $e->getMessage());
        }
    }
}

if (!function_exists('isDatabaseConnected')) {
    function isDatabaseConnected(): bool {
        global $conn;
        try {
            $conn->query("SELECT 1");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}