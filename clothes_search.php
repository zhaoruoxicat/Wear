<?php
// clothes_search.php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
date_default_timezone_set('Asia/Shanghai');

function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function arrint($a){ return array_values(array_filter(array_map('intval', (array)$a))); }

function tableHasColumn(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $st->execute([$table, $col]);
  return (bool)$st->fetchColumn();
}

/* 与首页一致的缩略图生成（不放大原图） */
function generateThumbnailIfNotExists(string $originalPath, string $thumbPath, int $maxWidth = 222, int $maxHeight = 300): void {
  if (!is_file($originalPath)) return;
  if (is_file($thumbPath)) return;
  $info = @getimagesize($originalPath);
  if (!$info) return;
  switch ($info['mime']) {
    case 'image/jpeg': $src = imagecreatefromjpeg($originalPath); break;
    case 'image/png':  $src = imagecreatefrompng($originalPath);  break;
    case 'image/gif':  $src = imagecreatefromgif($originalPath);  break;
    default: return;
  }
  $sw = imagesx($src); $sh = imagesy($src);
  $scale = min($maxWidth / $sw, $maxHeight / $sh, 1);
  $nw = max(1, (int)($sw * $scale));
  $nh = max(1, (int)($sh * $scale));
  if (!is_dir(dirname($thumbPath))) @mkdir(dirname($thumbPath), 0755, true);
  $thumb = imagecreatetruecolor($nw, $nh);
  imagecopyresampled($thumb, $src, 0,0,0,0, $nw,$nh, $sw,$sh);
  imagejpeg($thumb, $thumbPath, 85);
  imagedestroy($src); imagedestroy($thumb);
}

/* 基础数据 */
$categories    = $pdo->query("SELECT id,name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$subcategories = $pdo->query("SELECT id,name,category_id FROM subcategories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$seasons       = $pdo->query("SELECT id,name FROM seasons ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

/* 筛选参数 */
$categoryId    = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$subcategoryId = isset($_GET['subcategory_id']) ? (int)$_GET['subcategory_id'] : 0;
$seasonIds     = arrint($_GET['seasons'] ?? []);

/* 条件 */
$where = []; $params = [];
if ($categoryId > 0)    { $where[] = "c.category_id = ?";    $params[] = $categoryId; }
if ($subcategoryId > 0) { $where[] = "c.subcategory_id = ?"; $params[] = $subcategoryId; }
$filterJoin = '';
if (!empty($seasonIds)) {
  $ph = implode(',', array_fill(0, count($seasonIds), '?'));
  $filterJoin = "INNER JOIN clothes_seasons csf ON csf.clothes_id = c.id AND csf.season_id IN ($ph)";
  $params = array_merge($seasonIds, $params);
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* 动态图片列 -> 统一别名 img */
$imgCandidates = ['image_path','image','photo','img','picture'];
$imgCol = null;
foreach ($imgCandidates as $cand) {
  if (tableHasColumn($pdo, 'clothes', $cand)) { $imgCol = $cand; break; }
}
$imgSelect = $imgCol ? "c.`$imgCol` AS img," : "NULL AS img,";

/* 查询 */
$sql = "
SELECT 
  c.id, c.name, c.category_id, c.subcategory_id,
  $imgSelect
  cat.name AS category_name,
  sub.name AS subcategory_name,
  GROUP_CONCAT(DISTINCT s.name ORDER BY s.id SEPARATOR '、') AS season_names
FROM clothes c
JOIN categories   cat ON cat.id = c.category_id
JOIN subcategories sub ON sub.id = c.subcategory_id
LEFT JOIN clothes_seasons cs ON cs.clothes_id = c.id
LEFT JOIN seasons s ON s.id = cs.season_id
$filterJoin
$whereSql
GROUP BY c.id
ORDER BY c.id DESC
LIMIT 300
";
$st = $pdo->prepare($sql);
$st->execute($params);
$list = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <title>衣物筛选</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="/style/tabler.min.css" rel="stylesheet">
  <script src="/style/tabler.min.js"></script>
  <style>
    .page { padding:12px; }
    .filters { gap:.5rem; }

    /* 卡片缩小：最小宽度 140px，间距更紧凑 */
    .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(110px,1fr)); gap:.5rem; }
    .card { border-radius:12px; overflow:hidden; }
    .thumb {
      width:100%;
      aspect-ratio:111/150;
      object-fit:cover;
      border:1px solid var(--tblr-border-color);
      background:var(--tblr-bg-surface);
      display:block;
      border-radius:.4rem;
    }

    .card .card-body { padding:.5rem; }
    .fw-bold { font-size:.9rem; line-height:1.2; }
    .text-secondary { font-size:.8rem; }
    .season-badge{ font-size:11px; padding:1px 6px; border-radius:999px; background:var(--tblr-bg-surface,#f6f8fb); }
    .link-plain { text-decoration:none; color:inherit; }
  </style>
</head>
<body>
<div class="page container-xl">

  <!-- 顶部 -->
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div class="fs-3">🔎 衣物筛选</div>
    <div class="btn-list">
      <a class="btn btn-primary btn-sm" href="index.php">返回首页</a>

    </div>
  </div>

  <!-- 筛选 -->
  <form class="card mb-3" method="get">
    <div class="card-body">
      <div class="row g-3 filters">
        <div class="col-12 col-md-3">
          <label class="form-label">主分类</label>
          <select class="form-select" name="category_id" id="category_id">
            <option value="0">全部分类</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $categoryId===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label">子分类</label>
          <select class="form-select" name="subcategory_id" id="subcategory_id">
            <option value="0">全部子分类</option>
            <?php foreach ($subcategories as $s): ?>
              <option value="<?= (int)$s['id'] ?>" data-category="<?= (int)$s['category_id'] ?>" <?= $subcategoryId===(int)$s['id']?'selected':'' ?>>
                <?= h($s['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">选择主分类后，这里将只展示对应子分类。</div>
        </div>
        <div class="col-12">
          <label class="form-label">季节（多选，任一匹配即可）</label>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach ($seasons as $s): $sid=(int)$s['id']; ?>
              <label class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="seasons[]" value="<?= $sid ?>" <?= in_array($sid,$seasonIds,true)?'checked':''; ?>>
                <span class="form-check-label"><?= h($s['name']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <div class="card-footer d-flex gap-2">
      <button type="submit" class="btn btn-primary">🔍 开始筛选</button>
      <a class="btn btn-light" href="<?= h(strtok($_SERVER['REQUEST_URI'],'?')) ?>">🧹 重置</a>
    </div>
  </form>

  <!-- 结果 -->
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fs-5">共找到 <strong><?= count($list) ?></strong> 件</div>
  </div>

  <div class="grid">
    <?php if (!$list): ?>
      <div class="card"><div class="card-body text-secondary">无匹配结果，请调整筛选条件。</div></div>
    <?php else: ?>
      <?php
      $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
      foreach ($list as $row):
        $viewUrl = 'item.php?id=' . (int)$row['id'];
        $img = 'https://via.placeholder.com/111x150?text=No+Image';
        $imgPath = $row['img'] ?? null;
        if ($imgPath) {
          $originalWeb  = '/' . ltrim($imgPath, '/');
          $originalFile = $docRoot . $originalWeb;
          $filename     = basename($originalWeb);
          $thumbWeb     = '/thumbs/' . $filename . '_222x300.jpg';
          $thumbFile    = $docRoot . $thumbWeb;
          if (is_file($originalFile)) {
            generateThumbnailIfNotExists($originalFile, $thumbFile, 222, 300);
            if (is_file($thumbFile)) $img = $thumbWeb; else $img = $originalWeb;
          }
        }
      ?>
        <div class="card">
          <a href="<?= h($viewUrl) ?>" aria-label="查看 <?= h($row['name']) ?>">
            <img class="thumb" src="<?= h($img) ?>" alt="<?= h($row['name']) ?>" loading="lazy">
          </a>
          <div class="card-body">
            <div class="fw-bold mb-1">
              <a class="link-plain" href="<?= h($viewUrl) ?>"> <?= h($row['name']) ?></a>
            </div>
            <div class="text-secondary mb-2">
              <?= h($row['category_name']) ?> · <?= h($row['subcategory_name']) ?>
            </div>
            <?php if (!empty($row['season_names'])): ?>
              <div class="d-flex flex-wrap gap-1">
                <?php foreach (explode('、', (string)$row['season_names']) as $sn): ?>
                  <span class="season-badge"><?= h($sn) ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<script>
(function(){
  const catSel=document.getElementById('category_id');
  const subSel=document.getElementById('subcategory_id');
  function applyFilter(){
    const cid=parseInt(catSel.value||'0',10);
    let keep=false;
    [...subSel.options].forEach(opt=>{
      if(opt.value==='0'){ opt.hidden=false; return; }
      const ok=(cid===0)||(parseInt(opt.dataset.category||'0',10)===cid);
      opt.hidden=!ok;
      if(!opt.hidden&&opt.selected) keep=true;
    });
    if(!keep&&cid!==0) subSel.value='0';
  }
  catSel.addEventListener('change',applyFilter);
  applyFilter();
})();
</script>
</body>
</html>
