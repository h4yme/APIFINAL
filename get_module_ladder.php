<?php
/**
 * Get Module Ladder API
 * GET /get_module_ladder.php?student_id=1&module_id=1
 * 
 * Returns all nodes for a module with student progress
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

// Get query parameters
$studentId = intval($_GET['student_id'] ?? 0);
$moduleId = intval($_GET['module_id'] ?? 0);

// Validate required parameters
if ($studentId === 0 || $moduleId === 0) {
    sendError("student_id and module_id are required", 400);
}

try {
    // Get student info
    $stmt = $conn->prepare("SELECT CurrentNodeID, PlacementLevel FROM Students WHERE StudentID = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    
    if (!$student) {
        sendError("Student not found", 404);
    }
    
    // Get all nodes for module with progress
    $stmt = $conn->prepare("
        SELECT N.*, 
               COALESCE(SNP.LessonCompleted, 0) as LessonCompleted,
               COALESCE(SNP.GameCompleted, 0) as GameCompleted,
               COALESCE(SNP.QuizCompleted, 0) as QuizCompleted
        FROM Nodes N
        LEFT JOIN StudentNodeProgress SNP ON N.NodeID = SNP.NodeID AND SNP.StudentID = ?
        WHERE N.ModuleID = ?
        ORDER BY N.NodeNumber
    ");
    $stmt->execute([$studentId, $moduleId]);
    $nodes = $stmt->fetchAll();
    
    // Get visible supplemental nodes
    $stmt = $conn->prepare("
        SELECT * FROM SupplementalNodes 
        WHERE AfterNodeID IN (SELECT NodeID FROM Nodes WHERE ModuleID = ?)
        AND IsVisible = 1
    ");
    $stmt->execute([$moduleId]);
    $supplementalNodes = $stmt->fetchAll();
    
    sendResponse([
        'nodes' => $nodes,
        'supplementalNodes' => $supplementalNodes,
        'currentNodeId' => $student['CurrentNodeID'],
        'placementLevel' => $student['PlacementLevel']
    ]);
    
} catch (PDOException $e) {
    error_log("Database error in get_module_ladder: " . $e->getMessage());
    sendError("Failed to retrieve module ladder", 500, $e->getMessage());
}
?>
