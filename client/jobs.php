<?php
require __DIR__ . '/../app/bootstrap.php';
refresh_user();
$user = require_login('User');

$allowed = ['Pending', 'Processing', 'Completed', 'Cancelled'];
$filter  = $_GET['status'] ?? 'Pending';
if (!in_array($filter, $allowed, true)) {
    $filter = 'Pending';
}

// Fetch allowed paper sizes defined by admin
$allPossiblePaper = ['A4', 'A3', 'Letter', 'Legal', '4x6'];
$rawAllowed = setting('allowed_paper_sizes', 'A4,A3,Letter,Legal,4x6');
$paperOptions = array_values(array_intersect(array_filter(array_map('trim', explode(',', $rawAllowed))), $allPossiblePaper));
if (empty($paperOptions)) {
    $paperOptions = [setting('paper_size', 'A4')];
}

$colourOptions = ['Colour', 'Black & White'];
$allowClientSettings = setting('allow_client_settings', '1') === '1';
$maxAttachmentsLimit = max(1, (int)setting('max_attachments_limit', '10'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (isset($_POST['upload_jobs'])) {
        $files = $_FILES['documents'] ?? null;
        $names = $files['name'] ?? [];
        $count = count($names);

        if ((isset($files['error']) && in_array(UPLOAD_ERR_INI_SIZE, $files['error'], true)) || (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > (400 * 1024 * 1024)) {
            flash('error', 'The upload is larger than the server limit. Submit fewer or smaller files.');
            redirect('/client/jobs.php#new-job');
        }

        if (!$count || $count > $maxAttachmentsLimit) {
            flash('error', "Choose between 1 and {$maxAttachmentsLimit} file" . ($maxAttachmentsLimit === 1 ? '' : 's') . ' per submission.');
            redirect('/client/jobs.php#new-job');
        }

        // Determine settings based on admin allowed options
        if ($allowClientSettings && in_array(post_value('paper_size'), $paperOptions, true)) {
            $paper = post_value('paper_size');
        } else {
            $defaultPaper = setting('paper_size', 'A4');
            $paper = in_array($defaultPaper, $paperOptions, true) ? $defaultPaper : $paperOptions[0];
        }

        if ($allowClientSettings && in_array(post_value('colour_mode'), $colourOptions, true)) {
            $colour = post_value('colour_mode');
        } else {
            $colour = setting('colour_options', 'Colour');
        }

        $copies = 1;
        $notes  = substr((string)post_value('notes'), 0, 500);

        // Ensure storage directory exists
        $uploadDir = __DIR__ . '/../storage/uploads';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $extensions = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'tif', 'tiff', 'bmp', 'doc', 'docx', 'odt', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
        $mimeMap = [
            'pdf'  => 'application/pdf',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'tif'  => 'image/tiff',
            'tiff' => 'image/tiff',
            'bmp'  => 'image/bmp',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'odt'  => 'application/vnd.oasis.opendocument.text',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt'  => 'text/plain'
        ];

        $added = 0;

        for ($i = 0; $i < $count; $i++) {
            $file = [
                'error'    => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $files['size'][$i] ?? 0,
                'tmp_name' => $files['tmp_name'][$i] ?? ''
            ];

            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] <= 0) {
                continue;
            }

            if (function_exists('allowed_upload') && !allowed_upload($file)) {
                continue;
            }

            $original = basename((string)$names[$i]);
            $ext      = strtolower(pathinfo($original, PATHINFO_EXTENSION));

            if (!in_array($ext, $extensions, true)) {
                continue;
            }

            $detected = '';
            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $detected = $finfo->file($file['tmp_name']) ?: '';
            } elseif (function_exists('mime_content_type')) {
                $detected = mime_content_type($file['tmp_name']) ?: '';
            }

            $mime = $detected ?: ($mimeMap[$ext] ?? 'application/octet-stream');
            $stored = bin2hex(random_bytes(20)) . '.' . $ext;
            $destination = $uploadDir . '/' . $stored;

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                continue;
            }

            $size   = (int)$file['size'];
            $status = 'Pending';

            $stmt = db()->prepare('INSERT INTO jobs (user_id, status, original_name, stored_name, mime_type, file_size, copies, paper_size, colour_mode, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            if ($stmt) {
                $stmt->bind_param('issssiisss', $user['id'], $status, $original, $stored, $mime, $size, $copies, $paper, $colour, $notes);
                if ($stmt->execute()) {
                    $id = db()->insert_id;
                    $added++;

                    try {
                        if (function_exists('send_notice')) {
                            send_notice('job_received', $user['email'], ['email' => $user['email'], 'job_id' => $id, 'filename' => $original]);
                        }
                    } catch (\Throwable $t) {
                    }
                }
            }
        }

        if ($added) {
            flash('success', "{$added} print job" . ($added === 1 ? ' was' : 's were') . ' submitted. Each file has its own job.');
            redirect('/client/index.php');
        } else {
            flash('error', 'No files were accepted. Use supported file types up to 25 MB each.');
            redirect('/client/jobs.php#new-job');
        }
    }
}

// Pagination for client jobs (15 per page)
$perPage = 15;
$page = max(1, (int)($_GET['page'] ?? 1));

$stmtCount = db()->prepare('SELECT COUNT(*) FROM jobs WHERE user_id = ? AND status = ?');
$stmtCount->bind_param('is', $user['id'], $filter);
$stmtCount->execute();
$totalJobs = (int)$stmtCount->get_result()->fetch_column();
$totalPages = max(1, (int)ceil($totalJobs / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$sql  = 'SELECT * FROM jobs WHERE user_id = ? AND status = ? ORDER BY created_at DESC LIMIT ? OFFSET ?';
$stmt = db()->prepare($sql);
$stmt->bind_param('isii', $user['id'], $filter, $perPage, $offset);
$stmt->execute();
$jobs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

render_header('Job Management', 'client');
page_title('Job Management', 'Your files, from submission through to collection.');
?>

<div class="job-actions">
    <a class="button button-primary" href="#new-job">＋ Submit new job</a>
</div>

<div class="filter-tabs">
    <?php foreach ($allowed as $s): ?>
        <a class="filter-tab <?= $s === $filter ? 'selected' : '' ?>" href="/client/jobs.php?status=<?= e($s) ?>"><?= e($s) ?></a>
    <?php endforeach; ?>
</div>

<div class="table-card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Document</th>
                    <th>Submitted</th>
                    <th>Settings</th>
                    <th>Status</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$jobs): ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <span>▤</span>
                                <strong>No <?= e(strtolower($filter)) ?> jobs</strong>
                                <small>
                                    <?php if ($filter === 'Pending'): ?>
                                        Submit a document to start a new print request.
                                    <?php else: ?>
                                        Jobs in this state will show up here.
                                    <?php endif; ?>
                                </small>
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($jobs as $j): ?>
                    <tr>
                        <td>
                            <div class="file-cell">
                                <span class="file-icon">▤</span>
                                <span>
                                    <strong><?= e($j['original_name']) ?></strong>
                                    <small>Job #<?= str_pad((string)$j['id'], 5, '0', STR_PAD_LEFT) ?> · <?= number_format($j['file_size'] / 1024, 0) ?> KB</small>
                                </span>
                            </div>
                        </td>
                        <td><?= e(date('M j, Y · g:i a', strtotime($j['created_at']))) ?></td>
                        <td><?= e($j['paper_size']) ?> · <?= e($j['colour_mode']) ?></td>
                        <td><?= status_badge($j['status']) ?></td>
                        <td><?= e($j['admin_note'] ?: $j['notes'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div style="display:flex; justify-content:center; align-items:center; gap:6px; padding:1.25rem; border-top:1px solid var(--border, rgba(0,0,0,0.08)); flex-wrap:wrap;">
            <?php 
                $buildPageUrl = function($targetPage) use ($filter) {
                    return '/client/jobs.php?status=' . urlencode($filter) . '&page=' . $targetPage;
                };
            ?>

            <?php if ($page > 1): ?>
                <a class="button button-small button-soft" href="<?= e($buildPageUrl($page - 1)) ?>">&laquo; Prev</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php if ($i === 1 || $i === $totalPages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                    <a class="button button-small <?= $i === $page ? 'button-primary' : 'button-soft' ?>" href="<?= e($buildPageUrl($i)) ?>">
                        <?= $i ?>
                    </a>
                <?php elseif ($i === $page - 3 || $i === $page + 3): ?>
                    <span style="padding:0 4px; color:var(--muted, #64748b);">&hellip;</span>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a class="button button-small button-soft" href="<?= e($buildPageUrl($page + 1)) ?>">Next &raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- New Job Modal -->
<div class="modal-backdrop" id="new-job">
    <div class="modal-card">
        <a class="modal-close" href="/client/jobs.php">×</a>
        <div class="eyebrow">NEW REQUEST</div>
        <h2>Send to print</h2>
        <p class="muted">Upload up to <?= (int)$maxAttachmentsLimit ?> file<?= $maxAttachmentsLimit === 1 ? '' : 's' ?>. Each file becomes an individual job.</p>

        <form method="post" enctype="multipart/form-data" class="form-stack">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="upload_jobs" value="1">

            <label class="upload-zone">
                <span class="upload-glyph">↑</span>
                <strong>Choose documents to upload</strong>
                <small>PDF, images, Office · Max <?= (int)$maxAttachmentsLimit ?> files (25 MB max per file)</small>
                <input type="file" name="documents[]" id="documents" multiple required onchange="if(this.files.length > <?= (int)$maxAttachmentsLimit ?>){ alert('You can only upload a maximum of <?= (int)$maxAttachmentsLimit ?> files per submission.'); this.value=''; }">
            </label>

            <?php if ($allowClientSettings): ?>
                <div class="field-row">
                    <label>Paper size
                        <select name="paper_size">
                            <?php foreach ($paperOptions as $p): ?>
                                <option value="<?= e($p) ?>" <?= $p === setting('paper_size', 'A4') ? 'selected' : '' ?>><?= e($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>Colour mode
                        <select name="colour_mode">
                            <?php foreach ($colourOptions as $c): ?>
                                <option value="<?= e($c) ?>" <?= $c === setting('colour_options', 'Colour') ? 'selected' : '' ?>><?= e($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
            <?php else: ?>
                <div class="setting-preview">
                    <small>DEFAULT PRINT SETTINGS</small>
                    <strong><?= e(setting('paper_size', 'A4')) ?> · <?= e(setting('colour_options', 'Colour')) ?></strong>
                </div>
            <?php endif; ?>

            <label>Instructions <span class="optional">Optional</span>
                <textarea name="notes" rows="2" maxlength="500" placeholder="Any details for the print room..."></textarea>
            </label>

            <button class="button button-primary button-wide">Submit print job <span>→</span></button>
        </form>
    </div>
</div>

<?php render_footer(); ?>
