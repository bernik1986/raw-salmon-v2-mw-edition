<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    require dirname(__DIR__) . '/install.php';
    exit;
}

require __DIR__ . '/../app/bootstrap.php';

if (!is_installed()) {
    redirect('/install.php');
}

redirect(current_user() ? '/dashboard.php' : '/login.php');
