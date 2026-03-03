<?php
/**
 * Test what the login API actually returns
 * Access at: http://192.168.1.145/api/test_login_response.php
 */

header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

echo "Testing Login API Response\n";
echo str_repeat("=", 60) . "\n\n";

$email = 'gaeyl22@gmail.com';
$password = '123456';

echo "Login Request:\n";
echo "  Email: $email\n";
echo "  Password: $password\n\n";

try {
    // Call the stored procedure (same as login.php)
    $stmt = $conn->prepare("EXEC SP_StudentLogin @Email = :email, @Password = :password");
    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->bindValue(':password', $password, PDO::PARAM_STR);
    $stmt->execute();

    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        echo "✗ No student found\n";
        exit;
    }

    // Remove password from response
    unset($student['Password']);

    // Generate JWT token
    $token = generateJWT($student['StudentID'], $email);

    // Format response (EXACT SAME as login.php)
    $response = [
        'success' => true,
        'StudentID' => (int)$student['StudentID'],
        'FullName' => $student['FirstName'] . ' ' . $student['LastName'],
        'FirstName' => $student['FirstName'],
        'LastName' => $student['LastName'],
        'email' => $student['Email'],
        'GradeLevel' => (int)$student['GradeLevel'],
        'Section' => $student['Section'] ?? '',
        'CurrentAbility' => (float)$student['CurrentAbility'],
        'AbilityScore' => (float)$student['CurrentAbility'],
        'TotalXP' => (int)$student['TotalXP'],
        'XP' => (int)$student['TotalXP'],
        'CurrentStreak' => (int)$student['CurrentStreak'],
        'LongestStreak' => (int)$student['LongestStreak'],
        'LastLogin' => $student['LastLogin'] ?? null,
        'PreAssessmentCompleted' => isset($student['PreAssessmentCompleted']) ? (bool)$student['PreAssessmentCompleted'] : false,
        'AssessmentStatus' => $student['AssessmentStatus'] ?? 'Not Started',
        'token' => $token
    ];

    echo "API Response (what Android app receives):\n";
    echo str_repeat("-", 60) . "\n";
    echo json_encode($response, JSON_PRETTY_PRINT) . "\n\n";

    echo "Key Assessment Fields:\n";
    echo str_repeat("-", 60) . "\n";
    echo "  PreAssessmentCompleted: ";
    var_dump($response['PreAssessmentCompleted']);
    echo "\n";
    echo "  AssessmentStatus: " . $response['AssessmentStatus'] . "\n";
    echo "\n";

    if ($response['PreAssessmentCompleted'] === true) {
        echo "✓ PreAssessmentCompleted is TRUE - Should go to Dashboard\n";
    } else {
        echo "✗ PreAssessmentCompleted is FALSE - Will go to onboarding\n";
    }

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
?>