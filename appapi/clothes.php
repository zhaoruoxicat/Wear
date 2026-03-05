<?php
/**
 * appapi/clothes.php
 * 衣物管理 API
 *
 * GET    ?action=list        获取衣物列表（按分类分组）
 * GET    ?action=detail&id=  获取衣物详情
 * GET    ?action=filter      筛选衣物
 * POST   ?action=add         添加衣物（支持图片上传 multipart/form-data）
 * POST   ?action=update      修改衣物信息
 * POST   ?action=delete      删除衣物
 */
declare(strict_types=1);
require_once __DIR__ . '/init.php';

$action = api_str('action', 'list');

switch ($action) {

    // ===================== 衣物列表（按分类分组） =====================
    case 'list':
        api_require_method('GET');

        $categories = $pdo->query("SELECT id, name, sort_order FROM categories ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        $result = [];

        foreach ($categories as $cat) {
            $stmt = $pdo->prepare("
                SELECT c.id, c.name, c.image_path, c.brand, c.size, c.price, c.purchase_date,
                       c.location, c.created_at,
                       sub.name AS subcategory_name
                FROM clothes c
                JOIN subcategories sub ON c.subcategory_id = sub.id
                WHERE c.category_id = ?
                ORDER BY c.sort_order ASC, c.created_at DESC
            ");
            $stmt->execute([(int)$cat['id']]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 类型规范化
            foreach ($items as &$item) {
                $item['id']    = (int)$item['id'];
                $item['price'] = $item['price'] !== null ? (float)$item['price'] : null;
            }
            unset($item);

            $result[] = [
                'category_id'   => (int)$cat['id'],
                'category_name' => $cat['name'],
                'sort_order'    => (int)$cat['sort_order'],
                'items'         => $items,
            ];
        }
        api_success($result);

    // ===================== 衣物详情 =====================
    case 'detail':
        api_require_method('GET');
        $id = api_int('id');
        if ($id <= 0) api_error('缺少参数 id');

        $stmt = $pdo->prepare("
            SELECT c.*,
                   cat.name AS category_name,
                   sub.name AS subcategory_name,
                   s.name   AS source_name
            FROM clothes c
            JOIN categories    cat ON c.category_id    = cat.id
            JOIN subcategories sub ON c.subcategory_id = sub.id
            LEFT JOIN sources  s   ON c.source_id      = s.id
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) api_error('衣物不存在', 404);

        $item['id']    = (int)$item['id'];
        $item['price'] = $item['price'] !== null ? (float)$item['price'] : null;

        // 标签
        $tagStmt = $pdo->prepare("SELECT t.id, t.name FROM tags t JOIN clothes_tags ct ON ct.tag_id = t.id WHERE ct.clothes_id = ?");
        $tagStmt->execute([$id]);
        $item['tags'] = $tagStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($item['tags'] as &$t) { $t['id'] = (int)$t['id']; }
        unset($t);

        // 季节
        $seasonStmt = $pdo->prepare("SELECT se.id, se.name FROM seasons se JOIN clothes_seasons cs ON cs.season_id = se.id WHERE cs.clothes_id = ?");
        $seasonStmt->execute([$id]);
        $item['seasons'] = $seasonStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($item['seasons'] as &$s) { $s['id'] = (int)$s['id']; }
        unset($s);

        api_success($item);

    // ===================== 筛选衣物 =====================
    case 'filter':
        api_require_method('GET');

        $categoryId    = api_int('category_id');
        $subcategoryId = api_int('subcategory_id');
        $seasonIdsRaw  = $_GET['season_ids'] ?? [];
        $keyword       = api_str('keyword');
        $page          = max(1, api_int('page', 1));
        $perPage       = max(1, min(100, api_int('per_page', 50)));
        $offset        = ($page - 1) * $perPage;

        $seasonIds = [];
        if (is_array($seasonIdsRaw)) {
            foreach ($seasonIdsRaw as $sid) {
                $sid = (int)$sid;
                if ($sid > 0) $seasonIds[] = $sid;
            }
        }

        $where = []; $params = [];

        if ($categoryId > 0) {
            $where[] = "c.category_id = ?";
            $params[] = $categoryId;
        }
        if ($subcategoryId > 0) {
            $where[] = "c.subcategory_id = ?";
            $params[] = $subcategoryId;
        }
        if ($keyword !== '') {
            $where[] = "(c.name LIKE ? OR c.brand LIKE ? OR c.notes LIKE ?)";
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
            $params[] = "%{$keyword}%";
        }

        $joinSeason = '';
        if (!empty($seasonIds)) {
            $ph = implode(',', array_fill(0, count($seasonIds), '?'));
            $joinSeason = "INNER JOIN clothes_seasons csf ON csf.clothes_id = c.id AND csf.season_id IN ($ph)";
            $params = array_merge($seasonIds, $params);
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // 总数
        $countSql = "SELECT COUNT(DISTINCT c.id) FROM clothes c $joinSeason $whereSql";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // 列表
        $sql = "
            SELECT DISTINCT c.id, c.name, c.image_path, c.brand, c.price, c.size,
                   cat.name AS category_name, sub.name AS subcategory_name,
                   GROUP_CONCAT(DISTINCT se.name ORDER BY se.id SEPARATOR '，') AS season_names
            FROM clothes c
            JOIN categories    cat ON cat.id = c.category_id
            JOIN subcategories sub ON sub.id = c.subcategory_id
            LEFT JOIN clothes_seasons cs ON cs.clothes_id = c.id
            LEFT JOIN seasons se ON se.id = cs.season_id
            $joinSeason
            $whereSql
            GROUP BY c.id
            ORDER BY c.id DESC
            LIMIT $perPage OFFSET $offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($list as &$row) {
            $row['id']    = (int)$row['id'];
            $row['price'] = $row['price'] !== null ? (float)$row['price'] : null;
        }
        unset($row);

        api_success([
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'list'     => $list,
        ]);

    // ===================== 添加衣物 =====================
    case 'add':
        api_require_method('POST');
        $input = api_input();

        $category_id    = (int)($input['category_id'] ?? 0);
        $subcategory_id = (int)($input['subcategory_id'] ?? 0);
        $name           = trim((string)($input['name'] ?? ''));
        $location       = trim((string)($input['location'] ?? ''));
        $brand          = trim((string)($input['brand'] ?? ''));
        $price          = ($input['price'] ?? '') !== '' ? (float)$input['price'] : null;
        $size           = trim((string)($input['size'] ?? ''));
        $source_id      = ($input['source_id'] ?? '') !== '' ? (int)$input['source_id'] : null;
        $purchase_date  = ($input['purchase_date'] ?? '') !== '' ? (string)$input['purchase_date'] : null;
        $notes          = trim((string)($input['notes'] ?? ''));
        $tag_ids        = $input['tag_ids'] ?? [];
        $season_ids     = $input['season_ids'] ?? [];

        if ($category_id <= 0 || $subcategory_id <= 0) {
            api_error('分类和子分类不能为空');
        }

        // 图片上传
        $image_path = null;
        if (!empty($_FILES['image']['name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                $ext = 'jpg';
            }
            $uploadDir = dirname(__DIR__) . '/uploads';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $filename = 'uploads/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], dirname(__DIR__) . '/' . $filename)) {
                $image_path = $filename;
            }
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO clothes
                (name, category_id, subcategory_id, location, brand, price, size, source_id, purchase_date, notes, image_path)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $category_id, $subcategory_id, $location, $brand, $price, $size,
                $source_id, $purchase_date, $notes, $image_path
            ]);
            $clothes_id = (int)$pdo->lastInsertId();

            // 标签
            if (is_array($tag_ids)) {
                $insTag = $pdo->prepare("INSERT INTO clothes_tags (clothes_id, tag_id) VALUES (?, ?)");
                foreach ($tag_ids as $tid) {
                    $tid = (int)$tid;
                    if ($tid > 0) $insTag->execute([$clothes_id, $tid]);
                }
            }

            // 季节
            if (is_array($season_ids)) {
                $insSeason = $pdo->prepare("INSERT INTO clothes_seasons (clothes_id, season_id) VALUES (?, ?)");
                foreach ($season_ids as $sid) {
                    $sid = (int)$sid;
                    if ($sid > 0) $insSeason->execute([$clothes_id, $sid]);
                }
            }

            $pdo->commit();
            api_success(['id' => $clothes_id], '添加成功', 201);
        } catch (Throwable $e) {
            $pdo->rollBack();
            api_error('保存失败：' . $e->getMessage(), 500);
        }

    // ===================== 修改衣物 =====================
    case 'update':
        api_require_method('POST');
        $input = api_input();

        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) api_error('缺少参数 id');

        // 检查是否存在
        $checkStmt = $pdo->prepare("SELECT id, image_path FROM clothes WHERE id = ?");
        $checkStmt->execute([$id]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) api_error('衣物不存在', 404);

        $category_id    = (int)($input['category_id'] ?? 0);
        $subcategory_id = (int)($input['subcategory_id'] ?? 0);
        $name           = isset($input['name']) ? trim((string)$input['name']) : null;
        $name           = ($name === '' ? null : $name);
        $location       = trim((string)($input['location'] ?? ''));
        $brand          = trim((string)($input['brand'] ?? ''));
        $price          = ($input['price'] ?? '') !== '' ? (float)$input['price'] : null;
        $size           = trim((string)($input['size'] ?? ''));
        $source_id      = ($input['source_id'] ?? '') !== '' ? (int)$input['source_id'] : null;
        $purchase_date  = ($input['purchase_date'] ?? '') !== '' ? (string)$input['purchase_date'] : null;
        $notes          = (string)($input['notes'] ?? '');
        $sort_order     = (int)($input['sort_order'] ?? 0);
        $tag_ids        = $input['tag_ids'] ?? [];
        $season_ids     = $input['season_ids'] ?? [];

        // 图片
        $image_path = $existing['image_path'];
        if (!empty($_FILES['image']['name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION)) ?: 'jpg';
            $uploadDir = dirname(__DIR__) . '/uploads';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $filename = 'uploads/' . uniqid('cloth_', true) . '.' . $ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], dirname(__DIR__) . '/' . $filename)) {
                $image_path = $filename;
            }
        }

        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare("
                UPDATE clothes
                SET name=?, category_id=?, subcategory_id=?, location=?, brand=?, price=?, size=?,
                    source_id=?, purchase_date=?, notes=?, image_path=?, sort_order=?
                WHERE id=?
            ");
            $up->execute([$name, $category_id, $subcategory_id, $location, $brand, $price, $size,
                          $source_id, $purchase_date, $notes, $image_path, $sort_order, $id]);

            // 更新标签
            $pdo->prepare("DELETE FROM clothes_tags WHERE clothes_id=?")->execute([$id]);
            if (is_array($tag_ids)) {
                $ins = $pdo->prepare("INSERT INTO clothes_tags (clothes_id, tag_id) VALUES (?, ?)");
                foreach ($tag_ids as $tid) {
                    $tid = (int)$tid;
                    if ($tid > 0) $ins->execute([$id, $tid]);
                }
            }

            // 更新季节
            $pdo->prepare("DELETE FROM clothes_seasons WHERE clothes_id=?")->execute([$id]);
            if (is_array($season_ids)) {
                $ins = $pdo->prepare("INSERT INTO clothes_seasons (clothes_id, season_id) VALUES (?, ?)");
                foreach ($season_ids as $sid) {
                    $sid = (int)$sid;
                    if ($sid > 0) $ins->execute([$id, $sid]);
                }
            }

            $pdo->commit();
            api_success(null, '修改成功');
        } catch (Throwable $e) {
            $pdo->rollBack();
            api_error('保存失败：' . $e->getMessage(), 500);
        }

    // ===================== 删除衣物 =====================
    case 'delete':
        api_require_method('POST');
        $input = api_input();
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) api_error('缺少参数 id');

        $stmt = $pdo->prepare("SELECT * FROM clothes WHERE id = ?");
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) api_error('衣物不存在', 404);

        // 删除图片文件
        if (!empty($item['image_path'])) {
            $imagePath = dirname(__DIR__) . '/' . $item['image_path'];
            if (file_exists($imagePath)) {
                @unlink($imagePath);
            }
        }

        $pdo->prepare("DELETE FROM clothes_tags WHERE clothes_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM clothes_seasons WHERE clothes_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM clothes WHERE id = ?")->execute([$id]);

        api_success(null, '删除成功');

    default:
        api_error('未知操作: ' . $action);
}
