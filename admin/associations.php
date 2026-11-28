<?php
/**
 * admin/associations.php — verify / reject charity associations.
 * Verifying activates the representative account and notifies the team;
 * rejecting suspends it. Both are written to the audit log.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/components.php';

Gate::requireAdmin();
$adminId = (int)Auth::id();
$status  = get_q('status', '') ?? '';
$q       = get_q('q', '') ?? '';
$page    = max(1, get_int('page', 1) ?? 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action  = (string)post('action', '');
    $assocId = post_int('association_id', 0) ?? 0;
    $assoc   = $assocId ? AssociationRepo::find($assocId) : null;

    try {
        if (!$assoc) {
            throw new RuntimeException('الجمعية غير موجودة.');
        }
        if (!in_array($action, ['verify', 'reject', 'pending'], true)) {
            throw new RuntimeException('إجراء غير معروف.');
        }
        $newStatus = ['verify' => 'verified', 'reject' => 'rejected', 'pending' => 'pending'][$action];
        AssociationRepo::setStatus($assocId, $newStatus, $adminId);

        // Representative account follows the association's decision.
        $ownerStatus = match ($newStatus) {
            'verified' => 'active',
            'rejected' => 'suspended',
            default    => 'pending',
        };
        UserRepo::setStatus((int)$assoc['owner_user_id'], $ownerStatus);
        Repo::exec(
            "UPDATE login_requests SET status=?, resolved_by=?, resolved_at=?
              WHERE user_id=? AND kind='assoc_approval' AND status='pending'",
            [match ($newStatus) { 'verified' => 'approved', 'rejected' => 'denied', default => 'pending' }, $adminId, now(), (int)$assoc['owner_user_id']]
        );

        AuditRepo::log($adminId, 'association.' . $newStatus, 'association', $assocId,
            ['status' => $assoc['status']], ['status' => $newStatus]);

        NotificationRepo::push(
            (int)$assoc['owner_user_id'],
            'system',
            match ($newStatus) {
                'verified' => 'تم توثيق جمعيتكم',
                'rejected' => 'لم يُقبل طلب توثيق الجمعية',
                default    => 'جمعيتكم قيد المراجعة',
            },
            match ($newStatus) {
                'verified' => 'أصبح بإمكانكم الآن استلام التبرّعات وإصدار الإيصالات وتسجيل التوزيع.',
                'rejected' => 'يرجى مراجعة بيانات الترخيص والتواصل مع الإدارة لإعادة التقديم.',
                default    => 'عاد طلب التوثيق إلى قائمة المراجعة.',
            },
            'charity/dashboard.php',
            'landmark'
        );
        flash('success', 'تم تحديث حالة الجمعية إلى: ' . $newStatus . '.');
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/associations.php' . ($status !== '' ? '?status=' . urlencode($status) : ''));
}

[$associations, $pager] = AssociationRepo::allForAdmin(['status' => $status ?: null, 'q' => $q ?: null], $page, 12);
$counts = [];
foreach (Repo::q('SELECT status, COUNT(*) n FROM associations GROUP BY status') as $row) {
    $counts[(string)$row['status']] = (int)$row['n'];
}

$pageTitle   = 'إدارة الجمعيات | قيمة وأثر';
$currentPage = 'admin';
$adminPage   = 'associations';
$pageHead    = [
    'title'    => icon('landmark', 28) . ' الجمعيات الخيرية',
    'subtitle' => 'راجع تراخيص الجمعيات ووثّقها — التوثيق يفعّل استلام التبرّعات وإصدار الإيصالات.',
];
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_nav.php';
?>

<div class="container section" style="padding-top:24px">
  <div class="tabs mb-16">
    <a class="tab<?= $status === '' ? ' active' : '' ?>" href="<?= e(url('admin/associations.php')) ?>">الكل <span class="text-xs">(<?= array_sum($counts) ?>)</span></a>
    <a class="tab<?= $status === 'pending' ? ' active' : '' ?>" href="<?= e(url('admin/associations.php?status=pending')) ?>"><?= icon('clock', 14) ?>قيد المراجعة <span class="text-xs">(<?= (int)($counts['pending'] ?? 0) ?>)</span></a>
    <a class="tab<?= $status === 'verified' ? ' active' : '' ?>" href="<?= e(url('admin/associations.php?status=verified')) ?>"><?= icon('check-circle', 14) ?>موثّقة <span class="text-xs">(<?= (int)($counts['verified'] ?? 0) ?>)</span></a>
    <a class="tab<?= $status === 'rejected' ? ' active' : '' ?>" href="<?= e(url('admin/associations.php?status=rejected')) ?>"><?= icon('ban', 14) ?>مرفوضة <span class="text-xs">(<?= (int)($counts['rejected'] ?? 0) ?>)</span></a>
  </div>

  <form class="filters mb-16" method="get" action="<?= e(url('admin/associations.php')) ?>">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <div class="search-field">
      <?= icon('search', 16) ?>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="اسم الجمعية…">
    </div>
    <button class="btn btn-primary btn-sm" type="submit"><?= icon('search', 15) ?>بحث</button>
  </form>

  <?php if (!$associations): ?>
    <div class="panel"><?= empty_state('لا توجد جمعيات مطابقة', 'ستظهر طلبات التوثيق الجديدة هنا تلقائياً.', 'landmark') ?></div>
  <?php else: ?>
    <div class="charity-grid">
      <?php foreach ($associations as $a): ?>
        <article class="charity-card">
          <div class="flex justify-between items-center gap-8">
            <div class="logo-sm" style="margin:0"><?= icon('landmark', 22) ?></div>
            <span class="status <?= $a['status'] === 'verified' ? 'st-completed' : ($a['status'] === 'rejected' ? 'st-rejected' : 'st-review') ?>">
              <?= $a['status'] === 'verified' ? 'موثّقة' : ($a['status'] === 'rejected' ? 'مرفوضة' : 'قيد المراجعة') ?>
            </span>
          </div>
          <b style="font-size:16px;display:block;margin-top:10px"><?= e((string)$a['name']) ?></b>
          <span class="text-xs text-muted"><?= e((string)$a['city']) ?> · ترخيص: <span dir="ltr"><?= e((string)($a['license_number'] ?? '—')) ?></span></span>
          <p class="text-sm mt-8" style="color:var(--muted)"><?= e(excerpt((string)($a['description'] ?? ''), 120)) ?></p>
          <div class="kv mt-8">
            <div class="kv-row"><span>الممثل</span><b><?= e((string)$a['owner_name']) ?></b></div>
            <div class="kv-row"><span>الأعضاء</span><b><?= (int)$a['members_count'] ?></b></div>
            <div class="kv-row"><span>الحملات</span><b><?= (int)$a['campaigns_count'] ?></b></div>
            <div class="kv-row"><span>تبرّعات مستلمة</span><b><?= (int)$a['donations_received'] ?></b></div>
            <div class="kv-row"><span>سُجّلت</span><b><?= e(date('Y-m-d', strtotime((string)$a['created_at']))) ?></b></div>
          </div>
          <?php if (!empty($a['needs_summary'])): ?>
            <div class="tag-list mt-8">
              <?php foreach (array_slice(explode('·', (string)$a['needs_summary']), 0, 3) as $need): ?>
                <span class="tag"><?= e(trim($need)) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="row-actions mt-16">
            <?php if ($a['status'] !== 'verified'): ?>
              <form method="post" data-ajax="api/admin_action.php" data-confirm="توثيق الجمعية وتفعيل حساب ممثلها؟" data-done="reload">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="assoc_verify">
                <input type="hidden" name="association_id" value="<?= (int)$a['id'] ?>">
                <button class="btn btn-primary btn-sm" type="submit"><?= icon('shield-check', 14) ?>توثيق</button>
              </form>
            <?php endif; ?>
            <?php if ($a['status'] !== 'rejected'): ?>
              <form method="post" data-ajax="api/admin_action.php" data-confirm="رفض التوثيق وإيقاف حساب الممثل؟" data-done="reload">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="assoc_reject">
                <input type="hidden" name="association_id" value="<?= (int)$a['id'] ?>">
                <button class="icon-btn danger" type="submit"><?= icon('ban', 13) ?>رفض</button>
              </form>
            <?php endif; ?>
            <?php if ($a['status'] === 'verified'): ?>
              <form method="post" data-ajax="api/admin_action.php" data-confirm="إعادة الجمعية لقائمة المراجعة؟" data-done="reload">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="assoc_back_to_pending">
                <input type="hidden" name="association_id" value="<?= (int)$a['id'] ?>">
                <button class="icon-btn" type="submit"><?= icon('clock', 13) ?>تعليق</button>
              </form>
            <?php endif; ?>
            <a class="icon-btn" href="<?= e(url('charity/campaigns.php')) ?>"><?= icon('megaphone', 13) ?>الحملات</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <?= pagination_links($pager, array_filter(['status' => $status, 'q' => $q])) ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
