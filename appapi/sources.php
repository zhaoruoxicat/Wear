<?php
/**
 * appapi/sources.php
 * 购买途径管理 API
 *
 * GET    ?action=list      获取所有购买途径
 * POST   ?action=create    新增购买途径
 * POST   ?action=update    更新购买途径
 * POST   ?action=delete    删除购买途径
 */
declare(strict_types=1);
require_once __DIR__ . '/init.php';

$action = api_str('action', 'list');

switch ($action) {

    case 'list':
        api_require_method('GET');
        $rows = $pdo->query("SELECT id, name FROM sources ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; }
        unset($r);
        api_success($rows);

    case 'create':
        api_require_method('POST');
        $input = api_input();
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') api_error('途径名称不能为空');

        $stmt = $pdo->prepare("INSERT INTO sources (name) VALUES (?)");
        $stmt->execute([$name]);
        $newId = (int)$pdo->lastInsertId();

        api_success(['id' => $newId], '创建成功', 201);

    case 'update':
        api_require_method('POST');
        $input = api_input();
        $id   = (int)($input['id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));

        if ($id <= 0) api_error('缺少参数 id');
        if ($name === '') api_error('途径名称不能为空');

        $stmt = $pdo->prepare("UPDATE sources SET name = ? WHERE id = ?");
        $stmt->execute([$name, $id]);

        if ($stmt->rowCount() === 0) api_error('购买途径不存在', 404);
        api_success(null, '更新成功');

    case 'delete':
        api_require_method('POST');
        $input = api_input();
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) api_error('缺少参数 id');

        // 检查关联
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM clothes WHERE source_id = ?");
        $checkStmt->execute([$id]);
        $count = (int)$checkStmt->fetchColumn();
        if ($count > 0) {
            api_error("该途径下还有 {$count} 件衣物关联，请先修改这些衣物的购买途径");
        }

        $stmt = $pdo->prepare("DELETE FROM sources WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) api_error('购买途径不存在', 404);
        api_success(null, '删除成功');

    default:
        api_error('未知操作: ' . $action);
}
