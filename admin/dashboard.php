<?php
/**
 * admin/dashboard.php — platform overview: KPIs, request/donation mix,
 * 6-month chart, pending moderation queue and the latest audit trail.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/components.php';

Gate::requireAdmin();
$stats   = StatsRepo::platform();
$chart   = StatsRepo::chartLast6Months();
$byType  = ProductRepo::countsByType();
$byCat   = array_slice(ProductRepo::countsByCategory(), 0, 6);
$donStats = DonationRepo::statsPlatform();
$reqStatus = Repo::q('SELECT status, COUNT(*) n FROM user_requests GROUP BY status');
$reqMap = array_fill_keys(array_keys(request_status_meta()), 0);
foreach ($reqStatus as $r) {
    $reqMap[$r['status']] = (int)$r['n'];
}
$openReports = ModerationRepo::reports(['status' => 'open'], 1, 5)[0];
$pendingAssoc = AssociationRepo::allForAdmin(['status' => 'pending'], 1, 5)[0];
$recentAudit = AuditRepo::paginate([], 1, 6)[0];

$maxBar = 1;
foreach ($chart as $row) {
    $maxBar = max($maxBar, (int)$row['products'], (int)$row['requests']);
}

$pageTitle   = 'لوحة الإدارة | قيمة وأثر';
$currentPage = 'admin';
$adminPage   = 'dashboard';
$pageHead    = [
    'title'    => icon('shield', 28) . ' لوحة الإدارة',
    'subtitle' => 'نظرة شاملة على المستخدمين والمنتجات والطلبات والجمعيات والبلاغات.',
    'actions'  => '<a class="btn btn-outline btn-sm" href="' . e(url('admin/reports.php')) . '">' . icon('flag', 15) . 'البلاغات المفتوحة (' . (int)$stats['reports'] . ')</a>',
];
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_nav.php';
?>

<div class="container section" style="padding-top:24px">
  <div class="stat-grid">
    <a class="stat" href="<?= e(url('admin/users.php')) ?>">
      <span class="stat-ic"><?= icon('users', 18) ?></span>
      <b><?= number_format((int)$stats['users']) ?></b><span>مستخدم · <?= (int)$stats['pending_assoc'] ?> جمعية بانتظار التوثيق</span>
    </a>
    <a class="stat" href="<?= e(url('admin/products.php')) ?>">
      <span class="stat-ic"><?= icon('package', 18) ?></span>
      <b><?= number_format((int)$stats['products']) ?></b><span>منتج · <?= (int)$stats['available'] ?> متاح، <?= (int)$stats['reserved'] ?> محجوز</span>
    </a>
    <a class="stat" href="<?= e(url('admin/products.php?status=reserved')) ?>">
      <span class="stat-ic"><?= icon('clipboard', 18) ?></span>
      <b><?= number_format((int)$stats['requests']) ?></b><span>طلب · <?= (int)$stats['new_requests'] ?> جديد</span>
    </a>
    <a class="stat" href="<?= e(url('admin/reports.php')) ?>">
      <span class="stat-ic"><?= icon('hand-heart', 18) ?></span>
      <b><?= number_format((int)$stats['donations']) ?></b><span>تبرّع · <?= (int)$stats['distributed'] ?> موزّع</span>
    </a>
  </div>

  <div class="two-col">
    <div>
      <div class="panel">
        <h3><?= icon('bar-chart', 18) ?> النشاط خلال 6 أشهر</h3>
        <div class="chart-bars">
          <?php foreach ($chart as $row):
              $ph = max(3, (int)round(((int)$row['products'] / $maxBar) * 140));
              $rh = max(3, (int)round(((int)$row['requests'] / $maxBar) * 140));
          ?>
            <div class="chart-col">
              <div class="chart-stack">
                <i style="height:<?= $ph ?>px" title="<?= (int)$row['products'] ?> منتج"></i>
                <i class="alt" style="height:<?= $rh ?>px" title="<?= (int)$row['requests'] ?> طلب"></i>
              </div>
              <b><?= e($row['label']) ?></b>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="chart-legend">
          <span><i></i> منتجات جديدة</span>
          <span><i class="alt"></i> طلبات</span>
        </div>
      </div>

      <div class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('flag', 18) ?> بلاغات بانتظار المراجعة <span class="badge badge-soft"><?= (int)$stats['reports'] ?></span></h3>
        <?php if (!$openReports): ?>
          <p class="text-sm text-muted">لا توجد بلاغات مفتوحة.</p>
        <?php else: ?>
          <div class="timeline">
            <?php foreach ($openReports as $r): ?>
              <div class="timeline-row">
                <span class="timeline-dot"><?= icon('flag', 16) ?></span>
                <div class="grow">
                  <b><?= e(['product' => 'بلاغ منتج', 'user' => 'بلاغ مستخدم', 'message' => 'بلاغ رسالة', 'association' => 'بلاغ جمعية'][(string)$r['entity_type']] ?? 'بلاغ') ?>
                    · <?= e(['inappropriate' => 'محتوى غير لائق', 'fake' => 'إعلان وهمي', 'scam' => 'احتيال', 'prohibited' => 'منتج محظور', 'spam' => 'إزعاج', 'other' => 'أخرى'][(string)$r['reason']] ?? (string)$r['reason']) ?></b>
                  <span><?= e((string)$r['reporter_name']) ?> · <?= e(time_ago((string)$r['created_at'])) ?></span>
                </div>
                <a class="icon-btn" href="<?= e(url('admin/reports.php?focus=' . (int)$r['id'])) ?>"><?= icon('eye', 13) ?>مراجعة</a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('shield-check', 18) ?> أحدث عمليات التدقيق</h3>
        <div class="timeline">
          <?php foreach ($recentAudit as $a): ?>
            <div class="timeline-row">
              <span class="timeline-dot"><?= icon('activity', 15) ?></span>
              <div class="grow">
                <b dir="ltr" style="text-align:start"><?= e((string)$a['action']) ?></b>
                <span><?= e((string)($a['admin_name'] ?? 'النظام')) ?> · <?= e((string)$a['entity_type']) ?> #<?= (int)$a['entity_id'] ?> · <?= e(time_ago((string)$a['created_at'])) ?></span>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (!$recentAudit): ?><p class="text-sm text-muted">لا توجد عمليات بعد.</p><?php endif; ?>
        </div>
        <a class="btn btn-ghost btn-sm mt-8" href="<?= e(url('admin/audit_logs.php')) ?>">السجل الكامل<?= icon('arrow-left', 14) ?></a>
      </div>
    </div>

    <aside>
      <div class="panel">
        <h3><?= icon('clipboard', 18) ?> الطلبات حسب الحالة</h3>
        <div class="kv">
          <?php foreach (request_status_meta() as $key => $meta): ?>
            <div class="kv-row"><span><?= icon($meta['icon'], 13) ?> <?= e($meta['label']) ?></span><b><?= (int)$reqMap[$key] ?></b></div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('hand-heart', 18) ?> التبرّعات</h3>
        <div class="kv">
          <div class="kv-row"><span>معروضة</span><b><?= (int)$donStats['offered'] ?></b></div>
          <div class="kv-row"><span>محجوزة لجمعية</span><b><?= (int)$donStats['claimed'] ?></b></div>
          <div class="kv-row"><span>مستلمة</span><b><?= (int)$donStats['received'] ?></b></div>
          <div class="kv-row"><span>موزّعة</span><b><?= (int)$donStats['distributed'] ?></b></div>
        </div>
      </div>

      <div class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('tag', 18) ?> المنتجات حسب النوع</h3>
        <div class="kv">
          <?php foreach (transaction_meta() as $key => $meta):
              $n = 0;
              foreach ($byType as $row) { if ($row['transaction_type'] === $key) { $n = (int)$row['n']; } }
          ?>
            <div class="kv-row"><span><?= icon($meta['icon'], 13) ?> <?= e($meta['label']) ?></span><b><?= $n ?></b></div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('landmark', 18) ?> جمعيات بانتظار التوثيق</h3>
        <?php if (!$pendingAssoc): ?>
          <p class="text-sm text-muted">لا توجد طلبات توثيق معلّقة.</p>
        <?php else: ?>
          <div class="list">
            <?php foreach ($pendingAssoc as $a): ?>
              <div class="list-row">
                <span class="grow"><b><?= e((string)$a['name']) ?></b><span><?= e((string)$a['city']) ?> · <?= e(time_ago((string)$a['created_at'])) ?></span></span>
                <a class="icon-btn" href="<?= e(url('admin/associations.php?status=pending')) ?>"><?= icon('eye', 13) ?>مراجعة</a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('trending', 18) ?> أكثر التصنيفات</h3>
        <div class="kv">
          <?php foreach ($byCat as $c): ?>
            <div class="kv-row"><span><?= e((string)$c['name_ar']) ?></span><b><?= (int)$c['n'] ?></b></div>
          <?php endforeach; ?>
        </div>
      </div>
    </aside>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
