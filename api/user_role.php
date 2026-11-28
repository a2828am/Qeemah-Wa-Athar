<?php
/**
 * api/user_role.php — grant or revoke a role for a user (full admins only).
 *   POST action=grant_role|revoke_role, user_id, role_code
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('استخدم POST لهذا الإجراء.', 405);
}
Gate::requireFullAdmin();
require_csrf();

$action   = (string)post('action', 'grant_role');
$userId   = post_int('user_id', 0) ?? 0;
$roleCode = (string)post('role_code', '');
$adminId  = (int)Auth::id();

$user = $userId ? UserRepo::find($userId) : null;
if (!$user) {
    json_err('المستخدم غير موجود.', 404);
}
$role = Repo::q('SELECT * FROM roles WHERE code=?', [$roleCode])[0] ?? null;
if (!$role) {
    json_err('الدور غير موجود.', 404);
}
if (!in_array($action, ['grant_role', 'revoke_role'], true)) {
    json_err('إجراء غير معروف.', 400);
}

if ($action === 'grant_role') {
    UserRepo::grantRole($userId, $roleCode, $adminId);
} else {
    UserRepo::revokeRole($userId, $roleCode);
}
AuditRepo::log($adminId, 'role.' . ($action === 'grant_role' ? 'granted' : 'revoked'), 'user', $userId, null, ['role' => $roleCode]);

json_ok([
    'message' => $action === 'grant_role' ? 'تم منح الدور «' . (string)$role['name_ar'] . '».' : 'تم سحب الدور «' . (string)$role['name_ar'] . '».',
    'reload'  => true,
]);
