<?php
/**
 * get_schools.php
 * Returns all schools.
 *
 * GET /api/get_schools.php
 *
 * Requires: Bearer JWT (portal admin/teacher token)
 *
 * Response: JSON array of school objects:
 *   school_id, school_name, address
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

requireAuth();

try {
    $stmt = $conn->query("
        SELECT
            SchoolID   AS school_id,
            SchoolName AS school_name,
            Address    AS address
        FROM Schools
        ORDER BY SchoolName
    ");
    $schools = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($schools as &$sc) {
        $sc['school_id'] = (int) $sc['school_id'];
    }
    unset($sc);

    echo json_encode($schools);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to retrieve schools.']);
    error_log("get_schools error: " . $e->getMessage());
}