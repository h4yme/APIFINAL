<?php

/**

 * LiteRise Get Lesson Progress API

 * GET /api/get_lesson_progress.php?student_id=X

 * GET /api/get_lesson_progress.php?student_id=X&lesson_id=Y

 *

 * Returns lesson progress for a student with detailed stats for completed lessons

 */

 

require_once __DIR__ . '/src/db.php';

 

try {

    $studentID = (int)($_GET['student_id'] ?? 0);

    $lessonID = isset($_GET['lesson_id']) ? (int)$_GET['lesson_id'] : null;

 

    if ($studentID <= 0) {

        sendError("student_id is required", 400);

        exit;

    }

 

    // Get student info

    $studentSql = "SELECT TotalXP, CurrentStreak, LongestStreak, GradeLevel FROM Students WHERE StudentID = ?";

    $studentStmt = $conn->prepare($studentSql);

    $studentStmt->execute([$studentID]);

    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

 

    if (!$student) {

        sendError("Student not found", 404);

        exit;

    }

 

    $lessons = [];

 

    if ($lessonID !== null) {

        // Get detailed progress for specific lesson

        $sql = "SELECT

                    sp.LessonID,

                    sp.CompletionStatus,

                    sp.Score,

                    sp.LastAttemptDate,

                    l.LessonTitle,

                    l.LessonType

                FROM StudentProgress sp

                LEFT JOIN Lessons l ON sp.LessonID = l.LessonID

                WHERE sp.StudentID = ? AND sp.LessonID = ?";

 

        $stmt = $conn->prepare($sql);

        $stmt->execute([$studentID, $lessonID]);

        $progress = $stmt->fetch(PDO::FETCH_ASSOC);

 

       // Count UNIQUE game types played (not total plays)

        $uniqueGamesSql = "SELECT COUNT(DISTINCT GameType) as unique_games_played

                          FROM GameResults

                          WHERE StudentID = ? AND LessonID = ?";

        $uniqueStmt = $conn->prepare($uniqueGamesSql);

        $uniqueStmt->execute([$studentID, $lessonID]);

        $uniqueGames = $uniqueStmt->fetch(PDO::FETCH_ASSOC);

        $gamesPlayed = (int)($uniqueGames['unique_games_played'] ?? 0);

 

        // Get overall stats for this lesson

        $statsSql = "SELECT

                        ISNULL(SUM(XPEarned), 0) as total_xp_earned,

                        ISNULL(AVG(AccuracyPercentage), 0) as average_accuracy,

                        ISNULL(SUM(TimeCompleted), 0) as total_time_seconds,

                        ISNULL(AVG(TimeCompleted), 0) as average_time_seconds,

                        MAX(Score) as best_score,

                        MIN(DatePlayed) as first_played,

                        MAX(DatePlayed) as last_played

                    FROM GameResults

                    WHERE StudentID = ? AND LessonID = ?";

 

        $statsStmt = $conn->prepare($statsSql);

        $statsStmt->execute([$studentID, $lessonID]);

        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

 

        // Get per-game stats (best attempt for each game type)

        $perGameSql = "SELECT

                        GameType,

                        MAX(Score) as best_score,

                        MAX(XPEarned) as xp_earned,

                        MAX(AccuracyPercentage) as accuracy,

                        MIN(TimeCompleted) as best_time,

                        MAX(DatePlayed) as date_played

                      FROM GameResults

                      WHERE StudentID = ? AND LessonID = ?

                      GROUP BY GameType";

        $perGameStmt = $conn->prepare($perGameSql);

        $perGameStmt->execute([$studentID, $lessonID]);

        $perGameResults = $perGameStmt->fetchAll(PDO::FETCH_ASSOC);

 

        // Build games_completed map

        $gamesCompleted = [];

        foreach ($perGameResults as $game) {

            $gamesCompleted[$game['GameType']] = [

                'best_score' => (int)$game['best_score'],

                'xp_earned' => (int)$game['xp_earned'],

                'accuracy' => round((float)$game['accuracy'], 1),

                'best_time' => (int)$game['best_time'],

                'date_played' => $game['date_played']

            ];

        }

 

        $totalGamesRequired = 5;

        $progressPercent = min(100, (int)(($gamesPlayed / $totalGamesRequired) * 100));

 

        // Determine completion status

        $completionStatus = 'NotStarted';

        if ($progress) {

            $completionStatus = $progress['CompletionStatus'];

        } elseif ($gamesPlayed > 0) {

            $completionStatus = $gamesPlayed >= 5 ? 'Completed' : 'InProgress';

        }

 

        $lessons[] = [

            'lesson_id' => $lessonID,

            'lesson_title' => $progress['LessonTitle'] ?? 'Lesson ' . $lessonID,

            'lesson_type' => $progress['LessonType'] ?? 'reading',

            'completion_status' => $completionStatus,

            'score' => (float)($progress['Score'] ?? $stats['average_accuracy'] ?? 0),

            'games_played' => $gamesPlayed,

            'progress_percent' => $progressPercent,

            'last_attempt' => $progress['LastAttemptDate'] ?? $stats['last_played'],

            // Detailed stats

            'total_xp_earned' => (int)($stats['total_xp_earned'] ?? 0),

            'average_accuracy' => round((float)($stats['average_accuracy'] ?? 0), 1),

            'total_time_seconds' => (int)($stats['total_time_seconds'] ?? 0),

            'average_time_seconds' => (int)($stats['average_time_seconds'] ?? 0),

            'best_score' => (int)($stats['best_score'] ?? 0),

            'first_played' => $stats['first_played'],

            'last_played' => $stats['last_played'],

            // Per-game completion status

            'games_completed' => $gamesCompleted

        ];

    } else {

        // Get progress for all lessons (1-6)

        for ($i = 1; $i <= 6; $i++) {

           // Count UNIQUE game types played (not total plays)

            $uniqueGamesSql = "SELECT COUNT(DISTINCT GameType) as unique_games_played

                              FROM GameResults

                              WHERE StudentID = ? AND LessonID = ?";

            $uniqueStmt = $conn->prepare($uniqueGamesSql);

            $uniqueStmt->execute([$studentID, $i]);

            $uniqueGames = $uniqueStmt->fetch(PDO::FETCH_ASSOC);

            $gamesPlayed = (int)($uniqueGames['unique_games_played'] ?? 0);

 

            // Get overall stats

            $statsSql = "SELECT

                            ISNULL(SUM(XPEarned), 0) as total_xp_earned,

                            ISNULL(AVG(AccuracyPercentage), 0) as average_accuracy

                        FROM GameResults

                        WHERE StudentID = ? AND LessonID = ?";

 

            $statsStmt = $conn->prepare($statsSql);

            $statsStmt->execute([$studentID, $i]);

            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

 

            // Get student progress record

            $sql = "SELECT CompletionStatus, Score, LastAttemptDate

                    FROM StudentProgress

                    WHERE StudentID = ? AND LessonID = ?";

 

            $stmt = $conn->prepare($sql);

            $stmt->execute([$studentID, $i]);

            $progress = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalGamesRequired = 5;

            $progressPercent = min(100, (int)(($gamesPlayed / $totalGamesRequired) * 100));

 

            // Determine completion status

            $completionStatus = 'NotStarted';

            if ($progress) {

                $completionStatus = $progress['CompletionStatus'];

            } elseif ($gamesPlayed > 0) {

                $completionStatus = $gamesPlayed >= 5 ? 'Completed' : 'InProgress';

            }

 

            $lessons[] = [

                'lesson_id' => $i,

                'lesson_title' => 'Lesson ' . $i,

                'completion_status' => $completionStatus,

                'score' => (float)($progress['Score'] ?? $stats['average_accuracy'] ?? 0),

                'games_played' => $gamesPlayed,

                'progress_percent' => $progressPercent,

                'total_xp_earned' => (int)($stats['total_xp_earned'] ?? 0),

                'average_accuracy' => round((float)($stats['average_accuracy'] ?? 0), 1),

                'last_attempt' => $progress['LastAttemptDate'] ?? null

            ];

        }

    }

 

    sendResponse([

        'success' => true,

        'student' => [

            'total_xp' => (int)($student['TotalXP'] ?? 0),

            'current_streak' => (int)($student['CurrentStreak'] ?? 0),

            'longest_streak' => (int)($student['LongestStreak'] ?? 0),

            'grade_level' => (int)($student['GradeLevel'] ?? 4)

        ],

        'lessons' => $lessons

    ]);

 

} catch (PDOException $e) {

    error_log("Get lesson progress error: " . $e->getMessage());

    sendError("Database error: " . $e->getMessage(), 500);

} catch (Exception $e) {

    error_log("Get lesson progress error: " . $e->getMessage());

    sendError("Error: " . $e->getMessage(), 500);

}

?>