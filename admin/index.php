<?php
require __DIR__.'/../app/bootstrap.php';
refresh_user();

$currentUser = current_user();
$isAdmin = $currentUser && in_array(strtolower((string)($currentUser['role'] ?? '')), ['admin', 'administrator'], true);

// Direct Admin Sign In Handler: avoids redirect loops with active client sessions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['admin_login']) || isset($_POST['email']))) {
    verify_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $stmt = db()->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $adminCandidate = $stmt->get_result()->fetch_assoc();

    if ($adminCandidate && password_verify($password, $adminCandidate['password'])) {
        if (in_array(strtolower((string)($adminCandidate['role'] ?? '')), ['admin', 'administrator'], true)) {
            $_SESSION['user_id'] = $adminCandidate['id'];
            refresh_user();
            flash('success', 'Welcome to the admin console.');
            redirect('/admin/');
        } else {
            flash('error', 'This account does not have administrator privileges.');
            redirect('/admin/');
        }
    } else {
        flash('error', 'Invalid email address or password.');
        redirect('/admin/');
    }
}

// If not logged in as an administrator, show sign in form without redirecting
if (!$isAdmin) {
    render_header('Sign in', 'admin');
    ?>
    <div class="auth-shell admin-auth">
        <div class="auth-art">
            <div class="art-orbit orbit-one"></div>
            <div class="art-orbit orbit-two"></div>
            <div class="art-document">OPS<span>ADMIN CONSOLE</span></div>
            <div class="art-caption"><span>PRINTING,</span><br>UNDER CONTROL.</div>
        </div>
        <div class="auth-panel">
            <div class="eyebrow">ADMIN ACCESS</div>
            <h1>Welcome to<br>the control room</h1>
            <p class="muted">
                <?php if ($currentUser): ?>
                    Currently signed in as <?=e($currentUser['email'])?> (non-admin). Please sign in with an admin account.
                <?php else: ?>
                    Sign in to manage print jobs and accounts.
                <?php endif; ?>
            </p>
            <form method="post" action="/admin/" class="form-stack">
                <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                <input type="hidden" name="admin_login" value="1">
                <label>Email address
                    <input type="email" name="email" required placeholder="admin@company.com">
                </label>
                <label>Password
                    <input type="password" name="password" required placeholder="Enter your password">
                </label>
                <button class="button button-admin button-wide">Sign in <span>→</span></button>
            </form>
        </div>
    </div>
    <?php
    render_footer();
    exit;
}

require_login('admin');

// Handle Deleting an Email Log entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_email_log') {
    verify_csrf();
    $deleteId = (int)($_POST['id'] ?? 0);
    if ($deleteId > 0) {
        $delTable = 'email_logs';
        $checkTbl = db()->query("SHOW TABLES LIKE 'email_logs'");
        if (!$checkTbl || $checkTbl->num_rows === 0) {
            $delTable = 'email_log';
        }
        $stmtDel = db()->prepare("DELETE FROM {$delTable} WHERE id = ?");
        $stmtDel->bind_param('i', $deleteId);
        $stmtDel->execute();
        flash('success', 'Email log entry #' . $deleteId . ' deleted successfully.');
    }
    $pageRedirect = (int)($_GET['email_page'] ?? 1);
    redirect('/admin/' . ($pageRedirect > 1 ? '?email_page=' . $pageRedirect : ''));
}

$counts = [];
foreach (['Pending', 'Processing', 'Completed', 'Cancelled'] as $s) {
    $stmt = db()->prepare('SELECT COUNT(*) FROM jobs WHERE status=?');
    $stmt->bind_param('s', $s);
    $stmt->execute();
    $counts[$s] = (int)$stmt->get_result()->fetch_column();
}

$usersCount = (int)db()->query('SELECT COUNT(*) FROM users')->fetch_column();
$jobs = db()->query("SELECT j.*, u.email FROM jobs j JOIN users u ON u.id = j.user_id WHERE j.status = 'Pending' ORDER BY j.created_at ASC LIMIT 8")->fetch_all(MYSQLI_ASSOC);

// Email Logs Pagination (15 per page)
$emailLogs = [];
$totalEmailLogs = 0;
$emailPage = max(1, (int)($_GET['email_page'] ?? 1));
$perPage = 15;
$totalEmailPages = 1;

$tableCheck = db()->query("SHOW TABLES LIKE 'email_logs'");
$tableToUse = ($tableCheck && $tableCheck->num_rows > 0) ? 'email_logs' : 'email_log';

$tableExists = db()->query("SHOW TABLES LIKE '{$tableToUse}'");
if ($tableExists && $tableExists->num_rows > 0) {
    $totalCountRes = db()->query("SELECT COUNT(*) AS total FROM {$tableToUse}");
    $totalEmailLogs = (int)($totalCountRes ? $totalCountRes->fetch_assoc()['total'] : 0);
    $totalEmailPages = max(1, (int)ceil($totalEmailLogs / $perPage));

    if ($emailPage > $totalEmailPages) {
        $emailPage = $totalEmailPages;
    }
    $offset = ($emailPage - 1) * $perPage;

    if ($tableToUse === 'email_logs') {
        $stmtLog = db()->prepare("SELECT id, recipient, subject, job_id, status, sent_at FROM email_logs ORDER BY sent_at DESC LIMIT ? OFFSET ?");
    } else {
        $stmtLog = db()->prepare("SELECT id, recipient, subject, NULL as job_id, IF(success=1,'Sent','Failed') as status, sent_at FROM email_log ORDER BY sent_at DESC LIMIT ? OFFSET ?");
    }
    $stmtLog->bind_param('ii', $perPage, $offset);
    $stmtLog->execute();
    $emailLogs = $stmtLog->get_result()->fetch_all(MYSQLI_ASSOC);
}

render_header('Dashboard', 'admin');
page_title('Dashboard', 'A quick overview of the print room.');
?>
<div class="stats-grid admin-stats">
    <a class="stat-card" href="/admin/jobs.php?status=Pending">
        <div class="stat-top"><span>Pending jobs</span><i class="stat-icon warm">◷</i></div>
        <div class="stat-number"><?=number_format($counts['Pending'])?></div>
        <div class="stat-foot"><span class="dot pending"></span> Awaiting review</div>
    </a>
    <a class="stat-card" href="/admin/jobs.php?status=Processing">
        <div class="stat-top"><span>Processing jobs</span><i class="stat-icon violet">↻</i></div>
        <div class="stat-number"><?=number_format($counts['Processing'])?></div>
        <div class="stat-foot"><span class="dot processing"></span> In the print queue</div>
    </a>
    <a class="stat-card" href="/admin/jobs.php?status=Completed">
        <div class="stat-top"><span>Completed jobs</span><i class="stat-icon green">✓</i></div>
        <div class="stat-number"><?=number_format($counts['Completed'])?></div>
        <div class="stat-foot"><span class="dot completed"></span> Ready for collection</div>
    </a>
    <a class="stat-card" href="/admin/jobs.php?status=Cancelled">
        <div class="stat-top"><span>Cancelled jobs</span><i class="stat-icon gray">×</i></div>
        <div class="stat-number"><?=number_format($counts['Cancelled'])?></div>
        <div class="stat-foot"><span class="dot cancelled"></span> No longer in queue</div>
    </a>
    <a class="stat-card">
        <div class="stat-top"><span>Users</span><i class="stat-icon blue">♙</i></div>
        <div class="stat-number"><?=number_format($usersCount)?></div>
        <div class="stat-foot">Registered client accounts</div>
    </a>
</div>

<!-- Pending jobs table -->
<div class="section-heading">
    <div>
        <div class="eyebrow">NEEDS ATTENTION</div>
        <h2>Pending jobs</h2>
        <p>Review incoming documents and decide what prints next.</p>
    </div>
    <a class="text-link" href="/admin/jobs.php?status=Pending">View all pending <span>→</span></a>
</div>
<div class="table-card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Job</th>
                    <th>Document</th>
                    <th>Client</th>
                    <th>Submitted</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$jobs): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <span>✓</span>
                                <strong>All caught up</strong>
                                <small>There are no pending jobs right now.</small>
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($jobs as $j): ?>
                    <tr>
                        <td class="job-id">#<?=str_pad((string)$j['id'], 5, '0', STR_PAD_LEFT)?></td>
                        <td>
                            <div class="file-cell">
                                <span class="file-icon">▤</span>
                                <span>
                                    <strong><?=e($j['original_name'])?></strong>
                                    <small><?=number_format($j['file_size'] / 1024, 0)?> KB · <?=e($j['paper_size'])?> · <?=e($j['colour_mode'])?></small>
                                </span>
                            </div>
                        </td>
                        <td><?=e($j['email'])?></td>
                        <td><?=e(date('M j, Y · g:i a', strtotime($j['created_at'])))?></td>
                        <td><?=status_badge($j['status'])?></td>
                        <td><a class="button button-small button-soft" href="/admin/job.php?id=<?=(int)$j['id']?>">View details</a></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Email audit log table -->
<div class="section-heading" style="margin-top: 2.5rem; display:flex; justify-content:space-between; align-items:flex-end;">
    <div>
        <div class="eyebrow">COMMUNICATIONS AUDIT</div>
        <h2>Sent email logs</h2>
        <p>Record of all transactional notifications dispatched to clients (15 per page).</p>
    </div>
    <?php if ($totalEmailLogs > 0): ?>
        <small class="muted">Showing page <?= $emailPage ?> of <?= $totalEmailPages ?> (<?= $totalEmailLogs ?> total)</small>
    <?php endif; ?>
</div>
<div class="table-card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Recipient</th>
                    <th>Subject</th>
                    <th>Related Job</th>
                    <th>Sent At</th>
                    <th>Delivery Status</th>
                    <th style="text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$emailLogs): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <span>✉</span>
                                <strong>No emails logged yet</strong>
                                <small>Outbound emails will automatically appear here once dispatched.</small>
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($emailLogs as $log): ?>
                    <tr>
                        <td><strong><?=e($log['recipient'])?></strong></td>
                        <td><?=e($log['subject'])?></td>
                        <td>
                            <?php if (!empty($log['job_id'])): ?>
                                <a class="text-link" href="/admin/job.php?id=<?=(int)$log['job_id']?>">#<?=str_pad((string)$log['job_id'], 5, '0', STR_PAD_LEFT)?></a>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?=e(date('M j, Y · g:i a', strtotime($log['sent_at'])))?></td>
                        <td>
                            <?php if (strtolower((string)($log['status'] ?? '')) === 'sent'): ?>
                                <span class="dot completed"></span> <?=e($log['status'])?>
                            <?php else: ?>
                                <span class="dot cancelled"></span> <?=e($log['status'])?>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;">
                            <form method="post" action="" onsubmit="return confirm('Delete this email log entry?');" style="display:inline;">
                                <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                                <input type="hidden" name="action" value="delete_email_log">
                                <input type="hidden" name="id" value="<?=(int)$log['id']?>">
                                <button type="submit" class="button button-small button-soft" style="color:var(--danger, #dc2626); padding: 4px 8px; font-size: 0.8rem;">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalEmailPages > 1): ?>
        <div style="display:flex; justify-content:center; align-items:center; gap:6px; padding:1.25rem; border-top:1px solid var(--border, rgba(0,0,0,0.08)); flex-wrap:wrap;">
            <?php if ($emailPage > 1): ?>
                <a class="button button-small button-soft" href="?email_page=<?= $emailPage - 1 ?>">&laquo; Prev</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalEmailPages; $i++): ?>
                <?php if ($i === 1 || $i === $totalEmailPages || ($i >= $emailPage - 2 && $i <= $emailPage + 2)): ?>
                    <a class="button button-small <?= $i === $emailPage ? 'button-admin' : 'button-soft' ?>" href="?email_page=<?= $i ?>">
                        <?= $i ?>
                    </a>
                <?php elseif ($i === $emailPage - 3 || $i === $emailPage + 3): ?>
                    <span style="padding:0 4px; color:var(--muted, #64748b);">&hellip;</span>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($emailPage < $totalEmailPages): ?>
                <a class="button button-small button-soft" href="?email_page=<?= $emailPage + 1 ?>">Next &raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php
render_footer();
