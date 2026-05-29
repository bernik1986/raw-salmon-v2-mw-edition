<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
ensure_installed();
require_post();
auth()->logout();
redirect('/login.php');
