<?php

/**
 * LiteRise LRN + Login Code API
 * POST /api/login_lrn.php
 *
 * Students sign in using their 12-digit LRN and 4-digit LoginCode (PIN).
 *
 * Request Body:
 * {
 *   "lrn": "136654220148",
 *   "login_code": "1029"
 * }
 *
 * Success Response (same shape as login.php):
 * {
 *   "success": true,
 *   "StudentID": 1,
 *   "FullName": "Juan Dela Cruz",
 *   "LRN": "136654220148",
 *   "email": "...",
 *   "GradeLevel": 3,
 *   ...
 *   "token": "eyJ..."
 * }
 *
 * Error Response:
 * {
 *   "success": false,
 *   "error": "Invalid LRN or login code"
 * }
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError("Method not allowed", 405);
}

// Parse JSON body
$data       = getJsonInput();
$lrn        = trim($data['lrn']        ?? '');
$login_code = trim($data['login_code'] ?? '');

// Validate LRN
if (empty($lrn)) {
    sendError("LRN is required", 400);
}
if (!ctype_digit($lrn) || strlen($lrn) !== 12) {
    sendError("LRN must be a 12-digit number", 400);
}

// Validate login code
if (empty($login_code)) {
    sendError("Login code is required", 400);
}
if (!ctype_digit($login_code) || strlen($login_code) !== 4) {
    sendError("Login code must be a 4-digit number", 400);
}

try {
    // Look up student by LRN + LoginCode
    $stmt = $conn->prepare("
        SELECT
            s.StudentID,
            s.FirstName,
            s.LastName,
            s.Nickname,
            s.Email,
            s.LRN,
            s.LoginCode,
            s.GradeLevel,
            s.Section,
            s.CurrentAbility,
            s.TotalXP,
            s.CurrentStreak,
            s.LongestStreak,
            s.LastLogin,
            s.PreAssessmentCompleted,
            s.AssessmentStatus,
            s.Cat1_PhonicsWordStudy,
            s.Cat2_VocabularyWordKnowledge,
            s.Cat3_GrammarAwareness,
            s.Cat4_ComprehendingText,
            s.Cat5_CreatingComposing
        FROM Students s
        WHERE s.LRN = :lrn
    ");
    $stmt->bindValue(':lrn', $lrn, PDO::PARAM_STR);
    $stmt->execute();

    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    // Use a generic message for both bad LRN and wrong code (security best practice)
    if (!$student || (string)$student['LoginCode'] !== $login_code) {
        sendError("Invalid LRN or login code. Please try again.", 401);
    }

    // Generate JWT — use email if present, otherwise fall back to LRN
    $emailForToken = !empty($student['Email']) ? $student['Email'] : $lrn;
    $token = generateJWT((int)$student['StudentID'], $emailForToken);

    // Build response — identical shape to login.php so the Android app
    // can reuse the same SessionManager.saveStudent() calls
    $response = [
        'success'                    => true,
        'StudentID'                  => (int)$student['StudentID'],
        'FullName'                   => $student['FirstName'] . ' ' . $student['LastName'],
        'FirstName'                  => $student['FirstName'],
        'LastName'                   => $student['LastName'],
        'Nickname'                   => $student['Nickname'] ?? '',
        'LRN'                        => $student['LRN'],
        'email'                      => $student['Email'] ?? '',
        'GradeLevel'                 => (int)$student['GradeLevel'],
        'Section'                    => $student['Section'] ?? '',
        'CurrentAbility'             => (float)$student['CurrentAbility'],
        'AbilityScore'               => (float)$student['CurrentAbility'],
        'TotalXP'                    => (int)$student['TotalXP'],
        'XP'                         => (int)$student['TotalXP'],
        'CurrentStreak'              => (int)$student['CurrentStreak'],
        'LongestStreak'              => (int)$student['LongestStreak'],
        'LastLogin'                  => $student['LastLogin'] ?? null,
        'PreAssessmentCompleted'     => !empty($student['PreAssessmentCompleted']),
        'AssessmentStatus'           => $student['AssessmentStatus'] ?? 'Not Started',
        'Cat1_PhonicsWordStudy'      => (int)($student['Cat1_PhonicsWordStudy'] ?? 0),
        'Cat2_VocabularyWordKnowledge' => (int)($student['Cat2_VocabularyWordKnowledge'] ?? 0),
        'Cat3_GrammarAwareness'      => (int)($student['Cat3_GrammarAwareness'] ?? 0),
        'Cat4_ComprehendingText'     => (int)($student['Cat4_ComprehendingText'] ?? 0),
        'Cat5_CreatingComposing'     => (int)($student['Cat5_CreatingComposing'] ?? 0),
        'token'                      => $token,
    ];

    logActivity((int)$student['StudentID'], 'LRN_Login', 'Student signed in via LRN + LoginCode');

    sendResponse($response, 200);

} catch (PDOException $e) {
    error_log("LRN login error: " . $e->getMessage());
    sendError("Login failed", 500, $e->getMessage());
} catch (Exception $e) {
    error_log("LRN login error: " . $e->getMessage());
    sendError("An error occurred during login", 500, $e->getMessage());
}
?>