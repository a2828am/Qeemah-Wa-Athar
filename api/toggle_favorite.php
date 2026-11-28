<?php
/**
 * api/toggle_favorite.php — adds/removes a product from the user's favourites.
 *   POST product_id
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('استخدم POST لهذا الإجراء.', 405);
}
if (Auth::guest()) {
    json_err('سجّل دخولك لإضافة المنتجات إلى المفضلة.', 401, ['login' => true]);
}
require_csrf();

$productId = post_int('product_id', 0) ?? 0;
$product   = $productId ? ProductRepo::find($productId) : null;
if (!$product || $product['status'] === 'removed') {
    json_err('المنتج غير موجود.', 404);
}

try {
    $res = FavoriteRepo::toggle((int)Auth::id(), $productId);
    json_ok([
        'on'      => $res['on'],
        'count'   => $res['count'],
        'message' => $res['on'] ? 'أُضيف إلى المفضلة.' : 'أُزيل من المفضلة.',
    ]);
} catch (Throwable $ex) {
    json_err('تعذّر تحديث المفضلة.', 500);
}
