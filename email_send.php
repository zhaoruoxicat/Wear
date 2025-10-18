<?php
// send_outfit_recommendation.php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/token_auth.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';
require __DIR__ . '/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('Asia/Shanghai');

/* ===================== 可按需修改的配置 ===================== */
$BASE_URL = '';   // 站点基址（用于拼绝对URL的图片）
$SUBJECT_PREFIX = '穿搭推荐';
$NEXT_THRESHOLD_DAYS = 15;                // “下一个节气”距离>15天则改用“最近已过”的节气
$PICK_OUTFITS = 3;                        // 随机穿搭套数
$PICK_CLOTHES = 6;                        // 随机单品件数（固定为 6：两行×每行 3 个）
/* ========================================================= */

/* ========== 工具函数 ========== */
function h(?string $s): string {
  return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}
function itemThumbWeb(?string $imagePath): string {
  if (!$imagePath) return '';
  $filename = basename($imagePath);
  $thumbWeb = 'thumbs/'.$filename.'_222x300.jpg';
  $thumbAbs = __DIR__ . '/' . $thumbWeb;
  if (is_file($thumbAbs)) return $thumbWeb;
  $origAbs = __DIR__ . '/' . ltrim($imagePath, '/');
  if (is_file($origAbs)) return ltrim($imagePath, '/');
  return '';
}
function absUrl(string $base, string $rel): string {
  if ($rel === '') return '';
  if (str_starts_with($rel, 'http://') || str_starts_with($rel, 'https://')) return $rel;
  return rtrim($base, '/') . '/' . ltrim($rel, '/');
}

/* ===== 读取发件配置：email_settings ===== */
function loadMailConfig(PDO $pdo): array {
  $st = $pdo->query("SELECT * FROM email_settings WHERE is_enabled=1 ORDER BY id DESC LIMIT 1");
  $cfg = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $map = [
    'host'     => $cfg['smtp_host']    ?? '',
    'port'     => (int)($cfg['smtp_port'] ?? 0),
    'secure'   => strtolower($cfg['smtp_secure'] ?? 'ssl'), // ssl|tls|none
    'username' => $cfg['smtp_user']    ?? '',
    'password' => $cfg['smtp_pass']    ?? '',
    'from'     => $cfg['from_email']   ?? '',
    'fromName' => $cfg['from_name']    ?? 'Wear Bot',
    'isAuth'   => (int)($cfg['is_auth'] ?? 1),
  ];
  foreach (['host','from'] as $k) {
    if ($map[$k] === '') throw new RuntimeException("邮件配置缺少字段: {$k}");
  }
  if (!$map['port']) {
    if ($map['secure'] === 'ssl')      $map['port'] = 465;
    elseif ($map['secure'] === 'tls')  $map['port'] = 587;
    else                               $map['port'] = 25;
  }
  return $map;
}

/* ===== 读取收件人：email_recipients（is_enabled=1） ===== */
function loadRecipients(PDO $pdo): array {
  $recipients = [];
  $st = $pdo->query("SELECT email, name FROM email_recipients WHERE is_enabled=1 ORDER BY id ASC");
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $email = trim((string)($r['email'] ?? ''));
    $name  = trim((string)($r['name']  ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $recipients[$email] = $name; // 用 email 去重
    }
  }
  if (!$recipients) {
    throw new RuntimeException('没有可用的收件人（email_recipients.is_enabled=1 为空）');
  }
  $out = [];
  foreach ($recipients as $email => $name) {
    $out[] = ['email'=>$email, 'name'=>$name];
  }
  return $out;
}

/* ===== 最近节气→季节 ===== */
function pickNearestSolarSeason(PDO $pdo, int $nextThresholdDays = 15): array {
  $st = $pdo->query("SELECT id,name,month,day,season_id FROM solar_terms ORDER BY month,day,id");
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) throw new RuntimeException('solar_terms 表无数据');

  $today = new DateTimeImmutable('today');
  $y = (int)$today->format('Y');

  $cand = [];
  foreach ($rows as $r) {
    $cur = @DateTimeImmutable::createFromFormat('Y-n-j', sprintf('%d-%d-%d', $y, $r['month'], $r['day']));
    if (!$cur) continue;
    $next = $cur->modify('+1 year');
    $cand[] = ['row'=>$r, 'date'=>$cur,  'delta'=>(int)$cur->diff($today)->format('%r%a')];
    $cand[] = ['row'=>$r, 'date'=>$next, 'delta'=>(int)$next->diff($today)->format('%r%a')];
  }

  $future = array_values(array_filter($cand, fn($c)=>$c['delta']<0));
  usort($future, fn($a,$b)=>abs($a['delta'])<=>abs($b['delta']));
  $nextTerm = $future[0] ?? null;

  $past = array_values(array_filter($cand, fn($c)=>$c['delta']>=0));
  usort($past, fn($a,$b)=>$a['delta']<=>$b['delta']);
  $lastTerm = $past[0] ?? null;

  $use = $nextTerm ? ((abs($nextTerm['delta']) <= $nextThresholdDays) ? $nextTerm : ($lastTerm ?? $nextTerm))
                   : ($lastTerm ?? $cand[0]);

  $termDate = $use['date']->format('Y-m-d');
  $row      = $use['row'];

  $season = ['id'=>(int)$row['season_id'], 'name'=>null];
  if ($season['id'] > 0) {
    $st2 = $pdo->prepare("SELECT name FROM seasons WHERE id=?");
    $st2->execute([$season['id']]);
    $season['name'] = $st2->fetchColumn() ?: null;
  }

  return ['term'=>['id'=>(int)$row['id'],'name'=>$row['name'],'date'=>$termDate],'season'=>$season];
}

/* ===== 随机取 outfit/单品 ===== */
function pickOutfits(PDO $pdo, array $seasonIds, int $limit): array {
  $res = [];
  if ($seasonIds) {
    $in = implode(',', array_fill(0, count($seasonIds), '?'));
    $st = $pdo->prepare("SELECT DISTINCT o.id,o.name,o.notes
                         FROM outfits o
                         JOIN outfit_seasons os ON os.outfit_id=o.id
                         WHERE os.season_id IN ($in)
                         ORDER BY RAND()
                         LIMIT $limit");
    $st->execute($seasonIds);
    $res = $st->fetchAll(PDO::FETCH_ASSOC);
  }
  if (count($res) < $limit) {
    $st2 = $pdo->query("SELECT id,name,notes FROM outfits ORDER BY RAND() LIMIT ".($limit-count($res)));
    $res = array_merge($res, $st2->fetchAll(PDO::FETCH_ASSOC));
  }
  return $res;
}
function loadOutfitItems(PDO $pdo, int $outfitId): array {
  $st = $pdo->prepare("SELECT c.id,c.name,c.image_path
                       FROM outfit_items oi
                       JOIN clothes c ON oi.clothes_id=c.id
                       WHERE oi.outfit_id=? ORDER BY oi.order_index, oi.id");
  $st->execute([$outfitId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as &$r) $r['thumb_web'] = itemThumbWeb($r['image_path']);
  return $rows;
}
function pickClothes(PDO $pdo, array $seasonIds, int $limit): array {
  $res = [];
  if ($seasonIds) {
    $in = implode(',', array_fill(0, count($seasonIds), '?'));
    $sql = "SELECT DISTINCT c.id, c.name, c.image_path,
                   c.category_id, c.subcategory_id,
                   cat.name AS category_name,
                   sub.name AS subcategory_name
            FROM clothes c
            LEFT JOIN categories cat ON c.category_id = cat.id
            LEFT JOIN subcategories sub ON c.subcategory_id = sub.id
            JOIN clothes_seasons cs ON cs.clothes_id = c.id
            WHERE cs.season_id IN ($in)
            ORDER BY RAND()
            LIMIT $limit";
    $st = $pdo->prepare($sql);
    $st->execute($seasonIds);
    $res = $st->fetchAll(PDO::FETCH_ASSOC);
  }
  if (count($res) < $limit) {
    $need = $limit - count($res);
    $sql2 = "SELECT c.id, c.name, c.image_path,
                    c.category_id, c.subcategory_id,
                    cat.name AS category_name,
                    sub.name AS subcategory_name
             FROM clothes c
             LEFT JOIN categories cat ON c.category_id = cat.id
             LEFT JOIN subcategories sub ON c.subcategory_id = sub.id
             ORDER BY RAND() LIMIT $need";
    $st2 = $pdo->query($sql2);
    $res = array_merge($res, $st2->fetchAll(PDO::FETCH_ASSOC));
  }
  foreach ($res as &$r) $r['thumb_web'] = itemThumbWeb($r['image_path']);
  return $res;
}

/* ===== 构建 HTML（保持与发信版本一致：单品两行×每行3个，等大缩略图） ===== */
function buildEmailHtml(string $baseUrl, array $nearest, array $outfits, array $outfitItemsMap, array $clothes): string {
  $seasonLabel = h($nearest['season']['name'] ?? '适宜季节');
  $termName    = h($nearest['term']['name'] ?? '');
  $termDate    = h($nearest['term']['date'] ?? '');

  // —— Outfits：不补占位 —— 
  $outfitCards = '';
  foreach ($outfits as $o) {
    $title = h($o['name'] ?: '未命名组合');
    $items = $outfitItemsMap[(int)$o['id']] ?? [];
    $thumbs = '';
    foreach ($items as $it) {
      $imgRel = $it['thumb_web'] ?? '';
      $imgAbs = $imgRel ? absUrl($baseUrl, $imgRel) : '';
      $thumbs .= '<div class="thumb">'.($imgAbs ? '<img src="'.h($imgAbs).'" alt="">' : '<span style="font-size:11px;color:#9ca3af;">No Image</span>').'</div>';
    }
    $outfitCards .= '<div class="card"><h3>'.$title.'</h3><div class="thumb-row">'.$thumbs.'</div></div>';
  }

  // —— 单品：固定 3 列、总数 6；缩略图统一 3:4 比例，等大显示 —— 
  $singleCards = '';
  foreach ($clothes as $c) {
    $nm  = h($c['name'] ?: '未命名');
    $cat = h($c['category_name'] ?? '');
    $sub = h($c['subcategory_name'] ?? '');
    $catLine = ($cat !== '' || $sub !== '') ? '<div class="meta2">'.h(trim($cat.($sub!==''?' / '.$sub:''))).'</div>' : '';
    $imgRel = $c['thumb_web'] ?? '';
    $imgAbs = $imgRel ? absUrl($baseUrl, $imgRel) : '';
    $img = $imgAbs ? '<img src="'.h($imgAbs).'" alt="" class="thumb-img">' : '<span style="font-size:12px;color:#9ca3af;">No Image</span>';
    $singleCards .= '<div class="single"><div class="thumb-wrap">'.$img.'</div><div class="name">'.$nm.'</div>'.$catLine.'</div>';
  }

  return <<<HTML
<!doctype html>
<html>
<head><meta charset="utf-8"><title>{$seasonLabel} · 穿搭推荐</title>
<style>
/* 基础 */
body{margin:0;padding:0;background:#f6f7fb;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,"PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;color:#222;}
.wrap{max-width:860px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,.06);}
.header{padding:20px 24px;background:#111827;color:#fff;}
.header h1{margin:0;font-size:18px;}
.meta{font-size:12px;opacity:.85;margin-top:4px;}
.section{padding:18px 22px;}
h2{font-size:16px;margin:0 0 12px;}

/* Outfits */
.grid{display:flex;flex-wrap:wrap;gap:12px;}
.card{flex:1 1 calc(33.333% - 12px);min-width:240px;border:1px solid #eef0f4;border-radius:10px;overflow:hidden;}
.card h3{font-size:14px;margin:10px 12px 6px;}
.thumb-row{display:flex;flex-wrap:wrap;gap:6px;padding:8px 10px 12px;}
.thumb{width:72px;height:96px;background:#f2f3f7;border:1px solid #eef0f4;border-radius:6px;display:flex;align-items:center;justify-content:center;overflow:hidden;}
.thumb img{width:100%;height:100%;object-fit:cover;display:block;}

/* 单品：固定 3 列（每行 3 个），总数 6 */
.single-grid{
  display:grid;
  grid-template-columns:repeat(3, 1fr); /* 固定三列 */
  gap:12px;
}
.single{
  border:1px solid #eef0f4;border-radius:10px;overflow:hidden;text-align:center;
  padding:10px 8px 12px;background:#fafafa;
}
.single .thumb-wrap{
  width:100%;
  aspect-ratio: 3 / 4;        /* 统一 3:4 比例（接近 222x300） */
  background:#f2f3f7;border:1px solid #eef0f4;border-radius:8px;
  overflow:hidden;display:flex;align-items:center;justify-content:center;
  margin:0 auto 8px;
}
.single .thumb-wrap .thumb-img{
  width:100%;height:100%;object-fit:cover;display:block;
}
.single .name{font-size:14px;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.single .meta2{font-size:12px;color:#6b7280;margin-top:2px;}

/* 页脚：不在小屏改列数，保持两行三列的版式 */
.footer{padding:14px 22px;font-size:12px;color:#6b7280;}
</style></head>
<body>
<div class="wrap">
  <div class="header">
    <h1>{$seasonLabel} · 穿搭推荐</h1>
    <div class="meta">依据最近节气：{$termName}（{$termDate}）</div>
  </div>

  <div class="section">
    <h2>今日三套 · 穿搭组合</h2>
    <div class="grid">{$outfitCards}</div>
  </div>

  <div class="section">
    <h2>额外推荐 · 6 件单品</h2>
    <div class="single-grid">{$singleCards}</div>
  </div>

  <div class="footer">本邮件为系统自动生成的穿搭推荐，依据最近节气与季节标签进行筛选；图片使用站内缩略图。</div>
</div>
</body></html>
HTML;
}

/* ===== 发送 ===== */
function sendMail(array $cfg, array $recipients, string $subject, string $html): void {
  $mail = new PHPMailer(true);
  try {
    $mail->isSMTP();
    $mail->Host = $cfg['host'];
    $mail->Port = $cfg['port'];
    $mail->CharSet = 'UTF-8';

    $mail->SMTPAuth = (bool)$cfg['isAuth'];
    if ($mail->SMTPAuth) {
      $mail->Username = $cfg['username'];
      $mail->Password = $cfg['password'];
    }

    $secure = strtolower((string)$cfg['secure']);
    if ($secure === 'ssl')      $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    elseif ($secure === 'tls')  $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    else                        $mail->SMTPSecure = false;

    $mail->setFrom($cfg['from'], $cfg['fromName']);

    foreach ($recipients as $r) {
      $email = $r['email']; $name = $r['name'] ?? '';
      if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        if ($name !== '') $mail->addAddress($email, $name);
        else $mail->addAddress($email);
      }
    }

    if (count($mail->getToAddresses()) === 0) {
      throw new RuntimeException('未添加任何有效收件人（请检查 email_recipients）');
    }

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $html;

    $mail->send();
    echo "[OK] 邮件已发送，收件人数量：".count($mail->getToAddresses())."\n";
  } catch (Exception $e) {
    echo "[ERR] 发送失败：{$mail->ErrorInfo}\n";
  }
}

/* ===================== 主流程 ===================== */
try {
  // Token 已在 token_auth.php 中间件强制校验通过
  $cfg = loadMailConfig($pdo);
  $recipients = loadRecipients($pdo);

  $nearest   = pickNearestSolarSeason($pdo, $NEXT_THRESHOLD_DAYS);
  $seasonIds = $nearest['season']['id'] ? [(int)$nearest['season']['id']] : [];

  $outfits = pickOutfits($pdo, $seasonIds, $PICK_OUTFITS);
  $outfitItemsMap = [];
  foreach ($outfits as $o) {
    $outfitItemsMap[(int)$o['id']] = loadOutfitItems($pdo, (int)$o['id']);
  }
  $clothes = pickClothes($pdo, $seasonIds, $PICK_CLOTHES);

  $subject = $SUBJECT_PREFIX.'｜'.($nearest['season']['name'] ?? '本季').' · '.($nearest['term']['name'] ?? '');
  $html    = buildEmailHtml($BASE_URL, $nearest, $outfits, $outfitItemsMap, $clothes);

  sendMail($cfg, $recipients, $subject, $html);
} catch (Throwable $ex) {
  http_response_code(500);
  echo "[ERR] ".$ex->getMessage();
}

