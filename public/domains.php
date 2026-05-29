<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
ensure_installed();

use App\DomainService;
use App\View;

$user = require_auth();
$service = new DomainService(db());

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save') {
            $service->save((int) $user['id'], $_POST);
            flash('success', 'Domain saved');
        } elseif ($action === 'set_active') {
            $service->setActive((int) $user['id'], (int) $_POST['id'], (string) $_POST['active'] === '1');
            flash('success', 'Domain status updated');
        } elseif ($action === 'test') {
            $result = $service->test((int) $user['id'], (int) $_POST['id']);
            flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Mailgun connection OK' : ('Mailgun connection failed: ' . ($result['error'] ?? 'unknown error')));
        }
    } catch (Throwable $throwable) {
        flash('error', $throwable->getMessage());
    }
    redirect('/domains.php');
}

$domains = $service->all((int) $user['id']);
View::header('Domains', $user);
?>
<section class="section">
    <h2>Webhook Endpoint</h2>
    <p class="muted">Configure Mailgun delivery webhooks to POST JSON events to this URL after setting your Webhook Signing Key in Mailgun Settings.</p>
    <input readonly value="<?php echo e((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com') . '/mailgun-webhook.php'); ?>">
</section>

<section class="section">
    <h2>Add Domain</h2>
    <form method="post" class="form-grid columns">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save">
        <label>Domain <input name="domain" placeholder="mg-test.example.com" required></label>
        <label>Region
            <select name="region">
                <option value="US">US</option>
                <option value="EU">EU</option>
            </select>
        </label>
        <label>Default From Name <input name="default_from_name"></label>
        <label>Default Reply-To <input name="default_reply_to" type="email"></label>
        <label>Daily Limit <input name="daily_limit" type="number" min="1" value="100"></label>
        <label>Hourly Limit <input name="hourly_limit" type="number" min="1" value="25"></label>
        <label class="checkbox"><input name="test_mode" type="checkbox" value="1" checked> Test Mode</label>
        <label class="checkbox"><input name="is_active" type="checkbox" value="1" checked> Active</label>
        <label class="checkbox"><input name="is_default" type="checkbox" value="1"> Default</label>
        <button type="submit">Save Domain</button>
    </form>
</section>

<section class="section">
    <h2>Managed Domains</h2>
    <table>
        <thead><tr><th>Domain</th><th>Region</th><th>From</th><th>Reply-To</th><th>Limits</th><th>Mode</th><th>Status</th><th>Last Test</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($domains as $domain): ?>
            <tr>
                <td><?php echo e($domain['domain']); ?><?php echo (int) $domain['is_default'] ? ' <span class="status ok">default</span>' : ''; ?></td>
                <td><?php echo e($domain['region']); ?></td>
                <td><?php echo e($domain['default_from_name']); ?></td>
                <td><?php echo e($domain['default_reply_to']); ?></td>
                <td><?php echo (int) $domain['hourly_limit']; ?>/h, <?php echo (int) $domain['daily_limit']; ?>/d</td>
                <td><?php echo (int) $domain['test_mode'] ? 'Test' : 'Live'; ?></td>
                <td><span class="status <?php echo (int) $domain['is_active'] ? 'ok' : 'bad'; ?>"><?php echo (int) $domain['is_active'] ? 'active' : 'inactive'; ?></span></td>
                <td><?php echo e(($domain['last_test_status'] ?? '') . ' ' . ($domain['last_test_at'] ?? '')); ?></td>
                <td class="actions">
                    <form method="post" class="compact-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="test">
                        <input type="hidden" name="id" value="<?php echo (int) $domain['id']; ?>">
                        <button type="submit">Test Connection</button>
                    </form>
                    <form method="post" class="compact-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="set_active">
                        <input type="hidden" name="id" value="<?php echo (int) $domain['id']; ?>">
                        <input type="hidden" name="active" value="<?php echo (int) $domain['is_active'] ? '0' : '1'; ?>">
                        <button type="submit"><?php echo (int) $domain['is_active'] ? 'Deactivate' : 'Activate'; ?></button>
                    </form>
                </td>
            </tr>
            <tr>
                <td colspan="9">
                    <form method="post" class="inline-edit domain-edit">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?php echo (int) $domain['id']; ?>">
                        <input name="domain" value="<?php echo e($domain['domain']); ?>" required>
                        <select name="region">
                            <option value="US" <?php echo $domain['region'] === 'US' ? 'selected' : ''; ?>>US</option>
                            <option value="EU" <?php echo $domain['region'] === 'EU' ? 'selected' : ''; ?>>EU</option>
                        </select>
                        <input name="default_from_name" value="<?php echo e($domain['default_from_name']); ?>" placeholder="From name">
                        <input name="default_reply_to" value="<?php echo e($domain['default_reply_to']); ?>" placeholder="Reply-To">
                        <input name="hourly_limit" type="number" min="1" value="<?php echo (int) $domain['hourly_limit']; ?>">
                        <input name="daily_limit" type="number" min="1" value="<?php echo (int) $domain['daily_limit']; ?>">
                        <label class="checkbox"><input name="test_mode" type="checkbox" value="1" <?php echo (int) $domain['test_mode'] ? 'checked' : ''; ?>> Test</label>
                        <label class="checkbox"><input name="is_active" type="checkbox" value="1" <?php echo (int) $domain['is_active'] ? 'checked' : ''; ?>> Active</label>
                        <label class="checkbox"><input name="is_default" type="checkbox" value="1" <?php echo (int) $domain['is_default'] ? 'checked' : ''; ?>> Default</label>
                        <button type="submit">Save</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$domains): ?><tr><td colspan="9" class="empty">No domains yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>
<?php View::footer(); ?>
