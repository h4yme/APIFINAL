<?php

/**

 * LiteRise Submit Single Response API (for Adaptive Testing)

 * POST /api/submit_single_response.php

 *

 * Submit one response at a time and get updated ability estimate

 *

 * Request Body:

 * {

 *   "session_id": 123,

 *   "item_id": 5,

 *   "selected_option": "B",

 *   "is_correct": 1,

 *   "time_spent": 15

 * }

 *

 * Response:

 * {

 *   "success": true,

 *   "new_theta": 0.73,

 *   "theta_change": 0.23,

 *   "classification": "Proficient",

 *   "standard_error": 0.42,

 *   "feedback": "Correct! Moving to a harder question."

 * }

 */

 

require_once __DIR__ . '/src/db.php';

require_once __DIR__ . '/src/auth.php';

require_once __DIR__ . '/irt.php';

 

// Require authentication

$authUser = requireAuth();

 

// Get JSON input

$data = getJsonInput();

$sessionID = $data['session_id'] ?? null;

$itemID = $data['item_id'] ?? null;

$selectedOption = $data['selected_option'] ?? $data['Response'] ?? '';

$isCorrect = $data['is_correct'] ?? $data['IsCorrect'] ?? 0;

$timeSpent = $data['time_spent'] ?? $data['TimeSpent'] ?? 0;

 

// Validate required fields

if (!$sessionID || !$itemID) {

    sendError("session_id and item_id are required", 400);

}

 

try {

    // Verify session belongs to authenticated user

    $stmt = $conn->prepare("SELECT StudentID, InitialTheta FROM TestSessions WHERE SessionID = ?");

    $stmt->execute([$sessionID]);

    $session = $stmt->fetch(PDO::FETCH_ASSOC);

 

    if (!$session) {

        sendError("Session not found", 404);

    }

 

    if ($session['StudentID'] != $authUser['studentID']) {

        sendError("Unauthorized", 403);

    }

 

    $studentID = $session['StudentID'];

    $initialTheta = (float)$session['InitialTheta'];

 

    // Get item parameters

    $stmt = $conn->prepare(

        "SELECT DiscriminationParam, DifficultyParam, GuessingParam

         FROM Items WHERE ItemID = ?"

    );

    $stmt->execute([$itemID]);

    $item = $stmt->fetch(PDO::FETCH_ASSOC);

 

    if (!$item) {

        sendError("Item not found", 404);

    }

 

    // Get all responses for this session so far

    $stmt = $conn->prepare(

        "SELECT

            r.IsCorrect,

            i.DiscriminationParam as a,

            i.DifficultyParam as b,

            i.GuessingParam as c

         FROM Responses r

         JOIN Items i ON r.ItemID = i.ItemID

         WHERE r.SessionID = ?

         ORDER BY r.ResponseID"

    );

    $stmt->execute([$sessionID]);

    $previousResponses = $stmt->fetchAll(PDO::FETCH_ASSOC);

 

    // Convert to IRT format and include current response

    $irtResponses = array_map(function($r) {

        return [

            'isCorrect' => (bool)$r['IsCorrect'],

            'a' => (float)$r['a'],

            'b' => (float)$r['b'],

            'c' => (float)$r['c']

        ];

    }, $previousResponses);

 

    // Add current response

    $irtResponses[] = [

        'isCorrect' => (bool)$isCorrect,

        'a' => (float)$item['DiscriminationParam'],

        'b' => (float)$item['DifficultyParam'],

        'c' => (float)$item['GuessingParam']

    ];

 

    // Estimate new ability

    $irt = new ItemResponseTheory();

    $newTheta = $irt->estimateAbility($irtResponses, $initialTheta);

    $sem = $irt->calculateSEM($newTheta, $irtResponses);

    $classification = $irt->classifyAbility($newTheta);

 

    // Determine previous theta (from last response or initial)

    if (!empty($previousResponses)) {

        // Re-estimate with previous responses only

        $previousIRTResponses = array_slice($irtResponses, 0, -1);

        $previousTheta = $irt->estimateAbility($previousIRTResponses, $initialTheta);

    } else {

        $previousTheta = $initialTheta;

    }

 

    $thetaChange = $newTheta - $previousTheta;

 

    // Save response to database

    $insertStmt = $conn->prepare(

        "INSERT INTO Responses

        (SessionID, ItemID, StudentResponse, IsCorrect, TimeSpent, ThetaBeforeResponse, ThetaAfterResponse)

        VALUES (?, ?, ?, ?, ?, ?, ?)"

    );

    $insertStmt->execute([

        $sessionID,

        $itemID,

        $selectedOption,

        $isCorrect,

        $timeSpent,

        $previousTheta,

        $newTheta

    ]);

 

    // Generate feedback

    $feedback = '';

    if ($isCorrect) {

        if ($thetaChange > 0.2) {

            $feedback = "Excellent! That was a challenging question. Moving to a harder question.";

        } else {

            $feedback = "Correct! Next question coming up.";

        }

    } else {

        if ($thetaChange < -0.2) {

            $feedback = "Not quite. Let's try an easier question.";

        } else {

            $feedback = "Incorrect. Keep going!";

        }

    }

 

    // Prepare response

    $response = [

        'success' => true,

        'is_correct' => (bool)$isCorrect,

        'new_theta' => round($newTheta, 3),

        'previous_theta' => round($previousTheta, 3),

        'theta_change' => round($thetaChange, 3),

        'classification' => $classification,

        'standard_error' => round($sem, 3),

        'feedback' => $feedback,

        'total_responses' => count($irtResponses)

    ];

 

    sendResponse($response, 200);

 

} catch (PDOException $e) {

    error_log("Submit single response error: " . $e->getMessage());

    sendError("Failed to save response", 500, $e->getMessage());

} catch (Exception $e) {

    error_log("Submit single response error: " . $e->getMessage());

    sendError("An error occurred", 500, $e->getMessage());

}

?>