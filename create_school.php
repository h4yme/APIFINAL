<?php
// ============================================================
// create_school.php  POST  (admin only)
// Body: { school_name, district, address, city, province }
// Response: { success, school_id, school_name, ... }
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
$schoolName = trim($body['school_name'] ?? '');
$district   = trim($body['district']    ?? '');
$address    = trim($body['address']     ?? '');
$city       = trim($body['city']        ?? '');
$province   = trim($body['province']    ?? '');

if (!$schoolName) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'school_name is required.']);
    exit;
}

try {
    $pdo  = getConnection();
    $stmt = $pdo->prepare("
        INSERT INTO dbo.Schools (SchoolName, District, Address, City, Province, IsActive, DateCreated)
        OUTPUT INSERTED.SchoolID
        VALUES (?, ?, ?, ?, ?, 1, GETUTCDATE())
    ");
    $stmt->execute([$schoolName, $district, $address, $city, $province]);
    $newId = (int)$stmt->fetchColumn();

    echo json_encode([
        'success'     => true,
        'school_id'   => $newId,
        'school_name' => $schoolName,
        'district'    => $district,
        'city'        => $city,
        'province'    => $province,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}