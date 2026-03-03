<?php
/**
 * Test a specific endpoint and show what it returns
 */

$endpoint = $_GET['endpoint'] ?? 'register.php';
$method = $_GET['method'] ?? 'POST';

$testData = [
    'nickname' => 'TestUser',
    'first_name' => 'Test',
    'last_name' => 'Student',
    'email' => 'test_' . time() . '@example.com',
    'password' => 'password123',
    'grade_level' => '1'
];

?>
<!DOCTYPE html>
<html>
<head>
    <title>Endpoint Tester</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { color: #333; border-bottom: 2px solid #667eea; padding-bottom: 10px; }
        pre { background: #f8f8f8; padding: 15px; border-radius: 4px; overflow-x: auto; }
        .success { color: #28a745; }
        .error { color: #dc3545; }
        button { background: #667eea; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; }
        button:hover { background: #5568d3; }
        input, select { padding: 8px; margin: 5px; border: 1px solid #ddd; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>🔍 Endpoint Tester</h1>

    <div class="section">
        <h2>Configure Test</h2>
        <form method="GET">
            <label>Endpoint:
                <select name="endpoint" onchange="this.form.submit()">
                    <option value="register.php" <?= $endpoint === 'register.php' ? 'selected' : '' ?>>register.php</option>
                    <option value="login.php" <?= $endpoint === 'login.php' ? 'selected' : '' ?>>login.php</option>
                    <option value="forgot_password.php" <?= $endpoint === 'forgot_password.php' ? 'selected' : '' ?>>forgot_password.php</option>
                    <option value="test_simple.php" <?= $endpoint === 'test_simple.php' ? 'selected' : '' ?>>test_simple.php</option>
                </select>
            </label>
        </form>
        <p>Testing: <strong><?= htmlspecialchars($endpoint) ?></strong></p>
    </div>

    <div class="section">
        <h2>Test Request Data</h2>
        <pre><?= json_encode($testData, JSON_PRETTY_PRINT) ?></pre>
    </div>

    <div class="section">
        <h2>JavaScript Fetch Test</h2>
        <button onclick="testEndpoint()">Test Endpoint</button>
        <div id="result"></div>
    </div>

    <div class="section">
        <h2>PHP cURL Test</h2>
        <?php
        $url = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/' . $endpoint;
        echo "<p>URL: <code>$url</code></p>";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        echo "<h3>Response:</h3>";
        echo "<p>HTTP Code: <strong>$httpCode</strong></p>";
        echo "<p>Content-Type: <strong>$contentType</strong></p>";
        echo "<h4>Raw Response:</h4>";
        echo "<pre>" . htmlspecialchars($response) . "</pre>";

        echo "<h4>Parsed as JSON:</h4>";
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo "<pre class='success'>" . json_encode($decoded, JSON_PRETTY_PRINT) . "</pre>";
        } else {
            echo "<pre class='error'>JSON Parse Error: " . json_last_error_msg() . "</pre>";
            echo "<p class='error'>First 200 characters:</p>";
            echo "<pre class='error'>" . htmlspecialchars(substr($response, 0, 200)) . "</pre>";
        }
        ?>
    </div>

    <script>
        const endpoint = '<?= $endpoint ?>';
        const testData = <?= json_encode($testData) ?>;

        async function testEndpoint() {
            const resultDiv = document.getElementById('result');
            resultDiv.innerHTML = '<p>Testing...</p>';

            try {
                const url = window.location.origin + window.location.pathname.replace('test_endpoint.php', '') + endpoint;
                console.log('Fetching:', url);
                console.log('Data:', testData);

                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(testData)
                });

                const contentType = response.headers.get('content-type');
                const text = await response.text();

                let html = '<h3>JavaScript Fetch Result:</h3>';
                html += '<p>Status: <strong>' + response.status + '</strong></p>';
                html += '<p>Content-Type: <strong>' + contentType + '</strong></p>';
                html += '<h4>Raw Response:</h4>';
                html += '<pre>' + text.substring(0, 500) + '</pre>';

                try {
                    const json = JSON.parse(text);
                    html += '<h4>Parsed JSON:</h4>';
                    html += '<pre class="success">' + JSON.stringify(json, null, 2) + '</pre>';
                } catch (e) {
                    html += '<pre class="error">JSON Parse Error: ' + e.message + '</pre>';
                }

                resultDiv.innerHTML = html;
            } catch (error) {
                resultDiv.innerHTML = '<pre class="error">Error: ' + error.message + '</pre>';
            }
        }
    </script>
</body>
</html>