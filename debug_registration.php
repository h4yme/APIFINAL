<?php
/**
 * Registration Debug Script
 * Run this to see detailed error information
 */

// Enable error display
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>LiteRise Registration Debug</h1>";

// Test 1: Database connection
echo "<h2>1. Database Connection Test</h2>";
try {
    require_once __DIR__ . '/src/db.php';
    echo "✅ Database connected successfully<br>";
} catch (Exception $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "<br>";
}

// Test 2: Email functions
echo "<h2>2. Email Functions Test</h2>";
try {
    require_once __DIR__ . '/src/email.php';
    echo "✅ Email functions loaded successfully<br>";

    // Check if PHPMailer is available
    global $phpmailerAvailable;
    if ($phpmailerAvailable) {
        echo "✅ PHPMailer is available<br>";
    } else {
        echo "⚠️ PHPMailer not available (will use basic PHP mail)<br>";
    }
} catch (Exception $e) {
    echo "❌ Email functions failed: " . $e->getMessage() . "<br>";
}

// Test 3: Auth functions
echo "<h2>3. Auth Functions Test</h2>";
try {
    require_once __DIR__ . '/src/auth.php';
    echo "✅ Auth functions loaded successfully<br>";

    // Test password hashing
    $testHash = hashPassword('test123');
    echo "✅ Password hashing works<br>";

    // Test JWT generation
    $testToken = generateJWT(1, 'test@example.com');
    echo "✅ JWT generation works<br>";
} catch (Exception $e) {
    echo "❌ Auth functions failed: " . $e->getMessage() . "<br>";
}

// Test 4: Stored Procedure Test
echo "<h2>4. Stored Procedure Test</h2>";
try {
    $testData = [
        'nickname' => 'TestUser',
        'firstName' => 'Test',
        'lastName' => 'User',
        'email' => 'test_' . time() . '@example.com',
        'password' => hashPassword('test123'),
        'birthday' => '2015-01-01',
        'gender' => 'Male',
        'gradeLevel' => 1,
        'schoolId' => 1
    ];

    $stmt = $conn->prepare("
        EXEC SP_RegisterStudent
            @Nickname = :nickname,
            @FirstName = :firstName,
            @LastName = :lastName,
            @Email = :email,
            @Password = :password,
            @Birthday = :birthday,
            @Gender = :gender,
            @GradeLevel = :gradeLevel,
            @SchoolID = :schoolId,
            @Section = NULL
    ");

    $stmt->bindValue(':nickname', $testData['nickname'], PDO::PARAM_STR);
    $stmt->bindValue(':firstName', $testData['firstName'], PDO::PARAM_STR);
    $stmt->bindValue(':lastName', $testData['lastName'], PDO::PARAM_STR);
    $stmt->bindValue(':email', $testData['email'], PDO::PARAM_STR);
    $stmt->bindValue(':password', $testData['password'], PDO::PARAM_STR);
    $stmt->bindValue(':birthday', $testData['birthday'], PDO::PARAM_STR);
    $stmt->bindValue(':gender', $testData['gender'], PDO::PARAM_STR);
    $stmt->bindValue(':gradeLevel', $testData['gradeLevel'], PDO::PARAM_INT);
    $stmt->bindValue(':schoolId', $testData['schoolId'], PDO::PARAM_INT);

    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result && (int)$result['StudentID'] > 0) {
        echo "✅ Student created successfully with ID: " . $result['StudentID'] . "<br>";
        echo "<pre>";
        print_r($result);
        echo "</pre>";
    } else {
        echo "❌ Registration failed: " . ($result['ErrorMessage'] ?? 'Unknown error') . "<br>";
    }

} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage() . "<br>";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

// Test 5: Simulate full registration
echo "<h2>5. Full Registration API Test</h2>";
echo "Now try the actual registration endpoint with this data:<br>";
echo "<pre>";
echo json_encode([
    'nickname' => 'TestUser',
    'first_name' => 'Test',
    'last_name' => 'User',
    'email' => 'test_' . time() . '@example.com',
    'password' => 'test123',
    'birthday' => '2015-01-01',
    'gender' => 'Male',
    'school_id' => 1,
    'grade_level' => '1'
], JSON_PRETTY_PRINT);
echo "</pre>";

echo "<h2>Done!</h2>";
?>