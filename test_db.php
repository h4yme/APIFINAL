<?php

/**

 * LiteRise Database Connection Test

 * GET /api/test_db.php

 *

 * Tests database connectivity and basic queries

 *

 * Response:

 * {

 *   "success": true,

 *   "message": "Database connection successful",

 *   "database": "LiteRiseDB",

 *   "server": "DESKTOP-PEM6F9E\\SQLEXPRESS",

 *   "tests": {

 *     "connection": "✅ Connected",

 *     "students_table": "✅ 3 students found",

 *     "items_table": "✅ 13 items found",

 *     "badges_table": "✅ 7 badges found"

 *   }

 * }

 */

 

require_once __DIR__ . '/src/db.php';

 

try {

    $tests = [];

 

    // Test 1: Connection (already tested by db.php)

    $tests['connection'] = '✅ Connected';

 

    // Test 2: Check Students table

    $stmt = $conn->query("SELECT COUNT(*) as count FROM Students");

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $studentCount = $result['count'];

    $tests['students_table'] = "✅ $studentCount student(s) found";

 

    // Test 3: Check Items table

    $stmt = $conn->query("SELECT COUNT(*) as count FROM Items WHERE IsActive = 1");

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $itemCount = $result['count'];

    $tests['items_table'] = "✅ $itemCount active item(s) found";

 

    // Test 4: Check Badges table

    $stmt = $conn->query("SELECT COUNT(*) as count FROM Badges");

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $badgeCount = $result['count'];

    $tests['badges_table'] = "✅ $badgeCount badge(s) found";

 

    // Test 5: Check TestSessions table

    $stmt = $conn->query("SELECT COUNT(*) as count FROM TestSessions");

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $sessionCount = $result['count'];

    $tests['sessions_table'] = "✅ $sessionCount session(s) found";

 

    // Test 6: Check Lessons table

    $stmt = $conn->query("SELECT COUNT(*) as count FROM Lessons WHERE IsActive = 1");

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $lessonCount = $result['count'];

    $tests['lessons_table'] = "✅ $lessonCount active lesson(s) found";

 

    // Test 7: Test stored procedure

    try {

        $stmt = $conn->prepare("EXEC SP_GetPreAssessmentItems");

        $stmt->execute();

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tests['stored_procedure'] = "✅ SP_GetPreAssessmentItems works (" . count($items) . " items)";

    } catch (Exception $e) {

        $tests['stored_procedure'] = "❌ Stored procedure failed: " . $e->getMessage();

    }

 

    // Get database info

    $stmt = $conn->query("SELECT DB_NAME() as dbname, @@SERVERNAME as servername, @@VERSION as version");

    $dbInfo = $stmt->fetch(PDO::FETCH_ASSOC);

 

    $response = [

        'success' => true,

        'message' => 'Database connection successful',

        'database' => $dbInfo['dbname'],

        'server' => $dbInfo['servername'],

        'version' => substr($dbInfo['version'], 0, 100), // Truncate version string

        'timestamp' => date('Y-m-d H:i:s'),

        'tests' => $tests

    ];

 

    sendResponse($response, 200);

 

} catch (PDOException $e) {

    sendError("Database test failed", 500, $e->getMessage());

} catch (Exception $e) {

    sendError("Test failed", 500, $e->getMessage());

}

?>