<?php
require 'db.php';
require 'auth.php';

$id = $_GET['id'] ?? 0;
$id = intval($id);

// 查询是否存在该衣物
$stmt = $pdo->prepare("SELECT * FROM clothes WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    die("找不到要删除的衣物记录");
}

// 删除图片文件（使用绝对路径）
if (!empty($item['image_path'])) {
    $imagePath = __DIR__ . '/' . $item['image_path'];
    if (file_exists($imagePath)) {
        unlink($imagePath);
    }
}

// 删除标签关联
$stmt = $pdo->prepare("DELETE FROM clothes_tags WHERE clothes_id = ?");
$stmt->execute([$id]);

// 删除衣物记录
$stmt = $pdo->prepare("DELETE FROM clothes WHERE id = ?");
$stmt->execute([$id]);

// 跳转回首页
header("Location: index.php");
exit;
?>
