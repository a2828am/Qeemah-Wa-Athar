<?php
/**
 * api/join_action.php — approve or reject a pending association join request.
 *
 * Only a member holding the "members" capability of that association may act.
 * Approving is what creates the membership; nothing before it grants access.
 *   POST action=approve|reject, join_id[, decision_note]
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

$action = (string)post('action', '');
$joinId = post_int('join_id', 0) ?? 0;
$me     = Auth::user();
$assocId = (int)$assoc['id'];

$roleLabels = ['manager' => 'مدير', 'coordinator' => 'منسّق', 'viewer' => 'مشاهد'];

try {
    if ($action === 'approve') {
        $row = AssociationRepo::approveJoin($joinId, $assocId, (int)$me['id']);
        NotificationRepo::push(
            (int)$row['user_id'],
            'system',
            'تمت الموافقة على انضمامك',
            'وافقت «' . (string)$assoc['name'] . '» على طلبك. أصبحت عضواً في فريقها بدور ' .
                ($roleLabels[(string)$row['requested_role']] ?? 'منسّق') . '.',
            'charity/dashboard.php',
            'user-check'
        );
        AuditRepo::log(null, 'join.approved', 'association', $assocId, null, [
            'user' => (int)$row['user_id'], 'role' => (string)$row['requested_role'],
        ]);
        json_ok(['message' => 'تمت الموافقة. أصبح العضو ضمن فريق الجمعية.', 'reload' => true]);
    }

    if ($action === 'reject') {
        $note = (string)post('decision_note', '');
        $row  = AssociationRepo::rejectJoin($joinId, $assocId, (int)$me['id'], $note);
        NotificationRepo::push(
            (int)$row['user_id'],
            'system',
            'لم تتم الموافقة على طلب الانضمام',
            'اعتذرت «' . (string)$assoc['name'] . '» عن قبول طلبك' .
                ($note !== '' ? ': ' . $note : '.') . ' يمكنك التقدّم لجمعية أخرى.',
            'my_associations.php',
            'x'
        );
        AuditRepo::log(null, 'join.rejected', 'association', $assocId, null, ['user' => (int)$row['user_id']]);
        json_ok(['message' => 'تم رفض الطلب وإبلاغ صاحبه.', 'reload' => true]);
    }

    json_err('إجراء غير معروف.', 422);
} catch (Throwable $ex) {
    json_err($ex->getMessage(), 422);
}
