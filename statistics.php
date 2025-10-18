<?php
require 'auth.php';
require 'db.php';
date_default_timezone_set('Asia/Shanghai');

/** HTML 转义 */
function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$totalStmt = $pdo->query("SELECT SUM(price) AS total_price FROM clothes");
$totalRow  = $totalStmt->fetch(PDO::FETCH_ASSOC);
$grandTotal = (float)($totalRow['total_price'] ?? 0.0);
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>衣物价格统计</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- 本地 Tabler -->
  <link href="/style/tabler.min.css" rel="stylesheet">
  <script src="/style/tabler.min.js"></script>
  <style>
    .page-narrow {
      max-width: 900px;   /* 控制整体页面宽度 */
      margin: 0 auto;
    }
    .card-body-tight { padding: .75rem 1rem; }
    .row-compact > [class^="col"] { margin-bottom: .5rem; }
    .chip {
      display:inline-flex; align-items:center; gap:.25rem;
      padding:.2rem .6rem; border:1px solid var(--tblr-border-color);
      border-radius:999px; font-size:.85rem;
    }
    .price-strong { font-weight:600; }
    .table thead th { white-space: nowrap; }
    @media (max-width: 576px){
      .card-body-tight { padding: .75rem; }
      .page-title { font-size: 1.125rem; }
      .page-narrow { max-width: 100%; } /* 小屏放宽 */
    }
  </style>
</head>
<body>
<div class="page">
  <div class="page-wrapper">

    <div class="container-xl page-narrow">
      <!-- 头部 -->
      <div class="page-header d-print-none">
        <div class="row align-items-center">
          <div class="col">
            <div class="page-pretitle">统计</div>
            <h2 class="page-title">衣物价格统计</h2>
          </div>
          <div class="col-auto ms-auto d-print-none">
            <div class="btn-list">
              <a href="index.php" class="btn btn-outline-secondary">返回首页</a>
            </div>
          </div>
        </div>
      </div>

      <!-- 汇总卡片 -->
      <div class="row row-compact g-3 mb-3">
        <div class="col-12 col-md-6 col-lg-4">
          <div class="card">
            <div class="card-body card-body-tight">
              <div class="d-flex justify-content-between align-items-center">
                <div class="text-secondary">所有衣物总价</div>
                <div class="price-strong text-danger">
                  <?= number_format($grandTotal, 2) ?> 元
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 分类明细 -->
      <?php foreach ($categories as $cat): ?>
        <?php
        $catStmt = $pdo->prepare("SELECT COUNT(*) AS cnt, SUM(price) AS total FROM clothes WHERE category_id = ?");
        $catStmt->execute([$cat['id']]);
        $catRow   = $catStmt->fetch(PDO::FETCH_ASSOC);
        $catTotal = (float)($catRow['total'] ?? 0.0);
        $catCount = (int)($catRow['cnt'] ?? 0);

        $subStmt = $pdo->prepare("SELECT id, name FROM subcategories WHERE category_id = ? ORDER BY sort_order ASC, id ASC");
        $subStmt->execute([$cat['id']]);
        $subs = $subStmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="card mb-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
              <span class="page-title m-0"><?= h($cat['name']) ?></span>
              <span class="chip">共 <?= $catCount ?> 件</span>
            </div>
            <div class="text-secondary">
              总计：<span class="price-strong"><?= number_format($catTotal, 2) ?></span> 元
            </div>
          </div>
          <div class="card-body card-body-tight">
            <div class="table-responsive">
              <table class="table table-vcenter table-sm">
                <thead>
                  <tr>
                    <th>子分类名称</th>
                    <th style="width:100px">数量</th>
                    <th style="width:120px">总价（元）</th>
                    <th style="width:120px">均价（元）</th>
                  </tr>
                </thead>
                <tbody>
                <?php if ($subs): ?>
                  <?php foreach ($subs as $sub): ?>
                    <?php
                    $subTotalStmt = $pdo->prepare("
                      SELECT COUNT(price) AS cnt, SUM(price) AS total
                      FROM clothes WHERE subcategory_id = ?
                    ");
                    $subTotalStmt->execute([$sub['id']]);
                    $subRow   = $subTotalStmt->fetch(PDO::FETCH_ASSOC);
                    $subTotal = (float)($subRow['total'] ?? 0.0);
                    $subCount = (int)($subRow['cnt'] ?? 0);
                    $avgPrice = $subCount > 0 ? ($subTotal / $subCount) : 0.0;
                    ?>
                    <tr>
                      <td><?= h($sub['name']) ?></td>
                      <td><?= $subCount ?></td>
                      <td><?= number_format($subTotal, 2) ?></td>
                      <td><?= number_format($avgPrice, 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="4" class="text-secondary">该分类暂无子分类。</td>
                  </tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
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
