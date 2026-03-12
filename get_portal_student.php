<?php
/**
 * get_portal_student.php
 * Returns portal-view detail for a single student.
 *
 * GET /api/get_portal_student.php?student_id=77
 *
 * Requires: Bearer JWT (portal admin/teacher token)
 *
 * Response: JSON object with student portal summary fields:
 *   student_id, name, nickname, grade, gender, school_name, school_id,
 *   total_xp, streak_days, longest_streak, last_active, status,
 *   pre_theta, post_theta, placement_level, lessons_done
 *
 * Uses PreAssessmentTheta / PostAssessmentTheta (not CurrentAbility).
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

requireAuth();

$studentID = isset($_GET['student_id']) ? intval($_GET['student_id']) : null;

if (!$studentID) {
    sendError("student_id is required", 400);
}

try {
    $stmt = $conn->prepare("
        SELECT
            S.StudentID                                             AS student_id,
            S.FirstName + ' ' + S.LastName                         AS name,
            S.Nickname                                             AS nickname,
            S.GradeLevel                                           AS grade,
            S.Gender                                               AS gender,
            COALESCE(SC.SchoolName, '')                            AS school_name,
            S.SchoolID                                             AS school_id,
            COALESCE(S.TotalXP, 0)                                 AS total_xp,
            COALESCE(S.CurrentStreak, 0)                           AS streak_days,
            COALESCE(S.LongestStreak, 0)                           AS longest_streak,
            CONVERT(varchar(10), S.LastLogin, 120)                 AS last_active,
            CASE
                WHEN S.LastLogin >= DATEADD(day, -7, GETUTCDATE()) THEN 'active'
                ELSE 'inactive'
            END                                                    AS status,
            S.PreAssessmentTheta                                   AS pre_theta,
            S.PostAssessmentTheta                                  AS post_theta,
            CASE
                WHEN S.PreAssessmentTheta IS NULL    THEN NULL
                WHEN S.PreAssessmentTheta < -0.5     THEN 'beginner'
                WHEN S.PreAssessmentTheta <  0.5     THEN 'intermediate'
                ELSE                                  'advanced'
            END                                                    AS placement_level,
            COALESCE(lp.lessons_done, 0)                           AS lessons_done
        FROM Students S
        LEFT JOIN Schools SC ON S.SchoolID = SC.SchoolID
        LEFT JOIN (
            SELECT StudentID, COUNT(*) AS lessons_done
            FROM   StudentNodeProgress
            WHERE  LessonCompleted = 1
            GROUP  BY StudentID
        ) lp ON S.StudentID = lp.StudentID
        WHERE S.StudentID = ? AND S.IsActive = 1
    ");

    $stmt->execute([$studentID]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        sendError("Student not found", 404);
    }

    $student['student_id']     = (int)   $student['student_id'];
    $student['grade']          = (int)   $student['grade'];
    $student['school_id']      = $student['school_id'] !== null ? (int) $student['school_id'] : null;
    $student['total_xp']       = (int)   $student['total_xp'];
    $student['streak_days']    = (int)   $student['streak_days'];
    $student['longest_streak'] = (int)   $student['longest_streak'];
    $student['lessons_done']   = (int)   $student['lessons_done'];
    $student['pre_theta']      = $student['pre_theta']  !== null ? (float) $student['pre_theta']  : null;
    $student['post_theta']     = $student['post_theta'] !== null ? (float) $student['post_theta'] : null;

    echo json_encode($student);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to retrieve student.']);
    error_log("get_portal_student error: " . $e->getMessage());
}