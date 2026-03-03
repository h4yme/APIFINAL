<?php

/**

 * LiteRise Database Connection and Helper Functions

 * This file provides database connectivity and common utility functions

 */

// Prevent multiple inclusions
if (defined('LITERISE_DB_LOADED')) {
    return;
}
define('LITERISE_DB_LOADED', true);



// Set headers for all API responses

header('Content-Type: application/json');

header('Access-Control-Allow-Origin: *');

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

header('Access-Control-Allow-Headers: Content-Type, Authorization');

 

// Handle preflight OPTIONS request

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

    http_response_code(200);

    exit();

}

 

// Load environment variables from .env file

if (!function_exists('loadEnv')) {
    function loadEnv($path) {

        if (!file_exists($path)) {

            error_log("Warning: .env file not found at $path");

            return;

        }

 

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {

        $line = trim($line);

 

        // Skip comments and empty lines

        if (empty($line) || strpos($line, '#') === 0) {

            continue;

        }

 

        // Parse key=value

        if (strpos($line, '=') !== false) {

            list($key, $value) = explode('=', $line, 2);

            $key = trim($key);

            $value = trim($value);

 

            // Remove quotes if present

            $value = trim($value, '"\'');

 

            $_ENV[$key] = $value;

            putenv("$key=$value");

        }

    }

    }
}



// Load environment variables

$envFile = __DIR__ . '/../.env';

loadEnv($envFile);

 

// Database configuration from environment variables

$serverName = $_ENV['DB_SERVER'] ?? 'DESKTOP-PEM6F9E\SQLEXPRESS';

$database = $_ENV['DB_NAME'] ?? 'LiteRiseDB';

$username = $_ENV['DB_USER'] ?? 'sa';

$password = $_ENV['DB_PASSWORD'] ?? 'p@ssw0rd';

 

// Global database connection

$conn = null;

 

try {

    // Connect using SQL Server PDO driver

    $conn = new PDO("sqlsrv:Server=$serverName;Database=$database", $username, $password);

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

 

    // Log successful connection in debug mode

    if (($_ENV['DEBUG_MODE'] ?? 'false') === 'true') {

        error_log("✅ Database connection successful");

    }

} catch (PDOException $e) {

    http_response_code(500);

    $errorMessage = ($_ENV['DEBUG_MODE'] ?? 'false') === 'true'

        ? $e->getMessage()

        : "Database connection failed";

 

    echo json_encode([

        "success" => false,

        "error" => "Database connection failed",

        "message" => $errorMessage

    ]);

 

    error_log("❌ Database connection failed: " . $e->getMessage());

    exit;

}

 

/**

 * Send successful JSON response

 *

 * @param mixed $data Data to send

 * @param int $statusCode HTTP status code

 */

if (!function_exists('sendResponse')) {
function sendResponse($data, $statusCode = 200) {

    http_response_code($statusCode);

 

    // Ensure data has success flag

    if (is_array($data) && !isset($data['success'])) {

        $data['success'] = true;

    }

 

    echo json_encode($data);

    exit;

}
}

 

/**

 * Send error JSON response

 *

 * @param string $message Error message

 * @param int $statusCode HTTP status code

 * @param mixed $details Additional error details (only in debug mode)

 */

if (!function_exists('sendError')) {
function sendError($message, $statusCode = 400, $details = null) {

    http_response_code($statusCode);

 

    $response = [

        "success" => false,

        "error" => $message

    ];

 

    // Include details only in debug mode

    if ($details && ($_ENV['DEBUG_MODE'] ?? 'false') === 'true') {

        $response['details'] = $details;

    }

 

    echo json_encode($response);

    exit;

}
}

 

/**

 * Validate required fields in request data

 *

 * @param array $data Request data

 * @param array $requiredFields Array of required field names

 * @return bool True if all fields present, exits with error if not

 */

if (!function_exists('validateRequired')) {
function validateRequired($data, $requiredFields) {

    $missing = [];

 

    foreach ($requiredFields as $field) {

        if (!isset($data[$field]) || trim($data[$field]) === '') {

            $missing[] = $field;

        }

    }

 

    if (!empty($missing)) {

        sendError("Missing required fields: " . implode(', ', $missing), 400);

    }

 

    return true;

}
}

 

/**

 * Get JSON input from request body

 *

 * @return array Decoded JSON data

 */

if (!function_exists('getJsonInput')) {
function getJsonInput() {

    $input = file_get_contents("php://input");

    $data = json_decode($input, true);

 

    if (json_last_error() !== JSON_ERROR_NONE) {

        sendError("Invalid JSON input: " . json_last_error_msg(), 400);

    }

 

    return $data ?? [];

}
}

 

/**

 * Sanitize string input

 *

 * @param string $input Input string

 * @return string Sanitized string

 */

if (!function_exists('sanitizeInput')) {
function sanitizeInput($input) {

    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');

}
}

 

/**

 * Validate email format

 *

 * @param string $email Email address

 * @return bool True if valid

 */

if (!function_exists('isValidEmail')) {
function isValidEmail($email) {

    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

}
}

 

/**

 * Log activity to ActivityLog table

 *

 * @param int $studentID Student ID

 * @param string $activityType Type of activity

 * @param string $activityDetails Details of activity

 */

if (!function_exists('logActivity')) {
function logActivity($studentID, $activityType, $activityDetails = '') {

    global $conn;

 

    try {

        $stmt = $conn->prepare(

            "INSERT INTO ActivityLog (StudentID, ActivityType, ActivityDetails)

             VALUES (?, ?, ?)"

        );

        $stmt->execute([$studentID, $activityType, $activityDetails]);

    } catch (Exception $e) {

        // Don't fail the request if logging fails

        error_log("Failed to log activity: " . $e->getMessage());

    }

}
}

 

/**

 * Check if database connection is alive

 *

 * @return bool True if connected

 */

if (!function_exists('isDatabaseConnected')) {
function isDatabaseConnected() {

    global $conn;

 

    try {

        $conn->query("SELECT 1");

        return true;

    } catch (Exception $e) {

        return false;

    }

}
}

?>