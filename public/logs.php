<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
ensure_installed();

use App\QueueService;
use App\View;

$user = require_auth();
$isAdmin = $user['role'] === 'admin';
$logs = (new QueueService(db()))->logs((int) $user['id'], $isAdmin);

View::header('Logs', $user);
?>
<section class="section">
    <table>
        <thead><tr><th>Date/Time</th><th>User</th><th>Job</th><th>Recipient</th><th>From</th><th>Subject</th><th>Status</th><th>Mailgun Response</th><th>Error</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td><?php echo e($log['created_at']); ?></td>
                <td><?php echo e($log['user_email']); ?></td>
                <td><?php echo (int) $log['job_id']; ?></td>
                <td><?php echo e($log['recipient_email']); ?></td>
                <td><?php echo e($log['from_email']); ?></td>
                <td><?php echo e($log['subject'] ?? ''); ?></td>
                <td><span class="status <?php echo e($log['status']); ?>"><?php echo e($log['status']); ?></span></td>
                <td class="mono"><?php echo e(mb_substr((string) ($log['mailgun_response'] ?? ''), 0, 180)); ?></td>
                <td><?php echo e($log['error_message'] ?? ''); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?><tr><td colspan="9" class="empty">No logs yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
<?php View::footer(); ?>
