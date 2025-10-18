<?php
require 'db.php';
require 'auth.php';
date_default_timezone_set('Asia/Shanghai');

$categoryId = (int)($_GET['id'] ?? 0);

/** HTML 转义 */
function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

$stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
$stmt->execute([$categoryId]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$category) { http_response_code(404); exit('分类不存在'); }

$stmt = $pdo->prepare("SELECT * FROM subcategories WHERE category_id = ? ORDER BY sort_order ASC, id ASC");
$stmt->execute([$categoryId]);
$subcategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

/** 生成缩略图 */
function generateThumbnailIfNotExists($originalPath, $thumbPath, $maxWidth = 240, $maxHeight = 400) {
    if (!file_exists($originalPath) || file_exists($thumbPath)) return;
    $info = getimagesize($originalPath);
    if (!$info) return;
    switch ($info['mime']) {
        case 'image/jpeg': $srcImg = imagecreatefromjpeg($originalPath); break;
        case 'image/png':  $srcImg = imagecreatefrompng($originalPath);  break;
        case 'image/gif':  $srcImg = imagecreatefromgif($originalPath);  break;
        default: return;
    }
    $srcWidth  = imagesx($srcImg);
    $srcHeight = imagesy($srcImg);
    $scale     = min($maxWidth / $srcWidth, $maxHeight / $srcHeight);
    $newWidth  = max(1, (int)($srcWidth * $scale));
    $newHeight = max(1, (int)($srcHeight * $scale));

    if (!is_dir(dirname($thumbPath))) mkdir(dirname($thumbPath), 0755, true);

    $thumbImg = imagecreatetruecolor($newWidth, $newHeight);
    imagecopyresampled($thumbImg, $srcImg, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);
    imagejpeg($thumbImg, $thumbPath, 85);
    imagedestroy($srcImg);
    imagedestroy($thumbImg);
}
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title><?= h($category['name']) ?> - 分类</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="/style/tabler.min.css" rel="stylesheet">
  <script src="/style/tabler.min.js"></script>
  <style>
    .page-narrow { max-width: 900px; margin: 0 auto; }
    @media (max-width: 576px){ .page-narrow { max-width: 100%; } }

    /* 响应式缩略图布局 */
    .thumbs-row {
      display: flex;
      flex-wrap: wrap;
      --gap: 8px;
      margin-left: calc(var(--gap) * -0.5);
      margin-right: calc(var(--gap) * -0.5);
    }
    .thumb-col {
      padding: calc(var(--gap) * 0.5);
      flex: 0 0 33.3333%;
      max-width: 25%;
    }
    @media (min-width: 576px){ .thumb-col { flex-basis:25%; max-width:25%; } }
    @media (min-width: 768px){ .thumb-col { flex-basis:20%; max-width:20%; } }
    @media (min-width: 992px){ .thumb-col { flex-basis:12.5%; max-width:12.5%; } }
    @media (min-width: 1200px){ .thumb-col { flex-basis:10%; max-width:10%; } }

    .clothing-thumb {
      width: 100%;
      aspect-ratio: 3 / 5;
      object-fit: cover;
      border-radius: .5rem;
      border: 1px solid var(--tblr-border-color);
      background: var(--tblr-bg-surface);
      display: block;
    }
    .item-tile { display:block; transition: transform .06s ease; }
    .item-tile:active { transform: scale(.985); }

    .subcard-header a { text-decoration: none; color: inherit; }
    .chip {
      display:inline-flex; align-items:center; gap:.25rem;
      padding:.15rem .5rem; border:1px solid var(--tblr-border-color);
      border-radius:999px; font-size:.8rem; background: var(--tblr-bg-surface);
    }
  </style>
</head>
<body>
<div class="page">
  <div class="page-wrapper">
    <div class="container-xl page-narrow">
      <div class="page-header d-print-none">
        <div class="row align-items-center">
          <div class="col">
            <div class="page-pretitle">浏览</div>
            <h2 class="page-title"><?= h($category['name']) ?> 👚 分类</h2>
          </div>
          <div class="col-auto ms-auto d-print-none">
            <div class="btn-list"><a href="index.php" class="btn btn-outline-secondary">返回首页</a></div>
          </div>
        </div>
      </div>

      <?php foreach ($subcategories as $sub): ?>
        <?php
          $stmt = $pdo->prepare("SELECT * FROM clothes WHERE subcategory_id = ? ORDER BY sort_order ASC, id ASC");
          $stmt->execute([$sub['id']]);
          $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="card mb-3">
          <div class="card-header d-flex justify-content-between align-items-center subcard-header">
            <h3 class="m-0 h4">
              <a href="subcategory.php?id=<?= (int)$sub['id'] ?>" class="text-reset"><?= h($sub['name']) ?></a>
            </h3>
            <span class="chip">共 <?= count($items) ?> 件</span>
          </div>
          <div class="card-body">
            <?php if (!$items): ?>
              <div class="text-secondary">该子分类暂无衣物。</div>
            <?php else: ?>
              <div class="thumbs-row">
                <?php foreach ($items as $item):
                  if (!empty($item['image_path'])) {
                      $original      = __DIR__ . '/' . $item['image_path'];
                      $filename      = basename($item['image_path']);
                      $thumbWebPath  = 'thumbs/' . $filename . '_240x400.jpg';
                      $thumbFullPath = __DIR__ . '/' . $thumbWebPath;
                      generateThumbnailIfNotExists($original, $thumbFullPath, 240, 400);
                      $img = $thumbWebPath;
                  } else {
                      $img = 'https://via.placeholder.com/120x200?text=No+Image';
                  }
                  $tooltip = [];
                  if (!empty($item['size'])) $tooltip[] = "尺码：{$item['size']}";
                  if (is_numeric($item['price'])) $tooltip[] = "价格：{$item['price']} 元";
                  if (!empty($item['purchase_date']) && $item['purchase_date'] !== '0000-00-00') {
                      $tooltip[] = "购买日期：{$item['purchase_date']}";
                  }
                  if (!empty($item['purchase_channel'])) $tooltip[] = "购买途径：{$item['purchase_channel']}";
                  if (empty($tooltip)) $tooltip[] = "未填写";
                  $tooltipText = implode("\n", $tooltip);
                  $alt = trim((string)($item['name'] ?? ''));
                  if ($alt === '') $alt = '衣物';
                ?>
                  <div class="thumb-col">
                    <a href="item.php?id=<?= (int)$item['id'] ?>" class="item-tile" title="<?= h($tooltipText) ?>">
                      <img src="<?= h($img) ?>" class="clothing-thumb" alt="<?= h($alt) ?>" loading="lazy">
                    </a>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="my-4"></div>
    </div>
    <footer class="footer footer-transparent d-print-none">
      <div class="container-xl page-narrow">
        <div class="text-secondary small py-3">© <?= date('Y') ?> 服装管理</div>
      </div>
    </footer>
  </div>
</div>
</body>
</html>
