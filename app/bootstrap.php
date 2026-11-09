<?php
declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true, 'samesite' => 'Lax',
]);
session_start();
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'UTC');

function db(): mysqli {
    static $db;
    if ($db instanceof mysqli) return $db;
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', getenv('DB_NAME') ?: 'print_portal', (int)(getenv('DB_PORT') ?: 3306));
    $db->set_charset('utf8mb4');
    return $db;
}
function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $to): string { return $to; }
function redirect(string $to): never { header('Location: '.$to); exit; }
function flash(string $type, string $message): void { $_SESSION['flash'] = [$type, $message]; }
function take_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function verify_csrf(): void { if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(419); exit('Your form session expired. Please go back and try again.'); } }
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(?string $role = null): array {
    $u = current_user();
    if (!$u) redirect($role && strtolower($role) === 'admin' ? '/admin/' : '/');
    if ($role && strtolower($u['role']) !== strtolower($role)) redirect(strtolower($u['role']) === 'admin' ? '/admin/' : '/client/');
    return $u;
}
function refresh_user(): void {
    if (!isset($_SESSION['user']['id'])) return;
    $stmt = db()->prepare('SELECT id,email,role,status FROM users WHERE id=?'); $stmt->bind_param('i', $_SESSION['user']['id']); $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    if (!$u || $u['status'] !== 'Active') { unset($_SESSION['user']); return; }
    $_SESSION['user'] = $u;
}
function setting(string $key, string $default = ''): string {
    $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?'); $stmt->bind_param('s',$key); $stmt->execute();
    return (string)($stmt->get_result()->fetch_column() ?: $default);
}
function set_setting(string $key, string $value): void {
    $stmt = db()->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'); $stmt->bind_param('ss',$key,$value); $stmt->execute();
}
function deliver_mail(string $to, string $subject, string $body, ?string $attachmentPath = null, ?string $attachmentName = null): bool {
    $host = trim(setting('mail_host', ''));
    $from = setting('mail_from', ''); $fromName = str_replace(["\r", "\n"], '', setting('mail_from_name', 'Print Portal'));
    $subject = str_replace(["\r", "\n"], '', $subject);
    $to = str_replace(["\r", "\n"], '', $to);
    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $port = (int)setting('mail_port', '25'); $encryption = setting('mail_encryption', 'none');
    if ($host === '') {
        $headers = 'From: '.mb_encode_mimeheader($fromName).' <'.$from.">\r\nMIME-Version: 1.0\r\n";
        if ($attachmentPath && is_file($attachmentPath)) {
            $boundary='=_'.bin2hex(random_bytes(12)); $name=str_replace(['\\r','\\n','"'], '', $attachmentName ?: basename($attachmentPath)); $mime=mime_content_type($attachmentPath)?:'application/octet-stream';
            $headers .= 'Content-Type: multipart/mixed; boundary="'.$boundary.'"';
            $message='--'.$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n".$body."\r\n--$boundary\r\nContent-Type: $mime; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$name\"; filename*=UTF-8''".rawurlencode($name)."\r\n\r\n".chunk_split(base64_encode((string)file_get_contents($attachmentPath)))."\r\n--$boundary--";
        } else { $headers .= 'Content-Type: text/plain; charset=UTF-8'; $message=$body; }
        return @mail($to, mb_encode_mimeheader($subject), $message, $headers);
    }
    if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) return false;
    $target = ($encryption === 'ssl' ? 'ssl://' : '').$host.':'.$port;
    $socket = @stream_socket_client($target, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]));
    if (!$socket) return false;
    stream_set_timeout($socket, 10);
    $read = static function() use ($socket): string { $line=''; do { $line=fgets($socket, 515); if ($line===false) return ''; } while (isset($line[3]) && $line[3]==='-'); return trim($line); };
    $command = static function(string $cmd, array $codes) use ($socket,$read): bool { if (fwrite($socket,$cmd."\r\n")===false) return false; $reply=$read(); return in_array((int)substr($reply,0,3),$codes,true); };
    $greeting=$read();
    if ((int)substr($greeting,0,3)!==220 || !$command('EHLO print-portal', [250])) { fclose($socket); return false; }
    if ($encryption === 'tls') {
        if (!$command('STARTTLS',[220]) || !stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT) || !$command('EHLO print-portal',[250])) { fclose($socket); return false; }
    }
    $username=setting('mail_username'); $password=setting('mail_password');
    if ($username !== '' && (!$command('AUTH LOGIN',[334]) || !$command(base64_encode($username),[334]) || !$command(base64_encode($password),[235]))) { fclose($socket); return false; }
    if (!$command('MAIL FROM:<'.$from.'>',[250]) || !$command('RCPT TO:<'.$to.'>',[250,251]) || !$command('DATA',[354])) { fclose($socket); return false; }
    $boundary='=_'.bin2hex(random_bytes(12)); $headers='From: '.mb_encode_mimeheader($fromName).' <'.$from.">\r\nTo: <$to>\r\nSubject: ".mb_encode_mimeheader($subject)."\r\nMIME-Version: 1.0\r\n";
    if ($attachmentPath && is_file($attachmentPath)) {
        $name=str_replace(['\\r','\\n','"'], '', $attachmentName ?: basename($attachmentPath)); $mime=mime_content_type($attachmentPath)?:'application/octet-stream';
        $message=$headers.'Content-Type: multipart/mixed; boundary="'.$boundary.'"'."\r\n\r\n--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n".$body."\r\n--$boundary\r\nContent-Type: $mime; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$name\"; filename*=UTF-8''".rawurlencode($name)."\r\n\r\n".chunk_split(base64_encode((string)file_get_contents($attachmentPath)))."\r\n--$boundary--\r\n";
    } else $message=$headers."Content-Type: text/plain; charset=UTF-8\r\n\r\n".$body;
    $message=preg_replace('/(^|\r\n)\\./','$1..',$message) ?? $message;
    $ok=fwrite($socket,$message."\r\n.\r\n")!==false && (int)substr($read(),0,3)===250;
    $command('QUIT',[221]); fclose($socket); return $ok;
}
function dispatch_to_printer(array $job, string $filePath): bool {
    $to=trim(setting('printer_email'));
    if ($to==='' || !filter_var($to,FILTER_VALIDATE_EMAIL)) return false;
    $subject='Print job #'.$job['id'].' | '.$job['paper_size'].' | '.$job['colour_mode'].' | '.$job['copies'].' copies';
    $body="Approved print request from ".$job['email'].".\n\nFile: ".$job['original_name']."\nPaper size: ".$job['paper_size']."\nColour: ".$job['colour_mode']."\nCopies: ".$job['copies']."\nJob ID: ".$job['id']."\n";
    return deliver_mail($to,$subject,$body,$filePath,$job['original_name']);
}
function send_notice(string $key, string $email, array $vars = [], ?string $attachmentPath = null): bool {
    $stmt = db()->prepare('SELECT subject,body FROM email_templates WHERE template_key=?'); $stmt->bind_param('s',$key); $stmt->execute(); $template = $stmt->get_result()->fetch_assoc();
    if (!$template) return false;
    $subject = $template['subject']; $body = $template['body'];
    foreach ($vars as $name=>$value) { $subject = str_replace('{{'.$name.'}}', (string)$value, $subject); $body = str_replace('{{'.$name.'}}', (string)$value, $body); }
    $sent = deliver_mail($email, $subject, $body, $attachmentPath);
    $log = db()->prepare('INSERT INTO email_log(recipient,template_key,subject,sent_at,success) VALUES(?,?,?,NOW(),?)'); $log->bind_param('sssi',$email,$key,$subject,$sent); $log->execute();
    return $sent;
}
function h(string $text): string { return e($text); }
function status_badge(string $s): string { return '<span class="badge badge-'.strtolower(e($s)).'">'.e($s).'</span>'; }
function render_header(string $title, string $area): void {
    $admin = $area === 'admin'; $brand = $admin ? 'Control room' : 'Print portal'; $user = current_user();
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> · <?=e($brand)?></title><link rel="stylesheet" href="/<?= $admin?'admin':'client' ?>/public/css/app.css"><script defer src="/<?= $admin?'admin':'client' ?>/public/js/app.js"></script></head><body class="<?= $admin?'admin':'client' ?>">
    <?php if ($user): ?><aside class="sidebar"><a class="brand" href="/<?= $admin?'admin/':'client/' ?>"><span class="brand-mark">P</span><span><?=e($brand)?><small>DOCUMENT SERVICES</small></span></a><nav>
    <?php if($admin): ?>
      <a href="/admin/" class="<?=str_contains($_SERVER['REQUEST_URI'],'dashboard')||$_SERVER['REQUEST_URI']==='/admin/'?'active':''?>"><span>◫</span>Dashboard</a><div class="nav-rule"></div>
      <a href="/admin/jobs.php"><span>▤</span>Jobs Management</a><a href="/admin/users.php"><span>♙</span>Users Management</a><a href="/admin/emails.php"><span>✉</span>Emails Management</a><a href="/admin/settings.php"><span>⚙</span>Settings Management</a><div class="nav-rule"></div><a href="/admin/password.php"><span>⌑</span>Change Password</a>
    <?php else: ?>
      <a href="/client/" class="<?= $_SERVER['REQUEST_URI']==='/client/'?'active':'' ?>"><span>◫</span>Dashboard</a><div class="nav-rule"></div><a href="/client/jobs.php"><span>▤</span>Job Management</a><div class="nav-rule"></div><a href="/client/password.php"><span>⌑</span>Change Password</a>
    <?php endif; ?></nav><div class="sidebar-bottom"><div class="avatar"><?=e(strtoupper(substr($user['email'],0,1)))?></div><div class="user-meta"><strong><?=e($user['email'])?></strong><small><?=e($user['role'])?> account</small></div><a class="logout" href="/<?= $admin?'admin':'client' ?>/logout.php" title="Sign out">↗</a></div></aside>
    <?php endif; ?><main class="main <?= $user?'with-sidebar':'' ?>"><header class="topbar"><div class="crumb"><?=e($area==='admin'?'ADMINISTRATION':'WORKSPACE')?> <span>/</span> <?=e(strtoupper($title))?></div><?php if($user): ?><div class="top-user"><span class="status-dot"></span> <?=e($user['email'])?></div><?php endif; ?></header><section class="content">
    <?php $f=take_flash(); if($f): ?><div class="alert alert-<?=e($f[0])?>" data-flash><?=e($f[1])?><button type="button" aria-label="Dismiss">×</button></div><?php endif; ?>
    <?php
}
function render_footer(): void { ?></section><footer class="footer">Print Portal <span>·</span> Secure document workflow</footer></main></body></html><?php }
function page_title(string $title, string $description = ''): void { ?><div class="page-heading"><div><div class="eyebrow">PRINT PORTAL</div><h1><?=e($title)?></h1><?php if($description): ?><p><?=e($description)?></p><?php endif; ?></div></div><?php }
function post_value(string $key, string $default=''): string { return trim((string)($_POST[$key] ?? $default)); }
function allowed_upload(array $file): bool { return ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && (int)$file['size'] > 0 && (int)$file['size'] <= 25*1024*1024; }
