<?php
// ============================================================
// create_portal_account.php  POST  (admin only)
// Role=Admin  → INSERT into dbo.Admins
// Role=Teacher → INSERT into dbo.Teachers
//
// Body: { name, email, password, role }
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
$name     = trim($body['name']      ?? '');
$email    = trim($body['email']     ?? '');
$password = $body['password']       ?? '';
$role     = ucfirst(strtolower(trim($body['role'] ?? '')));

if (!$name || !$email || !$password || !$role) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'name, email, password, and role are required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}
if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
    exit;
}
if (!in_array($role, ['Admin', 'Teacher'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Role must be Admin or Teacher.']);
    exit;
}

$hash = password_hash($password, PASSWORD_BCRYPT);

try {
    $pdo = getConnection();

    if ($role === 'Admin') {
        // Check duplicate email in Admins
        $chk = $pdo->prepare("SELECT COUNT(*) FROM dbo.Admins WHERE Email = ?");
        $chk->execute([$email]);
        if ((int)$chk->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'An admin with this email already exists.']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO dbo.Admins (Username, Email, PasswordHash, IsActive, CreatedDate)
            OUTPUT INSERTED.AdminID
            VALUES (?, ?, ?, 1, GETUTCDATE())
        ");
        $stmt->execute([$name, $email, $hash]);
        $newId = (int)$stmt->fetchColumn();

        echo json_encode(['success' => true, 'message' => 'Admin account created.', 'id' => "admin_$newId"]);

    } else {
        // Split name into FirstName / LastName
        $parts     = explode(' ', $name, 2);
        $firstName = $parts[0];
        $lastName  = $parts[1] ?? '';

        // Check duplicate email in Teachers
        $chk = $pdo->prepare("SELECT COUNT(*) FROM dbo.Teachers WHERE Email = ?");
        $chk->execute([$email]);
        if ((int)$chk->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'A teacher with this email already exists.']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO dbo.Teachers (FirstName, LastName, Email, Password, IsActive, DateCreated)
            OUTPUT INSERTED.TeacherID
            VALUES (?, ?, ?, ?, 1, GETUTCDATE())
        ");
        $stmt->execute([$firstName, $lastName, $email, $hash]);
        $newId = (int)$stmt->fetchColumn();

        echo json_encode(['success' => true, 'message' => 'Teacher account created.', 'id' => "teacher_$newId"]);
    }

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}