<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
ensure_installed();

use App\RecipientService;
use App\View;

$user = require_auth();
$service = new RecipientService(db());

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create') {
            $service->create((int) $user['id'], (string) $_POST['name'], (string) $_POST['email']);
            flash('success', 'Recipient added to whitelist');
        } elseif ($action === 'set_active') {
            $service->setActive((int) $user['id'], (int) $_POST['id'], (string) $_POST['active'] === '1');
            flash('success', 'Recipient status updated');
        }
    } catch (Throwable $throwable) {
        flash('error', $throwable->getMessage());
    }
    redirect('/recipients.php');
}

$recipients = $service->all((int) $user['id']);
View::header('Recipients', $user);
?>
<section class="section">
    <h2>Add Whitelist Recipient</h2>
    <form method="post" class="form-grid columns">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="create">
        <label>Name <input name="name" required></label>
        <label>Email <input name="email" type="email" required></label>
        <button type="submit">Add Recipient</button>
    </form>
</section>

<section class="section">
    <h2>Whitelist</h2>
    <table>
        <thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($recipients as $recipient): ?>
            <tr>
                <td><?php echo e($recipient['name']); ?></td>
                <td><?php echo e($recipient['email']); ?></td>
                <td><span class="status <?php echo (int) $recipient['is_active'] ? 'ok' : 'bad'; ?>"><?php echo (int) $recipient['is_active'] ? 'active' : 'inactive'; ?></span></td>
                <td><?php echo e($recipient['created_at']); ?></td>
                <td>
                    <form method="post" class="compact-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="set_active">
                        <input type="hidden" name="id" value="<?php echo (int) $recipient['id']; ?>">
                        <input type="hidden" name="active" value="<?php echo (int) $recipient['is_active'] ? '0' : '1'; ?>">
                        <button type="submit"><?php echo (int) $recipient['is_active'] ? 'Deactivate' : 'Activate'; ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$recipients): ?><tr><td colspan="5" class="empty">No whitelist recipients yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
<?php View::footer(); ?>
