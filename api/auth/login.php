<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/db.php';

$email = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$remember = isset($_POST['remember_me']);

function fail(string $message, string $email): void
{
    $_SESSION['login_error'] = $message;
    $_SESSION['old_email'] = $email;
    header('Location: ../index.php');
    exit;
}

if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    fail('Your session expired. Please try logging in again.', $email);
}

if ($email === '' || $password === '') {
    fail('Both email and password are required.', $email);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('Please enter a valid email address.', $email);
}

if (!preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.edu\.ph$/i', $email)) {
    fail('Email must belong to the authorized institutional domain (@edu.ph).', $email);
}

try {
    $pdo = get_db_connection();
    $stmt = $pdo->prepare('SELECT id, email, password_hash, full_name, role, is_active FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => strtolower($email)]);
    $user = $stmt->fetch();
} catch (Throwable $e) {
    error_log('Login error: ' . $e->getMessage());
    fail('Unable to process login at this time. Please try again later.', $email);
}

if (!$user || !(bool)$user['is_active'] || !password_verify($password, $user['password_hash'])) {
    // Generic message to avoid account enumeration
    fail('Incorrect email or password.', $email);
}

// Prevent session fixation
session_regenerate_id(true);

$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['email'] = $user['email'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role'] = $user['role'];
$_SESSION['last_activity'] = time();

// Optional convenience: extend session cookie for "Remember Me" (30 days)
if ($remember) {
    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => time() + (60 * 60 * 24 * 30),
        'path' => $params['path'] ?? '/',
        'secure' => $params['secure'] ?? false,
        'httponly' => $params['httponly'] ?? true,
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

header('Location: ../dashboard.php');
exit;
