<?php
/**
 * appapi/categories.php
 * 分类管理 API
 *
 * GET    ?action=list                          获取所有分类（含子分类）
 * POST   ?action=create_category               新增主分类
 * POST   ?action=update_category               更新主分类
 * POST   ?action=delete_category               删除主分类（连带子分类）
 * POST   ?action=create_subcategory            新增子分类
 * POST   ?action=update_subcategory            更新子分类
 * POST   ?action=delete_subcategory            删除子分类
 */
declare(strict_types=1);
require_once __DIR__ . '/init.php';

$action = api_str('action', 'list');

switch ($action) {

    // ===================== 列表 =====================
    case 'list':
        api_require_method('GET');

        $categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        $subcategories = $pdo->query("SELECT * FROM subcategories ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);

        $subMap = [];
        foreach ($subcategories as $sub) {
            $subMap[(int)$sub['category_id']][] = [
                'id'         => (int)$sub['id'],
                'name'       => $sub['name'],
                'sort_order' => (int)$sub['sort_order'],
            ];
        }

        $result = [];
        foreach ($categories as $cat) {
            $catId = (int)$cat['id'];
            $result[] = [
                'id'            => $catId,
                'name'          => $cat['name'],
                'sort_order'    => (int)$cat['sort_order'],
                'subcategories' => $subMap[$catId] ?? [],
            ];
        }

        api_success($result);

    // ===================== 新增主分类 =====================
    case 'create_category':
        api_require_method('POST');
        $input = api_input();
        $name = trim((string)($input['name'] ?? ''));
        $sortOrder = (int)($input['sort_order'] ?? 0);

        if ($name === '') api_error('分类名称不能为空');

        $stmt = $pdo->prepare("INSERT INTO categories (name, sort_order) VALUES (?, ?)");
        $stmt->execute([$name, $sortOrder]);
        $newId = (int)$pdo->lastInsertId();

        api_success(['id' => $newId], '创建成功', 201);

    // ===================== 更新主分类 =====================
    case 'update_category':
        api_require_method('POST');
        $input = api_input();
        $id        = (int)($input['id'] ?? 0);
        $name      = trim((string)($input['name'] ?? ''));
        $sortOrder = (int)($input['sort_order'] ?? 0);

        if ($id <= 0) api_error('缺少参数 id');
        if ($name === '') api_error('分类名称不能为空');

        $stmt = $pdo->prepare("UPDATE categories SET name = ?, sort_order = ? WHERE id = ?");
        $stmt->execute([$name, $sortOrder, $id]);

        if ($stmt->rowCount() === 0) api_error('分类不存在', 404);
        api_success(null, '更新成功');

    // ===================== 删除主分类 =====================
    case 'delete_category':
        api_require_method('POST');
        $input = api_input();
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) api_error('缺少参数 id');

        // 检查是否有衣物关联
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM clothes WHERE category_id = ?");
        $checkStmt->execute([$id]);
        $count = (int)$checkStmt->fetchColumn();
        if ($count > 0) {
            api_error("该分类下还有 {$count} 件衣物，请先移除或删除这些衣物");
        }

        $pdo->prepare("DELETE FROM subcategories WHERE category_id = ?")->execute([$id]);
        $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) api_error('分类不存在', 404);
        api_success(null, '删除成功');

    // ===================== 新增子分类 =====================
    case 'create_subcategory':
        api_require_method('POST');
        $input = api_input();
        $categoryId = (int)($input['category_id'] ?? 0);
        $name       = trim((string)($input['name'] ?? ''));
        $sortOrder  = (int)($input['sort_order'] ?? 0);

        if ($categoryId <= 0) api_error('缺少参数 category_id');
        if ($name === '') api_error('子分类名称不能为空');

        $stmt = $pdo->prepare("INSERT INTO subcategories (category_id, name, sort_order) VALUES (?, ?, ?)");
        $stmt->execute([$categoryId, $name, $sortOrder]);
        $newId = (int)$pdo->lastInsertId();

        api_success(['id' => $newId], '创建成功', 201);

    // ===================== 更新子分类 =====================
    case 'update_subcategory':
        api_require_method('POST');
        $input = api_input();
        $id        = (int)($input['id'] ?? 0);
        $name      = trim((string)($input['name'] ?? ''));
        $sortOrder = (int)($input['sort_order'] ?? 0);

        if ($id <= 0) api_error('缺少参数 id');
        if ($name === '') api_error('子分类名称不能为空');

        $stmt = $pdo->prepare("UPDATE subcategories SET name = ?, sort_order = ? WHERE id = ?");
        $stmt->execute([$name, $sortOrder, $id]);

        if ($stmt->rowCount() === 0) api_error('子分类不存在', 404);
        api_success(null, '更新成功');

    // ===================== 删除子分类 =====================
    case 'delete_subcategory':
        api_require_method('POST');
        $input = api_input();
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) api_error('缺少参数 id');

        // 检查是否有衣物关联
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM clothes WHERE subcategory_id = ?");
        $checkStmt->execute([$id]);
        $count = (int)$checkStmt->fetchColumn();
        if ($count > 0) {
            api_error("该子分类下还有 {$count} 件衣物，请先移除或删除这些衣物");
        }

        $stmt = $pdo->prepare("DELETE FROM subcategories WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) api_error('子分类不存在', 404);
        api_success(null, '删除成功');

    default:
        api_error('未知操作: ' . $action);
}
