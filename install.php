<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    $phpVersion = htmlspecialchars(PHP_VERSION, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>PHP 8.1+ required</title></head>';
    echo '<body><h1>PHP 8.1+ is required</h1><p>Current PHP version: ' . $phpVersion . '.</p>';
    echo '<p>Select PHP 8.1 or newer in your hosting control panel, then reload this page.</p></body></html>';
    exit;
}

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
    'Writable session storage' => APP_SESSION_READY,
    'Writable config directory' => is_writable(__DIR__ . '/config'),
    'Writable storage directory' => is_writable(__DIR__ . '/storage'),
];

$installed = is_installed();
$errors = [];
$success = null;
$assetHref = asset('style.css');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($installed) {
        http_response_code(409);
        $errors[] = 'Application is already installed. Log in to continue.';
    } else {
        foreach ($requirements as $name => $ok) {
            if (!$ok) {
                $errors[] = "$name is required";
            }
        }

        if (!$errors) {
            Security::verifyCsrf($_POST['csrf_token'] ?? null);
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
            try {
                $localPath = __DIR__ . '/config/local.php';
                if (!is_file($localPath)) {
                    $local = [
                        'app_key' => base64_encode(random_bytes(32)),
                        'cron_token' => bin2hex(random_bytes(24)),
                        'db_path' => __DIR__ . '/storage/app.sqlite',
                    ];
                    $content = "<?php\n\nreturn " . var_export($local, true) . ";\n";
                    if (@file_put_contents($localPath, $content, LOCK_EX) === false) {
                        throw new RuntimeException('Unable to write config/local.php. Check config directory permissions.');
                    }
                } else {
                    $local = require $localPath;
                }

                $attachmentPath = __DIR__ . '/storage/attachments';
                if (!is_dir($attachmentPath) && !@mkdir($attachmentPath, 0775, true)) {
                    throw new RuntimeException('Unable to create storage/attachments. Check storage directory permissions.');
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
            } catch (Throwable $throwable) {
                $errors[] = $throwable->getMessage();
            }
        }
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

    <?php if (!$installed && !in_array(false, $requirements, true)): ?>
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
    <?php elseif ($installed): ?>
        <p><a class="button" href="<?php echo e(url('/login.php')); ?>">Open Login</a></p>
    <?php else: ?>
        <p class="muted">Fix the missing hosting requirements above, then reload this page.</p>
    <?php endif; ?>
</main>
</body>
</html>
