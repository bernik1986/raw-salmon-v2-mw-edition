<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
ensure_installed();

use App\View;
use App\WebhookService;

$user = require_auth();
$events = (new WebhookService(db()))->recent((int) $user['id'], $user['role'] === 'admin');

View::header('Webhook Events', $user);
?>
<section class="section">
    <table>
        <thead><tr><th>Date/Time</th><th>User</th><th>Domain</th><th>Event</th><th>Recipient</th><th>Queue</th><th>Signature</th><th>Message</th></tr></thead>
        <tbody>
        <?php foreach ($events as $event): ?>
            <?php $payload = json_decode((string) $event['payload'], true) ?: []; ?>
            <tr>
                <td><?php echo e($event['created_at']); ?></td>
                <td><?php echo e($event['user_email'] ?? ''); ?></td>
                <td><?php echo e($event['mailgun_domain']); ?></td>
                <td><span class="status <?php echo e($event['event_type']); ?>"><?php echo e($event['event_type']); ?></span></td>
                <td><?php echo e($event['recipient_email'] ?? ''); ?></td>
                <td><?php echo e((string) ($event['queue_id'] ?? '')); ?></td>
                <td><?php echo (int) $event['signature_valid'] ? 'valid' : 'invalid'; ?></td>
                <td><?php echo e(mb_substr((string) ($payload['delivery-status']['message'] ?? $payload['reason'] ?? $event['message_id'] ?? ''), 0, 180)); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$events): ?><tr><td colspan="8" class="empty">No webhook events yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
<?php View::footer(); ?>
