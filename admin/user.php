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

// Detect password column dynamically in the users table
function get_password_column_name(): ?string {
    static $colName = false;
    if ($colName !== false) {
        return $colName;
    }
    $res = db()->query("SHOW COLUMNS FROM users");
    if (!$res) {
        $colName = null;
        return null;
    }
    $columns = [];
    while ($row = $res->fetch_assoc()) {
        $columns[] = strtolower($row['Field']);
    }
    foreach (['password_hash', 'password', 'pass', 'passwd', 'pwd', 'user_password'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $colName = $candidate;
            return $colName;
        }
    }
    $colName = null;
    return null;
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_value('action') ?? 'update';

    // 1. Send Email Template with Dynamic Placeholders
    if ($action === 'send_template') {
        $templateId = (int)post_value('template_id');
        $stmtTpl = db()->prepare('SELECT * FROM email_templates WHERE id = ? LIMIT 1');
        $stmtTpl->bind_param('i', $templateId);
        $stmtTpl->execute();
        $tpl = $stmtTpl->get_result()->fetch_assoc();

        if (!$tpl) {
            flash('error', 'Selected email template was not found.');
            redirect('/admin/user.php?id=' . $id);
        }

        $isHttps  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
        $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $loginUrl = ($isHttps ? 'https://' : 'http://') . $host . '/login.php';

        $needsPassword = (bool)preg_match('/\{\{\s*password\s*\}\}/i', $tpl['subject'] . ' ' . $tpl['body']);
        $plainPassword = '';

        if ($needsPassword) {
            $pwdCol = get_password_column_name();
            if ($pwdCol) {
                $plainPassword = bin2hex(random_bytes(5));
                $hashed = password_hash($plainPassword, PASSWORD_DEFAULT);

                $stmtPwd = db()->prepare("UPDATE users SET `{$pwdCol}` = ? WHERE id = ?");
                $stmtPwd->bind_param('si', $hashed, $id);
                $stmtPwd->execute();
            } else {
                $plainPassword = '[Unavailable - check DB schema]';
            }
        }

        $placeholders = [
            'email'     => $user['email'],
            'login_url' => $loginUrl,
            'password'  => $plainPassword,
            'role'      => $user['role'],
            'status'    => $user['status'],
            'user_id'   => (string)$user['id'],
            'date'      => date('Y-m-d')
        ];

        $replaceTags = function (string $text) use ($placeholders): string {
            return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($matches) use ($placeholders) {
                $key = strtolower($matches[1]);
                return array_key_exists($key, $placeholders) ? (string)$placeholders[$key] : $matches[0];
            }, $text);
        };

        $renderedSubject = $replaceTags($tpl['subject']);
        $renderedBody    = $replaceTags($tpl['body']);

        $sent = false;

        if (function_exists('send_mail')) {
            $sent = send_mail($user['email'], $renderedSubject, $renderedBody);
        } elseif (function_exists('send_notice')) {
            $sent = send_notice($tpl['template_key'] ?: 'custom_notification', $user['email'], $placeholders);
        }

        if ($sent) {
            $msg = 'Email "' . e($tpl['name']) . '" sent to ' . e($user['email']) . '.';
            if ($needsPassword && $plainPassword !== '') {
                $msg .= ' A temporary password was generated and included in the email.';
            }
            flash('success', $msg);
        } else {
            flash('error', 'Could not send the email. Please check your SMTP settings in Settings Management.');
        }

        redirect('/admin/user.php?id=' . $id);
    }

    // 2. Delete User (Admin Protected)
    if ($action === 'delete') {
        if ($user['role'] === 'Admin') {
            flash('error', 'Administrator accounts cannot be deleted.');
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

    // 3. Update User Role & Status
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

    if ($old !== $status && function_exists('send_notice')) {
        send_notice($status === 'Suspended' ? 'account_suspended' : 'account_unsuspended', $user['email'], ['email' => $user['email']]);
    }

    flash('success', 'User account updated.');
    redirect('/admin/user.php?id=' . $id);
}

// Fetch Job Stats
$stmt = db()->prepare('SELECT status, COUNT(*) count FROM jobs WHERE user_id = ? GROUP BY status');
$stmt->bind_param('i', $id);
$stmt->execute();
$jobs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch Available Email Templates
$templatesQuery = db()->query('SELECT id, name, subject, is_default FROM email_templates ORDER BY is_default DESC, name ASC');
$templates = $templatesQuery ? $templatesQuery->fetch_all(MYSQLI_ASSOC) : [];

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

        <div class="eyebrow">NOTIFICATIONS</div>
        <h3 style="margin: 6px 0 4px; font-size: 1.1rem;">Send email template</h3>
        <p class="muted" style="font-size: 0.85rem; margin-top: 0; margin-bottom: 14px;">Tags like <code>{{email}}</code>, <code>{{login_url}}</code>, and <code>{{password}}</code> are replaced automatically.</p>

        <?php if (empty($templates)): ?>
            <p class="muted" style="font-size: 0.85rem;">No email templates available.</p>
        <?php else: ?>
            <form method="post" class="form-stack" onsubmit="return confirm('Send this email template to <?= e($user['email']) ?>?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="send_template">

                <label>Template
                    <select name="template_id" required>
                        <?php foreach ($templates as $tpl): ?>
                            <option value="<?= (int)$tpl['id'] ?>">
                                <?= e($tpl['name']) ?> (<?= $tpl['is_default'] ? 'System' : 'Custom' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <button class="button button-soft button-wide">Send template email</button>
            </form>
        <?php endif; ?>

        <hr style="margin: 24px 0; border: 0; border-top: 1px solid var(--border-color, #e2e8f0);">

        <div class="eyebrow" style="color: #dc2626;">DANGER ZONE</div>
        <h3 style="margin: 6px 0 4px; font-size: 1.1rem;">Delete account</h3>

        <?php if ($user['role'] === 'Admin'): ?>
            <p class="muted" style="font-size: 0.85rem; margin-top: 0; margin-bottom: 14px;">
                Administrator accounts are protected and cannot be deleted. Demote this user to <strong>User</strong> first if removal is required.
            </p>
            <button type="button" class="button button-danger button-wide" disabled style="opacity: 0.5; cursor: not-allowed; background: #94a3b8; color: #ffffff; border: none;">
                Delete protected (Admin)
            </button>
        <?php else: ?>
            <p class="muted" style="font-size: 0.85rem; margin-top: 0; margin-bottom: 14px;">
                Permanently remove this user account. This action cannot be undone.
            </p>
            <form method="post" onsubmit="return confirm('Are you sure you want to permanently delete this user? This cannot be undone.');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <button class="button button-danger button-wide" style="background: #dc2626; color: #ffffff; border: none; cursor: pointer;">
                    Delete user
                </button>
            </form>
        <?php endif; ?>
    </aside>
</div>

<?php render_footer(); ?>
