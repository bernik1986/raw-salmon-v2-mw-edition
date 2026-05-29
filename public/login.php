<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

if (!is_installed()) {
    redirect('/install.php');
}

if (current_user()) {
    redirect('/dashboard.php');
}

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    if (auth()->login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        redirect('/dashboard.php');
    }
    $error = 'Invalid credentials or inactive user';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - RAW SALMON V2.0 MW edition</title>
    <link rel="stylesheet" href="<?php echo e(asset('style.css')); ?>">
</head>
<body class="login-page">
<main class="login-panel">
    <div class="login-brand">
        <span class="brand-mark large">RS</span>
        <div>
            <div class="product-kicker">Mail Test Sender</div>
            <h1>RAW SALMON</h1>
            <p>V2.0 MW edition</p>
        </div>
    </div>
    <p>QA email presets, Mailgun domains, queues, and delivery logs in one control room.</p>
    <?php if ($error): ?><div class="flash error"><?php echo e($error); ?></div><?php endif; ?>
    <form method="post" class="form-grid">
        <?php echo csrf_field(); ?>
        <label>
            Email
            <input name="email" type="email" autocomplete="username" required>
        </label>
        <label>
            Password
            <input name="password" type="password" autocomplete="current-password" required>
        </label>
        <label class="checkbox">
            <input name="remember" type="checkbox" value="1">
            Remember me
        </label>
        <button type="submit">Login</button>
    </form>
</main>
</body>
</html>
