<?php
/**
 * Get Next Adaptive Question API
 * POST /api/get_next_question.php
 *
 * Uses IRT-based adaptive selection to choose the best next question
 * based on student's current ability estimate (theta)
 *
 * Request Body:
 * {
 *   "student_id": 27,
 *   "session_id": 1736149200,
 *   "current_theta": 0.0,
 *   "assessment_type": "PreAssessment",
 *   "category": "Oral Language"  // Optional - filter by category
 * }
 *
 * Response:
 * {
 *   "success": true,
 *   "question": {
 *     "item_id": 15,
 *     "category": "Oral Language",
 *     "subcategory": "Vocabulary",
 *     "question_text": "Which word means the same as 'happy'?",
 *     "question_type": "MultipleChoice",
 *     "option_a": "Sad",
 *     "option_b": "Angry",
 *     "option_c": "Joyful",
 *     "option_d": "Tired",
 *     "difficulty": -0.2,
 *     "discrimination": 1.5,
 *     "estimated_time": 30
 *   },
 *   "progress": {
 *     "questions_answered": 5,
 *     "current_theta": 0.3
 *   }
 * }
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

// Require authentication
$authUser = requireAuth();

// Get JSON input
$data = getJsonInput();

// Validate required fields
validateRequired($data, ['student_id', 'session_id', 'current_theta', 'assessment_type']);

$studentID = (int)$data['student_id'];
$sessionID = (int)$data['session_id'];
$currentTheta = (float)$data['current_theta'];
$assessmentType = sanitizeInput($data['assessment_type']);
$categoryFilter = isset($data['category']) ? sanitizeInput($data['category']) : null;

// Verify user can only get questions for themselves
if ($authUser['studentID'] != $studentID) {
    sendError("Unauthorized: Cannot get questions for another student", 403);
}

// Validate assessment type
if (!in_array($assessmentType, ['PreAssessment', 'PostAssessment'])) {
    sendError("Invalid assessment_type. Must be 'PreAssessment' or 'PostAssessment'", 400);
}

try {
    // Call stored procedure to get next question
    $stmt = $conn->prepare("EXEC dbo.SP_GetNextAdaptiveQuestion
        @StudentID = ?,
        @SessionID = ?,
        @CurrentTheta = ?,
        @AssessmentType = ?,
        @CategoryFilter = ?
    ");

    $stmt->execute([
        $studentID,
        $sessionID,
        $currentTheta,
        $assessmentType,
        $categoryFilter
    ]);

    $question = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$question) {
        sendError("No more questions available for this session", 404);
    }

    // Get progress information
    $progressStmt = $conn->prepare("
        SELECT
            COUNT(*) as QuestionsAnswered,
            AVG(CAST(IsCorrect AS FLOAT)) as Accuracy
        FROM dbo.StudentResponses
        WHERE StudentID = ? AND SessionID = ?
    ");
    $progressStmt->execute([$studentID, $sessionID]);
    $progress = $progressStmt->fetch(PDO::FETCH_ASSOC);

    // Format response (hide correct answer)
    $response = [
        'success' => true,
        'question' => [
            'item_id' => (int)$question['ItemID'],
            'category' => $question['Category'],
            'subcategory' => $question['Subcategory'],
            'skill_area' => $question['SkillArea'] ?? null,
            'question_text' => $question['QuestionText'],
            'question_type' => $question['QuestionType'],
            'reading_passage' => $question['ReadingPassage'] ?? null,
            'option_a' => $question['OptionA'],
            'option_b' => $question['OptionB'],
            'option_c' => $question['OptionC'],
            'option_d' => $question['OptionD'],
            'difficulty' => (float)$question['DifficultyParam'],
            'discrimination' => (float)$question['DiscriminationParam'],
            'estimated_time' => (int)$question['EstimatedTime']
        ],
        'progress' => [
            'questions_answered' => (int)$progress['QuestionsAnswered'],
            'accuracy' => $progress['Accuracy'] !== null ? (float)$progress['Accuracy'] : 0.0,
            'current_theta' => $currentTheta
        ]
    ];

    sendResponse($response, 200);

} catch (PDOException $e) {
    error_log("Get next question error: " . $e->getMessage());
    sendError("Failed to get next question", 500, $e->getMessage());
} catch (Exception $e) {
    error_log("Get next question error: " . $e->getMessage());
    sendError("An error occurred", 500, $e->getMessage());
}
?>