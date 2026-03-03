<?php
/**
 * Test script for session logging
 * Access at: http://192.168.1.145/api/test_session_log.php
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/src/db.php';

echo "Testing Session Logging...\n\n";

// Test 1: Check if stored procedure exists
echo "=== Test 1: Check Stored Procedure Exists ===\n";
try {
    $stmt = $conn->query("
        SELECT COUNT(*) as cnt
        FROM sys.objects
        WHERE type = 'P' AND name = 'SP_LogStudentSession'
    ");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result['cnt'] > 0) {
        echo "✓ SP_LogStudentSession exists\n\n";
    } else {
        echo "✗ SP_LogStudentSession NOT FOUND\n\n";
        exit;
    }
} catch (Exception $e) {
    echo "Error checking procedure: " . $e->getMessage() . "\n\n";
    exit;
}

// Test 2: Check if StudentSessionLogs table exists
echo "=== Test 2: Check Table Exists ===\n";
try {
    $stmt = $conn->query("
        SELECT COUNT(*) as cnt
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_NAME = 'StudentSessionLogs'
    ");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result['cnt'] > 0) {
        echo "✓ StudentSessionLogs table exists\n\n";
    } else {
        echo "✗ StudentSessionLogs table NOT FOUND\n\n";
        exit;
    }
} catch (Exception $e) {
    echo "Error checking table: " . $e->getMessage() . "\n\n";
    exit;
}

// Test 3: Call stored procedure directly
echo "=== Test 3: Call Stored Procedure ===\n";
try {
    $studentID = 27; // Test student
    $sessionType = 'Login';
    $sessionTag = 'test_from_php';
    $deviceInfo = 'Test Device PHP';
    $ipAddress = '127.0.0.1';
    $additionalData = '{"test": true, "from": "php"}';

    echo "Calling SP_LogStudentSession with:\n";
    echo "  StudentID: $studentID\n";
    echo "  SessionType: $sessionType\n";
    echo "  SessionTag: $sessionTag\n\n";

    // Method 1: Using EXEC with SET NOCOUNT ON
    echo "Method 1: EXEC with SET NOCOUNT ON\n";
    $stmt = $conn->prepare("
        SET NOCOUNT ON;
        EXEC dbo.SP_LogStudentSession
            @StudentID = :studentID,
            @SessionType = :sessionType,
            @SessionTag = :sessionTag,
            @DeviceInfo = :deviceInfo,
            @IPAddress = :ipAddress,
            @AdditionalData = :additionalData
    ");

    $stmt->bindValue(':studentID', $studentID, PDO::PARAM_INT);
    $stmt->bindValue(':sessionType', $sessionType, PDO::PARAM_STR);
    $stmt->bindValue(':sessionTag', $sessionTag, PDO::PARAM_STR);
    $stmt->bindValue(':deviceInfo', $deviceInfo, PDO::PARAM_STR);
    $stmt->bindValue(':ipAddress', $ipAddress, PDO::PARAM_STR);
    $stmt->bindValue(':additionalData', $additionalData, PDO::PARAM_STR);

    $executeResult = $stmt->execute();
    echo "Execute result: " . ($executeResult ? "SUCCESS" : "FAILED") . "\n";

    // Try to fetch result
    $logID = null;
    $resultCount = 0;

    do {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $resultCount++;
            echo "Row $resultCount: " . print_r($row, true) . "\n";
            if (isset($row['LogID'])) {
                $logID = $row['LogID'];
                echo "✓ Found LogID: $logID\n";
            }
        }
    } while ($stmt->nextRowset());

    if ($logID) {
        echo "\n✓ Successfully logged session with ID: $logID\n\n";
    } else {
        echo "\n⚠ Procedure executed but no LogID returned\n";
        echo "Total rows fetched: $resultCount\n\n";
    }

} catch (Exception $e) {
    echo "✗ Error calling procedure: " . $e->getMessage() . "\n";
    echo "Error code: " . $e->getCode() . "\n\n";
}

// Test 4: Verify the record was inserted
echo "=== Test 4: Verify Records in Table ===\n";
try {
    $stmt = $conn->query("
        SELECT TOP 5
            LogID, StudentID, SessionType, SessionTag, LoggedAt
        FROM dbo.StudentSessionLogs
        ORDER BY LoggedAt DESC
    ");

    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Last 5 session logs:\n";

    if (count($records) > 0) {
        foreach ($records as $record) {
            echo sprintf(
                "  ID: %d, Student: %d, Type: %s, Tag: %s, Time: %s\n",
                $record['LogID'],
                $record['StudentID'],
                $record['SessionType'],
                $record['SessionTag'],
                $record['LoggedAt']
            );
        }
        echo "\n✓ Found " . count($records) . " session records\n";
    } else {
        echo "  No records found\n";
        echo "⚠ Table is empty\n";
    }

} catch (Exception $e) {
    echo "Error querying table: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";
?>