<?php
/**
 * get_all_students.php
 * Returns a list of all active students with portal summary fields.
 *
 * GET /api/get_all_students.php
 * GET /api/get_all_students.php?school_id=3   (filter by school)
 *
 * Requires: Bearer JWT (portal admin/teacher token)
 *
 * Response: JSON array of student objects, each with:
 *   student_id, name, grade, school_name, school_id,
 *   total_xp, streak_days, last_active, status,
 *   pre_theta, post_theta, placement_level, lessons_done
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

// Portal auth is optional — if a token is present, validate it;
// if none is present, require it anyway to protect data.
requireAuth();

$schoolId = isset($_GET['school_id']) ? intval($_GET['school_id']) : null;

try {
    $sql = "
        SELECT
            S.StudentID                                             AS student_id,
            S.FirstName + ' ' + S.LastName                         AS name,
            S.GradeLevel                                           AS grade,
            COALESCE(SC.SchoolName, '')                            AS school_name,
            S.SchoolID                                             AS school_id,
            COALESCE(S.TotalXP, 0)                                 AS total_xp,
            COALESCE(S.CurrentStreak, 0)                           AS streak_days,
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
        WHERE S.IsActive = 1
    ";

    $params = [];
    if ($schoolId !== null) {
        $sql .= " AND S.SchoolID = ?";
        $params[] = $schoolId;
    }

    $sql .= " ORDER BY S.LastName, S.FirstName";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cast numeric fields
    foreach ($students as &$s) {
        $s['student_id']   = (int)  $s['student_id'];
        $s['grade']        = (int)  $s['grade'];
        $s['school_id']    = $s['school_id'] !== null ? (int) $s['school_id'] : null;
        $s['total_xp']     = (int)  $s['total_xp'];
        $s['streak_days']  = (int)  $s['streak_days'];
        $s['lessons_done'] = (int)  $s['lessons_done'];
        $s['pre_theta']    = $s['pre_theta']  !== null ? (float) $s['pre_theta']  : null;
        $s['post_theta']   = $s['post_theta'] !== null ? (float) $s['post_theta'] : null;
    }
    unset($s);

    echo json_encode($students);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to retrieve students.']);
    error_log("get_all_students error: " . $e->getMessage());
}