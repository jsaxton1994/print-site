<?php
require __DIR__ . '/../app/bootstrap.php';
refresh_user();
require_login('admin');

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT j.*, u.email FROM jobs j JOIN users u ON u.id = j.user_id WHERE j.id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();

if (!$job) {
    http_response_code(404);
    exit('Job not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_value('action');
    $note   = substr(post_value('admin_note'), 0, 500);
    $path   = __DIR__ . '/../storage/uploads/' . $job['stored_name'];

    if ($job['status'] === 'Pending' && $action === 'approve') {
        $dispatch = dispatch_to_printer($job, $path);
        if (!$dispatch) {
            flash('error', 'Printer email dispatch failed. Check Epson address and mail transport settings; the job remains pending.');
            redirect('/admin/job.php?id=' . $id);
        }
        try {
            $stmt = db()->prepare("UPDATE jobs SET status = 'Processing', admin_note = ? WHERE id = ? AND status = 'Pending'");
            $stmt->bind_param('si', $note, $id);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                flash('error', 'The job changed while it was being approved; check its current status.');
                redirect('/admin/job.php?id=' . $id);
            }
        } catch (Throwable $error) {
            flash('error', 'Printer accepted the email, but the job status could not be updated. Check job #' . $id . ' before retrying to avoid duplicate printing.');
            redirect('/admin/job.php?id=' . $id);
        }
        $noticeSent = send_notice('job_processing', $job['email'], ['email' => $job['email'], 'job_id' => $id, 'filename' => $job['original_name']]);
        flash($noticeSent ? 'success' : 'info', $noticeSent ? 'Job approved and sent to the Epson printer email address.' : 'Job was sent to the printer; the client notification email could not be sent.');
    } elseif ($job['status'] === 'Pending' && $action === 'cancel') {
        $stmt = db()->prepare("UPDATE jobs SET status = 'Cancelled', admin_note = ? WHERE id = ? AND status = 'Pending'");
        $stmt->bind_param('si', $note, $id);
        $stmt->execute();
        $noticeSent = send_notice('job_cancellation', $job['email'], ['email' => $job['email'], 'job_id' => $id, 'filename' => $job['original_name'], 'note' => $note]);
        flash($noticeSent ? 'success' : 'info', $noticeSent ? 'Job cancelled.' : 'Job cancelled, but the client notification email could not be sent.');
    } elseif ($job['status'] === 'Processing' && $action === 'complete') {
        $stmt = db()->prepare("UPDATE jobs SET status = 'Completed', completed_at = NOW(), admin_note = ? WHERE id = ? AND status = 'Processing'");
        $stmt->bind_param('si', $note, $id);
        $stmt->execute();
        $noticeSent = send_notice('job_completed', $job['email'], ['email' => $job['email'], 'job_id' => $id, 'filename' => $job['original_name']]);
        flash($noticeSent ? 'success' : 'info', $noticeSent ? 'Job marked completed.' : 'Job marked completed, but the client notification email could not be sent.');
    } else {
        flash('error', 'That action is not available for this job.');
    }
    redirect('/admin/job.php?id=' . $id);
}

$path = __DIR__ . '/../storage/uploads/' . $job['stored_name'];
render_header('Job Details', 'admin');
page_title('Job details', 'Review the request and update its print status.');
?>

<div class="detail-layout">
    <div class="table-card detail-card">
        <div class="detail-top">
            <div class="file-cell">
                <span class="file-icon large">▤</span>
                <span>
                    <strong><?= e($job['original_name']) ?></strong>
                    <small>Job #<?= str_pad((string)$job['id'], 5, '0', STR_PAD_LEFT) ?> · <?= number_format($job['file_size'] / 1024, 0) ?> KB</small>
                </span>
            </div>
            <?= status_badge($job['status']) ?>
        </div>

        <div class="detail-grid">
            <div>
                <small>Submitted by</small>
                <strong><?= e($job['email']) ?></strong>
            </div>
            <div>
                <small>Submitted at</small>
                <strong><?= e(date('M j, Y · g:i a', strtotime($job['created_at']))) ?></strong>
            </div>
            <div>
                <small>Paper size</small>
                <strong><?= e($job['paper_size']) ?></strong>
            </div>
            <div>
                <small>Colour option</small>
                <strong><?= e($job['colour_mode']) ?></strong>
            </div>
            <div>
                <small>File format</small>
                <strong><?= e(strtoupper(pathinfo($job['original_name'], PATHINFO_EXTENSION))) ?></strong>
            </div>
        </div>

        <div class="detail-note">
            <small>CLIENT INSTRUCTIONS</small>
            <p><?= nl2br(e($job['notes'] ?: 'No additional instructions.')) ?></p>
        </div>

        <?php if ($job['admin_note']): ?>
            <div class="detail-note">
                <small>ADMIN NOTE</small>
                <p><?= nl2br(e($job['admin_note'])) ?></p>
            </div>
        <?php endif; ?>

        <?php if (is_file($path)): ?>
            <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; margin-top:1rem;">
                <a class="button button-soft" href="/download.php?id=<?= (int)$job['id'] ?>">↓ Download Attachment</a>
                <a class="button button-soft" href="/download.php?id=<?= (int)$job['id'] ?>&preview=1" target="_blank">👁 Preview Attachment</a>
            </div>
        <?php endif; ?>
    </div>

    <aside class="action-card">
        <div class="eyebrow">JOB ACTIONS</div>
        <h2>Update status</h2>
        <p>Actions notify the client by email.</p>

        <form method="post" class="form-stack">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <label>Internal note
                <textarea name="admin_note" rows="3" maxlength="500" placeholder="Optional note for the client..."><?= e($job['admin_note'] ?? '') ?></textarea>
            </label>

            <?php if ($job['status'] === 'Pending'): ?>
                <button class="button button-admin button-wide" name="action" value="approve">✓ Approve &amp; send to print</button>
                <button class="button button-danger button-wide" name="action" value="cancel">Cancel job</button>
            <?php elseif ($job['status'] === 'Processing'): ?>
                <button class="button button-admin button-wide" name="action" value="complete">✓ Mark completed</button>
            <?php else: ?>
                <div class="notice-card">This job is <?= e(strtolower($job['status'])) ?>. No further status actions are available.</div>
            <?php endif; ?>
        </form>

        <a class="back-link" href="/admin/jobs.php">← Back to jobs</a>
    </aside>
</div>

<?php render_footer(); ?>
