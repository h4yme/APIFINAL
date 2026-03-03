<?php

/**

 * LiteRise Update Student Ability API

 * POST /api/update_ability.php

 *

 * Recalculates and updates a student's ability (theta) based on their response history

 *

 * Request Body:

 * {

 *   "student_id": 1,

 *   "session_id": 123  // Optional: specific session or all responses

 * }

 *

 * Response:

 * {

 *   "success": true,

 *   "message": "Ability updated successfully",

 *   "student_id": 1,

 *   "ability": 0.75,

 *   "classification": "Proficient",

 *   "standard_error": 0.32,

 *   "responses_analyzed": 45

 * }

 */

 

require_once __DIR__ . '/src/db.php';

require_once __DIR__ . '/src/auth.php';

require_once __DIR__ . '/irt.php';

 

// Require authentication

$authUser = requireAuth();

 

// Get JSON input

$data = getJsonInput();

$studentID = $data['student_id'] ?? null;

$sessionID = $data['session_id'] ?? null;

 

// Validate student_id

if (!$studentID) {

    sendError("student_id is required", 400);

}

 

// Verify authenticated user

if ($authUser['studentID'] != $studentID) {

    sendError("Unauthorized: Cannot update ability for another student", 403);

}

 

try {

    $irt = new ItemResponseTheory();

 

    // Get responses from this session or all recent sessions

    if ($sessionID) {

        // Get responses from specific session

        $stmt = $conn->prepare(

            "SELECT r.IsCorrect, i.DiscriminationParam as a, i.DifficultyParam as b, i.GuessingParam as c

             FROM Responses r

             JOIN Items i ON r.ItemID = i.ItemID

             WHERE r.SessionID = ?

             ORDER BY r.Timestamp"

        );

        $stmt->execute([$sessionID]);

    } else {

        // Get all recent responses from student (last 50)

        $stmt = $conn->prepare(

            "SELECT TOP 50 r.IsCorrect, i.DiscriminationParam as a, i.DifficultyParam as b, i.GuessingParam as c

             FROM Responses r

             JOIN Items i ON r.ItemID = i.ItemID

             JOIN TestSessions ts ON r.SessionID = ts.SessionID

             WHERE ts.StudentID = ?

             ORDER BY r.Timestamp DESC"

        );

        $stmt->execute([$studentID]);

    }

 

    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);

 

    if (empty($responses)) {

        sendError("No responses found to calculate ability", 404);

    }

 

    // Format responses for IRT

    $irtResponses = array_map(function($r) {

        return [

            'isCorrect' => (bool)$r['IsCorrect'],

            'a' => (float)$r['a'],

            'b' => (float)$r['b'],

            'c' => (float)$r['c']

        ];

    }, $responses);

 

    // Get current ability as starting point

    $stmt = $conn->prepare("SELECT CurrentAbility FROM Students WHERE StudentID = ?");

    $stmt->execute([$studentID]);

    $currentAbility = (float)($stmt->fetchColumn() ?? 0.0);

 

    // Estimate new ability

    $newTheta = $irt->estimateAbility($irtResponses, $currentAbility);

 

    // Calculate SEM (standard error of measurement)

    $sem = $irt->calculateSEM($newTheta, $irtResponses);

 

    // Classify ability

    $classification = $irt->classifyAbility($newTheta);

 

    // Update database

    $stmt = $conn->prepare("EXEC SP_UpdateStudentAbility @StudentID = ?, @NewTheta = ?");

    $stmt->execute([$studentID, $newTheta]);

 

    // Update session final theta if session provided

    if ($sessionID) {

        $stmt = $conn->prepare(

            "UPDATE TestSessions

             SET FinalTheta = ?, IsCompleted = 1, EndTime = GETDATE()

             WHERE SessionID = ?"

        );

        $stmt->execute([$newTheta, $sessionID]);

    }

 

    // Log activity

    logActivity($studentID, 'AbilityUpdate', "Ability updated to $newTheta ($classification)");

 

    $response = [

        'success' => true,

        'message' => 'Ability updated successfully',

        'student_id' => (int)$studentID,

        'ability' => round($newTheta, 3),

        'previous_ability' => round($currentAbility, 3),

        'change' => round($newTheta - $currentAbility, 3),

        'classification' => $classification,

        'standard_error' => round($sem, 3),

        'responses_analyzed' => count($responses)

    ];

 

    sendResponse($response, 200);

 

} catch (PDOException $e) {

    error_log("Update ability error: " . $e->getMessage());

    sendError("Failed to update ability", 500, $e->getMessage());

} catch (Exception $e) {

    error_log("Update ability error: " . $e->getMessage());

    sendError("An error occurred", 500, $e->getMessage());

}

?>