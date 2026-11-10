<?php
require __DIR__ . '/app/bootstrap.php';
refresh_user();

$user = current_user();
if (!$user) {
    http_response_code(403);
    exit('Access denied. Please sign in.');
}

$id = (int)($_GET['id'] ?? 0);
$isPreview = isset($_GET['preview']) && $_GET['preview'] == '1';

// Fetch job
$stmt = db()->prepare('SELECT id, user_id, original_name, stored_name, mime_type, file_size FROM jobs WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();

if (!$job) {
    http_response_code(404);
    exit('Document not found.');
}

// Ensure normal users can only download their own documents; admins can access any
if ($user['role'] !== 'Admin' && (int)$job['user_id'] !== (int)$user['id']) {
    http_response_code(403);
    exit('Access denied.');
}

$filePath = __DIR__ . '/storage/uploads/' . $job['stored_name'];
if (!is_file($filePath)) {
    http_response_code(404);
    exit('File not found on storage disk.');
}

// Clear any output buffers to prevent corrupted binaries
if (ob_get_level()) {
    ob_end_clean();
}

$disposition = $isPreview ? 'inline' : 'attachment';
$mimeType = $job['mime_type'] ?: mime_content_type($filePath) ?: 'application/octet-stream';

header('Content-Type: ' . $mimeType);
header('Content-Disposition: ' . $disposition . '; filename="' . basename($job['original_name']) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

readfile($filePath);
exit;
