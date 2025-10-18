<?php
// outfits_view.php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
date_default_timezone_set('Asia/Shanghai');

/* ---------- Utils ---------- */
function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function arrint($a){ return array_values(array_filter(array_map('intval', (array)$a))); }

function tableHasColumn(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $st->execute([$table, $col]);
  return (bool)$st->fetchColumn();
}

/** 简单缩略图生成（与其它页面保持一致） */
function generateThumbnailIfNotExists(string $originalPath, string $thumbPath, int $maxWidth = 400, int $maxHeight = 400): void {
  if (!is_file($originalPath) || is_file($thumbPath)) return;
  $info = @getimagesize($originalPath); if (!$info) return;
  switch ($info['mime']) {
    case 'image/jpeg': $src = imagecreatefromjpeg($originalPath); break;
    case 'image/png':  $src = imagecreatefrompng($originalPath);  break;
    case 'image/gif':  $src = imagecreatefromgif($originalPath);  break;
    default: return;
  }
  $sw=imagesx($src); $sh=imagesy($src);
  $scale=min($maxWidth/$sw, $maxHeight/$sh, 1);
  $nw=max(1,(int)($sw*$scale)); $nh=max(1,(int)($sh*$scale));
  if (!is_dir(dirname($thumbPath))) @mkdir(dirname($thumbPath),0755,true);
  $dst=imagecreatetruecolor($nw,$nh);
  imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$sw,$sh);
  imagejpeg($dst,$thumbPath,85);
  imagedestroy($src); imagedestroy($dst);
}

/* ---------- 接收参数 ---------- */
$outfitId = max(0, (int)($_GET['id'] ?? 0));
if ($outfitId <= 0) {
  http_response_code(400);
  echo '缺少参数：id';
  exit;
}

/* ---------- 删除处理（POST） ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  $delId = (int)($_POST['id'] ?? 0);
  if ($delId > 0) {
    try {
      $pdo->beginTransaction();
      $pdo->prepare("DELETE FROM outfit_items WHERE outfit_id = ?")->execute([$delId]);
      $pdo->prepare("DELETE FROM outfit_seasons WHERE outfit_id = ?")->execute([$delId]);
      $pdo->prepare("DELETE FROM outfits WHERE id = ?")->execute([$delId]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
    }
  }
  header('Location: outfits_index.php');
  exit;
}

/* ---------- 动态图片列 ---------- */
$imgCandidates = ['image_path','image','photo','img','picture'];
$imgCol = null;
foreach ($imgCandidates as $cand) { if (tableHasColumn($pdo,'clothes',$cand)) { $imgCol=$cand; break; } }
$imgSelect = $imgCol ? "c.`$imgCol` AS img" : "NULL AS img";

/* ---------- 查询穿搭主体 ---------- */
$st = $pdo->prepare("SELECT id, name, notes, created_at, updated_at FROM outfits WHERE id = ? LIMIT 1");
$st->execute([$outfitId]);
$outfit = $st->fetch(PDO::FETCH_ASSOC);

if (!$outfit) {
  http_response_code(404);
  echo '未找到该穿搭';
  exit;
}

/* ---------- 查询季节标签 ---------- */
$st = $pdo->prepare("SELECT s.id, s.name
                     FROM outfit_seasons os
                     JOIN seasons s ON s.id = os.season_id
                     WHERE os.outfit_id = ?
                     ORDER BY s.id");
$st->execute([$outfitId]);
$seasonTags = $st->fetchAll(PDO::FETCH_ASSOC);

/* ---------- 查询该穿搭的衣物（按 order_index） ---------- */
$sqlItems = "
  SELECT oi.id AS oi_id, oi.order_index, c.id AS clothes_id, c.name AS clothes_name,
         $imgSelect, cat.name AS category_name
  FROM outfit_items oi
  JOIN clothes c ON c.id = oi.clothes_id
  JOIN categories cat ON cat.id = c.category_id
  WHERE oi.outfit_id = ?
  ORDER BY oi.order_index ASC, oi.id ASC
";
$sti = $pdo->prepare($sqlItems);
$sti->execute([$outfitId]);
$items = $sti->fetchAll(PDO::FETCH_ASSOC);

/* ---------- 预处理图片 ---------- */
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
function itemThumb(array $row, string $docRoot): string {
  $img = 'https://via.placeholder.com/400x400?text=No+Image';
  if (!empty($row['img'])) {
    $originalWeb  = '/' . ltrim($row['img'], '/');
    $originalFile = $docRoot . $originalWeb;
    $filename     = basename($originalWeb);
    $thumbWeb     = '/thumbs/' . $filename . '_400x400.jpg';
    $thumbFile    = $docRoot . $thumbWeb;
    if (is_file($originalFile)) {
      generateThumbnailIfNotExists($originalFile, $thumbFile, 400, 400);
      $img = is_file($thumbFile) ? $thumbWeb : $originalWeb;
    }
  }
  return $img;
}

?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title><?= h($outfit['name']) ?> - 穿搭详情</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="/style/tabler.min.css" rel="stylesheet">
  <script src="/style/tabler.min.js"></script>
  <style>
    .page { padding:12px; }
    .grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(140px,1fr)); gap:.75rem; }
.thumb { width:100%; height:200px; object-fit:cover; border-radius:.6rem; border:1px solid var(--tblr-border-color); background:var(--tblr-bg-surface); }
    .muted { color: var(--tblr-secondary); font-size:.85rem; }
    .badge { --tblr-badge-color: var(--tblr-primary); }
    .header-actions .btn { margin-left:.4rem; }
    @media print{
      .no-print { display:none !important; }
      body { background:#fff; }
    }
  </style>
</head>
<body>
<div class="page container-xl">

  <!-- 头部 -->
  <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
      <div class="fs-3 mb-1"><?= h($outfit['name']) ?></div>
      <?php if ($outfit['notes']): ?>
        <div class="muted mb-2">备注：<?= h($outfit['notes']) ?></div>
      <?php endif; ?>
      <div class="d-flex flex-wrap gap-2">
        <?php if ($seasonTags): foreach ($seasonTags as $s): ?>
          <span class="badge"><?= h($s['name']) ?></span>
        <?php endforeach; else: ?>
          <span class="muted">季节不限</span>
        <?php endif; ?>
      </div>
      <div class="muted mt-2">
        更新于 <?= h(date('Y-m-d H:i', strtotime($outfit['updated_at'] ?? $outfit['created_at']))) ?>
      </div>
    </div>

    <div class="header-actions no-print">
      <a class="btn btn-outline-secondary" href="outfits_index.php">返回列表</a>
      <a class="btn btn-outline-primary" href="outfits_create.php">➕ 新建穿搭</a>
      <!-- 编辑入口 -->
      <a class="btn btn-primary" href="outfits_edit.php?id=<?= (int)$outfit['id'] ?>">编辑</a>
      <form method="post" class="d-inline" onsubmit="return confirm('确定删除该穿搭吗？此操作不可恢复');">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int)$outfit['id'] ?>">
        <button type="submit" class="btn btn-outline-danger">删除</button>
      </form>
      <button class="btn btn-light" onclick="window.print()">🖨️ 打印</button>
    </div>
  </div>

  <!-- 清单 -->
  <div class="card">
    <div class="card-body">
      <?php if (!$items): ?>
        <div class="text-secondary">这套穿搭还没有添加衣物。</div>
      <?php else: ?>
        <div class="grid">
          <?php foreach ($items as $row): 
            $img = itemThumb($row, $docRoot);
            $cid = (int)$row['clothes_id'];
          ?>
          <div class="card">
            <div class="p-2">
              <!-- ✅ 点击缩略图跳转衣物详情 -->
              <a href="item.php?id=<?= $cid ?>">
                <img class="thumb" src="<?= h($img) ?>" alt="<?= h($row['clothes_name']) ?>">
              </a>
            </div>
            <div class="card-body pt-1">
              <div class="fw-bold">
                <a href="item.php?id=<?= $cid ?>" class="text-reset text-decoration-none">
                  <?= h($row['clothes_name']) ?>
                </a>
              </div>
              <div class="muted"><?= h($row['category_name']) ?></div>
              <?php if (isset($row['order_index'])): ?>
                <div class="muted">序号：<?= (int)$row['order_index'] ?></div>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>
</body>
</html>
