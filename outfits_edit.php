<?php
// outfits_edit.php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
date_default_timezone_set('Asia/Shanghai');

/* ---------- Utils ---------- */
function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function arrint($a){ return array_values(array_filter(array_map('intval', (array)$a))); }

/* 缩略图与创建页一致：180x240 */
function generateThumbnailIfNotExists(string $originalPath,string $thumbPath,int $maxWidth=180,int $maxHeight=240): void {
  if(!is_file($originalPath) || is_file($thumbPath)) return;
  $info=@getimagesize($originalPath); if(!$info) return;
  switch($info['mime']){
    case 'image/jpeg': $src=imagecreatefromjpeg($originalPath); break;
    case 'image/png':  $src=imagecreatefrompng($originalPath);  break;
    case 'image/gif':  $src=imagecreatefromgif($originalPath);  break;
    default: return;
  }
  $sw=imagesx($src); $sh=imagesy($src);
  $scale=min($maxWidth/$sw,$maxHeight/$sh,1);
  $nw=max(1,(int)($sw*$scale)); $nh=max(1,(int)($sh*$scale));
  if(!is_dir(dirname($thumbPath))) @mkdir(dirname($thumbPath),0755,true);
  $dst=imagecreatetruecolor($nw,$nh);
  imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$sw,$sh);
  imagejpeg($dst,$thumbPath,85);
  imagedestroy($src); imagedestroy($dst);
}

/* ---------- 接收参数 ---------- */
$outfitId = max(0,(int)($_GET['id']??0));
if($outfitId<=0){ http_response_code(400); exit('缺少参数 id'); }

/* ---------- 基础数据 ---------- */
$seasons=$pdo->query("SELECT id,name FROM seasons ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$categories=$pdo->query("SELECT * FROM categories ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$subcategories=$pdo->query("SELECT * FROM subcategories ORDER BY category_id,id")->fetchAll(PDO::FETCH_ASSOC);
$subsByCat=[]; foreach($subcategories as $s){ $subsByCat[$s['category_id']][]=$s; }

/* ---------- 查询 outfit ---------- */
$st=$pdo->prepare("SELECT * FROM outfits WHERE id=?");
$st->execute([$outfitId]);
$outfit=$st->fetch(PDO::FETCH_ASSOC);
if(!$outfit){ http_response_code(404); exit('未找到该穿搭'); }

/* ---------- 已选季节 ---------- */
$st=$pdo->prepare("SELECT season_id FROM outfit_seasons WHERE outfit_id=?");
$st->execute([$outfitId]);
$selectedSeasons=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN,0));

/* ---------- 已选衣物 ---------- */
$st=$pdo->prepare("SELECT clothes_id FROM outfit_items WHERE outfit_id=? ORDER BY order_index ASC");
$st->execute([$outfitId]);
$selectedClothes=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN,0));

/* ---------- 所有衣物（动态图列） ---------- */
$imgCandidates=['image_path','image','photo','img','picture'];
$imgCol=null;
foreach($imgCandidates as $cand){
  $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clothes' AND COLUMN_NAME=?");
  $st->execute([$cand]);
  if($st->fetchColumn()){ $imgCol=$cand; break; }
}
$imgSelect=$imgCol ? "c.`$imgCol` AS img" : "NULL AS img";
$sql="SELECT c.id,c.name,c.category_id,c.subcategory_id,$imgSelect,cat.name AS cat_name,sub.name AS sub_name
      FROM clothes c
      JOIN categories cat ON cat.id=c.category_id
      JOIN subcategories sub ON sub.id=c.subcategory_id
      ORDER BY c.id DESC";
$allClothes=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

/* 缩略图路径与占位图：180x240 */
$docRoot=rtrim($_SERVER['DOCUMENT_ROOT']??'','/');
function clothesThumb(array $row,string $docRoot): string{
  $img='https://via.placeholder.com/180x240?text=No+Image';
  if(!empty($row['img'])){
    $originalWeb='/'.ltrim($row['img'],'/');
    $originalFile=$docRoot.$originalWeb;
    $filename=basename($originalWeb);
    $thumbWeb='/thumbs/'.$filename.'_180x240.jpg';
    $thumbFile=$docRoot.$thumbWeb;
    if(is_file($originalFile)){
      generateThumbnailIfNotExists($originalFile,$thumbFile,180,240);
      $img=is_file($thumbFile)?$thumbWeb:$originalWeb;
    }
  }
  return $img;
}

/* ---------- 保存 ---------- */
if($_SERVER['REQUEST_METHOD']==='POST'){
  $name=trim($_POST['name']??'');
  $notes=trim($_POST['notes']??'');
  $seasonIds=arrint($_POST['season']??[]);
  $clothesIds=arrint($_POST['clothes']??[]);

  if($name===''){ $error='名称不能为空'; }
  else{
    try{
      $pdo->beginTransaction();
      $pdo->prepare("UPDATE outfits SET name=?,notes=?,updated_at=NOW() WHERE id=?")
          ->execute([$name,$notes,$outfitId]);

      $pdo->prepare("DELETE FROM outfit_seasons WHERE outfit_id=?")->execute([$outfitId]);
      foreach($seasonIds as $sid){
        $pdo->prepare("INSERT INTO outfit_seasons(outfit_id,season_id) VALUES(?,?)")
            ->execute([$outfitId,$sid]);
      }

      $pdo->prepare("DELETE FROM outfit_items WHERE outfit_id=?")->execute([$outfitId]);
      $order=1;
      foreach($clothesIds as $cid){
        $pdo->prepare("INSERT INTO outfit_items(outfit_id,clothes_id,order_index) VALUES(?,?,?)")
            ->execute([$outfitId,$cid,$order++]);
      }
      $pdo->commit();
      header("Location: outfits_view.php?id=".$outfitId); exit;
    }catch(Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      $error="保存失败: ".$e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<title>编辑穿搭 - <?=h($outfit['name'])?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="/style/tabler.min.css" rel="stylesheet">
<script src="/style/tabler.min.js"></script>
<style>
/* 缩略图与创建页一致：高 240px */
.thumb{width:100%;height:240px;object-fit:cover;border-radius:.5rem;border:1px solid var(--tblr-border-color);background:var(--tblr-bg-surface);}

/* 左侧子分类三列并排（与创建页一致） */
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
</style>
</head>
<body>
<div class="page container-xl py-3">
<h2 class="mb-3">✏️ 编辑穿搭</h2>

<?php if(!empty($error)): ?>
  <div class="alert alert-danger"><?=h($error)?></div>
<?php endif; ?>

<form method="post">
  <div class="mb-3">
    <label class="form-label">名称 *</label>
    <input type="text" class="form-control" name="name" value="<?=h($_POST['name']??$outfit['name'])?>" required>
  </div>
  <div class="mb-3">
    <label class="form-label">备注</label>
    <textarea class="form-control" name="notes" rows="2"><?=h($_POST['notes']??$outfit['notes'])?></textarea>
  </div>
  <div class="mb-3">
    <label class="form-label">季节</label><br>
    <?php foreach($seasons as $s): 
      $sid=(int)$s['id'];
      $checked=in_array($sid,$_POST?arrint($_POST['season']??[]):$selectedSeasons,true);
    ?>
      <label class="form-check form-check-inline">
        <input type="checkbox" class="form-check-input" name="season[]" value="<?=$sid?>" <?=$checked?'checked':''?>>
        <span class="form-check-label"><?=h($s['name'])?></span>
      </label>
    <?php endforeach; ?>
  </div>

  <div class="mb-3">
    <label class="form-label">包含的衣物</label>
    <div class="row">
      <!-- 左侧：分类 + 子分类栅格（三列并排） -->
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

      <!-- 右侧：缩略图网格（中屏起每行5张） -->
      <div class="col-12 col-md-9">
        <div class="row row-cols-2 row-cols-sm-3 row-cols-md-5 g-2" id="clothes-grid">
          <?php foreach($allClothes as $c):
            $cid=(int)$c['id'];
            $checked=in_array($cid,$_POST?arrint($_POST['clothes']??[]):$selectedClothes,true);
            $img=clothesThumb($c,$docRoot);
          ?>
          <div class="col clothes-card" data-sub="<?=$c['subcategory_id']?>">
            <label class="card h-100 position-relative">
              <input type="checkbox" class="form-check-input position-absolute m-2"
                name="clothes[]" value="<?=$cid?>" <?=$checked?'checked':''?>>
              <img src="<?=h($img)?>" class="thumb card-img-top" alt="">
              <div class="card-body p-2">
                <div class="small text-truncate"><?=h($c['name'])?></div>
                <div class="text-muted small text-truncate"><?=h($c['cat_name'].'/'.$c['sub_name'])?></div>
              </div>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary">💾 保存</button>
    <a href="outfits_view.php?id=<?=$outfitId?>" class="btn btn-outline-secondary">取消</a>
  </div>
</form>
</div>
<script>
/* 左侧子分类过滤（与创建页同款：激活高亮 + 显示全部） */
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
