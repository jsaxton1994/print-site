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
        $from       = trim((string)post_value('mail_from'));
        $fromName   = trim((string)post_value('mail_from_name'));
        $host       = trim((string)post_value('mail_host'));
        $port       = (int)post_value('mail_port');
        $username   = trim((string)post_value('mail_username'));
        $password   = (string)post_value('mail_password');
        $encryption = strtolower(trim((string)post_value('mail_encryption')));

        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please enter a valid sender (From) email address.');
            redirect('/admin/settings.php');
        }

        if ($host === '') {
            flash('error', 'SMTP Host is required.');
            redirect('/admin/settings.php');
        }

        if ($port <= 0 || $port > 65535) {
            flash('error', 'Please enter a valid port number (e.g. 587, 465, 25).');
            redirect('/admin/settings.php');
        }

        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $encryption = 'tls';
        }

        // Save SMTP settings
        set_setting('mail_from', $from);
        set_setting('mail_from_name', $fromName ?: 'Print Portal');
        set_setting('mail_host', $host);
        set_setting('mail_port', (string)$port);
        set_setting('mail_username', $username);
        set_setting('mail_encryption', $encryption);

        // Only update the password if a new one was provided
        if ($password !== '') {
            set_setting('mail_password', $password);
        }

        flash('success', 'SMTP mail settings saved.');
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

    <!-- SMTP Mail Settings -->
    <section class="settings-card">
        <div class="settings-heading">
            <span class="settings-icon violet">✉</span>
            <div>
                <h2>SMTP Mail Settings</h2>
                <p>Connect an authenticated SMTP server for reliable system notices, user passwords, and Epson dispatch.</p>
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

            <div class="field-row">
                <label>SMTP Host
                    <input name="mail_host" type="text" value="<?=e(setting('mail_host', ''))?>" required placeholder="smtp.gmail.com or mail.example.com">
                </label>
                <label>SMTP Port
                    <input name="mail_port" type="number" min="1" max="65535" value="<?=e(setting('mail_port', '587'))?>" required placeholder="587">
                </label>
            </div>

            <div class="field-row">
                <label>Encryption
                    <select name="mail_encryption">
                        <?php $enc = strtolower(setting('mail_encryption', 'tls')); ?>
                        <option value="tls" <?=$enc === 'tls' ? 'selected' : ''?>>TLS (Recommended, port 587)</option>
                        <option value="ssl" <?=$enc === 'ssl' ? 'selected' : ''?>>SSL (Port 465)</option>
                        <option value="none" <?=$enc === 'none' ? 'selected' : ''?>>None (Plain / Port 25)</option>
                    </select>
                </label>
                <label>SMTP Username
                    <input name="mail_username" type="text" value="<?=e(setting('mail_username', ''))?>" placeholder="user@example.com">
                </label>
            </div>

            <label>SMTP Password
                <input name="mail_password" type="password" placeholder="<?=setting('mail_password') ? '•••••••• (leave blank to keep current password)' : 'Enter SMTP password'?>">
            </label>

            <button class="button button-admin">Save SMTP settings</button>
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
