<?php

/**

 * LiteRise Get Pre-Assessment Items API

 * POST /api/get_preassessment_items.php

 *

 * Request Body:

 * {

 *   "student_id": 1  // Optional: for adaptive selection

 * }

 *

 * Response:

 * {

 *   "success": true,

 *   "count": 20,

 *   "items": [

 *     {

 *       "ItemID": 1,

 *       "ItemText": "Choose the correct spelling:",

 *       "QuestionText": "Choose the correct spelling:",

 *       "PassageText": "",

 *       "ItemType": "Spelling",

 *       "DifficultyLevel": "Easy",

 *       "DifficultyParam": -0.5,

 *       "DiscriminationParam": 1.3,

 *       "GuessingParam": 0.25,

 *       "AnswerChoices": ["receive", "recieve", "recive"],

 *       "OptionA": "receive",

 *       "OptionB": "recieve",

 *       "OptionC": "recive",

 *       "OptionD": "",

 *       "CorrectOption": "A"

 *     }

 *   ]

 * }

 */

 

require_once __DIR__ . '/src/db.php';

require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/item_formatter.php';

 /**

 * Generate incorrect sentence variations by shuffling words

 * @param array $words Array of words to shuffle

 * @param string $correctSentence The correct sentence (to avoid)

 * @return array Array of 3 incorrect sentence variations

 */



// Require authentication

$authUser = requireAuth();

 

try {

    // Call stored procedure to get pre-assessment items

    $stmt = $conn->prepare("EXEC SP_GetPreAssessmentItems");

    $stmt->execute();

 

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

 

    if (empty($items)) {

        sendError("No assessment items available", 404);

    }

 

    // Format items to match Android app expectations

    // Format items to match Android app expectations using shared formatter

    $formattedItems = array_map('formatItemForApp', $items);

 

    $response = [

        'success' => true,

        'count' => count($formattedItems),

        'items' => $formattedItems

    ];

 

    sendResponse($response, 200);

 

} catch (PDOException $e) {

    error_log("Get pre-assessment items error: " . $e->getMessage());

    sendError("Failed to fetch assessment items", 500, $e->getMessage());

} catch (Exception $e) {

    error_log("Get pre-assessment items error: " . $e->getMessage());

    sendError("An error occurred", 500, $e->getMessage());

}

?>