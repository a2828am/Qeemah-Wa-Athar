<?php
/**
 * api/report.php — content report / complaint endpoint.
 *   POST entity_type=product|user|message, entity_id, reason, details?
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('استخدم POST لإرسال البلاغ.', 405);
}
if (Auth::guest()) {
    json_err('سجّل دخولك لإرسال بلاغ.', 401, ['login' => true]);
}
require_csrf();

$me         = Auth::user();
$entityType = (string)post('entity_type', 'product');
$entityId   = post_int('entity_id', 0) ?? 0;
$reason     = (string)post('reason', 'other');
$details    = (string)post('details', '');

$allowedTypes = ['product', 'user', 'message', 'association'];
if (!in_array($entityType, $allowedTypes, true)) {
    json_err('نوع الكيان غير مدعوم.');
}
if ($entityId <= 0) {
    json_err('حدّد العنصر المُبلَّغ عنه.');
}
$allowedReasons = ['inappropriate', 'fake', 'scam', 'prohibited', 'spam', 'other'];
if (!in_array($reason, $allowedReasons, true)) {
    $reason = 'other';
}

if ($entityType === 'product') {
    $product = ProductRepo::find($entityId);
    if (!$product) {
        json_err('المنتج غير موجود.', 404);
    }
    if ((int)$product['owner_id'] === (int)$me['id']) {
        json_err('لا يمكنك الإبلاغ عن منتجك.');
    }
    $already = (bool)Repo::q(
        'SELECT 1 FROM product_reports WHERE product_id=? AND reporter_id=? LIMIT 1',
        [$entityId, (int)$me['id']]
    );
    if ($already) {
        json_err('سبق أن أبلغت عن هذا المنتج، وقيد المراجعة لدى الفريق.');
    }
}

try {
    $id = ModerationRepo::reportEntity((int)$me['id'], $entityType, $entityId, $reason, $details !== '' ? $details : null);
    json_ok([
        'message'  => 'تم إرسال البلاغ رقم #' . $id . ' إلى فريق الإدارة. شكراً لمساعدتك في الحفاظ على المجتمع.',
        'readonly' => true,
    ]);
} catch (Throwable $ex) {
    json_err('تعذّر تسجيل البلاغ.', 500);
}
