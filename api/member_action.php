<?php
/**
 * api/member_action.php — remove a member from the association (JSON).
 * The representative row can never be removed (enforced in the repository).
 *   POST action=remove, member_id
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('استخدم POST لهذا الإجراء.', 405);
}
$assoc = Gate::requireAssociation();
Gate::requireMemberCapability('members');
require_csrf();

$memberId = post_int('member_id', 0) ?? 0;
$row = Repo::q(
    'SELECT * FROM association_members WHERE id=? AND association_id=?',
    [$memberId, (int)$assoc['id']]
)[0] ?? null;

if (!$row) {
    json_err('العضو غير موجود.', 404);
}
if ((string)$row['role'] === 'representative') {
    json_err('لا يمكن إزالة ممثل الجمعية.', 422);
}

AssociationRepo::removeMember($memberId, (int)$assoc['id']);
UserRepo::revokeRole((int)$row['user_id'], 'association_coordinator');
AuditRepo::log(null, 'member.removed', 'association', (int)$assoc['id'], null, ['member' => $memberId]);

json_ok(['message' => 'تم إزالة العضو من الجمعية.', 'reload' => true]);
