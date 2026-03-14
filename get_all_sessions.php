<?php
// ============================================================
// get_all_sessions.php  GET
// Returns all student login sessions for the system usage report.
// Used by: ReportsController (system usage report)
//
// Optional query params:
//   ?start_date=YYYY-MM-DD   (default: 30 days ago)
//   ?end_date=YYYY-MM-DD     (default: today)
//   ?school_id=int
//
// Response:
// {
//   total_sessions   : int,
//   unique_students  : int,
//   avg_duration_min : float,
//   daily_logins     : [{ date, login_count, unique_students }],
//   sessions         : [{ student_id, student_name, grade, school_name,
//                         login_at, logout_at, duration_min }]
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

// Default date range: last 30 days
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate   = $_GET['end_date']   ?? date('Y-m-d');
$schoolId  = isset($_GET['school_id']) ? (int)$_GET['school_id'] : null;

// Basic date validation
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   $endDate   = date('Y-m-d');

try {
    $pdo = getConnection();

    $schoolFilter = $schoolId ? 'AND s.SchoolID = ?' : '';
    $params       = [$startDate, $endDate];
    if ($schoolId) $params[] = $schoolId;

    // ── Summary totals ────────────────────────────────────────
    $summary = $pdo->prepare("
        SELECT
            COUNT(*)                               AS total_sessions,
            COUNT(DISTINCT ts.StudentID)           AS unique_students,
            ROUND(AVG(
                CAST(DATEDIFF(second, ts.LoginAt, ISNULL(ts.LogoutAt, GETDATE())) AS FLOAT) / 60
            ), 2)                                  AS avg_duration_min
        FROM  dbo.TestSessions ts
        JOIN  dbo.Students     s  ON s.StudentID = ts.StudentID
        WHERE CAST(ts.LoginAt AS DATE) BETWEEN ? AND ?
        {$schoolFilter}
    ");
    $summary->execute($params);
    $totals = $summary->fetch(PDO::FETCH_ASSOC);

    // ── Daily login counts ────────────────────────────────────
    $dailyStmt = $pdo->prepare("
        SELECT
            CONVERT(varchar(10), ts.LoginAt, 23)   AS date,
            COUNT(*)                               AS login_count,
            COUNT(DISTINCT ts.StudentID)           AS unique_students
        FROM  dbo.TestSessions ts
        JOIN  dbo.Students     s ON s.StudentID = ts.StudentID
        WHERE CAST(ts.LoginAt AS DATE) BETWEEN ? AND ?
        {$schoolFilter}
        GROUP BY CONVERT(varchar(10), ts.LoginAt, 23)
        ORDER BY date
    ");
    $dailyStmt->execute($params);
    $daily = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($daily as &$d) {
        $d['login_count']      = (int)$d['login_count'];
        $d['unique_students']  = (int)$d['unique_students'];
    }
    unset($d);

    // ── Session list (latest 500) ─────────────────────────────
    $sessStmt = $pdo->prepare("
        SELECT TOP 500
            ts.SessionID                                              AS session_id,
            ts.StudentID                                              AS student_id,
            CONCAT(s.FirstName, ' ', s.LastName)                     AS student_name,
            s.GradeLevel                                             AS grade,
            ISNULL(sc.SchoolName, 'Unknown')                         AS school_name,
            CONVERT(varchar(19), ts.LoginAt,  120)                   AS login_at,
            CONVERT(varchar(19), ts.LogoutAt, 120)                   AS logout_at,
            ROUND(
                CAST(DATEDIFF(second, ts.LoginAt,
                     ISNULL(ts.LogoutAt, GETDATE())) AS FLOAT) / 60, 2
            )                                                        AS duration_min
        FROM  dbo.TestSessions ts
        JOIN  dbo.Students     s  ON s.StudentID  = ts.StudentID
        LEFT  JOIN dbo.Schools sc ON sc.SchoolID  = s.SchoolID
        WHERE CAST(ts.LoginAt AS DATE) BETWEEN ? AND ?
        {$schoolFilter}
        ORDER BY ts.LoginAt DESC
    ");
    $sessStmt->execute($params);
    $sessions = $sessStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($sessions as &$sess) {
        $sess['student_id']   = (int)$sess['student_id'];
        $sess['grade']        = (int)$sess['grade'];
        $sess['duration_min'] = (float)$sess['duration_min'];
    }
    unset($sess);

    echo json_encode([
        'total_sessions'   => (int)($totals['total_sessions']   ?? 0),
        'unique_students'  => (int)($totals['unique_students']  ?? 0),
        'avg_duration_min' => (float)($totals['avg_duration_min'] ?? 0),
        'daily_logins'     => $daily,
        'sessions'         => $sessions,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}