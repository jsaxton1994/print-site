<?php
// Run periodically via Task Scheduler or Cron:
// php C:\xampp\htdocs\print-portal\cron.php
require __DIR__ . '/app/bootstrap.php';

$completed = check_epson_completion();
$time = date('Y-m-d H:i:s');

if ($completed > 0) {
    echo "[{$time}] Successfully completed and notified {$completed} job(s).\n";
} else {
    echo "[{$time}] Sync finished. No newly finished print jobs detected.\n";
}
