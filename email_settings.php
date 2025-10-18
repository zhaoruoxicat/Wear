<?php
// email_settings.php  —— 本地 Tabler UI，无外部 CSS/JS
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
date_default_timezone_set('Asia/Shanghai');

/* ---------- Utils ---------- */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}
function bint($v){ return (int)!!$v; }
function is_valid_email(string $e): bool { return (bool)filter_var($e, FILTER_VALIDATE_EMAIL); }

/* ---------- 保存发件配置 ---------- */
$ok = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'smtp') {
  $smtp_host   = trim((string)($_POST['smtp_host'] ?? ''));
  $smtp_port   = (int)($_POST['smtp_port'] ?? 465);
  $smtp_secure = $_POST['smtp_secure'] ?? 'ssl';  // 'ssl'|'tls'|'none'
  $smtp_user   = trim((string)($_POST['smtp_user'] ?? ''));
  $smtp_pass   = (string)($_POST['smtp_pass'] ?? ''); // 留空=不修改
  $from_name   = trim((string)($_POST['from_name'] ?? ''));
  $from_email  = trim((string)($_POST['from_email'] ?? ''));
  $is_auth     = bint($_POST['is_auth'] ?? 1);
  $is_enabled  = bint($_POST['is_enabled'] ?? 1);

  try {
    if ($smtp_host === '' || $smtp_user === '' || $from_name === '' || $from_email === '') {
      throw new RuntimeException('请填写必填项（SMTP 主机、用户名、发件人名称、发件邮箱）。');
    }
    if (!in_array($smtp_secure, ['ssl','tls','none'], true)) $smtp_secure = 'ssl';
    if ($smtp_port <= 0 || $smtp_port > 65535) $smtp_port = 465;
    if (!is_valid_email($from_email)) throw new RuntimeException('发件邮箱格式不正确。');

    $row = $pdo->query("SELECT id, smtp_pass FROM email_settings ORDER BY id ASC LIMIT 1")->fetch();
    if ($row) {
      $final_pass = ($smtp_pass === '') ? (string)$row['smtp_pass'] : $smtp_pass;
      $st = $pdo->prepare("UPDATE email_settings
                           SET smtp_host=?, smtp_port=?, smtp_secure=?, smtp_user=?, smtp_pass=?, from_name=?, from_email=?, is_auth=?, is_enabled=?, updated_at=NOW()
                           WHERE id=?");
      $st->execute([$smtp_host,$smtp_port,$smtp_secure,$smtp_user,$final_pass,$from_name,$from_email,$is_auth,$is_enabled,(int)$row['id']]);
    } else {
      $st = $pdo->prepare("INSERT INTO email_settings
        (smtp_host, smtp_port, smtp_secure, smtp_user, smtp_pass, from_name, from_email, is_auth, is_enabled)
        VALUES(?,?,?,?,?,?,?,?,?)");
      $st->execute([$smtp_host,$smtp_port,$smtp_secure,$smtp_user,$smtp_pass,$from_name,$from_email,$is_auth,$is_enabled]);
    }
    $ok = 'SMTP 配置已保存。';
  } catch (Throwable $e) {
    $err = '保存失败：' . $e->getMessage();
  }
}

/* ---------- 收件人：新增/删除/启用禁用 ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'rcpt_add') {
  $email = trim((string)($_POST['email'] ?? ''));
  $name  = trim((string)($_POST['name'] ?? ''));
  try {
    if (!is_valid_email($email)) throw new RuntimeException('收件人邮箱格式不正确。');
    $st = $pdo->prepare("INSERT INTO email_recipients(email, name, is_enabled) VALUES(?,?,1)
                         ON DUPLICATE KEY UPDATE name=VALUES(name), is_enabled=VALUES(is_enabled)");
    $st->execute([$email, $name]);
    $ok = '已添加/更新收件人。';
  } catch (Throwable $e) { $err = '操作失败：' . $e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'rcpt_toggle') {
  $id  = (int)($_POST['id'] ?? 0);
  $val = bint($_POST['val'] ?? 0);
  try {
    $st = $pdo->prepare("UPDATE email_recipients SET is_enabled=? WHERE id=?");
    $st->execute([$val, $id]);
    $ok = '已更新收件人状态。';
  } catch (Throwable $e) { $err = '操作失败：' . $e->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'rcpt_del') {
  $id = (int)($_POST['id'] ?? 0);
  try {
    $st = $pdo->prepare("DELETE FROM email_recipients WHERE id=?");
    $st->execute([$id]);
    $ok = '已删除收件人。';
  } catch (Throwable $e) { $err = '删除失败：' . $e->getMessage(); }
}

/* ---------- 读取当前配置与列表 ---------- */
$cfg   = $pdo->query("SELECT * FROM email_settings ORDER BY id ASC LIMIT 1")->fetch();
$rcpts = $pdo->query("SELECT * FROM email_recipients ORDER BY is_enabled DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <title>邮件配置</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- 本地 Tabler 资源 -->
  <link href="style/tabler.min.css" rel="stylesheet">
  <link href="style/tabler-vendors.min.css" rel="stylesheet"> <!-- 若不存在可删除此行 -->
  <style>
    .container-narrow{ max-width: 1024px; }
    .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
    .badge-on{ --tblr-bg-opacity:1; background-color: rgba(var(--tblr-success-rgb),var(--tblr-bg-opacity))!important; }
    .badge-off{ --tblr-bg-opacity:1; background-color: rgba(var(--tblr-secondary-rgb),var(--tblr-bg-opacity))!important; }
  </style>
</head>
<body>
  <div class="page">
    <div class="page-wrapper">
      <div class="container-xl container-narrow my-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <h1 class="page-title h2">邮件配置</h1>
          <div class="btn-list">
            <a href="index.php" class="btn btn-outline-secondary">返回首页</a>
          </div>
        </div>

        <?php if ($ok): ?>
          <div class="alert alert-success" role="alert"><?=h($ok)?></div>
        <?php endif; ?>
        <?php if ($err): ?>
          <div class="alert alert-danger" role="alert"><?=h($err)?></div>
        <?php endif; ?>

        <!-- 发件 SMTP 配置 -->
        <div class="card card-stacked mb-4">
          <div class="card-header">
            <h3 class="card-title">发件 SMTP 配置</h3>
          </div>
          <div class="card-body">
            <form method="post" class="row g-3">
              <input type="hidden" name="form" value="smtp">

              <div class="col-md-6">
                <label class="form-label required">SMTP 主机</label>
                <input name="smtp_host" class="form-control" required value="<?=h($cfg['smtp_host'] ?? '')?>">
              </div>
              <div class="col-md-3">
                <label class="form-label required">端口</label>
                <input type="number" name="smtp_port" class="form-control" min="1" max="65535" required value="<?=h($cfg['smtp_port'] ?? 465)?>">
              </div>
              <div class="col-md-3">
                <label class="form-label required">加密</label>
                <select name="smtp_secure" class="form-select">
                  <?php $sec = $cfg['smtp_secure'] ?? 'ssl';
                  foreach (['ssl'=>'SSL','tls'=>'TLS','none'=>'无'] as $k=>$v){
                    $sel = ($k===$sec)?'selected':'';
                    echo "<option value=\"{$k}\" {$sel}>{$v}</option>";
                  } ?>
                </select>
              </div>

              <div class="col-md-6">
                <label class="form-label required">用户名</label>
                <input name="smtp_user" class="form-control" required value="<?=h($cfg['smtp_user'] ?? '')?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">密码（留空则不修改）</label>
                <input name="smtp_pass" type="password" class="form-control" value="">
              </div>

              <div class="col-md-6">
                <label class="form-label required">发件人名称</label>
                <input name="from_name" class="form-control" required value="<?=h($cfg['from_name'] ?? '')?>">
              </div>
              <div class="col-md-6">
                <label class="form-label required">发件人邮箱</label>
                <input name="from_email" type="email" required class="form-control" value="<?=h($cfg['from_email'] ?? '')?>">
              </div>

              <div class="col-md-3">
                <label class="form-label">SMTP 认证</label>
                <?php $ia = (int)($cfg['is_auth'] ?? 1); ?>
                <select name="is_auth" class="form-select">
                  <option value="1" <?=$ia===1?'selected':''?>>启用</option>
                  <option value="0" <?=$ia===0?'selected':''?>>关闭</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">开关</label>
                <?php $ie = (int)($cfg['is_enabled'] ?? 1); ?>
                <select name="is_enabled" class="form-select">
                  <option value="1" <?=$ie===1?'selected':''?>>启用</option>
                  <option value="0" <?=$ie===0?'selected':''?>>关闭</option>
                </select>
              </div>

              <div class="col-12 text-end">
                <button class="btn btn-primary">保存配置</button>
              </div>
            </form>
          </div>
        </div>

        <!-- 收件人列表 -->
        <div class="card card-stacked">
          <div class="card-header">
            <h3 class="card-title">收件人</h3>
          </div>
          <div class="card-body">
            <form method="post" class="row g-2 align-items-end mb-3">
              <input type="hidden" name="form" value="rcpt_add">
              <div class="col-md-5">
                <label class="form-label required">收件邮箱</label>
                <input name="email" type="email" required class="form-control" placeholder="someone@example.com">
              </div>
              <div class="col-md-4">
                <label class="form-label">姓名（可选）</label>
                <input name="name" class="form-control" placeholder="显示名称">
              </div>
              <div class="col-md-3">
                <button class="btn btn-success w-100">添加 / 更新</button>
              </div>
            </form>

            <div class="table-responsive">
              <table class="table table-vcenter">
                <thead>
                  <tr>
                    <th style="width:80px;">ID</th>
                    <th>邮箱</th>
                    <th>姓名</th>
                    <th style="width:120px;">状态</th>
                    <th style="width:220px;">操作</th>
                  </tr>
                </thead>
                <tbody>
                <?php if ($rcpts): foreach ($rcpts as $r): ?>
                  <tr>
                    <td class="mono"><?= (int)$r['id'] ?></td>
                    <td><?= h($r['email']) ?></td>
                    <td><?= h((string)($r['name'] ?? '')) ?></td>
                    <td>
                      <?php if ((int)$r['is_enabled']===1): ?>
                        <span class="badge badge-outline text-white bg-success">启用</span>
                      <?php else: ?>
                        <span class="badge badge-outline text-white bg-secondary">停用</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                      <form method="post" class="d-inline">
                        <input type="hidden" name="form" value="rcpt_toggle">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="val" value="<?= (int)$r['is_enabled'] ? 0 : 1 ?>">
                        <button class="btn btn-outline-primary btn-sm"><?= (int)$r['is_enabled']? '停用' : '启用' ?></button>
                      </form>
                      <form method="post" class="d-inline" onsubmit="return confirm('确定删除该收件人？');">
                        <input type="hidden" name="form" value="rcpt_del">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-outline-danger btn-sm">删除</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; else: ?>
                  <tr><td colspan="5" class="text-secondary">暂无收件人</td></tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>

          </div>
        </div>

        <div class="text-secondary mt-3">
          提示：当前密码以明文保存便于迁移/调试；后续可接入 openssl_encrypt + 环境变量密钥进行加密存储。
        </div>
      </div>
    </div>
  </div>

  <!-- 本地 Tabler JS -->
  <script src="style/tabler.min.js"></script>
</body>
</html>
