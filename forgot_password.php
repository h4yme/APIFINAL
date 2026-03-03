<?php

/**
 * LiteRise Forgot Password API
 * POST /api/forgot_password.php
 *
 * Initiates password reset by sending OTP to user's email
 *
 * Request Body:
 * {
 *   "email": "student@example.com"
 * }
 *
 * Response (Success):
 * {
 *   "success": true,
 *   "message": "OTP sent to your email",
 *   "email": "stu***@example.com",  // Masked email for security
 *   "expires_in_minutes": 10
 * }
 *
 * Response (Error):
 * {
 *   "success": false,
 *   "error": "Email not found"
 * }
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/email.php';

// Get JSON input
$data = getJsonInput();

$email = trim($data['email'] ?? '');

// Validate required fields
if (empty($email)) {
    sendError("Email is required", 400);
}

// Validate email format
if (!isValidEmail($email)) {
    sendError("Invalid email format", 400);
}

try {
    // Check if email exists and get student info
    $stmt = $conn->prepare("
        SELECT StudentID, FirstName, LastName, Email, IsActive
        FROM Students
        WHERE Email = :email
    ");

    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->execute();

    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    // For security, don't reveal if email exists or not
    // Always show success message but only send OTP if email exists
    if (!$student) {
        // Still return success to prevent email enumeration attacks
        sendResponse([
            'success' => true,
            'message' => 'If this email is registered, you will receive an OTP code shortly.',
            'email' => maskEmail($email),
            'expires_in_minutes' => 10
        ], 200);
    }

    // Check if account is active
    if (!(bool)$student['IsActive']) {
        sendError("This account is inactive. Please contact support.", 403);
    }

    // Generate 6-digit OTP
    $otpCode = generateOTP();

    // Get user's IP address for logging
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

    // Create OTP in database
    $stmt = $conn->prepare("
        EXEC SP_CreatePasswordResetOTP
            @Email = :email,
            @OTPCode = :otpCode,
            @ExpiryMinutes = 10,
            @IPAddress = :ipAddress
    ");

    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->bindValue(':otpCode', $otpCode, PDO::PARAM_STR);
    $stmt->bindValue(':ipAddress', $ipAddress, PDO::PARAM_STR);

    $stmt->execute();
    $otpResult = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check if OTP creation failed
    if (!$otpResult || (int)$otpResult['OTPID'] === -1) {
        $errorMessage = $otpResult['ErrorMessage'] ?? 'Failed to create OTP';
        error_log("OTP creation failed for $email: $errorMessage");
        sendError("Unable to process password reset request", 500);
    }

    // Send OTP via email
    $emailSent = sendOTPEmail($email, $otpCode, $student['FirstName']);

    if (!$emailSent) {
        error_log("Failed to send OTP email to: $email");
        // Still return success to user, but log the error
    }

    // Log activity
    logActivity($student['StudentID'], 'Password Reset Request', "OTP sent to $email");

    // Return success response
    $response = [
        'success' => true,
        'message' => 'Password reset code sent to your email',
        'email' => maskEmail($email),
        'expires_in_minutes' => 10,
        'note' => 'Please check your email for the 6-digit verification code'
    ];

    // In debug mode, include OTP in response (REMOVE IN PRODUCTION!)
    if (($_ENV['DEBUG_MODE'] ?? 'false') === 'true') {
        $response['debug_otp'] = $otpCode;
    }

    sendResponse($response, 200);

} catch (PDOException $e) {
    error_log("Forgot password error: " . $e->getMessage());
    sendError("Unable to process password reset request", 500);

} catch (Exception $e) {
    error_log("Forgot password error: " . $e->getMessage());
    sendError("An error occurred", 500);
}

/**
 * Mask email address for security
 * Example: john.doe@example.com -> j***@example.com
 *
 * @param string $email Email to mask
 * @return string Masked email
 */
function maskEmail($email) {
    $parts = explode('@', $email);

    if (count($parts) !== 2) {
        return '***';
    }

    $username = $parts[0];
    $domain = $parts[1];

    // Show first character + *** + domain
    $maskedUsername = substr($username, 0, 1) . '***';

    return $maskedUsername . '@' . $domain;
}

?>