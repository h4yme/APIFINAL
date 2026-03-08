<?php
/**
 * Get Quiz Questions API
 * Endpoint: GET /api/get_quiz_questions.php
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
        echo json_encode(['success' => false, 'message' => 'Node ID is required']);
        exit;
    }

    $numQuestions = 10;

    $stmt = $conn->prepare("
        SELECT TOP (?)
            QuestionID,
            QuestionText,
            QuestionType,
            OptionsJSON,
            CorrectAnswer,
            EstimatedDifficulty as Difficulty,
            SkillCategory
        FROM QuizQuestions
        WHERE NodeID = ? AND IsActive = 1
        ORDER BY NEWID()
    ");
    $stmt->execute([$numQuestions, $nodeId]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($questions)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No quiz questions found for this node']);
        exit;
    }

    $questionsForClient = array_map(function($q) {
        $options = json_decode($q['OptionsJSON'], true) ?? [];
        return [
            'question_id'    => $q['QuestionID'],
            'question_text'  => $q['QuestionText'],
            'question_type'  => $q['QuestionType'],
            'option_a'       => $options[0] ?? null,
            'option_b'       => $options[1] ?? null,
            'option_c'       => $options[2] ?? null,
            'option_d'       => $options[3] ?? null,
            'skill_category' => $q['SkillCategory'],
        ];
    }, $questions);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'quiz' => [
            'node_id'         => $nodeId,
            'total_questions' => count($questions),
            'questions'       => $questionsForClient
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
}
?>
