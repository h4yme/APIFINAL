<?php

/**
 * LiteRise Reset Password API
 * POST /api/reset_password.php
 *
 * Resets password after OTP verification
 *
 * Request Body:
 * {
 *   "email": "student@example.com",
 *   "otp_code": "123456",
 *   "new_password": "newpassword123"
 * }
 *
 * Response (Success):
 * {
 *   "success": true,
 *   "message": "Password reset successfully"
 * }
 *
 * Response (Error):
 * {
 *   "success": false,
 *   "error": "Invalid OTP or password reset failed"
 * }
 */

require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

// Get JSON input
$data = getJsonInput();

$email = trim($data['email'] ?? '');
$otpCode = trim($data['otp_code'] ?? '');
$newPassword = $data['new_password'] ?? '';

// Validate required fields
if (empty($email) || empty($otpCode) || empty($newPassword)) {
    sendError("Email, OTP code, and new password are required", 400);
}

// Validate email format
if (!isValidEmail($email)) {
    sendError("Invalid email format", 400);
}

// Validate OTP format (6 digits)
if (!preg_match('/^\d{6}$/', $otpCode)) {
    sendError("OTP code must be 6 digits", 400);
}

// Validate new password strength
if (strlen($newPassword) < 6) {
    sendError("New password must be at least 6 characters long", 400);
}

// Optional: Check password strength
if (strlen($newPassword) < 8) {
    // You can add warnings but not block
    // For now, we allow 6+ characters
}

// Check if password contains at least one letter and one number (optional)
// Uncomment if you want stronger password requirements:
// if (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
//     sendError("Password must contain at least one letter and one number", 400);
// }

try {
    // Hash the new password
    $hashedPassword = hashPassword($newPassword);

    // Reset password using stored procedure
    $stmt = $conn->prepare("
        EXEC SP_ResetPassword
            @Email = :email,
            @OTPCode = :otpCode,
            @NewPassword = :newPassword
    ");

    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->bindValue(':otpCode', $otpCode, PDO::PARAM_STR);
    $stmt->bindValue(':newPassword', $hashedPassword, PDO::PARAM_STR);

    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check if password reset was successful
    $success = isset($result['Success']) && (int)$result['Success'] === 1;

    if (!$success) {
        $errorMessage = $result['Message'] ?? 'Password reset failed';

        // Common error cases
        if (strpos($errorMessage, 'Invalid') !== false || strpos($errorMessage, 'expired') !== false) {
            sendError($errorMessage, 400);
        } else if (strpos($errorMessage, 'used') !== false) {
            sendError('This OTP has already been used. Please request a new one.', 400);
        } else {
            sendError($errorMessage, 500);
        }
    }

    // Get student info for logging
    $stmtStudent = $conn->prepare("
        SELECT StudentID, FirstName
        FROM Students
        WHERE Email = :email
    ");
    $stmtStudent->bindValue(':email', $email, PDO::PARAM_STR);
    $stmtStudent->execute();
    $student = $stmtStudent->fetch(PDO::FETCH_ASSOC);

    // Log successful password reset
    if ($student) {
        logActivity($student['StudentID'], 'Password Reset', 'Password successfully reset via OTP');
    }

    // Return success response
    $response = [
        'success' => true,
        'message' => 'Password reset successfully! You can now login with your new password.',
        'note' => 'Please login with your new credentials'
    ];

    sendResponse($response, 200);

} catch (PDOException $e) {
    error_log("Password reset error: " . $e->getMessage());
    sendError("Unable to reset password. Please try again.", 500);

} catch (Exception $e) {
    error_log("Password reset error: " . $e->getMessage());
    sendError("An error occurred during password reset", 500);
}

?>