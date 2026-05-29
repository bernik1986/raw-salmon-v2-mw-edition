<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Database;
use App\Security;
use App\UserService;

$requirements = [
    'PHP 8.1+' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO SQLite' => extension_loaded('pdo_sqlite') && in_array('sqlite', PDO::getAvailableDrivers(), true),
    'OpenSSL' => extension_loaded('openssl'),
    'cURL' => extension_loaded('curl'),
    'JSON' => extension_loaded('json'),
    'Sessions' => function_exists('session_start'),
];

$installed = is_installed();
$errors = [];
$success = null;
$assetHref = asset('style.css');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Security::verifyCsrf($_POST['csrf_token'] ?? null);

    foreach ($requirements as $name => $ok) {
        if (!$ok) {
            $errors[] = "$name is required";
        }
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if ($name === '') {
        $errors[] = 'Admin name is required';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid admin email is required';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Admin password must be at least 8 characters';
    }

    if (!$errors) {
        $localPath = __DIR__ . '/config/local.php';
        if (!is_file($localPath)) {
            $local = [
                'app_key' => base64_encode(random_bytes(32)),
                'cron_token' => bin2hex(random_bytes(24)),
                'db_path' => __DIR__ . '/storage/app.sqlite',
            ];
            $content = "<?php\n\nreturn " . var_export($local, true) . ";\n";
            file_put_contents($localPath, $content);
        } else {
            $local = require $localPath;
        }

        $config = array_replace(app_config(), is_array($local) ? $local : []);
        Database::resetConnection();
        $pdo = Database::connect($config);
        Database::migrate($pdo);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count === 0) {
            (new UserService($pdo))->create($name, $email, $password, 'admin');
        }

        $installed = true;
        $success = 'Installed. Cron token: ' . $config['cron_token'];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install - RAW SALMON V2.0 MW edition</title>
    <link rel="stylesheet" href="<?php echo e($assetHref); ?>">
</head>
<body class="install-page">
<main class="install-panel">
    <div class="login-brand">
        <span class="brand-mark large">RS</span>
        <div>
            <div class="product-kicker">Mail Test Sender Install</div>
            <h1>RAW SALMON</h1>
            <p>V2.0 MW edition</p>
        </div>
    </div>
    <p>Creates the SQLite database, local encryption key, cron token, and first Admin user.</p>

    <?php if ($errors): ?>
        <div class="flash error"><?php echo e(implode(' ', $errors)); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="flash success"><?php echo e($success); ?></div>
    <?php endif; ?>

    <section class="section">
        <h2>Requirements</h2>
        <table>
            <tbody>
            <?php foreach ($requirements as $name => $ok): ?>
                <tr>
                    <td><?php echo e($name); ?></td>
                    <td><span class="status <?php echo $ok ? 'ok' : 'bad'; ?>"><?php echo $ok ? 'OK' : 'Missing'; ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <?php if (!$installed): ?>
        <form method="post" class="form-grid">
            <?php echo csrf_field(); ?>
            <label>
                Admin name
                <input name="name" required>
            </label>
            <label>
                Admin email
                <input name="email" type="email" required>
            </label>
            <label>
                Admin password
                <input name="password" type="password" minlength="8" required>
            </label>
            <button type="submit">Install</button>
        </form>
    <?php else: ?>
        <p><a class="button" href="/login.php">Open Login</a></p>
    <?php endif; ?>
</main>
</body>
</html>
