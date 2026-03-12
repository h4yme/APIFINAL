<?php
/**
 * get_portal_node_progress.php
 * Returns all 65 nodes with student progress (portal view).
 *
 * GET /api/get_portal_node_progress.php?student_id=77
 *
 * Requires: Bearer JWT (portal admin/teacher token)
 *
 * Response: JSON array of nodes:
 * [
 *   { "node_id": 1, "node_number": 1, "module_id": 1, "title": "...",
 *     "status": "completed|in_progress|locked",
 *     "lesson_completed": true, "game_completed": false, "quiz_completed": true,
 *     "quiz_score": 85.0, "completed_at": "2026-03-01 10:00:00" },
 *   ...
 * ]
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

requireAuth();

$studentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;

if (!$studentId) {
    sendError("student_id is required", 400);
}

try {
    $stmt = $conn->prepare("
        SELECT
            N.NodeID        AS node_id,
            N.NodeNumber    AS node_number,
            N.ModuleID      AS module_id,
            N.LessonTitle   AS title,
            COALESCE(SNP.LessonCompleted, 0) AS lesson_completed,
            COALESCE(SNP.GameCompleted,   0) AS game_completed,
            COALESCE(SNP.QuizCompleted,   0) AS quiz_completed,
            SNP.LatestQuizScore             AS quiz_score,
            SNP.CompletedDate               AS completed_at,
            CASE
                WHEN SNP.NodeID IS NULL                         THEN 'locked'
                WHEN COALESCE(SNP.LessonCompleted, 0) = 1      THEN 'completed'
                ELSE 'in_progress'
            END AS status
        FROM Nodes N
        LEFT JOIN StudentNodeProgress SNP
            ON N.NodeID = SNP.NodeID AND SNP.StudentID = ?
        WHERE N.IsActive = 1
        ORDER BY N.ModuleID, N.NodeNumber
    ");
    $stmt->execute([$studentId]);
    $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($nodes as &$n) {
        $n['node_id']          = (int)$n['node_id'];
        $n['node_number']      = (int)$n['node_number'];
        $n['module_id']        = (int)$n['module_id'];
        $n['lesson_completed'] = (bool)$n['lesson_completed'];
        $n['game_completed']   = (bool)$n['game_completed'];
        $n['quiz_completed']   = (bool)$n['quiz_completed'];
        $n['quiz_score']       = $n['quiz_score'] !== null ? (float)$n['quiz_score'] : null;
    }
    unset($n);

    echo json_encode($nodes);

} catch (PDOException $e) {
    error_log("get_portal_node_progress error: " . $e->getMessage());
    sendError("Failed to retrieve node progress", 500);
}