<?php

/**
 * LiteRise Email Utility
 * Handles sending emails for OTP and notifications
 * Supports both SMTP (via PHPMailer) and basic PHP mail()
 */

// Only try to use PHPMailer if it's available
$phpmailerAvailable = false;

// Check for Composer vendor autoload
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $phpmailerAvailable = class_exists('PHPMailer\\PHPMailer\\PHPMailer');
}

// Check for PHPMailer in src folder (alternative installation)
if (!$phpmailerAvailable && file_exists(__DIR__ . '/PHPMailer.php')) {
    require_once __DIR__ . '/PHPMailer.php';
    if (file_exists(__DIR__ . '/Exception.php')) {
        require_once __DIR__ . '/Exception.php';
    }
    if (file_exists(__DIR__ . '/SMTP.php')) {
        require_once __DIR__ . '/SMTP.php';
    }
    $phpmailerAvailable = class_exists('PHPMailer\\PHPMailer\\PHPMailer');
}

if (!function_exists('sendEmail')):
function sendEmail($to, $subject, $htmlBody, $from = null) {
    global $phpmailerAvailable;

    if (!$from) {
        $from = $_ENV['EMAIL_FROM'] ?? 'noreply@literise.com';
    }
    $fromName = $_ENV['EMAIL_FROM_NAME'] ?? 'LiteRise';

    $smtpEnabled = isset($_ENV['SMTP_ENABLED']) && $_ENV['SMTP_ENABLED'] === 'true';

    if ($smtpEnabled && $phpmailerAvailable) {
        return sendEmailViaSMTP($to, $subject, $htmlBody, $from, $fromName);
    } else {
        return sendEmailViaBasicPHP($to, $subject, $htmlBody, $from, $fromName);
    }
}
endif;

if (!function_exists('sendEmailViaSMTP')):
function sendEmailViaSMTP($to, $subject, $htmlBody, $from, $fromName) {
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['SMTP_USERNAME'] ?? '';
        $mail->Password = $_ENV['SMTP_PASSWORD'] ?? '';
        $mail->SMTPSecure = $_ENV['SMTP_ENCRYPTION'] ?? 'tls';
        $mail->Port = (int)($_ENV['SMTP_PORT'] ?? 587);
        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);
        $result = $mail->send();
        if ($result) {
            error_log("Email sent successfully via SMTP to: $to");
            return true;
        } else {
            error_log("Failed to send email via SMTP to: $to");
            return false;
        }
    } catch (\Exception $e) {
        error_log("SMTP Email Error: " . $e->getMessage());
        return false;
    }
}
endif;

if (!function_exists('sendEmailViaBasicPHP')):
function sendEmailViaBasicPHP($to, $subject, $htmlBody, $from, $fromName) {
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=utf-8',
        "From: $fromName <$from>",
        "Reply-To: $from",
        'X-Mailer: PHP/' . phpversion()
    ];
    $result = @mail($to, $subject, $htmlBody, implode("\r\n", $headers));
    if (!$result) {
        error_log("Failed to send email to: $to (expected in local dev without SMTP)");
        return true; // don't block registration in dev
    }
    error_log("Email sent successfully to: $to");
    return true;
}
endif;

if (!function_exists('sendOTPEmail')):
function sendOTPEmail($email, $otpCode, $firstName = '') {
    $subject = "LiteRise - Password Reset Code";
    $greeting = $firstName ? "Hi $firstName" : "Hello";
    $htmlBody = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:sans-serif;background:#f5f5f5;margin:0;padding:20px">
  <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden">
    <div style="background:linear-gradient(135deg,#667eea,#764ba2);padding:30px;text-align:center;color:#fff">
      <div style="font-size:32px">📚</div><h1 style="margin:0">LiteRise</h1>
    </div>
    <div style="padding:40px 30px">
      <p>$greeting,</p>
      <p>Use this code to reset your password:</p>
      <div style="background:linear-gradient(135deg,#667eea,#764ba2);border-radius:8px;padding:20px;text-align:center;margin:30px 0">
        <div style="font-size:36px;font-weight:bold;color:#fff;letter-spacing:8px;font-family:monospace">$otpCode</div>
        <div style="color:#fff;font-size:14px;margin-top:10px">Verification Code — expires in 10 minutes</div>
      </div>
      <p style="color:#856404;background:#fff3cd;border-left:4px solid #ffc107;padding:15px;border-radius:4px">
        ⚠️ If you didn't request this, please ignore this email.
      </p>
    </div>
    <div style="background:#f8f9fa;padding:20px;text-align:center;font-size:14px;color:#6c757d">
      © 2025 LiteRise. All rights reserved.
    </div>
  </div>
</body></html>
HTML;
    return sendEmail($email, $subject, $htmlBody);
}
endif;

if (!function_exists('sendWelcomeEmail')):
function sendWelcomeEmail($email, $firstName, $nickname = '') {
    $subject = "Welcome to LiteRise!";
    $displayName = $nickname ?: $firstName;
    $htmlBody = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:sans-serif;background:#f5f5f5;margin:0;padding:20px">
  <div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden">
    <div style="background:linear-gradient(135deg,#667eea,#764ba2);padding:40px;text-align:center;color:#fff">
      <div style="font-size:64px">🦁</div>
      <h1 style="margin:10px 0 0">Welcome to LiteRise!</h1>
    </div>
    <div style="padding:40px 30px">
      <p style="font-size:24px;font-weight:600">Hi $displayName! 👋</p>
      <p>We're excited to have you join our reading adventure! Your account is ready — start with a quick placement test to find the perfect lessons for you.</p>
      <p style="font-weight:600;color:#667eea">Let's learn to read together! 🌟</p>
    </div>
    <div style="background:#f8f9fa;padding:20px;text-align:center;font-size:14px;color:#6c757d">
      Need help? <a href="mailto:support@literise.com" style="color:#667eea">support@literise.com</a><br>
      © 2025 LiteRise. All rights reserved.
    </div>
  </div>
</body></html>
HTML;
    return sendEmail($email, $subject, $htmlBody);
}
endif;

if (!function_exists('generateOTP')):
function generateOTP() {
    return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
}
endif;

?>
