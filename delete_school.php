<?php
// ============================================================
// delete_school.php  POST  (admin only)
// Soft-deletes a school (IsActive = 0).
// Body: { school_id }
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
$schoolId = (int)($body['school_id'] ?? $body['id'] ?? 0);

if ($schoolId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'school_id is required.']);
    exit;
}

try {
    $pdo = getConnection();

    // Block deletion if students are still assigned
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM dbo.Students WHERE SchoolID = ?");
    $stmt->execute([$schoolId]);
    $studentCount = (int)$stmt->fetchColumn();

    if ($studentCount > 0) {
        echo json_encode([
            'success' => false,
            'message' => "Cannot delete: $studentCount student(s) are still assigned to this school."
        ]);
        exit;
    }

    $del = $pdo->prepare("UPDATE dbo.Schools SET IsActive = 0 WHERE SchoolID = ?");
    $del->execute([$schoolId]);

    echo json_encode(['success' => true, 'message' => 'School deleted.']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}