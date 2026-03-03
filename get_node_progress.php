<?php
/**
 * Get Node Progress API
 * 
 * Endpoint: GET /api/get_node_progress.php
 * Description: Gets completion status for a student's node
 * 
 * Parameters:
 * - student_id (required)
 * - node_id (required)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/src/db.php';

try {
    $studentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
    $nodeId = isset($_GET['node_id']) ? intval($_GET['node_id']) : 0;
    
    if ($studentId === 0 || $nodeId === 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Student ID and Node ID are required'
        ]);
        exit;
    }
    
    $stmt = $conn->prepare("
        SELECT 
            LessonCompleted,
            GameCompleted,
            QuizCompleted,
            LatestQuizScore,
            NodeState,
            CompletedDate
        FROM StudentNodeProgress
        WHERE StudentID = ? AND NodeID = ?
    ");
    $stmt->execute([$studentId, $nodeId]);
    $progress = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$progress) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'progress' => [
                'lesson_completed' => false,
                'game_completed' => false,
                'quiz_completed' => false,
                'quiz_score' => 0,
                'adaptive_decision' => null,
                'completed_at' => null
            ]
        ]);
    } else {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'progress' => [
                'lesson_completed' => (bool)$progress['LessonCompleted'],
                'game_completed' => (bool)$progress['GameCompleted'],
                'quiz_completed' => (bool)$progress['QuizCompleted'],
                'quiz_score' => (float)$progress['LatestQuizScore'],
                'adaptive_decision' => $progress['NodeState'], // Using NodeState as adaptive decision
                'completed_at' => $progress['CompletedDate']
            ]
        ]);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error',
        'error' => $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
        'error' => $e->getMessage()
    ]);
}

?>
