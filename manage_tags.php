<?php
require 'db.php';
require 'auth.php';
date_default_timezone_set('Asia/Shanghai');

/** 保存（新增/更新） */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $names = $_POST['names'] ?? [];
    $ids   = $_POST['ids'] ?? [];
    foreach ($names as $i => $name) {
        $id   = $ids[$i] ?? null;
        $name = trim((string)$name);
        if ($id === 'new') {
            if ($name !== '') {
                $stmt = $pdo->prepare("INSERT INTO tags (name) VALUES (?)");
                $stmt->execute([$name]);
            }
        } elseif (is_numeric($id)) {
            $stmt = $pdo->prepare("UPDATE tags SET name = ? WHERE id = ?");
            $stmt->execute([$name, $id]);
        }
    }
    header("Location: manage_tags.php");
    exit;
}

/** 数据 */
$stmt = $pdo->query("SELECT id, name FROM tags ORDER BY id");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/** HTML 转义 */
function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>标签管理</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- 本地 Tabler -->
  <link href="/style/tabler.min.css" rel="stylesheet">
  <script src="/style/tabler.min.js"></script>
  <style>
    .page-narrow { max-width: 900px; margin: 0 auto; } /* 整体收窄居中 */
    .card-body-tight { padding: .75rem 1rem; }
    .row-compact > [class^="col"] { margin-bottom: .5rem; }
    .id-chip {
      display:inline-flex; align-items:center; gap:.25rem;
      padding:.15rem .5rem; border:1px solid var(--tblr-border-color);
      border-radius:999px; font-size:.8rem;
    }
    @media (max-width: 576px){
      .table { display:none; }    /* 小屏隐藏表格，使用卡片列表 */
      .mobile-list { display:block; }
      .card-body-tight { padding: .75rem; }
    }
    @media (min-width: 577px){
      .mobile-list { display:none; }
    }
  </style>
</head>
<body>
<div class="page">
  <div class="page-wrapper">

    <div class="container-xl page-narrow">
      <div class="page-header d-print-none">
        <div class="row align-items-center">
          <div class="col">
            <div class="page-pretitle">管理</div>
            <h2 class="page-title">标签管理</h2>
          </div>
          <div class="col-auto ms-auto d-print-none">
            <div class="btn-list">
              <a href="index.php" class="btn btn-outline-secondary">返回首页</a>
            </div>
          </div>
        </div>
      </div>

      <form method="post" class="card">
        <div class="card-body card-body-tight">
          <!-- 桌面端：表格编辑 -->
          <div class="table-responsive">
            <table class="table table-vcenter">
              <thead>
                <tr>
                  <th style="width:120px">ID</th>
                  <th>标签名称</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $row): ?>
                  <tr>
                    <td>
                      <span class="id-chip">#<?= (int)$row['id'] ?></span>
                      <input type="hidden" name="ids[]" value="<?= (int)$row['id'] ?>">
                    </td>
                    <td>
                      <input type="text" name="names[]" class="form-control" value="<?= h($row['name']) ?>" placeholder="标签名称">
                    </td>
                  </tr>
                <?php endforeach; ?>
                <!-- 新增行 -->
                <tr>
                  <td>
                    <span class="text-secondary">新增</span>
                    <input type="hidden" name="ids[]" value="new">
                  </td>
                  <td>
                    <input type="text" name="names[]" class="form-control" placeholder="新增标签">
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- 移动端：卡片式编辑 -->
          <div class="mobile-list">
            <?php foreach ($rows as $row): ?>
              <div class="card mb-2">
                <div class="card-body card-body-tight">
                  <div class="row row-compact g-2 align-items-center">
                    <div class="col-12 d-flex justify-content-between">
                      <div class="text-secondary">ID</div>
                      <div><span class="id-chip">#<?= (int)$row['id'] ?></span></div>
                    </div>
                    <input type="hidden" name="ids[]" value="<?= (int)$row['id'] ?>">
                    <div class="col-12">
                      <input type="text" name="names[]" class="form-control" value="<?= h($row['name']) ?>" placeholder="标签名称">
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>

            <!-- 新增卡片 -->
            <div class="card">
              <div class="card-body card-body-tight">
                <div class="row row-compact g-2 align-items-center">
                  <div class="col-12 d-flex justify-content-between">
                    <div class="text-secondary">新增</div>
                    <input type="hidden" name="ids[]" value="new">
                  </div>
                  <div class="col-12">
                    <input type="text" name="names[]" class="form-control" placeholder="新增标签">
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 提交 -->
          <div class="d-flex justify-content-end mt-3">
            <button type="submit" class="btn btn-primary">保存修改</button>
          </div>
        </div>
      </form>

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
