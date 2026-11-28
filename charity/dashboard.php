<?php
/**
 * charity/dashboard.php — association home:
 * stats, quick actions, latest donations to claim and open campaigns.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/components.php';

$assoc = Gate::requireAssociation();
$assocId = (int)$assoc['id'];
$perms   = Auth::memberPerms();
$stats   = AssociationRepo::stats($assocId);
$recent  = DonationRepo::availableForAssociation($assocId, 1, 5)[0];
$claimed = array_slice(DonationRepo::claimedByAssociation($assocId), 0, 5);
$campaigns = array_slice(CampaignRepo::forAssociation($assocId), 0, 3);
$members   = AssociationRepo::members($assocId);
$me        = Auth::user();

$pageTitle   = 'لوحة الجمعية | قيمة وأثر';
$currentPage = 'charity_panel';
$pageHead    = [
    'title'    => icon('landmark', 28) . ' ' . e((string)$assoc['name']),
    'subtitle' => $assoc['status'] === 'verified'
        ? 'جمعية موثّقة · ' . (string)$assoc['city'] . ' · لوحة إدارة التبرعات والحملات والمستفيدين.'
        : 'حساب الجمعية قيد التوثيق — يمكنك التصفح والتجهيز، ويُفعّل استلام التبرعات بعد الموافقة.',
    'actions'  => '<a class="btn btn-primary btn-sm" href="' . e(url('charity/campaigns.php?new=1')) . '">' . icon('plus', 15) . 'حملة جديدة</a>'
                . '<a class="btn btn-outline btn-sm" href="' . e(url('charity/donations_manage.php')) . '">' . icon('hand-heart', 15) . 'التبرعات المتاحة</a>',
];
require __DIR__ . '/../includes/header.php';
?>

<div class="container">
  <?php if ($assoc['status'] !== 'verified'): ?>
    <div class="alert alert-warning">
      <?= icon('alert-triangle', 18) ?>
      <div class="grow">حساب جمعيتك «<b><?= e((string)$assoc['status'] === 'pending' ? 'قيد المراجعة' : (string)$assoc['status']) ?></b>». يمكنك إضافة الحملات والمستفيدين، لكن استلام التبرعات يُفعّل بعد التوثيق من الإدارة.</div>
    </div>
  <?php endif; ?>

  <div class="stat-grid">
    <a class="stat" href="<?= e(url('charity/donations_manage.php')) ?>">
      <span class="stat-ic"><?= icon('hand-heart', 18) ?></span>
      <b><?= (int)$stats['received'] ?></b><span>تبرّع مستلَم من أصل <?= (int)$stats['donations'] ?></span>
    </a>
    <a class="stat" href="<?= e(url('charity/campaigns.php')) ?>">
      <span class="stat-ic"><?= icon('megaphone', 18) ?></span>
      <b><?= (int)$stats['active_campaigns'] ?></b><span>حملة نشطة (<?= (int)$stats['campaigns'] ?> إجمالاً)</span>
    </a>
    <a class="stat" href="<?= e(url('charity/beneficiaries.php')) ?>">
      <span class="stat-ic"><?= icon('users', 18) ?></span>
      <b><?= (int)$stats['beneficiaries'] ?></b><span>مستفيد مسجّل · <?= (int)$stats['distributed'] ?> عملية توزيع</span>
    </a>
    <a class="stat" href="<?= e(url('charity/donations_manage.php?tab=receipts')) ?>">
      <span class="stat-ic"><?= icon('receipt', 18) ?></span>
      <b><?= (int)$stats['receipts'] ?></b><span>إيصال صادر</span>
    </a>
  </div>

  <div class="two-col">
    <div>
      <div class="panel">
        <h3><?= icon('hand-heart', 18) ?> تبرّعات بانتظار الاستلام <span class="badge badge-soft"><?= count($recent) ?></span></h3>
        <p class="panel-sub">التبرّعات الموجّهة لجمعيتك والتبرّعات العامة المتاحة لك.</p>
        <?php if (!$recent): ?>
          <?= empty_state('لا توجد تبرّعات متاحة حالياً', 'ستظهر هنا التبرّعات العامة والتبرّعات الموجّهة لجمعيتك.', 'hand-heart') ?>
        <?php else: ?>
          <div class="list">
            <?php foreach ($recent as $d): ?>
              <div class="list-row">
                <span class="stat-ic" style="width:38px;height:38px;display:grid;place-items:center;border-radius:11px;background:var(--indigo-bg);color:var(--indigo)"><?= icon('gift', 18) ?></span>
                <span class="grow">
                  <b><?= e(excerpt((string)$d['title'], 54)) ?></b>
                  <span><?= e((string)$d['donor_name']) ?> · <?= e(time_ago((string)$d['created_at'])) ?> ·
                    <?= ($d['donation_scope'] ?? 'general') === 'general' ? 'تبرّع عام' : 'موجّه لجمعيتك' ?></span>
                </span>
                <?php if ($perms['donations']): ?>
                  <form method="post" data-ajax="api/claim_donation.php" data-confirm="استلام هذا التبرّع باسم الجمعية؟" data-done="reload">
                    <?= csrf_field() ?>
                    <input type="hidden" name="donation_id" value="<?= (int)$d['id'] ?>">
                    <button class="btn btn-primary btn-sm" type="submit"><?= icon('check', 14) ?>استلام</button>
                  </form>
                <?php else: ?>
                  <span class="badge badge-muted"><?= icon('lock', 12) ?>بدون صلاحية</span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <a class="btn btn-ghost btn-sm mt-16" href="<?= e(url('charity/donations_manage.php')) ?>">كل التبرّعات<?= icon('arrow-left', 14) ?></a>
        <?php endif; ?>
      </div>

      <div class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('megaphone', 18) ?> حملاتك</h3>
        <?php if (!$campaigns): ?>
          <?= empty_state('لا توجد حملات بعد', 'أنشئ حملتك الأولى لعرض احتياجاتك وجمع التبرّعات العينية.', 'megaphone', 'حملة جديدة', url('charity/campaigns.php?new=1')) ?>
        <?php else: ?>
          <div class="list">
            <?php foreach ($campaigns as $c):
                $pct = CampaignRepo::progressPct($c);
            ?>
              <div class="list-row" style="flex-direction:column;align-items:stretch;gap:6px">
                <div class="flex justify-between items-center gap-8">
                  <b><?= e((string)$c['title']) ?></b>
                  <span class="badge <?= $c['status'] === 'active' ? 'badge-completed' : 'badge-muted' ?>"><?= $c['status'] === 'active' ? 'نشطة' : e((string)$c['status']) ?></span>
                </div>
                <div class="progress"><i style="width:<?= $pct ?>%"></i></div>
                <div class="progress-label"><span><?= (int)$c['received_items'] ?> / <?= (int)$c['target_items'] ?> عنصر</span><b><?= $pct ?>%</b></div>
                <a class="icon-btn" href="<?= e(url('charity/campaigns.php?campaign=' . (int)$c['id'])) ?>"><?= icon('eye', 13) ?>تفاصيل الحملة</a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <aside>
      <div class="panel">
        <h3><?= icon('user-check', 18) ?> صلاحياتك</h3>
        <div class="kv">
          <div class="kv-row"><span>دورك</span><b><?= e(['representative' => 'ممثل الجمعية', 'manager' => 'مدير', 'coordinator' => 'منسّق', 'viewer' => 'مشاهد'][(string)($assoc['member_role'] ?? 'representative')] ?? 'عضو') ?></b></div>
          <div class="kv-row"><span>الحملات</span><b><?= $perms['campaigns'] ? 'مسموح' : 'غير مسموح' ?></b></div>
          <div class="kv-row"><span>استلام التبرعات</span><b><?= $perms['donations'] ? 'مسموح' : 'غير مسموح' ?></b></div>
          <div class="kv-row"><span>المستفيدون</span><b><?= $perms['beneficiaries'] ? 'مسموح' : 'غير مسموح' ?></b></div>
          <div class="kv-row"><span>إدارة الأعضاء</span><b><?= $perms['members'] ? 'مسموح' : 'غير مسموح' ?></b></div>
        </div>
      </div>

      <div class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('users', 18) ?> الفريق <span class="badge badge-soft"><?= count($members) ?></span></h3>
        <div class="list">
          <?php foreach (array_slice($members, 0, 5) as $m): ?>
            <div class="list-row">
              <span class="avatar avatar-sm"><?= e(avatar_letter((string)$m['full_name'])) ?></span>
              <span class="grow">
                <b><?= e((string)$m['full_name']) ?></b>
                <span><?= e(['representative' => 'ممثل', 'manager' => 'مدير', 'coordinator' => 'منسّق', 'viewer' => 'مشاهد'][(string)$m['role']] ?? 'عضو') ?></span>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ($perms['members']): ?>
          <a class="btn btn-outline btn-sm mt-16" href="<?= e(url('charity/members.php')) ?>">إدارة الأعضاء<?= icon('arrow-left', 14) ?></a>
          <?php
            $pendingJoins = (int)Repo::scalar(
                "SELECT COUNT(*) FROM association_join_requests WHERE association_id=? AND status='pending'",
                [(int)$assoc['id']]
            );
          ?>
          <a class="btn btn-outline btn-sm mt-16" href="<?= e(url('charity/join_requests.php')) ?>">
            <?= icon('user-plus', 14) ?>طلبات الانضمام
            <?php if ($pendingJoins > 0): ?><span class="badge badge-pending"><?= $pendingJoins ?></span><?php endif; ?>
          </a>
        <?php endif; ?>
      </div>

      <div class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('hand-heart', 18) ?> آخر ما استلمتم</h3>
        <?php if (!$claimed): ?>
          <p class="text-sm text-muted">لم تستلم الجمعية أي تبرّع بعد.</p>
        <?php else: ?>
          <div class="list">
            <?php foreach ($claimed as $d): ?>
              <div class="list-row">
                <span class="grow">
                  <b><?= e(excerpt((string)$d['title'], 40)) ?></b>
                  <span><?= e((string)$d['donor_name']) ?></span>
                </span>
                <?= donation_status((string)$d['status']) ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </aside>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
