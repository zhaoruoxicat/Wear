<?php
// solar_terms_manage.php —— 二十四节气管理（本地 Tabler，无外链）+ 适合季节设置
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
date_default_timezone_set('Asia/Shanghai');

/* ---------- Utils ---------- */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}
function normIntOrNull($val, int $min, int $max): ?int {
  $val = trim((string)($val ?? ''));
  if ($val === '') return null;
  if (!preg_match('/^\d+$/', $val)) return null;
  $n = (int)$val;
  if ($n < $min || $n > $max) return null;
  return $n;
}

/* ---------- 常量：24节气（仅用于校准/补齐键值，不覆盖已填日期/备注/季节） ---------- */
$TERMS = [
  ['name'=>'立春','key'=>'lichun'], ['name'=>'雨水','key'=>'yushui'], ['name'=>'惊蛰','key'=>'jingzhe'],
  ['name'=>'春分','key'=>'chunfen'], ['name'=>'清明','key'=>'qingming'], ['name'=>'谷雨','key'=>'guyu'],
  ['name'=>'立夏','key'=>'lixia'], ['name'=>'小满','key'=>'xiaoman'], ['name'=>'芒种','key'=>'mangzhong'],
  ['name'=>'夏至','key'=>'xiazhi'], ['name'=>'小暑','key'=>'xiaoshu'], ['name'=>'大暑','key'=>'dashu'],
  ['name'=>'立秋','key'=>'liqiu'], ['name'=>'处暑','key'=>'chushu'], ['name'=>'白露','key'=>'bailu'],
  ['name'=>'秋分','key'=>'qiufen'], ['name'=>'寒露','key'=>'hanlu'], ['name'=>'霜降','key'=>'shuangjiang'],
  ['name'=>'立冬','key'=>'lidong'], ['name'=>'小雪','key'=>'xiaoxue'], ['name'=>'大雪','key'=>'daxue'],
  ['name'=>'冬至','key'=>'dongzhi'], ['name'=>'小寒','key'=>'xiaohan'], ['name'=>'大寒','key'=>'dahan'],
];

$okMsg = '';
$errMsg = '';

/* ---------- 确保 solar_terms 表存在，并确保有 season_id 字段 ---------- */
try {
  // 建表（若不存在）
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS `solar_terms` (
      `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `name` VARCHAR(20) NOT NULL,
      `key_name` VARCHAR(32) NOT NULL,
      `month` TINYINT UNSIGNED NULL DEFAULT NULL,
      `day`   TINYINT UNSIGNED NULL DEFAULT NULL,
      `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      `note`  VARCHAR(100) NULL DEFAULT NULL,
      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY `uk_solar_terms_key` (`key_name`),
      UNIQUE KEY `uk_solar_terms_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // 检测 season_id 字段是否存在；不存在则增加（并尽量加外键，但外键失败也不影响使用）
  $hasSeasonCol = false;
  $desc = $pdo->query("SHOW COLUMNS FROM solar_terms")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($desc as $col) {
    if (strcasecmp($col['Field'], 'season_id') === 0) { $hasSeasonCol = true; break; }
  }
  if (!$hasSeasonCol) {
    $pdo->exec("ALTER TABLE solar_terms ADD COLUMN `season_id` INT UNSIGNED NULL DEFAULT NULL AFTER `day`");
    // 可选：尝试加外键（若无 seasons 表或权限不足会报错，这里 try-catch 忽略）
    try {
      $pdo->exec("ALTER TABLE solar_terms ADD CONSTRAINT fk_solar_terms_season
                  FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE SET NULL ON UPDATE CASCADE");
    } catch (Throwable $e) { /* 忽略外键失败 */ }
  }
} catch (Throwable $e) {
  $errMsg = '初始化表结构失败：' . $e->getMessage();
}

/* ---------- 处理：校准24节气键 ---------- */
if (!$errMsg && (($_GET['action'] ?? '') === 'ensure')) {
  try {
    $pdo->beginTransaction();

    // 读取已有 key
    $exist = $pdo->query("SELECT id, key_name FROM solar_terms")->fetchAll(PDO::FETCH_ASSOC);
    $has = [];
    foreach ($exist as $e) $has[$e['key_name']] = (int)$e['id'];

    // 插入缺失项（不覆盖已填数据；sort_order 为 1..24）
    $ins = $pdo->prepare("INSERT INTO solar_terms (name, key_name, sort_order)
                          VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE name = VALUES(name)");
    $i = 1;
    foreach ($TERMS as $t) { $ins->execute([$t['name'], $t['key'], $i]); $i++; }

    // 若已有但 sort_order=0，则按顺序补上
    $updSort = $pdo->prepare("UPDATE solar_terms SET sort_order=? WHERE key_name=? AND sort_order=0");
    $i = 1;
    foreach ($TERMS as $t) { $updSort->execute([$i, $t['key']]); $i++; }

    $pdo->commit();
    $okMsg = '已校准 24 节气键；缺失项已补齐。';
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errMsg = '校准失败：' . $e->getMessage();
  }
}

/* ---------- 读取 seasons 作为下拉选项 ---------- */
$seasonOptions = [];
try {
  // 优先按 sort_order，其次按 id；若没有对应字段会忽略排序
  $seasonOptions = $pdo->query("
    SELECT id, name
    FROM seasons
    ORDER BY
      CASE WHEN (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME='seasons' AND COLUMN_NAME='sort_order') > 0
           THEN sort_order ELSE id END ASC
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // 若 seasons 表不存在也给出提示，但不阻塞页面
  if (!$errMsg) $errMsg = '注意：未找到 seasons 表，季节下拉将为空。请先创建 seasons 表并插入数据。';
}

/* ---------- 处理：保存 month/day/note/season_id ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
  $months    = $_POST['month']     ?? [];
  $days      = $_POST['day']       ?? [];
  $notes     = $_POST['note']      ?? [];
  $seasonIds = $_POST['season_id'] ?? [];

  try {
    $pdo->beginTransaction();

    $ids = $pdo->query("SELECT id FROM solar_terms ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_COLUMN);
    $st  = $pdo->prepare("UPDATE solar_terms SET month=?, day=?, season_id=?, note=? WHERE id=?");

    foreach ($ids as $id) {
      $id   = (int)$id;
      $m    = normIntOrNull($months[$id] ?? '', 1, 12);
      $d    = normIntOrNull($days[$id]   ?? '', 1, 31);
      $note = trim((string)($notes[$id] ?? ''));

      // season_id 允许为空；若提交非数字或 0 则视为 NULL
      $sidRaw = trim((string)($seasonIds[$id] ?? ''));
      $sid = (preg_match('/^\d+$/', $sidRaw) && (int)$sidRaw > 0) ? (int)$sidRaw : null;

      if ($d !== null && $m === null) {
        throw new RuntimeException("ID={$id}：填写了“日”但未填写“月”。");
      }

      $st->execute([$m, $d, $sid, ($note!==''?$note:null), $id]);
    }

    $pdo->commit();
    $okMsg = '已保存所有节气设置（日期 / 适合季节 / 备注）。';
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errMsg = '保存失败：' . $e->getMessage();
  }
}

/* ---------- 读取数据 ---------- */
$rows = $pdo->query("
  SELECT id, name, key_name, month, day, season_id, sort_order, note
  FROM solar_terms
  ORDER BY sort_order ASC, id ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <title>二十四节气管理</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- 本地 Tabler -->
  <link href="style/tabler.min.css" rel="stylesheet">
  <link href="style/tabler-vendors.min.css" rel="stylesheet"><!-- 若没有此文件可删除 -->
  <style>
    .container-narrow{ max-width: 1100px; }
    .w-72px{ width:72px; }
    .w-110px{ width:110px; }
    .w-160px{ width:160px; }
    .mono{ font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace; }
    .text-faded{ color: var(--tblr-secondary); }
  </style>
</head>
<body>
  <div class="page">
    <div class="page-wrapper">
      <div class="container-xl container-narrow my-4">
        <!-- Header -->
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h1 class="page-title h2 mb-0">二十四节气管理</h1>
          <div class="btn-list">
            <a href="?action=ensure" class="btn btn-outline-primary"
               onclick="return confirm('将校准/补齐 24 个节气的键与顺序（不覆盖已填月日/备注/季节）。继续？');">校准 24 节气键</a>
            <a href="index.php" class="btn btn-outline-secondary">返回首页</a>
          </div>
        </div>

        <div class="card card-stacked">
          <div class="card-body">
            <p class="mb-3 text-faded">
              说明：节气日期每年略有浮动。此处采用“固定月/日写入数据库”的方式，供定时推送使用。<br>
              可为每个节气选择「适合季节」，后续推送将据此从穿搭库筛选相应季节的单品/搭配。
            </p>

            <?php if ($okMsg): ?>
              <div class="alert alert-success" role="alert"><?=h($okMsg)?></div>
            <?php endif; ?>
            <?php if ($errMsg): ?>
              <div class="alert alert-danger" role="alert"><?=h($errMsg)?></div>
            <?php endif; ?>

            <form method="post">
              <input type="hidden" name="action" value="save">

              <div class="table-responsive">
                <table class="table table-vcenter">
                  <thead>
                    <tr>
                      <th class="w-72px">序</th>
                      <th>节气</th>
                      <th class="text-faded">键名</th>
                      <th class="w-110px">月份</th>
                      <th class="w-110px">日期</th>
                      <th class="w-160px">适合季节</th>
                      <th>备注</th>
                      <th class="w-110px text-center">示例</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if ($rows): foreach ($rows as $r): ?>
                    <tr>
                      <td class="mono"><?= (int)$r['sort_order'] ?></td>
                      <td><?= h($r['name']) ?></td>
                      <td class="text-faded"><?= h($r['key_name']) ?></td>
                      <td>
                        <input type="number" class="form-control" name="month[<?= (int)$r['id'] ?>]"
                               min="1" max="12" placeholder="1-12"
                               value="<?= $r['month']!==null ? (int)$r['month'] : '' ?>">
                      </td>
                      <td>
                        <input type="number" class="form-control" name="day[<?= (int)$r['id'] ?>]"
                               min="1" max="31" placeholder="1-31"
                               value="<?= $r['day']!==null ? (int)$r['day'] : '' ?>">
                      </td>
                      <td>
                        <select class="form-select" name="season_id[<?= (int)$r['id'] ?>]">
                          <option value="">（不指定）</option>
                          <?php
                            $currSid = $r['season_id'] !== null ? (int)$r['season_id'] : null;
                            foreach ($seasonOptions as $opt) {
                              $sid = (int)$opt['id'];
                              $sel = ($currSid === $sid) ? 'selected' : '';
                              echo '<option value="'.$sid.'" '.$sel.'>'.h($opt['name']).'</option>';
                            }
                          ?>
                        </select>
                      </td>
                      <td>
                        <input type="text" class="form-control" name="note[<?= (int)$r['id'] ?>]"
                               value="<?= h((string)($r['note'] ?? '')) ?>" placeholder="可选：来源/说明">
                      </td>
                      <td class="text-center text-faded">
                        <?php if ($r['month'] && $r['day']): ?>
                          <?= (int)$r['month'] ?>-<?= (int)$r['day'] ?>
                        <?php else: ?>—<?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; else: ?>
                    <tr><td colspan="8" class="text-faded">暂无数据，请点击右上角“校准 24 节气键”。</td></tr>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>

              <div class="card-footer d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">保存全部</button>
              </div>
            </form>

          </div>
        </div>

        <div class="mt-3 text-faded">
          * 后续定时任务将依据这里的 <b>适合季节</b> 与日期，筛选穿搭库中标记为对应季节的记录，拼装邮件内容并发送。<br>
          * 如需更精细（同一节气匹配多个季节），可另建关联表 <code>solar_term_seasons(term_id, season_id)</code> 支持多对多。
        </div>
      </div>
    </div>
  </div>

  <!-- 本地 Tabler JS -->
  <script src="style/tabler.min.js"></script>
</body>
</html>
