<?php
declare(strict_types=1);
require 'db.php';
require 'auth.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit('参数错误');
}

$stmt = $pdo->prepare("
    SELECT 
        c.*,
        cat.name AS category_name,
        sub.name AS subcategory_name,
        s.name  AS source_name,
        GROUP_CONCAT(se.name ORDER BY se.id SEPARATOR '，') AS season_names
    FROM clothes c
    JOIN categories    cat ON c.category_id    = cat.id
    JOIN subcategories sub ON c.subcategory_id = sub.id
    LEFT JOIN sources  s   ON c.source_id      = s.id
    LEFT JOIN clothes_seasons cs ON c.id = cs.clothes_id
    LEFT JOIN seasons se ON cs.season_id = se.id
    WHERE c.id = ?
    GROUP BY c.id
");
$stmt->execute([$id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    http_response_code(404);
    exit('衣物不存在');
}

$tagStmt = $pdo->prepare("
    SELECT t.name 
    FROM tags t 
    JOIN clothes_tags ct ON ct.tag_id = t.id 
    WHERE ct.clothes_id = ?
");
$tagStmt->execute([$id]);
$tags = $tagStmt->fetchAll(PDO::FETCH_COLUMN);

// helpers
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

$image       = $item['image_path'] ?: 'https://via.placeholder.com/600x800?text=No+Image';
$seasonText  = $item['season_names'] ?: '未设置';
$priceText   = (isset($item['price']) && $item['price'] !== '') ? (string)$item['price'] . ' 元' : '';
$name        = $item['name'] ?: '（未命名）';
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <title><?= h($name) ?> - 衣物详情</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- 本地 Tabler -->
  <link href="/style/tabler.min.css" rel="stylesheet"/>
  <style>
    .page-body { padding-top: 1rem; }
    .img-fit { width: 100%; height: auto; max-width: 460px; border-radius: .5rem; }
    @media (max-width: 768px) { .img-fit { max-width: 100%; } }
    .kv { display: grid; grid-template-columns: 120px 1fr; gap: .5rem 1rem; align-items: start; }
    .kv .k { color: var(--tblr-secondary); }
    .kv .v { color: var(--tblr-body-color); }
    .chip-list { display: flex; flex-wrap: wrap; gap: .375rem; }
  </style>
</head>
<body>
  <div class="page">
    <div class="page-wrapper">

      <!-- 页面主体 -->
      <div class="page-body">
        <div class="container-xl">
          <div class="row g-3 g-md-4">
            <!-- 左侧：图片 -->
            <div class="col-12 col-md-5 col-lg-4">
              <div class="card card-stacked">
                <div class="card-body text-center">
                  <img src="<?= h($image) ?>" alt="<?= h($name) ?> 图片" class="img-fit border">
                </div>
              </div>
            </div>

            <!-- 右侧：信息 -->
            <div class="col-12 col-md-7 col-lg-8">
              <div class="card card-stacked">
                <div class="card-body">
                  <!-- 新增：名称标题 -->
                  <h2 class="mb-2"><?= h($name) ?></h2>
                  <div class="text-secondary mb-3">
                    <?= h($item['category_name']) ?> &gt; <?= h($item['subcategory_name']) ?>
                  </div>

                  <div class="kv mb-3">
                    <div class="k">名称</div>
                    <div class="v"><?= h($name) ?></div>

                    <div class="k">分类</div>
                    <div class="v"><?= h($item['category_name']) ?> &gt; <?= h($item['subcategory_name']) ?></div>

                    <div class="k">季节</div>
                    <div class="v"><?= h($seasonText) ?></div>

                    <div class="k">收纳位置</div>
                    <div class="v"><?= h($item['location']) ?></div>

                    <div class="k">品牌</div>
                    <div class="v"><?= h($item['brand']) ?></div>

                    <div class="k">尺码</div>
                    <div class="v"><?= h($item['size']) ?></div>

                    <div class="k">价格</div>
                    <div class="v"><?= h($priceText) ?></div>

                    <div class="k">购买日期</div>
                    <div class="v"><?= h($item['purchase_date']) ?></div>

                    <div class="k">购买途径</div>
                    <div class="v"><?= h($item['source_name']) ?></div>
                  </div>

                  <div class="mb-3">
                    <div class="k text-secondary mb-2">标签</div>
                    <?php if (!empty($tags)): ?>
                      <div class="chip-list">
                        <?php foreach ($tags as $t): ?>
                          <span class="badge bg-blue-lt"><?= h($t) ?></span>
                        <?php endforeach; ?>
                      </div>
                    <?php else: ?>
                      <div class="text-secondary">无标签</div>
                    <?php endif; ?>
                  </div>

                  <div>
                    <div class="k text-secondary mb-2">备注</div>
                    <div class="v">
                      <?= nl2br(h($item['notes'])) ?: '<span class="text-secondary">（无）</span>' ?>
                    </div>
                  </div>
                </div>

                <!-- 操作按钮 -->
                <div class="card-footer d-flex gap-2">
                  <a href="edit_item.php?id=<?= (int)$item['id'] ?>" class="btn btn-primary">编辑</a>
                  <a href="delete_item.php?id=<?= (int)$item['id'] ?>" class="btn btn-danger"
                     onclick="return confirm('确定要删除此衣物吗？');">删除</a>
                  <a href="index.php" class="btn btn-outline-secondary ms-auto">返回首页</a>
                </div>
              </div>
            </div>
          </div> <!-- row -->
        </div>
      </div>

      <footer class="footer footer-transparent d-print-none">
        <div class="container-xl">
          <div class="text-secondary small py-3">
            © <?= date('Y') ?> 服装管理
          </div>
        </div>
      </footer>
    </div>
  </div>

  <script src="/style/tabler.min.js"></script>
</body>
</html>
