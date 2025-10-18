<?php
// outfits_index.php
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

/** 简单缩略图生成（默认 222x300；下方调用里按需覆盖） */
function generateThumbnailIfNotExists(string $originalPath, string $thumbPath, int $maxWidth = 222, int $maxHeight = 300): void {
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

/* ---------- 基础数据 ---------- */
$seasons = $pdo->query("SELECT id,name FROM seasons ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

/* ---------- 接收参数 ---------- */
$q           = trim((string)($_GET['q'] ?? ''));
$seasonIds   = arrint($_GET['season'] ?? []); // 多选：season[]=1&season[]=3
$page        = max(1, (int)($_GET['p'] ?? 1));
$perPage     = 24;

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
  $qs = $_SERVER['QUERY_STRING'] ?? '';
  header('Location: outfits_index.php' . ($qs ? ('?' . $qs) : ''));
  exit;
}

/* ---------- 动态图片列 ---------- */
$imgCandidates = ['image_path','image','photo','img','picture'];
$imgCol = null;
foreach ($imgCandidates as $cand) { if (tableHasColumn($pdo,'clothes',$cand)) { $imgCol=$cand; break; } }
$imgSelect = $imgCol ? "c.`$imgCol` AS img" : "NULL AS img";

/* ---------- 构建 where ---------- */
$where = []; $params = [];

if ($q !== '') {
  $where[] = "o.name LIKE ?";
  $params[] = "%{$q}%";
}
if (!empty($seasonIds)) {
  $in = implode(',', array_fill(0, count($seasonIds), '?'));
  $where[] = "EXISTS (SELECT 1 FROM outfit_seasons os WHERE os.outfit_id = o.id AND os.season_id IN ($in))";
  array_push($params, ...$seasonIds);
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ---------- 总数 & 分页 ---------- */
$sqlCount = "SELECT COUNT(*) FROM outfits o $whereSql";
$stc = $pdo->prepare($sqlCount);
$stc->execute($params);
$total = (int)$stc->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

/* ---------- 查询当前页 outfits ---------- */
$sqlList = "
  SELECT o.id, o.name, o.notes, o.created_at, o.updated_at
  FROM outfits o
  $whereSql
  ORDER BY o.updated_at DESC, o.id DESC
  LIMIT $perPage OFFSET $offset
";
$st = $pdo->prepare($sqlList);
$st->execute($params);
$outfits = $st->fetchAll(PDO::FETCH_ASSOC);
$outfitIds = array_map(fn($r)=> (int)$r['id'], $outfits);

/* ---------- 查询季节标签 ---------- */
$seasonsMap = [];
if ($outfitIds) {
  $in = implode(',', array_fill(0, count($outfitIds), '?'));
  $sql = "SELECT os.outfit_id, s.id, s.name
          FROM outfit_seasons os
          JOIN seasons s ON s.id = os.season_id
          WHERE os.outfit_id IN ($in)
          ORDER BY s.id";
  $stm = $pdo->prepare($sql);
  $stm->execute($outfitIds);
  while ($row = $stm->fetch(PDO::FETCH_ASSOC)) {
    $oid = (int)$row['outfit_id'];
    $seasonsMap[$oid][] = ['id'=>(int)$row['id'], 'name'=>$row['name']];
  }
}

/* ---------- 查询卡片展示用的单品缩略图（每套取前 4 件） ---------- */
$thumbsMap = [];
if ($outfitIds) {
  $in = implode(',', array_fill(0, count($outfitIds), '?'));
  $sql = "SELECT oi.outfit_id, c.name, $imgSelect
          FROM outfit_items oi
          JOIN clothes c ON c.id = oi.clothes_id
          WHERE oi.outfit_id IN ($in)
          ORDER BY oi.order_index ASC, oi.id ASC";
  $sti = $pdo->prepare($sql);
  $sti->execute($outfitIds);
  while ($row = $sti->fetch(PDO::FETCH_ASSOC)) {
    $oid = (int)$row['outfit_id'];
    if (!isset($thumbsMap[$oid])) $thumbsMap[$oid] = [];
    if (count($thumbsMap[$oid]) < 4) {
      $thumbsMap[$oid][] = ['img'=>$row['img'] ?? null, 'name'=>$row['name']];
    }
  }
}

/* ---------- 生成查询字符串 ---------- */
function qs(array $keep): string {
  $base = [];
  foreach ($keep as $k => $v) {
    if (is_array($v)) {
      foreach ($v as $vv) $base[] = urlencode($k) . '[]=' . urlencode((string)$vv);
    } else if ($v !== '' && $v !== null) {
      $base[] = urlencode($k) . '=' . urlencode((string)$v);
    }
  }
  return $base ? ('?' . implode('&', $base)) : '';
}

?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>穿搭库</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="/style/tabler.min.css" rel="stylesheet">
  <script src="/style/tabler.min.js"></script>
  <style>
    .page { padding:12px; }
    .grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px,1fr)); gap:.75rem; }
    .thumb { width:100%; aspect-ratio:1/1; object-fit:cover; border-radius:.5rem; border:1px solid var(--tblr-border-color); background:#fff; }
    .badge { --tblr-badge-color: var(--tblr-primary); }
    .mini-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:.35rem; }
    .card-title { font-size:1rem; margin:0; }

    /* —— 筛选条样式：不溢出，自动换行 —— */
    .sticky-top { position: sticky; top: 0; z-index: 10; background: var(--tblr-bg-surface); padding: .5rem 0; }
    .filter-card { box-shadow: 0 2px 12px rgba(0,0,0,.04); }
    .season-wrap { display:flex; flex-wrap:wrap; gap:.5rem; }
    .toolbar-actions { display:flex; gap:.5rem; flex-wrap:wrap; }
    .toolbar-actions .btn { flex: 1 1 auto; }
    @media (min-width: 992px){ /* lg 起尽量单行排列 */
      .toolbar-actions { flex-wrap: nowrap; }
      .toolbar-actions .btn { flex: 0 0 auto; }
    }

    .season-badges { display:flex; gap:.35rem; flex-wrap:wrap; }
    .btn-icon { padding: .25rem .5rem; }
  </style>
</head>
<body>
<div class="page container-xl">

  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="fs-3">👗 穿搭库</div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="index.php">返回首页</a>
      <a class="btn btn-primary" href="outfits_create.php">➕ 创建穿搭</a>
    </div>
  </div>

  <!-- ✅ 筛选条：自适应不出范围 -->
  <form method="get" class="sticky-top" id="filterForm">
    <div class="card filter-card">
      <div class="card-body">
        <div class="row g-3 align-items-end">
          <!-- 季节（占据更多空间，换行友好） -->
          <div class="col-12 col-lg-7">
            <label class="form-label">按季节（多选）</label>
            <div class="season-wrap">
              <?php foreach ($seasons as $s):
                $sid = (int)$s['id']; $checked = in_array($sid, $seasonIds, true);
              ?>
              <label class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="season[]" value="<?= $sid ?>" <?= $checked?'checked':'' ?>>
                <span class="form-check-label"><?= h($s['name']) ?></span>
              </label>
              <?php endforeach; ?>
            </div>
            <div class="form-text">不选不限季节；选择为“任意匹配”。</div>
          </div>

          <!-- 关键字 -->
          <div class="col-12 col-sm-6 col-lg-3">
            <label class="form-label">关键字</label>
            <input type="text" class="form-control" name="q" value="<?= h($q) ?>" placeholder="按名称搜索">
          </div>

          <!-- 操作按钮（自动换行，不会溢出） -->
          <div class="col-12 col-sm-6 col-lg-2">
            <label class="form-label d-none d-lg-block">&nbsp;</label>
            <div class="toolbar-actions">
              <button class="btn btn-outline-primary" type="submit">🔎 筛选</button>
              <a class="btn btn-light" href="outfits_index.php">🧹 重置</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </form>

  <!-- 统计/结果提示 -->
  <div class="my-2 text-secondary">
    共找到 <span class="fw-bold"><?= $total ?></span> 套穿搭
    <?php if (!empty($seasonIds)): ?>
      （季节：<?php
        $mapName = [];
        foreach ($seasons as $s) $mapName[(int)$s['id']] = $s['name'];
        $names = array_map(fn($id)=> $mapName[$id] ?? ('#'.$id), $seasonIds);
        echo h(implode('、', $names));
      ?>）
    <?php endif; ?>
  </div>

  <!-- 网格卡片 -->
  <div class="grid">
    <?php if (!$outfits): ?>
      <div class="card p-4"><div class="text-secondary">暂无数据，试试更换筛选条件。</div></div>
    <?php endif; ?>

    <?php
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    $blank = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMB/axrVqkAAAAASUVORK5CYII=';

    foreach ($outfits as $o):
      $oid = (int)$o['id'];
      $badges = $seasonsMap[$oid] ?? [];
      $thumbs = $thumbsMap[$oid] ?? [];

      // 2x2 缩略图，按 300x300 生成
      $imgs = [];
      foreach ($thumbs as $t) {
        $imgSrc = $blank;
        if (!empty($t['img'])) {
          $originalWeb  = '/' . ltrim($t['img'], '/');
          $originalFile = $docRoot . $originalWeb;
          $filename     = basename($originalWeb);
          $thumbWeb     = '/thumbs/' . $filename . '_300x300.jpg';
          $thumbFile    = $docRoot . $thumbWeb;
          if (is_file($originalFile)) {
            generateThumbnailIfNotExists($originalFile, $thumbFile, 300, 300);
            $imgSrc = is_file($thumbFile) ? $thumbWeb : $originalWeb;
          }
        }
        $imgs[] = $imgSrc;
      }
      while (count($imgs) < 4) $imgs[] = $blank;
    ?>
    <div class="card">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <div class="pe-2">
            <div class="card-title fw-bold m-0"><?= h($o['name']) ?></div>
            <?php if ($o['notes']): ?>
              <div class="text-secondary small"><?= h($o['notes']) ?></div>
            <?php endif; ?>
          </div>
          <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary btn-icon" href="outfits_view.php?id=<?= $oid ?>">查看</a>
            <a class="btn btn-sm btn-outline-primary btn-icon" href="outfits_edit.php?id=<?= $oid ?>">编辑</a>
            <form method="post" onsubmit="return confirm('确定删除该穿搭吗？此操作不可恢复');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $oid ?>">
              <?php foreach ($seasonIds as $sid): ?>
                <input type="hidden" name="season[]" value="<?= (int)$sid ?>">
              <?php endforeach; ?>
              <input type="hidden" name="q" value="<?= h($q) ?>">
              <input type="hidden" name="p" value="<?= (int)$page ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger btn-icon">删除</button>
            </form>
          </div>
        </div>

        <div class="mini-grid mb-2">
          <img class="thumb" src="<?= h($imgs[0]) ?>" alt="">
          <img class="thumb" src="<?= h($imgs[1]) ?>" alt="">
          <img class="thumb" src="<?= h($imgs[2]) ?>" alt="">
          <img class="thumb" src="<?= h($imgs[3]) ?>" alt="">
        </div>

        <div class="season-badges">
          <?php if ($badges): foreach ($badges as $b): ?>
            <span class="badge"><?= h($b['name']) ?></span>
          <?php endforeach; else: ?>
            <span class="text-secondary small">季节不限</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-footer">
        <div class="text-secondary small">更新于 <?= h(date('Y-m-d H:i', strtotime($o['updated_at'] ?? $o['created_at']))) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- 分页 -->
  <?php if ($pages > 1): 
    $baseKeep = ['q'=>$q, 'season'=>$seasonIds];
  ?>
  <div class="d-flex justify-content-center my-3">
    <ul class="pagination">
      <?php
      $renderLink = function(int $p, string $label, bool $active=false, bool $disabled=false) use ($baseKeep){
        $qs = qs(array_merge($baseKeep, ['p'=>$p]));
        $cls = 'page-item';
        if ($active) $cls .= ' active';
        if ($disabled) $cls .= ' disabled';
        echo '<li class="'.$cls.'"><a class="page-link" href="'.($disabled?'#':$qs).'">'.h($label).'</a></li>';
      };
      $renderLink(max(1,$page-1), '«', false, $page<=1);
      for ($i=max(1,$page-2); $i<=min($pages,$page+2); $i++) {
        $renderLink($i, (string)$i, $i===$page, false);
      }
      $renderLink(min($pages,$page+1), '»', false, $page>=$pages);
      ?>
    </ul>
  </div>
  <?php endif; ?>

</div>
</body>
</html>
