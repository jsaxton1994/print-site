<?php
require __DIR__ . '/../app/bootstrap.php';
refresh_user();
require_login('admin');

// Helper to extract options from database settings split with a comma
function get_db_comma_options(string $primaryKey, array $alternateKeys = [], array $fallback = []): array {
    $keys = array_merge([$primaryKey], $alternateKeys);
    foreach ($keys as $k) {
        $val = setting($k, '');
        if ($val !== '') {
            $items = array_filter(array_map('trim', explode(',', $val)));
            if (!empty($items)) {
                return array_values(array_unique($items));
            }
        }
    }
    return $fallback;
}

// Fetch paper size options and colour options from database settings split with a comma
$allPaperOptions  = get_db_comma_options('paper_size', ['paper_sizes', 'all_paper_sizes'], ['A4', 'A3', 'Letter', 'Legal', '4x6']);
$allColourOptions = get_db_comma_options('colour_options', ['color_options', 'colour_mode'], ['Colour', 'Black & White']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $section = post_value('section');

    if ($section === 'printer') {
        $address        = post_value('printer_email');
        $maxAttachments = max(1, min(50, (int)post_value('max_attachments_limit', '10')));
        $completeMode   = post_value('auto_complete_mode');
        $delayMinutes   = max(1, (int)post_value('auto_complete_minutes', '2'));

        // Collect and validate selected allowed paper sizes
        $postedAllowedPaper = $_POST['allowed_paper_sizes'] ?? [];
        if (!is_array($postedAllowedPaper)) {
            $postedAllowedPaper = [];
        }
        $validAllowedPaper = array_values(array_intersect($postedAllowedPaper, $allPaperOptions));

        if (empty($validAllowedPaper)) {
            flash('error', 'Please select at least one allowed paper size.');
            redirect('/admin/settings.php');
        }

        // Collect and validate selected allowed colour options
        $postedAllowedColour = $_POST['allowed_colour_options'] ?? [];
        if (!is_array($postedAllowedColour)) {
            $postedAllowedColour = [];
        }
        $validAllowedColour = array_values(array_intersect($postedAllowedColour, $allColourOptions));

        if (empty($validAllowedColour)) {
            flash('error', 'Please select at least one allowed color option.');
            redirect('/admin/settings.php');
        }

        if ($address !== '' && !filter_var($address, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Check printer email address.');
            redirect('/admin/settings.php');
        }

        // Save selected allowed choices
        set_setting('allowed_paper_sizes', implode(',', $validAllowedPaper));
        set_setting('allowed_colour_options', implode(',', $validAllowedColour));

        set_setting('printer_email', $address);
        set_setting('max_attachments_limit', (string)$maxAttachments);
        set_setting('auto_complete_mode', in_array($completeMode, ['timer', 'immediate', 'manual'], true) ? $completeMode : 'timer');
        set_setting('auto_complete_minutes', (string)$delayMinutes);

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

        set_setting('mail_from', $from);
        set_setting('mail_from_name', $fromName ?: 'Print Portal');
        set_setting('mail_host', $host);
        set_setting('mail_port', (string)$port);
        set_setting('mail_username', $username);
        set_setting('mail_encryption', $encryption);

        if ($password !== '') {
            set_setting('mail_password', $password);
        }

        flash('success', 'SMTP mail settings saved.');
    } elseif ($section === 'imap') {
        $iHost = trim((string)post_value('imap_host'));
        $iPort = (int)post_value('imap_port', '993');
        $iUser = trim((string)post_value('imap_username'));
        $iPass = (string)post_value('imap_password');
        $iEnc  = strtolower(trim((string)post_value('imap_encryption', 'ssl')));

        set_setting('imap_host', $iHost);
        set_setting('imap_port', (string)$iPort);
        set_setting('imap_username', $iUser);
        set_setting('imap_encryption', $iEnc);

        if ($iPass !== '') {
            set_setting('imap_password', $iPass);
        }

        flash('success', 'IMAP mailbox settings saved.');
    } elseif ($section === 'database') {
        flash('info', 'Database connection details are managed using the server environment.');
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
                <p>Allowed paper sizes, allowed color options, and completion automation.</p>
            </div>
        </div>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="section" value="printer">

            <!-- Allowed Paper Size (from paper_size in database, split by comma) -->
            <div style="margin-bottom: 1.25rem;">
                <label style="display:block; margin-bottom: 0.5rem; font-weight: 600;">Allowed Paper Size</label>
                <div style="display:flex; gap: 1.25rem; flex-wrap: wrap; padding: 0.75rem 1rem; background: var(--bg-soft, rgba(0,0,0,0.02)); border: 1px solid var(--border, rgba(0,0,0,0.08)); border-radius: 8px;">
                    <?php 
                        $rawAllowedPaper = trim(setting('allowed_paper_sizes', ''));
                        if ($rawAllowedPaper !== '') {
                            $savedAllowedPaper = array_filter(array_map('trim', explode(',', $rawAllowedPaper)));
                        } else {
                            $savedAllowedPaper = $allPaperOptions;
                        }
                    ?>
                    <?php foreach ($allPaperOptions as $opt): ?>
                        <label style="display:inline-flex; align-items:center; gap: 6px; font-weight: normal; cursor: pointer; user-select: none;">
                            <input type="checkbox" name="allowed_paper_sizes[]" value="<?=e($opt)?>" <?=in_array($opt, $savedAllowedPaper, true) ? 'checked' : ''?>>
                            <span><?=e($opt)?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <small class="muted">Check the paper sizes loaded from the database that are enabled for use.</small>
            </div>

            <!-- Allowed Color Options (from colour_options in database, split by comma) -->
            <div style="margin-bottom: 1.25rem;">
                <label style="display:block; margin-bottom: 0.5rem; font-weight: 600;">Allowed Color Options</label>
                <div style="display:flex; gap: 1.25rem; flex-wrap: wrap; padding: 0.75rem 1rem; background: var(--bg-soft, rgba(0,0,0,0.02)); border: 1px solid var(--border, rgba(0,0,0,0.08)); border-radius: 8px;">
                    <?php 
                        $rawAllowedColour = trim(setting('allowed_colour_options', ''));
                        if ($rawAllowedColour !== '') {
                            $savedAllowedColour = array_filter(array_map('trim', explode(',', $rawAllowedColour)));
                        } else {
                            $savedAllowedColour = $allColourOptions;
                        }
                    ?>
                    <?php foreach ($allColourOptions as $opt): ?>
                        <label style="display:inline-flex; align-items:center; gap: 6px; font-weight: normal; cursor: pointer; user-select: none;">
                            <input type="checkbox" name="allowed_colour_options[]" value="<?=e($opt)?>" <?=in_array($opt, $savedAllowedColour, true) ? 'checked' : ''?>>
                            <span><?=e($opt)?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <small class="muted">Check the color options loaded from the database that are enabled for use.</small>
            </div>

            <label>Max attachments limit
                <input name="max_attachments_limit" type="number" min="1" max="50" value="<?=e(setting('max_attachments_limit', '10'))?>" required>
                <small>Maximum number of files allowed per job upload (Epson Email Print limit is 10).</small>
            </label>

            <label>Epson Email Print address
                <input type="email" name="printer_email" value="<?=e(setting('printer_email'))?>" placeholder="printer@print.epsonconnect.com">
                <small>Approved jobs are emailed directly to this printer address.</small>
            </label>

            <div class="field-row">
                <label>Automatic Job Completion
                    <select name="auto_complete_mode">
                        <?php $m = setting('auto_complete_mode', 'timer'); ?>
                        <option value="timer" <?=$m === 'timer' ? 'selected' : ''?>>Auto-complete after estimated print duration (Recommended)</option>
                        <option value="immediate" <?=$m === 'immediate' ? 'selected' : ''?>>Auto-complete immediately upon email dispatch</option>
                        <option value="manual" <?=$m === 'manual' ? 'selected' : ''?>>Only complete via Epson email response or manual check</option>
                    </select>
                </label>
                <label>Estimated print time (minutes)
                    <input name="auto_complete_minutes" type="number" min="1" max="60" value="<?=e(setting('auto_complete_minutes', '2'))?>">
                </label>
            </div>

            <button class="button button-admin">Save printer settings</button>
        </form>
    </section>

    <!-- SMTP Mail Settings -->
    <section class="settings-card">
        <div class="settings-heading">
            <span class="settings-icon violet">✉</span>
            <div>
                <h2>SMTP Mail Settings</h2>
                <p>Outgoing email server for sending files to Epson and client notices.</p>
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
                        <option value="tls" <?=$enc === 'tls' ? 'selected' : ''?>>TLS (Port 587)</option>
                        <option value="ssl" <?=$enc === 'ssl' ? 'selected' : ''?>>SSL (Port 465)</option>
                        <option value="none" <?=$enc === 'none' ? 'selected' : ''?>>None (Port 25)</option>
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

    <!-- IMAP Mailbox Settings -->
    <section class="settings-card">
        <div class="settings-heading">
            <span class="settings-icon blue">📥</span>
            <div>
                <h2>IMAP Inbox Settings (Optional)</h2>
                <p>Read incoming Epson error and completion emails directly from your mailbox.</p>
            </div>
        </div>
        <form method="post" class="settings-form">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="section" value="imap">

            <div class="field-row">
                <label>IMAP Host
                    <input name="imap_host" type="text" value="<?=e(setting('imap_host', setting('mail_host', '')) )?>" placeholder="imap.gmail.com or mail.example.com">
                </label>
                <label>IMAP Port
                    <input name="imap_port" type="number" min="1" max="65535" value="<?=e(setting('imap_port', '993'))?>" placeholder="993">
                </label>
            </div>

            <div class="field-row">
                <label>Encryption
                    <select name="imap_encryption">
                        <?php $iEnc = strtolower(setting('imap_encryption', 'ssl')); ?>
                        <option value="ssl" <?=$iEnc === 'ssl' ? 'selected' : ''?>>SSL (Port 993)</option>
                        <option value="tls" <?=$iEnc === 'tls' ? 'selected' : ''?>>TLS (Port 143)</option>
                        <option value="none" <?=$iEnc === 'none' ? 'selected' : ''?>>None</option>
                    </select>
                </label>
                <label>IMAP Username
                    <input name="imap_username" type="text" value="<?=e(setting('imap_username', setting('mail_username', '')) )?>" placeholder="user@example.com">
                </label>
            </div>

            <label>IMAP Password
                <input name="imap_password" type="password" placeholder="<?=setting('imap_password') ? '•••••••• (leave blank to keep current password)' : 'Enter IMAP password'?>">
            </label>

            <button class="button button-admin">Save IMAP settings</button>
        </form>
    </section>

    <!-- SQL Database Info -->
    <section class="settings-card">
        <div class="settings-heading">
            <span class="settings-icon blue">⌘</span>
            <div>
                <h2>SQL Database settings</h2>
                <p>Connection credentials are set by the server environment variables.</p>
            </div>
        </div>
        <div class="db-env-list">
            <div><span>DB_HOST</span><strong><?=e(getenv('DB_HOST') ?: '127.0.0.1')?> · <?=e(getenv('DB_PORT') ?: '3306')?></strong></div>
            <div><span>DB_NAME</span><strong><?=e(getenv('DB_NAME') ?: 'print_portal')?></strong></div>
            <div><span>DB_USER</span><strong><?=e(getenv('DB_USER') ?: 'root')?></strong></div>
            <div><span>DB_PASS</span><strong>•••••••• (stored in environment)</strong></div>
        </div>
    </section>
</div>
<?php render_footer(); ?>
