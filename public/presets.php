<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
ensure_installed();

use App\PresetService;
use App\View;

$user = require_auth();
$service = new PresetService(db());

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'clone') {
            $newId = $service->clone((int) $user['id'], (int) $_POST['preset_id']);
            flash('success', 'Preset cloned');
            redirect('/preset-edit.php?id=' . $newId);
        } elseif ($action === 'import') {
            $json = trim((string) ($_POST['preset_json'] ?? ''));
            if ($json === '' && !empty($_FILES['preset_file']['tmp_name'])) {
                $json = (string) file_get_contents((string) $_FILES['preset_file']['tmp_name']);
            }
            $newId = $service->import((int) $user['id'], $json);
            flash('success', 'Preset imported');
            redirect('/preset-edit.php?id=' . $newId);
        }
    } catch (Throwable $throwable) {
        flash('error', $throwable->getMessage());
        redirect('/presets.php');
    }
}

$presets = $service->all((int) $user['id']);

View::header('Presets', $user);
?>
<section class="section">
    <div class="section-title">
        <h2>Saved Presets</h2>
        <div class="button-row">
            <a class="button secondary" href="#import-preset">Import</a>
            <a class="button" href="/preset-edit.php">Create Preset</a>
        </div>
    </div>
    <table>
        <thead>
        <tr><th>Name</th><th>Domain</th><th>Language</th><th>Topic</th><th>Recipients</th><th>Delay</th><th>Status</th><th>Updated</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($presets as $preset): ?>
            <tr>
                <td><?php echo e($preset['name']); ?></td>
                <td><?php echo e($preset['managed_domain'] ?: $preset['mailgun_domain']); ?></td>
                <td><?php echo e($preset['language']); ?></td>
                <td><?php echo e($preset['topic']); ?></td>
                <td><?php echo (int) $preset['recipient_count']; ?></td>
                <td><?php echo e($preset['delay_mode']); ?> <?php echo (int) $preset['delay_min_seconds']; ?>-<?php echo (int) $preset['delay_max_seconds']; ?>s</td>
                <td><span class="status <?php echo e($preset['status']); ?>"><?php echo e($preset['status']); ?></span></td>
                <td><?php echo e($preset['updated_at']); ?></td>
                <td class="actions">
                    <a class="button secondary" href="/preset-edit.php?id=<?php echo (int) $preset['id']; ?>">Edit</a>
                    <a class="button secondary" href="/preset-export.php?id=<?php echo (int) $preset['id']; ?>">Export</a>
                    <form method="post" class="compact-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="clone">
                        <input type="hidden" name="preset_id" value="<?php echo (int) $preset['id']; ?>">
                        <button type="submit">Clone</button>
                    </form>
                    <a class="button" href="/send-job.php?preset_id=<?php echo (int) $preset['id']; ?>">Create Sending Job</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$presets): ?><tr><td colspan="9" class="empty">No presets yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</section>

<section class="section" id="import-preset">
    <h2>Import Preset</h2>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="import">
        <label>Preset JSON File <input name="preset_file" type="file" accept="application/json,.json"></label>
        <label>Or Paste Preset JSON <textarea name="preset_json" rows="8" spellcheck="false"></textarea></label>
        <button type="submit">Import Preset</button>
    </form>
</section>
<?php View::footer(); ?>
