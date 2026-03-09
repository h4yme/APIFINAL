<?php
/**
 * Submit Quiz API
 * 
 * Endpoint: POST /api/submit_quiz.php
 * Description: Submits quiz answers and determines adaptive branching
 * 
 * Request Body:
 * {
 *   "student_id": 1,
 *   "node_id": 1,
 *   "placement_level": 2,
 *   "answers": {
 *     "1": 1,
 *     "2": 3
 *   }
 * }
 * 
 * Response:
 * {
 *   "success": true,
 *   "result": {
 *     "score_percent": 80,
 *     "correct_count": 4,
 *     "total_questions": 5,
 *     "adaptive_decision": "PROCEED",
 *     "xp_awarded": 80,
 *     "unlocked_nodes": [...]
 *   }
 * }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/src/db.php';

try {
    // Get POST data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid JSON format'
        ]);
        exit;
    }
    
    $studentId = isset($data['student_id']) ? intval($data['student_id']) : 0;
    $nodeId = isset($data['node_id']) ? intval($data['node_id']) : 0;
    $placementLevel = isset($data['placement_level']) ? intval($data['placement_level']) : 2;
    $answers = isset($data['answers']) ? $data['answers'] : [];
    
    if ($studentId === 0 || $nodeId === 0 || empty($answers)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required data: student_id, node_id, and answers are required'
        ]);
        exit;
    }
    
    // Get correct answers
    // Use int-cast IDs to avoid PDO ODBC type-mismatch with INT column
    $questionIds = array_map('intval', array_keys($answers));
    $placeholders = implode(',', array_fill(0, count($questionIds), '?'));

    $stmt = $conn->prepare("
        SELECT QuestionID, CorrectAnswer
        FROM QuizQuestions
        WHERE QuestionID IN ($placeholders)
    ");
    foreach ($questionIds as $i => $qid) {
        $stmt->bindValue($i + 1, $qid, PDO::PARAM_INT);
    }
    $stmt->execute();

    // Build map manually — PDO::FETCH_KEY_PAIR is unreliable with MSSQL ODBC
    $correctAnswers = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $correctAnswers[(int)$row['QuestionID']] = trim((string)$row['CorrectAnswer']);
    }

    // Calculate score
    $correctCount = 0;
    $totalQuestions = count($answers);

    foreach ($answers as $questionId => $studentAnswer) {
        $id = (int)$questionId;
        if (isset($correctAnswers[$id]) &&
            strcasecmp($correctAnswers[$id], trim((string)$studentAnswer)) === 0) {
            $correctCount++;
        }
    }
    
    $scorePercent = ($correctCount / $totalQuestions) * 100;
    
    // Determine adaptive decision
    $adaptiveDecision = determineAdaptiveDecision($scorePercent, $placementLevel);
    
    // Calculate XP
    $xpAwarded = calculateXP($scorePercent);
    
    // Update node progress
    updateNodeProgress($conn, $studentId, $nodeId, $scorePercent, $adaptiveDecision);

    // Award XP
    awardXP($conn, $studentId, $xpAwarded);

    // Handle adaptive branching
    $unlockedNodes = handleAdaptiveBranching($conn, $studentId, $nodeId, $adaptiveDecision);

    // Check badge unlocks on any quiz pass (score >= 70)
    $newBadges       = [];
    $moduleCompleted = false;
    $moduleId        = null;
    if ($scorePercent >= 70) {
        $badgeResult     = checkAndAwardBadges($conn, $studentId, $nodeId);
        $newBadges       = $badgeResult['new_badges'];
        $moduleCompleted = $badgeResult['module_completed'];
        $moduleId        = $badgeResult['module_id'];
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'result' => [
            'score_percent'    => (int) round($scorePercent),
            'correct_count'    => $correctCount,
            'total_questions'  => $totalQuestions,
            'adaptive_decision'=> $adaptiveDecision,
            'xp_awarded'       => $xpAwarded,
            'unlocked_nodes'   => $unlockedNodes,
            'new_badges'       => $newBadges,
            'module_completed' => $moduleCompleted,
            'module_id'        => $moduleId
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error',
        'error' => ((($_ENV['DEBUG_MODE'] ?? getenv('DEBUG_MODE')) ?? 'false') === 'true') ? $e->getMessage() : null
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
        'error' => ((($_ENV['DEBUG_MODE'] ?? getenv('DEBUG_MODE')) ?? 'false') === 'true') ? $e->getMessage() : null
    ]);
}


function checkAndAwardBadges($conn, $studentId, $nodeId) {
    $newBadges       = [];
    $moduleCompleted = false;
    $moduleId        = null;

    // Module completion check
    $stmt = $conn->prepare("SELECT ModuleID FROM Nodes WHERE NodeID = ?");
    $stmt->execute([$nodeId]);
    $moduleId = $stmt->fetchColumn();

    if ($moduleId) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM Nodes WHERE ModuleID = ? AND IsActive = 1");
        $stmt->execute([$moduleId]);
        $totalNodes = (int)$stmt->fetchColumn();

        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM StudentNodeProgress snp
            INNER JOIN Nodes n ON snp.NodeID = n.NodeID
            WHERE snp.StudentID = ? AND n.ModuleID = ? AND snp.QuizCompleted = 1 AND n.IsActive = 1
        ");
        $stmt->execute([$studentId, $moduleId]);
        $completedNodes = (int)$stmt->fetchColumn();

        if ($totalNodes > 0 && $completedNodes >= $totalNodes) {
            $moduleCompleted = true;

            $stmt = $conn->prepare("
                SELECT BadgeID, BadgeName, BadgeDescription, BadgeIconURL, XPReward, BadgeCategory
                FROM Badges WHERE UnlockCondition = ?
            ");
            $stmt->execute(["Module:$moduleId"]);
            $moduleBadge = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($moduleBadge) {
                $stmt = $conn->prepare("
                    IF NOT EXISTS (SELECT 1 FROM StudentBadges WHERE StudentID = ? AND BadgeID = ?)
                        INSERT INTO StudentBadges (StudentID, BadgeID, DateEarned) VALUES (?, ?, GETDATE())
                ");
                $stmt->execute([
                    $studentId, $moduleBadge['BadgeID'],
                    $studentId, $moduleBadge['BadgeID']
                ]);

                if ($stmt->rowCount() > 0) {
                    if ((int)$moduleBadge['XPReward'] > 0) {
                        $stmt = $conn->prepare(
                            "UPDATE Students SET TotalXP = ISNULL(TotalXP,0) + ? WHERE StudentID = ?"
                        );
                        $stmt->execute([$moduleBadge['XPReward'], $studentId]);
                    }
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

    // SP_CheckBadgeUnlock handles XP / Streak / Games / Lessons / Perfect
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
        error_log('SP_CheckBadgeUnlock failed: ' . $e->getMessage());
    }

    return [
        'new_badges'       => $newBadges,
        'module_completed' => $moduleCompleted,
        'module_id'        => $moduleId ? (int)$moduleId : null
    ];
}

function determineAdaptiveDecision($scorePercent, $placementLevel) {
    if ($scorePercent < 70) {
        return 'ADD_INTERVENTION';
    } elseif ($scorePercent >= 70 && $scorePercent < 80 && $placementLevel == 1) {
        return 'ADD_SUPPLEMENTAL';
    } elseif ($scorePercent >= 90 && $placementLevel == 3) {
        return 'OFFER_ENRICHMENT';
    } else {
        return 'PROCEED';
    }
}

function calculateXP($scorePercent) {
    if ($scorePercent >= 90) return 100;
    elseif ($scorePercent >= 80) return 80;
    elseif ($scorePercent >= 70) return 60;
    elseif ($scorePercent >= 60) return 40;
    else return 20;
}

function updateNodeProgress($conn, $studentId, $nodeId, $score, $decision) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM StudentNodeProgress
        WHERE StudentID = ? AND NodeID = ?
    ");
    $stmt->execute([$studentId, $nodeId]);
    $exists = $stmt->fetchColumn();

    $scoreInt = (int)round($score);

    // Intervention means the student must redo before the node is truly complete
    $isIntervention = ($decision === 'ADD_INTERVENTION');

    if ($exists) {
        if ($isIntervention) {

            // Record attempt/score only — leave NodeState and QuizCompleted as-is
            // so the next core lesson stays locked on the client
            $stmt = $conn->prepare("
                UPDATE StudentNodeProgress
                SET QuizCompleted = 0,
                    LatestQuizScore = ?,
                    BestQuizScore = CASE WHEN ISNULL(BestQuizScore, 0) < ? THEN ? ELSE BestQuizScore END,
                    AttemptCount = ISNULL(AttemptCount, 0) + 1
                WHERE StudentID = ? AND NodeID = ?
            ");
            $stmt->execute([$scoreInt, $scoreInt, $scoreInt, $studentId, $nodeId]);
        } else {
            $stmt = $conn->prepare("
                UPDATE StudentNodeProgress
                SET LessonCompleted = 1,
                    GameCompleted = 1,
                    QuizCompleted = 1,
                    LatestQuizScore = ?,
                    BestQuizScore = CASE WHEN ISNULL(BestQuizScore, 0) < ? THEN ? ELSE BestQuizScore END,
                    NodeState = 'COMPLETED',
                    CompletedDate = GETDATE(),
                    AttemptCount = ISNULL(AttemptCount, 0) + 1
                WHERE StudentID = ? AND NodeID = ?
            ");
            $stmt->execute([$scoreInt, $scoreInt, $scoreInt, $studentId, $nodeId]);
        }
    } else {
        if ($isIntervention) {
            $stmt = $conn->prepare("
                INSERT INTO StudentNodeProgress (StudentID, NodeID, LessonCompleted, GameCompleted, QuizCompleted, LatestQuizScore, BestQuizScore, AttemptCount)
                VALUES (?, ?, 1, 1, 0, ?, ?, 1)
            ");
            $stmt->execute([$studentId, $nodeId, $scoreInt, $scoreInt]);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO StudentNodeProgress (StudentID, NodeID, LessonCompleted, GameCompleted, QuizCompleted, LatestQuizScore, BestQuizScore, NodeState, CompletedDate, AttemptCount)
                VALUES (?, ?, 1, 1, 1, ?, ?, 'COMPLETED', GETDATE(), 1)
            ");
            $stmt->execute([$studentId, $nodeId, $scoreInt, $scoreInt]);
        }
    }

    // On pass, advance Students.CurrentNodeID to the next node in sequence
    if (!$isIntervention) {
        $stmt = $conn->prepare("
            SELECT n2.NodeID
            FROM Nodes n1
            JOIN Nodes n2 ON n2.ModuleID = n1.ModuleID
                          AND n2.NodeNumber = n1.NodeNumber + 1
                          AND n2.IsActive = 1
            WHERE n1.NodeID = ?
        ");
        $stmt->execute([$nodeId]);
        $nextNodeId = $stmt->fetchColumn();
        if ($nextNodeId) {
            $stmt = $conn->prepare("UPDATE Students SET CurrentNodeID = ? WHERE StudentID = ?");
            $stmt->execute([$nextNodeId, $studentId]);
        }
    }
}

function awardXP($conn, $studentId, $xp) {
    $stmt = $conn->prepare("
        UPDATE Students
        SET TotalXP = ISNULL(TotalXP, 0) + ?
        WHERE StudentID = ?
    ");
    $stmt->execute([$xp, $studentId]);
}

function handleAdaptiveBranching($conn, $studentId, $nodeId, $decision) {
    $unlockedNodes = [];
    
    switch ($decision) {
        case 'ADD_INTERVENTION':
            $stmt = $conn->prepare("
                SELECT SupplementalNodeID, Title
                FROM SupplementalNodes
                WHERE AfterNodeID = ? AND NodeType = 'INTERVENTION' AND IsActive = 1
            ");
            $stmt->execute([$nodeId]);
            $interventions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($interventions as $node) {
                $stmt = $conn->prepare("
                    IF NOT EXISTS (SELECT 1 FROM StudentSupplementalProgress WHERE StudentID = ? AND SupplementalNodeID = ?)
                    INSERT INTO StudentSupplementalProgress (StudentID, SupplementalNodeID, IsVisible, TriggerReason, IsCompleted, CreatedDate)
                    VALUES (?, ?, 1, 'score < 70 on node quiz', 0, GETDATE())
                ");
                $stmt->execute([$studentId, $node['SupplementalNodeID'], $studentId, $node['SupplementalNodeID']]);
                $unlockedNodes[] = [
                    'type' => 'INTERVENTION',
                    'node_id' => $node['SupplementalNodeID'],
                    'title' => $node['Title'],
                    'mandatory' => true
                ];
            }
            break;

        case 'ADD_SUPPLEMENTAL':
            $stmt = $conn->prepare("
                SELECT SupplementalNodeID, Title
                FROM SupplementalNodes
                WHERE AfterNodeID = ? AND NodeType = 'SUPPLEMENTAL' AND IsActive = 1
            ");
            $stmt->execute([$nodeId]);
            $supplementals = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($supplementals as $node) {
                $stmt = $conn->prepare("
                    IF NOT EXISTS (SELECT 1 FROM StudentSupplementalProgress WHERE StudentID = ? AND SupplementalNodeID = ?)
                    INSERT INTO StudentSupplementalProgress (StudentID, SupplementalNodeID, IsVisible, TriggerReason, IsCompleted, CreatedDate)
                    VALUES (?, ?, 1, 'beginner level 70-79%', 0, GETDATE())
                ");
                $stmt->execute([$studentId, $node['SupplementalNodeID'], $studentId, $node['SupplementalNodeID']]);
                $unlockedNodes[] = [
                    'type' => 'SUPPLEMENTAL',
                    'node_id' => $node['SupplementalNodeID'],
                    'title' => $node['Title'],
                    'mandatory' => false
                ];
            }
            break;

        case 'OFFER_ENRICHMENT':
            $stmt = $conn->prepare("
                SELECT SupplementalNodeID, Title
                FROM SupplementalNodes
                WHERE AfterNodeID = ? AND NodeType = 'ENRICHMENT' AND IsActive = 1
            ");
            $stmt->execute([$nodeId]);
            $enrichments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($enrichments as $node) {
                $stmt = $conn->prepare("
                    IF NOT EXISTS (SELECT 1 FROM StudentSupplementalProgress WHERE StudentID = ? AND SupplementalNodeID = ?)
                    INSERT INTO StudentSupplementalProgress (StudentID, SupplementalNodeID, IsVisible, TriggerReason, IsCompleted, CreatedDate)
                    VALUES (?, ?, 1, 'advanced level >= 90%', 0, GETDATE())
                ");
                $stmt->execute([$studentId, $node['SupplementalNodeID'], $studentId, $node['SupplementalNodeID']]);
                $unlockedNodes[] = [
                    'type' => 'ENRICHMENT',
                    'node_id' => $node['SupplementalNodeID'],
                    'title' => $node['Title'],
                    'mandatory' => false
                ];
            }
            break;
            
        case 'PROCEED':
        default:
            $stmt = $conn->prepare("
                SELECT TOP 1 NodeID, LessonTitle
                FROM Nodes
                WHERE ModuleID = (SELECT ModuleID FROM Nodes WHERE NodeID = ?)
                AND NodeNumber > (SELECT NodeNumber FROM Nodes WHERE NodeID = ?)
                AND NodeType = 'CORE_LESSON'
                ORDER BY NodeNumber ASC
            ");
            $stmt->execute([$nodeId, $nodeId]);
            $nextNode = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($nextNode) {
                $stmt = $conn->prepare("
                    IF NOT EXISTS (SELECT 1 FROM StudentNodeProgress WHERE StudentID = ? AND NodeID = ?)
                    INSERT INTO StudentNodeProgress (StudentID, NodeID, LessonCompleted, GameCompleted, QuizCompleted)
                    VALUES (?, ?, 0, 0, 0)
                ");
                $stmt->execute([$studentId, $nextNode['NodeID'], $studentId, $nextNode['NodeID']]);
                $unlockedNodes[] = [
                    'type' => 'NEXT_NODE',
                    'node_id' => $nextNode['NodeID'],
                    'title' => $nextNode['LessonTitle']
                ];
            }
            break;
    }
    
    return $unlockedNodes;
}
?>