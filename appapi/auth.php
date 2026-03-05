<?php
/**
 * appapi/auth.php
 * 用户登录接口（返回 access_token 供 App 后续请求使用）
 *
 * POST /appapi/auth.php
 * Body: { "username": "xxx", "password": "xxx" }
 * 注意：此接口不需要 Token 鉴权，需单独引入 db.php
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Shanghai');

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['code' => -1, 'message' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../db.php';

// 解析请求体
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
} else {
    $input = $_POST;
}

$username = trim((string)($input['username'] ?? ''));
$password = (string)($input['password'] ?? '');

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['code' => -1, 'message' => '请输入用户名和密码'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 验证用户
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode(['code' => -1, 'message' => '用户名或密码错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 生成 access_token 并写入 access_tokens 表
$token = bin2hex(random_bytes(32));
$tokenName = 'iOS App - ' . date('Y-m-d H:i:s');

$stmt = $pdo->prepare("INSERT INTO access_tokens (name, token, is_enabled) VALUES (?, ?, 1)");
$stmt->execute([$tokenName, $token]);

echo json_encode([
    'code'    => 0,
    'message' => '登录成功',
    'data'    => [
        'user_id'  => (int)$user['id'],
        'username' => $user['username'],
        'token'    => $token,
    ],
], JSON_UNESCAPED_UNICODE);
