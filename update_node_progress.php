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
    
    // Check if progress exists
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
        'error' => (($_ENV['DEBUG_MODE'] ?? 'false') === 'true') ? $e->getMessage() : null
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server errors',
        'error' => (($_ENV['DEBUG_MODE'] ?? 'false') === 'true') ? $e->getMessage() : null
    ]);
}

?>
