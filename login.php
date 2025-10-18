<?php
session_start();
require 'db.php';
date_default_timezone_set('Asia/Shanghai');

/** 计算在 Cloudflare/CDN/反向代理 后的真实客户端 IP */
function getClientIp(): string {
    // 1) Cloudflare 专用头
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) return $ip;

    // 2) 标准代理头（取第一段）
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff) {
        $parts = array_map('trim', explode(',', $xff));
        foreach ($parts as $cand) {
            if (filter_var($cand, FILTER_VALIDATE_IP)) {
                return $cand;
            }
        }
    }

    // 3) 其他常见头
    $xri = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    if ($xri && filter_var($xri, FILTER_VALIDATE_IP)) return $xri;

    // 4) 回退 REMOTE_ADDR
    $ra = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ra, FILTER_VALIDATE_IP) ? $ra : '0.0.0.0';
}

/** HTML 转义（兼容 NULL） */
function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    // 简单校验
    if ($username === '' || $password === '') {
        $error = '请输入用户名和密码';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            // 防会话固定
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];

            // 生成 token（持久登录/信任设备）
            $token = bin2hex(random_bytes(32));

            // Cookie：与站点其余页面一致（HttpOnly + SameSite=Lax；如为 HTTPS 可改 secure=>true）
            setcookie('remember_token', $token, [
                'expires'  => time() + 86400*30,
                'path'     => '/',
                'secure'   => false,            // 若已全站 HTTPS，建议改为 true
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            // 记录设备：使用真实 IP + UA
            $stmt = $pdo->prepare("
                INSERT INTO user_tokens (user_id, token, user_agent, ip_address, created_at)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                (int)$user['id'],
                $token,
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                getClientIp(),
                date('Y-m-d H:i:s'),
            ]);

            header("Location: index.php");
            exit;
        } else {
            $error = '用户名或密码错误';
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>登录</title>
  <!-- 使用本地 Tabler，与站点保持一致 -->
  <link href="/style/tabler.min.css" rel="stylesheet">
  <script src="/style/tabler.min.js"></script>
  <style>
    /* 居中卡片：移动端留足内边距，桌面端居中并限制宽度 */
    .auth-wrap {
      min-height: 100dvh;
      display: grid;
      place-items: center;
      padding: 16px;
      background: var(--tblr-bg-surface);
    }
    .auth-card {
      width: 100%;
      max-width: 420px;
      border: 1px solid var(--tblr-border-color);
      border-radius: .75rem;
      box-shadow: var(--tblr-shadow-card);
      background: var(--tblr-bg-surface);
    }
    .brand {
      display:flex; align-items:center; justify-content:center; gap:.5rem;
      font-weight:600; font-size:1.25rem;
    }
    .brand em { font-style: normal; color: var(--tblr-primary); }
  </style>
</head>
<body>
<div class="page">
  <div class="page-wrapper">
    <div class="auth-wrap">
      <div class="card auth-card">
        <div class="card-body p-4 p-sm-5">
          <div class="brand mb-3">👗 <span>服装管理 <em>登录</em></span></div>

          <?php if ($error): ?>
            <div class="alert alert-danger" role="alert"><?= h($error) ?></div>
          <?php endif; ?>

          <form method="post" autocomplete="off">
            <div class="mb-3">
              <label class="form-label">用户名</label>
              <input type="text" name="username" class="form-control" required autofocus>
            </div>
            <div class="mb-2">
              <label class="form-label">密码</label>
              <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100 mt-3">登录</button>
          </form>

          <div class="text-center text-secondary mt-3 small">
            登录将记录设备信息（IP、User-Agent）以支持信任设备与统计。
          </div>

          <div class="d-flex justify-content-center mt-3">
            <a href="index.php" class="btn btn-link">← 返回首页</a>
          </div>
        </div>
      </div>
    </div>

    <footer class="footer footer-transparent d-print-none">
      <div class="container-xl">
        <div class="text-secondary small py-3 text-center">© <?= date('Y') ?> 服装管理</div>
      </div>
    </footer>
  </div>
</div>
</body>
</html>
