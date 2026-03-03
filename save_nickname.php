<?php
/**
 * LiteRise Save Nickname API
 * 
 * POST /api/save_nickname.php
 * 
 * Request Body:
 * {
 *   "StudentID": 1,
 *   "Nickname": "Johnny"
 * }
 * 
 * Response:
 * {
 *   "success": true,
 *   "message": "Nickname saved successfully",
 *   "nickname": "Johnny"
 * }
 */

require_once __DIR__ . '/src/db.php';

// Get JSON input
$data = getJsonInput();

// Validate required fields
if (!isset($data['StudentID']) || !isset($data['Nickname'])) {
    sendError("StudentID and Nickname are required", 400);
}

$studentId = (int)$data['StudentID'];
$nickname = trim($data['Nickname']);

// Validate nickname
if (empty($nickname)) {
    sendError("Nickname cannot be empty", 400);
}

if (strlen($nickname) > 20) {
    sendError("Nickname must be 20 characters or less", 400);
}

try {
    // Update nickname in Students table
    $stmt = $conn->prepare("UPDATE Students SET Nickname = :nickname WHERE StudentID = :studentId");
    $stmt->bindValue(':nickname', $nickname, PDO::PARAM_STR);
    $stmt->bindValue(':studentId', $studentId, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        sendResponse([
            'message' => 'Nickname saved successfully',
            'nickname' => $nickname
        ]);
    } else {
        sendError("Student not found or nickname unchanged", 404);
    }

} catch (PDOException $e) {
    error_log("❌ Save nickname failed: " . $e->getMessage());
    sendError("Failed to save nickname: " . $e->getMessage(), 500);
}
?>
