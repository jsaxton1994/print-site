<?php
require __DIR__ . '/../app/bootstrap.php';
refresh_user();
$user = require_login('User');

$stmt = db()->prepare('SELECT COUNT(*) FROM jobs WHERE user_id = ? AND status = ?');
$pending = 'Pending';
$stmt->bind_param('is', $user['id'], $pending);
$stmt->execute();
$pendingCount = (int)$stmt->get_result()->fetch_column();

$stmt = db()->prepare('SELECT COUNT(*) FROM jobs WHERE user_id = ? AND status = ?');
$processing = 'Processing';
$stmt->bind_param('is', $user['id'], $processing);
$stmt->execute();
$processingCount = (int)$stmt->get_result()->fetch_column();

$stmt = db()->prepare('SELECT COUNT(*) FROM jobs WHERE user_id = ? AND status = ?');
$completed = 'Completed';
$stmt->bind_param('is', $user['id'], $completed);
$stmt->execute();
$completedCount = (int)$stmt->get_result()->fetch_column();

$stmt = db()->prepare('SELECT COUNT(*) FROM jobs WHERE user_id = ? AND status = ?');
$cancelled = 'Cancelled';
$stmt->bind_param('is', $user['id'], $cancelled);
$stmt->execute();
$cancelledCount = (int)$stmt->get_result()->fetch_column();

$stmt = db()->prepare('SELECT * FROM jobs WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$jobs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

render_header('Dashboard', 'client');
page_title('Good to see you', 'Track your print jobs and send your next document to the queue.');
?>

<div class="stats-grid client-stats">
    <div class="stat-card">
        <div class="stat-top">
            <span>Pending</span>
            <i class="stat-icon warm">◷</i>
        </div>
        <div class="stat-number"><?= $pendingCount ?></div>
        <div class="stat-foot">Awaiting review</div>
    </div>
    <div class="stat-card">
        <div class="stat-top">
            <span>Processing</span>
            <i class="stat-icon violet">↻</i>
        </div>
        <div class="stat-number"><?= $processingCount ?></div>
        <div class="stat-foot">In the print room</div>
    </div>
    <div class="stat-card">
        <div class="stat-top">
            <span>Completed</span>
            <i class="stat-icon green">✓</i>
        </div>
        <div class="stat-number"><?= $completedCount ?></div>
        <div class="stat-foot">Ready for collection</div>
    </div>
    <div class="stat-card">
        <div class="stat-top">
            <span>Cancelled</span>
            <i class="stat-icon red">✕</i>
        </div>
        <div class="stat-number"><?= $cancelledCount ?></div>
        <div class="stat-foot">Voided requests</div>
    </div>
</div>

<div class="section-heading">
    <div>
        <div class="eyebrow">RECENT ACTIVITY</div>
        <h2>Your latest jobs</h2>
        <p>Each file is handled as its own print request.</p>
    </div>
</div>

<div class="table-card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Document</th>
                    <th>Submitted</th>
                    <th>Print settings</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$jobs): ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <span>▤</span>
                                <strong>No print jobs yet</strong>
                                <small>Your uploaded documents will appear here.</small>
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
                                    <small>Job #<?= str_pad((string)$j['id'], 5, '0', STR_PAD_LEFT) ?></small>
                                </span>
                            </div>
                        </td>
                        <td><?= e(date('M j, Y · g:i a', strtotime($j['created_at']))) ?></td>
                        <td><?= e($j['copies']) ?> copy · <?= e($j['paper_size']) ?> · <?= e($j['colour_mode']) ?></td>
                        <td><?= status_badge($j['status']) ?></td>
                        <td><a class="text-link" href="/client/jobs.php?id=<?= (int)$j['id'] ?>">Details →</a></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
