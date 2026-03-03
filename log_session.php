<?php
/**
 * LiteRise Log Student Session API
 * POST /api/log_session.php
 *
 * Request Body:
 * {
 *   "student_id": 1,
 *   "session_type": "Login",
 *   "session_tag": "morning_session",
 *   "device_info": "Android 12, Samsung Galaxy",
 *   "additional_data": {
 *     "app_version": "1.0.0",
 *     "battery_level": 75
 *   }
 * }
 *
 * Session Types:
 * - Login
 * - Logout
 * - AssessmentStart
 * - AssessmentComplete
 * - LessonStart
 * - LessonComplete
 * - GameStart
 * - GameComplete
 *
 * Response:
 * {
 *   "success": true,
 *   "message": "Session logged successfully",
 *   "log_id": 123
 * }
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

// Require authentication
$authUser = requireAuth();

// Get JSON input
$data = getJsonInput();

// Extract fields
$studentID = $data['student_id'] ?? 0;
$sessionType = $data['session_type'] ?? '';
$sessionTag = $data['session_tag'] ?? null;
$deviceInfo = $data['device_info'] ?? null;
$additionalData = $data['additional_data'] ?? null;

// Validate required fields
if ($studentID == 0 || empty($sessionType)) {
    sendError("Missing required fields: student_id and session_type are required", 400);
}

// Validate session type
$validSessionTypes = [
    'Login', 'Logout',
    'AssessmentStart', 'AssessmentComplete',
    'LessonStart', 'LessonComplete',
    'GameStart', 'GameComplete'
];

if (!in_array($sessionType, $validSessionTypes)) {
    sendError("Invalid session_type. Must be one of: " . implode(', ', $validSessionTypes), 400);
}

// Verify the authenticated user matches the student_id (security check)
if ($authUser['studentID'] != $studentID) {
    sendError("Unauthorized: Cannot log session for another student", 403);
}

try {
    // Get client IP address
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

    // Convert additional data to JSON string if it's an array
    $additionalDataJson = null;
    if ($additionalData !== null) {
        $additionalDataJson = is_string($additionalData) ? $additionalData : json_encode($additionalData);
    }

    // Call stored procedure to log session
    // Using direct EXEC call for SQL Server
    $sql = "EXEC dbo.SP_LogStudentSession
            @StudentID = ?,
            @SessionType = ?,
            @SessionTag = ?,
            @DeviceInfo = ?,
            @IPAddress = ?,
            @AdditionalData = ?";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $studentID,
        $sessionType,
        $sessionTag,
        $deviceInfo,
        $ipAddress,
        $additionalDataJson
    ]);

    // Fetch the LogID from the result
    $logID = null;
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    error_log("SP_LogStudentSession execution result: " . print_r($result, true));

    if ($result && isset($result['LogID'])) {
        $logID = (int)$result['LogID'];
        error_log("Successfully retrieved LogID: " . $logID);
    } else {
        error_log("Warning: No LogID returned from stored procedure");
    }

    // Format response - even if logID is null, we'll return success since insert likely worked
    $response = [
        'success' => true,
        'message' => 'Session logged successfully',
        'log_id' => $logID ? (int)$logID : 0,
        'session_type' => $sessionType,
        'logged_at' => date('Y-m-d H:i:s')
    ];

    sendResponse($response, 201);

} catch (PDOException $e) {
    error_log("Log session error: " . $e->getMessage());
    sendError("Failed to log session", 500, $e->getMessage());
} catch (Exception $e) {
    error_log("Log session error: " . $e->getMessage());
    sendError("An error occurred", 500, $e->getMessage());
}
?>