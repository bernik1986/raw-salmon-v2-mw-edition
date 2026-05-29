<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
ensure_installed();

use App\UserService;
use App\View;

$user = require_admin();
$service = new UserService(db());

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create') {
            $service->create((string) $_POST['name'], (string) $_POST['email'], (string) $_POST['password'], (string) $_POST['role']);
            flash('success', 'User created');
        } elseif ($action === 'update') {
            $service->update((int) $_POST['id'], (string) $_POST['name'], (string) $_POST['email'], (string) $_POST['role'], (string) $_POST['status']);
            flash('success', 'User updated');
        } elseif ($action === 'reset_password') {
            $service->resetPassword((int) $_POST['id'], (string) $_POST['password']);
            flash('success', 'Password reset');
        }
    } catch (Throwable $throwable) {
        flash('error', $throwable->getMessage());
    }
    redirect('/users.php');
}

$users = $service->all();
View::header('Users', $user);
?>
<section class="section">
    <h2>Create User</h2>
    <form method="post" class="form-grid columns">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="create">
        <label>Name <input name="name" required></label>
        <label>Email <input name="email" type="email" required></label>
        <label>Password <input name="password" type="password" minlength="8" required></label>
        <label>Role
            <select name="role">
                <option value="user">User</option>
                <option value="admin">Admin</option>
            </select>
        </label>
        <button type="submit">Create User</button>
    </form>
</section>

<section class="section">
    <h2>All Users</h2>
    <table>
        <thead>
        <tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th><th>Last login</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $row): ?>
            <tr>
                <td><?php echo (int) $row['id']; ?></td>
                <td colspan="6">
                    <form method="post" class="inline-edit">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                        <input name="name" value="<?php echo e($row['name']); ?>" required>
                        <input name="email" type="email" value="<?php echo e($row['email']); ?>" required>
                        <select name="role">
                            <option value="user" <?php echo $row['role'] === 'user' ? 'selected' : ''; ?>>User</option>
                            <option value="admin" <?php echo $row['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        </select>
                        <select name="status">
                            <option value="active" <?php echo $row['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $row['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                        <span><?php echo e($row['created_at']); ?></span>
                        <span><?php echo e($row['last_login_at'] ?? ''); ?></span>
                        <button type="submit">Save</button>
                    </form>
                </td>
                <td>
                    <form method="post" class="compact-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                        <input name="password" type="password" minlength="8" placeholder="New password" required>
                        <button type="submit">Reset</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php View::footer(); ?>
