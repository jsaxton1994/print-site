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

// Converts plain text to a valid PDF document on the fly for Epson Email Print compatibility
function convert_txt_to_pdf(string $txtPath): ?string {
    if (!is_file($txtPath)) return null;
    $text = (string)file_get_contents($txtPath);
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
    $pages = array_chunk($lines, 55);
    if (empty($pages)) $pages = [['']];

    $objs = [];
    $pageObjIds = [];
    $fontId = 3;
    $objs[$fontId] = "<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>";

    $currId = 4;
    foreach ($pages as $pLines) {
        $stream = "BT\n/F1 10 Tf\n12 TL\n40 780 Td\n";
        foreach ($pLines as $l) {
            $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], substr($l, 0, 95));
            $safe = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $safe);
            $stream .= "(" . $safe . ") '\n";
        }
        $stream .= "ET";

        $contentId = $currId++;
        $objs[$contentId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";

        $pageId = $currId++;
        $pageObjIds[] = $pageId;
        $objs[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents $contentId 0 R /Resources << /Font << /F1 $fontId 0 R >> >> >>";
    }

    $kids = implode(" ", array_map(fn($id) => "$id 0 R", $pageObjIds));
    $objs[2] = "<< /Type /Pages /Kids [ $kids ] /Count " . count($pageObjIds) . " >>";
    $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";

    ksort($objs);
    $out = "%PDF-1.4\n";
    $offsets = [0 => 0];
    foreach ($objs as $id => $val) {
        $offsets[$id] = strlen($out);
        $out .= "$id 0 obj\n$val\nendobj\n";
    }
    $xref = strlen($out);
    $out .= "xref\n0 " . (max(array_keys($objs)) + 1) . "\n";
    $out .= "0000000000 65535 f \n";
    for ($i = 1; $i <= max(array_keys($objs)); $i++) {
        $out .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
    }
    $out .= "trailer\n<< /Size " . (max(array_keys($objs)) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";

    $tmpPdf = sys_get_temp_dir() . '/epson_job_' . bin2hex(random_bytes(8)) . '.pdf';
    file_put_contents($tmpPdf, $out);
    return $tmpPdf;
}

function deliver_mail(string $to, string $subject, string $body, ?string $attachmentPath = null, ?string $attachmentName = null): bool {
    $host = trim(setting('mail_host', ''));
    $from = setting('mail_from', '');
    $fromName = str_replace(["\r", "\n"], '', setting('mail_from_name', 'Print Portal'));
    $subject = str_replace(["\r", "\n"], '', $subject);
    $to = str_replace(["\r", "\n"], '', $to);

    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $port = (int)setting('mail_port', '25');
    $encryption = setting('mail_encryption', 'none');
    $hasBody = trim($body) !== '';

    // Standard PHP mail() fallback
    if ($host === '') {
        $headers = 'From: ' . mb_encode_mimeheader($fromName) . ' <' . $from . ">\r\nMIME-Version: 1.0\r\n";
        if ($attachmentPath && is_file($attachmentPath)) {
            $boundary = '=_' . bin2hex(random_bytes(12));
            $name = str_replace(["\r", "\n", '"'], '', $attachmentName ?: basename($attachmentPath));
            $mime = mime_content_type($attachmentPath) ?: 'application/octet-stream';
            $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . "\"\r\n";

            $message = '';
            if ($hasBody) {
                $message .= '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $body . "\r\n";
            }
            $message .= '--' . $boundary . "\r\nContent-Type: " . $mime . '; name="' . $name . "\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . $name . "\"; filename*=UTF-8''" . rawurlencode($name) . "\r\n\r\n" . chunk_split(base64_encode((string)file_get_contents($attachmentPath))) . "\r\n--" . $boundary . "--\r\n";
        } else {
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $message = $body;
        }
        return @mail($to, mb_encode_mimeheader($subject), $message, $headers);
    }

    // Authenticated SMTP socket delivery
    if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) return false;
    $target = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $socket = @stream_socket_client($target, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]));
    if (!$socket) return false;

    stream_set_timeout($socket, 10);
    $read = static function() use ($socket): string {
        $line = '';
        do {
            $line = fgets($socket, 515);
            if ($line === false) return '';
        } while (isset($line[3]) && $line[3] === '-');
        return trim($line);
    };

    $command = static function(string $cmd, array $codes) use ($socket, $read): bool {
        if (fwrite($socket, $cmd . "\r\n") === false) return false;
        $reply = $read();
        return in_array((int)substr($reply, 0, 3), $codes, true);
    };

    $greeting = $read();
    if ((int)substr($greeting, 0, 3) !== 220 || !$command('EHLO print-portal', [250])) { fclose($socket); return false; }

    if ($encryption === 'tls') {
        if (!$command('STARTTLS', [220]) || !stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) || !$command('EHLO print-portal', [250])) { fclose($socket); return false; }
    }

    $username = setting('mail_username');
    $password = setting('mail_password');
    if ($username !== '' && (!$command('AUTH LOGIN', [334]) || !$command(base64_encode($username), [334]) || !$command(base64_encode($password), [235]))) { fclose($socket); return false; }

    if (!$command('MAIL FROM:<' . $from . '>', [250]) || !$command('RCPT TO:<' . $to . '>', [250, 251]) || !$command('DATA', [354])) { fclose($socket); return false; }

    $headers = 'From: ' . mb_encode_mimeheader($fromName) . ' <' . $from . ">\r\nTo: <" . $to . ">\r\nSubject: " . mb_encode_mimeheader($subject) . "\r\nMIME-Version: 1.0\r\n";

    if ($attachmentPath && is_file($attachmentPath)) {
        $boundary = '=_' . bin2hex(random_bytes(12));
        $name = str_replace(["\r", "\n", '"'], '', $attachmentName ?: basename($attachmentPath));
        $mime = mime_content_type($attachmentPath) ?: 'application/octet-stream';
        $message = $headers . 'Content-Type: multipart/mixed; boundary="' . $boundary . "\"\r\n\r\n";

        if ($hasBody) {
            $message .= '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $body . "\r\n";
        }
        $message .= '--' . $boundary . "\r\nContent-Type: " . $mime . '; name="' . $name . "\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . $name . "\"; filename*=UTF-8''" . rawurlencode($name) . "\r\n\r\n" . chunk_split(base64_encode((string)file_get_contents($attachmentPath))) . "\r\n--" . $boundary . "--\r\n";
    } else {
        $message = $headers . "Content-Type: text/plain; charset=UTF-8\r\n\r\n" . $body;
    }

    $message = preg_replace('/(^|\r\n)\\./', '$1..', $message) ?? $message;
    $ok = fwrite($socket, $message . "\r\n.\r\n") !== false && (int)substr($read(), 0, 3) === 250;
    $command('QUIT', [221]);
    fclose($socket);
    return $ok;
}

// Dispatches attachment to printer. For .txt files, converts to PDF and includes body text so Epson accepts it.
function dispatch_to_printer(array $job, string $filePath): bool {
    $to = trim(setting('printer_email'));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    if (!is_file($filePath)) return false;

    $ext = strtolower(pathinfo($job['original_name'], PATHINFO_EXTENSION));
    $subject = 'Print Job #' . $job['id'] . ' - ' . $job['original_name'];
    $body = '';
    $tempPdf = null;
    $attachmentPath = $filePath;
    $attachmentName = $job['original_name'];

    // Epson Email Print rejects .txt attachments unless placed in body or sent as PDF
    if ($ext === 'txt') {
        $tempPdf = convert_txt_to_pdf($filePath);
        if ($tempPdf && is_file($tempPdf)) {
            $attachmentPath = $tempPdf;
            $attachmentName = pathinfo($job['original_name'], PATHINFO_FILENAME) . '.pdf';
        }
        $body = (string)file_get_contents($filePath);
    }

    $ok = deliver_mail($to, $subject, $body, $attachmentPath, $attachmentName);

    if ($tempPdf && is_file($tempPdf)) {
        @unlink($tempPdf);
    }

    return $ok;
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

// Polls mailbox for Epson status notices. Completes on success, or cancels automatically on error.
function check_epson_completion(): array {
    $results = ['completed' => 0, 'cancelled' => 0];
    $res = db()->query("SELECT j.*, u.email as user_email FROM jobs j JOIN users u ON u.id = j.user_id WHERE j.status = 'Processing'");
    $processingJobs = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    if (empty($processingJobs)) return $results;

    $imapHost = trim(setting('imap_host', setting('mail_host', '')));
    $imapPort = (int)setting('imap_port', '993');
    $imapUser = trim(setting('imap_username', setting('mail_username', '')));
    $imapPass = setting('imap_password', setting('mail_password', ''));
    $imapEnc  = setting('imap_encryption', 'ssl');

    if ($imapHost === '' || $imapUser === '' || $imapPass === '') return $results;

    if (function_exists('imap_open')) {
        $flag = $imapEnc === 'ssl' ? '/imap/ssl/novalidate-cert' : ($imapEnc === 'tls' ? '/imap/tls/novalidate-cert' : '/imap/notls');
        $mbox = @imap_open('{' . $imapHost . ':' . $imapPort . $flag . '}INBOX', $imapUser, $imapPass);
        if (!$mbox) return $results;

        $unseen = @imap_search($mbox, 'UNSEEN');
        if ($unseen) {
            foreach ($unseen as $msgNum) {
                $header = @imap_headerinfo($mbox, $msgNum);
                $from = (string)($header->fromaddress ?? '');
                $subject = (string)($header->subject ?? '');
                $body = (string)@imap_body($mbox, $msgNum);
                $fullText = $subject . ' ' . $body;

                $isEpson = stripos($from, 'epson') !== false 
                        || stripos($subject, 'epson') !== false 
                        || stripos($subject, 'print') !== false 
                        || stripos($body, 'epson') !== false;

                $isError = stripos($subject, 'error') !== false
                        || stripos($body, 'error') !== false
                        || stripos($fullText, 'could not be printed') !== false
                        || stripos($fullText, 'no printable data') !== false
                        || stripos($fullText, 'failed to print') !== false
                        || stripos($fullText, 'printing stopped') !== false;

                $isDone  = stripos($subject, 'complete') !== false 
                        || stripos($subject, 'printed') !== false 
                        || stripos($body, 'complete') !== false 
                        || stripos($body, 'printed successfully') !== false;

                if ($isEpson && ($isError || $isDone)) {
                    foreach ($processingJobs as $job) {
                        $matchId = stripos($subject, '#' . $job['id']) !== false 
                                || stripos($body, '#' . $job['id']) !== false 
                                || stripos($body, 'Job ' . $job['id']) !== false;
                        $matchName = stripos($subject, $job['original_name']) !== false 
                                  || stripos($body, $job['original_name']) !== false;

                        if ($matchId || $matchName) {
                            if ($isError) {
                                // Extract error description
                                $errDetail = 'Printer error';
                                if (preg_match('/Error\s*\([^)]+\)/i', $fullText, $m)) {
                                    $errDetail = $m[0];
                                } elseif (stripos($fullText, 'no printable data') !== false) {
                                    $errDetail = 'Error (No printable data was sent)';
                                } elseif (stripos($fullText, 'could not be printed') !== false) {
                                    $errDetail = 'Error (Some or all files could not be printed)';
                                }

                                $cancelNote = 'Automatically cancelled by printer: ' . $errDetail;
                                $stmt = db()->prepare("UPDATE jobs SET status = 'Cancelled', admin_note = ? WHERE id = ? AND status = 'Processing'");
                                $stmt->bind_param('si', $cancelNote, $job['id']);
                                $stmt->execute();

                                if ($stmt->affected_rows > 0) {
                                    send_notice('job_cancellation', $job['user_email'], [
                                        'email'    => $job['user_email'],
                                        'job_id'   => $job['id'],
                                        'filename' => $job['original_name'],
                                        'note'     => $cancelNote
                                    ]);
                                    $results['cancelled']++;
                                }
                            } else {
                                // Completed
                                $stmt = db()->prepare("UPDATE jobs SET status = 'Completed', completed_at = NOW(), admin_note = 'Completed automatically by Epson printer' WHERE id = ? AND status = 'Processing'");
                                $stmt->bind_param('i', $job['id']);
                                $stmt->execute();

                                if ($stmt->affected_rows > 0) {
                                    send_notice('job_completed', $job['user_email'], [
                                        'email'    => $job['user_email'],
                                        'job_id'   => $job['id'],
                                        'filename' => $job['original_name']
                                    ]);
                                    $results['completed']++;
                                }
                            }

                            @imap_setflag_full($mbox, (string)$msgNum, "\\Seen");
                            break;
                        }
                    }
                }
            }
        }
        @imap_close($mbox);
    }

    return $results;
}

function auto_check_epson(): void {
    if (!current_user() || current_user()['role'] !== 'Admin') return;
    $last = (int)($_SESSION['last_epson_check'] ?? 0);
    if (time() - $last < 20) return;
    $_SESSION['last_epson_check'] = time();
    check_epson_completion();
}

function h(string $text): string { return e($text); }
function status_badge(string $s): string { return '<span class="badge badge-'.strtolower(e($s)).'">'.e($s).'</span>'; }
function render_header(string $title, string $area): void {
    auto_check_epson();
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
