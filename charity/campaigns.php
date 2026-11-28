<?php
/**
 * charity/campaigns.php
 *   Public (no ?campaign): list of active campaigns from verified associations
 *                          + AI campaign matching for general donations.
 *   ?campaign=ID          campaign details with needs, progress, participants.
 *   Signed-in association members additionally get management controls and
 *   a "new campaign" form (?new=1).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/components.php';

$assoc    = Auth::association();
$assocId  = $assoc ? (int)$assoc['id'] : 0;
$perms    = Auth::memberPerms();
$campaignId = get_int('campaign', 0) ?? 0;
$showNew    = get_q('new') === '1';
$campaign   = $campaignId ? CampaignRepo::find($campaignId) : null;

/* ── new campaign submission ─────────────────────────────────────────────── */
$campaignErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    require_csrf();
    Gate::requireAssociation();
    Gate::requireMemberCapability('campaigns');

    $title       = (string)post('title', '');
    $description = (string)post('description', '');
    $targetItems = post_int('target_items', 0) ?? 0;
    $goal        = (string)post('goal_summary', '');
    $startsAt    = (string)post('starts_at', today());
    $endsAt      = (string)post('ends_at', '');

    if (mb_strlen($title) < 4) {
        $campaignErrors[] = 'عنوان الحملة قصير جداً.';
    }
    if (mb_strlen($description) < 20) {
        $campaignErrors[] = 'اكتب وصفاً أوضح للحملة (20 حرفاً على الأقل).';
    }
    if ($targetItems < 1) {
        $campaignErrors[] = 'حدّد عدد العناصر المستهدفة.';
    }

    $items = [];
    foreach (($_POST['items'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['item_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $items[] = [
            'item_name'       => $name,
            'quantity_needed' => max(1, (int)($row['quantity_needed'] ?? 1)),
            'priority'        => in_array(($row['priority'] ?? 'normal'), ['urgent', 'high', 'normal', 'low'], true) ? $row['priority'] : 'normal',
        ];
    }

    if (!$campaignErrors) {
        try {
            $newId = CampaignRepo::create($assocId, (int)Auth::id(), [
                'title'        => $title,
                'description'  => $description,
                'goal_summary' => $goal,
                'target_items' => $targetItems,
                'starts_at'    => $startsAt !== '' ? $startsAt : today(),
                'ends_at'      => $endsAt !== '' ? $endsAt : null,
                'status'       => 'active',
                'items'        => $items,
            ]);
            ActivityRepo::log((int)Auth::id(), 'campaign.created', 'campaign', (int)$newId);
            flash('success', 'تم إنشاء الحملة بنجاح وبدأ عرضها للمتبرعين.');
            redirect('charity/campaigns.php?campaign=' . $newId);
        } catch (Throwable $ex) {
            $campaignErrors[] = 'تعذّر إنشاء الحملة: ' . $ex->getMessage();
        }
    }
}
$isManager  = $campaign && $assocId && (int)$campaign['association_id'] === $assocId && $perms['campaigns'];

$pageTitle   = ($campaign ? excerpt((string)$campaign['title'], 50) : 'الحملات الخيرية') . ' | قيمة وأثر';
$currentPage = 'charity';

if ($campaign):
    $items = CampaignRepo::items((int)$campaign['id']);
    $pct   = CampaignRepo::progressPct($campaign);
    $participants = CampaignRepo::participants((int)$campaign['id']);
    $donations = Repo::q(
        "SELECT d.*, u.full_name AS donor_name FROM donations d JOIN users u ON u.id=d.donor_id
          WHERE d.campaign_id=? ORDER BY d.id DESC",
        [(int)$campaign['id']]
    );
    $pageHead = [
        'title'    => icon('megaphone', 26) . ' ' . e((string)$campaign['title']),
        'subtitle' => (string)$campaign['association_name'] . ' · ' . ($campaign['status'] === 'active' ? 'حملة نشطة' : 'حملة ' . (string)$campaign['status']),
        'actions'  => '<a class="btn btn-outline btn-sm" href="' . e(url('charity/campaigns.php')) . '">' . icon('chevron-right', 15) . 'كل الحملات</a>',
    ];
else:
    $pageHead = [
        'title'    => icon('hand-heart', 28) . ' الحملات الخيرية',
        'subtitle' => 'حملات جمعيات موثّقة تجمع احتياجاتها العينية — شارك بما تستطيع أو تبرّع بمنتج.',
        'actions'  => ($assoc && $perms['campaigns'])
            ? '<a class="btn btn-primary btn-sm" href="' . e(url('charity/campaigns.php?new=1')) . '">' . icon('plus', 15) . 'حملة جديدة</a>'
            : '<a class="btn btn-primary btn-sm" href="' . e(url('add_product.php')) . '">' . icon('hand-heart', 15) . 'تبرّع بمنتج</a>',
    ];
endif;

require __DIR__ . '/../includes/header.php';
?>

<div class="container section" style="padding-top:26px">
<?php if ($campaign): ?>
  <div class="two-col">
    <div>
      <div class="panel">
        <div class="flex justify-between items-center gap-8 mb-8">
          <span class="badge <?= $campaign['status'] === 'active' ? 'badge-completed' : 'badge-muted' ?>">
            <?= icon('megaphone', 13) ?><?= $campaign['status'] === 'active' ? 'نشطة' : e((string)$campaign['status']) ?>
          </span>
          <span class="text-xs text-muted"><?= icon('calendar', 13) ?> <?= e((string)$campaign['starts_at']) ?><?= $campaign['ends_at'] ? ' — ' . e((string)$campaign['ends_at']) : '' ?></span>
        </div>
        <p style="font-size:15px;line-height:1.95"><?= nl2br(e((string)$campaign['description'])) ?></p>
        <?php if (!empty($campaign['goal_summary'])): ?>
          <div class="trust-note" style="background:var(--indigo-bg);color:#3730a3"><?= icon('target', 16) ?><span><?= e((string)$campaign['goal_summary']) ?></span></div>
        <?php endif; ?>
        <div class="progress progress-lg mt-16"><i style="width:<?= $pct ?>%"></i></div>
        <div class="progress-label"><span><?= (int)$campaign['received_items'] ?> من <?= (int)$campaign['target_items'] ?> عنصر</span><b><?= $pct ?>%</b></div>

        <?php if ($isManager): ?>
          <div class="row-actions mt-16">
            <form method="post" data-ajax="api/campaign_action.php" data-confirm="تغيير حالة الحملة؟">
              <?= csrf_field() ?>
              <input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>">
              <input type="hidden" name="action" value="<?= $campaign['status'] === 'active' ? 'close' : 'activate' ?>">
              <button class="icon-btn" type="submit"><?= icon($campaign['status'] === 'active' ? 'archive' : 'check-circle', 14) ?><?= $campaign['status'] === 'active' ? 'إغلاق الحملة' : 'تفعيل الحملة' ?></button>
            </form>
            <a class="icon-btn" href="<?= e(url('charity/donations_manage.php')) ?>"><?= icon('receipt', 14) ?>إدارة التبرعات</a>
          </div>
        <?php endif; ?>
      </div>

      <div class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('list', 18) ?> احتياجات الحملة <span class="badge badge-soft"><?= count($items) ?></span></h3>
        <?php if (!$items): ?>
          <p class="text-sm text-muted">لم تُدرج احتياجات لهذه الحملة بعد.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>العنصر</th><th>الأولوية</th><th>مطلوب</th><th>تم جمعه</th><th>التقدّم</th></tr></thead>
              <tbody>
                <?php foreach ($items as $it):
                    $ip = (int)$it['quantity_needed'] > 0 ? min(100, (int)round(((int)$it['quantity_received'] / (int)$it['quantity_needed']) * 100)) : 0;
                ?>
                  <tr>
                    <td>
                      <span class="table-title"><?= e((string)$it['item_name']) ?></span>
                      <?php if (!empty($it['category_name'])): ?><span class="table-sub"><?= e((string)$it['category_name']) ?></span><?php endif; ?>
                    </td>
                    <td>
                      <span class="status <?= $it['priority'] === 'urgent' ? 'st-rejected' : ($it['priority'] === 'high' ? 'st-review' : 'st-accepted') ?>">
                        <?= e(['urgent' => 'عاجل', 'high' => 'مهم', 'normal' => 'عادي', 'low' => 'منخفض'][(string)$it['priority']] ?? (string)$it['priority']) ?>
                      </span>
                    </td>
                    <td><?= (int)$it['quantity_needed'] ?></td>
                    <td><?= (int)$it['quantity_received'] ?></td>
                    <td style="min-width:110px"><div class="progress" style="margin:0"><i style="width:<?= $ip ?>%"></i></div></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <aside>
      <div class="panel">
        <h3><?= icon('hand-heart', 18) ?> تبرّعات هذه الحملة</h3>
        <?php if (!$donations): ?>
          <p class="text-sm text-muted">لا توجد تبرّعات مرتبطة بالحملة بعد.</p>
        <?php else: ?>
          <div class="list">
            <?php foreach (array_slice($donations, 0, 8) as $d): ?>
              <div class="list-row">
                <span class="grow"><b><?= e(excerpt((string)$d['title'], 40)) ?></b><span><?= e((string)$d['donor_name']) ?> · <?= e(time_ago((string)$d['created_at'])) ?></span></span>
                <?= donation_status((string)$d['status']) ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('users', 18) ?> المشاركون <span class="badge badge-soft"><?= count($participants) ?></span></h3>
        <?php if (!$participants): ?>
          <p class="text-sm text-muted">كن أول المشاركين.</p>
        <?php else: ?>
          <div class="list">
            <?php foreach (array_slice($participants, 0, 8) as $pt): ?>
              <div class="list-row">
                <span class="avatar avatar-sm"><?= e(avatar_letter((string)$pt['full_name'])) ?></span>
                <span class="grow"><b><?= e((string)$pt['full_name']) ?></b><span><?= e(time_ago((string)$pt['joined_at'])) ?></span></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if (Auth::check()): ?>
          <a class="btn btn-primary btn-block mt-16" href="<?= e(url('add_product.php')) ?>"><?= icon('hand-heart', 16) ?>تبرّع بمنتج للحملة</a>
        <?php else: ?>
          <a class="btn btn-primary btn-block mt-16" href="<?= e(url('login.php?next=' . urlencode('charity/campaigns.php?campaign=' . (int)$campaign['id']))) ?>"><?= icon('log-in', 16) ?>سجّل دخولك للمشاركة</a>
        <?php endif; ?>
      </div>
    </aside>
  </div>

<?php elseif ($showNew && $assoc && $perms['campaigns']): ?>
  <div class="form-wrap">
    <?php if ($campaignErrors): ?>
      <div class="alert alert-error"><?= icon('alert-circle', 18) ?><div class="grow"><ul><?php foreach ($campaignErrors as $ce): ?><li><?= e($ce) ?></li><?php endforeach; ?></ul></div></div>
    <?php endif; ?>
    <form class="form-card" method="post" action="<?= e(url('charity/campaigns.php?new=1')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <h2><?= icon('megaphone', 20) ?> حملة جديدة</h2>
      <p class="form-sub">حدّد احتياجات الحملة بالأرقام ليتمكن المتبرعون من مطابقة تبرعاتهم.</p>
      <div class="form-grid">
        <div class="field full">
          <label for="title">عنوان الحملة <i>*</i></label>
          <input type="text" id="title" name="title" required maxlength="160" placeholder="مثال: شتاء دافئ 2026">
        </div>
        <div class="field full">
          <label for="description">وصف الحملة <i>*</i></label>
          <textarea id="description" name="description" rows="4" required placeholder="من تستهدف الحملة؟ وما الاحتياج الفعلي؟"></textarea>
        </div>
        <div class="field">
          <label for="goal_summary">ملخّص الهدف</label>
          <input type="text" id="goal_summary" name="goal_summary" placeholder="ملابس شتوية وبطانيات">
        </div>
        <div class="field">
          <label for="target_items">عدد العناصر المستهدفة <i>*</i></label>
          <input type="number" id="target_items" name="target_items" min="1" value="100" required>
        </div>
        <div class="field">
          <label for="starts_at">تبدأ في</label>
          <input type="date" id="starts_at" name="starts_at" value="<?= e(today()) ?>">
        </div>
        <div class="field">
          <label for="ends_at">تنتهي في</label>
          <input type="date" id="ends_at" name="ends_at" value="<?= e(date('Y-m-d', strtotime('+60 days'))) ?>">
        </div>

        <div class="field full">
          <label>عناصر الحملة</label>
          <?php for ($i = 0; $i < 4; $i++): ?>
            <div class="reg-grid" style="margin-bottom:8px">
              <input type="text" name="items[<?= $i ?>][item_name]" placeholder="اسم العنصر <?= $i + 1 ?> (مثال: بطانيات)">
              <div class="reg-grid" style="grid-template-columns:1fr 1fr;gap:8px">
                <input type="number" name="items[<?= $i ?>][quantity_needed]" min="1" placeholder="الكمية">
                <select name="items[<?= $i ?>][priority]">
                  <option value="normal">أولوية عادية</option>
                  <option value="high">مهم</option>
                  <option value="urgent">عاجل</option>
                  <option value="low">منخفض</option>
                </select>
              </div>
            </div>
          <?php endfor; ?>
          <span class="hint">اترك الحقول الفارغة إذا لم تكن بحاجة لإضافة عناصر الآن.</span>
        </div>
      </div>
      <div class="form-actions">
        <a class="btn btn-ghost" href="<?= e(url('charity/campaigns.php')) ?>">إلغاء</a>
        <button class="btn btn-primary" type="submit"><?= icon('check', 16) ?>إنشاء الحملة</button>
      </div>
    </form>
  </div>

<?php else: ?>
  <?php if ($assoc && $perms['campaigns']): ?>
    <div class="alert alert-info">
      <?= icon('info', 18) ?>
      <div class="grow">أنشئ حملة جديدة أو تابع حملات جمعيتك من <a href="<?= e(url('charity/dashboard.php')) ?>" style="font-weight:800;color:var(--green-700)">لوحة الجمعية</a>.</div>
    </div>
  <?php endif; ?>

  <div class="two-col">
    <div>
      <?php $all = CampaignRepo::activePublic(); ?>
      <?php if (!$all): ?>
        <div class="panel"><?= empty_state('لا توجد حملات نشطة حالياً', 'عد لاحقاً أو تبرّع بمنتج مباشرة لجمعية موثّقة.', 'megaphone') ?></div>
      <?php else: ?>
        <div class="charity-grid">
          <?php foreach ($all as $c):
              $pct = CampaignRepo::progressPct($c);
          ?>
            <article class="charity-card">
              <div class="flex justify-between items-center gap-8">
                <div class="logo-sm" style="margin:0"><?= icon('hand-heart', 22) ?></div>
                <span class="badge badge-completed"><?= icon('check-circle', 12) ?>موثّقة</span>
              </div>
              <b style="font-size:16px;display:block;margin-top:10px"><?= e((string)$c['title']) ?></b>
              <span class="text-xs text-muted"><?= e((string)$c['association_name']) ?> · <?= e((string)$c['association_city']) ?></span>
              <p class="text-sm mt-8" style="color:var(--muted)"><?= e(excerpt((string)$c['description'], 120)) ?></p>
              <div class="progress mt-16"><i style="width:<?= $pct ?>%"></i></div>
              <div class="progress-label"><span><?= (int)$c['received_items'] ?> من <?= (int)$c['target_items'] ?> عنصر</span><b><?= $pct ?>%</b></div>
              <a class="btn btn-primary btn-sm btn-block mt-16" href="<?= e(url('charity/campaigns.php?campaign=' . (int)$c['id'])) ?>"><?= icon('eye', 14) ?>تفاصيل الحملة</a>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <aside>
      <div class="panel" id="aiCampaignPanel">
        <h3><?= icon('sparkles', 18) ?> مطابقة ذكية</h3>
        <p class="panel-sub">نطابق التبرّعات العامة المتاحة مع احتياجات الحملات — تعمل محلياً دون إنترنت.</p>
        <div class="list" id="aiCampaignMatches">
          <div class="ai-loading"><?= icon('loader', 15) ?> جارٍ التحليل…</div>
        </div>
      </div>

      <div class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('landmark', 18) ?> الجمعيات الموثّقة</h3>
        <div class="list">
          <?php foreach (array_slice(AssociationRepo::verified(), 0, 6) as $a): ?>
            <div class="list-row">
              <span class="stat-ic" style="width:34px;height:34px;display:grid;place-items:center;border-radius:10px;background:var(--indigo-bg);color:var(--indigo)"><?= icon('landmark', 16) ?></span>
              <span class="grow"><b><?= e((string)$a['name']) ?></b><span><?= e((string)$a['city']) ?> · <?= (int)$a['active_campaigns'] ?> حملة نشطة</span></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </aside>
  </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
