<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
ensure_installed();

use App\QueueService;
use App\View;

$user = require_auth();
$queue = new QueueService(db());
$stats = $queue->dashboardStats((int) $user['id'], $user['role'] === 'admin');
$jobs = array_slice($queue->jobs((int) $user['id'], $user['role'] === 'admin'), 0, 8);

View::header('Dashboard', $user);
?>
<section class="stats-grid">
    <div class="stat"><span>Total presets</span><strong><?php echo (int) $stats['presets']; ?></strong></div>
    <div class="stat"><span>Managed domains</span><strong><?php echo (int) $stats['domains']; ?></strong></div>
    <div class="stat"><span>Whitelist recipients</span><strong><?php echo (int) $stats['recipients']; ?></strong></div>
    <div class="stat"><span>Attachments</span><strong><?php echo (int) $stats['attachments']; ?></strong></div>
    <div class="stat"><span>Emails sent today</span><strong><?php echo (int) $stats['sent_today']; ?></strong></div>
    <div class="stat"><span>Emails failed today</span><strong><?php echo (int) $stats['failed_today']; ?></strong></div>
    <div class="stat"><span>Delivered today</span><strong><?php echo (int) $stats['delivered_today']; ?></strong></div>
    <div class="stat"><span>Webhook events today</span><strong><?php echo (int) $stats['webhook_events_today']; ?></strong></div>
    <div class="stat"><span>Queue pending</span><strong><?php echo (int) $stats['pending']; ?></strong></div>
    <div class="stat"><span>Queue sending</span><strong><?php echo (int) $stats['sending']; ?></strong></div>
    <div class="stat"><span>Paused jobs</span><strong><?php echo (int) $stats['paused_jobs']; ?></strong></div>
    <div class="stat"><span>Sent all time</span><strong><?php echo (int) $stats['sent_all']; ?></strong></div>
</section>

<section class="dashboard-grid">
    <div class="section">
        <h2>Queue by Status</h2>
        <table>
            <thead><tr><th>Status</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($stats['queue_by_status'] as $row): ?>
                <tr><td><?php echo e($row['label']); ?></td><td><?php echo (int) $row['total']; ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$stats['queue_by_status']): ?><tr><td colspan="2" class="empty">No queue data.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="section">
        <h2>Webhook Events</h2>
        <table>
            <thead><tr><th>Event</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($stats['events_by_type'] as $row): ?>
                <tr><td><?php echo e($row['label']); ?></td><td><?php echo (int) $row['total']; ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$stats['events_by_type']): ?><tr><td colspan="2" class="empty">No webhook data.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="section">
    <div class="section-title">
        <h2>Last sending jobs</h2>
        <a class="button secondary" href="<?php echo e(url('/presets.php')); ?>">Create job</a>
    </div>
    <table>
        <thead>
        <tr><th>ID</th><th>Preset</th><th>Status</th><th>Total</th><th>Sent</th><th>Failed</th><th>Created</th></tr>
        </thead>
        <tbody>
        <?php foreach ($jobs as $job): ?>
            <tr>
                <td><?php echo (int) $job['id']; ?></td>
                <td><?php echo e($job['preset_name']); ?></td>
                <td><span class="status <?php echo e($job['display_status']); ?>"><?php echo e($job['display_status']); ?></span></td>
                <td><?php echo (int) $job['total_emails']; ?></td>
                <td><?php echo (int) $job['sent_count']; ?></td>
                <td><?php echo (int) $job['failed_count']; ?></td>
                <td><?php echo e($job['created_at']); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$jobs): ?><tr><td colspan="7" class="empty">No jobs yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
<?php View::footer(); ?>
