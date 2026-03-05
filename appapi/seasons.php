<?php
/**
 * appapi/seasons.php
 * 季节管理 API
 *
 * GET    ?action=list      获取所有季节
 * POST   ?action=create    新增季节
 * POST   ?action=update    更新季节
 * POST   ?action=toggle    切换启用/禁用状态
 * POST   ?action=delete    删除季节
 */
declare(strict_types=1);
require_once __DIR__ . '/init.php';

$action = api_str('action', 'list');

switch ($action) {

    case 'list':
        api_require_method('GET');
        $rows = $pdo->query("SELECT id, name, temp_min, temp_max, sort_order, is_active, notes, created_at, updated_at FROM seasons ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']         = (int)$r['id'];
            $r['temp_min']   = $r['temp_min'] !== null ? (float)$r['temp_min'] : null;
            $r['temp_max']   = $r['temp_max'] !== null ? (float)$r['temp_max'] : null;
            $r['sort_order'] = (int)$r['sort_order'];
            $r['is_active']  = (bool)(int)$r['is_active'];
        }
        unset($r);
        api_success($rows);

    case 'create':
        api_require_method('POST');
        $input = api_input();

        $name      = trim((string)($input['name'] ?? ''));
        $tempMin   = ($input['temp_min'] ?? '') !== '' ? (float)$input['temp_min'] : null;
        $tempMax   = ($input['temp_max'] ?? '') !== '' ? (float)$input['temp_max'] : null;
        $sortOrder = (int)($input['sort_order'] ?? 0);
        $isActive  = (int)($input['is_active'] ?? 1);
        $notes     = trim((string)($input['notes'] ?? ''));

        if ($name === '') api_error('季节名称不能为空');

        $stmt = $pdo->prepare("INSERT INTO seasons (name, temp_min, temp_max, sort_order, is_active, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $tempMin, $tempMax, $sortOrder, $isActive, $notes]);
        $newId = (int)$pdo->lastInsertId();

        api_success(['id' => $newId], '创建成功', 201);

    case 'update':
        api_require_method('POST');
        $input = api_input();

        $id        = (int)($input['id'] ?? 0);
        $name      = trim((string)($input['name'] ?? ''));
        $tempMin   = ($input['temp_min'] ?? '') !== '' ? (float)$input['temp_min'] : null;
        $tempMax   = ($input['temp_max'] ?? '') !== '' ? (float)$input['temp_max'] : null;
        $sortOrder = (int)($input['sort_order'] ?? 0);
        $isActive  = (int)($input['is_active'] ?? 1);
        $notes     = trim((string)($input['notes'] ?? ''));

        if ($id <= 0) api_error('缺少参数 id');
        if ($name === '') api_error('季节名称不能为空');

        $stmt = $pdo->prepare("UPDATE seasons SET name=?, temp_min=?, temp_max=?, sort_order=?, is_active=?, notes=? WHERE id=?");
        $stmt->execute([$name, $tempMin, $tempMax, $sortOrder, $isActive, $notes, $id]);

        if ($stmt->rowCount() === 0) api_error('季节不存在', 404);
        api_success(null, '更新成功');

    case 'toggle':
        api_require_method('POST');
        $input = api_input();
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) api_error('缺少参数 id');

        $stmt = $pdo->prepare("UPDATE seasons SET is_active = 1 - is_active WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) api_error('季节不存在', 404);

        // 返回新状态
        $st = $pdo->prepare("SELECT is_active FROM seasons WHERE id = ?");
        $st->execute([$id]);
        $newState = (bool)(int)$st->fetchColumn();

        api_success(['is_active' => $newState], '切换成功');

    case 'delete':
        api_require_method('POST');
        $input = api_input();
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) api_error('缺少参数 id');

        // 删除关联
        $pdo->prepare("DELETE FROM clothes_seasons WHERE season_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM outfit_seasons WHERE season_id = ?")->execute([$id]);

        $stmt = $pdo->prepare("DELETE FROM seasons WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) api_error('季节不存在', 404);
        api_success(null, '删除成功');

    default:
        api_error('未知操作: ' . $action);
}
