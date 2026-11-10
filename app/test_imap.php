<?php
require_once __DIR__ . '/bootstrap.php';

echo "<h2>IMAP Diagnostic Test</h2>";

if (!function_exists('imap_open')) {
    exit("<p style='color:red'><strong>Error:</strong> PHP IMAP extension is NOT installed or enabled on this server.</p>");
}

$imapHost = trim(setting('imap_host', setting('mail_host', '')));
$imapPort = (int)setting('imap_port', '993');
$imapUser = trim(setting('imap_username', setting('mail_username', '')));
$imapPass = setting('imap_password', setting('mail_password', ''));
$imapEnc  = setting('imap_encryption', 'ssl');

echo "<p>Connecting to: <code>{$imapHost}:{$imapPort}</code> as <code>{$imapUser}</code>...</p>";

$flag = $imapEnc === 'ssl' ? '/imap/ssl/novalidate-cert' : '/imap/notls';
$mbox = @imap_open('{' . $imapHost . ':' . $imapPort . $flag . '}INBOX', $imapUser, $imapPass);

if (!$mbox) {
    echo "<p style='color:red'><strong>Connection Failed:</strong> " . imap_last_error() . "</p>";
} else {
    echo "<p style='color:green'><strong>Connected successfully!</strong></p>";
    $check = imap_check($mbox);
    echo "<p>Total messages in INBOX: " . ($check->Nmsgs ?? 0) . "</p>";

    $results = check_epson_completion();
    echo "<p>Completed jobs updated: " . $results['completed'] . "</p>";
    echo "<p>Cancelled jobs updated: " . $results['cancelled'] . "</p>";
    imap_close($mbox);
}
