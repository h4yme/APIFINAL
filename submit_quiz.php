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
    $questionIds = array_keys($answers);
    $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
    
    $stmt = $conn->prepare("
        SELECT QuestionID, CorrectAnswer
        FROM QuizQuestions
        WHERE QuestionID IN ($placeholders)
    ");
    $stmt->execute($questionIds);
    $correctAnswers = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Calculate score
    $correctCount = 0;
    $totalQuestions = count($answers);
    
    foreach ($answers as $questionId => $studentAnswer) {
        if (isset($correctAnswers[$questionId]) && 
            $correctAnswers[$questionId] == $studentAnswer) {
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
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'result' => [
            'score_percent' => round($scorePercent, 2),
            'correct_count' => $correctCount,
            'total_questions' => $totalQuestions,
            'adaptive_decision' => $adaptiveDecision,
            'xp_awarded' => $xpAwarded,
            'unlocked_nodes' => $unlockedNodes
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error',
        'error' => (($_ENV['DEBUG_MODE'] ?? 'false') === 'true') ? $e->getMessage() : null
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error',
        'error' => (($_ENV['DEBUG_MODE'] ?? 'false') === 'true') ? $e->getMessage() : null
    ]);
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
    
    if ($exists) {
        $stmt = $conn->prepare("
            UPDATE StudentNodeProgress
            SET QuizCompleted = 1,
                QuizScore = ?,
                AdaptiveDecision = ?,
                CompletedAt = GETDATE()
            WHERE StudentID = ? AND NodeID = ?
        ");
        $stmt->execute([$score, $decision, $studentId, $nodeId]);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO StudentNodeProgress (StudentID, NodeID, LessonCompleted, GameCompleted, QuizCompleted, QuizScore, AdaptiveDecision, CompletedAt)
            VALUES (?, ?, 1, 1, 1, ?, ?, GETDATE())
        ");
        $stmt->execute([$studentId, $nodeId, $score, $decision]);
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
                SELECT NodeID, Title
                FROM SupplementalNodes
                WHERE AfterNodeID = ? AND NodeType = 'INTERVENTION'
            ");
            $stmt->execute([$nodeId]);
            $interventions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($interventions as $node) {
                $stmt = $conn->prepare("
                    IF NOT EXISTS (SELECT 1 FROM StudentNodeProgress WHERE StudentID = ? AND NodeID = ?)
                    INSERT INTO StudentNodeProgress (StudentID, NodeID, LessonCompleted, GameCompleted, QuizCompleted)
                    VALUES (?, ?, 0, 0, 0)
                ");
                $stmt->execute([$studentId, $node['NodeID'], $studentId, $node['NodeID']]);
                $unlockedNodes[] = [
                    'type' => 'INTERVENTION',
                    'node_id' => $node['NodeID'],
                    'title' => $node['Title'],
                    'mandatory' => true
                ];
            }
            break;
            
        case 'ADD_SUPPLEMENTAL':
            $stmt = $conn->prepare("
                SELECT NodeID, Title
                FROM SupplementalNodes
                WHERE AfterNodeID = ? AND NodeType = 'SUPPLEMENTAL'
            ");
            $stmt->execute([$nodeId]);
            $supplementals = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($supplementals as $node) {
                $stmt = $conn->prepare("
                    IF NOT EXISTS (SELECT 1 FROM StudentNodeProgress WHERE StudentID = ? AND NodeID = ?)
                    INSERT INTO StudentNodeProgress (StudentID, NodeID, LessonCompleted, GameCompleted, QuizCompleted)
                    VALUES (?, ?, 0, 0, 0)
                ");
                $stmt->execute([$studentId, $node['NodeID'], $studentId, $node['NodeID']]);
                $unlockedNodes[] = [
                    'type' => 'SUPPLEMENTAL',
                    'node_id' => $node['NodeID'],
                    'title' => $node['Title'],
                    'mandatory' => false
                ];
            }
            break;
            
        case 'OFFER_ENRICHMENT':
            $stmt = $conn->prepare("
                SELECT NodeID, Title
                FROM SupplementalNodes
                WHERE AfterNodeID = ? AND NodeType = 'ENRICHMENT'
            ");
            $stmt->execute([$nodeId]);
            $enrichments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($enrichments as $node) {
                $stmt = $conn->prepare("
                    IF NOT EXISTS (SELECT 1 FROM StudentNodeProgress WHERE StudentID = ? AND NodeID = ?)
                    INSERT INTO StudentNodeProgress (StudentID, NodeID, LessonCompleted, GameCompleted, QuizCompleted)
                    VALUES (?, ?, 0, 0, 0)
                ");
                $stmt->execute([$studentId, $node['NodeID'], $studentId, $node['NodeID']]);
                $unlockedNodes[] = [
                    'type' => 'ENRICHMENT',
                    'node_id' => $node['NodeID'],
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
