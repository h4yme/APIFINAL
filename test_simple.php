<?php

// Simple API test - returns pure JSON

header('Content-Type: application/json');

 

echo json_encode([

    'success' => true,

    'message' => 'API is working!',

    'timestamp' => time(),

    'php_version' => PHP_VERSION,

    'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'

]);

?>