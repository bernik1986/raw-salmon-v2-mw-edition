<?php

declare(strict_types=1);

namespace App;

final class View
{
    public static function header(string $title, ?array $user = null): void
    {
        $appName = e(app_config('app_name'));
        $pageTitle = e($title);
        $styleHref = e(asset('style.css'));
        $user ??= current_user();
        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$pageTitle} - {$appName}</title>
    <link rel="stylesheet" href="{$styleHref}">
</head>
<body>
<div class="app-shell">
HTML;

        if ($user) {
            self::sidebar($user);
        }

        echo '<main class="main">';
        echo '<div class="topbar"><div><div class="product-kicker">Mail Test Sender</div><h1>' . $pageTitle . '</h1><p>' . e($appName) . '</p></div>';
        if ($user) {
            echo '<form method="post" action="' . e(url('/logout.php')) . '" class="logout-form">' . csrf_field() . '<button type="submit">Logout</button></form>';
        }
        echo '</div>';

        foreach (consume_flash() as $item) {
            echo '<div class="flash ' . e($item['type']) . '">' . e($item['message']) . '</div>';
        }
    }

    public static function footer(): void
    {
        $scriptSrc = e(asset('app.js'));
        echo <<<HTML
</main>
</div>
<script src="{$scriptSrc}"></script>
</body>
</html>
HTML;
    }

    private static function sidebar(array $user): void
    {
        $isAdmin = ($user['role'] ?? '') === 'admin';
        echo '<aside class="sidebar">';
        echo '<div class="brand"><span class="brand-mark">RS</span><div><strong>RAW SALMON</strong><span>V2.0 MW edition</span></div></div>';
        echo '<nav>';
        echo '<a href="' . e(url('/dashboard.php')) . '">Dashboard</a>';
        if ($isAdmin) {
            echo '<a href="' . e(url('/users.php')) . '">Users</a>';
            echo '<a href="' . e(url('/diagnostics.php')) . '">Diagnostics</a>';
        }
        echo '<a href="' . e(url('/mailgun-settings.php')) . '">Mailgun Settings</a>';
        echo '<a href="' . e(url('/domains.php')) . '">Domains</a>';
        echo '<a href="' . e(url('/recipients.php')) . '">Recipients</a>';
        echo '<a href="' . e(url('/presets.php')) . '">Presets</a>';
        echo '<a href="' . e(url('/queue.php')) . '">Sending Queue</a>';
        echo '<a href="' . e(url('/logs.php')) . '">Logs</a>';
        echo '<a href="' . e(url('/webhook-events.php')) . '">Webhook Events</a>';
        echo '</nav>';
        echo '<div class="sidebar-user">' . e($user['name']) . '<span>' . e($user['role']) . '</span></div>';
        echo '</aside>';
    }
}
