<?php
/**
 * /install/install.php
 * 安装器（兼容性版）：导入 /install/sql.sql（或 sql_.sql），创建/更新管理员，生成根目录 /db.php
 */
declare(strict_types=1);
session_start();
date_default_timezone_set('Asia/Shanghai');

// ---------------- 基本常量 ----------------
define('INSTALL_DIR', __DIR__);
define('ROOT_DIR', __DIR__ . '/..');
define('DB_PHP_PATH', ROOT_DIR . '/db.php');
define('INSTALL_LOCK', __DIR__ . '/install.lock');
define('SQL_PRIMARY_PATH', __DIR__ . '/sql.sql');
define('SQL_FALLBACK_PATH', __DIR__ . '/sql_.sql');

// ---------------- 已安装阻断 ----------------
if (is_file(INSTALL_LOCK)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>已安装</title><style>body{font-family:sans-serif;padding:24px}</style>';
    echo '<h1>系统已安装</h1><p>如需重新安装，请先删除 <code>/install/install.lock</code>。</p>';
    exit;
}

// ---------------- CSRF ----------------
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

// ---------------- 工具函数 ----------------
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * 将 SQL 文件内容拆分为可执行语句数组：
 * - 移除注释/空行
 * - 忽略 START TRANSACTION/COMMIT/ROLLBACK
 * - 忽略 DELIMITER 指令
 */
function split_sql_statements($sql) {
    $sql = str_replace(array("\r\n", "\r"), "\n", (string)$sql);

    // 去掉 /* ... */ 注释
    $sql = preg_replace('#/\*.*?\*/#s', '', $sql);
    $lines = explode("\n", $sql);
    $clean = array();
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '') continue;
        if (substr($t, 0, 2) === '--') continue;
        if (preg_match('#^(START\s+TRANSACTION|COMMIT|ROLLBACK)\s*;?$#i', $t)) continue;
        if (preg_match('#^DELIMITER\s+#i', $t)) continue;
        $clean[] = $line;
    }
    $sql = implode("\n", $clean);

    // 按 ; 分割（本项目为常规建表/插入语句，足够可靠）
    $parts = explode(';', $sql);
    $stmts = array();
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') $stmts[] = $p;
    }
    return $stmts;
}

function write_file_atomic($path, $content) {
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($tmp, $content) === false) {
        throw new RuntimeException("写入临时文件失败：$tmp");
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("写入目标文件失败：$path");
    }
}

// ---------------- 提交处理 ----------------
$state = array(
    'ok'  => false,
    'msg' => '',
);

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, isset($_POST['csrf']) ? (string)$_POST['csrf'] : '')) {
            throw new RuntimeException('CSRF 校验失败，请刷新页面重试。');
        }

        // 表单参数
        $db_host = trim(isset($_POST['db_host']) ? (string)$_POST['db_host'] : '127.0.0.1');
        $db_port = (int)(isset($_POST['db_port']) ? $_POST['db_port'] : 3306);
        $db_name = trim(isset($_POST['db_name']) ? (string)$_POST['db_name'] : '');
        $db_user = trim(isset($_POST['db_user']) ? (string)$_POST['db_user'] : '');
        $db_pass = (string)(isset($_POST['db_pass']) ? $_POST['db_pass'] : '');
        $auto_create_db = isset($_POST['auto_create_db']);

        $admin_user  = trim(isset($_POST['admin_user']) ? (string)$_POST['admin_user'] : 'admin');
        $admin_pass  = (string)(isset($_POST['admin_pass']) ? $_POST['admin_pass'] : '');
        $admin_pass2 = (string)(isset($_POST['admin_pass2']) ? $_POST['admin_pass2'] : '');

        if ($db_name === '' || $db_user === '') {
            throw new InvalidArgumentException('数据库名称与账号不得为空。');
        }
        if ($admin_user === '' || $admin_pass === '') {
            throw new InvalidArgumentException('管理员用户名与密码不得为空。');
        }
        if ($admin_pass !== $admin_pass2) {
            throw new InvalidArgumentException('两次输入的管理员密码不一致。');
        }

        // 1) 先连到不带库的 DSN，必要时创建数据库
        $dsn_server = "mysql:host={$db_host};port={$db_port};charset=utf8mb4";
        $opts = array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
        );
        $pdo_server = new PDO($dsn_server, $db_user, $db_pass, $opts);

        if ($auto_create_db) {
            $quotedDb = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $db_name);
            $pdo_server->exec("CREATE DATABASE IF NOT EXISTS `{$quotedDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }

        // 2) 连接到目标数据库
        $dsn_db = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
        $pdo = new PDO($dsn_db, $db_user, $db_pass, $opts);
        $pdo->exec("SET NAMES utf8mb4");

        // 3) 读取 SQL 文件（主路径或回退路径）
        $sql_path = is_file(SQL_PRIMARY_PATH) ? SQL_PRIMARY_PATH : (is_file(SQL_FALLBACK_PATH) ? SQL_FALLBACK_PATH : '');
        if ($sql_path === '') {
            throw new RuntimeException('未找到数据库文件：/install/sql.sql 或 /install/sql_.sql');
        }
        $raw_sql = file_get_contents($sql_path);
        if ($raw_sql === false) {
            throw new RuntimeException("读取 SQL 失败：$sql_path");
        }

        // 4) 拆分并逐条执行（不使用外层事务，避免与 SQL 内部 BEGIN/COMMIT 冲突）
        $stmts = split_sql_statements($raw_sql);
        foreach ($stmts as $stmt) {
            $s = trim($stmt);
            if ($s === '') continue;
            $pdo->exec($s);
        }

        // 5) 写入/更新管理员账号
        //    表结构：users(id, username UNIQUE, password)
        $hash = password_hash($admin_pass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute(array($admin_user));
        $row = $stmt->fetch();
        if ($row) {
            $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $upd->execute(array($hash, $row['id']));
        } else {
            $ins = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
            $ins->execute(array($admin_user, $hash));
        }

        // 6) 生成 /db.php 到站点根目录
        $db_host_exp = var_export($db_host, true);
        $db_name_exp = var_export($db_name, true);
        $db_user_exp = var_export($db_user, true);
        $db_pass_exp = var_export($db_pass, true);
        $db_php = "<?php\n".
"declare(strict_types=1);\n\n".
"/**\n * 自动生成：安装器写入\n */\n".
"\$DB_HOST = {$db_host_exp};\n".
"\$DB_PORT = {$db_port};\n".
"\$DB_NAME = {$db_name_exp};\n".
"\$DB_USER = {$db_user_exp};\n".
"\$DB_PASS = {$db_pass_exp};\n\n".
"try {\n".
"    \$pdo = new PDO(\n".
"        \"mysql:host=\$DB_HOST;port=\$DB_PORT;dbname=\$DB_NAME;charset=utf8mb4\",\n".
"        \$DB_USER,\n".
"        \$DB_PASS,\n".
"        [\n".
"            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n".
"            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n".
"            PDO::ATTR_EMULATE_PREPARES => true,\n".
"        ]\n".
"    );\n".
"    \$pdo->exec(\"SET NAMES utf8mb4\");\n".
"} catch (Throwable \$e) {\n".
"    http_response_code(500);\n".
"    die(\"数据库连接失败，请检查 db.php 配置：\" . \$e->getMessage());\n".
"}\n";

        write_file_atomic(DB_PHP_PATH, $db_php);

        // 7) 写入安装锁
        write_file_atomic(INSTALL_LOCK, date('Y-m-d H:i:s') . " installed\n");

        $state['ok']  = true;
        $state['msg'] = "安装成功！<br>已生成 <code>/db.php</code> 与 <code>/install/install.lock</code>。请删除 /install 目录或限制访问。";

    } catch (Throwable $e) {
        $state['ok'] = false;
        $state['msg'] = '安装失败：' . h($e->getMessage());
    }
}

// ---------------- 输出界面 ----------------
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title>服装管理程序 - 安装向导</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--fg:#222;--muted:#666;--bg:#f6f7fb;--card:#fff;--pri:#2d6cdf}
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Inter,Helvetica,Arial,"Noto Sans SC",sans-serif;background:var(--bg);color:var(--fg)}
.container{max-width:860px;margin:40px auto;padding:0 16px}
.card{background:var(--card);border-radius:14px;box-shadow:0 6px 20px rgba(0,0,0,.06);padding:24px}
h1{margin:0 0 12px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.grid .col-1{grid-column:span 1}
.grid .col-2{grid-column:span 2}
label{display:block;font-weight:600;margin:8px 0 6px}
input[type=text],input[type=number],input[type=password]{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:10px;font-size:14px;background:#fff}
.btn{display:inline-block;padding:12px 18px;border-radius:10px;background:var(--pri);color:#fff;text-decoration:none;border:0;font-weight:600;cursor:pointer}
.note{color:var(--muted);font-size:13px}
.alert{padding:12px 14px;border-radius:10px;margin-bottom:16px}
.alert.ok{background:#e8f5e9;color:#256029}
.alert.err{background:#fdecea;color:#611a15}
hr{border:0;border-top:1px solid #eee;margin:16px 0}
@media (max-width:720px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="container">
  <div class="card">
    <h1>服装管理程序 · 安装向导</h1>
    <p class="note">本向导将导入数据库并创建管理员账号，然后在站点根目录生成 <code>db.php</code>。</p>

    <?php if ($state['msg'] !== '') { ?>
      <div class="alert <?php echo $state['ok'] ? 'ok' : 'err'; ?>"><?php echo $state['msg']; ?></div>
    <?php } ?>

    <?php if (!$state['ok']) { ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">

      <h3>数据库连接</h3>
      <div class="grid">
        <div class="col-1">
          <label>主机</label>
          <input type="text" name="db_host" value="<?php echo h(isset($_POST['db_host'])?$_POST['db_host']:'127.0.0.1'); ?>" required>
        </div>
        <div class="col-1">
          <label>端口</label>
          <input type="number" name="db_port" value="<?php echo h(isset($_POST['db_port'])? (string)$_POST['db_port'] : '3306'); ?>" required>
        </div>
        <div class="col-1">
          <label>数据库名称</label>
          <input type="text" name="db_name" value="<?php echo h(isset($_POST['db_name'])?$_POST['db_name']:''); ?>" required>
        </div>
        <div class="col-1">
          <label>数据库账号</label>
          <input type="text" name="db_user" value="<?php echo h(isset($_POST['db_user'])?$_POST['db_user']:''); ?>" required>
        </div>
        <div class="col-2">
          <label>数据库密码</label>
          <input type="password" name="db_pass" value="<?php echo h(isset($_POST['db_pass'])?$_POST['db_pass']:''); ?>">
        </div>
        <div class="col-2">
          <label><input type="checkbox" name="auto_create_db" <?php echo isset($_POST['auto_create_db']) ? 'checked' : ''; ?>> 若数据库不存在则自动创建</label>
          <div class="note">将使用 <code>utf8mb4 / unicode_ci</code> 编码创建。</div>
        </div>
      </div>

      <hr>
      <h3>管理员账号</h3>
      <div class="grid">
        <div class="col-1">
          <label>用户名</label>
          <input type="text" name="admin_user" value="<?php echo h(isset($_POST['admin_user'])?$_POST['admin_user']:'admin'); ?>" required>
        </div>
        <div class="col-1">
          <label>密码</label>
          <input type="password" name="admin_pass" required>
        </div>
        <div class="col-2">
          <label>确认密码</label>
          <input type="password" name="admin_pass2" required>
        </div>
      </div>

      <hr>
      <button class="btn" type="submit">开始安装</button>
      <div class="note" style="margin-top:8px">
        SQL 文件优先读取 <code>/install/sql.sql</code>；如不存在，将回退到 <code>/install/sql_.sql</code>。
      </div>
    </form>
    <?php } else { ?>
      <p>为了安全，建议现在：</p>
      <ol>
        <li>删除或限制访问 <code>/install</code> 目录；</li>
        <li>备份 <code>/db.php</code>；</li>
        <li>登录后台修改默认设置。</li>
      </ol>
    <?php } ?>
  </div>
</div>
</body>
</html>
