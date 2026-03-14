<?php
// ============================================================
// get_assessment_summary.php  GET
// Returns aggregate pre/post assessment (theta) stats.
// Used by: AnalyticsController.GetAssessmentSummaryAsync()
//
// Response:
// {
//   pre_assessment_taken  : int,
//   post_assessment_taken : int,
//   avg_pre_theta         : float | null,
//   avg_post_theta        : float | null,
//   avg_growth            : float | null,
//   level_distribution    : { beginner, intermediate, advanced, unplaced },
//   school_comparison     : [{ school_name, avg_pre, avg_post, student_count }]
// }
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$auth = verifyToken();
if (!$auth) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $pdo = getConnection();

    // ── Theta averages & counts ───────────────────────────────
    $row = $pdo->query("
        SELECT
            COUNT(CASE WHEN PreAssessmentCompleted = 1 THEN 1 END)  AS pre_taken,
            COUNT(CASE WHEN PostAssessmentCompleted = 1 THEN 1 END) AS post_taken,
            ROUND(AVG(CASE WHEN InitialAbility IS NOT NULL
                           THEN InitialAbility END), 4)             AS avg_pre,
            ROUND(AVG(CASE WHEN CurrentAbility IS NOT NULL
                           THEN CurrentAbility END), 4)             AS avg_post,
            ROUND(AVG(CASE WHEN InitialAbility IS NOT NULL
                                AND CurrentAbility IS NOT NULL
                           THEN CurrentAbility - InitialAbility END), 4) AS avg_growth
        FROM dbo.Students
    ")->fetch(PDO::FETCH_ASSOC);

    // ── Level distribution ────────────────────────────────────
    $levels = $pdo->query("
        SELECT
            SUM(CASE WHEN PreAssessmentLevel = 1 THEN 1 ELSE 0 END) AS beginner,
            SUM(CASE WHEN PreAssessmentLevel = 2 THEN 1 ELSE 0 END) AS intermediate,
            SUM(CASE WHEN PreAssessmentLevel = 3 THEN 1 ELSE 0 END) AS advanced,
            SUM(CASE WHEN PreAssessmentLevel IS NULL THEN 1 ELSE 0 END) AS unplaced
        FROM dbo.Students
    ")->fetch(PDO::FETCH_ASSOC);

    // ── Per-school comparison ─────────────────────────────────
    $schools = $pdo->query("
        SELECT
            ISNULL(sc.SchoolName, 'Unknown')                        AS school_name,
            ROUND(AVG(CASE WHEN s.InitialAbility IS NOT NULL
                           THEN s.InitialAbility END), 3)           AS avg_pre,
            ROUND(AVG(CASE WHEN s.CurrentAbility IS NOT NULL
                           THEN s.CurrentAbility END), 3)           AS avg_post,
            COUNT(*)                                                AS student_count
        FROM  dbo.Students s
        LEFT  JOIN dbo.Schools sc ON sc.SchoolID = s.SchoolID
        GROUP BY sc.SchoolName
        ORDER BY student_count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($schools as &$sc) {
        $sc['avg_pre']        = $sc['avg_pre']  !== null ? (float)$sc['avg_pre']  : null;
        $sc['avg_post']       = $sc['avg_post'] !== null ? (float)$sc['avg_post'] : null;
        $sc['student_count']  = (int)$sc['student_count'];
    }
    unset($sc);

    echo json_encode([
        'pre_assessment_taken'  => (int)($row['pre_taken']    ?? 0),
        'post_assessment_taken' => (int)($row['post_taken']   ?? 0),
        'avg_pre_theta'         => $row['avg_pre']    !== null ? (float)$row['avg_pre']    : null,
        'avg_post_theta'        => $row['avg_post']   !== null ? (float)$row['avg_post']   : null,
        'avg_growth'            => $row['avg_growth'] !== null ? (float)$row['avg_growth'] : null,
        'level_distribution'    => [
            'beginner'     => (int)($levels['beginner']     ?? 0),
            'intermediate' => (int)($levels['intermediate'] ?? 0),
            'advanced'     => (int)($levels['advanced']     ?? 0),
            'unplaced'     => (int)($levels['unplaced']     ?? 0),
        ],
        'school_comparison' => $schools,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}