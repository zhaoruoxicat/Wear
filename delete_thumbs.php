<?php
require 'auth.php';

$thumbDir = __DIR__ . '/thumbs/';
$deletedFiles = [];
$failedFiles = [];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_dir($thumbDir)) {
        $message = '❌ 缩略图目录 thumbs/ 不存在。';
    } else {
        $files = array_diff(scandir($thumbDir), ['.', '..']);
        foreach ($files as $file) {
            $fullPath = $thumbDir . $file;
            if (is_file($fullPath)) {
                if (@unlink($fullPath)) {
                    $deletedFiles[] = $file;
                } else {
                    $failedFiles[] = $file;
                }
            }
        }
        $message = '✅ 清理完成。';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>清理缩略图缓存</title>
    <!-- Tabler 本地 CDN（与你既有项目保持一致） -->
    <link href="/style/tabler.min.css" rel="stylesheet">
    <style>
        body { background:#f5f7fb; font-size:0.95rem; }
        .page-header { padding:1rem 0; }
        .page-title { font-size:1.25rem; margin:0; display:flex; gap:.5rem; align-items:center; }
        .w-md-auto { width:auto; }
        @media (max-width: 576px) {
            .page-title { font-size:1.1rem; }
            .btn-lg { font-size:1rem; padding:.5rem 1rem; }
            .w-md-auto { width:100%; }
        }
        details summary { cursor:pointer; }
        details summary::-webkit-details-marker { display:none; }
        details summary::before { content:"▸"; display:inline-block; margin-right:.5rem; }
        details[open] summary::before { content:"▾"; }
        .mono { white-space:pre-wrap; word-break:break-all; font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
    </style>
    <script>
        function confirmDelete() {
            if (confirm("⚠️ 确定要清空所有缩略图缓存吗？此操作不可恢复！")) {
                document.getElementById("deleteForm").submit();
            }
        }
    </script>
</head>
<body>
<div class="page">
  <div class="container-xl">
    <div class="page-header">
      <div class="d-flex justify-content-between align-items-center">
        <h2 class="page-title">🧹 清理缩略图缓存</h2>
        <div class="d-none d-sm-block">
          <a href="index.php" class="btn btn-outline-secondary">返回首页</a>
        </div>
      </div>
    </div>

    <?php if ($message): ?>
      <div class="alert <?=
        (str_starts_with($message, '✅') ? 'alert-success' :
        (str_starts_with($message, '❌') ? 'alert-danger' : 'alert-info'))
      ?>">
        <?= htmlspecialchars($message) ?>
      </div>
    <?php endif; ?>

    <div class="row row-cards">
      <div class="col-12">
        <div class="card">
          <div class="card-body">
            <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
              <div class="text-secondary mb-3">
                将删除 <code>thumbs/</code> 目录下的所有文件，仅清理缩略图缓存，不影响原始图片。
              </div>
              <form method="post" id="deleteForm" class="d-grid d-sm-inline">
                <button type="button" class="btn btn-danger btn-lg w-100 w-md-auto" onclick="confirmDelete()">清理缩略图缓存</button>
              </form>
              
            <?php endif; ?>

            <?php if ($deletedFiles): ?>
              <div class="mt-4">
                <div class="d-flex align-items-center mb-2">
                  <h3 class="m-0 me-2" style="font-size:1rem;">✅ 成功删除 <?= count($deletedFiles) ?> 个文件</h3>
                  <span class="badge bg-green"><?= count($deletedFiles) ?></span>
                </div>
                <details>
                  <summary class="text-secondary">展开查看文件列表</summary>
                  <div class="card mt-2">
                    <div class="card-body mono">
                      <?= htmlspecialchars(implode("\n", $deletedFiles)) ?>
                    </div>
                  </div>
                </details>
              </div>
            <?php endif; ?>

            <?php if ($failedFiles): ?>
              <div class="mt-4">
                <div class="d-flex align-items-center mb-2">
                  <h3 class="m-0 me-2" style="font-size:1rem;">❌ 删除失败文件</h3>
                  <span class="badge bg-red"><?= count($failedFiles) ?></span>
                </div>
                <details open>
                  <summary class="text-danger">展开/收起失败列表</summary>
                  <div class="card mt-2">
                    <div class="card-body mono">
                      <?= htmlspecialchars(implode("\n", $failedFiles)) ?>
                    </div>
                  </div>
                </details>
                <div class="text-secondary small mt-2">
                  可能原因：文件被占用、权限不足、磁盘只读或安全策略限制等。
                </div>
              </div>
            <?php endif; ?>

          </div>
        </div>
      </div>
    </div>

    <div class="d-block d-sm-none mt-3">
      <a href="index.php" class="btn btn-outline-secondary w-100">返回首页</a>
    </div>

  </div>
</div>

<script src="/style/tabler.min.js"></script>
</body>
</html>
