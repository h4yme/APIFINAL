<?php

/**

 * LiteRise Adaptive Item Selection API

 * POST /api/get_next_item.php

 *

 * Uses IRT to select the next best item based on current ability estimate

 *

 * Request Body:

 * {

 *   "session_id": 123,

 *   "current_theta": 0.5,

 *   "items_answered": [1, 3, 5, 7]  // IDs of already answered items

 * }

 *

 * Response:

 * {

 *   "success": true,

 *   "item": { ... item details ... },

 *   "current_theta": 0.5,

 *   "items_remaining": 15,

 *   "assessment_complete": false

 * }

 */

 

require_once __DIR__ . '/src/db.php';

require_once __DIR__ . '/src/auth.php';

require_once __DIR__ . '/irt.php';
require_once __DIR__ . '/src/item_formatter.php';
 

// Require authentication

$authUser = requireAuth();

 

// Get JSON input

$data = getJsonInput();

$sessionID = $data['session_id'] ?? null;

$currentTheta = $data['current_theta'] ?? 0.0;

$itemsAnswered = $data['items_answered'] ?? [];

 

// Configuration

$maxItems = 20; // Maximum items in assessment

$targetSEM = 0.25; // Target precision (stop when SEM is this low)

$minItems = 20; // Minimum items - require all 20 items for pre-assessment

 

// Item type distribution for variety (ensures mix of all types)

$targetDistribution = [

    'Spelling' => 5,

    'Grammar' => 5,

    'Pronunciation' => 5,

    'Syntax' => 5

];
 

// Validate session

// Auto-create session if not provided (for Android app compatibility)

$autoCreatedSession = false;

if (!$sessionID || $sessionID == 0) {

    error_log("Auto-creating PreAssessment session for student " . $authUser['studentID']);

    try {

        $stmt = $conn->prepare("EXEC SP_CreateTestSession @StudentID = :studentID, @Type = :type");

        $stmt->bindValue(':studentID', $authUser['studentID'], PDO::PARAM_INT);

        $stmt->bindValue(':type', 'PreAssessment', PDO::PARAM_STR);

        $stmt->execute();

 

        $session = $stmt->fetch(PDO::FETCH_ASSOC);

 

        // Close cursor to prevent "other threads running in session" error

        $stmt->closeCursor();

 

        if ($session) {

            $sessionID = $session['SessionID'];

            $autoCreatedSession = true;

            error_log("Created session $sessionID for student " . $authUser['studentID']);

        } else {

            sendError("Failed to create session", 500);

        }

    } catch (Exception $e) {

        error_log("Failed to create session: " . $e->getMessage());

        sendError("Failed to create session", 500, $e->getMessage());

    }

}

 

try {

    // Verify session belongs to authenticated user

    $stmt = $conn->prepare("SELECT StudentID, SessionType FROM TestSessions WHERE SessionID = ?");

    $stmt->execute([$sessionID]);

    $session = $stmt->fetch(PDO::FETCH_ASSOC);

 

    if (!$session) {

        sendError("Session not found", 404);

    }

 

    if ($session['StudentID'] != $authUser['studentID']) {

        sendError("Unauthorized", 403);

    }

 

    // Get all available items (not yet answered)

     $query = "SELECT

                ItemID,

                ItemText,

                ItemType,

                DifficultyLevel,

                AnswerChoices,

                CorrectAnswer,

                DifficultyParam,

                DiscriminationParam,

                GuessingParam,

                ImageURL,

                AudioURL,

                Phonetic,

                Definition

              FROM Items

              WHERE IsActive = 1";

 

    if (!empty($itemsAnswered)) {

        $placeholders = str_repeat('?,', count($itemsAnswered) - 1) . '?';
        $query .= " AND ItemID NOT IN ($placeholders)";

        $stmt = $conn->prepare($query);

        $stmt->execute($itemsAnswered);

    } else {

        $stmt = $conn->prepare($query);

        $stmt->execute();

    }

 

    $availableItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

 

    // Fetch all responses for this session to get actual completion count

    $stmt = $conn->prepare(

        "SELECT

            r.IsCorrect,

            i.DiscriminationParam as a,

            i.DifficultyParam as b,

            i.GuessingParam as c

         FROM Responses r

         JOIN Items i ON r.ItemID = i.ItemID

         WHERE r.SessionID = ?"

    );

    $stmt->execute([$sessionID]);

    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);

 

    // Use actual database count for items completed (more reliable than client-sent array)

    $itemsCompleted = count($responses);

    $shouldStop = false;

 

    error_log("get_next_item: sessionID=$sessionID, itemsCompleted=$itemsCompleted (from DB), itemsAnswered from request=" . count($itemsAnswered));

    error_log("get_next_item: Found " . count($responses) . " responses in database for session $sessionID");

 

    // If no more items available, complete the assessment with current stats

    if (empty($availableItems)) {

        // Calculate final stats

        $correctCount = 0;

        if (!empty($responses)) {

            foreach ($responses as $r) {

                if ($r['IsCorrect']) {

                    $correctCount++;

                }

            }

        }

        $accuracy = $itemsCompleted > 0 ? ($correctCount / $itemsCompleted) * 100 : 0;

 

        error_log("Assessment completion (no more items): items=$itemsCompleted, correct=$correctCount, accuracy=$accuracy%, theta=$currentTheta");

 

        // Update TestSessions

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

            $itemsCompleted,

            $correctCount,

            $accuracy,

            $currentTheta,

            $sessionID

        ]);

 

        // Update student's current ability

        $studentID = $session['StudentID'];

        error_log("Updating student $studentID ability to $currentTheta (no more items available)");

        $stmt = $conn->prepare(

            "UPDATE Students

             SET CurrentAbility = ?

             WHERE StudentID = ?"

        );

        $stmt->execute([$currentTheta, $studentID]);

 

        sendResponse([

            'success' => true,

            'session_id' => $sessionID,

            'assessment_complete' => true,

            'message' => 'No more items available',

            'items_completed' => $itemsCompleted,

            'total_items' => $itemsCompleted,

            'correct_answers' => $correctCount,

            'accuracy' => round($accuracy, 2),

            'final_theta' => $currentTheta

        ], 200);

        exit;

    }

 

    if ($itemsCompleted >= $minItems && !empty($responses)) {

        // Calculate current SEM if we have enough items

        $irt = new ItemResponseTheory();

 

        // Convert to IRT format

        $irtResponses = array_map(function($r) {

            return [

                'isCorrect' => (bool)$r['IsCorrect'],

                'a' => (float)$r['a'],

                'b' => (float)$r['b'],

                'c' => (float)$r['c']

            ];

        }, $responses);

 

        $sem = $irt->calculateSEM($currentTheta, $irtResponses);

 

        if ($sem <= $targetSEM || $itemsCompleted >= $maxItems) {

            $shouldStop = true;
        }

    }

 

    if ($shouldStop || $itemsCompleted >= $maxItems) {

        // Update TestSessions with final results

        $correctCount = 0;

       if (!empty($responses)) {

            foreach ($responses as $r) {

                if ($r['IsCorrect']) {

                    $correctCount++;

                }

            }

        }

        $accuracy = $itemsCompleted > 0 ? ($correctCount / $itemsCompleted) * 100 : 0;

 

        error_log("Assessment completion: items=$itemsCompleted, correct=$correctCount, accuracy=$accuracy%, theta=$currentTheta");

 

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

            $itemsCompleted,

            $correctCount,

            $accuracy,

            $currentTheta,

            $sessionID

        ]);

 

        // Update student's current ability in Students table

        $studentID = $session['StudentID'];

        error_log("Updating student $studentID ability to $currentTheta (adaptive assessment complete)");

        $stmt = $conn->prepare(

            "UPDATE Students

             SET CurrentAbility = ?

             WHERE StudentID = ?"

        );

        $stmt->execute([$currentTheta, $studentID]);

 

        sendResponse([

            'success' => true,

            'session_id' => $sessionID,

            'assessment_complete' => true,

            'message' => 'Assessment complete - sufficient precision achieved',

            'items_completed' => $itemsCompleted,

            'total_items' => $itemsCompleted,

            'correct_answers' => $correctCount,

            'accuracy' => round($accuracy, 2),

            'final_theta' => $currentTheta,

            'sem' => $sem ?? null

        ], 200);

        exit;

    }

 

    // Use IRT to select the next best item

    $irt = new ItemResponseTheory();

 

   $irt = new ItemResponseTheory();

 

   

    // Count items answered by type (from previous responses in this session)

 

    $typeCounts = ['Spelling' => 0, 'Grammar' => 0, 'Pronunciation' => 0, 'Syntax' => 0];

 

    $stmtTypes = $conn->prepare(

 

        "SELECT i.ItemType, COUNT(*) as cnt

 

         FROM Responses r

 

         JOIN Items i ON r.ItemID = i.ItemID

 

         WHERE r.SessionID = ?

 

         GROUP BY i.ItemType"

 

    );

 

    $stmtTypes->execute([$sessionID]);

 

    $typeResults = $stmtTypes->fetchAll(PDO::FETCH_ASSOC);

 

    foreach ($typeResults as $tr) {

 

        // Case-insensitive matching for item types

 

        $itemType = ucfirst(strtolower(trim($tr['ItemType'])));

 

        if (isset($typeCounts[$itemType])) {

 

            $typeCounts[$itemType] = (int)$tr['cnt'];

        }

    }

 

    // Determine which item types need more items (prioritize underrepresented types)

    $prioritizedTypes = [];

    foreach ($targetDistribution as $type => $target) {

        if ($typeCounts[$type] < $target) {

            $prioritizedTypes[] = $type;

        }

    }

 

    // Filter available items by prioritized types if any need more items

    $filteredItems = $availableItems;

    if (!empty($prioritizedTypes)) {

         // Create lowercase version of prioritized types for case-insensitive matching

 

        $prioritizedTypesLower = array_map('strtolower', $prioritizedTypes);

 

        $typeFilteredItems = array_filter($availableItems, function($item) use ($prioritizedTypesLower) {

 

            $itemTypeLower = strtolower(trim($item['ItemType'] ?? ''));

 

            return in_array($itemTypeLower, $prioritizedTypesLower);

        });

        // Only use filtered if we have items available

        if (!empty($typeFilteredItems)) {

            $filteredItems = array_values($typeFilteredItems);

        }

    }

 

    // Prepare items for IRT selection

    $irtItems = array_map(function($item) {

        return [

            'itemID' => $item['ItemID'],

            'a' => (float)($item['DiscriminationParam'] ?? 1.0),

            'b' => (float)($item['DifficultyParam'] ?? 0.0),

            'c' => (float)($item['GuessingParam'] ?? 0.25),

            'raw' => $item // Keep full item data

        ];

    }, $filteredItems);

 

    // Select best item using Maximum Information

    $selectedIRTItem = $irt->selectNextItem($currentTheta, $irtItems);

 

    if (!$selectedIRTItem) {

        sendError("Failed to select next item", 500);

    }

 

  // Get the full item data and format it using shared formatter

    $selectedItem = $selectedIRTItem['raw'];

    $formattedItem = formatItemForApp($selectedItem);
  

    $response = [

        'success' => true,

        'session_id' => $sessionID,

        'item' => $formattedItem,

        'current_theta' => round($currentTheta, 3),

        'items_completed' => $itemsCompleted, // Now uses actual DB count

        'items_remaining' => min($maxItems - $itemsCompleted, count($availableItems)),

        'assessment_complete' => false,

        'progress_percentage' => round(($itemsCompleted / $maxItems) * 100, 1)

    ];

 

    sendResponse($response, 200);

 

} catch (PDOException $e) {

    error_log("Get next item error: " . $e->getMessage());

    sendError("Failed to get next item", 500, $e->getMessage());

} catch (Exception $e) {

    error_log("Get next item error: " . $e->getMessage());

    sendError("An error occurred", 500, $e->getMessage());

}

?>