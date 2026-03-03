<?php

/**

 * LiteRise Get Scramble Sentences API

 * GET/POST /api/get_scramble_sentences.php

 *

 * Returns sentences for the Sentence Scramble game

 * Now pulls from LessonGameContent table (lesson-based) instead of Items table

 *

 * Request Parameters:

 * - lesson_id: The lesson to get content for (required for lesson-based content)

 * - count: Number of sentences to return (default 10)

 * - grade_level: Filter by grade level (optional, for fallback)

 * - difficulty: Filter by difficulty (optional: easy, medium, hard)

 *

 * Response:

 * {

 *   "success": true,

 *   "sentences": [

 *     {

 *       "SentenceID": 1,

 *       "CorrectSentence": "The quick brown fox jumps over the lazy dog",

 *       "ScrambledWords": ["jumps", "The", "dog", "over", "lazy", "quick", "fox", "the", "brown"],

 *       "Difficulty": 1.0,

 *       "Category": "General",

 *       "GradeLevel": 4

 *     }

 *   ],

 *   "total": 10,

 *   "lesson_id": 1

 * }

 */

 

require_once __DIR__ . '/src/db.php';

require_once __DIR__ . '/src/auth.php';

 

// Require authentication

$authUser = requireAuth();

 

// Get parameters - support both GET query params and POST JSON body

$data = [];

$rawInput = file_get_contents("php://input");

if (!empty($rawInput)) {

    $data = json_decode($rawInput, true) ?? [];

}

$lessonID = $data['lesson_id'] ?? $_GET['lesson_id'] ?? null;

$count = $data['count'] ?? $_GET['count'] ?? 10;

$gradeLevel = $data['grade_level'] ?? $_GET['grade_level'] ?? null;

$difficulty = $data['difficulty'] ?? $_GET['difficulty'] ?? null;

 

// Validate count

$count = min(max((int)$count, 1), 20); // Between 1 and 20

 

try {

    $sentences = [];

 // If lesson_id is provided, try to get content from LessonGameContent table

    if ($lessonID !== null) {

        try {

            // Check if LessonGameContent table exists before querying

            $checkTable = $conn->query("SELECT TOP 1 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'LessonGameContent'");

            $tableExists = $checkTable->fetch() !== false;

 

            if ($tableExists) {

                $query = "SELECT TOP $count

                            ContentID as SentenceID,

                            ContentText as CorrectSentence,

                            ContentData as ScrambledWordsJSON,

                            Difficulty,

                            Category,

                            l.GradeLevel

                          FROM LessonGameContent lgc

                          INNER JOIN Lessons l ON lgc.LessonID = l.LessonID

                          WHERE lgc.LessonID = ?

                            AND lgc.GameType = 'SentenceScramble'

                            AND lgc.IsActive = 1";

 

                $params = [(int)$lessonID];

 

                // Map difficulty string to range

                if ($difficulty !== null) {

                    switch (strtolower($difficulty)) {

                        case 'easy':

                            $query .= " AND lgc.Difficulty < 0.8";

                            break;

                        case 'medium':

                            $query .= " AND lgc.Difficulty >= 0.8 AND lgc.Difficulty < 1.2";

                            break;

                        case 'hard':

                            $query .= " AND lgc.Difficulty >= 1.2";

                            break;

                    }

                }

 

                $query .= " ORDER BY NEWID()"; // Random order

 

                $stmt = $conn->prepare($query);

                $stmt->execute($params);

                $dbSentences = $stmt->fetchAll(PDO::FETCH_ASSOC);

 

                if (!empty($dbSentences)) {

                    foreach ($dbSentences as $row) {

                        $sentence = [

                            'SentenceID' => (int)$row['SentenceID'],

                            'CorrectSentence' => $row['CorrectSentence'],

                            'Difficulty' => (float)$row['Difficulty'],

                            'Category' => $row['Category'],

                            'GradeLevel' => (int)$row['GradeLevel']

                        ];

 

                        // Try to parse scrambled words from JSON, or generate them

                        $scrambledWords = null;

                        if (!empty($row['ScrambledWordsJSON'])) {

                            $decoded = json_decode($row['ScrambledWordsJSON'], true);

                            if (is_array($decoded)) {

                                $scrambledWords = $decoded;

                            }

                        }

 

                        // Generate scrambled words if not available

                        if ($scrambledWords === null && !empty($row['CorrectSentence'])) {

                            $scrambledWords = generateScrambledWords($row['CorrectSentence']);

                        }

 

                        $sentence['ScrambledWords'] = $scrambledWords;

                        $sentences[] = $sentence;

                    }

                }

            }

        } catch (PDOException $e) {

            // Table doesn't exist or query failed - will fall back to hardcoded sentences

            error_log("LessonGameContent query failed (using fallback): " . $e->getMessage());

        }

    }


        // Fallback disabled for testing

    // If we don't have enough sentences from lesson content, return error

    if (count($sentences) < 1) {

        sendError("No sentences found for this lesson. LessonGameContent table may not exist or has no data.", 404);

        return;

    }

 

    // Log activity

    $activityDetail = $lessonID ? "Started Sentence Scramble for Lesson $lessonID" : "Started Sentence Scramble game";

    logActivity($authUser['studentID'], 'Game', $activityDetail);

 

    sendResponse([

        'success' => true,

        'sentences' => $sentences,

        'total' => count($sentences),

        'lesson_id' => $lessonID ? (int)$lessonID : null

    ]);

 

} catch (PDOException $e) {

    error_log("Get scramble sentences error: " . $e->getMessage());

    sendError("Failed to get sentences", 500, $e->getMessage());

} catch (Exception $e) {

    error_log("Get scramble sentences error: " . $e->getMessage());

    sendError("An error occurred", 500, $e->getMessage());

}

 

/**

 * Generate scrambled words from a sentence

 */

function generateScrambledWords($sentence) {

    // Remove punctuation and split into words

    $cleaned = preg_replace('/[.!?,;:]/', '', $sentence);

    $words = preg_split('/\s+/', trim($cleaned));

 

    $original = $words;

    $attempts = 0;

 

    // Keep shuffling until we get a different order

    do {

        shuffle($words);

        $attempts++;

    } while ($words === $original && $attempts < 10 && count($words) > 1);

 

    return $words;

}

 

/**

 * Get fallback sentences for when database is empty

 */

function getFallbackSentences($gradeLevel = null) {

    $sentences = [

        // Grade 4 sentences

        [

            'SentenceID' => 1001,

            'CorrectSentence' => 'The cat sat on the mat',

            'Difficulty' => 0.5,

            'Category' => 'Simple',

            'GradeLevel' => 4

        ],

        [

            'SentenceID' => 1002,

            'CorrectSentence' => 'She goes to school every day',

            'Difficulty' => 0.6,

            'Category' => 'Simple',

            'GradeLevel' => 4

        ],

        [

            'SentenceID' => 1003,

            'CorrectSentence' => 'The dog runs in the park',

            'Difficulty' => 0.5,

            'Category' => 'Simple',

            'GradeLevel' => 4

        ],

        [

            'SentenceID' => 1004,

            'CorrectSentence' => 'My mother cooks delicious food',

            'Difficulty' => 0.6,

            'Category' => 'Simple',

            'GradeLevel' => 4

        ],

        [

            'SentenceID' => 1005,

            'CorrectSentence' => 'The children play happily together',

            'Difficulty' => 0.7,

            'Category' => 'Simple',

            'GradeLevel' => 4

        ],

 

        // Grade 5 sentences

        [

            'SentenceID' => 1006,

            'CorrectSentence' => 'Maria finished her homework diligently',

            'Difficulty' => 0.8,

            'Category' => 'Compound',

            'GradeLevel' => 5

        ],

        [

            'SentenceID' => 1007,

            'CorrectSentence' => 'The students are reading their books quietly',

            'Difficulty' => 0.9,

            'Category' => 'Compound',

            'GradeLevel' => 5

        ],

        [

            'SentenceID' => 1008,

            'CorrectSentence' => 'The teacher explained the lesson clearly',

            'Difficulty' => 0.8,

            'Category' => 'Compound',

            'GradeLevel' => 5

        ],

        [

            'SentenceID' => 1009,

            'CorrectSentence' => 'We visited the beautiful museum yesterday',

            'Difficulty' => 0.9,

            'Category' => 'Compound',

            'GradeLevel' => 5

        ],

        [

            'SentenceID' => 1010,

            'CorrectSentence' => 'The quick brown fox jumps over the lazy dog',

            'Difficulty' => 1.0,

            'Category' => 'Compound',

            'GradeLevel' => 5

        ],

 

        // Grade 6 sentences

        [

            'SentenceID' => 1011,

            'CorrectSentence' => 'Reading books regularly helps improve vocabulary skills',

            'Difficulty' => 1.2,

            'Category' => 'Complex',

            'GradeLevel' => 6

        ],

        [

            'SentenceID' => 1012,

            'CorrectSentence' => 'My family and I visited the science museum yesterday',

            'Difficulty' => 1.3,

            'Category' => 'Complex',

            'GradeLevel' => 6

        ],

        [

            'SentenceID' => 1013,

            'CorrectSentence' => 'The beautiful butterfly landed gently on the colorful flower',

            'Difficulty' => 1.2,

            'Category' => 'Complex',

            'GradeLevel' => 6

        ],

        [

            'SentenceID' => 1014,

            'CorrectSentence' => 'Learning new words makes reading more enjoyable and interesting',

            'Difficulty' => 1.4,

            'Category' => 'Complex',

            'GradeLevel' => 6

        ],

        [

            'SentenceID' => 1015,

            'CorrectSentence' => 'The hardworking students completed their challenging project successfully',

            'Difficulty' => 1.5,

            'Category' => 'Complex',

            'GradeLevel' => 6

        ]

    ];

 

    // Filter by grade level if specified

    if ($gradeLevel !== null) {

        $gradeLevel = (int)$gradeLevel;

        $sentences = array_filter($sentences, function($s) use ($gradeLevel) {

            return $s['GradeLevel'] == $gradeLevel;

        });

        $sentences = array_values($sentences); // Re-index

    }

 

    return $sentences;

}

?>