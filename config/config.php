<?php
declare(strict_types=1);

$configOverrides = is_file(__DIR__ . '/config.local.php')
    ? (require __DIR__ . '/config.local.php')
    : [];
if (!is_array($configOverrides)) {
    $configOverrides = [];
}

$envValue = static function (string $key, string $default): string {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
};

$setting = static function (string $key, string $default) use ($configOverrides, $envValue): string {
    if (isset($configOverrides[$key]) && $configOverrides[$key] !== '') {
        return (string)$configOverrides[$key];
    }
    return $envValue($key, $default);
};

if (!defined('DB_HOST')) {
    define('DB_HOST', $setting('DB_HOST', '127.0.0.1'));
}
if (!defined('DB_PORT')) {
    define('DB_PORT', $setting('DB_PORT', '3306'));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', $setting('DB_NAME', 'school_clinic_inventory'));
}
if (!defined('DB_USER')) {
    define('DB_USER', $setting('DB_USER', 'root'));
}
if (!defined('DB_PASS')) {
    define('DB_PASS', $setting('DB_PASS', ''));
}
if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', rtrim($setting('APP_BASE_URL', ''), '/'));
}
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', $setting('APP_TIMEZONE', 'Asia/Manila'));
}
if (!defined('MAIL_FROM')) {
    define('MAIL_FROM', $setting('MAIL_FROM', 'no-reply@school.edu.ph'));
}
if (!defined('SESSION_IDLE_MINUTES')) {
    define('SESSION_IDLE_MINUTES', max(5, (int)$setting('SESSION_IDLE_MINUTES', '30')));
}
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', filter_var($setting('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN));
}
if (!defined('SHOW_RESET_LINK')) {
    define('SHOW_RESET_LINK', filter_var($setting('SHOW_RESET_LINK', 'false'), FILTER_VALIDATE_BOOLEAN));
}

date_default_timezone_set(APP_TIMEZONE);
