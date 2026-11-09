<?php
require __DIR__ . '/../app/bootstrap.php';
refresh_user();
$actor = require_login('admin');

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT id, email, role, status, created_at FROM users WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    http_response_code(404);
    exit('User not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_value('action') ?? 'update';

    // Handle Delete User
    if ($action === 'delete') {
        if ($id === (int)$actor['id']) {
            flash('error', 'You cannot delete your own administrator account.');
            redirect('/admin/user.php?id=' . $id);
        }

        try {
            $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();

            flash('success', 'User account ' . e($user['email']) . ' was permanently deleted.');
            redirect('/admin/users.php');
        } catch (mysqli_sql_exception $ex) {
            flash('error', 'Cannot delete this user because they have associated jobs or records. Suspend the account instead.');
            redirect('/admin/user.php?id=' . $id);
        }
    }

    // Handle Update Role / Status
    $role   = post_value('role');
    $status = post_value('status');

    if (!in_array($role, ['User', 'Admin'], true) || !in_array($status, ['Active', 'Suspended'], true)) {
        flash('error', 'Invalid role or status.');
        redirect('/admin/user.php?id=' . $id);
    }

    if ($id === (int)$actor['id'] && ($role !== 'Admin' || $status !== 'Active')) {
        flash('error', 'You cannot suspend or remove your own administrator access.');
        redirect('/admin/user.php?id=' . $id);
    }

    $old = $user['status'];
    $stmt = db()->prepare('UPDATE users SET role = ?, status = ? WHERE id = ?');
    $stmt->bind_param('ssi', $role, $status, $id);
    $stmt->execute();

    if ($old !== $status) {
        send_notice($status === 'Suspended' ? 'account_suspended' : 'account_unsuspended', $user['email'], ['email' => $user['email']]);
    }

    flash('success', 'User account updated.');
    redirect('/admin/user.php?id=' . $id);
}

$stmt = db()->prepare('SELECT status, COUNT(*) count FROM jobs WHERE user_id = ? GROUP BY status');
$stmt->bind_param('i', $id);
$stmt->execute();
$jobs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

render_header('User Details', 'admin');
page_title('User details', 'Review this account and update access.');
?>

<div class="detail-layout">
    <div class="table-card detail-card">
        <div class="detail-top">
            <div class="file-cell">
                <span class="avatar profile-avatar"><?= e(strtoupper(substr($user['email'], 0, 1))) ?></span>
                <span>
                    <strong><?= e($user['email']) ?></strong>
                    <small>User ID #<?= str_pad((string)$user['id'], 4, '0', STR_PAD_LEFT) ?></small>
                </span>
            </div>
            <?= status_badge($user['status']) ?>
        </div>

        <div class="detail-grid">
            <div>
                <small>Account role</small>
                <strong><?= e($user['role']) ?></strong>
            </div>
            <div>
                <small>Created</small>
                <strong><?= e(date('M j, Y', strtotime($user['created_at']))) ?></strong>
            </div>
        </div>

        <div class="detail-note">
            <small>JOB ACTIVITY</small>
            <div class="mini-stats">
                <?php if (!$jobs): ?>
                    <span>No submitted jobs yet.</span>
                <?php else: foreach ($jobs as $s): ?>
                    <span><?= status_badge($s['status']) ?> <strong><?= (int)$s['count'] ?></strong></span>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <a class="button button-soft" href="/admin/users.php">← Back to users</a>
    </div>

    <aside class="action-card">
        <div class="eyebrow">ACCOUNT ACCESS</div>
        <h2>Edit user</h2>
        <p>Suspending an account prevents the user from signing in.</p>

        <form method="post" class="form-stack">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update">

            <label>Role
                <select name="role">
                    <option <?= $user['role'] === 'User' ? 'selected' : '' ?>>User</option>
                    <option <?= $user['role'] === 'Admin' ? 'selected' : '' ?>>Admin</option>
                </select>
            </label>

            <label>Status
                <select name="status">
                    <option <?= $user['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option <?= $user['status'] === 'Suspended' ? 'selected' : '' ?>>Suspended</option>
                </select>
            </label>

            <button class="button button-admin button-wide">Save changes</button>
        </form>

        <hr style="margin: 24px 0; border: 0; border-top: 1px solid var(--border-color, #e2e8f0);">

        <div class="eyebrow" style="color: #dc2626;">DANGER ZONE</div>
        <h3 style="margin: 6px 0 4px; font-size: 1.1rem;">Delete account</h3>
        <p class="muted" style="font-size: 0.85rem; margin-top: 0; margin-bottom: 14px;">Permanently remove this user account. This action cannot be undone.</p>

        <form method="post" onsubmit="return confirm('Are you sure you want to permanently delete this user? This cannot be undone.');">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <button class="button button-danger button-wide" style="background: #dc2626; color: #ffffff; border: none; cursor: pointer;">Delete user</button>
        </form>
    </aside>
</div>

<?php render_footer(); ?>
