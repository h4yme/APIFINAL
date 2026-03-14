<?php
// ============================================================
// set_portal_account_active.php  POST  (admin only)
// id format: "admin_N" or "teacher_N"
// Body: { id, is_active: bool }
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

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$id       = trim($body['id']        ?? '');
$isActive = isset($body['is_active']) ? (bool)$body['is_active'] : null;

if (!$id || $isActive === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'id and is_active are required.']);
    exit;
}

$parts = explode('_', $id, 2);
$table = $parts[0];
$rawId = (int)($parts[1] ?? 0);

if ($rawId <= 0 || !in_array($table, ['admin', 'teacher'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid account id.']);
    exit;
}

try {
    $pdo   = getConnection();
    $flag  = $isActive ? 1 : 0;

    if ($table === 'admin') {
        $stmt = $pdo->prepare("UPDATE dbo.Admins SET IsActive = ? WHERE AdminID = ?");
    } else {
        $stmt = $pdo->prepare("UPDATE dbo.Teachers SET IsActive = ? WHERE TeacherID = ?");
    }
    $stmt->execute([$flag, $rawId]);

    $msg = $isActive ? 'Account reactivated.' : 'Account deactivated.';
    echo $stmt->rowCount()
        ? json_encode(['success' => true, 'message' => $msg])
        : json_encode(['success' => false, 'message' => 'Account not found.']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}