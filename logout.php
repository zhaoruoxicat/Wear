<?php
session_start();
require_once 'db.php';

if (isset($_COOKIE['remember_token'])) {
    $stmt = $pdo->prepare("DELETE FROM user_tokens WHERE token = ?");
    $stmt->execute([$_COOKIE['remember_token']]);
    setcookie('remember_token', '', time() - 3600, '/');
}

session_destroy();
header("Location: login.php");
exit;
