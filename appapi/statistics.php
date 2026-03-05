<?php
/**
 * appapi/statistics.php
 * 价格统计 API
 *
 * GET ?action=overview   总览统计（总价、各分类汇总）
 */
declare(strict_types=1);
require_once __DIR__ . '/init.php';

$action = api_str('action', 'overview');

switch ($action) {

    case 'overview':
        api_require_method('GET');

        // 总计
        $totalRow = $pdo->query("SELECT COUNT(*) AS total_count, SUM(price) AS total_price FROM clothes")->fetch(PDO::FETCH_ASSOC);
        $grandTotal = (float)($totalRow['total_price'] ?? 0);
        $totalCount = (int)($totalRow['total_count'] ?? 0);

        // 按分类统计
        $categories = $pdo->query("SELECT id, name FROM categories ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        $catStats = [];

        foreach ($categories as $cat) {
            $catId = (int)$cat['id'];

            $catStmt = $pdo->prepare("SELECT COUNT(*) AS cnt, SUM(price) AS total FROM clothes WHERE category_id = ?");
            $catStmt->execute([$catId]);
            $catRow = $catStmt->fetch(PDO::FETCH_ASSOC);
            $catTotal = (float)($catRow['total'] ?? 0);
            $catCount = (int)($catRow['cnt'] ?? 0);

            // 子分类统计
            $subStmt = $pdo->prepare("SELECT id, name FROM subcategories WHERE category_id = ? ORDER BY sort_order, id");
            $subStmt->execute([$catId]);
            $subs = $subStmt->fetchAll(PDO::FETCH_ASSOC);

            $subStats = [];
            foreach ($subs as $sub) {
                $subTotalStmt = $pdo->prepare("SELECT COUNT(price) AS cnt, SUM(price) AS total FROM clothes WHERE subcategory_id = ?");
                $subTotalStmt->execute([$sub['id']]);
                $subRow   = $subTotalStmt->fetch(PDO::FETCH_ASSOC);
                $subTotal = (float)($subRow['total'] ?? 0);
                $subCount = (int)($subRow['cnt'] ?? 0);
                $avgPrice = $subCount > 0 ? round($subTotal / $subCount, 2) : 0;

                $subStats[] = [
                    'subcategory_id'   => (int)$sub['id'],
                    'subcategory_name' => $sub['name'],
                    'count'            => $subCount,
                    'total_price'      => $subTotal,
                    'avg_price'        => $avgPrice,
                ];
            }

            $catStats[] = [
                'category_id'   => $catId,
                'category_name' => $cat['name'],
                'count'         => $catCount,
                'total_price'   => $catTotal,
                'subcategories' => $subStats,
            ];
        }

        api_success([
            'grand_total'  => $grandTotal,
            'total_count'  => $totalCount,
            'categories'   => $catStats,
        ]);

    default:
        api_error('未知操作: ' . $action);
}
