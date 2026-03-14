<?php
// ============================================================
// update_school.php  POST  (admin only)
// Body: { school_id, school_name, district, address, city, province }
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

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$schoolId   = (int)($body['school_id']   ?? 0);
$schoolName = trim($body['school_name']  ?? '');
$district   = trim($body['district']     ?? '');
$address    = trim($body['address']      ?? '');
$city       = trim($body['city']         ?? '');
$province   = trim($body['province']     ?? '');

if ($schoolId <= 0 || !$schoolName) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'school_id and school_name are required.']);
    exit;
}

try {
    $pdo  = getConnection();
    $stmt = $pdo->prepare("
        UPDATE dbo.Schools
        SET SchoolName = ?, District = ?, Address = ?, City = ?, Province = ?
        WHERE SchoolID = ?
    ");
    $stmt->execute([$schoolName, $district, $address, $city, $province, $schoolId]);

    echo $stmt->rowCount()
        ? json_encode(['success' => true, 'message' => 'School updated.'])
        : json_encode(['success' => false, 'message' => 'School not found.']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}