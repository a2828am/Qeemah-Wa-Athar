<?php
/**
 * api/admin_action.php — generic privileged actions used by the admin panel.
 * Every branch writes an immutable admin_audit_logs row.
 *
 *   action=suspend|activate     user_id
 *   action=product_remove       product_id
 *   action=product_restore      product_id
 *   action=assoc_verify|assoc_reject association_id, note?
 *   action=report_resolve       report_id, status=resolved|rejected, note?
 *   action=setting              key, value
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('استخدم POST لهذا الإجراء.', 405);
}
Gate::requireAdmin();
require_csrf();

$me      = Auth::user();
$adminId = (int)$me['id'];
$action  = (string)post('action', '');

try {
    switch ($action) {
        case 'suspend':
        case 'activate':
            $userId = post_int('user_id', 0) ?? 0;
            $target = UserRepo::find($userId);
            if (!$target) {
                json_err('المستخدم غير موجود.', 404);
            }
            if ($userId === $adminId) {
                json_err('لا يمكنك إيقاف حسابك الشخصي.', 422);
            }
            $status = $action === 'suspend' ? 'suspended' : 'active';
            UserRepo::setStatus($userId, $status);
            AuditRepo::log($adminId, 'user.' . $action, 'user', $userId, ['status' => $target['status']], ['status' => $status]);
            json_ok(['message' => $action === 'suspend' ? 'تم إيقاف الحساب.' : 'تم تفعيل الحساب.', 'reload' => true]);

        case 'product_remove':
        case 'product_restore':
            $productId = post_int('product_id', 0) ?? 0;
            $product = ProductRepo::find($productId);
            if (!$product) {
                json_err('المنتج غير موجود.', 404);
            }
            $newStatus = $action === 'product_remove' ? 'removed' : 'available';
            ProductRepo::setStatus($productId, $newStatus);
            AuditRepo::log($adminId, 'product.' . ($action === 'product_remove' ? 'removed' : 'restored'),
                'product', $productId, ['status' => $product['status']], ['status' => $newStatus]);
            NotificationRepo::push((int)$product['owner_id'], 'system',
                $action === 'product_remove' ? 'تم إزالة منتجك' : 'تمت إعادة منتجك',
                'الإعلان: ' . excerpt((string)$product['title'], 60) . ($action === 'product_remove' ? ' — خالف شروط النشر.' : ' — أصبح متاحاً من جديد.'),
                'my_requests.php?tab=listings', 'package');
            json_ok(['message' => $action === 'product_remove' ? 'تم إزالة المنتج من السوق.' : 'تمت إعادة المنتج إلى السوق.', 'reload' => true]);

        case 'assoc_verify':
        case 'assoc_reject':
        case 'assoc_back_to_pending':
            $assocId = post_int('association_id', 0) ?? 0;
            $assoc = AssociationRepo::find($assocId);
            if (!$assoc) {
                json_err('الجمعية غير موجودة.', 404);
            }
            $newStatus = $action === 'assoc_verify' ? 'verified' : ($action === 'assoc_reject' ? 'rejected' : 'pending');
            AssociationRepo::setStatus($assocId, $newStatus, $adminId);
            Repo::exec(
                'UPDATE users SET status=? WHERE id=?',
                [$newStatus === 'verified' ? 'active' : ($newStatus === 'rejected' ? 'suspended' : 'pending'), (int)$assoc['owner_user_id']]
            );
            Repo::exec(
                "UPDATE login_requests SET status=?, resolved_by=?, resolved_at=? WHERE user_id=? AND kind='assoc_approval' AND status='pending'",
                [$newStatus === 'verified' ? 'approved' : 'denied', $adminId, now(), (int)$assoc['owner_user_id']]
            );
            AuditRepo::log($adminId, 'association.' . $newStatus,
                'association', $assocId, ['status' => $assoc['status']], ['status' => $newStatus]);
            NotificationRepo::push((int)$assoc['owner_user_id'], 'system',
                $newStatus === 'verified' ? 'تم توثيق جمعيتكم' : ($newStatus === 'rejected' ? 'لم يُقبل طلب توثيق الجمعية' : 'عادت جمعيتكم لقائمة المراجعة'),
                $newStatus === 'verified'
                    ? 'أصبح بإمكانكم الآن استلام التبرّعات وإصدار الإيصالات.'
                    : ($newStatus === 'rejected' ? 'يرجى مراجعة بيانات الترخيص والتواصل مع الإدارة.' : 'سيعاد النظر في حالة الجمعية قريباً.'),
                'charity/dashboard.php', 'landmark');
            json_ok([
                'message' => $newStatus === 'verified' ? 'تم توثيق الجمعية.' : ($newStatus === 'rejected' ? 'تم رفض التوثيق.' : 'أُعيدت الجمعية لقائمة المراجعة.'),
                'reload'  => true,
            ]);

        case 'report_resolve':
            $reportId = post_int('report_id', 0) ?? 0;
            $status   = (string)post('status', 'resolved');
            if (!in_array($status, ['resolved', 'rejected', 'open'], true)) {
                json_err('حالة غير صحيحة.');
            }
            ModerationRepo::resolveReport($reportId, $status, $adminId, post('note'));
            AuditRepo::log($adminId, 'report.' . $status, 'report', $reportId, null, ['status' => $status]);
            json_ok(['message' => 'تم تحديث حالة البلاغ.', 'reload' => true]);

        case 'setting':
            Gate::requireFullAdmin();
            $key   = (string)post('setting_key', '');
            $value = post('setting_value', '');
            if ($key === '') {
                json_err('مفتاح الإعداد مطلوب.');
            }
            $old = Repo::q('SELECT setting_value FROM system_settings WHERE setting_key=?', [$key])[0] ?? null;
            if (!$old) {
                json_err('الإعداد غير موجود.', 404);
            }
            SettingsRepo::set($key, $value, $adminId);
            AuditRepo::log($adminId, 'setting.updated', 'setting', null, ['key' => $key, 'value' => $old['setting_value']], ['key' => $key, 'value' => $value]);
            json_ok(['message' => 'تم حفظ الإعداد.', 'reload' => true]);

        default:
            json_err('إجراء غير معروف.', 400);
    }
} catch (RuntimeException $ex) {
    json_err($ex->getMessage(), 422);
} catch (Throwable $ex) {
    json_err('تعذّر تنفيذ الإجراء: ' . $ex->getMessage(), 500);
}
