<?php

/**
 * LiteRise Update Quiz Score API
 *
 * POST /api/update_quiz_score.php
 *
 * Updates quiz score and determines branching decision based on thresholds
 * - Score < InterventionThreshold (60%): Unlock intervention branch
 * - Score >= EnrichmentThreshold (85%): Unlock enrichment branch
 * - Otherwise: Proceed to game normally
 *
 * Request Body:
 * {
 *   "student_id": 1,
 *   "lesson_id": 101,
 *   "quiz_score": 75,
 *   "time_spent": 180,
 *   "total_questions": 10,
 *   "correct_answers": 7
 * }
 *
 * Response:
 * {
 *   "success": true,
 *   "decision": "proceed_standard",
 *   "message": "Great job! You scored 75%. Move on to the game!",
 *   "quiz_score": 75,
 *   "next_step": "game",
 *   "unlocked_branches": [],
 *   "xp_awarded": 15,
 *   "lesson_locked": false
 * }
 *
 * OR for intervention:
 * {
 *   "success": true,
 *   "decision": "intervention_required",
 *   "message": "You scored 45%. Let's review this lesson together!",
 *   "quiz_score": 45,
 *   "next_step": "intervention",
 *   "unlocked_branches": [
 *     {
 *       "BranchID": 1,
 *       "BranchType": "intervention",
 *       "Title": "Sight Words Review",
 *       "Description": "Practice the words we just learned"
 *     }
 *   ],
 *   "xp_awarded": 5,
 *   "lesson_locked": true
 * }
 *
 * OR for enrichment:
 * {
 *   "success": true,
 *   "decision": "enrichment_unlocked",
 *   "message": "Excellent! You scored 90%. Try the enrichment challenge!",
 *   "quiz_score": 90,
 *   "next_step": "game",
 *   "unlocked_branches": [
 *     {
 *       "BranchID": 2,
 *       "BranchType": "enrichment",
 *       "Title": "Advanced Sight Words",
 *       "Description": "Challenge yourself with Key Stage 2 words"
 *     }
 *   ],
 *   "xp_awarded": 25,
 *   "lesson_locked": false
 * }
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

// Require authentication
$authUser = requireAuth();

// Get JSON input
$data = getJsonInput();
$studentID = $data['student_id'] ?? null;
$lessonID = $data['lesson_id'] ?? null;
$quizScore = $data['quiz_score'] ?? null;
$timeSpent = $data['time_spent'] ?? 0;
$totalQuestions = $data['total_questions'] ?? 0;
$correctAnswers = $data['correct_answers'] ?? 0;

// Validate inputs
if (!$studentID) {
    sendError("student_id is required", 400);
}
if (!$lessonID) {
    sendError("lesson_id is required", 400);
}
if ($quizScore === null || $quizScore < 0 || $quizScore > 100) {
    sendError("quiz_score is required and must be between 0 and 100", 400);
}

// Verify authenticated user
if ($authUser['studentID'] != $studentID) {
    sendError("Unauthorized: Cannot update quiz score for another student", 403);
}

try {
    // Call stored procedure to update quiz score and get branching decision
    $stmt = $conn->prepare("EXEC SP_UpdateQuizScore @StudentID = ?, @LessonID = ?, @QuizScore = ?");
    $stmt->execute([$studentID, $lessonID, $quizScore]);

    // Get decision result
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        sendError("Failed to update quiz score", 500);
    }

    $decision = $result['Decision'];
    $interventionThreshold = (int)$result['InterventionThreshold'];
    $enrichmentThreshold = (int)$result['EnrichmentThreshold'];

    // Determine XP award based on score
    $xpAwarded = 0;
    if ($quizScore >= $enrichmentThreshold) {
        $xpAwarded = 25; // Excellent performance
    } elseif ($quizScore >= $interventionThreshold) {
        $xpAwarded = 15; // Good performance
    } else {
        $xpAwarded = 5; // Needs review
    }

    // Award XP to student
    $stmt = $conn->prepare(
        "UPDATE Students SET TotalXP = TotalXP + ? WHERE StudentID = ?"
    );
    $stmt->execute([$xpAwarded, $studentID]);

    // Get unlocked branches (if any)
    $unlockedBranches = [];
    $lessonLocked = false;
    $nextStep = 'game';

    if ($decision === 'intervention_required') {
        // Get intervention branch
        $stmt = $conn->prepare(
            "SELECT BranchID, BranchType, Title, Description
             FROM LessonBranches
             WHERE ParentLessonID = ? AND BranchType = 'intervention'"
        );
        $stmt->execute([$lessonID]);
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($branch) {
            $unlockedBranches[] = [
                'BranchID' => (int)$branch['BranchID'],
                'BranchType' => $branch['BranchType'],
                'Title' => $branch['Title'],
                'Description' => $branch['Description']
            ];

            // Unlock the branch for this student
            $stmt = $conn->prepare(
                "IF NOT EXISTS (SELECT 1 FROM StudentBranches WHERE StudentID = ? AND BranchID = ?)
                 BEGIN
                     INSERT INTO StudentBranches (StudentID, BranchID, Status, UnlockedAt)
                     VALUES (?, ?, 'unlocked', GETDATE())
                 END
                 ELSE
                 BEGIN
                     UPDATE StudentBranches SET Status = 'unlocked', UnlockedAt = GETDATE()
                     WHERE StudentID = ? AND BranchID = ?
                 END"
            );
            $stmt->execute([
                $studentID, $branch['BranchID'],
                $studentID, $branch['BranchID'],
                $studentID, $branch['BranchID']
            ]);
        }

        $lessonLocked = true;
        $nextStep = 'intervention';
        $message = "You scored {$quizScore}%. Let's review this lesson together with some extra practice!";

    } elseif ($decision === 'enrichment_unlocked') {
        // Get enrichment branch
        $stmt = $conn->prepare(
            "SELECT BranchID, BranchType, Title, Description
             FROM LessonBranches
             WHERE ParentLessonID = ? AND BranchType = 'enrichment'"
        );
        $stmt->execute([$lessonID]);
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($branch) {
            $unlockedBranches[] = [
                'BranchID' => (int)$branch['BranchID'],
                'BranchType' => $branch['BranchType'],
                'Title' => $branch['Title'],
                'Description' => $branch['Description']
            ];

            // Unlock the branch for this student
            $stmt = $conn->prepare(
                "IF NOT EXISTS (SELECT 1 FROM StudentBranches WHERE StudentID = ? AND BranchID = ?)
                 BEGIN
                     INSERT INTO StudentBranches (StudentID, BranchID, Status, UnlockedAt)
                     VALUES (?, ?, 'unlocked', GETDATE())
                 END
                 ELSE
                 BEGIN
                     UPDATE StudentBranches SET Status = 'unlocked', UnlockedAt = GETDATE()
                     WHERE StudentID = ? AND BranchID = ?
                 END"
            );
            $stmt->execute([
                $studentID, $branch['BranchID'],
                $studentID, $branch['BranchID'],
                $studentID, $branch['BranchID']
            ]);
        }

        $nextStep = 'game'; // Can proceed to game, enrichment is optional
        $message = "Excellent! You scored {$quizScore}%. You've unlocked an enrichment challenge!";

    } else {
        // Standard progression
        $message = "Great job! You scored {$quizScore}%. Now let's play the game!";
    }

    // Log activity
    logActivity($studentID, 'quiz_completed', json_encode([
        'lesson_id' => $lessonID,
        'quiz_score' => $quizScore,
        'decision' => $decision,
        'xp_awarded' => $xpAwarded
    ]));

    // Prepare response
    $response = [
        'success' => true,
        'decision' => $decision,
        'message' => $message,
        'quiz_score' => (int)$quizScore,
        'next_step' => $nextStep,
        'unlocked_branches' => $unlockedBranches,
        'xp_awarded' => (int)$xpAwarded,
        'lesson_locked' => $lessonLocked,
        'thresholds' => [
            'intervention' => $interventionThreshold,
            'enrichment' => $enrichmentThreshold
        ]
    ];

    sendResponse($response, 200);

} catch (PDOException $e) {
    error_log("Update quiz score error: " . $e->getMessage());
    sendError("Failed to update quiz score", 500, $e->getMessage());
} catch (Exception $e) {
    error_log("Update quiz score error: " . $e->getMessage());
    sendError("An error occurred", 500, $e->getMessage());
}

?>