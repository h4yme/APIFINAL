<?php
/**
 * LiteRise Get Placement Progress API
 * GET /api/get_placement_progress.php?student_id=1
 *
 * Returns complete placement assessment progress including:
 * - Pre and post assessment results
 * - Growth metrics
 * - Session history
 * - Comparison data
 *
 * Response:
 * {
 *   "success": true,
 *   "student": {
 *     "StudentID": 1,
 *     "FirstName": "John",
 *     "PreAssessmentCompleted": true,
 *     "PostAssessmentCompleted": false,
 *     "AssessmentStatus": "Pre-Completed"
 *   },
 *   "results": {
 *     "pre": { ... },
 *     "post": null
 *   },
 *   "comparison": {
 *     "ThetaGrowth": 0.5,
 *     "LevelGrowth": 1,
 *     "AccuracyGrowth": 15.0
 *   },
 *   "session_history": [ ... ]
 * }
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

// Require authentication
$authUser = requireAuth();

// Get student ID from query params or use authenticated user's ID
$studentID = $_GET['student_id'] ?? $authUser['studentID'];

// Validate student ID
if ($studentID == 0 || !is_numeric($studentID)) {
    sendError("Valid student_id is required", 400);
}

// Verify the authenticated user can access this student's data
// (Allow access to own data or if admin - simplified for now)
if ($authUser['studentID'] != $studentID) {
    sendError("Unauthorized: Cannot access another student's data", 403);
}

try {
    // Call stored procedure to get student progress
    $stmt = $conn->prepare("EXEC SP_GetStudentProgress @StudentID = :studentID");
    $stmt->bindValue(':studentID', $studentID, PDO::PARAM_INT);
    $stmt->execute();

    // Get student info (first result set)
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
        sendError("Student not found", 404);
    }

    // Move to next result set (placement results)
    $stmt->nextRowset();
    $allResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Separate pre and post results
    $preResult = null;
    $postResult = null;
    foreach ($allResults as $result) {
        if ($result['AssessmentType'] === 'PreAssessment') {
            $preResult = $result;
        } elseif ($result['AssessmentType'] === 'PostAssessment') {
            $postResult = $result;
        }
    }

    // Move to next result set (session history)
    $stmt->nextRowset();
    $sessionHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Move to next result set (comparison view)
    $stmt->nextRowset();
    $comparison = $stmt->fetch(PDO::FETCH_ASSOC);

    // Format response
    $response = [
        'success' => true,
        'student' => [
            'StudentID' => (int)$student['StudentID'],
            'FirstName' => $student['FirstName'],
            'LastName' => $student['LastName'],
            'Nickname' => $student['Nickname'],
            'GradeLevel' => (int)$student['GradeLevel'],
            'CurrentAbility' => (float)$student['CurrentAbility'],
            'PreAssessmentCompleted' => (bool)$student['PreAssessmentCompleted'],
            'PreAssessmentDate' => $student['PreAssessmentDate'],
            'PreAssessmentLevel' => $student['PreAssessmentLevel'] ? (int)$student['PreAssessmentLevel'] : null,
            'PostAssessmentCompleted' => (bool)$student['PostAssessmentCompleted'],
            'PostAssessmentDate' => $student['PostAssessmentDate'],
            'PostAssessmentLevel' => $student['PostAssessmentLevel'] ? (int)$student['PostAssessmentLevel'] : null,
            'AssessmentStatus' => $student['AssessmentStatus'],
            'LastLoginDate' => $student['LastLoginDate'],
            'TotalLoginCount' => (int)$student['TotalLoginCount']
        ],
        'results' => [
            'pre' => $preResult ? formatResult($preResult) : null,
            'post' => $postResult ? formatResult($postResult) : null
        ],
        'comparison' => $comparison ? [
            'PreLevel' => $comparison['PreLevel'] ? (int)$comparison['PreLevel'] : null,
            'PostLevel' => $comparison['PostLevel'] ? (int)$comparison['PostLevel'] : null,
            'LevelGrowth' => $comparison['LevelGrowth'] ? (int)$comparison['LevelGrowth'] : null,
            'PreAccuracy' => $comparison['PreAccuracy'] ? (float)$comparison['PreAccuracy'] : null,
            'PostAccuracy' => $comparison['PostAccuracy'] ? (float)$comparison['PostAccuracy'] : null,
            'AccuracyGrowth' => $comparison['AccuracyGrowth'] ? (float)$comparison['AccuracyGrowth'] : null,
            'ThetaGrowth' => $comparison['ThetaGrowth'] ? (float)$comparison['ThetaGrowth'] : null,
            'Category1Growth' => $comparison['Category1Growth'] ? (float)$comparison['Category1Growth'] : null,
            'Category2Growth' => $comparison['Category2Growth'] ? (float)$comparison['Category2Growth'] : null,
            'Category3Growth' => $comparison['Category3Growth'] ? (float)$comparison['Category3Growth'] : null,
            'Category4Growth' => $comparison['Category4Growth'] ? (float)$comparison['Category4Growth'] : null,
            'ComparisonStatus' => $comparison['ComparisonStatus']
        ] : null,
        'session_history' => array_map('formatSessionLog', $sessionHistory)
    ];

    sendResponse($response);

} catch (PDOException $e) {
    error_log("Get placement progress error: " . $e->getMessage());
    sendError("Failed to retrieve placement progress", 500, $e->getMessage());
} catch (Exception $e) {
    error_log("Get placement progress error: " . $e->getMessage());
    sendError("An error occurred", 500, $e->getMessage());
}

/**
 * Format placement result for API response
 */
function formatResult($result) {
    return [
        'ResultID' => (int)$result['ResultID'],
        'AssessmentType' => $result['AssessmentType'],
        'CompletedDate' => $result['CompletedDate'],
        'FinalTheta' => (float)$result['FinalTheta'],
        'PlacementLevel' => (int)$result['PlacementLevel'],
        'LevelName' => $result['LevelName'],
        'TotalQuestions' => (int)$result['TotalQuestions'],
        'CorrectAnswers' => (int)$result['CorrectAnswers'],
        'AccuracyPercentage' => (float)$result['AccuracyPercentage'],
        'CategoryScores' => [
            'category1' => $result['Category1Score'] ? (float)$result['Category1Score'] : null,
            'category2' => $result['Category2Score'] ? (float)$result['Category2Score'] : null,
            'category3' => $result['Category3Score'] ? (float)$result['Category3Score'] : null,
            'category4' => $result['Category4Score'] ? (float)$result['Category4Score'] : null
        ],
        'CategoryTheta' => [
            'category1' => $result['Category1Theta'] ? (float)$result['Category1Theta'] : null,
            'category2' => $result['Category2Theta'] ? (float)$result['Category2Theta'] : null,
            'category3' => $result['Category3Theta'] ? (float)$result['Category3Theta'] : null,
            'category4' => $result['Category4Theta'] ? (float)$result['Category4Theta'] : null
        ],
        'TimeSpentSeconds' => $result['TimeSpentSeconds'] ? (int)$result['TimeSpentSeconds'] : null,
        'DeviceInfo' => $result['DeviceInfo'],
        'AppVersion' => $result['AppVersion']
    ];
}

/**
 * Format session log for API response
 */
function formatSessionLog($log) {
    return [
        'LogID' => (int)$log['LogID'],
        'SessionType' => $log['SessionType'],
        'SessionTag' => $log['SessionTag'],
        'LoggedAt' => $log['LoggedAt'],
        'DeviceInfo' => $log['DeviceInfo'],
        'IPAddress' => $log['IPAddress']
    ];
}
?>