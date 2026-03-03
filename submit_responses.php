<?php

/**

 * LiteRise Submit Responses API

 * POST /api/submit_responses.php

 *

 * Request Body:

 * {

 *   "session_id": 123,

 *   "student_id": 1,

 *   "responses": [

 *     {

 *       "ItemID": 1,

 *       "Response": "A",

 *       "IsCorrect": 1,

 *       "TimeSpent": 15

 *     },

 *     {

 *       "ItemID": 2,

 *       "Response": "B",

 *       "IsCorrect": 0,

 *       "TimeSpent": 20

 *     }

 *   ]

 * }

 *

 * Response:

 * {

 *   "success": true,

 *   "message": "Responses saved successfully",

 *   "session_id": 123,

 *   "total_responses": 20,

 *   "correct_answers": 15,

 *   "accuracy": 75.0,

 *   "ability": {

 *     "initial_theta": 0.5,

 *     "final_theta": 0.75,

 *     "classification": "Proficient",

 *     "standard_error": 0.32

 *   }

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

$studentID = $data['student_id'] ?? $data['StudentID'] ?? null;

$responses = $data['responses'] ?? $data['Responses'] ?? [];

 

// Validate student_id

if (!$studentID) {

    sendError("student_id is required", 400);

}

 

// Auto-create session if not provided (for Android app compatibility)

$autoCreatedSession = false;

if (!$sessionID) {

    error_log("Auto-creating PreAssessment session for student $studentID");

    try {

        $stmt = $conn->prepare("EXEC SP_CreateTestSession @StudentID = :studentID, @Type = :type");

        $stmt->bindValue(':studentID', $studentID, PDO::PARAM_INT);

        $stmt->bindValue(':type', 'PreAssessment', PDO::PARAM_STR);

        $stmt->execute();

 

        $session = $stmt->fetch(PDO::FETCH_ASSOC);

 

        // Close cursor to prevent "other threads running in session" error

        $stmt->closeCursor();

 

        if ($session) {

            $sessionID = $session['SessionID'];

            $autoCreatedSession = true;

            error_log("Created session $sessionID for student $studentID");

        } else {

            sendError("Failed to create session", 500);

        }

    } catch (Exception $e) {

        sendError("Failed to create session", 500, $e->getMessage());

    }

}

 

if (empty($responses) || !is_array($responses)) {

    sendError("responses array is required and cannot be empty", 400);

}

 

// Verify authenticated user matches student_id

if ($authUser['studentID'] != $studentID) {

    sendError("Unauthorized: Cannot submit responses for another student", 403);

}

 

try {

    // Start transaction (only if not already in one)

    if (!$conn->inTransaction()) {

        $conn->beginTransaction();

    }

 

    // Verify session belongs to student

    $stmt = $conn->prepare("SELECT StudentID, InitialTheta, SessionType FROM TestSessions WHERE SessionID = ?");

    $stmt->execute([$sessionID]);

    $session = $stmt->fetch(PDO::FETCH_ASSOC);

 

    if (!$session) {

        $conn->rollBack();

        sendError("Session not found", 404);

    }

 

    if ($session['StudentID'] != $studentID) {

        $conn->rollBack();

        sendError("Session does not belong to this student", 403);

    }

 

    $initialTheta = (float)$session['InitialTheta'];

    $sessionType = $session['SessionType'];

 

    // Prepare IRT data for ability estimation

    $irtResponses = [];

    $correctCount = 0;

 

    // Insert each response

    foreach ($responses as $response) {

        $itemID = $response['ItemID'] ?? null;

        // Handle both Android format (SelectedOption) and original format (Response)

        $studentResponse = $response['Response'] ?? $response['SelectedOption'] ?? '';

        // Handle both Android format (Correct) and original format (IsCorrect)

        $isCorrect = $response['IsCorrect'] ?? $response['Correct'] ?? 0;

        // Handle both Android format (TimeTakenSec) and original format (TimeSpent)

        $timeSpent = $response['TimeSpent'] ?? $response['TimeTakenSec'] ?? 0;

 

        if (!$itemID) continue;

 

        // Get item IRT parameters

        $stmt = $conn->prepare(

            "SELECT DiscriminationParam, DifficultyParam, GuessingParam

             FROM Items WHERE ItemID = ?"

        );

        $stmt->execute([$itemID]);

        $item = $stmt->fetch(PDO::FETCH_ASSOC);

 

        if ($item) {

            // Save response to database

            $insertStmt = $conn->prepare(

                "INSERT INTO Responses

                (SessionID, ItemID, StudentResponse, IsCorrect, TimeSpent, ThetaBeforeResponse)

                VALUES (?, ?, ?, ?, ?, ?)"

            );

            $insertStmt->execute([

                $sessionID,

                $itemID,

                $studentResponse,

                $isCorrect,

                $timeSpent,

                $initialTheta

            ]);

 

            // Collect IRT data

            $irtResponses[] = [

                'isCorrect' => (bool)$isCorrect,

                'a' => (float)$item['DiscriminationParam'],

                'b' => (float)$item['DifficultyParam'],

                'c' => (float)$item['GuessingParam']

            ];

 

            if ($isCorrect) {

                $correctCount++;

            }

        }

    }

 

    $totalResponses = count($irtResponses);

    $accuracy = $totalResponses > 0 ? ($correctCount / $totalResponses) * 100 : 0;

 

    // Estimate new ability using IRT

    $irt = new ItemResponseTheory();

    $finalTheta = $irt->estimateAbility($irtResponses, $initialTheta);

    $sem = $irt->calculateSEM($finalTheta, $irtResponses);

    $classification = $irt->classifyAbility($finalTheta);

 

    // Update session statistics

    $stmt = $conn->prepare(

        "UPDATE TestSessions

         SET TotalQuestions = ?,

             CorrectAnswers = ?,

             AccuracyPercentage = ?,

             FinalTheta = ?,

             IsCompleted = 1,

             EndTime = GETDATE()

         WHERE SessionID = ?"

    );

    $stmt->execute([

        $totalResponses,

        $correctCount,

        $accuracy,

        $finalTheta,

        $sessionID

    ]);

 

    // Update student's current ability

    error_log("Updating student $studentID ability from $initialTheta to $finalTheta");

    $stmt = $conn->prepare(

        "UPDATE Students

         SET CurrentAbility = ?

         WHERE StudentID = ?"

    );

    $updateResult = $stmt->execute([$finalTheta, $studentID]);

    $rowsAffected = $stmt->rowCount();

    error_log("Ability update executed: " . ($updateResult ? 'SUCCESS' : 'FAILED') . ", rows affected: $rowsAffected");

 

    // Update theta for all responses

    $stmt = $conn->prepare(

        "UPDATE Responses

         SET ThetaAfterResponse = ?

         WHERE SessionID = ?"

    );

    $stmt->execute([$finalTheta, $sessionID]);

 

    // Commit transaction

    $conn->commit();

 

    // Log activity

    logActivity($studentID, 'SessionComplete', "Completed $sessionType session with $accuracy% accuracy");

 

    // Prepare response

    $response = [

        'success' => true,

        'message' => 'Responses saved successfully',

        'session_id' => (int)$sessionID,

        'total_responses' => $totalResponses,

        'correct_answers' => $correctCount,

        'accuracy' => round($accuracy, 2),

        'ability' => [

            'initial_theta' => round($initialTheta, 3),

            'final_theta' => round($finalTheta, 3),

            'change' => round($finalTheta - $initialTheta, 3),

            'classification' => $classification,

            'standard_error' => round($sem, 3)

        ]

    ];

 

    sendResponse($response, 200);

 

} catch (PDOException $e) {

    if ($conn->inTransaction()) {

        $conn->rollBack();

    }

    error_log("Submit responses error: " . $e->getMessage());

    sendError("Failed to save responses", 500, $e->getMessage());

} catch (Exception $e) {

    if ($conn->inTransaction()) {

        $conn->rollBack();

    }

    error_log("Submit responses error: " . $e->getMessage());

    sendError("An error occurred", 500, $e->getMessage());

}

?>