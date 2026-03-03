<?php
/**
 * Evaluate Pronunciation API
 * POST /api/evaluate_pronunciation.php
 *
 * Evaluates student's pronunciation using Google Cloud Speech-to-Text API
 * with pronunciation assessment features
 *
 * Request Body (multipart/form-data):
 * {
 *   "student_id": 27,
 *   "item_id": 15,
 *   "response_id": 1234,
 *   "audio_file": <file upload>,
 *   "target_word": "elephant"
 * }
 *
 * Response:
 * {
 *   "success": true,
 *   "score_id": 567,
 *   "pronunciation_result": {
 *     "recognized_text": "elephant",
 *     "confidence": 0.95,
 *     "overall_accuracy": 87,
 *     "pronunciation_score": 0.87,
 *     "fluency_score": 0.92,
 *     "completeness_score": 1.0,
 *     "feedback": "Great job! Your pronunciation is excellent!",
 *     "passed": true
 *   },
 *   "phoneme_details": [
 *     {"phoneme": "ɛ", "accuracy": 0.95},
 *     {"phoneme": "l", "accuracy": 0.88},
 *     {"phoneme": "ə", "accuracy": 0.90}
 *   ]
 * }
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

// Google Cloud Speech-to-Text API imports
use Google\Cloud\Speech\V1\SpeechClient;
use Google\Cloud\Speech\V1\RecognitionAudio;
use Google\Cloud\Speech\V1\RecognitionConfig;
use Google\Cloud\Speech\V1\RecognitionConfig\AudioEncoding;
use Google\Cloud\Speech\V1\SpeechContext;

// Require authentication
$authUser = requireAuth();

// Get form data
$studentID = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
$itemID = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
$responseID = isset($_POST['response_id']) ? (int)$_POST['response_id'] : 0;
$sessionID = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
$targetWord = isset($_POST['target_word']) ? sanitizeInput($_POST['target_word']) : '';

// Validate required fields
if (!$studentID || !$itemID || !$targetWord) {
    sendError('Missing required fields', 400);
}

// Verify user can only submit for themselves
if ($authUser['studentID'] != $studentID) {
    sendError('Unauthorized: Cannot submit pronunciation for another student', 403);
}

// Check if audio file was uploaded
if (!isset($_FILES['audio_file']) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) {
    sendError('Audio file is required', 400);
}

$audioFile = $_FILES['audio_file'];

// Validate file type (accept common audio formats)
$allowedMimeTypes = ['audio/webm', 'audio/wav', 'audio/mp3', 'audio/mpeg', 'audio/ogg', 'audio/flac', 'audio/3gp', 'audio/3gpp', 'audio/amr', 'video/3gpp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $audioFile['tmp_name']);
finfo_close($finfo);

// Log the detected MIME type for debugging
error_log("DEBUG: Detected MIME type: " . $mimeType . " for file: " . $audioFile['name']);

if (!in_array($mimeType, $allowedMimeTypes)) {
    sendError('Invalid audio file format. Detected: ' . $mimeType . '. Supported: WAV, MP3, WebM, OGG, FLAC, 3GP', 400);
}

// Validate file size (max 10MB)
$maxFileSize = 10 * 1024 * 1024; // 10MB
if ($audioFile['size'] > $maxFileSize) {
    sendError('Audio file too large. Maximum size: 10MB', 400);
}

// Get item details for pronunciation assessment
$itemStmt = $conn->prepare("
    SELECT TargetPronunciation, PhoneticTranscription, MinimumAccuracy, DifficultyParam, Category
    FROM dbo.AssessmentItems
    WHERE ItemID = ?
");
$itemStmt->execute([$itemID]);
$item = $itemStmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    sendError('Assessment item not found', 404);
}

$targetPronunciation = $item['TargetPronunciation'] ?? $targetWord;
$minAccuracy = $item['MinimumAccuracy'] ?? 65;
$itemDifficulty = $item['DifficultyParam'] ?? 0.0;
$category = $item['Category'] ?? 'Oral Language';

// =============================================
// Google Cloud Speech-to-Text API
// =============================================
try {
    require_once __DIR__ . '/vendor/autoload.php'; // Google Cloud PHP SDK

    // Check credentials file exists
    $credentialsPath = __DIR__ . '/google-cloud-credentials.json';
    if (!file_exists($credentialsPath)) {
        error_log("ERROR: Google Cloud credentials file not found at: " . $credentialsPath);
        sendError('Speech recognition service not configured. Please contact administrator.', 500);
    }

    // Initialize Google Cloud Speech client
    $speech = new SpeechClient([
        'credentials' => $credentialsPath
    ]);

    // Read audio file
    $audioContent = file_get_contents($audioFile['tmp_name']);
    error_log("INFO: Processing audio file - Size: " . strlen($audioContent) . " bytes, Format: 3GP/AMR");

    // Configure recognition for 3GP (AMR-NB) audio
    $audio = (new RecognitionAudio())->setContent($audioContent);

    // Create speech context with phrase hints to improve recognition
    // Add phonetic variations to help with difficult words like "knife"
    $phraseHints = [$targetPronunciation];

    // Add common phonetic variations for better recognition
    // For "knife" add "nife", for "write" add "rite", etc.
    $phonetic = preg_replace('/^kn/', 'n', $targetPronunciation); // knife -> nife
    $phonetic2 = preg_replace('/^wr/', 'r', $targetPronunciation); // write -> rite
    if ($phonetic !== $targetPronunciation) {
        $phraseHints[] = $phonetic;
    }
    if ($phonetic2 !== $targetPronunciation) {
        $phraseHints[] = $phonetic2;
    }

    $speechContext = (new SpeechContext())
        ->setPhrases($phraseHints)
        ->setBoost(20.0); // Strong boost for expected words

    $config = (new RecognitionConfig())
        ->setEncoding(AudioEncoding::AMR) // 3GP uses AMR-NB encoding
        ->setSampleRateHertz(8000) // AMR-NB sample rate
        ->setLanguageCode('en-US')
        ->setEnableAutomaticPunctuation(false)
        ->setSpeechContexts([$speechContext]) // Help API expect this word
        ->setModel('phone_call') // Optimized for low-quality audio like phone calls
        ->setUseEnhanced(true) // Use enhanced model for better accuracy
        ->setMaxAlternatives(3); // Get top 3 alternatives to check against target

    // Perform speech recognition
    error_log("INFO: Sending audio to Google Cloud Speech API...");
    $response = $speech->recognize($config, $audio);

    $recognizedText = '';
    $confidence = 0.0;
    $pronunciationScore = 0.0;
    $bestMatch = null;
    $bestScore = 0.0;

    // Process results - check all alternatives for best match
    $results = $response->getResults();
    if (count($results) > 0) {
        $alternatives = $results[0]->getAlternatives();

        error_log("INFO: Checking " . count($alternatives) . " speech alternatives");

        // Check each alternative to find the best match to target word
        foreach ($alternatives as $idx => $alternative) {
            $altText = strtolower(trim($alternative->getTranscript()));
            $altConfidence = $alternative->getConfidence();

            error_log("INFO: Alternative " . ($idx + 1) . ": '$altText' (confidence: " . $altConfidence . ")");

            // Calculate score for this alternative
            $altScore = calculatePronunciationScore(
                $altText,
                $targetPronunciation,
                $altConfidence
            );

            // Keep the best matching alternative
            if ($altScore > $bestScore || $bestMatch === null) {
                $bestScore = $altScore;
                $bestMatch = [
                    'text' => $altText,
                    'confidence' => $altConfidence,
                    'score' => $altScore
                ];
            }
        }

        if ($bestMatch !== null) {
            $recognizedText = $bestMatch['text'];
            $confidence = $bestMatch['confidence'];
            $pronunciationScore = $bestMatch['score'];

            error_log("INFO: Best match selected - Recognized: '$recognizedText', Target: '$targetPronunciation', Score: " . $pronunciationScore);
        }
    } else {
        // No speech detected
        error_log("WARNING: No speech detected in audio");
        $recognizedText = '';
        $confidence = 0.0;
        $pronunciationScore = 0.0;
    }

    $speech->close();

    // Calculate overall accuracy
    $overallAccuracy = (int)($pronunciationScore * 100);

} catch (Exception $e) {
    error_log('ERROR: Speech recognition failed - ' . $e->getMessage());
    sendError('Speech recognition failed: ' . $e->getMessage(), 500);
}

// Fluency and completeness scores (would come from API in production)
$fluencyScore = 0.90;
$completenessScore = 1.0;

// Determine if student passed
$passed = ($overallAccuracy >= $minAccuracy);

// Generate feedback message
$feedback = generateFeedback($overallAccuracy, $passed);

// Prepare phoneme details (would come from detailed API response)
$phonemeDetails = generatePhonemeDetails($targetPronunciation, $recognizedText);
$errorPhonemes = implode(',', array_column(array_filter($phonemeDetails, function($p) {
    return $p['accuracy'] < 0.7;
}), 'phoneme'));

// Get audio duration (in milliseconds)
$audioDuration = null; // Would be extracted from audio file metadata

// Build API response JSON (for storing full details)
$apiResponseJSON = json_encode([
    'recognized_text' => $recognizedText,
    'confidence' => $confidence,
    'target' => $targetPronunciation,
    'provider' => 'Fallback', // Change to 'Google Cloud Speech' in production
    'timestamp' => date('c')
]);

// Record pronunciation score in database
try {
    // Create StudentResponse first (required by foreign key constraint)
    // We create it here because pronunciation evaluation is complete
    $insertResponseStmt = $conn->prepare("
        INSERT INTO dbo.StudentResponses
        (StudentID, ItemID, SessionID, AssessmentType, SelectedAnswer, IsCorrect,
         StudentThetaAtTime, ItemDifficulty, QuestionNumber, ResponseTime)
        VALUES (?, ?, ?, 'Pronunciation', ?, ?, 0.0, ?, 1, 0)
    ");

    $insertResponseStmt->execute([
        $studentID,
        $itemID,
        $sessionID, // SessionID from placement test
        $recognizedText, // SelectedAnswer = what was recognized
        $passed ? 1 : 0, // IsCorrect based on pronunciation pass/fail
        $itemDifficulty // ItemDifficulty from AssessmentItems
    ]);

    // Get the actual ResponseID that was created
    $actualResponseID = (int)$conn->lastInsertId();

    if ($actualResponseID === 0) {
        error_log("ERROR: Failed to get lastInsertId after StudentResponse insert");
        throw new Exception("Failed to create StudentResponse record");
    }

    error_log("DEBUG: Created StudentResponse with ResponseID: " . $actualResponseID);

    // Now record the pronunciation score with the valid ResponseID
    $stmt = $conn->prepare("EXEC dbo.SP_RecordPronunciationScore
        @ResponseID = ?,
        @StudentID = ?,
        @ItemID = ?,
        @RecognizedText = ?,
        @Confidence = ?,
        @OverallAccuracy = ?,
        @PronunciationScore = ?,
        @FluencyScore = ?,
        @CompletenessScore = ?,
        @PhonemeAccuracyJSON = ?,
        @ErrorPhonemes = ?,
        @AudioDuration = ?,
        @AudioQuality = ?,
        @APIResponseJSON = ?
    ");

    $stmt->execute([
        $actualResponseID, // Use the actual ResponseID from StudentResponses
        $studentID,
        $itemID,
        $recognizedText,
        $confidence,
        $overallAccuracy,
        $pronunciationScore,
        $fluencyScore,
        $completenessScore,
        json_encode($phonemeDetails),
        $errorPhonemes,
        $audioDuration,
        0.8, // Audio quality placeholder
        $apiResponseJSON
    ]);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $scoreID = $result['ScoreID'] ?? 0;

    // Return success response with response_id so app knows not to call submit_answer again
    sendResponse([
        'score_id' => $scoreID,
        'response_id' => $actualResponseID, // StudentResponse record already created
        'pronunciation_result' => [
            'recognized_text' => $recognizedText,
            'confidence' => $confidence,
            'overall_accuracy' => $overallAccuracy,
            'pronunciation_score' => $pronunciationScore,
            'fluency_score' => $fluencyScore,
            'completeness_score' => $completenessScore,
            'feedback' => $feedback,
            'passed' => $passed,
            'minimum_accuracy' => $minAccuracy
        ],
        'phoneme_details' => $phonemeDetails
    ]);

} catch (PDOException $e) {
    error_log('Database error in pronunciation scoring: ' . $e->getMessage());
    $errorDetails = ($_ENV['DEBUG_MODE'] ?? 'false') === 'true' ? $e->getMessage() : null;
    sendError('Failed to record pronunciation score: ' . $e->getMessage(), 500, $errorDetails);
}

// =============================================
// Helper Functions
// =============================================

/**
 * Calculate pronunciation score based on text match and confidence
 * Uses multiple metrics for accurate pronunciation assessment
 */
function calculatePronunciationScore($recognized, $target, $confidence) {
    $recognized = strtolower(trim($recognized));
    $target = strtolower(trim($target));

    // No speech detected
    if (empty($recognized)) {
        return 0.0;
    }

    // Exact match gets excellent score
    if ($recognized === $target) {
        // Return high score (at least 0.95) for exact matches
        return max($confidence, 0.95);
    }

    // Check if target word is contained in recognized text
    // (e.g., "the cat" contains "cat")
    if (strpos($recognized, $target) !== false || strpos($target, $recognized) !== false) {
        // Partial containment - good score
        return max($confidence * 0.9, 0.85);
    }

    // Calculate similarity using Levenshtein distance
    $maxLen = max(strlen($recognized), strlen($target));
    if ($maxLen === 0) return 0.0;

    $distance = levenshtein($recognized, $target);
    $textSimilarity = 1.0 - ($distance / $maxLen);

    // Calculate similar_text percentage for additional comparison
    $similarTextPercent = 0;
    similar_text($recognized, $target, $similarTextPercent);
    $similarTextScore = $similarTextPercent / 100.0;

    // Combine multiple similarity metrics with confidence
    // - Text similarity (Levenshtein): 40%
    // - Similar text percentage: 30%
    // - Speech recognition confidence: 30%
    $combinedScore = (
        $textSimilarity * 0.4 +
        $similarTextScore * 0.3 +
        $confidence * 0.3
    );

    // Ensure minimum score for close attempts
    // If confidence is high but text doesn't match, student may have mispronounced
    if ($confidence > 0.8 && $textSimilarity < 0.5) {
        // High confidence but wrong word - give partial credit
        $combinedScore = max($combinedScore, 0.4);
    }

    // Ensure score is between 0 and 1
    return max(0.0, min(1.0, $combinedScore));
}

/**
 * Generate feedback message based on accuracy
 */
function generateFeedback($accuracy, $passed) {
    if ($accuracy >= 95) {
        return "Perfect! Your pronunciation is excellent! 🌟";
    } elseif ($accuracy >= 85) {
        return "Great job! Your pronunciation is very good! 🎉";
    } elseif ($accuracy >= 75) {
        return "Good work! Keep practicing to improve! 👍";
    } elseif ($accuracy >= 65) {
        return "Nice try! You're getting better! 💪";
    } else {
        return "Keep practicing! Try again! 🔄";
    }
}

/**
 * Generate simulated phoneme details
 * In production, this would come from Google Cloud Speech API's detailed response
 */
function generatePhonemeDetails($target, $recognized) {
    // This is a placeholder - actual phoneme analysis requires speech API
    // For now, return empty array or basic analysis

    $phonemes = [];

    // Simple character-by-character comparison
    $targetChars = str_split(strtolower($target));
    $recognizedChars = str_split(strtolower($recognized));

    for ($i = 0; $i < count($targetChars); $i++) {
        $accuracy = 1.0;

        if (!isset($recognizedChars[$i]) || $recognizedChars[$i] !== $targetChars[$i]) {
            $accuracy = 0.6; // Lower accuracy for mismatch
        }

        $phonemes[] = [
            'phoneme' => $targetChars[$i],
            'accuracy' => $accuracy
        ];
    }

    return $phonemes;
}