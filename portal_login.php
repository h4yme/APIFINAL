<?php
/**
 * LiteRise Portal Login
 * POST /api/portal_login.php
 *
 * Form params (application/x-www-form-urlencoded):
 *   email    – account email
 *   password – plain-text password
 *   role     – "admin" | "teacher"
 *
 * Returns: { success, user_id, name, email, role, token, message }
 */

require_once 'src/db.php';   // sets $conn (PDO), sendResponse(), sendError(), headers
require_once 'src/auth.php'; // provides generateJWT()

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('POST required.', 405);
}

$email    = trim($_POST['email']    ?? '');
$password = trim($_POST['password'] ?? '');
$role     = strtolower(trim($_POST['role'] ?? 'admin'));

if ($email === '' || $password === '') {
    sendError('Email and password are required.', 400);
}

if (!in_array($role, ['admin', 'teacher'], true)) {
    sendError('Invalid role. Must be "admin" or "teacher".', 400);
}

// ── Admin login ────────────────────────────────────────────────────────────
if ($role === 'admin') {

    $stmt = $conn->prepare(
        'SELECT AdminID, Username, Email, PasswordHash, Salt,
                IsActive, FailedLoginAttempts, LockoutEnd
         FROM   dbo.Admins
         WHERE  Email = ?'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        sendError('Invalid email or password.', 401);
    }

    if (!(bool) $row['IsActive']) {
        sendError('This account has been deactivated.', 403);
    }

    if ($row['LockoutEnd'] !== null && strtotime($row['LockoutEnd']) > time()) {
        sendError('Account is temporarily locked. Try again later.', 403);
    }

    if (!password_verify($password, $row['PasswordHash'])) {
        $conn->prepare(
            'UPDATE dbo.Admins SET FailedLoginAttempts = FailedLoginAttempts + 1 WHERE AdminID = ?'
        )->execute([$row['AdminID']]);
        sendError('Invalid email or password.', 401);
    }

    // Success — reset lockout counters, update last login
    $conn->prepare(
        'UPDATE dbo.Admins
         SET FailedLoginAttempts = 0, LockoutEnd = NULL, LastLoginDate = GETDATE()
         WHERE AdminID = ?'
    )->execute([$row['AdminID']]);

    $token = generateJWT($row['AdminID'], $row['Email']);

    sendResponse([
        'user_id' => (int) $row['AdminID'],
        'name'    => $row['Username'],
        'email'   => $row['Email'],
        'role'    => 'admin',
        'token'   => $token,
        'message' => 'Login successful.',
    ]);

// ── Teacher login ──────────────────────────────────────────────────────────
} else {

    $stmt = $conn->prepare(
        'SELECT TeacherID, FirstName, LastName, Email, Password, IsActive
         FROM   dbo.Teachers
         WHERE  Email = ?'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        sendError('Invalid email or password.', 401);
    }

    if (!(bool) $row['IsActive']) {
        sendError('This account has been deactivated.', 403);
    }

    if (!password_verify($password, $row['Password'])) {
        sendError('Invalid email or password.', 401);
    }

    $token = generateJWT($row['TeacherID'], $row['Email']);

    sendResponse([
        'user_id' => (int) $row['TeacherID'],
        'name'    => $row['FirstName'] . ' ' . $row['LastName'],
        'email'   => $row['Email'],
        'role'    => 'teacher',
        'token'   => $token,
        'message' => 'Login successful.',
    ]);
}