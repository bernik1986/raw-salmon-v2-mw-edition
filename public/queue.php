<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
ensure_installed();

use App\QueueService;
use App\View;

$user = require_auth();
$service = new QueueService(db());

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'cancel') {
            $service->cancelJob((int) $user['id'], (int) $_POST['job_id']);
            flash('success', 'Job cancelled');
        } elseif ($action === 'retry_failed') {
            $service->retryFailed((int) $user['id'], (int) $_POST['job_id']);
            flash('success', 'Failed emails moved back to pending');
        } elseif ($action === 'pause') {
            $service->pauseJob((int) $user['id'], (int) $_POST['job_id']);
            flash('success', 'Job paused');
        } elseif ($action === 'resume') {
            $service->resumeJob((int) $user['id'], (int) $_POST['job_id']);
            flash('success', 'Job resumed');
        }
    } catch (Throwable $throwable) {
        flash('error', $throwable->getMessage());
    }
    redirect('/queue.php');
}

$isAdmin = $user['role'] === 'admin';
$jobs = $service->jobs((int) $user['id'], $isAdmin);
$queue = $service->queue((int) $user['id'], $isAdmin);

View::header('Sending Queue', $user);
?>
<section class="section">
    <h2>Jobs</h2>
    <table>
        <thead><tr><th>ID</th><th>User</th><th>Preset</th><th>Status</th><th>Total</th><th>Sent</th><th>Failed</th><th>Created</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $job): ?>
            <tr>
                <td><?php echo (int) $job['id']; ?></td>
                <td><?php echo e($job['user_email']); ?></td>
                <td><?php echo e($job['preset_name']); ?></td>
                <td><span class="status <?php echo e($job['display_status']); ?>"><?php echo e($job['display_status']); ?></span></td>
                <td><?php echo (int) $job['total_emails']; ?></td>
                <td><?php echo (int) $job['sent_count']; ?></td>
                <td><?php echo (int) $job['failed_count']; ?></td>
                <td><?php echo e($job['created_at']); ?></td>
                <td class="actions">
                    <?php if ((int) $job['user_id'] === (int) $user['id']): ?>
                        <form method="post" class="compact-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="cancel">
                            <input type="hidden" name="job_id" value="<?php echo (int) $job['id']; ?>">
                            <button type="submit">Cancel</button>
                        </form>
                        <?php if (empty($job['paused_at']) && in_array($job['status'], ['pending', 'running'], true)): ?>
                            <form method="post" class="compact-form">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="pause">
                                <input type="hidden" name="job_id" value="<?php echo (int) $job['id']; ?>">
                                <button type="submit">Pause</button>
                            </form>
                        <?php elseif (!empty($job['paused_at'])): ?>
                            <form method="post" class="compact-form">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="resume">
                                <input type="hidden" name="job_id" value="<?php echo (int) $job['id']; ?>">
                                <button type="submit">Resume</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" class="compact-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="retry_failed">
                            <input type="hidden" name="job_id" value="<?php echo (int) $job['id']; ?>">
                            <button type="submit">Retry Failed</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$jobs): ?><tr><td colspan="9" class="empty">No sending jobs yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<section class="section">
    <h2>Queued Emails</h2>
    <table>
        <thead><tr><th>Scheduled</th><th>Recipient</th><th>From</th><th>Subject</th><th>Status</th><th>Attempts</th><th>Last Error</th></tr></thead>
        <tbody>
        <?php foreach ($queue as $item): ?>
            <tr>
                <td><?php echo e($item['scheduled_at']); ?></td>
                <td><?php echo e($item['recipient_email']); ?></td>
                <td><?php echo e($item['from_email']); ?></td>
                <td><?php echo e($item['subject'] ?? ''); ?></td>
                <td><span class="status <?php echo e($item['status']); ?>"><?php echo e($item['status']); ?></span></td>
                <td><?php echo (int) $item['attempts']; ?></td>
                <td><?php echo e($item['last_error'] ?? ''); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$queue): ?><tr><td colspan="7" class="empty">No queued emails yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
<?php View::footer(); ?>
