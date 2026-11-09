<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
$email = $argv[1] ?? '';
$password = $argv[2] ?? '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) { fwrite(STDERR, "Usage: php bin/create-admin.php admin@example.com 'long-password-at-least-12'\n"); exit(1); }
$stmt = db()->prepare("INSERT INTO users(email,password_hash,role,status) VALUES(?,?,'Admin','Active')"); $hash=password_hash($password,PASSWORD_DEFAULT); $stmt->bind_param('ss',$email,$hash); $stmt->execute();
echo "Administrator created: $email\n";
