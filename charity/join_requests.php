<?php
/**
 * charity/join_requests.php — individuals asking to be recognised as members
 * or representatives of this association.
 *
 * Nothing is granted by registering: the picker in register.php only raises a
 * row in association_join_requests. This page is where the association's
 * representative turns one of those rows into real membership (or refuses it).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/components.php';

$assoc   = Gate::requireAssociation();
Gate::requireMemberCapability('members');
$assocId = (int)$assoc['id'];
$me      = Auth::user();
$errors  = [];

$roleLabels = [
    'manager'     => 'مدير',
    'coordinator' => 'منسّق',
    'viewer'      => 'مشاهد',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)post('action', '');
    $joinId = post_int('join_id', 0) ?? 0;

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
            flash('success', 'تمت الموافقة. أصبح العضو ضمن فريق الجمعية.');
            redirect('charity/join_requests.php');
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
            flash('success', 'تم رفض الطلب وإبلاغ صاحبه.');
            redirect('charity/join_requests.php');
        }
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
    }
}

$pending = AssociationRepo::pendingJoins($assocId);
$recent  = Repo::q(
    "SELECT j.*, u.full_name, u.email
       FROM association_join_requests j
       JOIN users u ON u.id = j.user_id
      WHERE j.association_id=? AND j.status<>'pending'
      ORDER BY j.resolved_at DESC, j.id DESC LIMIT 12",
    [$assocId]
);

$currentPage = 'charity_panel';
$pageHead    = [
    'title'    => icon('user-plus', 28) . ' طلبات الانضمام',
    'subtitle' => 'أفراد يطلبون تمثيل الجمعية. راجع كل طلب ثم اقبله أو ارفضه — لا صلاحية تُمنح قبل موافقتك.',
    'actions'  => '<a class="btn btn-outline btn-sm" href="' . e(url('charity/members.php')) . '">' . icon('users', 15) . 'أعضاء الجمعية</a>'
        . '<a class="btn btn-outline btn-sm" href="' . e(url('charity/dashboard.php')) . '">' . icon('dashboard', 15) . 'لوحة الجمعية</a>',
];
require __DIR__ . '/../includes/header.php';
?>

<div class="container section" style="padding-top:24px">
  <?php if ($errors): ?>
    <div class="alert alert-error"><?= icon('alert-circle', 18) ?><div class="grow"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
  <?php endif; ?>

  <?php if (!$pending): ?>
    <div class="panel mb-16">
      <?= empty_state(
          'لا توجد طلبات معلّقة',
          'عندما يذكر أحد الأفراد عند التسجيل أنه يمثّل جمعيتكم، سيظهر طلبه هنا للمراجعة.',
          'inbox',
          'أعضاء الجمعية',
          url('charity/members.php')
      ) ?>
    </div>
  <?php else: ?>
    <div class="stats mb-16">
      <div class="stat"><span class="stat-ic"><?= icon('clock', 20) ?></span><b><?= count($pending) ?></b><span>طلب معلّق</span></div>
      <div class="stat"><span class="stat-ic"><?= icon('users', 20) ?></span><b><?= (int)Repo::scalar("SELECT COUNT(*) FROM association_members WHERE association_id=? AND status='active'", [$assocId]) ?></b><span>عضو حالي</span></div>
    </div>

    <div class="grid gap-16" style="display:grid;gap:16px">
      <?php foreach ($pending as $j): ?>
        <article class="panel">
          <div class="flex justify-between items-center gap-8" style="flex-wrap:wrap">
            <div class="flex items-center gap-8">
              <span class="avatar"><?= e(avatar_letter((string)$j['full_name'])) ?></span>
              <div>
                <b style="font-size:15px"><?= e((string)$j['full_name']) ?></b>
                <div class="table-sub" dir="ltr"><?= e((string)$j['email']) ?></div>
              </div>
            </div>
            <span class="badge badge-pending"><?= icon('clock', 12) ?>بانتظار قرارك</span>
          </div>

          <div class="kv-list mt-16">
            <div class="kv-row"><span>الدور المطلوب</span><b><?= e($roleLabels[(string)$j['requested_role']] ?? 'منسّق') ?></b></div>
            <div class="kv-row"><span>المدينة</span><b><?= e((string)($j['city'] ?? '—')) ?></b></div>
            <div class="kv-row"><span>عضو منذ</span><b><?= e(time_ago((string)$j['user_since'])) ?></b></div>
            <div class="kv-row"><span>منتجاته على المنصة</span><b><?= (int)$j['products_count'] ?></b></div>
            <div class="kv-row"><span>تاريخ الطلب</span><b><?= e(time_ago((string)$j['created_at'])) ?></b></div>
          </div>

          <?php if (trim((string)$j['message']) !== ''): ?>
            <div class="alert alert-info mt-16">
              <?= icon('message', 16) ?>
              <div class="grow">«<?= e((string)$j['message']) ?>»</div>
            </div>
          <?php endif; ?>

          <div class="row-actions mt-16" style="display:flex;gap:8px;flex-wrap:wrap">
            <form method="post" data-ajax="api/join_action.php" data-confirm="قبول العضو ومنحه دور «<?= e($roleLabels[(string)$j['requested_role']] ?? 'منسّق') ?>»؟" data-done="reload">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="approve">
              <input type="hidden" name="join_id" value="<?= (int)$j['id'] ?>">
              <button class="btn btn-primary btn-sm" type="submit"><?= icon('check', 14) ?>قبول العضوية</button>
            </form>

            <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap" data-ajax="api/join_action.php" data-confirm="رفض الطلب؟ سيُبلَّغ صاحبه." data-done="reload">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reject">
              <input type="hidden" name="join_id" value="<?= (int)$j['id'] ?>">
              <input type="text" name="decision_note" maxlength="255" placeholder="سبب الرفض (اختياري)" style="max-width:220px">
              <button class="btn btn-ghost btn-sm" type="submit"><?= icon('x', 14) ?>رفض</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($recent): ?>
    <div class="panel mt-24">
      <h3><?= icon('history', 18) ?> قرارات سابقة</h3>
      <div class="table-wrap" style="box-shadow:none;border:0">
        <table>
          <thead><tr><th>العضو</th><th>الدور</th><th>القرار</th><th>التاريخ</th></tr></thead>
          <tbody>
            <?php foreach ($recent as $r): ?>
              <tr>
                <td>
                  <span class="table-title"><?= e((string)$r['full_name']) ?></span>
                  <span class="table-sub" dir="ltr"><?= e((string)$r['email']) ?></span>
                </td>
                <td><?= e($roleLabels[(string)$r['requested_role']] ?? 'منسّق') ?></td>
                <td>
                  <?php if ((string)$r['status'] === 'approved'): ?>
                    <span class="badge badge-completed"><?= icon('check', 11) ?>مقبول</span>
                  <?php else: ?>
                    <span class="badge badge-rejected"><?= icon('x', 11) ?>مرفوض</span>
                  <?php endif; ?>
                  <?php if (trim((string)$r['decision_note']) !== ''): ?>
                    <span class="table-sub"><?= e((string)$r['decision_note']) ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-xs text-muted"><?= e(time_ago((string)($r['resolved_at'] ?? $r['created_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
