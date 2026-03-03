<?php
/**
 * Get Quiz Questions API
 * 
 * Endpoint: GET /api/get_quiz_questions.php
 * Description: Retrieves quiz questions for a node
 * 
 * Parameters:
 * - node_id (required): Node ID
 * - placement_level (optional): Student level (1-3)
 * 
 * Response:
 * {
 *   "success": true,
 *   "quiz": {
 *     "node_id": 1,
 *     "total_questions": 5,
 *     "questions": [...]
 *   }
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/src/db.php';

try {
    $nodeId = isset($_GET['node_id']) ? intval($_GET['node_id']) : 0;
    $placementLevel = isset($_GET['placement_level']) ? intval($_GET['placement_level']) : 2;
    
    if ($nodeId === 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Node ID is required'
        ]);
        exit;
    }
    
    // Determine number of questions
    $numQuestions = 5;
    
    // Get quiz questions
    $stmt = $conn->prepare("
        SELECT TOP (?) 
            QuestionID,
            QuestionText,
            OptionA,
            OptionB,
            OptionC,
            OptionD,
            Difficulty
        FROM QuizQuestions
        WHERE NodeID = ?
        ORDER BY NEWID()
    ");
    
    $stmt->execute([$numQuestions, $nodeId]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($questions)) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'No quiz questions found for this node'
        ]);
        exit;
    }
    
    // Remove correct answers (validated server-side)
    $questionsForClient = array_map(function($q) {
        return [
            'question_id' => $q['QuestionID'],
            'question_text' => $q['QuestionText'],
            'option_a' => $q['OptionA'],
            'option_b' => $q['OptionB'],
            'option_c' => $q['OptionC'],
            'option_d' => $q['OptionD']
        ];
    }, $questions);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'quiz' => [
            'node_id' => $nodeId,
            'total_questions' => count($questions),
            'questions' => $questionsForClient
        ]
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
        'message' => 'Server error',
        'error' => (($_ENV['DEBUG_MODE'] ?? 'false') === 'true') ? $e->getMessage() : null
    ]);
}

?>
