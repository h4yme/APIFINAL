<?php

/**

 * LiteRise Get Game Data API

 * POST /api/get_game_data.php

 *

 * Returns game questions/challenges based on game type and student level

 *

 * Request Body:

 * {

 *   "student_id": 1,

 *   "game_type": "SentenceScramble",  // SentenceScramble or TimedTrail

 *   "count": 10  // Number of items (optional, default 10)

 * }

 *

 * Response:

 * {

 *   "success": true,

 *   "game_type": "SentenceScramble",

 *   "count": 10,

 *   "items": [

 *     {

 *       "ItemID": 1,

 *       "ItemText": "homework / diligently / finished / her / Maria",

 *       "CorrectAnswer": "Maria diligently finished her homework.",

 *       "DifficultyLevel": "Easy",

 *       "DifficultyParam": -1.0,

 *       "Words": ["homework", "diligently", "finished", "her", "Maria"]

 *     }

 *   ]

 * }

 */

 

require_once __DIR__ . '/src/db.php';

require_once __DIR__ . '/src/auth.php';

 

// Require authentication

$authUser = requireAuth();

 

// Get JSON input

$data = getJsonInput();

$studentID = $data['student_id'] ?? null;

$gameType = $data['game_type'] ?? 'SentenceScramble';

$count = $data['count'] ?? 10;

 

// Validate student_id

if (!$studentID) {

    sendError("student_id is required", 400);

}

 

// Verify authenticated user

if ($authUser['studentID'] != $studentID) {

    sendError("Unauthorized: Cannot get game data for another student", 403);

}

 

// Validate game type

$validGameTypes = ['SentenceScramble', 'TimedTrail'];

if (!in_array($gameType, $validGameTypes)) {

    sendError("Invalid game_type. Must be: " . implode(', ', $validGameTypes), 400);

}

 

// Validate count

$count = max(1, min((int)$count, 50)); // Between 1 and 50

 

try {

    // Get student's grade level

    $stmt = $conn->prepare("SELECT GradeLevel FROM Students WHERE StudentID = ?");

    $stmt->execute([$studentID]);

    $student = $stmt->fetch(PDO::FETCH_ASSOC);

 

    if (!$student) {

        sendError("Student not found", 404);

    }

 

    $gradeLevel = (int)$student['GradeLevel'];

 

    // Get game data based on game type

    if ($gameType === 'SentenceScramble') {

        // Call stored procedure for Sentence Scramble

        $stmt = $conn->prepare("EXEC SP_GetSentenceScrambleData @GradeLevel = ?, @Count = ?");

        $stmt->execute([$gradeLevel, $count]);

    } else {

        // Call stored procedure for Timed Trail

        $stmt = $conn->prepare("EXEC SP_GetTimedTrailData @GradeLevel = ?, @Count = ?");

        $stmt->execute([$gradeLevel, $count]);

    }

 

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

 

    if (empty($items)) {

        sendError("No game data available for this grade level", 404);

    }

 

    // Format items based on game type

    $formattedItems = array_map(function($item) use ($gameType) {

        $formatted = [

            'ItemID' => (int)$item['ItemID'],

            'ItemText' => $item['ItemText'],

            'ItemType' => $item['ItemType'] ?? 'Syntax',

            'CorrectAnswer' => $item['CorrectAnswer'],

            'DifficultyLevel' => $item['DifficultyLevel'],

            'DifficultyParam' => (float)($item['DifficultyParam'] ?? 0),

        ];

 

        // For Sentence Scramble, split into words

        if ($gameType === 'SentenceScramble') {

            $words = explode(' / ', $item['ItemText']);

            $formatted['Words'] = array_map('trim', $words);

            $formatted['WordCount'] = count($formatted['Words']);

        }

 

        // For Timed Trail, include answer choices

        if ($gameType === 'TimedTrail' && !empty($item['AnswerChoices'])) {

            $choices = json_decode($item['AnswerChoices'], true);

            $formatted['AnswerChoices'] = $choices ?? [];

 

            // Map to options

            $formatted['OptionA'] = $choices[0] ?? '';

            $formatted['OptionB'] = $choices[1] ?? '';

            $formatted['OptionC'] = $choices[2] ?? '';

            $formatted['OptionD'] = $choices[3] ?? '';

 

            // Determine correct option

            $correctOption = '';

            if ($item['CorrectAnswer'] === ($choices[0] ?? '')) $correctOption = 'A';

            elseif ($item['CorrectAnswer'] === ($choices[1] ?? '')) $correctOption = 'B';

            elseif ($item['CorrectAnswer'] === ($choices[2] ?? '')) $correctOption = 'C';

            elseif ($item['CorrectAnswer'] === ($choices[3] ?? '')) $correctOption = 'D';

 

            $formatted['CorrectOption'] = $correctOption;

        }

 

        return $formatted;

    }, $items);

 

    $response = [

        'success' => true,

        'game_type' => $gameType,

        'grade_level' => $gradeLevel,

        'count' => count($formattedItems),

        'items' => $formattedItems

    ];

 

    sendResponse($response, 200);

 

} catch (PDOException $e) {

    error_log("Get game data error: " . $e->getMessage());

    sendError("Failed to fetch game data", 500, $e->getMessage());

} catch (Exception $e) {

    error_log("Get game data error: " . $e->getMessage());

    sendError("An error occurred", 500, $e->getMessage());

}

?>