<?php

/**

 * Debug endpoint to check student's current ability value

 */

 

header("Content-Type: application/json");

 

require_once __DIR__ . '/src/db.php';

require_once __DIR__ . '/src/auth.php';

 

try {

    // Require authentication

    $authUser = requireAuth();

    $studentID = $authUser['studentID'];

 

    // Get student's current ability and recent sessions

    $stmt = $conn->prepare("

        SELECT

            StudentID,

            FirstName,

            LastName,

            CurrentAbility,

            TotalXP,

            CurrentStreak

        FROM Students

        WHERE StudentID = ?

    ");

    $stmt->execute([$studentID]);

    $student = $stmt->fetch(PDO::FETCH_ASSOC);

 

    // Get recent test sessions

    $stmt = $conn->prepare("

        SELECT TOP 5

            SessionID,

            SessionType,

            InitialTheta,

            FinalTheta,

            TotalQuestions,

            CorrectAnswers,

            AccuracyPercentage,

            StartTime,

            EndTime

        FROM TestSessions

        WHERE StudentID = ?

        ORDER BY StartTime DESC

    ");

    $stmt->execute([$studentID]);

    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

 

    echo json_encode([

        'student' => $student,

        'recent_sessions' => $sessions,

        'diagnosis' => [

            'current_ability' => (float)$student['CurrentAbility'],

            'is_at_ceiling' => (float)$student['CurrentAbility'] >= 3.0,

            'is_at_floor' => (float)$student['CurrentAbility'] <= -3.0,

            'needs_reset' => (float)$student['CurrentAbility'] >= 2.5 || (float)$student['CurrentAbility'] <= -2.5

        ]

    ], JSON_PRETTY_PRINT);

 

} catch (Exception $e) {

    echo json_encode([

        'error' => $e->getMessage()

    ], JSON_PRETTY_PRINT);

}

?>