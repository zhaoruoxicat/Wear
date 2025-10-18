<?php
// token_settings.php —— 令牌管理（Tabler 本地版）
declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/db.php';
date_default_timezone_set('Asia/Shanghai');

/* utils */
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); } }
function bint($v){ return (int)!!$v; }
function gen_token(int $len=48): string {
  $raw = bin2hex(random_bytes((int)max(16, min(64, $len))));
  return substr($raw, 0, max(16, min(96, $len))); // 16~96
}
$ok=''; $err='';

/* Add / Reset / Toggle / Delete */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';
  try {
    if ($action==='add') {
      $name = trim((string)($_POST['name'] ?? ''));
      $paths = trim((string)($_POST['allowed_paths'] ?? ''));
      $exp   = trim((string)($_POST['expire_at'] ?? ''));
      if ($name==='') throw new RuntimeException('请填写名称。');

      $tok = gen_token(48);
      $st = $pdo->prepare("INSERT INTO access_tokens(name, token, is_enabled, allowed_paths, expire_at) VALUES(?,?,?,?,?)");
      $st->execute([$name, $tok, 1, ($paths!==''?$paths:null), ($exp!==''?$exp:null)]);
      $ok = '已创建令牌：' . $tok . '（请妥善保存）';
    } elseif ($action==='toggle') {
      $id = (int)($_POST['id'] ?? 0);
      $val = bint($_POST['val'] ?? 0);
      $st = $pdo->prepare("UPDATE access_tokens SET is_enabled=? WHERE id=?");
      $st->execute([$val, $id]);
      $ok = '状态已更新。';
    } elseif ($action==='delete') {
      $id = (int)($_POST['id'] ?? 0);
      $st = $pdo->prepare("DELETE FROM access_tokens WHERE id=?");
      $st->execute([$id]);
      $ok = '已删除。';
    } elseif ($action==='reset') {
      $id = (int)($_POST['id'] ?? 0);
      $tok = gen_token(48);
      $st = $pdo->prepare("UPDATE access_tokens SET token=?, is_enabled=1 WHERE id=?");
      $st->execute([$tok, $id]);
      $ok = '新令牌：' . $tok . '（请立即复制保存）';
    } elseif ($action==='edit_meta') {
      $id = (int)($_POST['id'] ?? 0);
      $name = trim((string)($_POST['name'] ?? ''));
      $paths = trim((string)($_POST['allowed_paths'] ?? ''));
      $exp = trim((string)($_POST['expire_at'] ?? ''));
      if ($name==='') throw new RuntimeException('请填写名称。');
      $st = $pdo->prepare("UPDATE access_tokens SET name=?, allowed_paths=?, expire_at=? WHERE id=?");
      $st->execute([$name, ($paths!==''?$paths:null), ($exp!==''?$exp:null), $id]);
      $ok = '已保存令牌信息。';
    }
  } catch (Throwable $e) {
    $err = '操作失败：' . $e->getMessage();
  }
}

/* list */
$list = $pdo->query("SELECT * FROM access_tokens ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

/* 示例访问链接前缀 */
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseExample = $proto . $host . '/email_test.php?token=';
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <title>token设置</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="style/tabler.min.css" rel="stylesheet">
  <link href="style/tabler-vendors.min.css" rel="stylesheet">
  <style>
    .container-narrow{max-width: 1300px;}
    .mono{font-family:ui-monospace,Menlo,Consolas,monospace}
  </style>
</head>
<body>
<div class="page">
  <div class="page-wrapper">
    <div class="container-xl container-narrow my-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="page-title h2">token设置</h2>
        <div class="btn-list">
          <a href="index.php" class="btn btn-outline-secondary">返回首页</a>
        </div>
      </div>

      <?php if($ok): ?><div class="alert alert-success"><?=$ok?></div><?php endif; ?>
      <?php if($err): ?><div class="alert alert-danger"><?=$err?></div><?php endif; ?>

      <!-- 新增 -->
      <div class="card card-stacked mb-4">
        <div class="card-header"><h3 class="card-title">新增令牌</h3></div>
        <div class="card-body">
          <form method="post" class="row g-3">
            <input type="hidden" name="action" value="add">
            <div class="col-md-6">
              <label class="form-label required">名称/用途备注</label>
              <input name="name" class="form-control" required placeholder="如：邮件发送接口">
            </div>
            <div class="col-md-6">
              <label class="form-label">过期时间（可选）</label>
              <input name="expire_at" type="datetime-local" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">限制可用脚本（可选，逗号分隔）</label>
              <input name="allowed_paths" class="form-control" placeholder="如：/email_test.php,/cron_send_mail.php">
            </div>
            <div class="col-12 text-end">
              <button class="btn btn-primary">生成令牌</button>
            </div>
          </form>
        </div>
        <div class="card-footer text-secondary small">
          令牌会明文显示在提示中，之后列表中仅展示前后若干位。建议复制保存到你的密码管理器。
        </div>
      </div>

      <!-- 列表 -->
      <div class="card card-stacked">
        <div class="card-header"><h3 class="card-title">令牌列表</h3></div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-vcenter">
              <thead>
              <tr>
                <th style="width:80px">ID</th>
                <th>名称</th>
                <th>token（部分）</th>
                <th>状态</th>
                <th>过期</th>
                <th>限制脚本</th>
                <th style="width:360px">操作</th>
              </tr>
              </thead>
              <tbody>
              <?php if($list): foreach($list as $r): ?>
                <tr>
                  <td class="mono"><?= (int)$r['id'] ?></td>
                  <td>
                    <form method="post" class="d-flex gap-2">
                      <input type="hidden" name="action" value="edit_meta">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <input name="name" class="form-control" value="<?= h($r['name']) ?>">
                      <button class="btn btn-outline-primary btn-sm">保存</button>
                    </form>
                  </td>
                  <td class="mono text-secondary">
                    <?php
                      $tok = (string)$r['token'];
                      $mask = strlen($tok)>12 ? substr($tok,0,6).'…'.substr($tok,-6) : $tok;
                      echo h($mask);
                    ?>
                  </td>
                  <td>
                    <?php if((int)$r['is_enabled']===1): ?>
                      <span class="badge ">启用</span>
                    <?php else: ?>
                      <span class="badge ">停用</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-secondary"><?= $r['expire_at'] ? h($r['expire_at']) : '—' ?></td>
                  <td style="max-width:260px;">
                    <form method="post" class="d-flex gap-2">
                      <input type="hidden" name="action" value="edit_meta">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="name" value="<?= h($r['name']) ?>">
                      <input name="allowed_paths" class="form-control" value="<?= h((string)($r['allowed_paths'] ?? '')) ?>">
                      <input type="hidden" name="expire_at" value="<?= h((string)($r['expire_at'] ?? '')) ?>">
                      <button class="btn btn-outline-primary btn-sm">保存</button>
                    </form>
                  </td>
                  <td class="text-nowrap">
                    <!-- 查看完整按钮 -->
                    <button
                      type="button"
                      class="btn btn-info btn-sm btn-show-token"
                      data-bs-toggle="modal" data-bs-target="#modal-show-token"
                      data-name="<?= h($r['name']) ?>"
                      data-token="<?= h((string)$r['token']) ?>"
                      data-example="<?= h($baseExample . (string)$r['token']) ?>">
                      查看
                    </button>

                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="val" value="<?= (int)$r['is_enabled']?0:1 ?>">
                      <button class="btn btn-outline-primary btn-sm"><?= (int)$r['is_enabled']? '停用' : '启用' ?></button>
                    </form>
                    <form method="post" class="d-inline" onsubmit="return confirm('确定重置？');">
                      <input type="hidden" name="action" value="reset">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-outline-warning btn-sm">重置</button>
                    </form>
                    <form method="post" class="d-inline" onsubmit="return confirm('确定删除该令牌？');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-outline-danger btn-sm">删除</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="7" class="text-secondary">暂无令牌</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- 模态框 -->
<div class="modal modal-blur fade" id="modal-show-token" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">令牌详情 <small class="text-secondary fw-normal" id="token-name"></small></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">完整 Token</label>
          <div class="input-group">
            <input id="token-full" class="form-control mono" readonly>
            <button id="btn-copy-token" class="btn btn-outline-primary" type="button">复制</button>
          </div>
        </div>
        <div>
          <label class="form-label">示例访问 URL</label>
          <div class="input-group">
            <input id="token-example" class="form-control mono" readonly>
            <button id="btn-copy-example" class="btn btn-outline-primary" type="button">复制</button>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn me-auto" data-bs-dismiss="modal">关闭</button>
        <button class="btn btn-primary" id="btn-copy-both" type="button">复制全部</button>
      </div>
    </div>
  </div>
</div>

<!-- 引入 JS -->
<script src="style/bootstrap.bundle.min.js"></script>
<script src="style/tabler.min.js"></script>
<script>
(function(){
  const modalEl = document.getElementById('modal-show-token');
  const nameEl  = document.getElementById('token-name');
  const fullEl  = document.getElementById('token-full');
  const exmpEl  = document.getElementById('token-example');
  let modal;

  function ensureModal(){
    const ModalClass = window.bootstrap && window.bootstrap.Modal;
    if (!ModalClass) return null;
    if (!modal) modal = new ModalClass(modalEl);
    return modal;
  }
  function copyText(txt){
    if (!txt) return;
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(txt).catch(()=>fallbackCopy(txt));
    } else fallbackCopy(txt);
  }
  function fallbackCopy(txt){
    const ta=document.createElement('textarea');ta.value=txt;
    ta.style.position='fixed';ta.style.left='-9999px';document.body.appendChild(ta);
    ta.select();try{document.execCommand('copy');}catch(e){}document.body.removeChild(ta);
  }

  document.addEventListener('click', function(e){
    const btn = e.target.closest('.btn-show-token');
    if (!btn) return;
    const name=btn.getAttribute('data-name')||'';
    const token=btn.getAttribute('data-token')||'';
    const example=btn.getAttribute('data-example')||'';
    nameEl.textContent = name? '（'+name+'）':'';
    fullEl.value=token;exmpEl.value=example;
    const m=ensureModal();
    if(m){m.show();} else {alert("Token:"+token+"\n示例:"+example);}
  });
  document.getElementById('btn-copy-token')?.addEventListener('click',()=>copyText(fullEl.value));
  document.getElementById('btn-copy-example')?.addEventListener('click',()=>copyText(exmpEl.value));
  document.getElementById('btn-copy-both')?.addEventListener('click',()=>copyText(fullEl.value+"\n"+exmpEl.value));
})();
</script>
</body>
</html>
