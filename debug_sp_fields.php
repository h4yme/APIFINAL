<?php

/**

 * Debug endpoint to see what fields the stored procedure returns

 */

 

header("Content-Type: application/json");

 

require_once __DIR__ . '/src/db.php';

require_once __DIR__ . '/src/auth.php';

 

try {

    // Require authentication

    $authUser = requireAuth();

 

    // Call stored procedure to get pre-assessment items

    $stmt = $conn->prepare("EXEC SP_GetPreAssessmentItems");

    $stmt->execute();

 

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

 

    if (empty($items)) {

        echo json_encode(['error' => 'No items found']);

        exit;

    }

 

    // Get the first item to see all its fields

    $firstItem = $items[0];

 

    echo json_encode([

        'message' => 'Debug: All fields returned by SP_GetPreAssessmentItems',

        'field_names' => array_keys($firstItem),

        'first_item_data' => $firstItem,

        'total_items' => count($items)

    ], JSON_PRETTY_PRINT);

 

} catch (Exception $e) {

    echo json_encode(['error' => $e->getMessage()]);

}