<?php
/**
 * Update Node Progress API
 * 
 * Endpoint: POST /api/update_node_progress.php
 * Description: Updates completion status for lesson/game/quiz phases
 * 
 * Request Body:
 * {
 *   "student_id": 1,
 *   "node_id": 1,
 *   "phase": "lesson"
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
        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON format'
        ]);
        exit;
    }
    
    $studentId = isset($data['student_id']) ? intval($data['student_id']) : 0;
    $nodeId = isset($data['node_id']) ? intval($data['node_id']) : 0;
    $phase = isset($data['phase']) ? strtolower($data['phase']) : '';
    
    if ($studentId === 0 || $nodeId === 0 || empty($phase)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required data: student_id, node_id, and phase are required'
        ]);
        exit;
    }
    
    // Validate phase
    if (!in_array($phase, ['lesson', 'game', 'quiz'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid phase. Must be: lesson, game, or quiz'
        ]);
        exit;
    }
    
    // Determine node type: core nodes live in Nodes (IDs 101-513); supplemental nodes
    // live in SupplementalNodes with auto-incremented IDs that overlap the core range.
    // Always check Nodes first — if found there it is a core node, never supplemental.
    $stmtCore = $conn->prepare("SELECT COUNT(*) FROM Nodes WHERE NodeID = ?");
    $stmtCore->execute([$nodeId]);
    $isCoreNode = (int)$stmtCore->fetchColumn() > 0;

    $isSupplemental = false;
    if (!$isCoreNode) {
        $stmtCheck = $conn->prepare("SELECT COUNT(*) FROM SupplementalNodes WHERE SupplementalNodeID = ?");
        $stmtCheck->execute([$nodeId]);
        $isSupplemental = (int)$stmtCheck->fetchColumn() > 0;
    }

    if ($isSupplemental) {
        // For supplemental nodes, update StudentSupplementalProgress.IsCompleted when lesson phase completes
        // (supplemental nodes don't have game/quiz phases — lesson completion = done)
        if ($phase === 'lesson') {
            $stmt = $conn->prepare("
                UPDATE StudentSupplementalProgress
                SET IsCompleted = 1, CompletedDate = GETDATE()
                WHERE StudentID = ? AND SupplementalNodeID = ?
            ");
            $stmt->execute([$studentId, $nodeId]);

            // Note: QuizCompleted for the parent core node is intentionally NOT set here.
            // The Android client will auto-launch the quiz retake after intervention;
            // submit_quiz.php will set QuizCompleted=1 when the student passes (score >= 70).
        }
        // game/quiz phases on supplemental nodes are no-ops
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => ucfirst($phase) . ' completed successfully'
        ]);
        exit;
    }

    // Core node — check if progress row exists
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM StudentNodeProgress
        WHERE StudentID = ? AND NodeID = ?
    ");
    $stmt->execute([$studentId, $nodeId]);
    $exists = $stmt->fetchColumn();

    if (!$exists) {
        $stmt = $conn->prepare("
            INSERT INTO StudentNodeProgress (StudentID, NodeID, LessonCompleted, GameCompleted, QuizCompleted)
            VALUES (?, ?, 0, 0, 0)
        ");
        $stmt->execute([$studentId, $nodeId]);
    }

    // Update phase
    $field = '';
    switch ($phase) {
        case 'lesson':
            $field = 'LessonCompleted';
            break;
        case 'game':
            $field = 'GameCompleted';
            break;
        case 'quiz':
            $field = 'QuizCompleted';
            break;
    }

    $stmt = $conn->prepare("
        UPDATE StudentNodeProgress
        SET $field = 1
        WHERE StudentID = ? AND NodeID = ?
    ");
    $stmt->execute([$studentId, $nodeId]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => ucfirst($phase) . ' completed successfully'
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error',
        'error' => ((($_ENV['DEBUG_MODE'] ?? getenv('DEBUG_MODE')) ?? 'false') === 'true') ? $e->getMessage() : null
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server errors',
        'error' => ((($_ENV['DEBUG_MODE'] ?? getenv('DEBUG_MODE')) ?? 'false') === 'true') ? $e->getMessage() : null
    ]);
}

?>