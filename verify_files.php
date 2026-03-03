<?php

/**

 * Verify that API files have correct content

 */

?>

<!DOCTYPE html>

<html>

<head>

    <title>File Verification</title>

    <style>

        body { font-family: monospace; padding: 20px; background: #f5f5f5; }

        .file { background: white; padding: 15px; margin: 10px 0; border-radius: 8px; }

        .correct { border-left: 4px solid #28a745; }

        .wrong { border-left: 4px solid #dc3545; }

        .unknown { border-left: 4px solid #ffc107; }

        h2 { margin: 0 0 10px 0; }

        pre { background: #f8f8f8; padding: 10px; border-radius: 4px; font-size: 12px; }

        .status { font-weight: bold; padding: 5px 10px; border-radius: 4px; }

        .status.ok { background: #d4edda; color: #155724; }

        .status.error { background: #f8d7da; color: #721c24; }

    </style>

</head>

<body>

    <h1>🔍 API Files Verification</h1>

    <p>Checking if your API files have the correct content...</p>

 

    <?php

    $files = [

        'register.php' => [

            'should_contain' => 'LiteRise Student Registration API',

            'should_not_contain' => 'Test script for LiteRise',

            'type' => 'API Endpoint'

        ],

        'login.php' => [

            'should_contain' => 'LiteRise Student Login API',

            'should_not_contain' => 'Test script',

            'type' => 'API Endpoint'

        ],

        'forgot_password.php' => [

            'should_contain' => 'LiteRise Forgot Password API',

            'should_not_contain' => 'Test script',

            'type' => 'API Endpoint'

        ],

        'verify_otp.php' => [

            'should_contain' => 'LiteRise Verify OTP API',

            'should_not_contain' => 'Test script',

            'type' => 'API Endpoint'

        ],

        'reset_password.php' => [

            'should_contain' => 'LiteRise Reset Password API',

            'should_not_contain' => 'Test script',

            'type' => 'API Endpoint'

        ],

        'test_registration.php' => [

            'should_contain' => 'Test script for LiteRise',

            'should_not_contain' => null,

            'type' => 'Test Script'

        ],

        'src/db.php' => [

            'should_contain' => 'Database Connection and Helper Functions',

            'should_not_contain' => null,

            'type' => 'Utility'

        ],

        'src/auth.php' => [

            'should_contain' => 'JWT Authentication Middleware',

            'should_not_contain' => null,

            'type' => 'Utility'

        ],

        'src/email.php' => [

            'should_contain' => 'Email Utility',

            'should_not_contain' => null,

            'type' => 'Utility'

        ]

    ];

 

    foreach ($files as $filename => $checks) {

        $filepath = __DIR__ . '/' . $filename;

        $exists = file_exists($filepath);

 

        echo '<div class="file ';

 

        if (!$exists) {

            echo 'wrong">';

            echo '<h2>❌ ' . htmlspecialchars($filename) . '</h2>';

            echo '<p class="status error">FILE NOT FOUND</p>';

            echo '<p>Expected: ' . $checks['type'] . '</p>';

        } else {

            $content = file_get_contents($filepath);

            $first_500 = substr($content, 0, 500);

 

            $has_correct = strpos($content, $checks['should_contain']) !== false;

            $has_wrong = $checks['should_not_contain'] ? strpos($content, $checks['should_not_contain']) !== false : false;

 

            if ($has_correct && !$has_wrong) {

                echo 'correct">';

                echo '<h2>✅ ' . htmlspecialchars($filename) . '</h2>';

                echo '<p class="status ok">CORRECT - ' . $checks['type'] . '</p>';

            } else if ($has_wrong) {

                echo 'wrong">';

                echo '<h2>❌ ' . htmlspecialchars($filename) . '</h2>';

                echo '<p class="status error">WRONG CONTENT - This file has test script content!</p>';

                echo '<p><strong>Problem:</strong> Found "' . htmlspecialchars($checks['should_not_contain']) . '"</p>';

                echo '<p><strong>Expected:</strong> "' . htmlspecialchars($checks['should_contain']) . '"</p>';

                echo '<p><strong>Fix:</strong> Replace this file with the correct version from git</p>';

            } else {

                echo 'unknown">';

                echo '<h2>⚠️ ' . htmlspecialchars($filename) . '</h2>';

                echo '<p class="status error">WRONG CONTENT - Missing expected content</p>';

                echo '<p><strong>Expected to find:</strong> "' . htmlspecialchars($checks['should_contain']) . '"</p>';

                echo '<p><strong>Fix:</strong> Replace this file with the correct version from git</p>';

            }

 

            echo '<details>';

            echo '<summary>Show first 500 characters</summary>';

            echo '<pre>' . htmlspecialchars($first_500) . '</pre>';

            echo '</details>';

        }

 

        echo '</div>';

    }

    ?>

 

    <div class="file" style="background: #e7f3ff; border-left: 4px solid #0066cc;">

        <h2>📝 How to Fix Wrong Files</h2>

        <ol>

            <li>Go to your git repository: <code>C:\path\to\LiteRise</code></li>

            <li>Run: <code>git pull origin claude/literise-reading-app-hoohN</code></li>

            <li>Copy the correct files:

                <pre>copy api\register.php C:\xampp\htdocs\api\register.php

copy api\forgot_password.php C:\xampp\htdocs\api\forgot_password.php

copy api\verify_otp.php C:\xampp\htdocs\api\verify_otp.php

copy api\reset_password.php C:\xampp\htdocs\api\reset_password.php

copy api\src\email.php C:\xampp\htdocs\api\src\email.php</pre>

            </li>

            <li>Refresh this page to verify</li>

        </ol>

    </div>

 

</body>

</html>