<?php

/**

 * LiteRise Save Game Result API

 * POST /api/save_game_results.php

 *

 * Saves game results and awards XP/streaks

 *

 * Request Body:

 * {

 *   "session_id": 123,        // Optional - test session ID

 *   "student_id": 1,          // Required

 *   "game_type": "SentenceScramble",

 *   "score": 850,

 *   "accuracy_percentage": 85.0,

 *   "time_completed": 120,    // seconds

 *   "xp_earned": 100,

 *   "streak_achieved": 7,

 *   "lesson_id": 1            // Optional - lesson this game was played for

 * }

 *

 * Response:

 * {

 *   "success": true,

 *   "message": "Game result saved successfully",

 *   "game_result_id": 456,

 *   "student": {

 *     "TotalXP": 1300,

 *     "CurrentStreak": 7,

 *     "LongestStreak": 10

 *   },

 *   "badges_unlocked": []

 * }

 */

 

require_once __DIR__ . '/src/db.php';

require_once __DIR__ . '/src/auth.php';

 

// Require authentication

$authUser = requireAuth();

 

// Get JSON input

$data = getJsonInput();

$sessionID = $data['session_id'] ?? null;

$studentID = $data['student_id'] ?? null;

$gameType = $data['game_type'] ?? '';

$score = $data['score'] ?? 0;

$accuracyPercentage = $data['accuracy_percentage'] ?? 0.0;

$timeCompleted = $data['time_completed'] ?? 0;

$xpEarned = $data['xp_earned'] ?? 0;

$streakAchieved = $data['streak_achieved'] ?? 0;

$lessonID = $data['lesson_id'] ?? null;

 

// Validate required fields (session_id is now optional)

validateRequired($data, ['student_id', 'game_type', 'score']);

 

// Verify authenticated user

if ($authUser['studentID'] != $studentID) {

    sendError("Unauthorized: Cannot save game result for another student", 403);

}

 

// Validate game type

$validGameTypes = ['SentenceScramble', 'TimedTrail', 'WordHunt', 'ShadowRead', 'MinimalPairs'];

if (!in_array($gameType, $validGameTypes)) {

    sendError("Invalid game_type. Must be: " . implode(', ', $validGameTypes), 400);

}

 

try {

    // Start transaction

    $conn->beginTransaction();

 

    // If session_id provided, verify it exists and belongs to student

    if ($sessionID !== null) {

        $stmt = $conn->prepare(

            "SELECT StudentID, SessionType FROM TestSessions WHERE SessionID = ?"

        );

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

    }

 

    // Call stored procedure to save game result

    $stmt = $conn->prepare(

        "EXEC SP_SaveGameResult

         @SessionID = ?,

         @StudentID = ?,

         @GameType = ?,

         @Score = ?,

         @AccuracyPercentage = ?,

         @TimeCompleted = ?,

         @XPEarned = ?,

         @StreakAchieved = ?,

         @LessonID = ?"

    );

    $stmt->execute([

        $sessionID,

        $studentID,

        $gameType,

        $score,

        $accuracyPercentage,

        $timeCompleted,

        $xpEarned,

        $streakAchieved,

        $lessonID

    ]);

 

    // Get the game result ID

    $stmt = $conn->prepare(

        "SELECT TOP 1 GameResultID

         FROM GameResults

         WHERE StudentID = ?

         ORDER BY DatePlayed DESC"

    );

    $stmt->execute([$studentID]);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $gameResultID = $result['GameResultID'] ?? null;

 

    // Get updated student stats

    $stmt = $conn->prepare(

        "SELECT TotalXP, CurrentStreak, LongestStreak

         FROM Students

         WHERE StudentID = ?"

    );

    $stmt->execute([$studentID]);

    $studentStats = $stmt->fetch(PDO::FETCH_ASSOC);

 

    // Check for badge unlocks (if stored procedure exists)

    $unlockedBadges = [];

    try {

        $stmt = $conn->prepare("EXEC SP_CheckBadgeUnlock @StudentID = ?");

        $stmt->execute([$studentID]);

        $unlockedBadges = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {

        // Badge check failed - not critical, continue

        error_log("Badge check failed: " . $e->getMessage());

    }

 

    // Mark session as completed if session_id was provided

    if ($sessionID !== null) {

        $stmt = $conn->prepare(

            "UPDATE TestSessions

             SET IsCompleted = 1, EndTime = GETDATE()

             WHERE SessionID = ?"

        );

        $stmt->execute([$sessionID]);

    }

 

    // Commit transaction

    $conn->commit();

 

    // Log activity

    $lessonInfo = $lessonID ? " (Lesson: $lessonID)" : "";

    logActivity(

        $studentID,

        'GameComplete',

        "Completed $gameType$lessonInfo - Score: $score, Accuracy: $accuracyPercentage%, XP: +$xpEarned"

    );

 

    $response = [

        'success' => true,

        'message' => 'Game result saved successfully',

        'game_result_id' => (int)$gameResultID,

        'student' => [

            'TotalXP' => (int)($studentStats['TotalXP'] ?? 0),

            'CurrentStreak' => (int)($studentStats['CurrentStreak'] ?? 0),

            'LongestStreak' => (int)($studentStats['LongestStreak'] ?? 0)

        ],

        'badges_unlocked' => $unlockedBadges

    ];

 

    sendResponse($response, 201);

 

} catch (PDOException $e) {

    if ($conn->inTransaction()) {

        $conn->rollBack();

    }

    error_log("Save game result error: " . $e->getMessage());

    sendError("Failed to save game result", 500, $e->getMessage());

} catch (Exception $e) {

    if ($conn->inTransaction()) {

        $conn->rollBack();

    }

    error_log("Save game result error: " . $e->getMessage());

    sendError("An error occurred", 500, $e->getMessage());

}

?>