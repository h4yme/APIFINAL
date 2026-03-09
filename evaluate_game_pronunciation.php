<?php
/**
 * Evaluate Game Pronunciation API
 * POST /api/evaluate_game_pronunciation.php
 *
 * Lightweight pronunciation evaluation for in-game activities.
 * Uses Google Cloud Speech-to-Text — no placement-test DB recording.
 *
 * Multipart/form-data:
 *   student_id  int
 *   target_word string  (single word or short phrase to match)
 *   audio_file  file    (.3gp / AMR-NB recorded by PronunciationHelper)
 *
 * Response:
 * {
 *   "success": true,
 *   "recognized_text": "frog",
 *   "accuracy": 87,
 *   "passed": true,
 *   "feedback": "Great job! Keep it up! 🎉"
 * }
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

use Google\Cloud\Speech\V1\SpeechClient;
use Google\Cloud\Speech\V1\RecognitionAudio;
use Google\Cloud\Speech\V1\RecognitionConfig;
use Google\Cloud\Speech\V1\RecognitionConfig\AudioEncoding;
use Google\Cloud\Speech\V1\SpeechContext;

// ── Auth ─────────────────────────────────────────────────────────────────────
$authUser  = requireAuth();
$studentID = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
$targetWord = isset($_POST['target_word']) ? trim($_POST['target_word']) : '';

if (!$studentID || $targetWord === '') {
    sendError('Missing student_id or target_word', 400);
}
if ((string)$authUser['studentID'] !== (string)$studentID) {
    sendError('Unauthorized', 403);
}

// ── Audio file ────────────────────────────────────────────────────────────────
if (!isset($_FILES['audio_file']) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) {
    sendError('audio_file is required', 400);
}
$audioFile = $_FILES['audio_file'];
if ($audioFile['size'] > 10 * 1024 * 1024) {
    sendError('Audio file too large (max 10 MB)', 400);
}

// ── Google Cloud Speech ───────────────────────────────────────────────────────
try {
    require_once __DIR__ . '/vendor/autoload.php';

    $credPath = __DIR__ . '/google-cloud-credentials.json';
    if (!file_exists($credPath)) {
        sendError('Speech service not configured on server', 500);
    }

    $speech       = new SpeechClient(['credentials' => $credPath]);
    $audioContent = file_get_contents($audioFile['tmp_name']);
    $isPassage    = str_word_count($targetWord) > 3;

    // Build phrase hints
    $phraseHints = [];
    if (!$isPassage) {
        $phraseHints[] = $targetWord;
        $phonetic = preg_replace('/^kn/', 'n', $targetWord);
        $phonetic2 = preg_replace('/^wr/', 'r', $targetWord);
        if ($phonetic !== $targetWord)  $phraseHints[] = $phonetic;
        if ($phonetic2 !== $targetWord) $phraseHints[] = $phonetic2;
    } else {
        foreach (explode(' ', $targetWord) as $w) {
            $w = trim(preg_replace('/[^a-zA-Z\'-]/', '', $w));
            if ($w !== '' && strlen($w) <= 100) $phraseHints[] = $w;
        }
    }

    $speechContext = (new SpeechContext())
        ->setPhrases($phraseHints)
        ->setBoost($isPassage ? 10.0 : 20.0);

    $config = (new RecognitionConfig())
        ->setEncoding(AudioEncoding::AMR)
        ->setSampleRateHertz(8000)
        ->setLanguageCode('en-US')
        ->setEnableAutomaticPunctuation(false)
        ->setSpeechContexts([$speechContext])
        ->setModel('phone_call')
        ->setUseEnhanced(true)
        ->setMaxAlternatives($isPassage ? 1 : 3);

    $audio    = (new RecognitionAudio())->setContent($audioContent);
    $response = $speech->recognize($config, $audio);
    $results  = $response->getResults();

    $recognizedText    = '';
    $confidence        = 0.0;
    $pronunciationScore = 0.0;

    if (count($results) > 0) {
        if ($isPassage) {
            $segs     = [];
            $totalConf = 0.0;
            $segCount  = 0;
            foreach ($results as $seg) {
                $alts = $seg->getAlternatives();
                if (count($alts) > 0) {
                    $segs[]     = strtolower(trim($alts[0]->getTranscript()));
                    $totalConf += $alts[0]->getConfidence();
                    $segCount++;
                }
            }
            $recognizedText = implode(' ', $segs);
            $confidence     = $segCount > 0 ? $totalConf / $segCount : 0.0;
            if ($confidence === 0.0 && !empty($recognizedText)) $confidence = 0.80;
            $pronunciationScore = gamePronScore($recognizedText, $targetWord, $confidence, true);
        } else {
            $bestScore = 0.0;
            $bestMatch = null;
            foreach ($results[0]->getAlternatives() as $alt) {
                $altText = strtolower(trim($alt->getTranscript()));
                $altConf = $alt->getConfidence();
                $s = gamePronScore($altText, $targetWord, $altConf, false);
                if ($s > $bestScore || $bestMatch === null) {
                    $bestScore = $s;
                    $bestMatch = ['text' => $altText, 'confidence' => $altConf, 'score' => $s];
                }
            }
            if ($bestMatch !== null) {
                $recognizedText    = $bestMatch['text'];
                $confidence        = $bestMatch['confidence'];
                $pronunciationScore = $bestMatch['score'];
            }
        }
    }

    $speech->close();

    $accuracy = (int)($pronunciationScore * 100);
    $passed   = $accuracy >= 65;

    sendResponse([
        'recognized_text' => $recognizedText,
        'accuracy'        => $accuracy,
        'passed'          => $passed,
        'feedback'        => gameFeedback($accuracy),
    ]);

} catch (Exception $e) {
    error_log('evaluate_game_pronunciation error: ' . $e->getMessage());
    sendError('Speech recognition failed: ' . $e->getMessage(), 500);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Map digit characters/strings to their English word equivalents and vice-versa
 * so that "8" and "eight" (and similar pairs) are treated as the same token.
 */
function normalizeNumbers($text) {
    // Always normalize to word form so "8" and "eight" both become "eight".
    static $digitToWord = [
        '0' => 'zero',  '1' => 'one',   '2' => 'two',   '3' => 'three',
        '4' => 'four',  '5' => 'five',  '6' => 'six',   '7' => 'seven',
        '8' => 'eight', '9' => 'nine',  '10' => 'ten',  '11' => 'eleven',
        '12' => 'twelve', '13' => 'thirteen', '14' => 'fourteen',
        '15' => 'fifteen', '16' => 'sixteen', '17' => 'seventeen',
        '18' => 'eighteen', '19' => 'nineteen', '20' => 'twenty',
    ];

    $tokens = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($tokens as &$tok) {
        if (isset($digitToWord[$tok])) {
            $tok = $digitToWord[$tok];
        }
    }
    return implode('', $tokens);
}

function gamePronScore($recognized, $target, $confidence, $isPassage) {
    $recognized = strtolower(trim($recognized));
    $target     = strtolower(trim($target));

    // Normalize numeric tokens so "8" == "eight", "2" == "two", etc.
    $recognized = normalizeNumbers($recognized);
    $target     = normalizeNumbers($target);
    if (empty($recognized)) return 0.0;

    if ($isPassage) {
        $ct = preg_replace('/[^a-z\s]/i', '', $target);
        $cr = preg_replace('/[^a-z\s]/i', '', $recognized);
        $tw = array_values(array_filter(preg_split('/\s+/', $ct)));
        $rw = array_values(array_filter(preg_split('/\s+/', $cr)));
        if (count($tw) === 0) return 0.0;
        $bag  = array_count_values($rw);
        $hits = 0;
        foreach ($tw as $w) {
            if (isset($bag[$w]) && $bag[$w] > 0) { $hits++; $bag[$w]--; continue; }
            if (strlen($w) >= 4) {
                foreach ($bag as $r => $c) {
                    if ($c > 0 && levenshtein($w, $r) <= 1) { $hits++; $bag[$r]--; break; }
                }
            }
        }
        $eff = ($confidence > 0.0) ? $confidence : 0.80;
        return max(0.0, min(1.0, ($hits / count($tw)) * 0.85 + $eff * 0.15));
    }

    // Single word
    if ($recognized === $target) return max($confidence, 0.95);
    if (strpos($recognized, $target) !== false || strpos($target, $recognized) !== false)
        return max($confidence * 0.9, 0.85);

    $maxLen = max(strlen($recognized), strlen($target));
    if ($maxLen === 0) return 0.0;
    $lev = 1.0 - (levenshtein($recognized, $target) / $maxLen);
    similar_text($recognized, $target, $sim);
    return max(0.0, min(1.0, $lev * 0.4 + ($sim / 100.0) * 0.3 + $confidence * 0.3));
}

function gameFeedback($accuracy) {
    if ($accuracy >= 90) return "Excellent! Perfect pronunciation! 🌟";
    if ($accuracy >= 75) return "Great job! Keep it up! 🎉";
    if ($accuracy >= 65) return "Good! You passed! 👍";
    if ($accuracy >= 45) return "Almost there! Try again! 💪";
    return "Speak clearly and try again! 🎤";
}