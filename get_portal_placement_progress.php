<?php
/**
 * get_portal_placement_progress.php
 * Returns pre/post assessment results for a student (portal view).
 *
 * GET /api/get_portal_placement_progress.php?student_id=77
 *
 * Requires: Bearer JWT (portal admin/teacher token)
 *
 * Response:
 * {
 *   "pre_assessment": {
 *     "date": "2025-01-15",
 *     "level": "intermediate",
 *     "theta": 0.123,
 *     "accuracy": "78.5%",
 *     "total_items": 30
 *   },
 *   "post_assessment": null | { same shape }
 * }
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

requireAuth();
// Any authenticated portal user (admin/teacher) may view any student's assessment.

$studentID = isset($_GET['student_id']) ? intval($_GET['student_id']) : null;

if (!$studentID) {
    sendError("student_id is required", 400);
}

try {
    // Call stored procedure to get student progress
    $stmt = $conn->prepare("EXEC SP_GetStudentProgress @StudentID = :studentID");
    $stmt->bindValue(':studentID', $studentID, PDO::PARAM_INT);
    $stmt->execute();

    // First result set: student info
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
        sendError("Student not found", 404);
    }

    // Second result set: placement results
    $stmt->nextRowset();
    $allResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $preResult  = null;
    $postResult = null;
    foreach ($allResults as $result) {
        if ($result['AssessmentType'] === 'PreAssessment') {
            $preResult = $result;
        } elseif ($result['AssessmentType'] === 'PostAssessment') {
            $postResult = $result;
        }
    }

    // Use the authoritative PreAssessmentTheta from Students table
    if ($preResult) {
        $stmtTheta = $conn->prepare(
            "SELECT PreAssessmentTheta FROM dbo.Students WHERE StudentID = :sid"
        );
        $stmtTheta->bindValue(':sid', $studentID, PDO::PARAM_INT);
        $stmtTheta->execute();
        $thetaRow = $stmtTheta->fetch(PDO::FETCH_ASSOC);
        if ($thetaRow && $thetaRow['PreAssessmentTheta'] !== null) {
            $preResult['FinalTheta'] = (float)$thetaRow['PreAssessmentTheta'];
        }
    }

    // Use the authoritative PostAssessmentTheta from Students table
    if ($postResult) {
        $stmtPostTheta = $conn->prepare(
            "SELECT PostAssessmentTheta FROM dbo.Students WHERE StudentID = :sid"
        );
        $stmtPostTheta->bindValue(':sid', $studentID, PDO::PARAM_INT);
        $stmtPostTheta->execute();
        $postThetaRow = $stmtPostTheta->fetch(PDO::FETCH_ASSOC);
        if ($postThetaRow && $postThetaRow['PostAssessmentTheta'] !== null) {
            $postResult['FinalTheta'] = (float)$postThetaRow['PostAssessmentTheta'];
        }
    }

    $response = [
        'pre_assessment'  => $preResult  ? formatForPortal($preResult)  : null,
        'post_assessment' => $postResult ? formatForPortal($postResult) : null,
    ];

    sendResponse($response);

} catch (PDOException $e) {
    error_log("get_portal_placement_progress error: " . $e->getMessage());
    sendError("Failed to retrieve placement progress", 500, $e->getMessage());
} catch (Exception $e) {
    error_log("get_portal_placement_progress error: " . $e->getMessage());
    sendError("An error occurred", 500, $e->getMessage());
}

function formatForPortal($result) {
    $theta    = isset($result['FinalTheta']) ? (float)$result['FinalTheta'] : null;
    $accuracy = isset($result['AccuracyPercentage']) ? round((float)$result['AccuracyPercentage'], 1) . '%' : null;

    $level = null;
    if ($theta !== null) {
        if ($theta < -0.5)     $level = 'beginner';
        elseif ($theta < 0.5)  $level = 'intermediate';
        else                   $level = 'advanced';
    }

    return [
        'date'        => $result['CompletedDate'] ?? null,
        'level'       => $level,
        'theta'       => $theta,
        'accuracy'    => $accuracy,
        'total_items' => isset($result['TotalQuestions']) ? (int)$result['TotalQuestions'] : null,
    ];
}