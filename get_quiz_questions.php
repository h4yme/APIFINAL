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
    $numQuestions = 10;
    
    // Get quiz questions (OptionsJSON holds options as {"A":"...","B":"...","C":"...","D":"..."})
    // Note: SQL Server requires TOP parameter to be bound as integer (PDO::PARAM_INT),
    // otherwise PDO's default string binding causes a runtime error.
    $stmt = $conn->prepare("
        SELECT TOP (?)
            QuestionID,
            QuestionText,
            OptionsJSON,
            EstimatedDifficulty
        FROM QuizQuestions
        WHERE NodeID = ? AND (IsActive IS NULL OR IsActive = 1)
        ORDER BY NEWID()
    ");

    $stmt->bindValue(1, $numQuestions, PDO::PARAM_INT);
    $stmt->bindValue(2, $nodeId, PDO::PARAM_INT);
    $stmt->execute();
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($questions)) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'No quiz questions found for this node'
        ]);
        exit;
    }

    // Remove correct answers (validated server-side); parse OptionsJSON into option_a..d
    // Supports two OptionsJSON formats from the database:
    //   Object: {"A":"could","B":"cold","C":"cat","D":"can"}
    //   Array:  ["could","cold"]  (imported from QuizQuestions.csv)
    $questionsForClient = array_map(function($q) {
        $raw  = $q['OptionsJSON'] ?? '{}';
        $opts = json_decode($raw, true) ?: [];

        if (array_values($opts) === $opts) {
            // Numeric/sequential array — map index 0→A, 1→B, 2→C, 3→D
            $keys   = ['A', 'B', 'C', 'D'];
            $mapped = [];
            foreach ($opts as $i => $val) {
                if (isset($keys[$i])) $mapped[$keys[$i]] = $val;
            }
            $opts = $mapped;
        }

        return [
            'question_id'   => $q['QuestionID'],
            'question_text' => $q['QuestionText'],
            'option_a'      => $opts['A'] ?? null,
            'option_b'      => $opts['B'] ?? null,
            'option_c'      => $opts['C'] ?? null,
            'option_d'      => $opts['D'] ?? null
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