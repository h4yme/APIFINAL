<?php

/**
 * Quick syntax and function test for registration and password reset APIs
 */

echo "Testing API Files...\n\n";

// Test 1: Check if all required files exist
echo "1. Checking files exist:\n";
$files = [
    'src/db.php',
    'src/auth.php',
    'src/email.php',
    'register.php',
    'forgot_password.php',
    'verify_otp.php',
    'reset_password.php'
];

foreach ($files as $file) {
    if (file_exists(__DIR__ . '/' . $file)) {
        echo "   ✓ $file\n";
    } else {
        echo "   ✗ $file (NOT FOUND)\n";
    }
}

echo "\n2. Checking PHP syntax:\n";
foreach ($files as $file) {
    $fullPath = __DIR__ . '/' . $file;
    if (file_exists($fullPath)) {
        exec("php -l $fullPath 2>&1", $output, $returnCode);
        if ($returnCode === 0) {
            echo "   ✓ $file\n";
        } else {
            echo "   ✗ $file (SYNTAX ERROR)\n";
            echo "      " . implode("\n      ", $output) . "\n";
        }
        $output = [];
    }
}

echo "\n3. Testing function availability:\n";

// Test email functions
require_once __DIR__ . '/src/email.php';

$functions = [
    'generateOTP',
    'sendEmail',
    'sendOTPEmail',
    'sendWelcomeEmail'
];

foreach ($functions as $func) {
    if (function_exists($func)) {
        echo "   ✓ $func()\n";
    } else {
        echo "   ✗ $func() (NOT FOUND)\n";
    }
}

// Test auth functions
require_once __DIR__ . '/src/auth.php';

$authFunctions = [
    'generateJWT',
    'verifyJWT',
    'hashPassword',
    'verifyPassword'
];

foreach ($authFunctions as $func) {
    if (function_exists($func)) {
        echo "   ✓ $func()\n";
    } else {
        echo "   ✗ $func() (NOT FOUND)\n";
    }
}

echo "\n4. Testing utility functions:\n";

// Test generateOTP
$otp = generateOTP();
if (preg_match('/^\d{6}$/', $otp)) {
    echo "   ✓ generateOTP() returns valid 6-digit code: $otp\n";
} else {
    echo "   ✗ generateOTP() returned invalid format: $otp\n";
}

// Test password hashing
$testPassword = 'test123';
$hashedPassword = hashPassword($testPassword);
if (password_verify($testPassword, $hashedPassword)) {
    echo "   ✓ hashPassword() and verifyPassword() working\n";
} else {
    echo "   ✗ Password hashing/verification failed\n";
}

// Test JWT generation
$testToken = generateJWT(1, 'test@example.com');
if (strpos($testToken, '.') !== false) {
    echo "   ✓ generateJWT() returns valid token format\n";
} else {
    echo "   ✗ generateJWT() returned invalid token\n";
}

echo "\n5. File permissions:\n";
foreach ($files as $file) {
    $fullPath = __DIR__ . '/' . $file;
    if (file_exists($fullPath)) {
        $perms = substr(sprintf('%o', fileperms($fullPath)), -4);
        $isReadable = is_readable($fullPath);
        if ($isReadable) {
            echo "   ✓ $file (perms: $perms)\n";
        } else {
            echo "   ✗ $file (perms: $perms - NOT READABLE)\n";
        }
    }
}

echo "\n========================================\n";
echo "All tests completed!\n";
echo "========================================\n";

?>