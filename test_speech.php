<?php
require_once __DIR__ . '/vendor/autoload.php';
use Google\Cloud\Speech\V1\SpeechClient;

try {
    $speech = new SpeechClient([
        'credentials' => __DIR__ . '/google-cloud-credentials.json'
    ]);
    echo "✅ Google Cloud Speech API connected successfully!\n";
    echo "Your pronunciation assessment is ready to use.\n";
    $speech->close();
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
