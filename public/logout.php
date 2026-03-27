<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

use Mailbox\Auth;

Auth::logout();
header('Location: login.php');
exit;
