<?php
/**
 * Submit Quiz API
 * Endpoint: POST /api/submit_quiz.php
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/src/db.php';

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON format']);
        exit;
    }

    $studentId     = isset($data['student_id'])     ? intval($data['student_id'])     : 0;
    $nodeId        = isset($data['node_id'])         ? intval($data['node_id'])         : 0;
    $placementLevel= isset($data['placement_level']) ? intval($data['placement_level']) : 2;
    $answers       = isset($data['answers'])         ? $data['answers']                 : [];

    if ($studentId === 0 || $nodeId === 0 || empty($answers)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required data']);
        exit;
    }

    // Get correct answers from DB (text-based)
    $questionIds  = array_keys($answers);
    $placeholders = implode(',', array_fill(0, count($questionIds), '?'));

    $stmt = $conn->prepare("
        SELECT QuestionID, CorrectAnswer
        FROM QuizQuestions
        WHERE QuestionID IN ($placeholders)
    ");
    $stmt->execute($questionIds);
    $correctAnswers = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Calculate score — compare answer TEXT (case-insensitive, trimmed)
    $correctCount   = 0;
    $totalQuestions = count($answers);

    foreach ($answers as $questionId => $studentAnswerText) {
        if (isset($correctAnswers[$questionId]) &&
            strtolower(trim($correctAnswers[$questionId])) === strtolower(trim($studentAnswerText))) {
            $correctCount++;
        }
    }

    $scorePercent     = ($totalQuestions > 0) ? ($correctCount / $totalQuestions) * 100 : 0;
    $adaptiveDecision = determineAdaptiveDecision($scorePercent, $placementLevel);
    $xpAwarded        = calculateXP($scorePercent);

    updateNodeProgress($conn, $studentId, $nodeId, $scorePercent, $adaptiveDecision);
    awardXP($conn, $studentId, $xpAwarded);
    $unlockedNodes = handleAdaptiveBranching($conn, $studentId, $nodeId, $adaptiveDecision);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'result'  => [
            'score_percent'     => round($scorePercent, 2),
            'correct_count'     => $correctCount,
            'total_questions'   => $totalQuestions,
            'adaptive_decision' => $adaptiveDecision,
            'xp_awarded'        => $xpAwarded,
            'unlocked_nodes'    => $unlockedNodes
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error', 'error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
}

function determineAdaptiveDecision($scorePercent, $placementLevel) {
    if ($scorePercent < 70)                                        return 'ADD_INTERVENTION';
    if ($scorePercent >= 70 && $scorePercent < 80 && $placementLevel == 1) return 'ADD_SUPPLEMENTAL';
    if ($scorePercent >= 90 && $placementLevel == 3)               return 'OFFER_ENRICHMENT';
    return 'PROCEED';
}

function calculateXP($scorePercent) {
    if ($scorePercent >= 90) return 100;
    if ($scorePercent >= 80) return 80;
    if ($scorePercent >= 70) return 60;
    if ($scorePercent >= 60) return 40;
    return 20;
}

function updateNodeProgress($conn, $studentId, $nodeId, $score, $decision) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM StudentNodeProgress WHERE StudentID = ? AND NodeID = ?");
    $stmt->execute([$studentId, $nodeId]);
    $exists   = $stmt->fetchColumn();
    $scoreInt = (int)round($score);

    if ($exists) {
        $stmt = $conn->prepare("
            UPDATE StudentNodeProgress
            SET QuizCompleted  = 1,
                LatestQuizScore = ?,
                BestQuizScore   = CASE WHEN ISNULL(BestQuizScore, 0) < ? THEN ? ELSE BestQuizScore END,
                NodeState       = ?,
                CompletedDate   = GETDATE(),
                AttemptCount    = ISNULL(AttemptCount, 0) + 1
            WHERE StudentID = ? AND NodeID = ?
        ");
        $stmt->execute([$scoreInt, $scoreInt, $scoreInt, $decision, $studentId, $nodeId]);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO StudentNodeProgress
                (StudentID, NodeID, LessonCompleted, GameCompleted, QuizCompleted,
                 LatestQuizScore, BestQuizScore, NodeState, CompletedDate, AttemptCount)
            VALUES (?, ?, 1, 1, 1, ?, ?, ?, GETDATE(), 1)
        ");
        $stmt->execute([$studentId, $nodeId, $scoreInt, $scoreInt, $decision]);
    }
}

function awardXP($conn, $studentId, $xp) {
    $stmt = $conn->prepare("UPDATE Students SET TotalXP = ISNULL(TotalXP, 0) + ? WHERE StudentID = ?");
    $stmt->execute([$xp, $studentId]);
}

function handleAdaptiveBranching($conn, $studentId, $nodeId, $decision) {
    $unlockedNodes = [];

    $typeMap = [
        'ADD_INTERVENTION' => ['type' => 'INTERVENTION', 'mandatory' => true,  'reason' => 'score < 70 on node quiz'],
        'ADD_SUPPLEMENTAL' => ['type' => 'SUPPLEMENTAL', 'mandatory' => false, 'reason' => 'beginner level 70-79%'],
        'OFFER_ENRICHMENT' => ['type' => 'ENRICHMENT',   'mandatory' => false, 'reason' => 'advanced level >= 90%'],
    ];

    if (isset($typeMap[$decision])) {
        $meta = $typeMap[$decision];
        $stmt = $conn->prepare("
            SELECT SupplementalNodeID, Title
            FROM SupplementalNodes
            WHERE AfterNodeID = ? AND NodeType = ? AND IsActive = 1
        ");
        $stmt->execute([$nodeId, $meta['type']]);
        $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($nodes as $node) {
            $stmt = $conn->prepare("
                IF NOT EXISTS (SELECT 1 FROM StudentSupplementalProgress WHERE StudentID = ? AND SupplementalNodeID = ?)
                INSERT INTO StudentSupplementalProgress
                    (StudentID, SupplementalNodeID, IsVisible, TriggerReason, IsCompleted, CreatedDate)
                VALUES (?, ?, 1, ?, 0, GETDATE())
            ");
            $stmt->execute([$studentId, $node['SupplementalNodeID'], $studentId, $node['SupplementalNodeID'], $meta['reason']]);
            $unlockedNodes[] = [
                'type'      => $meta['type'],
                'node_id'   => $node['SupplementalNodeID'],
                'title'     => $node['Title'],
                'mandatory' => $meta['mandatory']
            ];
        }
    } else {
        // PROCEED — unlock next core node
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
            $unlockedNodes[] = ['type' => 'NEXT_NODE', 'node_id' => $nextNode['NodeID'], 'title' => $nextNode['LessonTitle']];
        }
    }

    return $unlockedNodes;
}
?>
