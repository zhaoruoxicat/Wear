<?php
require 'db.php';
require 'auth.php';
date_default_timezone_set('Asia/Shanghai');

/** HTML 转义（兼容 NULL） */
function h($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_id    = $_POST['category_id'] ?? null;
    $subcategory_id = $_POST['subcategory_id'] ?? null;
    $name           = trim($_POST['name'] ?? '');
    $location       = trim($_POST['location'] ?? '');
    $brand          = trim($_POST['brand'] ?? '');
    $price          = ($_POST['price'] ?? '') !== '' ? $_POST['price'] : null;
    $size           = trim($_POST['size'] ?? '');
    $source_id      = ($_POST['source_id'] ?? '') !== '' ? $_POST['source_id'] : null;
    $purchase_date  = ($_POST['purchase_date'] ?? '') !== '' ? $_POST['purchase_date'] : null;
    $notes          = trim($_POST['notes'] ?? '');
    $tag_ids        = $_POST['tag_ids'] ?? [];
    $season_ids     = $_POST['season_ids'] ?? []; // ★ 新增：多选季节

    // 图片上传（保持与你项目一致）
    $image_path = null;
    if (!empty($_FILES['image']['name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        // 简单白名单
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $ext = 'jpg';
        }
        if (!is_dir(__DIR__ . '/uploads')) {
            mkdir(__DIR__ . '/uploads', 0755, true);
        }
        $filename = 'uploads/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/' . $filename)) {
            error_log('图片上传失败');
        } else {
            $image_path = $filename;
        }
    }

    // 事务，保证主记录 / 标签 / 季节一致
    $pdo->beginTransaction();
    try {
        // 注意：去掉了旧的 season 文本字段；保留为 NULL
        $stmt = $pdo->prepare("
            INSERT INTO clothes
            (name, category_id, subcategory_id, location, brand, price, size, source_id, purchase_date, notes, image_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $name, $category_id, $subcategory_id, $location, $brand, $price, $size,
            $source_id, $purchase_date, $notes, $image_path
        ]);
        $clothes_id = (int)$pdo->lastInsertId();

        // 标签多对多
        if (is_array($tag_ids)) {
            $insTag = $pdo->prepare("INSERT INTO clothes_tags (clothes_id, tag_id) VALUES (?, ?)");
            foreach ($tag_ids as $tid) {
                if ($tid !== '') $insTag->execute([$clothes_id, (int)$tid]);
            }
        }

        // ★ 季节多对多
        if (is_array($season_ids)) {
            $insSeason = $pdo->prepare("INSERT INTO clothes_seasons (clothes_id, season_id) VALUES (?, ?)");
            foreach ($season_ids as $sid) {
                if ($sid !== '') $insSeason->execute([$clothes_id, (int)$sid]);
            }
        }

        $pdo->commit();
        header("Location: index.php");
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('保存衣物失败：' . $e->getMessage());
        http_response_code(500);
        exit('保存失败，请稍后重试。');
    }
}

// 下拉/多选数据
$categories    = $pdo->query("SELECT id, name FROM categories ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
$subcategories = $pdo->query("SELECT id, name, category_id FROM subcategories ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
$sources       = $pdo->query("SELECT id, name FROM sources ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$tags          = $pdo->query("SELECT id, name FROM tags ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$seasons       = $pdo->query("SELECT id, name FROM seasons ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>添加衣物</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- 使用本地 Tabler UI -->
  <link href="/style/tabler.min.css" rel="stylesheet">
  <script src="/style/tabler.min.js"></script>
  <style>
    .wrap-inline .form-check { margin-right: .75rem; margin-bottom: .25rem; }
    @media (max-width: 576px) {
      .form-label { font-size: .925rem; }
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
            <div class="page-pretitle">录入</div>
            <h2 class="page-title">添加衣物</h2>
          </div>
          <div class="col-auto ms-auto d-print-none">
            <div class="btn-list">
              <a href="index.php" class="btn btn-outline-secondary">返回首页</a>
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <form method="post" enctype="multipart/form-data" class="row g-3">

            <div class="col-12 col-md-6">
              <label class="form-label">分类</label>
              <select name="category_id" id="categorySelect" class="form-select" required>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">子分类</label>
              <select name="subcategory_id" id="subcategorySelect" class="form-select" required></select>
            </div>

            <div class="col-12">
              <input type="text" name="name" class="form-control" placeholder="衣物名称">
            </div>

            <!-- ★ 新：季节多选（来自 seasons 表） -->
            <div class="col-12">
              <label class="form-label">适用季节</label>
              <div class="wrap-inline">
                <?php foreach ($seasons as $s): ?>
                  <label class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="season_ids[]" value="<?= (int)$s['id'] ?>">
                    <span class="form-check-label"><?= h($s['name']) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div class="mt-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSeasonAll">全选</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSeasonNone">全不选</button>
              </div>
            </div>

            <div class="col-12 col-md-6">
              <input type="text" name="location" class="form-control" placeholder="收纳位置">
            </div>
            <div class="col-12 col-md-6">
              <input type="text" name="brand" class="form-control" placeholder="品牌">
            </div>

            <div class="col-12 col-md-4">
              <input type="number" step="0.01" name="price" class="form-control" placeholder="价格">
            </div>
            <div class="col-12 col-md-4">
              <input type="text" name="size" class="form-control" placeholder="尺码">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">购买途径</label>
              <select name="source_id" class="form-select">
                <option value="">无</option>
                <?php foreach ($sources as $s): ?>
                  <option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">购买日期</label>
              <input type="date" name="purchase_date" class="form-control">
            </div>

            <div class="col-12">
              <label class="form-label">标签</label><br>
              <div class="wrap-inline">
                <?php foreach ($tags as $tag): ?>
                  <label class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="tag_ids[]" value="<?= (int)$tag['id'] ?>">
                    <span class="form-check-label"><?= h($tag['name']) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="col-12">
              <label class="form-label">备注</label>
              <textarea name="notes" class="form-control" rows="3"></textarea>
            </div>

            <div class="col-12">
              <label class="form-label">上传图片</label>
              <input type="file" name="image" class="form-control">
            </div>

            <div class="col-12 d-flex gap-2">
              <button type="submit" class="btn btn-primary">保存</button>
              <a href="index.php" class="btn btn-outline-secondary">取消</a>
            </div>

          </form>
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

<script>
document.addEventListener("DOMContentLoaded", function () {
  // 分类→子分类联动
  const categorySelect    = document.getElementById('categorySelect');
  const subcategorySelect = document.getElementById('subcategorySelect');
  const allSubs = <?= json_encode($subcategories, JSON_UNESCAPED_UNICODE) ?>;

  function updateSubcategories() {
    const selectedCat = categorySelect.value;
    subcategorySelect.innerHTML = '';
    const list = allSubs.filter(s => String(s.category_id) === String(selectedCat));
    list.forEach(sub => {
      const opt = document.createElement('option');
      opt.value = sub.id;
      opt.textContent = sub.name;
      subcategorySelect.appendChild(opt);
    });
  }
  categorySelect.addEventListener('change', updateSubcategories);
  updateSubcategories();

  // 季节全选/全不选
  const btnAll  = document.getElementById('btnSeasonAll');
  const btnNone = document.getElementById('btnSeasonNone');
  btnAll?.addEventListener('click', () => {
    document.querySelectorAll('input[name="season_ids[]"]').forEach(c => c.checked = true);
  });
  btnNone?.addEventListener('click', () => {
    document.querySelectorAll('input[name="season_ids[]"]').forEach(c => c.checked = false);
  });
});
</script>
</body>
</html>
