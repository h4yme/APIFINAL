<?php

/**

 * Direct API Test - Tests if files can be accessed directly

 */

 

echo "<h2>LiteRise API Direct Test</h2>";

echo "<p>Testing if API files are accessible...</p>";

 

echo "<h3>1. Current Directory:</h3>";

echo "<pre>" . __DIR__ . "</pre>";

 

echo "<h3>2. Files in this directory:</h3>";

echo "<pre>";

$files = scandir(__DIR__);

foreach ($files as $file) {

    if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'php') {

        echo "✓ $file\n";

    }

}

echo "</pre>";

 

echo "<h3>3. Testing required files:</h3>";

echo "<pre>";

$requiredFiles = [

    'src/db.php',

    'src/auth.php',

    'src/email.php',

    'register.php',

    'login.php',

    'forgot_password.php'

];

 

foreach ($requiredFiles as $file) {

    $path = __DIR__ . '/' . $file;

    if (file_exists($path)) {

        $readable = is_readable($path);

        echo ($readable ? "✓" : "✗") . " $file (" . ($readable ? "readable" : "not readable") . ")\n";

    } else {

        echo "✗ $file (NOT FOUND)\n";

    }

}

echo "</pre>";

 

echo "<h3>4. Testing function includes:</h3>";

echo "<pre>";

 

try {

    require_once __DIR__ . '/src/email.php';

    echo "✓ email.php loaded\n";

 

    if (function_exists('generateOTP')) {

        $otp = generateOTP();

        echo "✓ generateOTP() works: $otp\n";

    }

 

    if (function_exists('sendOTPEmail')) {

        echo "✓ sendOTPEmail() exists\n";

    }

 

    if (function_exists('sendWelcomeEmail')) {

        echo "✓ sendWelcomeEmail() exists\n";

    }

 

} catch (Exception $e) {

    echo "✗ Error: " . $e->getMessage() . "\n";

}

 

try {

    require_once __DIR__ . '/src/auth.php';

    echo "✓ auth.php loaded\n";

 

    if (function_exists('hashPassword')) {

        echo "✓ hashPassword() exists\n";

    }

 

    if (function_exists('generateJWT')) {

        echo "✓ generateJWT() exists\n";

    }

 

} catch (Exception $e) {

    echo "✗ Error: " . $e->getMessage() . "\n";

}

 

echo "</pre>";

 

echo "<h3>5. API Endpoint URLs to Test:</h3>";

echo "<p>Try these URLs in your browser or Postman:</p>";

echo "<ul>";

 

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';

$host = $_SERVER['HTTP_HOST'];

$baseUrl = dirname($_SERVER['PHP_SELF']);

 

echo "<li><strong>Registration:</strong> POST $protocol://$host$baseUrl/register.php</li>";

echo "<li><strong>Login:</strong> POST $protocol://$host$baseUrl/login.php</li>";

echo "<li><strong>Forgot Password:</strong> POST $protocol://$host$baseUrl/forgot_password.php</li>";

echo "<li><strong>Verify OTP:</strong> POST $protocol://$host$baseUrl/verify_otp.php</li>";

echo "<li><strong>Reset Password:</strong> POST $protocol://$host$baseUrl/reset_password.php</li>";

echo "</ul>";

 

echo "<h3>6. Sample cURL Commands:</h3>";

echo "<p>Test registration with this command:</p>";

echo "<pre>";

echo 'curl -X POST ' . $protocol . '://' . $host . $baseUrl . '/register.php \\' . "\n";

echo '  -H "Content-Type: application/json" \\' . "\n";

echo '  -d \'{

    "nickname": "TestUser",

    "first_name": "Test",

    "last_name": "Student",

    "email": "test@example.com",

    "password": "password123",

    "grade_level": "1"

}\'';

echo "</pre>";

 

echo "<h3>7. Environment Check:</h3>";

echo "<pre>";

echo "PHP Version: " . PHP_VERSION . "\n";

echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";

echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "\n";

echo "</pre>";

 

?>