<?php
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

$authUser = requireAuth();

$data = getJsonInput();

validateRequired($data, [
    'student_id',
    'session_id',
    'assessment_type',
    'final_theta',
    'placement_level',
    'total_questions',
    'correct_answers',
    'accuracy_percentage'
]);

$studentID = (int)$data['student_id'];
$sessionID = (int)$data['session_id'];
$assessmentType = sanitizeInput($data['assessment_type']);
$finalTheta = (float)$data['final_theta'];
$placementLevel = (int)$data['placement_level'];
$levelName = isset($data['level_name']) ? sanitizeInput($data['level_name']) : '';
$totalQuestions = (int)$data['total_questions'];
$correctAnswers = (int)$data['correct_answers'];
$accuracyPercentage = (float)$data['accuracy_percentage'];

if ($authUser['studentID'] != $studentID) {
    sendError("Unauthorized", 403);
}

$categoryScores = $data['category_scores'] ?? [];
$cat1 = isset($categoryScores['category1']) ? (int)$categoryScores['category1'] : null;
$cat2 = isset($categoryScores['category2']) ? (int)$categoryScores['category2'] : null;
$cat3 = isset($categoryScores['category3']) ? (int)$categoryScores['category3'] : null;
$cat4 = isset($categoryScores['category4']) ? (int)$categoryScores['category4'] : null;
$cat5 = isset($categoryScores['category5']) ? (int)$categoryScores['category5'] : null;

$categoryTheta = $data['category_theta'] ?? [];
$theta1 = isset($categoryTheta['category1']) ? (float)$categoryTheta['category1'] : null;
$theta2 = isset($categoryTheta['category2']) ? (float)$categoryTheta['category2'] : null;
$theta3 = isset($categoryTheta['category3']) ? (float)$categoryTheta['category3'] : null;
$theta4 = isset($categoryTheta['category4']) ? (float)$categoryTheta['category4'] : null;
$theta5 = isset($categoryTheta['category5']) ? (float)$categoryTheta['category5'] : null;

$timeSpent = isset($data['time_spent_seconds']) ? (int)$data['time_spent_seconds'] : null;
$deviceInfo = isset($data['device_info']) ? sanitizeInput($data['device_info']) : null;
$appVersion = isset($data['app_version']) ? sanitizeInput($data['app_version']) : null;

try {
    $conn->beginTransaction();

    $sql = "INSERT INTO dbo.PlacementResults (
        StudentID, SessionID, AssessmentType, FinalTheta, PlacementLevel, LevelName,
        TotalQuestions, CorrectAnswers, AccuracyPercentage,
        Category1Score, Category2Score, Category3Score, Category4Score, Category5Score,
        Category1Theta, Category2Theta, Category3Theta, Category4Theta, Category5Theta,
        TimeSpentSeconds, DeviceInfo, AppVersion, CompletedDate
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $studentID, $sessionID, $assessmentType, $finalTheta, $placementLevel, $levelName,
        $totalQuestions, $correctAnswers, $accuracyPercentage,
        $cat1, $cat2, $cat3, $cat4, $cat5,
        $theta1, $theta2, $theta3, $theta4, $theta5,
        $timeSpent, $deviceInfo, $appVersion
    ]);

    if ($assessmentType === 'PreAssessment') {
        $sql = "UPDATE dbo.Students 
            SET Cat1_PhonicsWordStudy = ?, Cat2_VocabularyWordKnowledge = ?,
                Cat3_GrammarAwareness = ?, Cat4_ComprehendingText = ?, Cat5_CreatingComposing = ?,
                Cat1_PhonicsWordStudy_Theta = ?, Cat2_VocabularyWordKnowledge_Theta = ?,
                Cat3_GrammarAwareness_Theta = ?, Cat4_ComprehendingText_Theta = ?, Cat5_CreatingComposing_Theta = ?,
                PreAssessmentLevel = ?, PreAssessmentTheta = ?, PreAssessmentDate = GETDATE(), PreAssessmentCompleted = 1
            WHERE StudentID = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $cat1, $cat2, $cat3, $cat4, $cat5,
            $theta1, $theta2, $theta3, $theta4, $theta5,
            $placementLevel, $finalTheta, $studentID
        ]);
    } else {
        $sql = "UPDATE dbo.Students 
            SET PostAssessmentLevel = ?, PostAssessmentTheta = ?, PostAssessmentDate = GETDATE(), PostAssessmentCompleted = 1
            WHERE StudentID = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$placementLevel, $finalTheta, $studentID]);
    }

    $conn->commit();

    sendResponse([
        'success' => true,
        'message' => 'Placement result saved successfully'
    ], 201);

} catch (Exception $e) {
    $conn->rollBack();
    error_log("Save placement error: " . $e->getMessage());
    sendError("Failed to save placement result", 500, $e->getMessage());
}
?>
