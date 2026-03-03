<?php

header('Content-Type: application/json');

 

require_once __DIR__ . '/src/db.php';

 

$testPassword = 'password123';

 

// Get the actual hash from database

$stmt = $conn->prepare("SELECT Password FROM Students WHERE Email = 'test@student.com'");

$stmt->execute();

$result = $stmt->fetch(PDO::FETCH_ASSOC);

$hashFromDB = $result['Password'] ?? 'NOT_FOUND';

 

// Test password verification

$verifyResult = password_verify($testPassword, $hashFromDB);

 

$response = [

    'test_password' => $testPassword,

    'hash_from_database' => $hashFromDB,

    'hash_length' => strlen($hashFromDB),

    'hash_starts_with' => substr($hashFromDB, 0, 10),

    'password_verify_function_exists' => function_exists('password_verify'),

    'password_verify_result' => $verifyResult,

    'verification_status' => $verifyResult ? '✅ SUCCESS' : '❌ FAILED',

    'php_version' => phpversion(),

    'tests' => [

        'correct_password' => password_verify('password123', $hashFromDB),

        'wrong_password' => password_verify('wrongpassword', $hashFromDB),

        'empty_password' => password_verify('', $hashFromDB)

    ]

];

 

// Also test the known good hash

$knownGoodHash = '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TfZ.q8fTZ7wSp.kZP9Hq.hJpG6Fu';

$response['known_good_hash_test'] = password_verify('password123', $knownGoodHash);

 

// Compare hashes

$response['hash_comparison'] = [

    'db_hash' => $hashFromDB,

    'known_good_hash' => $knownGoodHash,

    'hashes_match' => $hashFromDB === $knownGoodHash

];

 

echo json_encode($response, JSON_PRETTY_PRINT);

?>