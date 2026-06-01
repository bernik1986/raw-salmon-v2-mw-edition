<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
ensure_installed();

use App\AdminRecoveryService;
use App\AppLog;

$service = new AdminRecoveryService(db());
$error = null;
$success = null;

try {
    $service->ensureToken();
} catch (Throwable $throwable) {
    $error = $throwable->getMessage();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $error === null) {
    require_post();
    try {
        $service->resetAdminPassword(
            (string) ($_POST['recovery_token'] ?? ''),
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['password'] ?? '')
        );
        AppLog::write('warning', 'auth.admin_password_recovered', [
            'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        $success = 'Admin password updated. The recovery token was rotated. You can log in now.';
    } catch (Throwable $throwable) {
        AppLog::write('warning', 'auth.admin_password_recovery_failed', [
            'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        usleep(300000);
        $error = $throwable->getMessage();
    }
}

$assetHref = asset('style.css');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recover Admin - RAW SALMON V2.0 MW edition</title>
    <link rel="stylesheet" href="<?php echo e($assetHref); ?>">
</head>
<body class="login-page">
<main class="login-panel">
    <div class="login-brand">
        <span class="brand-mark large">RS</span>
        <div>
            <div class="product-kicker">Admin Recovery</div>
            <h1>RAW SALMON</h1>
            <p>V2.0 MW edition</p>
        </div>
    </div>
    <p>Open the protected <code>config/local.php</code> file in your hosting file manager and copy the value of <code>admin_recovery_token</code>. Never send that token to anyone.</p>

    <?php if ($error): ?><div class="flash error"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($success): ?>
        <div class="flash success"><?php echo e($success); ?></div>
        <p><a class="button" href="<?php echo e(url('/login.php')); ?>">Open Login</a></p>
    <?php elseif ($error === null): ?>
        <form method="post" class="form-grid">
            <?php echo csrf_field(); ?>
            <label>
                Admin email
                <input name="email" type="email" autocomplete="username" required>
            </label>
            <label>
                Recovery token
                <input name="recovery_token" type="password" autocomplete="off" required>
            </label>
            <label>
                New password
                <input name="password" type="password" minlength="8" autocomplete="new-password" required>
            </label>
            <button type="submit">Reset Admin Password</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
