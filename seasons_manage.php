<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
date_default_timezone_set('Asia/Shanghai');

function fetch_seasons(PDO $pdo): array {
    $stmt = $pdo->query("SELECT * FROM seasons ORDER BY sort_order ASC, id ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $stmt = $pdo->prepare("INSERT INTO seasons (name, temp_min, temp_max, sort_order, is_active, notes)
                               VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            trim((string)($_POST['name'] ?? '')),
            ($_POST['temp_min'] === '' ? null : (float)$_POST['temp_min']),
            ($_POST['temp_max'] === '' ? null : (float)$_POST['temp_max']),
            (int)($_POST['sort_order'] ?? 0),
            (int)($_POST['is_active'] ?? 0),
            trim((string)($_POST['notes'] ?? ''))
        ]);
        header('Location: seasons_manage.php'); exit;
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE seasons
                               SET name=?, temp_min=?, temp_max=?, sort_order=?, is_active=?, notes=?
                               WHERE id=?");
        $stmt->execute([
            trim((string)($_POST['name'] ?? '')),
            ($_POST['temp_min'] === '' ? null : (float)$_POST['temp_min']),
            ($_POST['temp_max'] === '' ? null : (float)$_POST['temp_max']),
            (int)($_POST['sort_order'] ?? 0),
            (int)($_POST['is_active'] ?? 0),
            trim((string)($_POST['notes'] ?? '')),
            $id
        ]);
        header('Location: seasons_manage.php'); exit;
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE seasons SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
        header('Location: seasons_manage.php'); exit;
    }
}

$rows = fetch_seasons($pdo);
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <title>季节管理</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="/style/tabler.min.css" rel="stylesheet">
  <script src="/style/tabler.min.js"></script>
  <style>
    .table td, .table th { vertical-align: middle; }
    .table-fixed { table-layout: fixed; width: 100%; }
    .table input.form-control, .table select.form-select { width: 100%; box-sizing: border-box; }
    .break-anywhere { word-break: break-all; overflow-wrap: anywhere; white-space: normal; }
    .col-num { text-align: center; }
    .nowrap { white-space: nowrap; }
  </style>
</head>
<body>
<div class="page">
  <div class="content">
    <div class="container-xl">

      <div class="d-flex align-items-center my-3">
        <a href="/index.php" class="btn btn-outline-primary me-2">返回首页</a>
        <h2 class="m-0">季节管理</h2>
      </div>

      <!-- 新增季节表单 -->
      <div class="card mb-3">
        <div class="card-header"><strong>新增季节</strong></div>
        <div class="card-body">
          <form method="post" class="row g-2">
            <input type="hidden" name="action" value="create">
            <div class="col-md-3">
              <label class="form-label">名称</label>
              <input name="name" class="form-control" required>
            </div>
            <div class="col-md-2">
              <label class="form-label">最低温(℃)</label>
              <input name="temp_min" type="number" step="0.1" class="form-control" placeholder="可空">
            </div>
            <div class="col-md-2">
              <label class="form-label">最高温(℃)</label>
              <input name="temp_max" type="number" step="0.1" class="form-control" placeholder="可空">
            </div>
            <div class="col-md-2">
              <label class="form-label">排序</label>
              <input name="sort_order" type="number" class="form-control" value="0">
            </div>
            <div class="col-md-2">
              <label class="form-label">启用</label>
              <select name="is_active" class="form-select">
                <option value="1" selected>是</option>
                <option value="0">否</option>
              </select>
            </div>
            <div class="col-md-12">
              <label class="form-label">备注</label>
              <input name="notes" class="form-control" placeholder="可空">
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary">保存</button>
            </div>
          </form>
        </div>
      </div>

      <!-- 季节列表 -->
      <div class="card">
        <div class="card-header"><strong>季节列表</strong></div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-fixed">
              <colgroup>
                <col style="width:70px;">
                <col style="width:10%;">
                <col style="width:10%;">
                <col style="width:10%;">
                <col style="width:10%;">
                <col style="width:15%;">
                <col style="width:30%;">
                <col style="width:10%;">
              </colgroup>
              <thead>
                <tr>
                  <th class="col-num">ID</th>
                  <th>名称</th>
                  <th class="col-num">最低温</th>
                  <th class="col-num">最高温</th>
                  <th class="col-num">排序</th>
                  <th class="col-num">状态</th>
                  <th>备注</th>
                  <th class="nowrap">操作</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($rows as $r): 
                    $fid = 'f' . (int)$r['id']; ?>
                <tr>
                  <td class="col-num"><?= (int)$r['id'] ?></td>
                  <td>
                    <input name="name" class="form-control break-anywhere" 
                           value="<?= htmlspecialchars($r['name']) ?>" required form="<?= $fid ?>">
                  </td>
                  <td>
                    <input name="temp_min" type="number" step="0.1" class="form-control" 
                           value="<?= htmlspecialchars((string)$r['temp_min']) ?>" form="<?= $fid ?>">
                  </td>
                  <td>
                    <input name="temp_max" type="number" step="0.1" class="form-control" 
                           value="<?= htmlspecialchars((string)$r['temp_max']) ?>" form="<?= $fid ?>">
                  </td>
                  <td>
                    <input name="sort_order" type="number" class="form-control" 
                           value="<?= (int)$r['sort_order'] ?>" form="<?= $fid ?>">
                  </td>
                  <td>
                    <select name="is_active" class="form-select" form="<?= $fid ?>">
                      <option value="1" <?= $r['is_active'] ? 'selected' : '' ?>>启用</option>
                      <option value="0" <?= $r['is_active'] ? '' : 'selected' ?>>禁用</option>
                    </select>
                  </td>
                  <td>
                    <input name="notes" class="form-control break-anywhere" 
                           value="<?= htmlspecialchars((string)$r['notes']) ?>" form="<?= $fid ?>">
                  </td>
                  <td class="nowrap">
                    <!-- 更新表单（放在操作列，其他输入用 form= 关联到它） -->
                    <form id="<?= $fid ?>" method="post" class="d-inline">
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-primary">保存</button>
                    </form>

                    <!-- 启用/禁用表单 -->
                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-secondary">
                        <?= $r['is_active'] ? '禁用' : '启用' ?>
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="text-muted mb-0">提示：为保证列宽统一，使用了固定列布局和 colgroup。如需在小屏堆叠显示，可改为卡片式列表。</p>
        </div>
      </div>

    </div>
  </div>
</div>
</body>
</html>
