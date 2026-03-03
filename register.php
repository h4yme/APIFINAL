<?php

/**
 * LiteRise Student Registration API
 * POST /api/register.php
 *
 * Request Body:
 * {
 *   "nickname": "Leo123",
 *   "first_name": "John",
 *   "last_name": "Doe",
 *   "email": "student@example.com",
 *   "password": "password123",
 *   "birthday": "2015-05-15",  // Optional
 *   "gender": "Male",           // Optional
 *   "school_id": 1,             // Optional
 *   "grade_level": "1"          // Optional, default 1
 * }
 *
 * Response (Success):
 * {
 *   "success": true,
 *   "message": "Registration successful!",
 *   "student": {
 *     "StudentID": 123,
 *     "Nickname": "Leo123",
 *     "FirstName": "John",
 *     "LastName": "Doe",
 *     "Email": "student@example.com",
 *     "GradeLevel": 1,
 *     "CurrentAbility": 0.0,
 *     "TotalXP": 0
 *   },
 *   "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
 * }
 *
 * Response (Error):
 * {
 *   "success": false,
 *   "error": "Error message here"
 * }
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/email.php';

// Get JSON input
$data = getJsonInput();

// Extract and trim input fields
$nickname = trim($data['nickname'] ?? '');
$firstName = trim($data['first_name'] ?? '');
$lastName = trim($data['last_name'] ?? '');
$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';
$birthday = $data['birthday'] ?? null;
$gender = $data['gender'] ?? null;
$schoolId = $data['school_id'] ?? null;
$gradeLevel = $data['grade_level'] ?? '1';

// Validate required fields
$requiredFields = ['nickname', 'first_name', 'last_name', 'email', 'password'];
$missingFields = [];

foreach ($requiredFields as $field) {
    if (empty($data[$field]) || trim($data[$field]) === '') {
        $missingFields[] = $field;
    }
}

if (!empty($missingFields)) {
    sendError("Missing required fields: " . implode(', ', $missingFields), 400);
}

// Validate email format
if (!isValidEmail($email)) {
    sendError("Invalid email format", 400);
}

// Validate password strength (minimum 6 characters)
if (strlen($password) < 6) {
    sendError("Password must be at least 6 characters long", 400);
}

// Validate nickname length
if (strlen($nickname) < 3 || strlen($nickname) > 50) {
    sendError("Nickname must be between 3 and 50 characters", 400);
}

// Validate first name and last name
if (strlen($firstName) < 2 || strlen($firstName) > 50) {
    sendError("First name must be between 2 and 50 characters", 400);
}

if (strlen($lastName) < 2 || strlen($lastName) > 50) {
    sendError("Last name must be between 2 and 50 characters", 400);
}

// Validate grade level
$gradeLevel = (int)$gradeLevel;
if ($gradeLevel < 1 || $gradeLevel > 12) {
    sendError("Grade level must be between 1 and 12", 400);
}

// Validate birthday if provided
if ($birthday) {
    $birthdayDate = date_create($birthday);
    if (!$birthdayDate) {
        sendError("Invalid birthday format. Use YYYY-MM-DD", 400);
    }

    // Check if student is between 5 and 18 years old
    $today = new DateTime();
    $age = $today->diff($birthdayDate)->y;

    if ($age < 3 || $age > 25) {
        sendError("Student age must be between 3 and 25 years", 400);
    }
}

// Validate gender if provided
if ($gender && !in_array(strtolower($gender), ['male', 'female', 'other'])) {
    sendError("Gender must be 'Male', 'Female', or 'Other'", 400);
}

try {
    // Hash password
    $hashedPassword = hashPassword($password);

    // Call stored procedure to register student
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

    $stmt->bindValue(':nickname', $nickname, PDO::PARAM_STR);
    $stmt->bindValue(':firstName', $firstName, PDO::PARAM_STR);
    $stmt->bindValue(':lastName', $lastName, PDO::PARAM_STR);
    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->bindValue(':password', $hashedPassword, PDO::PARAM_STR);
    $stmt->bindValue(':birthday', $birthday, PDO::PARAM_STR);
    $stmt->bindValue(':gender', $gender, PDO::PARAM_STR);
    $stmt->bindValue(':gradeLevel', $gradeLevel, PDO::PARAM_INT);
    $stmt->bindValue(':schoolId', $schoolId, PDO::PARAM_INT);

    $stmt->execute();
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check if registration failed (StudentID = -1 indicates error)
    if (!$student || (int)$student['StudentID'] === -1) {
        $errorMessage = $student['ErrorMessage'] ?? 'Registration failed';

        // Check if it's a duplicate email error
        if (strpos($errorMessage, 'already registered') !== false) {
            sendError("This email is already registered. Please login or use a different email.", 409);
        }

        sendError($errorMessage, 400);
    }

    // Remove sensitive data
    unset($student['Password']);
    unset($student['ErrorMessage']);

    // Generate JWT token
    $token = generateJWT($student['StudentID'], $email);

    // Send welcome email (don't fail registration if email fails)
    try {
        sendWelcomeEmail($email, $firstName, $nickname);
    } catch (Exception $e) {
        error_log("Failed to send welcome email: " . $e->getMessage());
    }

    // Log successful registration
    logActivity($student['StudentID'], 'Registration', 'New student registered');

    // Format response
    $response = [
        'success' => true,
        'message' => 'Registration successful! Welcome to LiteRise!',
        'student' => [
            'StudentID' => (int)$student['StudentID'],
            'Nickname' => $student['Nickname'],
            'FirstName' => $student['FirstName'],
            'LastName' => $student['LastName'],
            'FullName' => $student['FirstName'] . ' ' . $student['LastName'],
            'Email' => $student['Email'],
            'Birthday' => $student['Birthday'] ?? null,
            'Gender' => $student['Gender'] ?? null,
            'GradeLevel' => (int)$student['GradeLevel'],
            'SchoolID' => $student['SchoolID'] ? (int)$student['SchoolID'] : null,
            'Section' => $student['Section'] ?? null,
            'CurrentAbility' => (float)$student['CurrentAbility'],
            'AbilityScore' => (float)$student['CurrentAbility'],
            'TotalXP' => (int)$student['TotalXP'],
            'XP' => (int)$student['TotalXP'],
            'CurrentStreak' => (int)$student['CurrentStreak'],
            'LongestStreak' => (int)$student['LongestStreak'],
            'DateCreated' => $student['DateCreated'],
            'IsActive' => (bool)$student['IsActive']
        ],
        'token' => $token
    ];

    sendResponse($response, 201);

} catch (PDOException $e) {
    error_log("Registration error: " . $e->getMessage());

    // Check for specific SQL errors
    $errorMessage = $e->getMessage();

    // Duplicate email (unique constraint violation)
    if (strpos($errorMessage, 'UNIQUE') !== false || strpos($errorMessage, 'duplicate') !== false) {
        sendError("This email is already registered. Please login or use a different email.", 409);
    }

    // Generic database error
    sendError("Registration failed. Please try again later.", 500);

} catch (Exception $e) {
    error_log("Registration error: " . $e->getMessage());
    sendError("An error occurred during registration", 500);
}

?>