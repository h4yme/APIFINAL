<?php
/**
 * Test script for SP_StudentLogin stored procedure
 * Access at: http://192.168.1.145/api/test_login_sp.php
 */

header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/src/db.php';

echo "Testing SP_StudentLogin Stored Procedure\n";
echo str_repeat("=", 60) . "\n\n";

// Test 1: Check if stored procedure exists
echo "Test 1: Check if SP_StudentLogin exists\n";
echo str_repeat("-", 60) . "\n";
try {
    $stmt = $conn->query("
        SELECT COUNT(*) as cnt
        FROM sys.objects
        WHERE type = 'P' AND name = 'SP_StudentLogin'
    ");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result['cnt'] > 0) {
        echo "✓ SP_StudentLogin exists\n\n";
    } else {
        echo "✗ SP_StudentLogin NOT FOUND\n";
        echo "Please run: api/db/create_student_login_sp.sql\n\n";
        exit;
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
    exit;
}

// Test 2: Check which fields the procedure returns
echo "Test 2: Check SP_StudentLogin return columns\n";
echo str_repeat("-", 60) . "\n";
try {
    $stmt = $conn->query("
        SELECT
            c.name as ColumnName,
            t.name as DataType
        FROM sys.procedures p
        INNER JOIN sys.parameters c ON p.object_id = c.object_id
        INNER JOIN sys.types t ON c.user_type_id = t.user_type_id
        WHERE p.name = 'SP_StudentLogin'
        ORDER BY c.parameter_id
    ");

    $params = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($params) > 0) {
        echo "Input Parameters:\n";
        foreach ($params as $param) {
            echo "  - {$param['ColumnName']} ({$param['DataType']})\n";
        }
    }
    echo "\n";
} catch (Exception $e) {
    echo "Warning: Could not fetch parameter info\n\n";
}

// Test 3: Get a test student email from database
echo "Test 3: Find test student\n";
echo str_repeat("-", 60) . "\n";
try {
    $stmt = $conn->query("SELECT * from Students Where Email = 'gaeyl22@gmail.com'");
    $testStudent = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($testStudent) {
        $testEmail = $testStudent['Email'];
        echo "Found test student: {$testStudent['FirstName']} {$testStudent['LastName']}\n";
        echo "Email: $testEmail\n\n";
    } else {
        echo "✗ No students found in database\n\n";
        exit;
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
    exit;
}

// Test 4: Call the stored procedure
echo "Test 4: Call SP_StudentLogin\n";
echo str_repeat("-", 60) . "\n";
echo "Calling: EXEC SP_StudentLogin @Email = '$testEmail', @Password = 'test'\n\n";

try {
    $stmt = $conn->prepare("EXEC SP_StudentLogin @Email = :email, @Password = :password");
    $stmt->bindValue(':email', $testEmail, PDO::PARAM_STR);
    $stmt->bindValue(':password', 'test', PDO::PARAM_STR);
    $stmt->execute();

    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($student) {
        echo "✓ Stored procedure executed successfully\n\n";
        echo "Returned fields:\n";
        echo str_repeat("-", 60) . "\n";

        $importantFields = [
            'StudentID',
            'FirstName',
            'LastName',
            'Email',
            'GradeLevel',
            'PreAssessmentCompleted',
            'AssessmentStatus',
            'PreAssessmentDate',
            'PreAssessmentLevel',
            'PreAssessmentTheta'
        ];

        foreach ($importantFields as $field) {
            if (array_key_exists($field, $student)) {
                $value = $student[$field];
                if ($value === null) {
                    $value = 'NULL';
                } elseif (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                echo sprintf("  %-30s: %s\n", $field, $value);
            } else {
                echo sprintf("  %-30s: ✗ MISSING\n", $field);
            }
        }

        echo "\n";

        // Check critical fields
        if (array_key_exists('PreAssessmentCompleted', $student)) {
            echo "✓ PreAssessmentCompleted field exists\n";
        } else {
            echo "✗ PreAssessmentCompleted field MISSING - This will cause login issues!\n";
        }

        if (array_key_exists('AssessmentStatus', $student)) {
            echo "✓ AssessmentStatus field exists\n";
        } else {
            echo "✗ AssessmentStatus field MISSING - This will cause login issues!\n";
        }

        echo "\nAll returned fields (" . count($student) . " total):\n";
        echo str_repeat("-", 60) . "\n";
        foreach (array_keys($student) as $key) {
            echo "  - $key\n";
        }

    } else {
        echo "✗ No data returned from stored procedure\n";
    }

} catch (Exception $e) {
    echo "✗ Error calling procedure: " . $e->getMessage() . "\n";
    echo "Error code: " . $e->getCode() . "\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Test complete!\n";
?>