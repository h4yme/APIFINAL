<?php

header('Content-Type: application/json');

 

$password = 'password123';

 

// Generate multiple hashes to test

$hash1 = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$hash2 = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$hash3 = password_hash($password, PASSWORD_DEFAULT);

 

// Test each hash immediately

$response = [

    'password' => $password,

    'php_version' => phpversion(),

    'hashes_generated' => [

        [

            'hash' => $hash1,

            'cost' => 12,

            'algorithm' => 'PASSWORD_BCRYPT',

            'verify_immediately' => password_verify($password, $hash1),

            'length' => strlen($hash1)

        ],

        [

            'hash' => $hash2,

            'cost' => 12,

            'algorithm' => 'PASSWORD_BCRYPT',

            'verify_immediately' => password_verify($password, $hash2),

            'length' => strlen($hash2)

        ],

        [

            'hash' => $hash3,

            'algorithm' => 'PASSWORD_DEFAULT',

            'verify_immediately' => password_verify($password, $hash3),

            'length' => strlen($hash3)

        ]

    ],

    'instructions' => [

        'step1' => 'Copy one of the hashes above that has verify_immediately=true',

        'step2' => 'Run this SQL: UPDATE Students SET Password = \'PASTE_HASH_HERE\' WHERE Email = \'test@student.com\';',

        'step3' => 'Try login again'

    ]

];

 

// Test the problematic hash

$problematicHash = '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TfZ.q8fTZ7wSp.kZP9Hq.hJpG6Fu';

$response['test_problematic_hash'] = [

    'hash' => $problematicHash,

    'verify_result' => password_verify($password, $problematicHash),

    'note' => 'This hash might have been generated on a different system'

];

 

// Check bcrypt support

$response['bcrypt_info'] = [

    'PASSWORD_BCRYPT_defined' => defined('PASSWORD_BCRYPT'),

    'PASSWORD_BCRYPT_value' => PASSWORD_BCRYPT ?? 'undefined',

    'available_algos' => password_algos()

];

 

echo json_encode($response, JSON_PRETTY_PRINT);

?>