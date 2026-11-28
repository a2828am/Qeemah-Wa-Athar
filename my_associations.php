<?php
/**
 * my_associations.php — where an individual follows their association requests.
 *
 * A person picks an association at registration (or here, later). The request
 * waits until that association's representative decides; this page is the
 * visible half of that promise, so nothing disappears into a queue unseen.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/components.php';

Auth::requireLogin();
$me     = Auth::user();
$userId = (int)$me['id'];
$errors = [];

$myAssoc = Auth::association();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)post('action', '');

    try {
        if ($action === 'request') {
            $assocId = post_int('association_id', 0) ?? 0;
            AssociationRepo::requestJoin(
                $assocId,
                $userId,
                (string)post('role', 'coordinator'),
                (string)post('message', '')
            );
            $assocRow = AssociationRepo::find($assocId);
            $repId = (int)Repo::scalar(
                "SELECT user_id FROM association_members
                  WHERE association_id=? AND role='representative' AND status='active' LIMIT 1",
                [$assocId]
            ) ?: (int)($assocRow['owner_user_id'] ?? 0);
            if ($repId > 0) {
                NotificationRepo::push(
                    $repId,
                    'system',
                    'طلب انضمام جديد للجمعية',
                    (string)$me['full_name'] . ' يطلب الانضمام إلى «' . (string)$assocRow['name'] . '» — راجعه للقبول أو الرفض.',
                    'charity/join_requests.php',
                    'user-plus'
                );
            }
            flash('success', 'أُرسل طلبك إلى «' . (string)$assocRow['name'] . '». سيصلك إشعار بالقرار.');
            redirect('my_associations.php');
        }

        if ($action === 'cancel') {
            AssociationRepo::cancelJoin(post_int('join_id', 0) ?? 0, $userId);
            flash('success', 'أُلغي الطلب.');
            redirect('my_associations.php');
        }
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
    }
}

$mine    = AssociationRepo::myJoins($userId);
$pending = array_values(array_filter($mine, static fn ($j) => (string)$j['status'] === 'pending'));
$openIds = array_map(static fn ($j) => (int)$j['association_id'], $pending);
$options = array_values(array_filter(
    AssociationRepo::verifiedList(),
    static fn ($a) => !in_array((int)$a['id'], $openIds, true)
));

$roleLabels = ['manager' => 'مدير', 'coordinator' => 'منسّق', 'viewer' => 'مشاهد'];
$statusMeta = [
    'pending'   => ['بانتظار رد الجمعية', 'badge-pending', 'clock'],
    'approved'  => ['مقبول', 'badge-completed', 'check'],
    'rejected'  => ['مرفوض', 'badge-rejected', 'x'],
    'cancelled' => ['ملغى', 'badge-muted', 'x'],
];

$currentPage = 'my_associations';
$pageTitle   = 'جمعياتي | قيمة وأثر';
$pageHead    = [
    'title'    => icon('landmark', 28) . ' جمعياتي',
    'subtitle' => 'إن كنت منسّقاً أو متطوّعاً في جمعية، أرسل طلب انضمام — وتبقى العضوية معلّقة حتى تقبل الجمعية.',
];
require __DIR__ . '/includes/header.php';
?>

<div class="container section">

  <?php if ($errors): ?>
    <div class="alert alert-error"><?= icon('alert-circle', 18) ?><div class="grow"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
  <?php endif; ?>

  <?php if ($myAssoc): ?>
    <div class="panel mb-16" style="border-inline-start:4px solid var(--success)">
      <div class="flex justify-between items-center gap-8" style="flex-wrap:wrap">
        <div>
          <span class="text-xs text-muted">عضو في</span>
          <h3 style="margin:4px 0"><?= icon('badge-check', 18) ?> <?= e((string)$myAssoc['name']) ?></h3>
          <span class="badge badge-soft"><?= e($roleLabels[(string)($myAssoc['member_role'] ?? 'coordinator')] ?? 'منسّق') ?></span>
        </div>
        <a class="btn btn-primary btn-sm" href="<?= e(url('charity/dashboard.php')) ?>"><?= icon('dashboard', 15) ?>لوحة الجمعية</a>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($pending): ?>
    <h3 class="mb-8" style="font-size:17px"><?= icon('clock', 18) ?> طلبات قيد المراجعة</h3>
    <div style="display:grid;gap:12px" class="mb-24">
      <?php foreach ($pending as $j): ?>
        <div class="panel">
          <div class="flex justify-between items-center gap-8" style="flex-wrap:wrap">
            <div>
              <b><?= e((string)$j['assoc_name']) ?></b>
              <div class="table-sub"><?= e((string)$j['assoc_city']) ?> · دور مطلوب: <?= e($roleLabels[(string)$j['requested_role']] ?? 'منسّق') ?></div>
            </div>
            <div class="flex items-center gap-8">
              <span class="badge badge-pending"><?= icon('clock', 12) ?>بانتظار رد الجمعية</span>
              <form method="post" data-confirm="إلغاء الطلب؟">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="join_id" value="<?= (int)$j['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit"><?= icon('x', 13) ?>إلغاء الطلب</button>
              </form>
            </div>
          </div>
          <?php if (trim((string)$j['message']) !== ''): ?>
            <p class="text-sm text-muted" style="margin-top:8px">رسالتك: «<?= e((string)$j['message']) ?>»</p>
          <?php endif; ?>
          <p class="text-xs text-muted" style="margin-top:8px"><?= icon('info', 12) ?> أُرسل <?= e(time_ago((string)$j['created_at'])) ?> — يمكنك استخدام حسابك كفرد حتى يصل الرد.</p>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($options && !$myAssoc): ?>
    <div class="panel">
      <h3><?= icon('user-plus', 18) ?> انضم إلى جمعية</h3>
      <p class="text-sm text-muted mb-16">اختر الجمعية التي تمثّلها. سيصل طلبك إلى ممثلها، ولا تُمنح أي صلاحية قبل موافقته.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="request">
        <div class="reg-grid">
          <div class="field">
            <label for="association_id">الجمعية <i>*</i></label>
            <select id="association_id" name="association_id" required>
              <option value="">اختر الجمعية</option>
              <?php foreach ($options as $a): ?>
                <option value="<?= (int)$a['id'] ?>"><?= e((string)$a['name']) ?> — <?= e((string)$a['city']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="role">دورك المتوقّع</label>
            <select id="role" name="role">
              <option value="coordinator">منسّق</option>
              <option value="manager">مدير</option>
              <option value="viewer">مشاهد</option>
            </select>
          </div>
          <div class="field full">
            <label for="message">رسالة للجمعية (اختياري)</label>
            <textarea id="message" name="message" rows="2" placeholder="كيف تساعد الجمعية؟"></textarea>
          </div>
        </div>
        <button class="btn btn-primary mt-16" type="submit"><?= icon('send', 16) ?>إرسال طلب الانضمام</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($mine): ?>
    <div class="panel mt-24">
      <h3><?= icon('history', 18) ?> سجل طلباتي</h3>
      <div class="table-wrap" style="box-shadow:none;border:0">
        <table>
          <thead><tr><th>الجمعية</th><th>الدور</th><th>الحالة</th><th>التاريخ</th></tr></thead>
          <tbody>
            <?php foreach ($mine as $j):
                $meta = $statusMeta[(string)$j['status']] ?? ['—', 'badge-muted', 'info'];
            ?>
              <tr>
                <td><span class="table-title"><?= e((string)$j['assoc_name']) ?></span></td>
                <td><?= e($roleLabels[(string)$j['requested_role']] ?? 'منسّق') ?></td>
                <td>
                  <span class="badge <?= $meta[1] ?>"><?= icon($meta[2], 11) ?><?= e($meta[0]) ?></span>
                  <?php if (trim((string)$j['decision_note']) !== ''): ?>
                    <span class="table-sub"><?= e((string)$j['decision_note']) ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-xs text-muted"><?= e(time_ago((string)($j['resolved_at'] ?? $j['created_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
