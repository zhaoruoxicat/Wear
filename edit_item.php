<?php
declare(strict_types=1);
require 'db.php';
require 'auth.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit('参数错误');
}

$stmt = $pdo->prepare("SELECT * FROM clothes WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$item) {
    http_response_code(404);
    exit("衣物不存在");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_id    = (int)($_POST['category_id'] ?? 0);
    $subcategory_id = (int)($_POST['subcategory_id'] ?? 0);
    $name           = trim((string)($_POST['name'] ?? ''));
    $name           = ($name === '' ? null : $name); // 非必填，空值存 NULL
    $location       = trim((string)($_POST['location'] ?? ''));
    $brand          = trim((string)($_POST['brand'] ?? ''));
    $price_raw      = $_POST['price'] ?? '';
    $price          = ($price_raw === '' ? null : (float)$price_raw);
    $size           = trim((string)($_POST['size'] ?? ''));
    $source_id_raw  = $_POST['source_id'] ?? '';
    $source_id      = ($source_id_raw === '' ? null : (int)$source_id_raw);
    $purchase_date  = ($_POST['purchase_date'] ?? '') ?: null;
    $notes          = (string)($_POST['notes'] ?? '');
    $sort_order     = (int)($_POST['sort_order'] ?? 0);

    // 季节复选框
    $season_ids_post = $_POST['season_ids'] ?? [];
    $season_ids = [];
    if (is_array($season_ids_post)) {
        foreach ($season_ids_post as $sid) {
            $sid = (int)$sid;
            if ($sid > 0) $season_ids[] = $sid;
        }
        $season_ids = array_values(array_unique($season_ids));
    }

    // 图片
    $image_path = $item['image_path'];
    if (!empty($_FILES['image']['name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $ext      = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $filename = 'uploads/' . uniqid('cloth_', true) . "." . strtolower($ext);
        @mkdir(dirname($filename), 0777, true);
        if (move_uploaded_file($_FILES['image']['tmp_name'], $filename)) {
            $image_path = $filename;
        }
    }

    // 标签
    $tag_ids_post = $_POST['tag_ids'] ?? [];
    $tag_ids = [];
    if (is_array($tag_ids_post)) {
        foreach ($tag_ids_post as $tid) {
            $tid = (int)$tid;
            if ($tid > 0) $tag_ids[] = $tid;
        }
        $tag_ids = array_values(array_unique($tag_ids));
    }

    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare("
            UPDATE clothes
            SET name=?, category_id=?, subcategory_id=?, location=?, brand=?, price=?, size=?,
                source_id=?, purchase_date=?, notes=?, image_path=?, sort_order=?
            WHERE id=?
        ");
        $up->execute([$name, $category_id, $subcategory_id, $location, $brand, $price, $size,
                      $source_id, $purchase_date, $notes, $image_path, $sort_order, $id]);

        // 更新标签
        $pdo->prepare("DELETE FROM clothes_tags WHERE clothes_id=?")->execute([$id]);
        if ($tag_ids) {
            $ins = $pdo->prepare("INSERT INTO clothes_tags (clothes_id, tag_id) VALUES (?, ?)");
            foreach ($tag_ids as $tid) $ins->execute([$id, $tid]);
        }

        // 更新季节
        $pdo->prepare("DELETE FROM clothes_seasons WHERE clothes_id=?")->execute([$id]);
        if ($season_ids) {
            $ins = $pdo->prepare("INSERT INTO clothes_seasons (clothes_id, season_id) VALUES (?, ?)");
            foreach ($season_ids as $sid) $ins->execute([$id, $sid]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        exit('保存失败: ' . $e->getMessage());
    }

    header("Location: item.php?id=" . $id);
    exit;
}

// 下拉数据
$categories    = $pdo->query("SELECT id,name FROM categories ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
$subcategories = $pdo->query("SELECT id,name,category_id FROM subcategories ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
$sources       = $pdo->query("SELECT id,name FROM sources ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$tags          = $pdo->query("SELECT id,name FROM tags ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$seasons       = $pdo->query("SELECT id,name FROM seasons ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$selSeasonStmt = $pdo->prepare("SELECT season_id FROM clothes_seasons WHERE clothes_id=?");
$selSeasonStmt->execute([$id]);
$selected_season_ids = array_map('intval', $selSeasonStmt->fetchAll(PDO::FETCH_COLUMN));

$selTagStmt = $pdo->prepare("SELECT tag_id FROM clothes_tags WHERE clothes_id=?");
$selTagStmt->execute([$id]);
$selected_tag_ids = array_map('intval', $selTagStmt->fetchAll(PDO::FETCH_COLUMN));

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <title>编辑衣物</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="/style/tabler.min.css" rel="stylesheet"/>
  <style>
    .page-body { padding-top: 1rem; }
    .tag-grid, .season-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: .25rem .75rem; }
    @media (min-width:768px) { .tag-grid, .season-grid { grid-template-columns: repeat(4, 1fr); } }
    .thumb { max-height:100px; border-radius:.375rem; border:1px solid var(--tblr-border-color); padding:2px; background:var(--tblr-bg-surface); }
  </style>
</head>
<body>
<div class="page">
  <div class="page-wrapper">
    <div class="container-xl">
      <div class="page-header d-print-none">
        <div class="row align-items-center">
          <div class="col">
            <div class="page-pretitle">编辑</div>
            <h2 class="page-title">编辑衣物</h2>
          </div>
        </div>
      </div>
    </div>

    <div class="page-body">
      <div class="container-xl">
        <div class="row">
          <div class="col-12 col-md-10 col-lg-8">
            <form method="post" enctype="multipart/form-data" class="card card-stacked">
              <div class="card-body">

                <div class="mb-3">
                  <label class="form-label">分类</label>
                  <select name="category_id" id="categorySelect" class="form-select" required>
                    <?php foreach ($categories as $c): ?>
                      <option value="<?= $c['id'] ?>" <?= $c['id']==$item['category_id']?'selected':'' ?>><?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="mb-3">
                  <label class="form-label">子分类</label>
                  <select name="subcategory_id" id="subcategorySelect" class="form-select" required>
                    <?php foreach ($subcategories as $s): ?>
                      <option value="<?= $s['id'] ?>" data-cat="<?= $s['category_id'] ?>" <?= $s['id']==$item['subcategory_id']?'selected':'' ?>><?= h($s['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="mb-3">
                  <label class="form-label">名称</label>
                  <input type="text" name="name" class="form-control" value="<?= h($item['name']) ?>">
                </div>

                <!-- 季节复选框 -->
                <div class="mb-3">
                  <label class="form-label">适用季节（可多选）</label>
                  <div class="season-grid">
                    <?php foreach ($seasons as $se): 
                      $sid=(int)$se['id']; $checked=in_array($sid,$selected_season_ids)?'checked':'';
                    ?>
                      <label class="form-check">
                        <input class="form-check-input" type="checkbox" name="season_ids[]" value="<?= $sid ?>" <?= $checked ?>>
                        <span class="form-check-label"><?= h($se['name']) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>

                <div class="mb-3">
                  <label class="form-label">收纳位置</label>
                  <input type="text" name="location" class="form-control" value="<?= h($item['location']) ?>">
                </div>

                <div class="mb-3"><label class="form-label">品牌</label>
                  <input type="text" name="brand" class="form-control" value="<?= h($item['brand']) ?>">
                </div>

                <div class="mb-3"><label class="form-label">价格</label>
                  <input type="number" step="0.01" name="price" class="form-control" value="<?= h((string)$item['price']) ?>">
                </div>

                <div class="mb-3"><label class="form-label">尺码</label>
                  <input type="text" name="size" class="form-control" value="<?= h($item['size']) ?>">
                </div>

                <div class="mb-3">
                  <label class="form-label">购买途径</label>
                  <select name="source_id" class="form-select">
                    <option value="">无</option>
                    <?php foreach ($sources as $s): ?>
                      <option value="<?= $s['id'] ?>" <?= $s['id']==$item['source_id']?'selected':'' ?>><?= h($s['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="mb-3">
                  <label class="form-label">购买日期</label>
                  <input type="date" name="purchase_date" class="form-control" value="<?= h((string)$item['purchase_date']) ?>">
                </div>

                <div class="mb-3">
                  <label class="form-label">标签</label>
                  <div class="tag-grid">
                    <?php foreach ($tags as $tag): 
                      $tid=(int)$tag['id']; $chk=in_array($tid,$selected_tag_ids)?'checked':'';
                    ?>
                      <label class="form-check">
                        <input class="form-check-input" type="checkbox" name="tag_ids[]" value="<?= $tid ?>" <?= $chk ?>>
                        <span class="form-check-label"><?= h($tag['name']) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>

                <div class="mb-3">
                  <label class="form-label">备注</label>
                  <textarea name="notes" class="form-control"><?= h($item['notes']) ?></textarea>
                </div>

                <div class="mb-3">
                  <label class="form-label">排序值</label>
                  <input type="number" name="sort_order" class="form-control" value="<?= (int)$item['sort_order'] ?>">
                </div>

                <div class="mb-2">
                  <label class="form-label">更换图片</label>
                  <input type="file" name="image" class="form-control">
                  <?php if ($item['image_path']): ?>
                    <div class="mt-2">
                      <img src="<?= h($item['image_path']) ?>" class="thumb" alt="当前图片">
                    </div>
                  <?php endif; ?>
                </div>

              </div>
              <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-primary">保存修改</button>
                <a href="item.php?id=<?= $id ?>" class="btn btn-outline-secondary">取消</a>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <footer class="footer footer-transparent d-print-none">
      <div class="container-xl">
        <div class="text-secondary small py-3">© <?= date('Y') ?> 服装管理</div>
      </div>
    </footer>
  </div>
</div>
<script src="/style/tabler.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", () => {
  const categorySelect=document.getElementById('categorySelect');
  const subcategorySelect=document.getElementById('subcategorySelect');
  function filterSubs(){
    const cat=categorySelect.value;
    Array.from(subcategorySelect.options).forEach(o=>{
      const ok=!o.dataset.cat || o.dataset.cat===cat;
      o.hidden=!ok;
    });
  }
  filterSubs();
  categorySelect.addEventListener('change',filterSubs);
});
</script>
</body>
</html>
