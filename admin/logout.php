<?php
require __DIR__ . '/../app/bootstrap.php';

unset($_SESSION['user']);

flash('info', 'You have been logged out.');
redirect('/');
