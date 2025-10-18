<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $stmt = $pdo->prepare("SELECT user_id FROM user_tokens WHERE token = ?");
    $stmt->execute([$_COOKIE['remember_token']]);
    $row = $stmt->fetch();

    if ($row) {
        $_SESSION['user_id'] = $row['user_id'];
        // 可选：记录活跃时间
        $stmt = $pdo->prepare("UPDATE user_tokens SET created_at = NOW() WHERE token = ?");
        $stmt->execute([$_COOKIE['remember_token']]);
    }
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
