<?php
require 'db.php';
require 'auth.php';
date_default_timezone_set('Asia/Shanghai');

// 当前用户 ID
$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) { http_response_code(403); exit('未登录'); }

// 删除 token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_token'])) {
    $tokenId = intval($_POST['delete_token']);
    $stmt = $pdo->prepare("DELETE FROM user_tokens WHERE id = ? AND user_id = ?");
    $stmt->execute([$tokenId, $userId]);
    header("Location: token_manage.php");
    exit;
}

// 查询 token 列表（按时间倒序）
$stmt = $pdo->prepare("
  SELECT id, token, user_agent, ip_address, created_at
  FROM user_tokens
  WHERE user_id = ?
  ORDER BY created_at DESC
");
$stmt->execute([$userId]);
$tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);

/** HTML 转义（兼容 NULL） */
function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>信任设备管理</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- 本地 Tabler（与站点其他页面保持一致） -->
  <link href="/style/tabler.min.css" rel="stylesheet">
  <script src="/style/tabler.min.js"></script>

  <style>
    /* 页面宽度与自适应 */
    .page-narrow { max-width: 1200px; margin: 0 auto; }

    /* 表格在移动端可滚动 + 单元格自动换行 */
    .table-responsive { overflow-x: auto; }
    .token-table td, .token-table th { vertical-align: middle; }

    /* UA 列在桌面端做省略，移动端点击“查看”弹窗 */
    .ua-clip {
      max-width: 420px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    @media (max-width: 576px){
      .ua-clip { max-width: 200px; }
      .token-table td, .token-table th { font-size: .875rem; }
      .btn-sm { --tblr-btn-padding-y: .2rem; --tblr-btn-padding-x: .45rem; }
    }

    /* 小徽章样式 */
    .chip {
      display:inline-flex; align-items:center; gap:.25rem;
      padding:.15rem .5rem; border:1px solid var(--tblr-border-color);
      border-radius:999px; font-size:.75rem; background: var(--tblr-bg-surface);
    }
  </style>
</head>
<body>
<div class="page">
  <div class="page-wrapper">

    <div class="container-xl page-narrow">
      <!-- 页头 -->
      <div class="page-header d-print-none">
        <div class="row align-items-center">
          <div class="col">
            <div class="page-pretitle">账户</div>
            <h2 class="page-title">信任设备管理</h2>
            <div class="text-secondary mt-1">
              已登录设备 <span class="chip"><?= count($tokens) ?> 台</span>
            </div>
          </div>
          <div class="col-auto ms-auto d-print-none">
            <div class="btn-list">
              <a href="index.php" class="btn btn-outline-secondary">
                ← 返回首页
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- 列表卡片 -->
      <div class="card">
        <div class="card-body">
          <?php if (empty($tokens)): ?>
            <div class="alert alert-info mb-0">当前没有活跃设备。</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table token-table">
                <thead>
                <tr>
                  <th style="min-width: 130px;">登录时间</th>
                  <th style="min-width: 120px;">IP 地址</th>
                  <th style="min-width: 220px;">设备信息（User-Agent）</th>
                  <th class="text-end" style="min-width: 120px;">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tokens as $t): ?>
                  <tr>
                    <td><?= h($t['created_at']) ?></td>
                    <td><?= h($t['ip_address']) ?></td>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <span class="ua-clip" title="<?= h($t['user_agent']) ?>"><?= h($t['user_agent']) ?></span>
                        <button
                          type="button"
                          class="btn btn-sm btn-outline-secondary"
                          data-bs-toggle="modal"
                          data-bs-target="#uaModal"
                          data-ua="<?= h($t['user_agent']) ?>">
                          查看
                        </button>
                      </div>
                    </td>
                    <td class="text-end">
                      <form method="post" class="d-inline" onsubmit="return confirm('确定要删除此设备登录信息？')">
                        <input type="hidden" name="delete_token" value="<?= (int)$t['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">删除</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="my-4"></div>
    </div>

    <!-- UA 弹窗 -->
    <div class="modal" id="uaModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h3 class="modal-title">设备信息（User-Agent）</h3>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
          </div>
          <div class="modal-body">
            <div id="uaModalText" class="small" style="word-break: break-all;"></div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-primary" data-bs-dismiss="modal">知道了</button>
          </div>
        </div>
      </div>
    </div>

    <footer class="footer footer-transparent d-print-none">
      <div class="container-xl page-narrow">
        <div class="text-secondary small py-3">© <?= date('Y') ?> 服装管理</div>
      </div>
    </footer>

  </div>
</div>

<script>
  // 填充 UA 弹窗内容
  const uaModal = document.getElementById('uaModal');
  if (uaModal) {
    uaModal.addEventListener('show.bs.modal', function (event) {
      const button = event.relatedTarget;
      const ua = button?.getAttribute('data-ua') || '';
      const box = document.getElementById('uaModalText');
      if (box) box.textContent = ua;
    });
  }
</script>
</body>
</html>
