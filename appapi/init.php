<?php
/**
 * appapi/init.php
 * API 公共初始化：数据库连接、Token 鉴权、JSON 响应辅助
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Shanghai');

// CORS 支持（iOS App 通常不需要，但保留以兼容调试）
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Access-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 数据库连接
require_once __DIR__ . '/../db.php';

// Token 鉴权（强制模式，JSON 响应版本）
// 先引入 token_auth 的函数（可选模式，不自动拒绝）
define('TOKEN_OPTIONAL', true);
require_once __DIR__ . '/../token_auth.php';

// 自行校验，失败时返回统一 JSON 格式（而非 text/plain）
$__appapi_token = ta_extract_token_from_request();
if ($__appapi_token === null || $__appapi_token === '') {
    http_response_code(401);
    echo json_encode(['code' => -1, 'message' => 'Missing access token', 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($GLOBALS['ACCESS_TOKEN_ROW'] === null) {
    http_response_code(401);
    echo json_encode(['code' => -1, 'message' => 'Invalid or disabled token', 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- JSON 响应辅助 ----------

function api_success($data = null, string $message = 'ok', int $code = 200): void {
    http_response_code($code);
    echo json_encode([
        'code'    => 0,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(string $message, int $httpCode = 400, int $bizCode = -1): void {
    http_response_code($httpCode);
    echo json_encode([
        'code'    => $bizCode,
        'message' => $message,
        'data'    => null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 获取请求体 JSON 参数（兼容 form-data 和 json body）
 */
function api_input(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

/**
 * 要求请求方法
 */
function api_require_method(string ...$methods): void {
    $current = $_SERVER['REQUEST_METHOD'];
    foreach ($methods as $m) {
        if (strcasecmp($current, $m) === 0) return;
    }
    api_error('Method Not Allowed: ' . $current, 405);
}

/**
 * 获取整型参数
 */
function api_int($key, $default = 0): int {
    return (int)($_GET[$key] ?? $_POST[$key] ?? $default);
}

/**
 * 获取字符串参数
 */
function api_str($key, $default = ''): string {
    return trim((string)($_GET[$key] ?? $_POST[$key] ?? $default));
}
