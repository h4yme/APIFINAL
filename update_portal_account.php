<?php
// ============================================================
// update_portal_account.php  POST  (admin only)
// id format: "admin_N" or "teacher_N"
// Body: { id, name, email, password?, role }
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
$id       = trim($body['id']       ?? '');   // "admin_1" or "teacher_1"
$name     = trim($body['name']     ?? '');
$email    = trim($body['email']    ?? '');
$password = $body['password']      ?? '';
$role     = ucfirst(strtolower(trim($body['role'] ?? '')));

if (!$id || !$name || !$email || !$role) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'id, name, email, and role are required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}
if (!empty($password) && strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
    exit;
}

// Parse prefixed id
$parts  = explode('_', $id, 2);
$table  = $parts[0];   // "admin" or "teacher"
$rawId  = (int)($parts[1] ?? 0);

if ($rawId <= 0 || !in_array($table, ['admin', 'teacher'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid account id.']);
    exit;
}

try {
    $pdo  = getConnection();
    $hash = !empty($password) ? password_hash($password, PASSWORD_BCRYPT) : null;

    if ($table === 'admin') {
        // Check email uniqueness (exclude self)
        $chk = $pdo->prepare("SELECT COUNT(*) FROM dbo.Admins WHERE Email = ? AND AdminID <> ?");
        $chk->execute([$email, $rawId]);
        if ((int)$chk->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Another admin with this email already exists.']);
            exit;
        }

        if ($hash) {
            $stmt = $pdo->prepare("UPDATE dbo.Admins SET Username=?, Email=?, PasswordHash=? WHERE AdminID=?");
            $stmt->execute([$name, $email, $hash, $rawId]);
        } else {
            $stmt = $pdo->prepare("UPDATE dbo.Admins SET Username=?, Email=? WHERE AdminID=?");
            $stmt->execute([$name, $email, $rawId]);
        }

    } else {
        // Split FirstName / LastName
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0];
        $lastName  = $nameParts[1] ?? '';

        // Check email uniqueness (exclude self)
        $chk = $pdo->prepare("SELECT COUNT(*) FROM dbo.Teachers WHERE Email = ? AND TeacherID <> ?");
        $chk->execute([$email, $rawId]);
        if ((int)$chk->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Another teacher with this email already exists.']);
            exit;
        }

        if ($hash) {
            $stmt = $pdo->prepare("UPDATE dbo.Teachers SET FirstName=?, LastName=?, Email=?, Password=? WHERE TeacherID=?");
            $stmt->execute([$firstName, $lastName, $email, $hash, $rawId]);
        } else {
            $stmt = $pdo->prepare("UPDATE dbo.Teachers SET FirstName=?, LastName=?, Email=? WHERE TeacherID=?");
            $stmt->execute([$firstName, $lastName, $email, $rawId]);
        }
    }

    echo $stmt->rowCount()
        ? json_encode(['success' => true, 'message' => 'Account updated successfully.'])
        : json_encode(['success' => false, 'message' => 'Account not found.']);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}