<?php
/**
 * get_portal_module_ladder.php
 * Returns all modules with their nodes and student progress (portal view).
 *
 * GET /api/get_portal_module_ladder.php?student_id=77
 *
 * Requires: Bearer JWT (portal admin/teacher token)
 *
 * Response: JSON array of modules:
 * [
 *   {
 *     "module_id": 1,
 *     "module_title": "Module 1",
 *     "nodes": [
 *       { "node_id": 1, "node_number": 1, "title": "...",
 *         "status": "completed|in_progress|locked",
 *         "quiz_score": 85.0, "quiz_path": null }
 *     ]
 *   },
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
            M.ModuleID          AS module_id,
            M.ModuleName        AS module_title,
            N.NodeID            AS node_id,
            N.NodeNumber        AS node_number,
            N.LessonTitle       AS title,
            SNP.LatestQuizScore AS quiz_score,
            CASE
                WHEN SNP.NodeID IS NULL                    THEN 'locked'
                WHEN COALESCE(SNP.LessonCompleted, 0) = 1 THEN 'completed'
                ELSE 'in_progress'
            END AS status
        FROM Modules M
        JOIN  Nodes N ON N.ModuleID = M.ModuleID AND N.IsActive = 1
        LEFT JOIN StudentNodeProgress SNP
            ON N.NodeID = SNP.NodeID AND SNP.StudentID = ?
        ORDER BY M.ModuleID, N.NodeNumber
    ");
    $stmt->execute([$studentId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $modules = [];
    foreach ($rows as $row) {
        $mid = (int)$row['module_id'];
        if (!isset($modules[$mid])) {
            $modules[$mid] = [
                'module_id'    => $mid,
                'module_title' => $row['module_title'],
                'nodes'        => []
            ];
        }
        $modules[$mid]['nodes'][] = [
            'node_id'     => (int)$row['node_id'],
            'node_number' => (int)$row['node_number'],
            'title'       => $row['title'],
            'status'      => $row['status'],
            'quiz_score'  => $row['quiz_score'] !== null ? (float)$row['quiz_score'] : null,
            'quiz_path'   => null
        ];
    }

    sendResponse(array_values($modules));

} catch (PDOException $e) {
    error_log("get_portal_module_ladder error: " . $e->getMessage());
    sendError("Failed to retrieve module ladder", 500, $e->getMessage());
}