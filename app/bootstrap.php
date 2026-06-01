<?php

declare(strict_types=1);

define('APP_BASE_PATH', dirname(__DIR__));
define('APP_START_TIME', time());

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = APP_BASE_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require_once APP_BASE_PATH . '/app/helpers.php';

$config = app_config();
date_default_timezone_set($config['timezone']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['session_name']);
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $sessionPath = (string) ($config['session_storage_path'] ?? '');
    if ($sessionPath !== '') {
        if (!is_dir($sessionPath)) {
            @mkdir($sessionPath, 0775, true);
        }
        if (is_dir($sessionPath) && is_writable($sessionPath)) {
            session_save_path($sessionPath);
        }
    }

    @session_start();
}

define('APP_SESSION_READY', session_status() === PHP_SESSION_ACTIVE);

if (!APP_SESSION_READY && is_file(APP_BASE_PATH . '/config/local.php')) {
    http_response_code(500);
    exit('Session storage is not writable. Check storage/sessions directory permissions.');
}
