<?php

/**

 * check_pronunciation.php

 *

 * Receives pronunciation attempt and validates it against the expected word

 *

 * Request (POST JSON):

 * {

 *   "item_id": 12,

 *   "expected_word": "education",

 *   "recognized_text": "education",

 *   "confidence": 0.95

 * }

 *

 * Response:

 * {

 *   "success": true,

 *   "score": 95,

 *   "feedback": "Excellent pronunciation!",

 *   "is_correct": true,

 *   "expected": "education",

 *   "recognized": "education"

 * }

 */

 

header("Access-Control-Allow-Origin: *");

header("Access-Control-Allow-Methods: POST, OPTIONS");

header("Access-Control-Allow-Headers: Content-Type, Authorization");

header("Content-Type: application/json");

 

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

    http_response_code(200);

    exit();

}

 

require_once __DIR__ . '/src/db.php';

require_once __DIR__ . '/src/auth.php';

 

try {

    // Require authentication

    $user = requireAuth();

 

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

        sendError('Only POST method is allowed', 405);

    }

 

    $data = getJsonInput();

 

    // Validate required fields

    validateRequired($data, ['item_id', 'expected_word', 'recognized_text']);

 

    $itemId = (int)$data['item_id'];

    $expectedWord = strtolower(trim($data['expected_word']));

    $recognizedText = strtolower(trim($data['recognized_text']));

    $confidence = isset($data['confidence']) ? (float)$data['confidence'] : 0.0;

 

    // Calculate pronunciation score using similarity algorithms

    $score = calculatePronunciationScore($expectedWord, $recognizedText, $confidence);

 

    // Determine if pronunciation is correct (threshold: 70%)

    $isCorrect = $score >= 70;

 

    // Generate feedback based on score

    $feedback = generatePronunciationFeedback($score);

 

    // Log the pronunciation attempt

  logPronunciationAttempt($conn, $user['studentID'], $itemId, $expectedWord, $recognizedText, $score);

 

    // Return result

    sendResponse([

        'score' => $score,

        'feedback' => $feedback,

        'is_correct' => $isCorrect,

        'expected' => $expectedWord,

        'recognized' => $recognizedText,

        'confidence' => $confidence

    ]);

 

} catch (Exception $e) {

    sendError($e->getMessage(), 500);

}

 

/**

 * Calculate pronunciation score based on similarity between expected and recognized text

 */

function calculatePronunciationScore($expected, $recognized, $confidence) {

    // Exact match gets 100

    if ($expected === $recognized) {

        return 100;

    }

 

    // Calculate Levenshtein distance (edit distance)

    $distance = levenshtein($expected, $recognized);

    $maxLength = max(strlen($expected), strlen($recognized));

 

    // Calculate similarity percentage

    $similarity = (1 - ($distance / $maxLength)) * 100;

 

    // Factor in speech recognition confidence

    $confidenceWeight = 0.3; // 30% weight for confidence

    $similarityWeight = 0.7;  // 70% weight for similarity

 

    $score = ($similarity * $similarityWeight) + ($confidence * 100 * $confidenceWeight);

 

    // Ensure score is between 0 and 100

    $score = max(0, min(100, $score));

 

    return round($score);

}

 

/**

 * Generate feedback message based on pronunciation score

 */

function generatePronunciationFeedback($score) {

    if ($score >= 95) {

        return "Excellent pronunciation! Perfect!";

    } elseif ($score >= 85) {

        return "Great job! Your pronunciation is very good.";

    } elseif ($score >= 70) {

        return "Good effort! Your pronunciation is acceptable.";

    } elseif ($score >= 50) {

        return "Keep practicing! Try to pronounce more clearly.";

    } else {

        return "Needs improvement. Listen carefully and try again.";

    }

}

 

/**

 * Log pronunciation attempt to database

 */

function logPronunciationAttempt($conn, $studentId, $itemId, $expected, $recognized, $score) {

    try {

        $sql = "INSERT INTO PronunciationAttempts

                (StudentID, ItemID, ExpectedWord, RecognizedText, Score, AttemptDate)

                VALUES (:student_id, :item_id, :expected, :recognized, :score, GETDATE())";

 

        $stmt = $conn->prepare($sql);

        $stmt->bindParam(':student_id', $studentId, PDO::PARAM_INT);

        $stmt->bindParam(':item_id', $itemId, PDO::PARAM_INT);

        $stmt->bindParam(':expected', $expected, PDO::PARAM_STR);

        $stmt->bindParam(':recognized', $recognized, PDO::PARAM_STR);

        $stmt->bindParam(':score', $score, PDO::PARAM_INT);

 

        $stmt->execute();

    } catch (PDOException $e) {

        // Log error but don't fail the request

        error_log("Failed to log pronunciation attempt: " . $e->getMessage());

    }

}