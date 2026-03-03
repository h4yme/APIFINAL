<?php

/**

 * Reset student's ability to a reasonable starting value

 * Use this when a student's ability is stuck at ceiling/floor due to bugs

 */

 

header("Content-Type: application/json");

 

require_once __DIR__ . '/src/db.php';

require_once __DIR__ . '/src/auth.php';

 

// Require authentication

$authUser = requireAuth();

$studentID = $authUser['studentID'];

 

try {

    // Get current ability

    $stmt = $conn->prepare("SELECT CurrentAbility FROM Students WHERE StudentID = ?");

    $stmt->execute([$studentID]);

    $currentAbility = (float)$stmt->fetchColumn();

 

    // Reset to 0.0 (average/typical ability)

    $newAbility = 0.0;

 

    $stmt = $conn->prepare("UPDATE Students SET CurrentAbility = ? WHERE StudentID = ?");

    $stmt->execute([$newAbility, $studentID]);

 

    // Log the reset

    logActivity($studentID, 'AbilityReset', "Ability reset from $currentAbility to $newAbility");

 

    echo json_encode([

        'success' => true,

        'message' => 'Ability has been reset to starting value',

        'previous_ability' => $currentAbility,

        'new_ability' => $newAbility,

        'note' => 'Your next PreAssessment will accurately determine your ability level'

    ], JSON_PRETTY_PRINT);

 

} catch (Exception $e) {

    echo json_encode([

        'success' => false,

        'error' => $e->getMessage()

    ], JSON_PRETTY_PRINT);

}

?>