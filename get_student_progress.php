<?php

/**

 * LiteRise Get Student Progress API

 * POST /api/get_student_progress.php

 *

 * Request Body:

 * {

 *   "student_id": 1

 * }

 *

 * Response:

 * {

 *   "success": true,

 *   "student": {

 *     "StudentID": 1,

 *     "FullName": "John Doe",

 *     "CurrentAbility": 0.75,

 *     "TotalXP": 1200,

 *     "CurrentStreak": 5,

 *     "LongestStreak": 10,

 *     "Classification": "Proficient"

 *   },

 *   "stats": {

 *     "TotalSessions": 15,

 *     "AverageAccuracy": 78.5,

 *     "TotalBadges": 5,

 *     "CompletedLessons": 8

 *   },

 *   "recent_sessions": [...],

 *   "badges": [...]

 * }

 */

 

require_once __DIR__ . '/src/db.php';

require_once __DIR__ . '/src/auth.php';

require_once __DIR__ . '/irt.php';

 

// Require authentication

$authUser = requireAuth();

 

// Get JSON input

$data = getJsonInput();

$studentID = $data['student_id'] ?? null;

 

// Validate student_id

if (!$studentID) {

    sendError("student_id is required", 400);

}

 

// Verify authenticated user

if ($authUser['studentID'] != $studentID) {

    sendError("Unauthorized: Cannot view progress for another student", 403);

}

 

try {

    $irt = new ItemResponseTheory();

 

    // Get student basic info and progress

    $stmt = $conn->prepare("EXEC SP_GetStudentProgress @StudentID = ?");

    $stmt->execute([$studentID]);

    $progress = $stmt->fetch(PDO::FETCH_ASSOC);

 

    if (!$progress) {

        sendError("Student not found", 404);

    }

 

    // Classify current ability

    $currentAbility = (float)$progress['CurrentAbility'];

    $classification = $irt->classifyAbility($currentAbility);

 

    // Get recent sessions

    $stmt = $conn->prepare(

        "SELECT TOP 10

            SessionID,

            SessionType,

            StartTime,

            EndTime,

            TotalQuestions,

            CorrectAnswers,

            AccuracyPercentage,

            InitialTheta,

            FinalTheta,

            IsCompleted

         FROM TestSessions

         WHERE StudentID = ?

         ORDER BY StartTime DESC"

    );

    $stmt->execute([$studentID]);

    $recentSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

 

    // Format sessions

    $formattedSessions = array_map(function($session) {

        return [

            'SessionID' => (int)$session['SessionID'],

            'SessionType' => $session['SessionType'],

            'StartTime' => $session['StartTime'],

            'EndTime' => $session['EndTime'],

            'TotalQuestions' => (int)$session['TotalQuestions'],

            'CorrectAnswers' => (int)$session['CorrectAnswers'],

            'AccuracyPercentage' => (float)$session['AccuracyPercentage'],

            'InitialTheta' => (float)$session['InitialTheta'],

            'FinalTheta' => (float)$session['FinalTheta'],

            'IsCompleted' => (bool)$session['IsCompleted']

        ];

    }, $recentSessions);

 

    // Get earned badges

    $stmt = $conn->prepare(

        "SELECT b.BadgeID, b.BadgeName, b.BadgeDescription, b.BadgeIconURL,

                b.XPReward, sb.DateEarned

         FROM StudentBadges sb

         JOIN Badges b ON sb.BadgeID = b.BadgeID

         WHERE sb.StudentID = ?

         ORDER BY sb.DateEarned DESC"

    );

    $stmt->execute([$studentID]);

    $badges = $stmt->fetchAll(PDO::FETCH_ASSOC);

 

    // Get completed lessons count

    $stmt = $conn->prepare(

        "SELECT COUNT(*) as CompletedLessons

         FROM StudentProgress

         WHERE StudentID = ? AND CompletionStatus = 'Completed'"

    );

    $stmt->execute([$studentID]);

    $lessonStats = $stmt->fetch(PDO::FETCH_ASSOC);

 

    // Prepare response

    $response = [

        'success' => true,

        'student' => [

            'StudentID' => (int)$studentID,

            'FullName' => $progress['FirstName'] . ' ' . $progress['LastName'],

            'FirstName' => $progress['FirstName'],

            'LastName' => $progress['LastName'],

            'CurrentAbility' => round($currentAbility, 3),

            'Classification' => $classification,

            'TotalXP' => (int)$progress['TotalXP'],

            'CurrentStreak' => (int)$progress['CurrentStreak'],

            'LongestStreak' => (int)$progress['LongestStreak']

        ],

        'stats' => [

            'TotalSessions' => (int)$progress['TotalSessions'],

            'AverageAccuracy' => round((float)$progress['AverageAccuracy'], 2),

            'TotalBadges' => (int)$progress['TotalBadges'],

            'CompletedLessons' => (int)$lessonStats['CompletedLessons']

        ],

        'recent_sessions' => $formattedSessions,

        'badges' => $badges

    ];

 

    sendResponse($response, 200);

 

} catch (PDOException $e) {

    error_log("Get student progress error: " . $e->getMessage());

    sendError("Failed to fetch student progress", 500, $e->getMessage());

} catch (Exception $e) {

    error_log("Get student progress error: " . $e->getMessage());

    sendError("An error occurred", 500, $e->getMessage());

}

?>