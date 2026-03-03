<?php

/**
 * LiteRise Verify OTP API
 * POST /api/verify_otp.php
 *
 * Verifies OTP code for password reset
 *
 * Request Body:
 * {
 *   "email": "student@example.com",
 *   "otp_code": "123456"
 * }
 *
 * Response (Success):
 * {
 *   "success": true,
 *   "message": "OTP verified successfully",
 *   "valid": true
 * }
 *
 * Response (Error):
 * {
 *   "success": false,
 *   "valid": false,
 *   "error": "Invalid or expired OTP"
 * }
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

// Get JSON input
$data = getJsonInput();

$email = trim($data['email'] ?? '');
$otpCode = trim($data['otp_code'] ?? '');

// Validate required fields
if (empty($email) || empty($otpCode)) {
    sendError("Email and OTP code are required", 400);
}

// Validate email format
if (!isValidEmail($email)) {
    sendError("Invalid email format", 400);
}

// Validate OTP format (6 digits)
if (!preg_match('/^\d{6}$/', $otpCode)) {
    sendError("OTP code must be 6 digits", 400);
}

try {
    // Verify OTP using stored procedure
    $stmt = $conn->prepare("
        EXEC SP_VerifyPasswordResetOTP
            @Email = :email,
            @OTPCode = :otpCode
    ");

    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->bindValue(':otpCode', $otpCode, PDO::PARAM_STR);

    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check verification result
    $isValid = isset($result['IsValid']) && (int)$result['IsValid'] === 1;

    if (!$isValid) {
        $errorMessage = $result['Message'] ?? 'Invalid OTP';

        // Return error with valid flag
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'valid' => false,
            'error' => $errorMessage
        ]);
        exit;
    }

    // OTP is valid
    $response = [
        'success' => true,
        'valid' => true,
        'message' => 'OTP verified successfully',
        'note' => 'You can now reset your password'
    ];

    sendResponse($response, 200);

} catch (PDOException $e) {
    error_log("OTP verification error: " . $e->getMessage());
    sendError("Unable to verify OTP", 500);

} catch (Exception $e) {
    error_log("OTP verification error: " . $e->getMessage());
    sendError("An error occurred", 500);
}

?>