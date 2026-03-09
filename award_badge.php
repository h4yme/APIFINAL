<?php
/**
 * Award Badge / Check Badge Unlock
 *
 * POST /api/award_badge.php
 *
 * Called after a student passes a node quiz. Checks:
 *   1. Whether all nodes in the current module are now complete → awards module badge.
 *   2. SP_CheckBadgeUnlock for XP / Streak / Games / Lessons / Perfect conditions.
 *
 * Request Body:
 * {
 *   "student_id": 1,
 *   "node_id":    13
 * }
 *
 * Response:
 * {
 *   "success":      true,
 *   "new_badges":   [
 *     {
 *       "badge_id":          1,
 *       "badge_name":        "Phonics Pioneer",
 *       "badge_description": "Completed Module 1: Phonics and Word Study",
 *       "badge_icon_url":    "badge_module1",
 *       "xp_reward":         100,
 *       "badge_category":    "module",
 *       "date_earned":       "2026-03-01 10:00:00"
 *     }
 *   ],
 *   "module_completed": true,
 *   "module_id":        1
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/src/db.php';

$body      = json_decode(file_get_contents('php://input'), true);
$studentId = isset($body['student_id']) ? intval($body['student_id']) : 0;
$nodeId    = isset($body['node_id'])    ? intval($body['node_id'])    : 0;

if ($studentId === 0 || $nodeId === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'student_id and node_id are required']);
    exit;
}

try {
    $newBadges       = [];
    $moduleCompleted = false;
    $moduleId        = null;

    // ── 1. Module completion check ────────────────────────────────────────────
    // Get the module this node belongs to
    $stmt = $conn->prepare("SELECT ModuleID FROM Nodes WHERE NodeID = ?");
    $stmt->execute([$nodeId]);
    $moduleId = $stmt->fetchColumn();

    if ($moduleId) {
        // Total active nodes in the module
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM Nodes WHERE ModuleID = ? AND IsActive = 1
        ");
        $stmt->execute([$moduleId]);
        $totalNodes = (int)$stmt->fetchColumn();

        // Nodes the student has completed in that module
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM StudentNodeProgress snp
            INNER JOIN Nodes n ON snp.NodeID = n.NodeID
            WHERE snp.StudentID = ?
              AND n.ModuleID    = ?
              AND snp.QuizCompleted = 1
              AND n.IsActive = 1
        ");
        $stmt->execute([$studentId, $moduleId]);
        $completedNodes = (int)$stmt->fetchColumn();

        if ($totalNodes > 0 && $completedNodes >= $totalNodes) {
            $moduleCompleted = true;

            // Find the module badge
            $stmt = $conn->prepare("
                SELECT BadgeID, BadgeName, BadgeDescription, BadgeIconURL, XPReward, BadgeCategory
                FROM Badges
                WHERE UnlockCondition = ?
            ");
            $stmt->execute(["Module:$moduleId"]);
            $moduleBadge = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($moduleBadge) {
                // Award only if not already earned
                $stmt = $conn->prepare("
                    IF NOT EXISTS (
                        SELECT 1 FROM StudentBadges
                        WHERE StudentID = ? AND BadgeID = ?
                    )
                    INSERT INTO StudentBadges (StudentID, BadgeID, DateEarned)
                    VALUES (?, ?, GETDATE())
                ");
                $stmt->execute([
                    $studentId, $moduleBadge['BadgeID'],
                    $studentId, $moduleBadge['BadgeID']
                ]);

                // If newly inserted, also award the badge's XP bonus
                if ($stmt->rowCount() > 0 && (int)$moduleBadge['XPReward'] > 0) {
                    $stmt = $conn->prepare("
                        UPDATE Students
                        SET TotalXP = ISNULL(TotalXP, 0) + ?
                        WHERE StudentID = ?
                    ");
                    $stmt->execute([$moduleBadge['XPReward'], $studentId]);

                    $newBadges[] = [
                        'badge_id'          => (int)$moduleBadge['BadgeID'],
                        'badge_name'        => $moduleBadge['BadgeName'],
                        'badge_description' => $moduleBadge['BadgeDescription'],
                        'badge_icon_url'    => $moduleBadge['BadgeIconURL'],
                        'xp_reward'         => (int)$moduleBadge['XPReward'],
                        'badge_category'    => $moduleBadge['BadgeCategory'],
                        'date_earned'       => date('Y-m-d H:i:s')
                    ];
                }
            }
        }
    }

    // ── 2. SP_CheckBadgeUnlock (XP / Streak / Games / Lessons / Perfect) ─────
    try {
        $stmt = $conn->prepare("EXEC SP_CheckBadgeUnlock ?");
        $stmt->execute([$studentId]);

        $spBadges = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($spBadges as $b) {
            $newBadges[] = [
                'badge_id'          => (int)$b['BadgeID'],
                'badge_name'        => $b['BadgeName'],
                'badge_description' => $b['BadgeDescription'],
                'badge_icon_url'    => $b['BadgeIconURL'],
                'xp_reward'         => (int)$b['XPReward'],
                'badge_category'    => $b['BadgeCategory'],
                'date_earned'       => $b['DateEarned']
            ];
        }
    } catch (Exception $e) {
        // SP may not exist yet; non-fatal
        error_log('SP_CheckBadgeUnlock failed: ' . $e->getMessage());
    }

    echo json_encode([
        'success'          => true,
        'new_badges'       => $newBadges,
        'module_completed' => $moduleCompleted,
        'module_id'        => $moduleId ? (int)$moduleId : null
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error',
        'error'   => ((($_ENV['DEBUG_MODE'] ?? getenv('DEBUG_MODE')) ?? 'false') === 'true') ? $e->getMessage() : null
    ]);
}