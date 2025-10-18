<?php
require 'db.php';
require 'auth.php';

$categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
$subList = $pdo->query("SELECT * FROM subcategories ORDER BY category_id, sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
$subMap = [];
foreach ($subList as $sub) {
    $subMap[$sub['category_id']][] = $sub;
}

// 分类保存
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cat_id'], $_POST['cat_name'], $_POST['cat_sort_order'])) {
    $cat_id = $_POST['cat_id'];
    $cat_name = trim($_POST['cat_name']);
    $cat_sort_order = intval($_POST['cat_sort_order']);

    if ($cat_id === 'new') {
        $stmt = $pdo->prepare("INSERT INTO categories (name, sort_order) VALUES (?, ?)");
        $stmt->execute([$cat_name, $cat_sort_order]);
        $newCatId = $pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare("UPDATE categories SET name = ?, sort_order = ? WHERE id = ?");
        $stmt->execute([$cat_name, $cat_sort_order, $cat_id]);
        $newCatId = $cat_id;
    }

    // 更新或新增子分类
    $sub_names       = $_POST['sub_names'] ?? [];
    $sub_sort_orders = $_POST['sub_sort_orders'] ?? [];
    $sub_ids         = $_POST['sub_ids'] ?? [];
    $sub_cats        = $_POST['sub_cat'] ?? [];

    for ($i = 0; $i < count($sub_names); $i++) {
        $sub_name = trim($sub_names[$i]);
        $sub_sort = intval($sub_sort_orders[$i]);
        $sub_id   = $sub_ids[$i];
        $sub_cat  = $sub_cats[$i] == 0 ? $newCatId : intval($sub_cats[$i]);

        if ($sub_id === 'new' && $sub_name !== '') {
            $stmt = $pdo->prepare("INSERT INTO subcategories (category_id, name, sort_order) VALUES (?, ?, ?)");
            $stmt->execute([$sub_cat, $sub_name, $sub_sort]);
        } elseif (is_numeric($sub_id)) {
            $stmt = $pdo->prepare("UPDATE subcategories SET name = ?, sort_order = ? WHERE id = ?");
            $stmt->execute([$sub_name, $sub_sort, $sub_id]);
        }
    }

    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// 删除子分类
if (isset($_POST['delete_sub'])) {
    $sub_id = intval($_POST['delete_sub']);
    $stmt = $pdo->prepare("DELETE FROM subcategories WHERE id = ?");
    $stmt->execute([$sub_id]);
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// 删除主分类
if (isset($_POST['delete_cat'])) {
    $cat_id = intval($_POST['delete_cat']);
    // 先删子分类再删分类
    $stmt = $pdo->prepare("DELETE FROM subcategories WHERE category_id = ?");
    $stmt->execute([$cat_id]);
    $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
    $stmt->execute([$cat_id]);
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

/** HTML 转义 */
function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>分类管理</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- 本地 Tabler -->
  <link href="/style/tabler.min.css" rel="stylesheet">
  <script src="/style/tabler.min.js"></script>
  <style>
    .page-title { font-weight: 600; }
    .card-body-tight { padding: .75rem 1rem; }
    .chip { display:inline-flex; align-items:center; gap:.25rem; padding:.25rem .5rem; border:1px solid var(--tblr-border-color);
            border-radius: 999px; font-size:.85rem; }
    .row-condensed > [class^="col"] { margin-bottom: .5rem; }
    .sub-row .form-control { min-width: 0; }
    .sub-row .btn { white-space: nowrap; }
    @media (max-width: 576px){
      .card-body-tight { padding: .75rem; }
    }
  </style>
</head>
<body>
<div class="page">
  <div class="page-wrapper">

    <div class="container-xl">
      <div class="page-header d-print-none">
        <div class="row align-items-center">
          <div class="col">
            <div class="page-pretitle">管理</div>
            <h2 class="page-title">分类与子分类管理</h2>
          </div>
          <div class="col-auto ms-auto d-print-none">
            <div class="btn-list">
              <a href="index.php" class="btn btn-outline-secondary">返回首页</a>
            </div>
          </div>
        </div>
      </div>

      <!-- 已有分类列表 -->
      <?php foreach ($categories as $cat): ?>
      <form method="post" class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div class="d-flex align-items-center gap-2">
            <span class="chip">ID: <?= (int)$cat['id'] ?></span>
            <span class="text-secondary">排序：<?= (int)$cat['sort_order'] ?></span>
          </div>
          <div class="btn-list">
            <button type="submit" class="btn btn-primary btn-sm">保存</button>
          </div>
        </div>

        <div class="card-body card-body-tight">
          <input type="hidden" name="cat_id" value="<?= (int)$cat['id'] ?>">

          <div class="row row-condensed g-2">
            <div class="col-12 col-md">
              <input type="text" name="cat_name" class="form-control" value="<?= h($cat['name']) ?>" placeholder="主分类名称">
            </div>
            <div class="col-6 col-md-2">
              <input type="number" name="cat_sort_order" class="form-control" value="<?= (int)$cat['sort_order'] ?>" placeholder="排序">
            </div>
          </div>

          <!-- 子分类列表 -->
          <div class="mt-2">
            <?php foreach ($subMap[$cat['id']] ?? [] as $sub): ?>
              <div class="row g-2 align-items-center sub-row mb-1">
                <div class="col-12 col-sm">
                  <input type="text" name="sub_names[]" class="form-control" value="<?= h($sub['name']) ?>" placeholder="子分类名称">
                </div>
                <div class="col-5 col-sm-2">
                  <input type="number" name="sub_sort_orders[]" class="form-control" value="<?= (int)$sub['sort_order'] ?>" placeholder="排序">
                </div>
                <div class="col-7 col-sm-auto">
                  <input type="hidden" name="sub_ids[]" value="<?= (int)$sub['id'] ?>">
                  <input type="hidden" name="sub_cat[]" value="<?= (int)$cat['id'] ?>">
                  <button type="submit" name="delete_sub" value="<?= (int)$sub['id'] ?>" class="btn btn-outline-danger btn-sm"
                          onclick="return confirm('确认删除该子分类？');">删除</button>
                </div>
              </div>
            <?php endforeach; ?>

            <!-- 新增子分类 -->
            <div class="row g-2 align-items-center sub-row">
              <div class="col-12 col-sm">
                <input type="text" name="sub_names[]" class="form-control" placeholder="新增子分类">
              </div>
              <div class="col-5 col-sm-2">
                <input type="number" name="sub_sort_orders[]" class="form-control" value="0" placeholder="排序">
              </div>
              <div class="col-7 col-sm-auto">
                <input type="hidden" name="sub_ids[]" value="new">
                <input type="hidden" name="sub_cat[]" value="<?= (int)$cat['id'] ?>">
                <span class="text-secondary small">添加后点“保存”</span>
              </div>
            </div>
          </div>
        </div>
      </form>

      <form method="post" onsubmit="return confirm('删除分类将同时删除所有子分类，确认？');" class="mb-4">
        <input type="hidden" name="delete_cat" value="<?= (int)$cat['id'] ?>">
        <button class="btn btn-outline-danger w-100">🗑 删除此分类</button>
      </form>
      <?php endforeach; ?>

      <!-- 新增主分类 -->
      <form method="post" class="card">
        <div class="card-header">
          <div class="d-flex justify-content-between align-items-center">
            <div class="text-muted">新增分类</div>
            <button type="submit" class="btn btn-success btn-sm">添加</button>
          </div>
        </div>
        <div class="card-body card-body-tight">
          <input type="hidden" name="cat_id" value="new">
          <div class="row row-condensed g-2 mb-2">
            <div class="col-12 col-md">
              <input type="text" name="cat_name" class="form-control" placeholder="主分类名称">
            </div>
            <div class="col-6 col-md-2">
              <input type="number" name="cat_sort_order" class="form-control" placeholder="排序" value="0">
            </div>
          </div>

          <!-- 可选：一并新增一个子分类 -->
          <div class="row g-2 align-items-center sub-row">
            <div class="col-12 col-sm">
              <input type="text" name="sub_names[]" class="form-control" placeholder="可选子分类">
            </div>
            <div class="col-5 col-sm-2">
              <input type="number" name="sub_sort_orders[]" class="form-control" value="0" placeholder="排序">
            </div>
            <div class="col-7 col-sm-auto">
              <input type="hidden" name="sub_ids[]" value="new">
              <input type="hidden" name="sub_cat[]" value="0">
              <span class="text-secondary small">添加后点“添加”</span>
            </div>
          </div>
        </div>
      </form>

      <div class="my-4"></div>
    </div>

    <footer class="footer footer-transparent d-print-none">
      <div class="container-xl">
        <div class="text-secondary small py-3">© <?= date('Y') ?> 服装管理</div>
      </div>
    </footer>

  </div>
</div>
</body>
</html>
