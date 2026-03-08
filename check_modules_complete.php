<?php
/**
 * Check Modules Complete API
 *
 * Endpoint: GET /api/check_modules_complete.php?student_id=1
 * Description: Checks if a student has completed all 65 nodes (60 CORE_LESSON + 5 FINAL_ASSESSMENT)
 *              and whether a post-assessment is pending.
 *
 * Response:
 * {
 *   "success": true,
 *   "all_complete": true,
 *   "completed_count": 65,
 *   "total_count": 65,
 *   "post_assessment_done": false,
 *   "should_trigger_post_assessment": true
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/src/db.php';

try {
    $studentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;

    if ($studentId === 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required parameter: student_id'
        ]);
        exit;
    }

    // Count total nodes (all 65: CORE_LESSON + FINAL_ASSESSMENT)
    $stmt = $conn->prepare("SELECT COUNT(*) FROM Nodes WHERE IsActive = 1");
    $stmt->execute();
    $totalCount = (int)$stmt->fetchColumn();

    // Count completed nodes for this student (QuizCompleted = 1)
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM StudentNodeProgress snp
        INNER JOIN Nodes n ON snp.NodeID = n.NodeID
        WHERE snp.StudentID = ?
        AND snp.QuizCompleted = 1
        AND n.IsActive = 1
    ");
    $stmt->execute([$studentId]);
    $completedCount = (int)$stmt->fetchColumn();

    $allComplete = ($completedCount >= $totalCount && $totalCount > 0);

    // Check if post-assessment already done
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM PlacementResults
        WHERE StudentID = ? AND AssessmentType = 'POST'
    ");
    $stmt->execute([$studentId]);
    $postAssessmentDone = (int)$stmt->fetchColumn() > 0;

    $shouldTrigger = $allComplete && !$postAssessmentDone;

    echo json_encode([
        'success' => true,
        'all_complete' => $allComplete,
        'completed_count' => $completedCount,
        'total_count' => $totalCount,
        'post_assessment_done' => $postAssessmentDone,
        'should_trigger_post_assessment' => $shouldTrigger
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error',
        'error' => ((($_ENV['DEBUG_MODE'] ?? getenv('DEBUG_MODE')) ?? 'false') === 'true') ? $e->getMessage() : null
    ]);
}
?>