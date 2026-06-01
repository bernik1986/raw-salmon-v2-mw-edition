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
    header('Location: ' . url($path));
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

function url(string $path = ''): string
{
    if ($path !== '' && (str_starts_with($path, '#') || preg_match('~^(?:[a-z][a-z0-9+.-]*:)?//~i', $path))) {
        return $path;
    }

    $base = app_base_url();
    if ($path === '') {
        return $base === '' ? '/' : $base;
    }

    return $base . '/' . ltrim($path, '/');
}

function app_base_url(): string
{
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $basePath = realpath(APP_BASE_PATH);
    if ($documentRoot && $basePath) {
        $relative = filesystem_relative_path($basePath, $documentRoot);
        if ($relative !== null) {
            return filesystem_path_to_url($relative);
        }
    }

    return script_base_url($basePath ?: APP_BASE_PATH);
}

function public_base_url(): string
{
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $publicPath = realpath(APP_BASE_PATH . '/public');
    if ($documentRoot && $publicPath) {
        $relative = filesystem_relative_path($publicPath, $documentRoot);
        if ($relative !== null) {
            return filesystem_path_to_url($relative);
        }
    }

    return rtrim(app_base_url(), '/') . '/public';
}

function filesystem_relative_path(string $path, string $parent): ?string
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $parent = rtrim(str_replace('\\', '/', $parent), '/');
    $comparisonPath = PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    $comparisonParent = PHP_OS_FAMILY === 'Windows' ? strtolower($parent) : $parent;

    if ($comparisonPath === $comparisonParent) {
        return '';
    }

    $prefix = $comparisonParent . '/';
    if (!str_starts_with($comparisonPath, $prefix)) {
        return null;
    }

    return substr($path, strlen($parent) + 1);
}

function filesystem_path_to_url(string $path): string
{
    $segments = array_filter(explode('/', str_replace('\\', '/', trim($path, '/\\'))), 'strlen');
    if (!$segments) {
        return '';
    }

    return '/' . implode('/', array_map('rawurlencode', $segments));
}

function script_base_url(string $rootPath): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptFilename = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if (!$scriptFilename) {
        return '';
    }

    $relative = filesystem_relative_path($scriptFilename, $rootPath);
    if ($relative === null) {
        $publicPath = realpath(APP_BASE_PATH . '/public');
        $relative = $publicPath ? filesystem_relative_path($scriptFilename, $publicPath) : null;
    }
    if ($relative === null || $relative === '') {
        return '';
    }

    $suffix = '/' . str_replace('%2F', '/', rawurlencode(str_replace('\\', '/', $relative)));
    if (!str_ends_with($scriptName, $suffix)) {
        return '';
    }

    return rtrim(substr($scriptName, 0, -strlen($suffix)), '/');
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
