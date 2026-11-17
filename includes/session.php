<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function is_authenticated(): bool
{
    return !empty($_SESSION['user_id']);
}

function current_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function csrf_valid(?string $token): bool
{
    return is_string($token)
        && $token !== ''
        && hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token);
}

function set_flash(string $key, string $message): void
{
    $_SESSION['flash_' . $key] = $message;
}

function take_flash(string $key): string
{
    $value = (string)($_SESSION['flash_' . $key] ?? '');
    unset($_SESSION['flash_' . $key]);
    return $value;
}

function enforce_session_timeout(): void
{
    if (!is_authenticated()) {
        return;
    }

    $idleLimit = SESSION_IDLE_MINUTES * 60;
    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);

    if ($lastActivity > 0 && (time() - $lastActivity) > $idleLimit) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'secure' => $params['secure'] ?? false,
                'httponly' => $params['httponly'] ?? true,
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        header('Location: index.php?timeout=1');
        exit;
    }

    $_SESSION['last_activity'] = time();
}

function require_auth(): void
{
    if (!is_authenticated()) {
        header('Location: index.php');
        exit;
    }

    enforce_session_timeout();
}
