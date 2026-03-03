<?php

/**

 * LiteRise Get Lessons API

 * POST /api/get_lessons.php

 *

 * Returns lessons adapted to student's current ability level

 *

 * Request Body:

 * {

 *   "student_id": 1,

 *   "grade_level": 5  // Optional: filter by grade

 * }

 *

 * Response:

 * {

 *   "success": true,

 *   "student_ability": 0.75,

 *   "classification": "Proficient",

 *   "lessons": [

 *     {

 *       "LessonID": 1,

 *       "LessonTitle": "Reading Comprehension Basics",

 *       "LessonDescription": "...",

 *       "RequiredAbility": 0.5,

 *       "GradeLevel": 5,

 *       "LessonType": "Reading",

 *       "IsUnlocked": true,

 *       "CompletionStatus": "NotStarted",

 *       "Score": null

 *     }

 *   ]

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

$gradeLevel = $data['grade_level'] ?? null;

 

// Validate student_id

if (!$studentID) {

    sendError("student_id is required", 400);

}

 

// Verify authenticated user

if ($authUser['studentID'] != $studentID) {

    sendError("Unauthorized: Cannot view lessons for another student", 403);

}

 

try {

    $irt = new ItemResponseTheory();

 

    // Get student's current ability

    $stmt = $conn->prepare("SELECT CurrentAbility, GradeLevel FROM Students WHERE StudentID = ?");

    $stmt->execute([$studentID]);

    $student = $stmt->fetch(PDO::FETCH_ASSOC);

 

    if (!$student) {

        sendError("Student not found", 404);

    }

 

    $currentAbility = (float)$student['CurrentAbility'];

    $studentGradeLevel = (int)$student['GradeLevel'];

    $classification = $irt->classifyAbility($currentAbility);

 

    // Use provided grade level or student's grade level

    $targetGradeLevel = $gradeLevel ?? $studentGradeLevel;

 

    // Call stored procedure to get lessons by ability

    $stmt = $conn->prepare("EXEC SP_GetLessonsByAbility @StudentID = ?");

    $stmt->execute([$studentID]);

    $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);

 

    // Get student's progress for each lesson

    $stmt = $conn->prepare(

        "SELECT LessonID, CompletionStatus, Score, LastAttemptDate

         FROM StudentProgress

         WHERE StudentID = ?"

    );

    $stmt->execute([$studentID]);

    $progressData = $stmt->fetchAll(PDO::FETCH_ASSOC);

 

    // Create progress lookup

    $progressLookup = [];

    foreach ($progressData as $progress) {

        $progressLookup[$progress['LessonID']] = $progress;

    }

 

    // Format lessons with progress information

    $formattedLessons = array_map(function($lesson) use ($currentAbility, $progressLookup) {

        $lessonID = (int)$lesson['LessonID'];

        $requiredAbility = (float)$lesson['RequiredAbility'];

 

        // Check if lesson is unlocked (ability within range)

        $isUnlocked = $currentAbility >= ($requiredAbility - 0.3);

 

        // Get progress if exists

        $progress = $progressLookup[$lessonID] ?? null;

 

        return [

            'LessonID' => $lessonID,

            'LessonTitle' => $lesson['LessonTitle'],

            'LessonDescription' => $lesson['LessonDescription'],

            'RequiredAbility' => round($requiredAbility, 2),

            'GradeLevel' => (int)$lesson['GradeLevel'],

            'LessonType' => $lesson['LessonType'],

            'IsUnlocked' => $isUnlocked,

            'CompletionStatus' => $progress['CompletionStatus'] ?? 'NotStarted',

            'Score' => $progress ? (float)$progress['Score'] : null,

            'LastAttemptDate' => $progress['LastAttemptDate'] ?? null

        ];

    }, $lessons);

 

    // Sort by required ability (adaptive learning)

    usort($formattedLessons, function($a, $b) {

        return $a['RequiredAbility'] <=> $b['RequiredAbility'];

    });

 

    $response = [

        'success' => true,

        'student_ability' => round($currentAbility, 3),

        'classification' => $classification,

        'total_lessons' => count($formattedLessons),

        'unlocked_lessons' => count(array_filter($formattedLessons, fn($l) => $l['IsUnlocked'])),

        'lessons' => $formattedLessons

    ];

 

    sendResponse($response, 200);

 

} catch (PDOException $e) {

    error_log("Get lessons error: " . $e->getMessage());

    sendError("Failed to fetch lessons", 500, $e->getMessage());

} catch (Exception $e) {

    error_log("Get lessons error: " . $e->getMessage());

    sendError("An error occurred", 500, $e->getMessage());

}

?>