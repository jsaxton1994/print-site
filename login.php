<?php
require __DIR__ . '/app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/');
}
verify_csrf();

$email    = post_value('email');
$password = (string)($_POST['password'] ?? '');

$stmt = db()->prepare('SELECT id, email, password_hash, role, status FROM users WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();

// Check if user exists and password is correct
if (!$u || !password_verify($password, $u['password_hash'])) {
    flash('error', 'The email or password was not recognised.');
    redirect('/');
}

// Handle Suspended status
if ($u['status'] === 'Suspended') {
    session_regenerate_id(true);
    unset($u['password_hash']);
    $_SESSION['user'] = $u;
    redirect('/suspended.php');
}

// Block any other non-active status
if ($u['status'] !== 'Active') {
    flash('error', 'Your account is not active.');
    redirect('/');
}

// Active user login
session_regenerate_id(true);
unset($u['password_hash']);
$_SESSION['user'] = $u;
redirect(strtolower($u['role']) === 'admin' ? '/admin/' : '/client/');
