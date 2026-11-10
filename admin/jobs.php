<?php
require __DIR__ . '/../app/bootstrap.php';
refresh_user();
require_login('admin');

$allowed = ['Pending', 'Processing', 'Completed', 'Cancelled'];
$filter  = $_GET['status'] ?? 'Pending';
if (!in_array($filter, $allowed, true)) {
    $filter = 'Pending';
}

// Pagination for admin jobs (15 per page)
$perPage = 15;
$page = max(1, (int)($_GET['page'] ?? 1));

$stmtCount = db()->prepare('SELECT COUNT(*) FROM jobs WHERE status = ?');
$stmtCount->bind_param('s', $filter);
$stmtCount->execute();
$totalJobs = (int)$stmtCount->get_result()->fetch_column();
$totalPages = max(1, (int)ceil($totalJobs / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$sql = 'SELECT j.*, u.email 
        FROM jobs j 
        JOIN users u ON u.id = j.user_id 
        WHERE j.status = ? 
        ORDER BY j.created_at DESC 
        LIMIT ? OFFSET ?';
$stmt = db()->prepare($sql);
$stmt->bind_param('sii', $filter, $perPage, $offset);
$stmt->execute();
$jobs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

render_header('Job Management', 'admin');
page_title('Job Management', 'Review, dispatch, and track print jobs across all client accounts.');
?>

<div class="filter-tabs">
    <?php foreach ($allowed as $s): ?>
        <a class="filter-tab <?= $s === $filter ? 'selected' : '' ?>" href="/admin/jobs.php?status=<?= e($s) ?>"><?= e($s) ?></a>
    <?php endforeach; ?>
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
                    <th>Settings</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$jobs): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <span>✓</span>
                                <strong>No <?= e(strtolower($filter)) ?> jobs</strong>
                                <small>Jobs in this state will appear here as they are received.</small>
                            </div>
                        </td>
                    </tr>
                <?php else: foreach ($jobs as $j): ?>
                    <tr>
                        <td class="job-id">#<?= str_pad((string)$j['id'], 5, '0', STR_PAD_LEFT) ?></td>
                        <td>
                            <div class="file-cell">
                                <span class="file-icon">▤</span>
                                <span>
                                    <strong><?= e($j['original_name']) ?></strong>
                                    <small><?= number_format($j['file_size'] / 1024, 0) ?> KB</small>
                                </span>
                            </div>
                        </td>
                        <td><?= e($j['email']) ?></td>
                        <td><?= e(date('M j, Y · g:i a', strtotime($j['created_at']))) ?></td>
                        <td><?= e($j['paper_size']) ?> · <?= e($j['colour_mode']) ?></td>
                        <td><?= status_badge($j['status']) ?></td>
                        <td>
                            <a class="button button-small button-soft" href="/admin/job.php?id=<?= (int)$j['id'] ?>">View details</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div style="display:flex; justify-content:center; align-items:center; gap:6px; padding:1.25rem; border-top:1px solid var(--border, rgba(0,0,0,0.08)); flex-wrap:wrap;">
            <?php 
                $buildPageUrl = function($targetPage) use ($filter) {
                    return '/admin/jobs.php?status=' . urlencode($filter) . '&page=' . $targetPage;
                };
            ?>

            <?php if ($page > 1): ?>
                <a class="button button-small button-soft" href="<?= e($buildPageUrl($page - 1)) ?>">&laquo; Prev</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php if ($i === 1 || $i === $totalPages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                    <a class="button button-small <?= $i === $page ? 'button-admin' : 'button-soft' ?>" href="<?= e($buildPageUrl($i)) ?>">
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

<?php render_footer(); ?>
