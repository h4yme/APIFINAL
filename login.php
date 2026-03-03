<?php


/**

 * LiteRise Student Login API

 * POST /api/login.php

 *

 * Request Body:

 * {

 *   "email": "student@example.com",

 *   "password": "password123"

 * }

 *

 * Response:

 * {

 *   "success": true,

 *   "StudentID": 1,

 *   "FullName": "John Doe",

 *   "FirstName": "John",

 *   "LastName": "Doe",

 *   "email": "student@example.com",

 *   "GradeLevel": 5,

 *   "Section": "A",

 *   "CurrentAbility": 0.5,

 *   "TotalXP": 1200,

 *   "CurrentStreak": 5,

 *   "LongestStreak": 10,

 *   "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."

 * }

 */

 

require_once __DIR__ . '/src/db.php';

require_once __DIR__ . '/src/auth.php';

 

// Get JSON input

$data = getJsonInput();

$email = trim($data['email'] ?? '');

$password = $data['password'] ?? '';

 

// Validate required fields

if (empty($email) || empty($password)) {

    sendError("Email and password are required", 400);

}

 

// Validate email format

if (!isValidEmail($email)) {

    sendError("Invalid email format", 400);

}

 

try {

    // Call the stored procedure to get student data

    $stmt = $conn->prepare("EXEC SP_StudentLogin @Email = :email, @Password = :password");

    $stmt->bindValue(':email', $email, PDO::PARAM_STR);

    $stmt->bindValue(':password', $password, PDO::PARAM_STR);

    $stmt->execute();

 

    $student = $stmt->fetch(PDO::FETCH_ASSOC);

 

    // Check if student exists

    if (!$student) {

        error_log("LOGIN FAILED: Student not found for email: $email");

        sendError("Invalid email or password", 401);

    }

 

    // Verify password

    $hashedPassword = $student['Password'];

 

    // DEBUG logging

    error_log("LOGIN ATTEMPT: Email=$email, HashFromDB=" . substr($hashedPassword, 0, 20) . "...");

    $verifyResult = verifyPassword($password, $hashedPassword);

    error_log("PASSWORD VERIFY: Result=" . ($verifyResult ? 'TRUE' : 'FALSE'));

 

    if (!$verifyResult) {

        error_log("LOGIN FAILED: Password verification failed for email: $email");

 

        // In debug mode, show more info

        $debugInfo = null;

        if (($_ENV['DEBUG_MODE'] ?? 'false') === 'true') {

            $debugInfo = [

                'hash_start' => substr($hashedPassword, 0, 20),

                'hash_length' => strlen($hashedPassword),

                'password_length' => strlen($password)

            ];

        }

        sendError("Invalid email or password", 401, $debugInfo);

    }

    // Remove password from response

    unset($student['Password']);

 

    // Generate JWT token

    $token = generateJWT($student['StudentID'], $email);

 

    // Format response to match Android app expectations

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

        'AbilityScore' => (float)$student['CurrentAbility'], // Alias for Android

        'TotalXP' => (int)$student['TotalXP'],

        'XP' => (int)$student['TotalXP'], // Alias for Android

        'CurrentStreak' => (int)$student['CurrentStreak'],

        'LongestStreak' => (int)$student['LongestStreak'],

        'LastLogin' => $student['LastLogin'] ?? null,

        'PreAssessmentCompleted' => isset($student['PreAssessmentCompleted']) ? (bool)$student['PreAssessmentCompleted'] : false,

        'AssessmentStatus' => $student['AssessmentStatus'] ?? 'Not Started',

        'token' => $token

    ];

 

    // Log successful login

    logActivity($student['StudentID'], 'Login', 'User logged in successfully');

 

    sendResponse($response, 200);

 

} catch (PDOException $e) {

    error_log("Login error: " . $e->getMessage());

    sendError("Login failed", 500, $e->getMessage());

} catch (Exception $e) {

    error_log("Login error: " . $e->getMessage());

    sendError("An error occurred during login", 500, $e->getMessage());

}

?>