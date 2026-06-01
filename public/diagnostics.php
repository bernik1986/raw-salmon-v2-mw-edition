<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
ensure_installed();

use App\AppLog;
use App\View;

$user = require_admin();
$logPath = (string) app_config('app_log_path', APP_BASE_PATH . '/storage/logs/app.log');
$logDirectory = dirname($logPath);
if (!is_dir($logDirectory)) {
    @mkdir($logDirectory, 0775, true);
}

$checks = [
    'PHP 8.1 or newer' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO SQLite module' => extension_loaded('pdo_sqlite'),
    'cURL module' => extension_loaded('curl'),
    'OpenSSL module' => extension_loaded('openssl'),
    'mbstring module' => extension_loaded('mbstring'),
    'Writable config directory' => is_writable(APP_BASE_PATH . '/config'),
    'Writable storage directory' => is_writable(APP_BASE_PATH . '/storage'),
    'Writable session storage' => is_dir((string) app_config('session_storage_path')) && is_writable((string) app_config('session_storage_path')),
    'Writable app log directory' => is_dir($logDirectory) && is_writable($logDirectory),
];
$events = AppLog::recent(100, $logPath);

View::header('Diagnostics', $user);
?>
<section class="section">
    <h2>System Health</h2>
    <p class="muted">This page is safe to screenshot: API keys, tokens, passwords, and signatures are not displayed.</p>
    <table>
        <thead><tr><th>Check</th><th>Status</th></tr></thead>
        <tbody>
        <tr><td>PHP version</td><td><?php echo e(PHP_VERSION); ?></td></tr>
        <?php foreach ($checks as $label => $ok): ?>
            <tr><td><?php echo e($label); ?></td><td><span class="status <?php echo $ok ? 'ok' : 'bad'; ?>"><?php echo $ok ? 'OK' : 'FAILED'; ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="section">
    <h2>Recent Application Events</h2>
    <p class="muted">Run Domains &gt; Test Connection, then reload this page. Failed queue sends are recorded here too.</p>
    <table>
        <thead><tr><th>Date/Time</th><th>Level</th><th>Event</th><th>Context</th></tr></thead>
        <tbody>
        <?php foreach ($events as $event): ?>
            <tr>
                <td><?php echo e((string) ($event['date'] ?? '')); ?></td>
                <td><?php echo e((string) ($event['level'] ?? '')); ?></td>
                <td><?php echo e((string) ($event['event'] ?? '')); ?></td>
                <td class="mono"><?php echo e((string) json_encode($event['context'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$events): ?><tr><td colspan="4" class="empty">No diagnostic events yet. Run a Mailgun connection test first.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
<?php View::footer(); ?>
