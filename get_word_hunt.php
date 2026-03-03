<?php

/**

 * Word Hunt Game API Endpoint

 * Returns vocabulary words for the Word Hunt game

 * Filters by student's exact grade level

 */

 

require_once __DIR__ . '/src/db.php';

 

try {

    // Get parameters from query string or JSON body

    $data = [];

    $rawInput = file_get_contents("php://input");

    if (!empty($rawInput)) {

        $data = json_decode($rawInput, true) ?? [];

    }

 

    $count = (int)($data['count'] ?? $_GET['count'] ?? 8);

    $lessonID = $data['lesson_id'] ?? $_GET['lesson_id'] ?? null;

    $studentID = (int)($data['student_id'] ?? $_GET['student_id'] ?? 0);

    $gridSize = 10; // Default grid size

 

    // Validate count to prevent SQL injection (already cast to int, but extra safety)

    if ($count < 1) $count = 8;

    if ($count > 20) $count = 20;

 

    $words = [];

    $gradeLevel = null;

 

    // Get student's grade level if student_id is provided

    if ($studentID) {

        try {

            $gradeSql = "SELECT GradeLevel FROM Students WHERE StudentID = ?";

            $gradeStmt = $conn->prepare($gradeSql);

            $gradeStmt->execute([$studentID]);

            $studentData = $gradeStmt->fetch(PDO::FETCH_ASSOC);

            if ($studentData && isset($studentData['GradeLevel'])) {

                $gradeLevel = (int)$studentData['GradeLevel'];

            }

        } catch (Exception $e) {

            error_log("Failed to get student grade level: " . $e->getMessage());

        }

    }

 

    if (!$gradeLevel) {

        sendError("Student not found or grade level not set. StudentID: " . $studentID, 400);

        exit;

    }

 

    // Get words from VocabularyWords table filtered by EXACT grade level

    // Note: Using direct value for TOP since it's already validated as int

    try {

        $sql = "SELECT TOP $count

                    WordID as word_id,

                    Word as word,

                    Definition as definition,

                    ExampleSentence as example_sentence,

                    Difficulty as difficulty,

                    Category as category,

                    GradeLevel as grade_level

                FROM VocabularyWords

                WHERE IsActive = 1

                  AND GradeLevel = ?

                ORDER BY NEWID()";

 

        $stmt = $conn->prepare($sql);

        $stmt->execute([$gradeLevel]);

        $words = $stmt->fetchAll(PDO::FETCH_ASSOC);

 

        // If not enough words at exact grade, expand to adjacent grades

        if (count($words) < $count) {

            $remaining = $count - count($words);

            $existingIds = array_column($words, 'word_id');

 

            // Get more words from adjacent grades

            $adjacentSql = "SELECT TOP $remaining

                        WordID as word_id,

                        Word as word,

                        Definition as definition,

                        ExampleSentence as example_sentence,

                        Difficulty as difficulty,

                        Category as category,

                        GradeLevel as grade_level

                    FROM VocabularyWords

                    WHERE IsActive = 1

                      AND GradeLevel != ?

                      AND WordID NOT IN (" . (count($existingIds) > 0 ? implode(',', $existingIds) : '0') . ")

                    ORDER BY ABS(GradeLevel - ?) ASC, NEWID()";

 

            $adjStmt = $conn->prepare($adjacentSql);

            $adjStmt->execute([$gradeLevel, $gradeLevel]);

            $additionalWords = $adjStmt->fetchAll(PDO::FETCH_ASSOC);

 

            $words = array_merge($words, $additionalWords);

        }

    } catch (Exception $e) {

        error_log("VocabularyWords query failed: " . $e->getMessage());

        sendError("Database query failed: " . $e->getMessage(), 500);

        exit;

    }

 

    // Check if we got any words

    if (empty($words)) {

        sendError("No vocabulary words found for grade level " . $gradeLevel . ". Please add words to VocabularyWords table.", 404);

        exit;

    }

 

    // Process words

    foreach ($words as &$word) {

        // Ensure word is uppercase

        $word['word'] = strtoupper(trim($word['word']));

 

        // Adjust grid size if needed for longer words

        $wordLen = strlen($word['word']);

        if ($wordLen > $gridSize - 2) {

            $gridSize = $wordLen + 2;

        }

    }

 

    sendResponse([

        'success' => true,

        'words' => $words,

        'grid_size' => $gridSize,

        'lesson_id' => $lessonID,

        'student_id' => $studentID,

        'student_grade' => $gradeLevel,

        'words_count' => count($words)

    ]);

 

} catch (PDOException $e) {

    error_log("Word Hunt DB error: " . $e->getMessage());

    sendError("Database error: " . $e->getMessage(), 500);

} catch (Exception $e) {

    error_log("Word Hunt error: " . $e->getMessage());

    sendError("Error loading words: " . $e->getMessage(), 500);

}

?>