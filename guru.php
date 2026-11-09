<?php
declare(strict_types=1);
session_start();
if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'teacher') {
    header('Location: login.php');
    exit;
}
header('Location: index.php');
exit;
