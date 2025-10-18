<?php
require 'db.php';
require 'auth.php';
date_default_timezone_set('Asia/Shanghai');

$subcategoryId = intval($_GET['id'] ?? 0);

/** HTML 转义（兼容 NULL） */
function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

// 获取子分类信息
$stmt = $pdo->prepare("SELECT * FROM subcategories WHERE id = ?");
$stmt->execute([$subcategoryId]);
$subcategory = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$subcategory) { http_response_code(404); exit('子分类不存在'); }

// 主分类信息
$stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
$stmt->execute([$subcategory['category_id']]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);

// 衣物记录
$stmt = $pdo->prepare("SELECT * FROM clothes WHERE subcategory_id = ? ORDER BY created_at DESC");
$stmt->execute([$subcategoryId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title><?= h($subcategory['name']) ?> - 子分类</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- 本地 Tabler -->
  <link href="/style/tabler.min.css" rel="stylesheet">
  <script src="/style/tabler.min.js"></script>
  <style>
    /* 整体页面收窄居中（桌面），移动端自动全宽 */
    .page-narrow { max-width: 900px; margin: 0 auto; }
    @media (max-width: 576px){ .page-narrow { max-width: 100%; } }

    /* 自适应宫格：用断点控制每行数量；≥1200px 才固定到 10% */
    .thumbs-row {
      display: flex;
      flex-wrap: wrap;
      --gap: 8px;
      margin-left: calc(var(--gap) * -0.5);
      margin-right: calc(var(--gap) * -0.5);
    }
    .thumb-wrapper {
      padding: calc(var(--gap) * 0.5);
      /* 默认：手机 3 列（≈33.333%） */
      flex: 0 0 33.3333%;
      max-width: 25%;
    }
    @media (min-width: 576px){ /* ≥SM: 4 列 */
      .thumb-wrapper { flex-basis:25%; max-width:25%; }
    }
    @media (min-width: 768px){ /* ≥MD: 5 列 */
      .thumb-wrapper { flex-basis:20%; max-width:20%; }
    }
    @media (min-width: 992px){ /* ≥LG: 8 列 */
      .thumb-wrapper { flex-basis:12.5%; max-width:12.5%; }
    }
    @media (min-width: 1200px){ /* ≥XL: 10 列（10%） */
      .thumb-wrapper { flex-basis:10%; max-width:10%; }
    }

    .thumb-wrapper img{
      width: 100%;
      aspect-ratio: 2 / 3;        /* 保持与原来一致 */
      object-fit: cover;           /* 保持与原来一致 */
      border-radius: .5rem;        /* Tabler 风格圆角 */
      border: 1px solid var(--tblr-border-color);
      background: var(--tblr-bg-surface);
      display: block;
    }

    .item-tile { display:block; transition: transform .06s ease; }
    .item-tile:active { transform: scale(.985); }

    .chip {
      display:inline-flex; align-items:center; gap:.25rem;
      padding:.15rem .5rem; border:1px solid var(--tblr-border-color);
      border-radius:999px; font-size:.8rem;
      background: var(--tblr-bg-surface);
    }
  </style>
</head>
<body>
<div class="page">
  <div class="page-wrapper">

    <div class="container-xl page-narrow">
      <!-- 页头 -->
      <div class="page-header d-print-none">
        <div class="row align-items-center">
          <div class="col">
            <div class="page-pretitle">浏览</div>
            <h2 class="page-title"><?= h($subcategory['name']) ?> 👕</h2>
            <div class="mt-1 text-secondary">
              所属分类：
              <a class="text-reset" href="category.php?id=<?= (int)$category['id'] ?>"><?= h($category['name']) ?></a>
              <span class="chip ms-2">共 <?= count($items) ?> 件</span>
            </div>
          </div>
          <div class="col-auto ms-auto d-print-none">
            <div class="btn-list">
              <a href="index.php" class="btn btn-outline-secondary">返回首页</a>
            </div>
          </div>
        </div>
      </div>

      <!-- 列表卡片 -->
      <div class="card">
        <div class="card-body">
          <?php if (!$items): ?>
            <div class="text-secondary">该子分类暂时没有衣物。</div>
          <?php else: ?>
            <div class="thumbs-row">
              <?php foreach ($items as $item):
                  $img = $item['image_path'] ?: 'https://via.placeholder.com/160x240?text=No+Image';

                  // 提示信息
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
                <div class="thumb-wrapper">
                  <a href="item.php?id=<?= (int)$item['id'] ?>" class="item-tile" title="<?= h($tooltipText) ?>">
                    <img src="<?= h($img) ?>" alt="<?= h($alt) ?>" loading="lazy">
                  </a>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

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
