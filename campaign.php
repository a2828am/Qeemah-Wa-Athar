<?php
/**
 * campaign.php — public campaign page (shareable, indexable).
 * Shows the goal, progress, item checklist and how to contribute.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/components.php';

$slug = get_q('slug', '') ?? '';
$id   = get_int('id');
$campaign = null;
if ($slug !== '') {
    $campaign = Repo::q(
        "SELECT c.*, a.name AS association_name, a.slug AS association_slug, a.city AS association_city,
                a.status AS association_status, a.description AS association_desc, a.id AS assoc_id
           FROM donation_campaigns c JOIN associations a ON a.id=c.association_id
          WHERE c.slug=?",
        [$slug]
    )[0] ?? null;
} elseif ($id) {
    $campaign = CampaignRepo::find($id);
}

$me = Auth::user();

if (!$campaign || ($campaign['status'] ?? '') === 'draft') {
    http_response_code(404);
    $pageTitle = 'الحملة غير موجودة | قيمة وأثر';
    require __DIR__ . '/includes/header.php';
    echo '<div class="container section">' . empty_state('الحملة غير موجودة', 'ربما انتهت الحملة أو تغيّر رابطها.', 'target', 'تصفّح الحملات', url('charity_campaigns.php')) . '</div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$campaignId = (int)$campaign['id'];
$progress   = CampaignRepo::progressPct($campaign);
$items      = CampaignRepo::items($campaignId);
$participants = CampaignRepo::participants($campaignId);
$assocId    = (int)$campaign['association_id'];
$donations  = Repo::q(
    "SELECT d.*, u.full_name AS donor_name
       FROM donations d JOIN users u ON u.id=d.donor_id
      WHERE d.campaign_id=? ORDER BY d.created_at DESC LIMIT 8",
    [$campaignId]
);
$received = (int)($campaign['received_items'] ?? 0);
$target   = max(1, (int)($campaign['target_items'] ?? 1));
$daysLeft = !empty($campaign['ends_at']) ? (int)ceil((strtotime((string)$campaign['ends_at']) - time()) / 86400) : null;

$pageTitle   = excerpt((string)$campaign['title'], 70) . ' | قيمة وأثر';
$pageDesc    = excerpt((string)$campaign['description'], 150);
$currentPage = 'charity';
require __DIR__ . '/includes/header.php';
?>

<div class="container">
  <nav class="breadcrumb" aria-label="المسار">
    <a href="<?= e(url('index.php')) ?>"><?= icon('home', 14) ?>الرئيسية</a>
    <?= icon('chevron-left', 13) ?>
    <a href="<?= e(url('charity_campaigns.php')) ?>">الحملات الخيرية</a>
    <?= icon('chevron-left', 13) ?>
    <span><?= e(excerpt((string)$campaign['title'], 40)) ?></span>
  </nav>

  <div class="detail-grid">
    <div>
      <div class="panel">
        <div class="flex items-center gap-8 flex-wrap mb-8">
          <span class="status <?= $campaign['status'] === 'active' ? 'st-accepted' : 'st-completed' ?>">
            <?= $campaign['status'] === 'active' ? 'حملة نشطة' : 'حملة مكتملة' ?>
          </span>
          <span class="cat"><?= e((string)$campaign['association_name']) ?></span>
          <?php if ($daysLeft !== null): ?>
            <span class="status st-review"><?= icon('clock', 12) ?><?= $daysLeft > 0 ? $daysLeft . ' يوماً متبقياً' : 'انتهت المدة' ?></span>
          <?php endif; ?>
        </div>
        <h1 style="font-size:clamp(22px,3vw,30px);font-weight:900;line-height:1.35"><?= e((string)$campaign['title']) ?></h1>
        <p style="margin-top:12px;font-size:15px;line-height:1.95;color:var(--ink);white-space:pre-line"><?= e((string)$campaign['description']) ?></p>

        <div class="mt-16">
          <div class="flex justify-between items-center mb-8">
            <span class="text-sm text-muted">تقدّم الحملة</span>
            <b><?= (int)$progress ?>%</b>
          </div>
          <div style="height:12px;border-radius:999px;background:var(--green-50,#f0fdf4);overflow:hidden">
            <i style="display:block;height:100%;width:<?= (int)$progress ?>%;background:linear-gradient(90deg,var(--green-500),var(--green-700))"></i>
          </div>
          <div class="flex justify-between mt-8 text-xs text-muted">
            <span><?= $received ?> عنصر مستلم</span>
            <span>الهدف <?= $target ?> عنصر</span>
          </div>
        </div>
      </div>

      <div class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('list', 18) ?> احتياجات الحملة</h3>
        <?php if (!$items): ?>
          <p class="text-sm text-muted">لا توجد قائمة احتياجات منظّمة لهذه الحملة — يمكنك التبرّع بأي عنصر مناسب.</p>
        <?php else: ?>
          <div class="list">
            <?php foreach ($items as $it):
                $need = max(1, (int)$it['quantity_needed']);
                $got  = (int)($it['quantity_received'] ?? 0);
                $pct  = min(100, (int)round($got / $need * 100));
            ?>
              <div class="list-row">
                <span class="stat-ic" style="width:34px;height:34px;display:grid;place-items:center;border-radius:10px;background:var(--green-50,#f0fdf4);color:var(--green-700)">
                  <?= icon($it['priority'] === 'urgent' ? 'alert-circle' : 'package', 15) ?>
                </span>
                <span class="grow">
                  <b><?= e((string)$it['item_name']) ?></b>
                  <span><?= $got ?> / <?= $need ?> · <?= $pct ?>%</span>
                </span>
                <span class="status <?= $pct >= 100 ? 'st-completed' : 'st-review' ?>"><?= $pct >= 100 ? 'مكتمل' : 'مستمر' ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('hand-heart', 18) ?> آخر التبرّعات</h3>
        <?php if (!$donations): ?>
          <p class="text-sm text-muted">كن أول من يتبرّع لهذه الحملة.</p>
        <?php else: ?>
          <div class="list">
            <?php foreach ($donations as $d): ?>
              <div class="list-row">
                <span class="avatar avatar-sm"><?= e(avatar_letter((string)$d['donor_name'])) ?></span>
                <span class="grow">
                  <b><?= e((string)$d['donor_name']) ?></b>
                  <span><?= e((string)$d['title']) ?> · <?= e((string)$d['quantity']) ?></span>
                </span>
                <span class="status st-accepted"><?= e(donation_status((string)$d['status'])) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <aside>
      <div class="panel">
        <h3><?= icon('landmark', 18) ?> <?= e((string)$campaign['association_name']) ?></h3>
        <p class="text-sm text-muted"><?= e(excerpt((string)($campaign['association_desc'] ?? ''), 160)) ?></p>
        <div class="kv mt-8">
          <div class="kv-row"><span>المدينة</span><b><?= e((string)($campaign['association_city'] ?? '—')) ?></b></div>
          <div class="kv-row"><span>الحالة</span><b><?= ($campaign['association_status'] ?? '') === 'verified' ? 'موثّقة' : 'قيد التوثيق' ?></b></div>
          <div class="kv-row"><span>المشاركون</span><b><?= count($participants) ?></b></div>
          <?php if (!empty($campaign['goal_summary'])): ?>
            <div class="kv-row"><span>هدف الحملة</span><b><?= e((string)$campaign['goal_summary']) ?></b></div>
          <?php endif; ?>
        </div>

        <div class="divider-top">
          <?php if (!$me): ?>
            <a class="btn btn-primary btn-block" href="<?= e(url('login.php?next=' . urlencode('campaign.php?slug=' . (string)$campaign['slug']))) ?>">
              <?= icon('log-in', 16) ?>سجّل دخولك للتبرّع
            </a>
          <?php elseif (Auth::isGuest()): ?>
            <a class="btn btn-primary btn-block" href="<?= e(url('login.php')) ?>"><?= icon('log-in', 16) ?>تسجيل الدخول</a>
          <?php else: ?>
            <a class="btn btn-primary btn-block" href="<?= e(url('add_product.php?donate=1&campaign=' . $campaignId)) ?>">
              <?= icon('gift', 16) ?>تبرّع لهذه الحملة
            </a>
          <?php endif; ?>
          <a class="btn btn-outline btn-block mt-8" style="margin-top:8px" href="<?= e(url('charity_campaigns.php')) ?>">
            <?= icon('target', 16) ?>كل الحملات
          </a>
        </div>
        <p class="text-xs text-muted mt-8"><?= icon('info', 12) ?> التبرّع عبر المنصة مجاني، ويصل للجمعية بعد تأكيد الاستلام.</p>
      </div>
    </aside>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
