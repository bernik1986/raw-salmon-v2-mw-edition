<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

if (!is_installed()) {
    redirect('/install.php');
}

redirect(current_user() ? '/dashboard.php' : '/login.php');
