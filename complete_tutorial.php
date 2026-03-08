<?php
/**
 * Complete Tutorial API
 *
 * Endpoint: POST /api/complete_tutorial.php
 * Description: Marks a tutorial as seen for a student.
 *
 * Request Body:
 * {
 *   "student_id": 1,
 *   "tutorial_key": "dashboard_tutorial"
 * }
 *
 * Response:
 * {
 *   "success": true,
 *   "message": "Tutorial marked as seen"
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/src/db.php';

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON format']);
        exit;
    }

    $studentId = isset($data['student_id']) ? intval($data['student_id']) : 0;
    $tutorialKey = isset($data['tutorial_key']) ? trim($data['tutorial_key']) : '';

    if ($studentId === 0 || empty($tutorialKey)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields: student_id and tutorial_key'
        ]);
        exit;
    }

    // Upsert: insert if not exists, do nothing if already seen
    $stmt = $conn->prepare("
        IF NOT EXISTS (
            SELECT 1 FROM TutorialProgress
            WHERE StudentID = ? AND TutorialKey = ?
        )
        INSERT INTO TutorialProgress (StudentID, TutorialKey, SeenAt)
        VALUES (?, ?, GETDATE())
    ");
    $stmt->execute([$studentId, $tutorialKey, $studentId, $tutorialKey]);

    echo json_encode([
        'success' => true,
        'message' => 'Tutorial marked as seen'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error',
        'error' => ((($_ENV['DEBUG_MODE'] ?? getenv('DEBUG_MODE')) ?? 'false') === 'true') ? $e->getMessage() : null
    ]);
}
?>