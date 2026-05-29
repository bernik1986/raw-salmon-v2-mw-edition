<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
ensure_installed();

use App\AttachmentService;
use App\DomainService;
use App\FromGenerator;
use App\JsonValidator;
use App\PresetService;
use App\RecipientService;
use App\View;

$user = require_auth();
$presetService = new PresetService(db());
$recipientService = new RecipientService(db());
$domainService = new DomainService(db());
$attachmentService = new AttachmentService(db());
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$preset = $id ? $presetService->find((int) $user['id'], $id) : null;
if ($id && !$preset) {
    http_response_code(404);
    exit('Preset not found');
}

$validation = null;
$savedId = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    $action = (string) ($_POST['action'] ?? 'validate');
    if ($action === 'delete_attachment') {
        try {
            $attachmentService->deletePresetAttachment((int) $user['id'], (int) $_POST['attachment_id']);
            flash('success', 'Attachment deleted');
        } catch (Throwable $throwable) {
            flash('error', $throwable->getMessage());
        }
        redirect('/preset-edit.php?id=' . (int) ($_POST['id'] ?? 0));
    }

    $validation = JsonValidator::validate((string) ($_POST['json_payload'] ?? ''));

    if ($action === 'save') {
        try {
            $savedId = $presetService->save((int) $user['id'], $_POST);
            $attachmentService->addUploadedFiles((int) $user['id'], $savedId, $_FILES['attachments'] ?? []);
            flash('success', 'Preset saved');
            redirect('/preset-edit.php?id=' . $savedId);
        } catch (Throwable $throwable) {
            flash('error', $throwable->getMessage());
        }
    } elseif ($validation['valid']) {
        flash('success', 'JSON is valid');
    } else {
        flash('error', implode('; ', $validation['errors']));
    }

    $preset = array_merge($preset ?? [], $_POST);
    $preset['recipients'] = [];
}

$activeRecipients = $recipientService->active((int) $user['id']);
$activeDomains = $domainService->active((int) $user['id']);
$selectedIds = [];
if (isset($_POST['recipient_ids']) && is_array($_POST['recipient_ids'])) {
    $selectedIds = array_map('intval', $_POST['recipient_ids']);
} elseif ($preset && isset($preset['recipients'])) {
    $selectedIds = array_map(fn (array $row): int => (int) $row['id'], $preset['recipients']);
}

$form = [
    'id' => $preset['id'] ?? '',
    'mailgun_domain_id' => $preset['mailgun_domain_id'] ?? '',
    'name' => $preset['name'] ?? '',
    'mailgun_domain' => $preset['mailgun_domain'] ?? '',
    'language' => $preset['language'] ?? '',
    'topic' => $preset['topic'] ?? '',
    'from_pattern' => $preset['from_pattern'] ?? 'client-{random}@example.com',
    'delay_mode' => $preset['delay_mode'] ?? 'fixed',
    'delay_min_seconds' => $preset['delay_min_seconds'] ?? 30,
    'delay_max_seconds' => $preset['delay_max_seconds'] ?? 30,
    'batch_size' => $preset['batch_size'] ?? 1,
    'json_payload' => $preset['json_payload'] ?? '',
];

View::header($form['id'] ? 'Edit Preset' : 'Create Preset', $user);
?>
<section class="section">
    <form method="post" class="form-grid" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo e((string) $form['id']); ?>">

        <div class="form-grid columns">
            <label>Preset Name <input name="name" value="<?php echo e((string) $form['name']); ?>" required></label>
            <label>Managed Mailgun Domain
                <select name="mailgun_domain_id">
                    <option value="">Use manual/default domain</option>
                    <?php foreach ($activeDomains as $domain): ?>
                        <option value="<?php echo (int) $domain['id']; ?>" <?php echo (int) $form['mailgun_domain_id'] === (int) $domain['id'] ? 'selected' : ''; ?>>
                            <?php echo e($domain['domain']); ?> (<?php echo e($domain['region']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Manual Mailgun Domain <input name="mailgun_domain" value="<?php echo e((string) $form['mailgun_domain']); ?>" placeholder="Optional fallback"></label>
            <label>Language <input name="language" value="<?php echo e((string) $form['language']); ?>"></label>
            <label>Topic <input name="topic" value="<?php echo e((string) $form['topic']); ?>"></label>
            <label>From Pattern
                <input name="from_pattern" value="<?php echo e((string) $form['from_pattern']); ?>" required>
                <small>Preview: <?php echo e(FromGenerator::preview((string) $form['from_pattern'])); ?></small>
            </label>
            <label>Delay Mode
                <select name="delay_mode">
                    <option value="fixed" <?php echo $form['delay_mode'] === 'fixed' ? 'selected' : ''; ?>>fixed</option>
                    <option value="random" <?php echo $form['delay_mode'] === 'random' ? 'selected' : ''; ?>>random</option>
                </select>
            </label>
            <label>Delay Min Seconds <input name="delay_min_seconds" type="number" min="0" value="<?php echo (int) $form['delay_min_seconds']; ?>"></label>
            <label>Delay Max Seconds <input name="delay_max_seconds" type="number" min="0" value="<?php echo (int) $form['delay_max_seconds']; ?>"></label>
            <label>Batch Size <input name="batch_size" type="number" min="1" value="<?php echo (int) $form['batch_size']; ?>"></label>
        </div>

        <fieldset>
            <legend>Recipients</legend>
            <div class="checkbox-list">
                <?php foreach ($activeRecipients as $recipient): ?>
                    <label class="checkbox">
                        <input type="checkbox" name="recipient_ids[]" value="<?php echo (int) $recipient['id']; ?>" <?php echo in_array((int) $recipient['id'], $selectedIds, true) ? 'checked' : ''; ?>>
                        <?php echo e($recipient['name']); ?> &lt;<?php echo e($recipient['email']); ?>&gt;
                    </label>
                <?php endforeach; ?>
                <?php if (!$activeRecipients): ?><p class="empty">Add whitelist recipients before saving a preset.</p><?php endif; ?>
            </div>
        </fieldset>

        <fieldset>
            <legend>Attachments</legend>
            <label>
                Add Attachments
                <input name="attachments[]" type="file" multiple>
                <small>Stored under storage/attachments and sent with each queued message for this preset.</small>
            </label>
            <?php if (!empty($preset['attachments'])): ?>
                <table>
                    <thead><tr><th>Name</th><th>Type</th><th>Size</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($preset['attachments'] as $attachment): ?>
                        <tr>
                            <td><?php echo e($attachment['original_name']); ?></td>
                            <td><?php echo e($attachment['mime_type']); ?></td>
                            <td><?php echo number_format((int) $attachment['size_bytes']); ?> bytes</td>
                            <td>
                                <button type="submit" name="action" value="delete_attachment" formnovalidate formaction="/preset-edit.php?id=<?php echo (int) $form['id']; ?>" onclick="this.form.attachment_id.value='<?php echo (int) $attachment['id']; ?>'">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <input type="hidden" name="attachment_id" value="">
        </fieldset>

        <label>
            JSON Emails
            <textarea name="json_payload" rows="18" spellcheck="false" required><?php echo e((string) $form['json_payload']); ?></textarea>
        </label>

        <div class="button-row">
            <button type="submit" name="action" value="validate">Validate JSON / Preview</button>
            <button type="submit" name="action" value="save">Save Preset</button>
            <?php if ($form['id']): ?><a class="button secondary" href="/send-job.php?preset_id=<?php echo (int) $form['id']; ?>">Create Sending Job</a><?php endif; ?>
        </div>
    </form>
</section>

<?php if ($validation && $validation['valid']): ?>
    <section class="section">
        <h2>Preview Emails</h2>
        <table>
            <thead><tr><th>#</th><th>Subject</th><th>Body Preview</th><th>Tags</th></tr></thead>
            <tbody>
            <?php foreach ($validation['data']['emails'] as $index => $email): ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo e($email['subject'] ?? ''); ?></td>
                    <td><?php echo e(mb_substr((string) ($email['body'] ?? ''), 0, 160)); ?></td>
                    <td><?php echo e(implode(', ', $email['tags'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>
<?php View::footer(); ?>
