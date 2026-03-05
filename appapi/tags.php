<?php
/**
 * appapi/tags.php
 * 标签管理 API
 *
 * GET    ?action=list      获取所有标签
 * POST   ?action=create    新增标签
 * POST   ?action=update    更新标签
 * POST   ?action=delete    删除标签
 */
declare(strict_types=1);
require_once __DIR__ . '/init.php';

$action = api_str('action', 'list');

switch ($action) {

    case 'list':
        api_require_method('GET');
        $rows = $pdo->query("SELECT id, name FROM tags ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; }
        unset($r);
        api_success($rows);

    case 'create':
        api_require_method('POST');
        $input = api_input();
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') api_error('标签名称不能为空');

        $stmt = $pdo->prepare("INSERT INTO tags (name) VALUES (?)");
        $stmt->execute([$name]);
        $newId = (int)$pdo->lastInsertId();

        api_success(['id' => $newId], '创建成功', 201);

    case 'update':
        api_require_method('POST');
        $input = api_input();
        $id   = (int)($input['id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));

        if ($id <= 0) api_error('缺少参数 id');
        if ($name === '') api_error('标签名称不能为空');

        $stmt = $pdo->prepare("UPDATE tags SET name = ? WHERE id = ?");
        $stmt->execute([$name, $id]);

        if ($stmt->rowCount() === 0) api_error('标签不存在', 404);
        api_success(null, '更新成功');

    case 'delete':
        api_require_method('POST');
        $input = api_input();
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) api_error('缺少参数 id');

        // 删除关联关系
        $pdo->prepare("DELETE FROM clothes_tags WHERE tag_id = ?")->execute([$id]);

        $stmt = $pdo->prepare("DELETE FROM tags WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) api_error('标签不存在', 404);
        api_success(null, '删除成功');

    default:
        api_error('未知操作: ' . $action);
}
