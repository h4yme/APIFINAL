<?php
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

$authUser = requireAuth();

$data = getJsonInput();

// Validate required fields for ANSWER submission (not placement)
validateRequired($data, [
    'student_id',
    'item_id',
    'session_id',
    'assessment_type',
    'student_theta',
    'question_number'
]);

$studentID = (int)$data['student_id'];
$itemID = (int)$data['item_id'];
$sessionID = (int)$data['session_id'];
$assessmentType = sanitizeInput($data['assessment_type']);
$selectedAnswer = isset($data['selected_answer']) ? sanitizeInput($data['selected_answer']) : '';
$studentTheta = (float)$data['student_theta'];

// Determine correctness server-side
$isCorrect = false;
if (!empty($selectedAnswer)) {
    $stmt = $conn->prepare("SELECT CorrectAnswer FROM dbo.AssessmentItems WHERE ItemID = ?");
    $stmt->execute([$itemID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $correctAnswer = trim($row['CorrectAnswer']);
        $isCorrect = (strcasecmp(trim($selectedAnswer), $correctAnswer) === 0);
    }
}

$responseTime = isset($data['response_time']) ? (int)$data['response_time'] : null;
$questionNumber = (int)$data['question_number'];
$deviceInfo = isset($data['device_info']) ? sanitizeInput($data['device_info']) : null;
$interactionData = isset($data['interaction_data']) ? $data['interaction_data'] : null;

if ($authUser['studentID'] != $studentID) {
    sendError("Unauthorized", 403);
}

try {
    $stmt = $conn->prepare("EXEC dbo.SP_RecordStudentResponse
        @StudentID = ?, @ItemID = ?, @SessionID = ?, @AssessmentType = ?,
        @SelectedAnswer = ?, @IsCorrect = ?, @StudentThetaAtTime = ?,
        @ResponseTime = ?, @QuestionNumber = ?, @DeviceInfo = ?, @InteractionData = ?
    ");

    $stmt->execute([
        $studentID, $itemID, $sessionID, $assessmentType,
        $selectedAnswer, $isCorrect ? 1 : 0, $studentTheta,
        $responseTime, $questionNumber, $deviceInfo, $interactionData
    ]);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $responseID = $result ? (int)$result['ResponseID'] : 0;

    // Calculate new theta
    $thetaAdjustment = $isCorrect ? 0.1 : -0.1;
    $newTheta = max(-3.0, min(3.0, $studentTheta + $thetaAdjustment));

    // Get item parameters for feedback
    $itemStmt = $conn->prepare("SELECT DifficultyParam, DiscriminationParam, GuessingParam FROM dbo.AssessmentItems WHERE ItemID = ?");
    $itemStmt->execute([$itemID]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

    $expectedProbability = 0.5;
    if ($item) {
        $b = (float)$item['DifficultyParam'];
        $a = (float)$item['DiscriminationParam'];
        $c = (float)$item['GuessingParam'];
        $expectedProbability = $c + (1 - $c) / (1 + exp(-1.7 * $a * ($studentTheta - $b)));
    }

    sendResponse([
        'success' => true,
        'response_id' => $responseID,
        'is_correct' => $isCorrect,
        'feedback' => [
            'message' => $isCorrect ? "Correct! Great job! 🎉" : "Not quite. Keep trying! 💪",
            'expected_probability' => round($expectedProbability, 3),
            'new_theta_estimate' => round($newTheta, 3)
        ]
    ], 201);

} catch (Exception $e) {
    error_log("Submit answer error: " . $e->getMessage());
    sendError("Failed to submit answer", 500, $e->getMessage());
}
?>
