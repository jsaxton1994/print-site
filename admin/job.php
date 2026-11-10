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

// Auto-check printer status only when viewing a Processing job
if ($job['status'] === 'Processing' && function_exists('check_epson_completion')) {
    check_epson_completion();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_value('action');
    $note   = substr(post_value('admin_note'), 0, 500);
    $path   = __DIR__ . '/../storage/uploads/' . $job['stored_name'];

    if ($action === 'sync') {
        if (!function_exists('check_epson_completion')) {
            flash('error', 'Printer sync function (check_epson_completion) is not defined or required in bootstrap.php.');
        } else {
            $stats = check_epson_completion();
            if (!empty($stats['cancelled'])) {
                flash('error', 'Printer returned an error; job cancelled automatically.');
            } elseif (!empty($stats['completed'])) {
                flash('success', 'Print job completed successfully.');
            } else {
                flash('info', 'Printer sync checked. Job is still processing.');
            }
        }
        redirect('/admin/job.php?id=' . $id);
    } elseif ($job['status'] === 'Pending' && $action === 'approve') {
        if (!function_exists('dispatch_to_printer')) {
            flash('error', 'Printer dispatch function (dispatch_to_printer) is not defined.');
            redirect('/admin/job.php?id=' . $id);
        }

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
            flash('error', 'Printer accepted the email, but the job status could not be updated. Check job #' . $id . ' before retrying.');
            redirect('/admin/job.php?id=' . $id);
        }

        // If completion mode is set to immediate, mark completed right away
        if (setting('auto_complete_mode') === 'immediate') {
            $completeStmt = db()->prepare("UPDATE jobs SET status = 'Completed', completed_at = NOW() WHERE id = ?");
            $completeStmt->bind_param('i', $id);
            $completeStmt->execute();

            send_notice('job_completed', $job['email'], ['email' => $job['email'], 'job_id' => $id, 'filename' => $job['original_name']]);
            flash('success', 'Job approved, sent to printer, and marked completed.');
        } else {
            $noticeSent = send_notice('job_processing', $job['email'], ['email' => $job['email'], 'job_id' => $id, 'filename' => $job['original_name']]);
            flash($noticeSent ? 'success' : 'info', $noticeSent ? 'Job approved and sent to the Epson printer.' : 'Job sent to the printer; client email notice could not be sent.');
        }
    } elseif ($job['status'] === 'Pending' && $action === 'cancel') {
        $stmt = db()->prepare("UPDATE jobs SET status = 'Cancelled', admin_note = ? WHERE id = ? AND status = 'Pending'");
        $stmt->bind_param('si', $note, $id);
        $stmt->execute();
        $noticeSent = send_notice('job_cancellation', $job['email'], ['email' => $job['email'], 'job_id' => $id, 'filename' => $job['original_name'], 'note' => $note]);
        flash($noticeSent ? 'success' : 'info', $noticeSent ? 'Job cancelled.' : 'Job cancelled, but client notification email could not be sent.');
    } elseif ($job['status'] === 'Processing' && $action === 'complete') {
        $stmt = db()->prepare("UPDATE jobs SET status = 'Completed', completed_at = NOW(), admin_note = ? WHERE id = ? AND status = 'Processing'");
        $stmt->bind_param('si', $note, $id);
        $stmt->execute();
        $noticeSent = send_notice('job_completed', $job['email'], ['email' => $job['email'], 'job_id' => $id, 'filename' => $job['original_name']]);
        flash($noticeSent ? 'success' : 'info', $noticeSent ? 'Job marked completed.' : 'Job marked completed, but client notification email could not be sent.');
    } elseif ($action === 'requeue' && $job['status'] !== 'Pending') {
        $stmt = db()->prepare("UPDATE jobs SET status = 'Pending', completed_at = NULL, admin_note = ? WHERE id = ?");
        $stmt->bind_param('si', $note, $id);
        $stmt->execute();
        flash('success', 'Job #' . str_pad((string)$id, 5, '0', STR_PAD_LEFT) . ' has been requeued back to Pending.');
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
                <small>Copies</small>
                <strong><?= (int)($job['copies'] ?? 1) ?> <?= (int)($job['copies'] ?? 1) === 1 ? 'copy' : 'copies' ?></strong>
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
                <small>ADMIN NOTE / PRINTER FEEDBACK</small>
                <p><?= nl2br(e($job['admin_note'])) ?></p>
            </div>
        <?php endif; ?>

        <?php if (is_file($path)): ?>
            <div style="display:flex; gap:0.5rem; align-items:center; flex-wrap:wrap; margin-top:1rem;">
                <a class="button button-soft" href="/download.php?id=<?= (int)$job['id'] ?>">↓ Download Attachment</a>
                <button type="button" class="button button-soft" onclick="openPreviewModal()">👁 Preview Attachment</button>
            </div>
        <?php endif; ?>
    </div>

    <aside class="action-card">
        <div class="eyebrow">JOB ACTIONS</div>
        <h2>Update status</h2>
        <p>Actions update the job and notify the client by email.</p>

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
                <button class="button button-soft button-wide" name="action" value="sync">⟳ Sync with printer</button>
                <button class="button button-soft button-wide" name="action" value="requeue" onclick="return confirm('Are you sure you want to requeue this job back to Pending?');">↺ Requeue to Pending</button>
            <?php else: ?>
                <div class="notice-card">This job is <?= e(strtolower($job['status'])) ?>.</div>
                <button class="button button-soft button-wide" name="action" value="requeue" onclick="return confirm('Are you sure you want to requeue this job back to Pending?');">↺ Requeue to Pending</button>
            <?php endif; ?>
        </form>

        <a class="back-link" href="/admin/jobs.php">← Back to jobs</a>
    </aside>
</div>

<!-- Modal Viewer for in-browser preview -->
<div id="previewModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:99999; align-items:center; justify-content:center; padding:1.5rem;">
    <div style="background:#fff; width:100%; max-width:1050px; height:90vh; border-radius:12px; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 20px 40px rgba(0,0,0,0.3);">
        <div style="padding:0.75rem 1.25rem; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e5e7eb; background:#f9fafb;">
            <strong style="font-size:1rem;"><?= e($job['original_name']) ?></strong>
            <div style="display:flex; gap:0.5rem; align-items:center;">
                <a href="/download.php?id=<?= (int)$job['id'] ?>&preview=1" target="_blank" class="button button-sm button-soft" style="text-decoration:none;">Open in new tab ↗</a>
                <button type="button" class="button button-sm" onclick="closePreviewModal()" style="cursor:pointer; background:#e5e7eb; border:none; border-radius:6px; padding:0.25rem 0.65rem;">✕ Close</button>
            </div>
        </div>
        <div style="flex:1; background:#525659; overflow:hidden;">
            <iframe id="previewIframe" src="" style="width:100%; height:100%; border:none;"></iframe>
        </div>
    </div>
</div>

<script>
function openPreviewModal() {
    const modal = document.getElementById('previewModal');
    const iframe = document.getElementById('previewIframe');
    iframe.src = '/download.php?id=<?= (int)$job['id'] ?>&preview=1';
    modal.style.display = 'flex';
}

function closePreviewModal() {
    const modal = document.getElementById('previewModal');
    const iframe = document.getElementById('previewIframe');
    iframe.src = '';
    modal.style.display = 'none';
}

document.getElementById('previewModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePreviewModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('previewModal');
        if (modal && modal.style.display === 'flex') {
            closePreviewModal();
        }
    }
});
</script>

<?php render_footer(); ?>
