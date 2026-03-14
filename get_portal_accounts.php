<?php
// ============================================================
// get_portal_accounts.php  GET  (admin only)
// UNIONs dbo.Admins and dbo.Teachers into one list.
//
// Response: [
//   { id, name, email, role, isActive }
// ]
// ============================================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$auth = verifyToken();
if (!$auth || strtolower($auth['role'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $pdo = getConnection();

    // ── Admins ────────────────────────────────────────────────────────────
    $stmtA = $pdo->query("
        SELECT
            'admin_' + CAST(AdminID AS VARCHAR)              AS id,
            AdminID                                          AS raw_id,
            ISNULL(Username, Email)                          AS name,
            Email                                            AS email,
            'Admin'                                          AS role,
            CAST(IsActive AS BIT)                            AS isActive
        FROM dbo.Admins
    ");
    if ($stmtA === false) {
        throw new \RuntimeException('Admins query failed: ' . implode(', ', $pdo->errorInfo()));
    }
    $admins = $stmtA->fetchAll(PDO::FETCH_ASSOC);

    // ── Teachers ──────────────────────────────────────────────────────────
    $stmtT = $pdo->query("
        SELECT
            'teacher_' + CAST(TeacherID AS VARCHAR)          AS id,
            TeacherID                                         AS raw_id,
            RTRIM(ISNULL(FirstName,'') + ' ' + ISNULL(LastName,'')) AS name,
            Email                                             AS email,
            'Teacher'                                         AS role,
            CAST(IsActive AS BIT)                             AS isActive
        FROM dbo.Teachers
    ");
    if ($stmtT === false) {
        throw new \RuntimeException('Teachers query failed: ' . implode(', ', $pdo->errorInfo()));
    }
    $teachers = $stmtT->fetchAll(PDO::FETCH_ASSOC);

    $all = array_merge($admins, $teachers);

    foreach ($all as &$row) {
        $row['raw_id']   = (int)$row['raw_id'];
        $row['isActive'] = (bool)$row['isActive'];
    }
    unset($row);

    usort($all, function ($a, $b) { return strcmp($a['name'], $b['name']); });

    echo json_encode($all, JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}