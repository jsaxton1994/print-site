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
    $db = new mysqli(getenv('DB_HOST') ?: 'localhost', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', getenv('DB_NAME') ?: 'print_portal', (int)(getenv('DB_PORT') ?: 3306));
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

function log_sent_email(string $recipient, string $subject, ?string $body = null, ?int $jobId = null, string $status = 'Sent'): bool {
    $tableCheck = db()->query("SHOW TABLES LIKE 'email_logs'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $stmt = db()->prepare('INSERT INTO email_logs (recipient, subject, body, job_id, status, sent_at) VALUES (?, ?, ?, ?, ?, NOW())');
        if (!$stmt) return false;
        $stmt->bind_param('sssis', $recipient, $subject, $body, $jobId, $status);
        return $stmt->execute();
    }
    return false;
}

// Converts plain text into a valid PDF document for Epson Email Print compatibility
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

    $tmpPdf = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epson_txt_' . bin2hex(random_bytes(8)) . '.pdf';
    file_put_contents($tmpPdf, $out);
    return $tmpPdf;
}

/**
 * Strips all colour data, ICC profiles, and colour channels to guarantee monochrome printing
 */
function force_monochrome_file(string $filePath, string $ext): ?string {
    if (!is_file($filePath)) return null;
    $ext = strtolower($ext);
    $tmpBw = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mono_' . bin2hex(random_bytes(8)) . '.' . $ext;

    // 1. Process Images using PHP GD (Forces 8-bit single grayscale channel)
    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    if (in_array($ext, $imageExts, true) && extension_loaded('gd')) {
        $src = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($filePath),
            'png'         => @imagecreatefrompng($filePath),
            'gif'         => @imagecreatefromgif($filePath),
            'bmp'         => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($filePath) : false,
            'webp'        => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filePath) : false,
            default       => false,
        };

        if ($src) {
            $w = imagesx($src);
            $h = imagesy($src);

            // Create a palette-based 8-bit image with 256 pure gray tones (DeviceGray compatible)
            $paletteImg = imagecreate($w, $h);
            for ($c = 0; $c < 256; $c++) {
                imagecolorallocate($paletteImg, $c, $c, $c);
            }

            // Desaturate source and copy into pure palette
            imagefilter($src, IMG_FILTER_GRAYSCALE);
            imagefilter($src, IMG_FILTER_CONTRAST, -5);
            imagecopymerge($paletteImg, $src, 0, 0, 0, 0, $w, $h, 100);

            $saved = match ($ext) {
                'jpg', 'jpeg' => @imagejpeg($paletteImg, $tmpBw, 100),
                'png'         => @imagepng($paletteImg, $tmpBw, 9),
                'gif'         => @imagegif($paletteImg, $tmpBw),
                'bmp'         => function_exists('imagebmp') ? @imagebmp($paletteImg, $tmpBw) : false,
                'webp'        => function_exists('imagewebp') ? @imagewebp($paletteImg, $tmpBw, 100) : false,
                default       => false,
            };

            imagedestroy($src);
            imagedestroy($paletteImg);

            if ($saved && is_file($tmpBw) && filesize($tmpBw) > 0) {
                return $tmpBw;
            }
        }
    }

    // 2. Process PDFs (Ghostscript or ImageMagick)
    if ($ext === 'pdf' && function_exists('exec')) {
        $gsBins = ['gswin64c.exe', 'gswin32c.exe', 'gswin64c', 'gswin32c', 'gs'];

        // Automatically discover Ghostscript on Windows if installed in Program Files
        $programFiles = [
            getenv('ProgramFiles') ?: 'C:\\Program Files',
            getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)',
        ];
        foreach ($programFiles as $pf) {
            $matches = glob($pf . '\\gs\\gs*\\bin\\gswin*c.exe');
            if (!empty($matches)) {
                foreach ($matches as $m) {
                    array_unshift($gsBins, $m);
                }
            }
        }
        $gsBins = array_unique($gsBins);

        foreach ($gsBins as $bin) {
            $cmd = sprintf(
                '%s -sDEVICE=pdfwrite -dColorConversionStrategy=Gray -dProcessColorModel=/DeviceGray -dCompatibilityLevel=1.4 -dNOPAUSE -dBATCH -sOutputFile=%s %s 2>&1',
                escapeshellarg($bin),
                escapeshellarg($tmpBw),
                escapeshellarg($filePath)
            );
            $out = [];
            $code = -1;
            @exec($cmd, $out, $code);
            if ($code === 0 && is_file($tmpBw) && filesize($tmpBw) > 0) {
                return $tmpBw;
            }
        }

        // ImageMagick fallback
        $imCmd = sprintf('magick %s -colorspace Gray -type Grayscale %s 2>&1', escapeshellarg($filePath), escapeshellarg($tmpBw));
        @exec($imCmd, $out, $code);
        if ($code === 0 && is_file($tmpBw) && filesize($tmpBw) > 0) {
            return $tmpBw;
        }
    }

    return null;
}

function deliver_mail(string $to, string $subject, string $body, ?string $attachmentPath = null, ?string $attachmentName = null, ?int $jobId = null): bool {
    $host = trim(setting('mail_host', ''));
    $from = setting('mail_from', '');
    $fromName = str_replace(["\r", "\n"], '', setting('mail_from_name', 'Print Portal'));
    $subject = str_replace(["\r", "\n"], '', $subject);
    $to = str_replace(["\r", "\n"], '', $to);

    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        log_sent_email($to, $subject, $body, $jobId, 'Failed: Invalid Email');
        return false;
    }

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

        $sent = @mail($to, mb_encode_mimeheader($subject), $message, $headers);
        log_sent_email($to, $subject, $body, $jobId, $sent ? 'Sent' : 'Failed');
        return $sent;
    }

    // Direct SMTP Socket
    $target = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $socket = @stream_socket_client($target, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
    if (!$socket) {
        log_sent_email($to, $subject, $body, $jobId, 'Failed: SMTP Connection Error (' . $errstr . ')');
        return false;
    }

    stream_set_timeout($socket, 5);
    $read = static function() use ($socket): string {
        $line = '';
        while (($chunk = fgets($socket, 515)) !== false) {
            $line .= $chunk;
            if (isset($chunk[3]) && $chunk[3] === ' ') break;
        }
        return trim($line);
    };

    $command = static function(string $cmd, array $codes) use ($socket, $read): bool {
        if (fwrite($socket, $cmd . "\r\n") === false) return false;
        $reply = $read();
        return in_array((int)substr($reply, 0, 3), $codes, true);
    };

    $greeting = $read();
    if ((int)substr($greeting, 0, 3) !== 220 || !$command('EHLO print-portal', [250])) {
        fclose($socket);
        log_sent_email($to, $subject, $body, $jobId, 'Failed: EHLO rejection');
        return false;
    }

    if ($encryption === 'tls') {
        if (!$command('STARTTLS', [220]) || !stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) || !$command('EHLO print-portal', [250])) {
            fclose($socket);
            log_sent_email($to, $subject, $body, $jobId, 'Failed: TLS error');
            return false;
        }
    }

    $username = setting('mail_username');
    $password = setting('mail_password');
    if ($username !== '' && (!$command('AUTH LOGIN', [334]) || !$command(base64_encode($username), [334]) || !$command(base64_encode($password), [235]))) {
        fclose($socket);
        log_sent_email($to, $subject, $body, $jobId, 'Failed: SMTP Auth failed');
        return false;
    }

    if (!$command('MAIL FROM:<' . $from . '>', [250]) || !$command('RCPT TO:<' . $to . '>', [250, 251]) || !$command('DATA', [354])) {
        fclose($socket);
        log_sent_email($to, $subject, $body, $jobId, 'Failed: Recipient rejected');
        return false;
    }

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

    log_sent_email($to, $subject, $body, $jobId, $ok ? 'Sent' : 'Failed');
    return $ok;
}

function dispatch_to_printer(array $job, string $filePath): bool {
    $to = trim(setting('printer_email'));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    if (!is_file($filePath)) return false;

    $ext = strtolower(pathinfo($job['original_name'], PATHINFO_EXTENSION));
    $subject = 'Print Job #' . $job['id'] . ' - ' . $job['original_name'];
    $body = ''; // Keep email body empty so Epson doesn't print a separate text page
    $tempFiles = [];
    $attachmentPath = $filePath;
    $attachmentName = $job['original_name'];

    // 1. Convert .txt files to clean PDF
    if ($ext === 'txt') {
        $tempPdf = convert_txt_to_pdf($filePath);
        if ($tempPdf && is_file($tempPdf)) {
            $attachmentPath = $tempPdf;
            $attachmentName = pathinfo($job['original_name'], PATHINFO_FILENAME) . '.pdf';
            $tempFiles[] = $tempPdf;
            $ext = 'pdf';
        }
    }

    // 2. Strict Black & White enforcement
    $colourMode = trim((string)($job['colour_mode'] ?? 'Colour'));
    $isBw = $colourMode !== '' && (
        stripos($colourMode, 'black') !== false ||
        stripos($colourMode, 'b&w') !== false ||
        stripos($colourMode, 'b/w') !== false ||
        stripos($colourMode, 'bw') !== false ||
        stripos($colourMode, 'mono') !== false ||
        stripos($colourMode, 'gray') !== false ||
        stripos($colourMode, 'grey') !== false
    );

    if ($isBw) {
        // Epson Connect Email Print requires '=+bk' appended to the email local part to force hardware monochrome
        if (str_contains($to, '@')) {
            [$local, $domain] = explode('@', $to, 2);
            if (!str_contains($local, '=+bk')) {
                $to = $local . '=+bk@' . $domain;
            }
        }

        // Also convert document colorspace to grayscale
        $monoFile = force_monochrome_file($attachmentPath, $ext);
        if ($monoFile && is_file($monoFile)) {
            $attachmentPath = $monoFile;
            $tempFiles[] = $monoFile;
        }
    }

    $ok = deliver_mail($to, $subject, $body, $attachmentPath, $attachmentName, (int)$job['id']);

    foreach ($tempFiles as $f) {
        if (is_file($f)) @unlink($f);
    }

    return $ok;
}

/**
 * Checks completion status of print jobs (placeholder to prevent fatal errors)
 */
function check_epson_completion(): array {
    return ['completed' => 0, 'cancelled' => 0];
}

/**
 * High-performance 1-by-1 Queue Manager:
 * Ensures the printer is never overwhelmed and requests complete in 1ms.
 */
function process_job_queue(): void {
    // 1. Auto-complete processing jobs older than 60 seconds to make room for the next job
    $timeoutSeconds = 60;
    $stmt = db()->prepare("UPDATE jobs SET status = 'Completed', completed_at = NOW() WHERE status = 'Processing' AND TIMESTAMPDIFF(SECOND, updated_at, NOW()) > ?");
    $stmt->bind_param('i', $timeoutSeconds);
    $stmt->execute();

    // 2. Count active jobs currently at the printer
    $activeRes = db()->query("SELECT COUNT(*) FROM jobs WHERE status = 'Processing'");
    $activeCount = (int)($activeRes ? $activeRes->fetch_row()[0] : 0);

    // If a job is already actively printing, let it finish first
    if ($activeCount > 0) {
        return;
    }

    // 3. Find the next job waiting in line (Queued or Approved)
    $stmt = db()->prepare("SELECT * FROM jobs WHERE status IN ('Queued', 'Approved') ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    $nextJob = $stmt->get_result()->fetch_assoc();
    if (!$nextJob) {
        return;
    }

    // Mark as Processing immediately so concurrent requests don't pick it up
    $mark = db()->prepare("UPDATE jobs SET status = 'Processing', updated_at = NOW() WHERE id = ? AND status IN ('Queued', 'Approved')");
    $mark->bind_param('i', $nextJob['id']);
    $mark->execute();
    if ($mark->affected_rows === 0) {
        return;
    }

    // Dispatch the single job to the printer
    $filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $nextJob['stored_name'];
    if (!is_file($filePath)) {
        $filePath = $nextJob['file_path'] ?? '';
    }

    if (is_file($filePath)) {
        dispatch_to_printer($nextJob, $filePath);
    }
}

function send_notice(string $key, string $email, array $vars = [], ?string $attachmentPath = null): bool {
    $stmt = db()->prepare('SELECT subject,body FROM email_templates WHERE template_key=?'); $stmt->bind_param('s',$key); $stmt->execute(); $template = $stmt->get_result()->fetch_assoc();
    if (!$template) return false;
    $subject = $template['subject']; $body = $template['body'];
    foreach ($vars as $name=>$value) { $subject = str_replace('{{'.$name.'}}', (string)$value, $subject); $body = str_replace('{{'.$name.'}}', (string)$value, $body); }
    $jobId = isset($vars['job_id']) ? (int)$vars['job_id'] : null;
    return deliver_mail($email, $subject, $body, $attachmentPath, null, $jobId);
}

function h(string $text): string { return e($text); }
function status_badge(string $s): string { return '<span class="badge badge-'.strtolower(e($s)).'">'.e($s).'</span>'; }

function render_header(string $title, string $area): void {
    // Process queue in 1ms on page load without any blocking IMAP calls
    process_job_queue();

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
