<?php
require 'db.php';
require 'auth.php';
date_default_timezone_set('Asia/Shanghai');

/** HTML 转义（兼容 NULL） */
function h($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/** 生成 111x150 缩略图（不放大原图） */
function generateThumbnailIfNotExists($originalPath, $thumbPath, $maxWidth = 111, $maxHeight = 150) {
    if (!file_exists($originalPath)) {
        error_log("跳过缩略图生成，原图不存在: $originalPath");
        return;
    }
    if (file_exists($thumbPath)) return;

    $info = getimagesize($originalPath);
    if (!$info) {
        error_log("获取图片信息失败: $originalPath");
        return;
    }

    switch ($info['mime']) {
        case 'image/jpeg': $srcImg = imagecreatefromjpeg($originalPath); break;
        case 'image/png':  $srcImg = imagecreatefrompng($originalPath);  break;
        case 'image/gif':  $srcImg = imagecreatefromgif($originalPath);  break;
        default:
            error_log("不支持的图片格式: $originalPath");
            return;
    }

    $srcWidth  = imagesx($srcImg);
    $srcHeight = imagesy($srcImg);
    $scale     = min($maxWidth / $srcWidth, $maxHeight / $srcHeight, 1); // 不放大
    $newWidth  = max(1, (int)($srcWidth * $scale));
    $newHeight = max(1, (int)($srcHeight * $scale));

    if (!is_dir(dirname($thumbPath))) {
        mkdir(dirname($thumbPath), 0755, true);
    }

    $thumbImg = imagecreatetruecolor($newWidth, $newHeight);
    imagecopyresampled($thumbImg, $srcImg, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);
    imagejpeg($thumbImg, $thumbPath, 85);
    imagedestroy($srcImg);
    imagedestroy($thumbImg);
    error_log("✅ 成功生成缩略图: $thumbPath");
}
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <title>我的衣橱 - 首页</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- 基础元信息 -->
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="theme-color" content="#2d6cdf" />

<!-- PWA 清单 -->
<link rel="manifest" href="/manifest.webmanifest" />

<!-- iOS 安装支持（可选但推荐） -->
<link rel="apple-touch-icon" href="/icons/icon-192.png" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-status-bar-style" content="default" />
<meta name="apple-mobile-web-app-title" content="Clothes" />

<!-- 注册 Service Worker -->
<script src="/pwa-register.js"></script>

    <!-- 使用本地 Tabler UI（与你其他页面一致） -->
    <link href="/style/tabler.min.css" rel="stylesheet">
    <script src="/style/tabler.min.js"></script>
    <style>
        .clothing-thumb {
            width: 100%;
            aspect-ratio: 111 / 150; /* 与缩略图比例一致 */
            object-fit: cover;
            border-radius: .5rem;
            border: 1px solid var(--tblr-border-color);
            background: var(--tblr-bg-surface);
            display: block;
        }
        .category-block { margin-bottom: 1.25rem; }
        .subcat-links a { color: var(--tblr-secondary); text-decoration: none; margin-right: .5rem; }
        .subcat-links a:hover { text-decoration: underline; }
        .item-tile { transition: transform .06s ease; }
        .item-tile:active { transform: scale(.985); }

        /* === 自适应宫格（与分类/子分类页保持一致） === */
        .thumbs-row{
            display:flex;
            flex-wrap:wrap;
            --gap:8px;
            margin-left:calc(var(--gap)*-0.5);
            margin-right:calc(var(--gap)*-0.5);
        }
        .thumb-col{
            padding:calc(var(--gap)*0.5);
            /* ≤576px: 3列 */
            flex:0 0 33.3333%;
            max-width:33.3333%;
        }
        @media (min-width:576px){  /* ≥SM: 4列 */
            .thumb-col{ flex-basis:25%; max-width:25%; }
        }
        @media (min-width:768px){  /* ≥MD: 5列 */
            .thumb-col{ flex-basis:20%; max-width:20%; }
        }
        @media (min-width:992px){  /* ≥LG: 8列 */
            .thumb-col{ flex-basis:12.5%; max-width:12.5%; }
        }
        @media (min-width:1200px){ /* ≥XL: 10列（桌面端 10%） */
            .thumb-col{ flex-basis:10%; max-width:10%; }
        }
    </style>
</head>
<body>
<div class="page">
  <div class="page-wrapper">

    <!-- 顶部：Tabler 风格页头 + 你的按钮文件 -->
    <div class="container-xl">
      <div class="page-header d-print-none">
        <div class="row align-items-center">
          <div class="col">
            <div class="page-pretitle">概览</div>
            <h2 class="page-title">我的衣橱</h2>
          </div>
          <div class="col-auto ms-auto d-print-none">
            <div class="btn-list">
              <?php
              if (file_exists(__DIR__ . '/header_buttons.php')) {
                  include __DIR__ . '/header_buttons.php';
              }
              ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 页面主体 -->
    <div class="page-body">
      <div class="container-xl">

<?php
$stmt = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, id ASC");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($categories as $category) {
    echo '<div class="category-block">';
    echo '  <h3 class="mb-2"><a class="text-reset" href="category.php?id=' . (int)$category['id'] . '">' . h($category['name']) . '</a></h3>';

    $stmtSub = $pdo->prepare("SELECT id, name FROM subcategories WHERE category_id = ? ORDER BY sort_order,id");
    $stmtSub->execute([(int)$category['id']]);
    $subs = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

    if ($subs) {
        echo '<div class="mb-2 small subcat-links">';
        foreach ($subs as $sub) {
            echo '<a href="subcategory.php?id=' . (int)$sub['id'] . '">' . h($sub['name']) . '</a>';
        }
        echo '</div>';
    }

    $stmt2 = $pdo->prepare("
        SELECT c.id, c.name, c.image_path, c.size, c.price, c.purchase_date, c.created_at
        FROM clothes c
        WHERE c.category_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt2->execute([(int)$category['id']]);
    $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    echo '<div class="thumbs-row">';
    foreach ($items as $item) {
        $img = 'https://via.placeholder.com/111x150?text=No+Image';

        if (!empty($item['image_path'])) {
            $original      = __DIR__ . '/' . $item['image_path'];
            $imageFilename = basename($item['image_path']);
            // 首页展示更小缩略图：222x300（2x 尺寸，Retina更清晰）
            $thumbWebPath  = 'thumbs/' . $imageFilename . '_222x300.jpg';
            $thumbFullPath = __DIR__ . '/' . $thumbWebPath;

            if (!file_exists($original)) {
                error_log("❌ 原图不存在: $original");
            }

            generateThumbnailIfNotExists($original, $thumbFullPath, 222, 300);

            if (file_exists($thumbFullPath)) {
                $img = $thumbWebPath;
            } elseif (file_exists($original)) {
                $img = $item['image_path'];
                error_log("⚠️ 缩略图未生成，回退使用原图: $img");
            } else {
                error_log("❌ 无有效图片: " . $item['image_path']);
            }
        }

        // 提示信息
        $tooltip = [];
        if (!empty($item['size']))      $tooltip[] = "尺码：" . (string)$item['size'];
        if (is_numeric($item['price'])) $tooltip[] = "价格：" . (string)$item['price'] . " 元";
        if (!empty($item['purchase_date']) && $item['purchase_date'] !== '0000-00-00') {
            $tooltip[] = "购买日期：" . (string)$item['purchase_date'];
        }
        if (empty($tooltip)) $tooltip[] = "未填写";
        $tooltipText = implode("\n", $tooltip);

        $altText = trim((string)($item['name'] ?? ''));
        if ($altText === '') $altText = '衣物';

        echo '  <div class="thumb-col">';
        echo '    <a href="item.php?id=' . (int)$item['id'] . '" class="item-tile" title="' . h($tooltipText) . '">';
        echo '      <img src="' . h($img) . '" class="clothing-thumb" alt="' . h($altText) . '" loading="lazy">';
        echo '    </a>';
        echo '  </div>';
    }
    echo '</div>'; // thumbs-row
    echo '</div>'; // category-block
}
?>

      </div>
    </div>

    <footer class="footer footer-transparent d-print-none">
      <div class="container-xl">
        <div class="text-secondary small py-3">
          © <?= date('Y') ?> 服装管理
        </div>
      </div>
    </footer>

  </div>
</div>
</body>
</html>
