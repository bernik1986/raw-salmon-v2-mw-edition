<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
ensure_installed();

use App\MailgunSettingsService;
use App\View;

$user = require_auth();
$service = new MailgunSettingsService(db());

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    try {
        $service->save((int) $user['id'], $_POST);
        flash('success', 'Mailgun settings saved');
    } catch (Throwable $throwable) {
        flash('error', $throwable->getMessage());
    }
    redirect('/mailgun-settings.php');
}

$settings = $service->get((int) $user['id']);
View::header('Mailgun Settings', $user);
?>
<section class="section">
    <form method="post" class="form-grid columns">
        <?php echo csrf_field(); ?>
        <label>
            Mailgun API Key
            <input name="api_key" type="password" placeholder="<?php echo $settings['api_key_masked'] ? e($settings['api_key_masked']) : 'Paste key'; ?>">
            <small>Leave blank to keep the saved key.</small>
        </label>
        <label>
            Webhook Signing Key
            <input name="webhook_signing_key" type="password" placeholder="<?php echo $settings['webhook_signing_key_masked'] ? e($settings['webhook_signing_key_masked']) : 'Paste Mailgun signing key'; ?>">
            <small>Used to verify Mailgun delivery webhooks.</small>
        </label>
        <label>Default Mailgun Domain <input name="domain" value="<?php echo e($settings['domain']); ?>" placeholder="Optional; saved into Domains manager"></label>
        <label>Region
            <select name="region">
                <option value="US" <?php echo $settings['region'] === 'US' ? 'selected' : ''; ?>>US</option>
                <option value="EU" <?php echo $settings['region'] === 'EU' ? 'selected' : ''; ?>>EU</option>
            </select>
        </label>
        <label>Default From Name <input name="default_from_name" value="<?php echo e($settings['default_from_name']); ?>"></label>
        <label>Default Reply-To <input name="default_reply_to" type="email" value="<?php echo e($settings['default_reply_to']); ?>"></label>
        <label>Daily Limit <input name="daily_limit" type="number" min="1" value="<?php echo (int) $settings['daily_limit']; ?>"></label>
        <label>Hourly Limit <input name="hourly_limit" type="number" min="1" value="<?php echo (int) $settings['hourly_limit']; ?>"></label>
        <label class="checkbox">
            <input name="test_mode" type="checkbox" value="1" <?php echo (int) $settings['test_mode'] === 1 ? 'checked' : ''; ?>>
            Test Mode
        </label>
        <button type="submit">Save Settings</button>
    </form>
</section>
<section class="section">
    <div class="section-title">
        <h2>Domain Manager</h2>
        <a class="button" href="/domains.php">Open Domains</a>
    </div>
    <p class="muted">Add one or more Mailgun domains, test credentials, and pick the domain per preset.</p>
</section>
<?php View::footer(); ?>
