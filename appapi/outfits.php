<?php
/**
 * appapi/outfits.php
 * 穿搭组合管理 API
 *
 * GET    ?action=list              穿搭列表（支持分页、季节筛选、关键字搜索）
 * GET    ?action=detail&id=        穿搭详情
 * POST   ?action=create            创建穿搭
 * POST   ?action=update            编辑穿搭
 * POST   ?action=delete            删除穿搭
 */
declare(strict_types=1);
require_once __DIR__ . '/init.php';

$action = api_str('action', 'list');

switch ($action) {

    // ===================== 穿搭列表 =====================
    case 'list':
        api_require_method('GET');

        $q         = api_str('q');
        $seasonRaw = $_GET['season_ids'] ?? [];
        $page      = max(1, api_int('page', 1));
        $perPage   = max(1, min(100, api_int('per_page', 24)));
        $offset    = ($page - 1) * $perPage;

        $seasonIds = [];
        if (is_array($seasonRaw)) {
            foreach ($seasonRaw as $sid) {
                $sid = (int)$sid;
                if ($sid > 0) $seasonIds[] = $sid;
            }
        }

        $where = []; $params = [];
        if ($q !== '') {
            $where[] = "o.name LIKE ?";
            $params[] = "%{$q}%";
        }
        if (!empty($seasonIds)) {
            $in = implode(',', array_fill(0, count($seasonIds), '?'));
            $where[] = "EXISTS (SELECT 1 FROM outfit_seasons os WHERE os.outfit_id = o.id AND os.season_id IN ($in))";
            array_push($params, ...$seasonIds);
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // 总数
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM outfits o $whereSql");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // 列表
        $sqlList = "
            SELECT o.id, o.name, o.notes, o.created_at, o.updated_at
            FROM outfits o $whereSql
            ORDER BY o.updated_at DESC, o.id DESC
            LIMIT $perPage OFFSET $offset
        ";
        $stmt = $pdo->prepare($sqlList);
        $stmt->execute($params);
        $outfits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $outfitIds = array_map(fn($r) => (int)$r['id'], $outfits);

        // 季节标签
        $seasonsMap = [];
        if ($outfitIds) {
            $in = implode(',', array_fill(0, count($outfitIds), '?'));
            $stm = $pdo->prepare("
                SELECT os.outfit_id, s.id, s.name
                FROM outfit_seasons os JOIN seasons s ON s.id = os.season_id
                WHERE os.outfit_id IN ($in) ORDER BY s.id
            ");
            $stm->execute($outfitIds);
            while ($row = $stm->fetch(PDO::FETCH_ASSOC)) {
                $seasonsMap[(int)$row['outfit_id']][] = ['id' => (int)$row['id'], 'name' => $row['name']];
            }
        }

        // 每套前4件衣物缩略图
        $thumbsMap = [];
        if ($outfitIds) {
            $in = implode(',', array_fill(0, count($outfitIds), '?'));
            $sti = $pdo->prepare("
                SELECT oi.outfit_id, c.id AS clothes_id, c.name, c.image_path
                FROM outfit_items oi JOIN clothes c ON c.id = oi.clothes_id
                WHERE oi.outfit_id IN ($in)
                ORDER BY oi.order_index ASC, oi.id ASC
            ");
            $sti->execute($outfitIds);
            while ($row = $sti->fetch(PDO::FETCH_ASSOC)) {
                $oid = (int)$row['outfit_id'];
                if (!isset($thumbsMap[$oid])) $thumbsMap[$oid] = [];
                if (count($thumbsMap[$oid]) < 4) {
                    $thumbsMap[$oid][] = [
                        'clothes_id' => (int)$row['clothes_id'],
                        'name'       => $row['name'],
                        'image_path' => $row['image_path'],
                    ];
                }
            }
        }

        // 组装结果
        foreach ($outfits as &$o) {
            $oid = (int)$o['id'];
            $o['id']      = $oid;
            $o['seasons'] = $seasonsMap[$oid] ?? [];
            $o['preview_items'] = $thumbsMap[$oid] ?? [];
        }
        unset($o);

        api_success([
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'list'     => $outfits,
        ]);

    // ===================== 穿搭详情 =====================
    case 'detail':
        api_require_method('GET');
        $id = api_int('id');
        if ($id <= 0) api_error('缺少参数 id');

        $st = $pdo->prepare("SELECT id, name, notes, created_at, updated_at FROM outfits WHERE id = ?");
        $st->execute([$id]);
        $outfit = $st->fetch(PDO::FETCH_ASSOC);
        if (!$outfit) api_error('穿搭不存在', 404);

        $outfit['id'] = (int)$outfit['id'];

        // 季节
        $st = $pdo->prepare("
            SELECT s.id, s.name FROM outfit_seasons os JOIN seasons s ON s.id = os.season_id
            WHERE os.outfit_id = ? ORDER BY s.id
        ");
        $st->execute([$id]);
        $outfit['seasons'] = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($outfit['seasons'] as &$s) { $s['id'] = (int)$s['id']; }
        unset($s);

        // 衣物列表
        $sti = $pdo->prepare("
            SELECT oi.order_index, c.id AS clothes_id, c.name AS clothes_name,
                   c.image_path, cat.name AS category_name
            FROM outfit_items oi
            JOIN clothes c ON c.id = oi.clothes_id
            JOIN categories cat ON cat.id = c.category_id
            WHERE oi.outfit_id = ?
            ORDER BY oi.order_index ASC, oi.id ASC
        ");
        $sti->execute([$id]);
        $outfit['items'] = $sti->fetchAll(PDO::FETCH_ASSOC);
        foreach ($outfit['items'] as &$item) {
            $item['clothes_id']  = (int)$item['clothes_id'];
            $item['order_index'] = (int)$item['order_index'];
        }
        unset($item);

        api_success($outfit);

    // ===================== 创建穿搭 =====================
    case 'create':
        api_require_method('POST');
        $input = api_input();

        $name       = trim((string)($input['name'] ?? ''));
        $notes      = trim((string)($input['notes'] ?? ''));
        $seasonIds  = $input['season_ids'] ?? [];
        $clothesIds = $input['clothes_ids'] ?? [];

        if ($name === '') api_error('请填写组合名称');
        if (empty($clothesIds) || !is_array($clothesIds)) api_error('请至少选择一件衣物');

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO outfits(name, notes) VALUES(?, ?)")->execute([$name, $notes]);
            $oid = (int)$pdo->lastInsertId();

            if (is_array($seasonIds)) {
                $insS = $pdo->prepare("INSERT INTO outfit_seasons(outfit_id, season_id) VALUES(?, ?)");
                foreach ($seasonIds as $sid) {
                    $sid = (int)$sid;
                    if ($sid > 0) $insS->execute([$oid, $sid]);
                }
            }

            $insI = $pdo->prepare("INSERT INTO outfit_items(outfit_id, clothes_id, order_index) VALUES(?, ?, ?)");
            $order = 0;
            foreach ($clothesIds as $cid) {
                $cid = (int)$cid;
                if ($cid > 0) {
                    $insI->execute([$oid, $cid, $order++]);
                }
            }

            $pdo->commit();
            api_success(['id' => $oid], '创建成功', 201);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            api_error('保存失败：' . $e->getMessage(), 500);
        }

    // ===================== 编辑穿搭 =====================
    case 'update':
        api_require_method('POST');
        $input = api_input();

        $id         = (int)($input['id'] ?? 0);
        $name       = trim((string)($input['name'] ?? ''));
        $notes      = trim((string)($input['notes'] ?? ''));
        $seasonIds  = $input['season_ids'] ?? [];
        $clothesIds = $input['clothes_ids'] ?? [];

        if ($id <= 0) api_error('缺少参数 id');
        if ($name === '') api_error('名称不能为空');

        // 检查存在
        $checkStmt = $pdo->prepare("SELECT id FROM outfits WHERE id = ?");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) api_error('穿搭不存在', 404);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE outfits SET name=?, notes=?, updated_at=NOW() WHERE id=?")
                ->execute([$name, $notes, $id]);

            // 更新季节
            $pdo->prepare("DELETE FROM outfit_seasons WHERE outfit_id=?")->execute([$id]);
            if (is_array($seasonIds)) {
                $insS = $pdo->prepare("INSERT INTO outfit_seasons(outfit_id, season_id) VALUES(?, ?)");
                foreach ($seasonIds as $sid) {
                    $sid = (int)$sid;
                    if ($sid > 0) $insS->execute([$id, $sid]);
                }
            }

            // 更新衣物
            $pdo->prepare("DELETE FROM outfit_items WHERE outfit_id=?")->execute([$id]);
            if (is_array($clothesIds)) {
                $insI = $pdo->prepare("INSERT INTO outfit_items(outfit_id, clothes_id, order_index) VALUES(?, ?, ?)");
                $order = 0;
                foreach ($clothesIds as $cid) {
                    $cid = (int)$cid;
                    if ($cid > 0) $insI->execute([$id, $cid, $order++]);
                }
            }

            $pdo->commit();
            api_success(null, '修改成功');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            api_error('保存失败：' . $e->getMessage(), 500);
        }

    // ===================== 删除穿搭 =====================
    case 'delete':
        api_require_method('POST');
        $input = api_input();
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) api_error('缺少参数 id');

        $checkStmt = $pdo->prepare("SELECT id FROM outfits WHERE id = ?");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) api_error('穿搭不存在', 404);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM outfit_items WHERE outfit_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM outfit_seasons WHERE outfit_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM outfits WHERE id = ?")->execute([$id]);
            $pdo->commit();
            api_success(null, '删除成功');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            api_error('删除失败：' . $e->getMessage(), 500);
        }

    default:
        api_error('未知操作: ' . $action);
}
