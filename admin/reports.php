<?php
/**
 * admin/reports.php — moderation queue for reports + complaints.
 *   ?status=open|resolved|rejected   ?focus=REPORT_ID
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/components.php';

Gate::requireAdmin();
$adminId = (int)Auth::id();
$status  = get_q('status', 'open') ?? 'open';
$focus   = get_int('focus', 0) ?? 0;
$page    = max(1, get_int('page', 1) ?? 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $reportId = post_int('report_id', 0) ?? 0;
        $newStatus = (string)post('status', 'resolved');
        $note = (string)post('note', '');
        if (!$reportId) {
            throw new RuntimeException('البلاغ غير موجود.');
        }
        ModerationRepo::resolveReport($reportId, $newStatus, $adminId, $note !== '' ? $note : null);
        AuditRepo::log($adminId, 'report.' . $newStatus, 'report', $reportId, null, ['status' => $newStatus, 'note' => $note]);
        flash('success', 'تم تحديث حالة البلاغ.');
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/reports.php?status=' . urlencode($status));
}

$filterStatus = $status === 'all' ? '' : $status;
[$reports, $pager] = ModerationRepo::reports($filterStatus !== '' ? ['status' => $filterStatus] : [], $page, 15);
$complaints = ModerationRepo::complaints();
$counts = ['open' => 0, 'resolved' => 0, 'rejected' => 0];
foreach (Repo::q('SELECT status, COUNT(*) n FROM reports GROUP BY status') as $row) {
    $counts[(string)$row['status']] = (int)$row['n'];
}

$reasonLabels = [
    'inappropriate' => 'محتوى غير لائق', 'fake' => 'إعلان وهمي', 'scam' => 'احتيال',
    'prohibited' => 'منتج محظور', 'spam' => 'إزعاج/تكرار', 'other' => 'أخرى',
];

$pageTitle   = 'البلاغات والشكاوى | قيمة وأثر';
$currentPage = 'admin';
$adminPage   = 'reports';
$pageHead    = [
    'title'    => icon('flag', 28) . ' البلاغات والشكاوى',
    'subtitle' => 'راجع البلاغات الواردة، واتخذ إجراءً موثّقاً في سجل التدقيق.',
];
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_nav.php';
?>

<div class="container section" style="padding-top:24px">
  <div class="tabs mb-16">
    <a class="tab<?= $status === 'open' ? ' active' : '' ?>" href="<?= e(url('admin/reports.php?status=open')) ?>"><?= icon('alert-circle', 14) ?>مفتوحة <span class="text-xs">(<?= (int)$counts['open'] ?>)</span></a>
    <a class="tab<?= $status === 'resolved' ? ' active' : '' ?>" href="<?= e(url('admin/reports.php?status=resolved')) ?>"><?= icon('check-circle', 14) ?>معالَجة <span class="text-xs">(<?= (int)$counts['resolved'] ?>)</span></a>
    <a class="tab<?= $status === 'rejected' ? ' active' : '' ?>" href="<?= e(url('admin/reports.php?status=rejected')) ?>"><?= icon('ban', 14) ?>مرفوضة <span class="text-xs">(<?= (int)$counts['rejected'] ?>)</span></a>
    <a class="tab<?= $status === 'all' ? ' active' : '' ?>" href="<?= e(url('admin/reports.php?status=all')) ?>">الكل</a>
  </div>

  <div class="two-col">
    <div>
      <div class="panel">
        <h3><?= icon('flag', 18) ?> البلاغات <span class="badge badge-soft"><?= (int)$pager['total'] ?></span></h3>
        <?php if (!$reports): ?>
          <?= empty_state('لا توجد بلاغات في هذه الحالة', 'عمل رائع — لا شيء بانتظار المراجعة.', 'check-circle') ?>
        <?php else: ?>
          <div class="list">
            <?php foreach ($reports as $r): ?>
              <div class="list-row" style="align-items:flex-start<?= $focus === (int)$r['id'] ? ';box-shadow:0 0 0 3px var(--green-100);border-color:var(--green-500)' : '' ?>">
                <span class="stat-ic" style="width:36px;height:36px;display:grid;place-items:center;border-radius:10px;background:var(--danger-bg);color:var(--danger)"><?= icon('flag', 16) ?></span>
                <span class="grow">
                  <b>
                    <?= e(['product' => 'منتج', 'user' => 'مستخدم', 'message' => 'رسالة', 'association' => 'جمعية'][(string)$r['entity_type']] ?? (string)$r['entity_type']) ?>
                    #<?= (int)$r['entity_id'] ?> — <?= e($reasonLabels[(string)$r['reason']] ?? (string)$r['reason']) ?>
                  </b>
                  <span>
                    بلّغ عنه <?= e((string)$r['reporter_name']) ?> · <?= e(time_ago((string)$r['created_at'])) ?>
                    <?php if (!empty($r['reviewer_name'])): ?> · راجعه <?= e((string)$r['reviewer_name']) ?><?php endif; ?>
                  </span>
                  <?php if (!empty($r['details'])): ?><div class="req-msg mt-8"><?= e((string)$r['details']) ?></div><?php endif; ?>
                  <?php if (!empty($r['action_note'])): ?><span class="text-xs text-muted">الإجراء: <?= e((string)$r['action_note']) ?></span><?php endif; ?>
                  <div class="row-actions mt-8">
                    <?php if ($r['entity_type'] === 'product'): ?>
                      <?php $prod = ProductRepo::find((int)$r['entity_id']); ?>
                      <?php if ($prod): ?>
                        <a class="icon-btn" href="<?= e(url('product_details.php?slug=' . urlencode((string)$prod['slug']))) ?>" target="_blank" rel="noopener"><?= icon('eye', 13) ?>المنتج</a>
                      <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($r['status'] === 'open'): ?>
                      <form method="post" class="flex gap-6">
                        <?= csrf_field() ?>
                        <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="note" value="تمت المراجعة ومعالجة البلاغ">
                        <button class="icon-btn" type="submit" name="status" value="resolved"><?= icon('check', 12) ?>معالجة</button>
                        <button class="icon-btn danger" type="submit" name="status" value="rejected"><?= icon('ban', 12) ?>رفض</button>
                      </form>
                    <?php else: ?>
                      <span class="status <?= $r['status'] === 'resolved' ? 'st-completed' : 'st-cancelled' ?>"><?= $r['status'] === 'resolved' ? 'معالَج' : 'مرفوض' ?></span>
                    <?php endif; ?>
                  </div>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
          <?= pagination_links($pager, ['status' => $status]) ?>
        <?php endif; ?>
      </div>
    </div>

    <aside>
      <div class="panel">
        <h3><?= icon('clipboard', 18) ?> شكاوى المستخدمين</h3>
        <?php if (!$complaints): ?>
          <p class="text-sm text-muted">لا توجد شكاوى.</p>
        <?php else: ?>
          <div class="list">
            <?php foreach ($complaints as $c): ?>
              <div class="list-row" style="align-items:flex-start">
                <span class="grow">
                  <b><?= e(excerpt((string)$c['subject'], 60)) ?></b>
                  <span><?= e((string)$c['complainant_name']) ?><?= !empty($c['against_name']) ? ' ضد ' . e((string)$c['against_name']) : '' ?> · <?= e(time_ago((string)$c['created_at'])) ?></span>
                  <div class="req-msg mt-8"><?= e(excerpt((string)$c['body'], 140)) ?></div>
                </span>
                <span class="status <?= $c['status'] === 'resolved' ? 'st-completed' : 'st-review' ?>">
                  <?= $c['status'] === 'resolved' ? 'محلولة' : ($c['status'] === 'investigating' ? 'تحت التحقيق' : 'مفتوحة') ?>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('info', 18) ?> إرشاد سريع</h3>
        <div class="kv">
          <div class="kv-row"><span>بلاغ المحتوى غير اللائق</span><b>إزالة المنتج</b></div>
          <div class="kv-row"><span>بلاغ الاحتيال</span><b>إيقاف الحساب</b></div>
          <div class="kv-row"><span>بلاغ مكرر</span><b>رفض البلاغ</b></div>
        </div>
        <p class="text-xs text-muted mt-8">كل إجراء يسجّل في سجل التدقيق مع هوية المسؤول والوقت.</p>
      </div>
    </aside>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
