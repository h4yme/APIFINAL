<?php
/**
 * Get Badges
 *
 * GET /api/get_badges.php?student_id=1
 *
 * Returns all badges in the system with earned/unearned status for the student.
 * Used by the dashboard badge collection display.
 *
 * Response:
 * {
 *   "success":       true,
 *   "earned_count":  3,
 *   "total_count":   15,
 *   "badges": [
 *     {
 *       "badge_id":          1,
 *       "badge_name":        "Phonics Pioneer",
 *       "badge_description": "Completed Module 1: Phonics and Word Study",
 *       "badge_icon_url":    "badge_module1",
 *       "xp_reward":         100,
 *       "badge_category":    "module",
 *       "earned":            true,
 *       "date_earned":       "2026-03-01 10:00:00"
 *     },
 *     ...
 *   ]
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/src/db.php';

$studentId = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;

if ($studentId === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'student_id is required']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT
            b.BadgeID          AS badge_id,
            b.BadgeName        AS badge_name,
            b.BadgeDescription AS badge_description,
            b.BadgeIconURL     AS badge_icon_url,
            b.XPReward         AS xp_reward,
            b.BadgeCategory    AS badge_category,
            CASE WHEN sb.BadgeID IS NOT NULL THEN 1 ELSE 0 END AS earned,
            CONVERT(VARCHAR(19), sb.DateEarned, 120)            AS date_earned
        FROM Badges b
        LEFT JOIN StudentBadges sb
            ON sb.BadgeID = b.BadgeID AND sb.StudentID = ?
        ORDER BY
            b.BadgeCategory ASC,
            b.BadgeID ASC
    ");
    $stmt->execute([$studentId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast types
    $badges = array_map(function ($row) {
        $row['badge_id']   = (int)$row['badge_id'];
        $row['xp_reward']  = (int)$row['xp_reward'];
        $row['earned']     = (bool)$row['earned'];
        return $row;
    }, $rows);

    $earnedCount = count(array_filter($badges, fn($b) => $b['earned']));

    echo json_encode([
        'success'      => true,
        'earned_count' => $earnedCount,
        'total_count'  => count($badges),
        'badges'       => $badges
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error',
        'error'   => ((($_ENV['DEBUG_MODE'] ?? getenv('DEBUG_MODE')) ?? 'false') === 'true') ? $e->getMessage() : null
    ]);
}