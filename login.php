<?php
require __DIR__.'/app/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/'); verify_csrf();
$email=post_value('email'); $password=(string)($_POST['password']??'');
$stmt=db()->prepare('SELECT id,email,password_hash,role,status FROM users WHERE email=?'); $stmt->bind_param('s',$email); $stmt->execute(); $u=$stmt->get_result()->fetch_assoc();
if(!$u || $u['status']!=='Active' || !password_verify($password,$u['password_hash'])) { flash('error','The email or password was not recognised.'); redirect('/'); }
session_regenerate_id(true); unset($u['password_hash']); $_SESSION['user']=$u; redirect(strtolower($u['role'])==='admin'?'/admin/':'/client/');
