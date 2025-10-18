<?php
// clothes_batch_name.php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
date_default_timezone_set('Asia/Shanghai');

/** ---------- Utils ---------- */
function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function arrint($a){ return array_values(array_filter(array_map('intval', (array)$a), fn($x)=>$x>0)); }

/** 缩略图/占位处理（显式可空类型，兼容 PHP 8.2+ 提示） */
function itemThumbWeb(?string $imagePath): string {
  if ($imagePath) {
    $filename = basename($imagePath);
    $thumbWeb = 'thumbs/'.$filename.'_222x300.jpg';
    $thumbAbs = __DIR__.'/'.$thumbWeb;
    if (is_file($thumbAbs)) return $thumbWeb;
    if (is_file(__DIR__.'/'.$imagePath)) return $imagePath;
  }
  return 'https://via.placeholder.com/111x150?text=No+Image';
}

/** 压缩空白 */
function normalize_spaces(string $s): string {
  $s = trim($s);
  // 将包含中文空格在内的各种空白合并为单个空格
  $s = preg_replace('/[\h\pZ]+/u', ' ', $s) ?? $s;
  return trim($s);
}

/** 模板渲染 */
function render_name_template(string $tpl, array $row): string {
  $repl = [
    '{id}'            => (string)$row['id'],
    '{category}'      => (string)($row['category_name'] ?? ''),
    '{subcategory}'   => (string)($row['subcategory_name'] ?? ''),
    '{brand}'         => (string)($row['brand'] ?? ''),
    '{size}'          => (string)($row['size'] ?? ''),
    '{purchase_date}' => (string)($row['purchase_date'] ?? ''),
    '{price}'         => is_numeric($row['price'] ?? null) ? (string)$row['price'] : '',
  ];
  return strtr($tpl, $repl);
}

/** ---------- 入参：筛选 & 分页 ---------- */
$categoryId    = isset($_GET['category_id'])    ? (int)$_GET['category_id']    : 0;
$subcategoryId = isset($_GET['subcategory_id']) ? (int)$_GET['subcategory_id'] : 0;
$seasonFilter  = arrint($_GET['season_ids'] ?? []); // 兼容你当前筛选习惯
$page          = max(1, (int)($_GET['page'] ?? 1));
$pagesize      = 120;
$offset        = ($page - 1) * $pagesize;

/** ---------- 下拉数据 ---------- */
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY sort_order ASC, id ASC")
                  ->fetchAll(PDO::FETCH_ASSOC);

$subcategories = [];
if ($categoryId) {
  $ps = $pdo->prepare("SELECT id, name FROM subcategories WHERE category_id = ? ORDER BY sort_order ASC, id ASC");
  $ps->execute([$categoryId]);
  $subcategories = $ps->fetchAll(PDO::FETCH_ASSOC);
}

$seasons = $pdo->query("SELECT id, name FROM seasons ORDER BY sort_order ASC, id ASC")
               ->fetchAll(PDO::FETCH_ASSOC);
$seasonNameMap = [];
foreach ($seasons as $s) { $seasonNameMap[(int)$s['id']] = $s['name']; }

/** ---------- 能力探测：是否有多对多季节表 ---------- */
$pivotExists = (function(PDO $pdo): bool {
  try {
    $r = $pdo->query("SHOW TABLES LIKE 'clothes_seasons'");
    return $r && $r->rowCount() > 0;
  } catch (Throwable $e) { return false; }
})($pdo);

$cols = $pdo->query("SHOW COLUMNS FROM clothes")->fetchAll(PDO::FETCH_COLUMN, 0);
$hasSeasonId = in_array('season_id', $cols, true);
$hasSeason   = in_array('season', $cols, true);

/** ---------- 提交处理（批量改名） ---------- */
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_rename') {
  $ids           = arrint($_POST['ids'] ?? []);
  $mode          = $_POST['mode'] ?? ''; // exact / prefix / suffix / replace / template
  $onlyEmpty     = isset($_POST['only_empty']);
  $doNormalize   = isset($_POST['normalize']);
  $exactName     = trim((string)($_POST['exact_name'] ?? ''));
  $prefix        = (string)($_POST['prefix'] ?? '');
  $suffix        = (string)($_POST['suffix'] ?? '');
  $findStr       = (string)($_POST['find_str'] ?? '');
  $replaceStr    = (string)($_POST['replace_str'] ?? '');
  $templateStr   = (string)($_POST['template_str'] ?? '');

  if (!$ids) {
    $flash = '请先勾选要修改的衣物。';
  } else {
    // 先取选中项的完整数据以支持模板
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("
      SELECT c.id, c.name, c.image_path, c.brand, c.size, c.price, c.purchase_date,
             cat.name AS category_name, sub.name AS subcategory_name
      FROM clothes c
      JOIN categories cat ON c.category_id = cat.id
      JOIN subcategories sub ON c.subcategory_id = sub.id
      WHERE c.id IN ($in)
      ORDER BY c.id ASC
    ");
    $st->execute($ids);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
      $flash = '未找到选中的衣物记录。';
    } else {
      try {
        $pdo->beginTransaction();
        $upd = $pdo->prepare("UPDATE clothes SET name = :name WHERE id = :id");

        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
          $origName = (string)($row['name'] ?? '');
          if ($onlyEmpty && $origName !== '' && $origName !== '0') {
            $skipped++;
            continue;
          }

          $newName = $origName;

          switch ($mode) {
            case 'exact':
              // 统一设为
              $newName = $exactName;
              break;
            case 'prefix':
              $newName = $prefix . $origName;
              break;
            case 'suffix':
              $newName = $origName . $suffix;
              break;
            case 'replace':
              // 空 find 不做替换
              if ($findStr !== '') {
                $newName = str_replace($findStr, $replaceStr, $origName);
              }
              break;
            case 'template':
              $newName = render_name_template($templateStr, $row);
              break;
            default:
              throw new RuntimeException('未选择改名方式。');
          }

          if ($doNormalize) {
            $newName = normalize_spaces($newName);
          }

          // 空名则跳过更新
          if ($newName === '') {
            $skipped++;
            continue;
          }

          // 与旧名一致则跳过
          if ($newName === $origName) {
            $skipped++;
            continue;
          }

          $upd->execute([':name' => $newName, ':id' => (int)$row['id']]);
          $updated++;
        }

        $pdo->commit();
        $flash = "批量改名完成：成功 {$updated} 条，跳过 {$skipped} 条。";
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = '保存失败：' . $e->getMessage();
      }
    }
  }
}

/** ---------- 查询列表（带筛选） ---------- */
$where = [];
$args  = [];
$join  = '';

if ($categoryId)    { $where[] = 'c.category_id = ?';    $args[] = $categoryId; }
if ($subcategoryId) { $where[] = 'c.subcategory_id = ?'; $args[] = $subcategoryId; }

if ($seasonFilter) {
  if ($pivotExists) {
    $in = implode(',', array_fill(0, count($seasonFilter), '?'));
    $join .= " INNER JOIN clothes_seasons cs ON cs.clothes_id = c.id AND cs.season_id IN ($in)";
    array_push($args, ...$seasonFilter);
  } elseif ($hasSeasonId) {
    $in = implode(',', array_fill(0, count($seasonFilter), '?'));
    $where[] = "c.season_id IN ($in)";
    array_push($args, ...$seasonFilter);
  } elseif ($hasSeason) {
    $names = [];
    foreach ($seasonFilter as $sid) { if (isset($seasonNameMap[$sid])) $names[] = $seasonNameMap[$sid]; }
    if ($names) {
      $ors = [];
      foreach ($names as $nm) {
        $ors[] = "FIND_IN_SET(?, REPLACE(REPLACE(c.season,'，',','),'、',',')) > 0";
        $args[] = $nm;
      }
      $where[] = '(' . implode(' OR ', $ors) . ')';
    }
  }
}

// 统计总数
$countSql = "SELECT COUNT(DISTINCT c.id) FROM clothes c {$join}" . ($where ? ' WHERE '.implode(' AND ', $where) : '');
$stc = $pdo->prepare($countSql); $stc->execute($args);
$total = (int)$stc->fetchColumn();
$pages = max(1, (int)ceil($total / $pagesize));

// 列表
$sql = "SELECT DISTINCT c.id, c.name, c.image_path, c.brand, c.size, c.price, c.purchase_date
        FROM clothes c {$join}".($where ? ' WHERE '.implode(' AND ', $where) : '').
       " ORDER BY c.created_at DESC, c.id DESC LIMIT {$pagesize} OFFSET {$offset}";
$st = $pdo->prepare($sql); $st->execute($args);
$items = $st->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>批量修改名称</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="/style/tabler.min.css" rel="stylesheet">
  <script src="/style/tabler.min.js"></script>
  <style>
    .page-narrow { max-width: 1200px; margin: 0 auto; }
    @media (max-width: 576px){ .page-narrow { max-width: 100%; } }

    .thumbs-row { display:flex; flex-wrap:wrap; --gap:8px; margin-left:calc(var(--gap)*-0.5); margin-right:calc(var(--gap)*-0.5); }
    .thumb-col  { padding:calc(var(--gap)*0.5); flex:0 0 33.333%; max-width:33.333%; }
    @media (min-width:576px){ .thumb-col{ flex-basis:25%;   max-width:25%;   } }
    @media (min-width:768px){ .thumb-col{ flex-basis:20%;   max-width:20%;   } }
    @media (min-width:992px){ .thumb-col{ flex-basis:12.5%; max-width:12.5%; } }
    @media (min-width:1200px){.thumb-col{ flex-basis:10%;   max-width:10%;   } }

    .thumb-card { position:relative; border:1px solid var(--tblr-border-color); border-radius:.5rem; overflow:hidden; background:var(--tblr-bg-surface); }
    .thumb-card img { width:100%; aspect-ratio:111/150; object-fit:cover; display:block; }
    .select-badge {
      position:absolute; top:.4rem; left:.4rem; z-index:2;
      background:rgba(255,255,255,.85); border-radius:.4rem; padding:.15rem .3rem; border:1px solid var(--tblr-border-color);
      display:flex; align-items:center; gap:.35rem;
    }
    .thumb-meta { font-size:.72rem; color:var(--tblr-secondary); padding:.3rem .4rem .5rem .4rem; line-height:1.25; }
    .season-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:.25rem .75rem; }
    @media (min-width: 576px){ .season-grid { grid-template-columns: repeat(3, minmax(0,1fr)); } }
    @media (min-width: 768px){ .season-grid { grid-template-columns: repeat(4, minmax(0,1fr)); } }
    .help { font-size: .8rem; color: var(--tblr-secondary); }
    .code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; background: var(--tblr-bg-surface-secondary); padding:.2rem .35rem; border-radius:.35rem; }
  </style>
</head>
<body>
<div class="page">
  <div class="page-wrapper">
    <div class="container-xl page-narrow">

      <!-- 顶部 -->
      <div class="page-header d-print-none">
        <div class="row align-items-center">
          <div class="col">
            <div class="page-pretitle">批量编辑</div>
            <h2 class="page-title">勾选缩略图后批量修改名称</h2>
            <div class="text-secondary mt-1">共 <?= (int)$total ?> 件 · 第 <?= (int)$page ?>/<?= (int)$pages ?> 页</div>
          </div>
          <div class="col-auto ms-auto d-print-none">
            <div class="btn-list">
              <a class="btn btn-outline-secondary" href="index.php">← 返回首页</a>
            </div>
          </div>
        </div>
      </div>

      <?php if ($flash): ?>
        <div class="alert <?= str_starts_with($flash,'保存失败') ? 'alert-danger' : 'alert-success' ?>"><?= h($flash) ?></div>
      <?php endif; ?>

      <!-- 筛选 -->
      <div class="card mb-3">
        <div class="card-body">
          <form class="row g-3 align-items-end" method="get">
            <div class="col-12 col-md-3">
              <label class="form-label">主分类</label>
              <select name="category_id" class="form-select" onchange="this.form.submit()">
                <option value="0">全部</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c['id'] ?>" <?= $categoryId===(int)$c['id']?'selected':''; ?>><?= h($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label">子分类</label>
              <select name="subcategory_id" class="form-select" onchange="this.form.submit()">
                <option value="0">全部</option>
                <?php foreach ($subcategories as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= $subcategoryId===(int)$s['id']?'selected':''; ?>><?= h($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">季节（筛选，可多选）</label>
              <select name="season_ids[]" class="form-select" multiple size="4">
                <?php foreach ($seasons as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= in_array((int)$s['id'], $seasonFilter, true)?'selected':''; ?>><?= h($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-hint">不选表示不限季节。</div>
            </div>
            <div class="col-12 col-md-2 d-grid">
              <button class="btn btn-primary">应用筛选</button>
            </div>
          </form>
        </div>
      </div>

      <!-- 批量改名操作条 -->
      <div class="card mb-3">
        <div class="card-body">
          <form method="post" id="bulkForm">
            <input type="hidden" name="action" value="bulk_rename">

            <div class="row g-3">
              <div class="col-12 col-md-6">
                <label class="form-label">改名方式</label>
                <div class="form-selectgroup">
                  <label class="form-selectgroup-item">
                    <input type="radio" name="mode" value="exact" class="form-selectgroup-input">
                    <span class="form-selectgroup-label">统一设为</span>
                  </label>
                  <label class="form-selectgroup-item">
                    <input type="radio" name="mode" value="prefix" class="form-selectgroup-input">
                    <span class="form-selectgroup-label">加前缀</span>
                  </label>
                  <label class="form-selectgroup-item">
                    <input type="radio" name="mode" value="suffix" class="form-selectgroup-input">
                    <span class="form-selectgroup-label">加后缀</span>
                  </label>
                  <label class="form-selectgroup-item">
                    <input type="radio" name="mode" value="replace" class="form-selectgroup-input">
                    <span class="form-selectgroup-label">查找并替换</span>
                  </label>
                  <label class="form-selectgroup-item">
                    <input type="radio" name="mode" value="template" class="form-selectgroup-input">
                    <span class="form-selectgroup-label">模板生成</span>
                  </label>
                </div>
              </div>

              <div class="col-12 col-md-6">
                <label class="form-label">常用选项</label>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="only_empty" name="only_empty">
                  <label class="form-check-label" for="only_empty">仅修改空名称的衣物</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="normalize" name="normalize" checked>
                  <label class="form-check-label" for="normalize">自动去除首尾空格、合并连续空白</label>
                </div>
              </div>

              <!-- 统一设为 -->
              <div class="col-12 col-md-6">
                <label class="form-label">统一设为</label>
                <input type="text" name="exact_name" class="form-control" placeholder="例如：白色西装外套">
              </div>

              <!-- 前后缀 -->
              <div class="col-12 col-md-3">
                <label class="form-label">前缀</label>
                <input type="text" name="prefix" class="form-control" placeholder="例如：冬季 ">
              </div>
              <div class="col-12 col-md-3">
                <label class="form-label">后缀</label>
                <input type="text" name="suffix" class="form-control" placeholder="例如： 加厚款">
              </div>

              <!-- 替换 -->
              <div class="col-12 col-md-3">
                <label class="form-label">查找</label>
                <input type="text" name="find_str" class="form-control" placeholder="例如：外套">
              </div>
              <div class="col-12 col-md-3">
                <label class="form-label">替换为</label>
                <input type="text" name="replace_str" class="form-control" placeholder="例如：夹克">
              </div>

              <!-- 模板 -->
              <div class="col-12">
                <label class="form-label">模板（支持占位符）</label>
                <input type="text" name="template_str" class="form-control" placeholder="例如：{brand} {subcategory} {size} {purchase_date}">
                <div class="help mt-1">
                  可用占位符：
                  <span class="code">{category}</span>、
                  <span class="code">{subcategory}</span>、
                  <span class="code">{brand}</span>、
                  <span class="code">{size}</span>、
                  <span class="code">{purchase_date}</span>、
                  <span class="code">{price}</span>、
                  <span class="code">{id}</span>
                </div>
              </div>

              <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit">保存到已勾选</button>
                <button class="btn btn-outline-secondary" type="button" id="selectAllPage">本页全选</button>
                <button class="btn btn-outline-secondary" type="button" id="clearAll">清空选择</button>
              </div>

              <!-- 选中的ID注入到这里 -->
              <div id="selectedIdsContainer"></div>
            </div>
          </form>
        </div>
      </div>

      <!-- 缩略图列表（可勾选） -->
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <div class="card-title m-0">当前页：<?= count($items) ?> 件</div>
          <div class="small text-secondary">点击缩略图左上角复选框或整卡切换选中</div>
        </div>
        <div class="card-body">
          <?php if (!$items): ?>
            <div class="text-secondary">没有符合条件的衣物。</div>
          <?php else: ?>
            <div class="thumbs-row" id="grid">
              <?php foreach ($items as $it):
                $id    = (int)$it['id'];
                $img   = itemThumbWeb($it['image_path'] ?? null);
                $title = $it['name'] ?: '未命名';
                $meta  = [];
                if (!empty($it['brand'])) $meta[] = $it['brand'];
                if (!empty($it['size']))  $meta[] = '尺码 '.$it['size'];
                if (is_numeric($it['price'])) $meta[] = '￥'.$it['price'];
                if (!empty($it['purchase_date']) && $it['purchase_date']!=='0000-00-00') $meta[] = $it['purchase_date'];
              ?>
              <div class="thumb-col">
                <div class="thumb-card" data-id="<?= $id ?>">
                  <label class="select-badge form-check m-0">
                    <input class="form-check-input sel" type="checkbox" data-id="<?= $id ?>">
                    <span class="form-check-label">选择</span>
                  </label>
                  <img src="<?= h($img) ?>" alt="<?= h($title) ?>" loading="lazy">
                  <div class="px-2 pt-2 small"><?= h($title) ?></div>
                  <?php if ($meta): ?><div class="thumb-meta"><?= h(implode(' · ', $meta)) ?></div><?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($pages>1): ?>
          <div class="card-footer d-flex justify-content-center">
            <ul class="pagination m-0">
              <li class="page-item <?= $page<=1?'disabled':'' ?>">
                <a class="page-link" href="?<?= http_build_query(['category_id'=>$categoryId,'subcategory_id'=>$subcategoryId,'season_ids'=>$seasonFilter,'page'=>$page-1]) ?>">上一页</a>
              </li>
              <li class="page-item disabled"><span class="page-link"><?= $page ?>/<?= $pages ?></span></li>
              <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
                <a class="page-link" href="?<?= http_build_query(['category_id'=>$categoryId,'subcategory_id'=>$subcategoryId,'season_ids'=>$seasonFilter,'page'=>$page+1]) ?>">下一页</a>
              </li>
            </ul>
          </div>
        <?php endif; ?>
      </div>

      <div class="my-4"></div>
    </div>

    <footer class="footer footer-transparent d-print-none">
      <div class="container-xl page-narrow">
        <div class="text-secondary small py-3">© <?= date('Y') ?> 服装管理</div>
      </div>
    </footer>
  </div>
</div>

<script>
  // 选择集
  const selected = new Set();

  // 将已选 ID 注入隐藏 inputs
  function syncSelectedHidden() {
    const wrap = document.getElementById('selectedIdsContainer');
    wrap.innerHTML = '';
    selected.forEach(id => {
      const input = document.createElement('input');
      input.type  = 'hidden';
      input.name  = 'ids[]';
      input.value = id;
      wrap.appendChild(input);
    });
  }

  // 卡片点击/复选框切换
  document.querySelectorAll('.thumb-card').forEach(card => {
    const id  = parseInt(card.dataset.id);
    const cb  = card.querySelector('.sel');

    // 点击整卡也可切换
    card.addEventListener('click', (e) => {
      if (e.target.tagName.toLowerCase() === 'input') return;
      cb.checked = !cb.checked;
      cb.dispatchEvent(new Event('change'));
    });

    cb.addEventListener('change', () => {
      if (cb.checked) selected.add(id); else selected.delete(id);
      syncSelectedHidden();
      card.style.outline = cb.checked ? '2px solid var(--tblr-primary)' : 'none';
    });
  });

  // 本页全选/清空
  document.getElementById('selectAllPage')?.addEventListener('click', () => {
    document.querySelectorAll('.sel').forEach(cb => { cb.checked = true; cb.dispatchEvent(new Event('change')); });
  });
  document.getElementById('clearAll')?.addEventListener('click', () => {
    document.querySelectorAll('.sel').forEach(cb => { cb.checked = false; cb.dispatchEvent(new Event('change')); });
  });

  // 提交前无选择拦截 + 模式校验
  document.getElementById('bulkForm')?.addEventListener('submit', (e) => {
    if (selected.size === 0) {
      e.preventDefault();
      alert('请先勾选要修改的衣物。');
      return;
    }
    const mode = document.querySelector('input[name="mode"]:checked')?.value;
    if (!mode) {
      e.preventDefault();
      alert('请选择一种改名方式。');
      return;
    }
  });
</script>
</body>
</html>
