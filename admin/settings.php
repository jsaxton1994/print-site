<?php
require __DIR__ . '/../app/bootstrap.php';
refresh_user();
require_login('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $section = post_value('section');

    if ($section === 'printer') {
        $paper   = post_value('paper_size');
        $colour  = post_value('colour_options');
        $address = post_value('printer_email');

        if (!in_array($paper, ['A4', 'A3', 'Letter', 'Legal', '4x6'], true) ||
            !in_array($colour, ['Colour', 'Black & White'], true) ||
            ($address !== '' && !filter_var($address, FILTER_VALIDATE_EMAIL))) {
            flash('error', 'Check paper, colour, or printer email address.');
            redirect('/admin/settings.php');
        }

        set_setting('paper_size', $paper);
        set_setting('colour_options', $colour);
        set_setting('printer_email', $address);
        flash('success', 'Epson printer settings saved.');
    } elseif ($section === 'mail') {
        $from = post_value('mail_from');

        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please enter a valid sender email address.');
            redirect('/admin/settings.php');
        }

        // Save PHP mail settings
        set_setting('mail_from', $from);
        set_setting('mail_from_name', post_value('mail_from_name'));

        // Clear SMTP settings so the application falls back to PHP mail()
        set_setting('mail_host', '');
        set_setting('mail_port', '');
        set_setting('mail_username', '');
        set_setting('mail_password', '');
        set_setting('mail_encryption', 'none');

        flash('success', 'PHP mail settings saved.');
    } elseif ($section === 'database') {
        flash('info', 'Database connection details are managed using the server environment (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS) to avoid storing secrets in the database.');
    }

    redirect('/admin/settings.php');
}

render_header('Settings Management', 'admin');
page_title('Settings Management', 'Configure printer delivery and application connections.');
?>
<div class="settings-stack">
    <!-- Printer Settings -->
    <section class="settings-card">
        <div class="settings-heading">
            <span class="settings-icon orange">▣</span>
            <div>
                <h2>Epson Printer Settings</h2>
                <p>Defaults applied to new client print requests.</p>
            </div>
        </div>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="section" value="printer">
            <div class="field-row">
                <label>Paper size
                    <select name="paper_size">
                        <?php foreach (['A4', 'A3', 'Letter', 'Legal', '4x6'] as $o): ?>
                            <option <?=$o === setting('paper_size', 'A4') ? 'selected' : ''?>><?=e($o)?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Colour options
                    <select name="colour_options">
                        <?php foreach (['Colour', 'Black & White'] as $o): ?>
                            <option <?=$o === setting('colour_options', 'Colour') ? 'selected' : ''?>><?=e($o)?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <label>Epson Email Print address
                <input type="email" name="printer_email" value="<?=e(setting('printer_email'))?>" placeholder="printer@print.epsonconnect.com">
                <small>Approved jobs are emailed as an attachment; print settings are included in the subject and message.</small>
            </label>
            <button class="button button-admin">Save printer settings</button>
        </form>
    </section>

    <!-- PHP Mail Settings -->
    <section class="settings-card">
        <div class="settings-heading">
            <span class="settings-icon violet">✉</span>
            <div>
                <h2>PHP Mail Settings</h2>
                <p>Standard PHP mail transport for account and job notifications.</p>
            </div>
        </div>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="section" value="mail">
            <div class="field-row">
                <label>From email
                    <input name="mail_from" type="email" value="<?=e(setting('mail_from', ''))?>" required placeholder="noreply@example.com">
                </label>
                <label>From name
                    <input name="mail_from_name" value="<?=e(setting('mail_from_name', 'Print Portal'))?>" placeholder="Print Portal">
                </label>
            </div>
            <div class="notice-card">
                The application sends mail using the server's native PHP <code>mail()</code> transport (sendmail / postfix). Epson dispatch uses this same transport.
            </div>
            <button class="button button-admin">Save mail settings</button>
        </form>
    </section>

    <!-- SQL Database Info -->
    <section class="settings-card">
        <div class="settings-heading">
            <span class="settings-icon blue">⌘</span>
            <div>
                <h2>SQL Database settings</h2>
                <p>Connection credentials are set by the server, not stored in the portal.</p>
            </div>
        </div>
        <div class="db-env-list">
            <div><span>DB_HOST</span><strong><?=e(getenv('DB_HOST') ?: '127.0.0.1')?> · <?=e(getenv('DB_PORT') ?: '3306')?></strong></div>
            <div><span>DB_NAME</span><strong><?=e(getenv('DB_NAME') ?: 'print_portal')?></strong></div>
            <div><span>DB_USER</span><strong><?=e(getenv('DB_USER') ?: 'root')?></strong></div>
            <div><span>DB_PASS</span><strong>•••••••• (stored in environment)</strong></div>
        </div>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="section" value="database">
            <button class="button button-soft">View connection guidance</button>
        </form>
    </section>
</div>
<?php render_footer(); ?>
