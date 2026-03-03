<?php

/**

 * LiteRise JWT Authentication Middleware

 * Handles JWT token generation and verification

 */

 

/**

 * Generate JWT token for authenticated user

 *

 * @param int $studentID Student ID

 * @param string $email Student email

 * @param int $expiryDays Number of days until token expires (default 7)

 * @return string JWT token

 */

if (!function_exists('generateJWT')) {
function generateJWT($studentID, $email, $expiryDays = 7) {

    $secret = $_ENV['JWT_SECRET'] ?? 'default_secret_change_this';

    $issuedAt = time();

    $expire = $issuedAt + (60 * 60 * 24 * $expiryDays);

 

    $header = json_encode([

        'typ' => 'JWT',

        'alg' => 'HS256'

    ]);

 

    $payload = json_encode([

        'iat' => $issuedAt,

        'exp' => $expire,

        'studentID' => $studentID,

        'email' => $email

    ]);

 

    $base64UrlHeader = base64UrlEncode($header);

    $base64UrlPayload = base64UrlEncode($payload);

 

    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);

    $base64UrlSignature = base64UrlEncode($signature);

 

    $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

 

    return $jwt;

}
}

 

/**

 * Verify and decode JWT token

 *

 * @param string $jwt JWT token

 * @return array|false Decoded payload or false if invalid

 */

if (!function_exists('verifyJWT')) {
function verifyJWT($jwt) {

    $secret = $_ENV['JWT_SECRET'] ?? 'default_secret_change_this';

 

    // Split token into parts

    $tokenParts = explode('.', $jwt);

 

    if (count($tokenParts) !== 3) {

        return false;

    }

 

    list($base64UrlHeader, $base64UrlPayload, $base64UrlSignature) = $tokenParts;

 

    // Verify signature

    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);

    $base64UrlSignatureCheck = base64UrlEncode($signature);

 

    if ($base64UrlSignature !== $base64UrlSignatureCheck) {

        return false;

    }

 

    // Decode payload

    $payload = json_decode(base64UrlDecode($base64UrlPayload), true);

 

    if (!$payload) {

        return false;

    }

 

    // Check expiration

    if (isset($payload['exp']) && $payload['exp'] < time()) {

        return false; // Token expired

    }

 

    return $payload;

}
}

 

/**

 * Base64 URL encode

 *

 * @param string $data Data to encode

 * @return string Encoded string

 */

if (!function_exists('base64UrlEncode')) {
function base64UrlEncode($data) {

    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

}
}

 

/**

 * Base64 URL decode

 *

 * @param string $data Data to decode

 * @return string Decoded string

 */

if (!function_exists('base64UrlDecode')) {
function base64UrlDecode($data) {

    return base64_decode(strtr($data, '-_', '+/'));

}
}

 

/**

 * Get JWT token from request headers

 *

 * @return string|null JWT token or null if not found

 */

if (!function_exists('getTokenFromRequest')) {
function getTokenFromRequest() {

    $headers = getallheaders();

 

    // Check Authorization header

    if (isset($headers['Authorization'])) {

        $authHeader = $headers['Authorization'];

 

        // Format: "Bearer <token>"

        if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {

            return $matches[1];

        }

 

        // Direct token without "Bearer"

        return $authHeader;

    }

 

    // Check for token in query parameter (fallback)

    if (isset($_GET['token'])) {

        return $_GET['token'];

    }

 

    return null;

}
}

 

/**

 * Require authentication - verify JWT and return user data

 * Call this at the start of protected endpoints

 *

 * @return array User data from token (studentID, email)

 */

if (!function_exists('requireAuth')) {
function requireAuth() {

    $token = getTokenFromRequest();

 

    if (!$token) {

        sendError("Authentication required. No token provided.", 401);

    }

 

    $payload = verifyJWT($token);

 

    if (!$payload) {

        sendError("Invalid or expired token", 401);

    }

 

    // Return authenticated user data

    return [

        'studentID' => $payload['studentID'] ?? null,

        'email' => $payload['email'] ?? null

    ];

}
}

 

/**

 * Hash password using bcrypt

 *

 * @param string $password Plain text password

 * @return string Hashed password

 */

if (!function_exists('hashPassword')) {
function hashPassword($password) {

    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

}
}

 

/**

 * Verify password against hash

 *

 * @param string $password Plain text password

 * @param string $hash Hashed password

 * @return bool True if password matches

 */

if (!function_exists('verifyPassword')) {
function verifyPassword($password, $hash) {

    return password_verify($password, $hash);

}
}

 

/**

 * Check if password needs rehashing (security upgrade)

 *

 * @param string $hash Current password hash

 * @return bool True if needs rehash

 */

if (!function_exists('needsRehash')) {
function needsRehash($hash) {

    return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);

}
}

?>