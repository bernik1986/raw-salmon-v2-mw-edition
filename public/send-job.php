<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
ensure_installed();

use App\DomainService;
use App\PresetService;
use App\QueueService;
use App\View;

$user = require_auth();
$presetId = (int) ($_GET['preset_id'] ?? $_POST['preset_id'] ?? 0);
if ($presetId < 1) {
    redirect('/presets.php');
}

$presetService = new PresetService(db());
$queueService = new QueueService(db());

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    try {
        $jobId = $queueService->createJob((int) $user['id'], $presetId);
        flash('success', 'Sending job created: #' . $jobId);
        redirect('/queue.php');
    } catch (Throwable $throwable) {
        flash('error', $throwable->getMessage());
        redirect('/send-job.php?preset_id=' . $presetId);
    }
}

try {
    $summary = $presetService->summary((int) $user['id'], $presetId);
    $settings = (new DomainService(db()))->settingsForPreset((int) $user['id'], $summary['preset']);
} catch (Throwable $throwable) {
    flash('error', $throwable->getMessage());
    redirect('/presets.php');
}

$preset = $summary['preset'];
$domain = $settings['domain'];

View::header('Start Sending', $user);
?>
<section class="section">
    <h2>Summary Before Sending</h2>
    <table>
        <tbody>
        <tr><td>Preset</td><td><?php echo e($preset['name']); ?></td></tr>
        <tr><td>Total emails</td><td><?php echo (int) $summary['email_count']; ?></td></tr>
        <tr><td>Recipients</td><td><?php echo (int) $summary['recipient_count']; ?></td></tr>
        <tr><td>Total outgoing messages</td><td><strong><?php echo (int) $summary['total_outgoing']; ?></strong></td></tr>
        <tr><td>Delay</td><td><?php echo e($preset['delay_mode']); ?> <?php echo (int) $preset['delay_min_seconds']; ?>-<?php echo (int) $preset['delay_max_seconds']; ?> seconds</td></tr>
        <tr><td>From domain</td><td><?php echo e($domain); ?></td></tr>
        <tr><td>Test mode</td><td><?php echo (int) $settings['test_mode'] === 1 ? 'ON' : 'OFF'; ?></td></tr>
        </tbody>
    </table>
    <form method="post" class="button-row confirm-send">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="preset_id" value="<?php echo (int) $presetId; ?>">
        <a class="button secondary" href="<?php echo e(url('/presets.php')); ?>">Back</a>
        <button type="submit">Start Sending</button>
    </form>
</section>
<?php View::footer(); ?>
