<?php
// token_auth.php —— 统一 Token 校验中间件
declare(strict_types=1);

/**
 * 依赖：
 * - 已引入 db.php 并提供 $pdo (PDO, ERRMODE_EXCEPTION)
 * - 数据表 access_tokens(id, name, token, is_enabled TINYINT(1))
 *
 * 用法（强制校验，失败即 401 中止）：
 *   require __DIR__ . '/db.php';
 *   require __DIR__ . '/token_auth.php';
 *
 * 用法（可选校验，不提供 token 也放行，但可在 $GLOBALS['ACCESS_TOKEN_ROW'] 读到命中与否）：
 *   define('TOKEN_OPTIONAL', true);
 *   require __DIR__ . '/db.php';
 *   require __DIR__ . '/token_auth.php';
 */

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit('[ERR] token_auth.php 需要在包含 db.php 且 $pdo 可用之后引入');
}

/** 取全部请求头（兼容 FPM/CLI） */
function ta_getallheaders(): array {
  if (function_exists('getallheaders')) {
    $h = getallheaders();
    return is_array($h) ? $h : [];
  }
  $headers = [];
  foreach ($_SERVER as $name => $value) {
    if (str_starts_with($name, 'HTTP_')) {
      $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
      $headers[$key] = $value;
    }
  }
  return $headers;
}

/** 从请求中提取 token（优先顺序：Authorization: Bearer → X-Access-Token → ?token） */
function ta_extract_token_from_request(): ?string {
  // 1) Authorization: Bearer xxx
  $headers = ta_getallheaders();
  $auth = $headers['Authorization'] ?? $headers['authorization'] ?? null;
  if (is_string($auth) && preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m)) {
    return trim($m[1]);
  }
  // 2) X-Access-Token: xxx
  $x = $headers['X-Access-Token'] ?? $headers['x-access-token'] ?? null;
  if (is_string($x) && $x !== '') {
    return trim($x);
  }
  // 3) ?token=xxx
  $q = $_GET['token'] ?? null;
  if (is_string($q) && $q !== '') {
    return trim($q);
  }
  return null;
}

/**
 * 在 DB 中校验 token（启用状态），返回命中行或 null。
 * 注：使用 hash_equals 做常量时间比较，避免时序攻击。
 */
function ta_check_access_token_db(PDO $pdo, string $token): ?array {
  // 只拉启用的 token，逐条常量时间对比（行数通常不大；如很多可改为 WHERE token=? 精确匹配）
  $st = $pdo->prepare("SELECT id, name, token, is_enabled FROM access_tokens WHERE is_enabled=1");
  $st->execute();
  $best = null;
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $dbToken = (string)$row['token'];
    // 忽略空 token 记录
    if ($dbToken === '') continue;
    if (hash_equals($dbToken, $token)) {
      $best = $row;
      break;
    }
  }
  return $best;
}

/** 向客户端输出 401 并中止 */
function ta_deny_401(string $message = 'Unauthorized'): void {
  if (!headers_sent()) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('WWW-Authenticate: Bearer');
  }
  http_response_code(401);
  // 不泄露内部细节，只提示必要信息
  echo "[401] {$message}";
  exit;
}

/** 主流程 */
$__token_optional = defined('TOKEN_OPTIONAL') && TOKEN_OPTIONAL === true;

$__token_in_req = ta_extract_token_from_request();
$__row = null;

if ($__token_in_req !== null && $__token_in_req !== '') {
  $__row = ta_check_access_token_db($pdo, $__token_in_req);
}

$GLOBALS['ACCESS_TOKEN_ROW'] = $__row; // 给业务层读取命中记录（如需要 name、id 等）

if (!$__token_optional) {
  // 强制模式：必须提供有效 token
  if ($__token_in_req === null || $__token_in_req === '') {
    ta_deny_401('Missing access token');
  }
  if ($__row === null) {
    ta_deny_401('Invalid or disabled token');
  }
}
// 可选模式下不强制中止；未命中时 $GLOBALS['ACCESS_TOKEN_ROW'] 为 null
