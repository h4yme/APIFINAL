<?php
/**
 * Check Tutorial Status API
 *
 * Endpoint: GET /api/check_tutorial.php?student_id=1&tutorial_key=dashboard_tutorial
 * Description: Returns whether a student has seen a specific tutorial.
 *
 * Response:
 * {
 *   "success": true,
 *   "seen": false,
 *   "tutorial_key": "dashboard_tutorial"
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/src/db.php';

try {
    $studentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
    $tutorialKey = isset($_GET['tutorial_key']) ? trim($_GET['tutorial_key']) : '';

    if ($studentId === 0 || empty($tutorialKey)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required parameters: student_id and tutorial_key'
        ]);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT SeenAt
        FROM TutorialProgress
        WHERE StudentID = ? AND TutorialKey = ?
    ");
    $stmt->execute([$studentId, $tutorialKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'seen' => $row !== false,
        'tutorial_key' => $tutorialKey,
        'seen_at' => $row ? $row['SeenAt'] : null
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