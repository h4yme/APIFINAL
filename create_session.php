<?php

/**

 * LiteRise Create Test Session API

 * POST /api/create_session.php

 *

 * Request Body:

 * {

 *   "student_id": 1,

 *   "type": "PreAssessment"

 * }

 *

 * Session Types: PreAssessment, Lesson, PostAssessment, Game

 *

 * Response:

 * {

 *   "success": true,

 *   "session": {

 *     "SessionID": 123,

 *     "StudentID": 1,

 *     "SessionType": "PreAssessment",

 *     "InitialTheta": 0.5,

 *     "StartTime": "2024-11-17 10:30:00"

 *   }

 * }

 */

 

require_once __DIR__ . '/src/db.php';

require_once __DIR__ . '/src/auth.php';

 

// Require authentication

$authUser = requireAuth();

 

// Get JSON input

$data = getJsonInput();

$studentID = $data['student_id'] ?? 0;

$type = $data['type'] ?? 'PreAssessment';

 

// Validate student_id

if ($studentID == 0 || !is_numeric($studentID)) {

    sendError("Valid student_id is required", 400);

}

 

// Validate session type

$validTypes = ['PreAssessment', 'Lesson', 'PostAssessment', 'Game'];

if (!in_array($type, $validTypes)) {

    sendError("Invalid session type. Must be one of: " . implode(', ', $validTypes), 400);

}

 

// Verify the authenticated user matches the student_id (security check)

if ($authUser['studentID'] != $studentID) {

    sendError("Unauthorized: Cannot create session for another student", 403);

}

 

try {

    // Call stored procedure to create session

    $stmt = $conn->prepare("EXEC SP_CreateTestSession @StudentID = :studentID, @Type = :type");

    $stmt->bindValue(':studentID', $studentID, PDO::PARAM_INT);

    $stmt->bindValue(':type', $type, PDO::PARAM_STR);

    $stmt->execute();

 

    $session = $stmt->fetch(PDO::FETCH_ASSOC);

 

    if (!$session) {

        sendError("Failed to create session", 500);

    }

 

    // Format response

    $response = [

        'success' => true,

        'message' => 'Session created successfully',

        'session' => [

            'SessionID' => (int)$session['SessionID'],

            'StudentID' => (int)$session['StudentID'],

            'SessionType' => $session['SessionType'],

            'InitialTheta' => (float)$session['InitialTheta'],

            'StartTime' => $session['StartTime']

        ]

    ];

 

    // Log activity

    logActivity($studentID, 'SessionStart', "Started $type session");

 

    sendResponse($response, 201);

 

} catch (PDOException $e) {

    error_log("Create session error: " . $e->getMessage());

    sendError("Failed to create session", 500, $e->getMessage());

} catch (Exception $e) {

    error_log("Create session error: " . $e->getMessage());

    sendError("An error occurred", 500, $e->getMessage());

}

?>