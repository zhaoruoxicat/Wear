<?php
// outfits_create.php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
date_default_timezone_set('Asia/Shanghai');

/* ==== Utils ==== */
function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function arrint($a){ return array_values(array_filter(array_map('intval', (array)$a))); }

function tableHasColumn(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $st->execute([$table, $col]);
  return (bool)$st->fetchColumn();
}

/* 生成 180x240 缩略图（更小更紧凑） */
function generateThumbnailIfNotExists(string $originalPath, string $thumbPath, int $maxWidth = 180, int $maxHeight = 240): void {
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

/* ==== 基础数据 ==== */
$seasons      = $pdo->query("SELECT id,name FROM seasons ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$categories   = $pdo->query("SELECT * FROM categories ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$subcategories= $pdo->query("SELECT * FROM subcategories ORDER BY category_id,id")->fetchAll(PDO::FETCH_ASSOC);
$subsByCat=[]; foreach($subcategories as $s){ $subsByCat[$s['category_id']][]=$s; }

/* ==== 动态图片列（统一别名 img） ==== */
$imgCandidates = ['image_path','image','photo','img','picture'];
$imgCol = null;
foreach ($imgCandidates as $cand) { if (tableHasColumn($pdo,'clothes',$cand)) { $imgCol=$cand; break; } }
$imgSelect = $imgCol ? "c.`$imgCol` AS img" : "NULL AS img";

/* ==== 拉取所有衣物（右侧一次性渲染，左侧点子分类仅前端过滤） ==== */
$sqlAll = "SELECT c.id,c.name,c.category_id,c.subcategory_id,$imgSelect,
                  cat.name AS cat_name, sub.name AS sub_name
           FROM clothes c
           JOIN categories cat ON cat.id=c.category_id
           JOIN subcategories sub ON sub.id=c.subcategory_id
           ORDER BY c.id DESC";
$allClothes = $pdo->query($sqlAll)->fetchAll(PDO::FETCH_ASSOC);

/* ==== 提交表单（创建组合） ==== */
$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $name   = trim((string)($_POST['name'] ?? ''));
  $notes  = trim((string)($_POST['notes'] ?? ''));
  $seasonIds = arrint($_POST['seasons'] ?? []);
  $selectedClothes = arrint($_POST['clothes'] ?? []);
  $orders = array_map('intval', $_POST['order'] ?? []);

  if ($name==='')            $errors[]='请填写组合名称';
  if (empty($selectedClothes)) $errors[]='请至少选择一件衣物';

  if (!$errors) {
    try{
      $pdo->beginTransaction();
      $pdo->prepare("INSERT INTO outfits(name,notes) VALUES(?,?)")->execute([$name,$notes]);
      $oid = (int)$pdo->lastInsertId();

      if ($seasonIds) {
        $insS = $pdo->prepare("INSERT INTO outfit_seasons(outfit_id,season_id) VALUES(?,?)");
        foreach($seasonIds as $sid){ $insS->execute([$oid,$sid]); }
      }

      $insI = $pdo->prepare("INSERT INTO outfit_items(outfit_id,clothes_id,order_index) VALUES(?,?,?)");
      $i=0;
      foreach($selectedClothes as $cid){
        $ord = isset($orders[$cid]) ? max(0,(int)$orders[$cid]) : $i;
        $insI->execute([$oid,$cid,$ord]);
        $i++;
      }

      $pdo->commit();
      header('Location: outfits_view.php?id='.$oid); exit;
    }catch(Throwable $e){
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[]='保存失败：'.$e->getMessage();
    }
  }
}

/* ==== 缩略图函数（占位与路径同步 180x240） ==== */
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
function clothesThumb(array $row, string $docRoot): string {
  $img = 'https://via.placeholder.com/180x240?text=No+Image';
  if (!empty($row['img'])) {
    $originalWeb  = '/' . ltrim($row['img'], '/');
    $originalFile = $docRoot . $originalWeb;
    $filename     = basename($originalWeb);
    $thumbWeb     = '/thumbs/' . $filename . '_180x240.jpg';
    $thumbFile    = $docRoot . $thumbWeb;
    if (is_file($originalFile)) {
      generateThumbnailIfNotExists($originalFile, $thumbFile, 180, 240);
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
  <title>创建穿搭组合</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="/style/tabler.min.css" rel="stylesheet">
  <script src="/style/tabler.min.js"></script>
  <style>
    .page { padding:12px; }

    /* 缩略图更小：180x240 */
    .thumb{width:100%;height:240px;object-fit:cover;border-radius:.5rem;border:1px solid var(--tblr-border-color);background:var(--tblr-bg-surface);}

    .muted{font-size:.85rem;color:var(--tblr-secondary);}
    .title{font-size:.92rem;margin:.35rem 0 .15rem;}
    .order-input{width:3.5rem;}
    .check-wrap{display:flex;align-items:center;gap:.4rem;margin-top:.25rem;}

    /* 左侧分类区域：子分类三列并排的栅格 */
    .subs-grid{
      display:grid;
      grid-template-columns:repeat(3,minmax(0,1fr));
      gap:.4rem .5rem;
      margin-top:.35rem;
    }
    .sub-link{
      display:block;
      padding:.25rem .5rem;
      border:1px solid var(--tblr-border-color);
      border-radius:.4rem;
      text-decoration:none;
      color:inherit;
      font-size:.875rem;
      line-height:1.25rem;
      background:var(--tblr-bg-surface);
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .sub-link.active{
      background:var(--tblr-primary);
      color:#fff;
      border-color:var(--tblr-primary);
    }

    /* “显示全部”按钮样式与子分类一致 */
    .show-all{
      display:block;
      padding:.35rem .6rem;
      border:1px dashed var(--tblr-border-color);
      border-radius:.4rem;
      text-decoration:none;
      color:inherit;
      font-size:.9rem;
      background:var(--tblr-bg-surface);
    }
    .show-all.active{
      border-style:solid;
      background:var(--tblr-primary);
      color:#fff;
      border-color:var(--tblr-primary);
    }

    /* 右侧网格更密：中屏起每行 5 列 */
    @media (min-width: 768px){
      #clothes-grid{ --tblr-gutter-x:.5rem; --tblr-gutter-y:.5rem; }
    }
  </style>
</head>
<body>
<div class="page container-xl">

  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="fs-3">👗 创建穿搭组合</div>
    <div class="d-flex gap-2">
      <a href="index.php" class="btn btn-light">返回首页</a>
    </div>
  </div>

  <?php if($errors): ?>
    <div class="alert alert-danger">
      <div class="fw-bold mb-1">保存失败</div>
      <ul class="mb-0"><?php foreach($errors as $e): ?><li><?=h($e)?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form method="post" id="createForm">
    <!-- 基本信息 -->
    <div class="card mb-3">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12 col-md-4">
            <label class="form-label">组合名称 <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" maxlength="120" required placeholder="例如：周末休闲风">
          </div>
          <div class="col-12 col-md-8">
            <label class="form-label">备注</label>
            <input type="text" name="notes" class="form-control" placeholder="可写场景、灵感等">
          </div>
          <div class="col-12">
            <label class="form-label">适合季节（可多选，任意匹配即可）</label>
            <div class="d-flex flex-wrap" style="gap:.5rem;">
              <?php foreach($seasons as $s): ?>
                <label class="form-check form-check-inline">
                  <input class="form-check-input" type="checkbox" name="seasons[]" value="<?=$s['id']?>">
                  <span class="form-check-label"><?=h($s['name'])?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <div class="form-text">不选表示季节不限。</div>
          </div>
        </div>
      </div>
    </div>

    <!-- 包含的衣物：左侧分类/子分类（3列并排），右侧缩略图（更小更密） -->
    <div class="card">
      <div class="card-body">
        <div class="row">
          <!-- 左侧：分类 + 子分类栅格 -->
          <div class="col-12 col-md-3 mb-3 mb-md-0">
            <div id="category-panel">
              <a href="#" class="show-all mb-2 active" data-sub="all">显示全部</a>
              <?php foreach($categories as $cat): ?>
                <div class="mb-3">
                  <div class="fw-bold small"><?=h($cat['name'])?></div>
                  <?php if(!empty($subsByCat[$cat['id']])): ?>
                    <div class="subs-grid" data-cat="<?=$cat['id']?>">
                      <?php foreach($subsByCat[$cat['id']] as $sub): ?>
                        <a href="#" class="sub-link" data-sub="<?=$sub['id']?>"><?=h($sub['name'])?></a>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- 右侧：缩略图网格勾选（更小：180x240；中屏起每行5列） -->
          <div class="col-12 col-md-9">
            <div class="row row-cols-2 row-cols-sm-3 row-cols-md-5 g-2" id="clothes-grid">
              <?php foreach($allClothes as $c):
                $cid=(int)$c['id'];
                $img=clothesThumb($c,$docRoot);
              ?>
              <div class="col clothes-card" data-sub="<?=$c['subcategory_id']?>">
                <label class="card h-100 position-relative">
                  <input type="checkbox" class="form-check-input position-absolute m-2"
                         name="clothes[]" value="<?=$cid?>">
                  <img src="<?=h($img)?>" class="thumb card-img-top" alt="">
                  <div class="card-body p-2">
                    <div class="title text-truncate"><?=h($c['name'])?></div>
                    <div class="muted text-truncate"><?=h($c['cat_name'].'/'.$c['sub_name'])?></div>
                    <div class="check-wrap">
                      <span class="muted">序号</span>
                      <input type="number" class="form-control form-control-sm order-input"
                             name="order[<?=$cid?>]" placeholder="0,1,2...">
                    </div>
                  </div>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div> <!-- /row -->
      </div>
    </div>

    <!-- 提交 -->
    <div class="mt-3 d-flex gap-2">
      <button type="submit" class="btn btn-primary">✅ 创建组合</button>
      <a href="index.php" class="btn btn-light">返回首页</a>
    </div>
  </form>
</div>

<script>
/* 左侧子分类过滤（支持“显示全部”与每个子分类按钮） */
function setActiveLink(target){
  document.querySelectorAll('.sub-link, .show-all').forEach(a=>a.classList.remove('active'));
  target.classList.add('active');
}
function filterBySub(subId){
  document.querySelectorAll('#clothes-grid .clothes-card').forEach(function(card){
    card.style.display = (subId==='all' || card.getAttribute('data-sub')===subId) ? 'block' : 'none';
  });
}

document.querySelectorAll('.show-all').forEach(function(a){
  a.addEventListener('click', function(e){
    e.preventDefault();
    setActiveLink(this);
    filterBySub('all');
  });
});

document.querySelectorAll('.sub-link').forEach(function(a){
  a.addEventListener('click', function(e){
    e.preventDefault();
    setActiveLink(this);
    const sub = this.getAttribute('data-sub');
    filterBySub(sub);
  });
});
</script>
</body>
</html>
