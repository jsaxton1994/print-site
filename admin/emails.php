<?php
require __DIR__ . '/../app/bootstrap.php';
refresh_user();
require_login('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action  = post_value('action');
    $subject = trim((string)post_value('subject'));
    $body    = trim((string)($_POST['body'] ?? ''));

    // Create New Custom Template
    if ($action === 'custom') {
        if ($subject !== '' && $body !== '') {
            $key  = 'custom_' . bin2hex(random_bytes(8));
            $name = substr(post_value('name') ?: $subject, 0, 120);

            $stmt = db()->prepare('INSERT INTO email_templates(template_key, name, subject, body, is_default) VALUES(?, ?, ?, ?, 0)');
            $stmt->bind_param('ssss', $key, $name, $subject, $body);
            $stmt->execute();

            flash('success', 'Custom email template created.');
        } else {
            flash('error', 'Add a template name, subject, and body.');
        }
        redirect('/admin/emails.php');
    }

    // Update Existing Template
    if ($action === 'update') {
        $id = (int)post_value('id');

        if ($id > 0 && $subject !== '' && $body !== '') {
            $stmt = db()->prepare('SELECT is_default, name FROM email_templates WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();

            if ($existing) {
                $name = $existing['is_default'] ? $existing['name'] : substr(post_value('name') ?: $subject, 0, 120);

                $stmt = db()->prepare('UPDATE email_templates SET name = ?, subject = ?, body = ? WHERE id = ?');
                $stmt->bind_param('sssi', $name, $subject, $body, $id);
                $stmt->execute();

                flash('success', 'Email template updated successfully.');
            } else {
                flash('error', 'Email template not found.');
            }
        } else {
            flash('error', 'Subject and body cannot be empty.');
        }
        redirect('/admin/emails.php');
    }

    redirect('/admin/emails.php');
}

$templates = db()->query('SELECT * FROM email_templates ORDER BY is_default DESC, name')->fetch_all(MYSQLI_ASSOC);

render_header('Emails Management', 'admin');
page_title('Emails Management', 'Review notification templates and custom messages.');
?>

<div class="section-heading users-toolbar">
    <div>
        <div class="eyebrow">NOTIFICATIONS</div>
        <h2>Email templates <span class="count-pill"><?= count($templates) ?></span></h2>
        <p>Default templates are system managed and cannot be removed.</p>
    </div>
    <a class="button button-admin" href="#create-template">＋ Create email template</a>
</div>

<div class="template-grid">
    <?php foreach ($templates as $t): ?>
        <article class="template-card">
            <div class="template-card-top">
                <span class="template-icon">✉</span>
                <?= $t['is_default'] ? '<span class="template-label">DEFAULT · SYSTEM</span>' : '<span class="template-label custom-label">CUSTOM</span>' ?>
            </div>
            <h3><?= e($t['name']) ?></h3>
            <div class="template-subject">Subject: <?= e($t['subject']) ?></div>
            <p><?= e(mb_strimwidth($t['body'], 0, 150, '…')) ?></p>
            <details>
                <summary>Preview full template</summary>
                <pre><?= e($t['body']) ?></pre>
            </details>
            <div style="margin-top: 14px;">
                <a class="button button-soft button-small" href="#edit-template-<?= (int)$t['id'] ?>">Edit template</a>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<!-- Create Template Modal -->
<div class="modal-backdrop" id="create-template">
    <div class="modal-card">
        <a class="modal-close" href="/admin/emails.php">×</a>
        <div class="eyebrow">CUSTOM MESSAGE</div>
        <h2>Create email template</h2>
        <p class="muted">Available dynamic tags: <code>{{email}}</code>, <code>{{login_url}}</code>, <code>{{password}}</code>, <code>{{role}}</code></p>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="custom">

            <label>Template name
                <input name="name" required placeholder="e.g. Collection reminder">
            </label>
            <label>Subject
                <input name="subject" required placeholder="Email subject">
            </label>
            <label>Body
                <textarea name="body" rows="6" required placeholder="Hello {{email}},&#10;&#10;Your login link: {{login_url}}&#10;Your password: {{password}}"></textarea>
            </label>
            <button class="button button-admin button-wide">Save custom template</button>
        </form>
    </div>
</div>

<!-- Edit Template Modals -->
<?php foreach ($templates as $t): ?>
    <div class="modal-backdrop" id="edit-template-<?= (int)$t['id'] ?>">
        <div class="modal-card">
            <a class="modal-close" href="/admin/emails.php">×</a>
            <div class="eyebrow"><?= $t['is_default'] ? 'SYSTEM NOTIFICATION' : 'CUSTOM MESSAGE' ?></div>
            <h2>Edit email template</h2>
            <p class="muted">Available dynamic tags: <code>{{email}}</code>, <code>{{login_url}}</code>, <code>{{password}}</code>, <code>{{role}}</code></p>

            <form method="post" class="form-stack">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">

                <label>Template name
                    <input name="name" value="<?= e($t['name']) ?>" required <?= $t['is_default'] ? 'readonly' : '' ?>>
                    <?php if ($t['is_default']): ?>
                        <small class="muted" style="font-size: 11px;">Default template names cannot be changed.</small>
                    <?php endif; ?>
                </label>

                <label>Subject
                    <input name="subject" value="<?= e($t['subject']) ?>" required placeholder="Email subject">
                </label>

                <label>Body
                    <textarea name="body" rows="8" required placeholder="Write the email message..."><?= e($t['body']) ?></textarea>
                </label>

                <button class="button button-admin button-wide">Save template changes</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<?php render_footer(); ?>
