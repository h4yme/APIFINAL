<?php
// ============================================================
// get_game_summary.php  GET
// Returns aggregate game stats across ALL students.
// Used by: AnalyticsController.GetGameSummaryAsync()
//
// Response:
// {
//   total_plays     : int,
//   avg_accuracy    : float,
//   total_xp        : int,
//   most_played     : string,
//   by_game_type    : [{ game_type, play_count, avg_accuracy, total_xp }],
//   xp_over_time    : [{ week_label, xp_total }]   -- last 8 weeks
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

    // ── Totals ────────────────────────────────────────────────
    $totals = $pdo->query("
        SELECT
            COUNT(*)                     AS total_plays,
            ROUND(AVG(CAST(Accuracy AS FLOAT)), 2) AS avg_accuracy,
            SUM(XPEarned)                AS total_xp
        FROM dbo.GameResults
    ")->fetch(PDO::FETCH_ASSOC);

    // ── Most-played game type ─────────────────────────────────
    $mostPlayed = $pdo->query("
        SELECT TOP 1 GameType
        FROM   dbo.GameResults
        GROUP  BY GameType
        ORDER  BY COUNT(*) DESC
    ")->fetchColumn();

    // ── Per-game-type breakdown ───────────────────────────────
    $byType = $pdo->query("
        SELECT
            GameType                                   AS game_type,
            COUNT(*)                                   AS play_count,
            ROUND(AVG(CAST(Accuracy AS FLOAT)), 2)     AS avg_accuracy,
            SUM(XPEarned)                              AS total_xp
        FROM  dbo.GameResults
        GROUP BY GameType
        ORDER BY play_count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($byType as &$row) {
        $row['play_count']    = (int)$row['play_count'];
        $row['avg_accuracy']  = (float)$row['avg_accuracy'];
        $row['total_xp']      = (int)$row['total_xp'];
    }
    unset($row);

    // ── XP over time — last 8 ISO weeks ───────────────────────
    $xpOverTime = $pdo->query("
        SELECT
            CONCAT('W', DATEPART(iso_week, PlayedAt)) AS week_label,
            SUM(XPEarned)                             AS xp_total
        FROM  dbo.GameResults
        WHERE PlayedAt >= DATEADD(week, -8, GETDATE())
        GROUP BY DATEPART(iso_week, PlayedAt),
                 DATEPART(year,     PlayedAt)
        ORDER BY DATEPART(year,     PlayedAt),
                 DATEPART(iso_week, PlayedAt)
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($xpOverTime as &$row) {
        $row['xp_total'] = (int)$row['xp_total'];
    }
    unset($row);

    echo json_encode([
        'total_plays'  => (int)($totals['total_plays']  ?? 0),
        'avg_accuracy' => (float)($totals['avg_accuracy'] ?? 0),
        'total_xp'     => (int)($totals['total_xp']     ?? 0),
        'most_played'  => $mostPlayed ?: null,
        'by_game_type' => $byType,
        'xp_over_time' => $xpOverTime,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}