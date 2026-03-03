<?php

/**
 * Test script for LiteRise Registration and Password Reset API
 * Run this script to test all endpoints
 */

// Test configuration
$API_BASE_URL = 'http://localhost/literise/api';
$TEST_EMAIL = 'test_' . time() . '@example.com';
$TEST_PASSWORD = 'TestPass123';
$TEST_NEW_PASSWORD = 'NewPass123';

echo "========================================\n";
echo "LiteRise API Test Suite\n";
echo "========================================\n\n";

/**
 * Make HTTP request
 */
function makeRequest($url, $data = null) {
    $ch = curl_init($url);

    if ($data) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => json_decode($response, true)
    ];
}

/**
 * Test 1: Registration
 */
echo "Test 1: Registration\n";
echo "--------------------\n";

$registerData = [
    'nickname' => 'TestUser',
    'first_name' => 'Test',
    'last_name' => 'Student',
    'email' => $TEST_EMAIL,
    'password' => $TEST_PASSWORD,
    'birthday' => '2015-05-15',
    'gender' => 'Male',
    'grade_level' => '1'
];

$response = makeRequest("$API_BASE_URL/register.php", $registerData);

echo "Status Code: " . $response['code'] . "\n";
echo "Response: " . json_encode($response['body'], JSON_PRETTY_PRINT) . "\n";

if ($response['code'] === 201 && $response['body']['success']) {
    echo "✅ Registration: PASSED\n";
    $studentId = $response['body']['student']['StudentID'];
    $token = $response['body']['token'];
} else {
    echo "❌ Registration: FAILED\n";
    exit(1);
}

echo "\n";

/**
 * Test 2: Duplicate Registration (Should Fail)
 */
echo "Test 2: Duplicate Registration\n";
echo "--------------------\n";

$response = makeRequest("$API_BASE_URL/register.php", $registerData);

echo "Status Code: " . $response['code'] . "\n";
echo "Response: " . json_encode($response['body'], JSON_PRETTY_PRINT) . "\n";

if ($response['code'] === 409) {
    echo "✅ Duplicate Registration Prevention: PASSED\n";
} else {
    echo "❌ Duplicate Registration Prevention: FAILED\n";
}

echo "\n";

/**
 * Test 3: Login
 */
echo "Test 3: Login\n";
echo "--------------------\n";

$loginData = [
    'email' => $TEST_EMAIL,
    'password' => $TEST_PASSWORD
];

$response = makeRequest("$API_BASE_URL/login.php", $loginData);

echo "Status Code: " . $response['code'] . "\n";
echo "Response: " . json_encode($response['body'], JSON_PRETTY_PRINT) . "\n";

if ($response['code'] === 200 && $response['body']['success']) {
    echo "✅ Login: PASSED\n";
} else {
    echo "❌ Login: FAILED\n";
}

echo "\n";

/**
 * Test 4: Invalid Login
 */
echo "Test 4: Invalid Login\n";
echo "--------------------\n";

$invalidLoginData = [
    'email' => $TEST_EMAIL,
    'password' => 'WrongPassword123'
];

$response = makeRequest("$API_BASE_URL/login.php", $invalidLoginData);

echo "Status Code: " . $response['code'] . "\n";

if ($response['code'] === 401) {
    echo "✅ Invalid Login Prevention: PASSED\n";
} else {
    echo "❌ Invalid Login Prevention: FAILED\n";
}

echo "\n";

/**
 * Test 5: Forgot Password (Request OTP)
 */
echo "Test 5: Forgot Password (Request OTP)\n";
echo "--------------------\n";

$forgotData = [
    'email' => $TEST_EMAIL
];

$response = makeRequest("$API_BASE_URL/forgot_password.php", $forgotData);

echo "Status Code: " . $response['code'] . "\n";
echo "Response: " . json_encode($response['body'], JSON_PRETTY_PRINT) . "\n";

if ($response['code'] === 200 && $response['body']['success']) {
    echo "✅ Forgot Password: PASSED\n";

    // In debug mode, OTP is returned in response
    $otpCode = $response['body']['debug_otp'] ?? null;

    if ($otpCode) {
        echo "Debug OTP: $otpCode\n";
    } else {
        echo "⚠️  OTP sent to email. Check your inbox.\n";
        echo "Enter OTP code: ";
        $otpCode = trim(fgets(STDIN));
    }
} else {
    echo "❌ Forgot Password: FAILED\n";
    exit(1);
}

echo "\n";

/**
 * Test 6: Verify OTP
 */
if (isset($otpCode)) {
    echo "Test 6: Verify OTP\n";
    echo "--------------------\n";

    $verifyData = [
        'email' => $TEST_EMAIL,
        'otp_code' => $otpCode
    ];

    $response = makeRequest("$API_BASE_URL/verify_otp.php", $verifyData);

    echo "Status Code: " . $response['code'] . "\n";
    echo "Response: " . json_encode($response['body'], JSON_PRETTY_PRINT) . "\n";

    if ($response['code'] === 200 && $response['body']['valid']) {
        echo "✅ Verify OTP: PASSED\n";
    } else {
        echo "❌ Verify OTP: FAILED\n";
    }

    echo "\n";

    /**
     * Test 7: Reset Password
     */
    echo "Test 7: Reset Password\n";
    echo "--------------------\n";

    $resetData = [
        'email' => $TEST_EMAIL,
        'otp_code' => $otpCode,
        'new_password' => $TEST_NEW_PASSWORD
    ];

    $response = makeRequest("$API_BASE_URL/reset_password.php", $resetData);

    echo "Status Code: " . $response['code'] . "\n";
    echo "Response: " . json_encode($response['body'], JSON_PRETTY_PRINT) . "\n";

    if ($response['code'] === 200 && $response['body']['success']) {
        echo "✅ Reset Password: PASSED\n";
    } else {
        echo "❌ Reset Password: FAILED\n";
    }

    echo "\n";

    /**
     * Test 8: Login with New Password
     */
    echo "Test 8: Login with New Password\n";
    echo "--------------------\n";

    $loginNewData = [
        'email' => $TEST_EMAIL,
        'password' => $TEST_NEW_PASSWORD
    ];

    $response = makeRequest("$API_BASE_URL/login.php", $loginNewData);

    echo "Status Code: " . $response['code'] . "\n";

    if ($response['code'] === 200 && $response['body']['success']) {
        echo "✅ Login with New Password: PASSED\n";
    } else {
        echo "❌ Login with New Password: FAILED\n";
    }

    echo "\n";
}

/**
 * Test 9: Validation - Missing Fields
 */
echo "Test 9: Validation - Missing Required Fields\n";
echo "--------------------\n";

$invalidData = [
    'email' => $TEST_EMAIL
    // Missing password
];

$response = makeRequest("$API_BASE_URL/register.php", $invalidData);

echo "Status Code: " . $response['code'] . "\n";

if ($response['code'] === 400) {
    echo "✅ Validation (Missing Fields): PASSED\n";
} else {
    echo "❌ Validation (Missing Fields): FAILED\n";
}

echo "\n";

/**
 * Test 10: Validation - Invalid Email
 */
echo "Test 10: Validation - Invalid Email Format\n";
echo "--------------------\n";

$invalidEmailData = [
    'nickname' => 'Test',
    'first_name' => 'Test',
    'last_name' => 'User',
    'email' => 'invalid-email',
    'password' => 'password123'
];

$response = makeRequest("$API_BASE_URL/register.php", $invalidEmailData);

echo "Status Code: " . $response['code'] . "\n";

if ($response['code'] === 400) {
    echo "✅ Validation (Invalid Email): PASSED\n";
} else {
    echo "❌ Validation (Invalid Email): FAILED\n";
}

echo "\n";

/**
 * Summary
 */
echo "========================================\n";
echo "Test Summary\n";
echo "========================================\n";
echo "All critical tests completed!\n\n";

echo "Test Account Created:\n";
echo "  Email: $TEST_EMAIL\n";
echo "  Password: $TEST_NEW_PASSWORD\n";
echo "  Student ID: " . ($studentId ?? 'N/A') . "\n\n";

echo "⚠️  Note: Remember to clean up test data from database\n";
echo "========================================\n";

?>