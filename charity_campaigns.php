<?php
/**
 * charity_campaigns.php — public list of active campaigns.
 * Each card links to its shareable page (campaign.php?slug=…).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/components.php';

$campaigns = CampaignRepo::activePublic();
$totalNeeded = 0;
$totalReceived = 0;
foreach ($campaigns as $c) {
    $totalNeeded   += (int)$c['target_items'];
    $totalReceived += (int)$c['received_items'];
}
$overallPct = $totalNeeded > 0 ? min(100, (int)round($totalReceived / $totalNeeded * 100)) : 0;
$donationsCount = (int)Repo::q("SELECT COUNT(*) c FROM donations WHERE status IN ('received','distributed')")[0]['c'] ?? 0;
$assocCount     = (int)Repo::q("SELECT COUNT(*) c FROM associations WHERE status='verified'")[0]['c'] ?? 0;

$pageTitle   = 'الحملات الخيرية | قيمة وأثر';
$currentPage = 'charity';
$pageHead    = [
    'title'    => icon('hand-heart', 28) . ' الحملات الخيرية',
    'subtitle' => 'حملات جمعيات موثّقة تنتظر مشاركتك — تبرّعك يصبح إيصالاً وتوزيعاً موثّقاً على المستفيدين.',
];
require __DIR__ . '/includes/header.php';
?>

<div class="container section" style="padding-top:24px">
  <div class="stats mb-16">
    <div class="stat"><span class="stat-ic"><?= icon('megaphone', 20) ?></span><b><?= count($campaigns) ?></b><span>حملة نشطة</span></div>
    <div class="stat"><span class="stat-ic"><?= icon('package', 20) ?></span><b><?= number_format($totalReceived) ?></b><span>عنصر تم جمعه</span></div>
    <div class="stat"><span class="stat-ic"><?= icon('hand-heart', 20) ?></span><b><?= number_format($donationsCount) ?></b><span>تبرّع وصل</span></div>
    <div class="stat"><span class="stat-ic"><?= icon('landmark', 20) ?></span><b><?= number_format($assocCount) ?></b><span>جمعية موثّقة</span></div>
  </div>

  <?php if ($overallPct > 0): ?>
    <div class="panel mb-16">
      <div class="flex justify-between items-center mb-8">
        <b><?= icon('target', 16) ?> التقدّم الإجمالي لكل الحملات</b>
        <span><?= $overallPct ?>%</span>
      </div>
      <div class="progress"><i style="width:<?= $overallPct ?>%"></i></div>
      <div class="progress-label"><span><?= number_format($totalReceived) ?> من <?= number_format($totalNeeded) ?> عنصر مطلوب</span><b><?= $overallPct ?>%</b></div>
    </div>
  <?php endif; ?>

  <?php if (!$campaigns): ?>
    <div class="panel"><?= empty_state('لا توجد حملات نشطة الآن', 'تابعنا — تُضاف حملات جديدة باستمرار من الجمعيات الموثّقة.', 'hand-heart') ?></div>
  <?php else: ?>
    <div class="charity-grid">
      <?php foreach ($campaigns as $c):
          $pct = CampaignRepo::progressPct($c);
          $daysLeft = !empty($c['ends_at']) ? (int)ceil((strtotime((string)$c['ends_at']) - time()) / 86400) : null;
      ?>
        <article class="charity-card">
          <div class="flex justify-between items-center gap-8">
            <div class="logo-sm" style="margin:0"><?= icon('hand-heart', 22) ?></div>
            <?php if ($daysLeft !== null): ?>
              <span class="status st-review"><?= icon('clock', 12) ?><?= $daysLeft > 0 ? $daysLeft . ' يوماً' : 'انتهت' ?></span>
            <?php endif; ?>
          </div>
          <b style="font-size:17px;display:block;margin-top:10px"><?= e((string)$c['title']) ?></b>
          <span class="text-xs text-muted"><?= icon('landmark', 12) ?> <?= e((string)$c['association_name']) ?> · <?= e((string)$c['association_city']) ?></span>
          <p class="text-sm mt-8" style="color:var(--muted)"><?= e(excerpt((string)$c['description'], 130)) ?></p>
          <?php if (!empty($c['goal_summary'])): ?>
            <div class="tag-list mt-8"><span class="tag"><?= icon('target', 12) ?><?= e((string)$c['goal_summary']) ?></span></div>
          <?php endif; ?>
          <div class="progress mt-16"><i style="width:<?= $pct ?>%"></i></div>
          <div class="progress-label"><span><?= (int)$c['received_items'] ?> من <?= (int)$c['target_items'] ?> عنصر</span><b><?= $pct ?>%</b></div>
          <div class="row-actions mt-16">
            <a class="btn btn-primary btn-sm grow" href="<?= e(url('campaign.php?slug=' . urlencode((string)$c['slug']))) ?>"><?= icon('hand-heart', 15) ?>شارك في الحملة</a>
            <button type="button" class="icon-btn" data-copy="<?= e(url('campaign.php?slug=' . urlencode((string)$c['slug']))) ?>" title="نسخ رابط الحملة"><?= icon('link', 14) ?></button>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="panel mt-16" style="margin-top:16px">
    <div class="flex justify-between items-center gap-8 flex-wrap">
      <div>
        <b><?= icon('landmark', 16) ?> هل تدير جمعية خيرية؟</b>
        <p class="text-sm text-muted" style="margin-top:4px">سجّل جمعيتك وأضف حملتك الأولى — استلام التبرعات وإصدار الإيصالات مجاني بالكامل.</p>
      </div>
      <a class="btn btn-outline btn-sm" href="<?= e(url('register.php?type=association_rep')) ?>"><?= icon('plus', 15) ?>انضم كجمعية</a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
