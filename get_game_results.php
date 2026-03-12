<?php
/**
 * get_game_results.php
 * Returns game result history for a student (portal view).
 *
 * GET /api/get_game_results.php?student_id=77
 *
 * Requires: Bearer JWT (portal admin/teacher token)
 *
 * Response: JSON array of game results:
 * [
 *   { "game_type": "SentenceScramble", "score": 850, "accuracy": "85.0%",
 *     "xp_earned": 100, "played_at": "2026-03-01 10:00:00" },
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
            GameType             AS game_type,
            Score                AS score,
            AccuracyPercentage   AS accuracy,
            XPEarned             AS xp_earned,
            DatePlayed           AS played_at
        FROM GameResults
        WHERE StudentID = ?
        ORDER BY DatePlayed DESC
    ");
    $stmt->execute([$studentId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($results as &$r) {
        $r['score']     = $r['score']     !== null ? (int)$r['score']                            : null;
        $r['accuracy']  = $r['accuracy']  !== null ? round((float)$r['accuracy'], 1) . '%'       : null;
        $r['xp_earned'] = $r['xp_earned'] !== null ? (int)$r['xp_earned']                        : 0;
    }
    unset($r);

    echo json_encode($results);

} catch (PDOException $e) {
    error_log("get_game_results error: " . $e->getMessage());
    sendError("Failed to retrieve game results", 500);
}