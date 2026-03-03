<?php
/**
 * Get Pacing Strategy API
 * GET /get_pacing_strategy.php?student_id=1&node_id=5
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

$studentId = intval($_GET['student_id'] ?? 0);
$nodeId = intval($_GET['node_id'] ?? 0);

if ($studentId === 0 || $nodeId === 0) {
    sendError("student_id and node_id are required", 400);
}

try {
    // Get student placement level
    $stmt = $conn->prepare("SELECT PlacementLevel FROM Students WHERE StudentID = ?");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    
    if (!$student) {
        sendError("Student not found", 404);
    }
    
    $placementLevel = $student['PlacementLevel'];
    
    // Get recent quiz scores
    $stmt = $conn->prepare("
        SELECT Score FROM QuizAttempts 
        WHERE StudentID = ? 
        ORDER BY CompletedDate DESC 
        LIMIT 3
    ");
    $stmt->execute([$studentId]);
    $recentScores = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Calculate average
    $avgScore = count($recentScores) > 0 ? array_sum($recentScores) / count($recentScores) : 0;
    
    // Determine pacing strategy
    $speed = 'MODERATE';
    $scaffolding = 'BALANCED';
    $examples = 'ADEQUATE';
    $durationMinutes = 12;
    $allowReview = true;
    $gameDifficulty = 'MEDIUM';
    
    if ($placementLevel == 1) {
        // Beginner
        $speed = 'SLOW';
        $scaffolding = 'HIGH';
        $examples = 'MANY';
        $durationMinutes = 15;
        $gameDifficulty = 'EASY';
    } elseif ($placementLevel == 3) {
        // Advanced
        $speed = 'FAST';
        $scaffolding = 'MINIMAL';
        $examples = 'FEW';
        $durationMinutes = 10;
        $gameDifficulty = 'HARD';
    } else {
        // Intermediate - adaptive
        if ($avgScore < 70) {
            $speed = 'SLOW';
            $scaffolding = 'HIGH';
            $examples = 'MANY';
            $durationMinutes = 14;
            $gameDifficulty = 'EASY';
        } elseif ($avgScore >= 85) {
            $speed = 'FAST';
            $scaffolding = 'LOW';
            $examples = 'ADEQUATE';
            $durationMinutes = 10;
            $gameDifficulty = 'HARD';
        }
    }
    
    sendResponse([
        'speed' => $speed,
        'scaffolding' => $scaffolding,
        'examples' => $examples,
        'duration_minutes' => $durationMinutes,
        'allow_review' => $allowReview,
        'game_difficulty' => $gameDifficulty
    ]);
    
} catch (PDOException $e) {
    error_log("Database error in get_pacing_strategy: " . $e->getMessage());
    sendError("Failed to get pacing strategy", 500, $e->getMessage());
}
?>
