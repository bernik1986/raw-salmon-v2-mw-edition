<?php

declare(strict_types=1);

use App\Auth;
use App\Database;
use App\Security;

function app_config(?string $key = null, mixed $default = null): mixed
{
    static $config = null;
    if ($config === null) {
        $config = require APP_BASE_PATH . '/config/config.php';
    }

    if ($key === null) {
        return $config;
    }

    return $config[$key] ?? $default;
}

function db(): PDO
{
    return Database::connect();
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flash(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(Security::csrfToken()) . '">';
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        exit('Method not allowed');
    }

    Security::verifyCsrf($_POST['csrf_token'] ?? null);
}

function auth(): Auth
{
    static $auth = null;
    if ($auth === null) {
        $auth = new Auth(db());
    }
    return $auth;
}

function current_user(): ?array
{
    return auth()->user();
}

function require_auth(): array
{
    return auth()->requireUser();
}

function require_admin(): array
{
    return auth()->requireAdmin();
}

function asset(string $path): string
{
    return public_base_url() . '/assets/' . ltrim($path, '/');
}

function public_base_url(): string
{
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot && is_file($documentRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'style.css')) {
        return '';
    }
    if ($documentRoot && is_file($documentRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'style.css')) {
        return '/public';
    }

    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    return str_contains($scriptName, '/public/') ? '' : '/public';
}

function is_installed(): bool
{
    return is_file(APP_BASE_PATH . '/config/local.php') && is_file((string) app_config('db_path'));
}

function ensure_installed(): void
{
    if (!is_installed()) {
        redirect('/install.php');
    }
}
