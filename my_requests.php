<?php
/**
 * my_requests.php — the signed-in user's dashboard:
 *   incoming requests (as product owner) → accept / reject, follow the deal
 *   outgoing requests (as requester)     → track / cancel / rate
 *   my listings                          → status + quick links
 * Each row links to request.php where the agreement, delivery and dispute
 * confirmations live. State changes go through RequestFlowRepo only.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/components.php';

Auth::requireLogin('my_requests.php');
$me   = Auth::user();
$uid  = (int)$me['id'];
$tab  = get_q('tab', 'incoming') ?? 'incoming';
$status = get_q('status', '') ?? '';
$status = $status !== '' && isset(request_status_meta()[$status]) ? $status : '';
$page   = max(1, get_int('page', 1) ?? 1);
$focus  = get_int('focus', 0) ?? 0;

$dash = StatsRepo::userDashboard($uid);

$incoming = [[], ['total' => 0, 'pages' => 1]];
$outgoing = [[], ['total' => 0, 'pages' => 1]];
if ($tab === 'incoming') {
    $incoming = RequestRepo::forOwner($uid, $status ?: null, $page, 10);
}
if ($tab === 'outgoing') {
    $outgoing = RequestRepo::forRequester($uid, $status ?: null, $page, 10);
}
$myListings = $tab === 'listings' ? ProductRepo::search(['owner_id' => $uid], $page, 12) : [[], ['total' => 0, 'pages' => 1]];

$activeRows  = RequestRepo::activeForUser($uid, $tab === 'outgoing' ? 'requester' : 'owner', 6);

/* One live deal per listed product, for the progress strip under each card. */
$dealsByProduct = [];
foreach (RequestRepo::activeForUser($uid, 'owner', 40) as $d) {
    // The compact bar draws its dots from the lifecycle history, which this
    // query does not join; add it so a step does not look un-reached.
    $d['history'] = RequestFlowRepo::historyFor((int)$d['id']);
    $dealsByProduct[(int)$d['product_id']] = $d;
}
$activeDeals = count($activeRows);
$rows  = $tab === 'incoming' ? $incoming[0] : ($tab === 'outgoing' ? $outgoing[0] : $myListings[0]);
$pager = $tab === 'incoming' ? $incoming[1] : ($tab === 'outgoing' ? $outgoing[1] : $myListings[1]);

$counts = RequestRepo::statusCounts($uid, 'owner');
$outCounts = RequestRepo::statusCounts($uid, 'requester');
$ratedRequests = [];
foreach ($rows as $r) {
    if (($r['status'] ?? '') === 'delivered') {
        $ratedRequests[(int)$r['id']] = RatingRepo::hasRated((int)$r['id'], $uid);
    }
}

$pageTitle   = 'طلباتي | قيمة وأثر';
$currentPage = 'requests';
$pageHead    = [
    'title'    => icon('dashboard', 28) . ' لوحتي',
    'subtitle' => 'طلباتك الواردة والصادرة، منتجاتك، وتقييماتك — كل شيء في مكان واحد.',
    'actions'  => '<a class="btn btn-primary btn-sm" href="' . e(url('add_product.php')) . '">' . icon('plus', 15) . 'أضف منتج</a>'
                . '<a class="btn btn-outline btn-sm" href="' . e(url('favorites.php')) . '">' . icon('heart', 15) . 'المفضلة (' . (int)$dash['favorites'] . ')</a>',
];
require __DIR__ . '/includes/header.php';
?>

<div class="container">
  <div class="stat-grid">
    <a class="stat" href="<?= e(url('my_requests.php?tab=listings')) ?>">
      <span class="stat-ic"><?= icon('package', 19) ?></span>
      <b><?= (int)$dash['my_products'] ?></b><span>منتجاتي — <?= (int)$dash['available'] ?> متاح، <?= (int)$dash['reserved'] ?> محجوز</span>
    </a>
    <a class="stat" href="<?= e(url('my_requests.php?tab=incoming')) ?>">
      <span class="stat-ic"><?= icon('clipboard', 19) ?></span>
      <b><?= (int)$dash['incoming'] ?></b><span>طلبات واردة — <?= (int)$dash['new_incoming'] ?> جديدة</span>
    </a>
    <a class="stat" href="<?= e(url('my_requests.php?tab=outgoing')) ?>">
      <span class="stat-ic"><?= icon('handshake', 19) ?></span>
      <b><?= (int)$dash['outgoing'] ?></b><span>طلبات أرسلتها</span>
    </a>
    <a class="stat" href="<?= e(url('chat.php')) ?>">
      <span class="stat-ic"><?= icon('message-circle', 19) ?></span>
      <b><?= (int)$dash['unread_msgs'] ?></b><span>رسائل غير مقروءة</span>
    </a>
  </div>

  <div class="dash-tabs">
    <a class="dash-tab<?= $tab === 'incoming' ? ' active' : '' ?>" href="<?= e(url('my_requests.php?tab=incoming')) ?>">
      <?= icon('inbox', 15) ?>الواردة <span class="badge badge-soft"><?= (int)$counts['awaiting_owner'] ?></span>
    </a>
    <a class="dash-tab<?= $tab === 'outgoing' ? ' active' : '' ?>" href="<?= e(url('my_requests.php?tab=outgoing')) ?>">
      <?= icon('upload', 15) ?>الصادرة
    </a>
    <a class="dash-tab<?= $tab === 'listings' ? ' active' : '' ?>" href="<?= e(url('my_requests.php?tab=listings')) ?>">
      <?= icon('package', 15) ?>منتجاتي
    </a>
  </div>

  <?php if ($tab !== 'listings'): ?>
    <div class="tabs mb-16">
      <a class="tab<?= $status === '' ? ' active' : '' ?>" href="<?= e(url('my_requests.php?tab=' . $tab)) ?>">الكل</a>
      <?php foreach (request_status_meta() as $key => $meta):
          $n = (int)($tab === 'incoming' ? $counts[$key] : $outCounts[$key]);
      ?>
        <?php if ($n > 0 || $status === $key): ?>
          <a class="tab<?= $status === $key ? ' active' : '' ?>" href="<?= e(url('my_requests.php?tab=' . $tab . '&status=' . $key)) ?>">
            <?= icon($meta['icon'], 14) ?><?= e($meta['label']) ?>
            <span class="text-xs" style="opacity:.7">(<?= $n ?>)</span>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($tab !== 'listings' && $activeDeals > 0): ?>
    <!-- The owner's own view of what is currently in motion -->
    <div class="panel mb-16">
      <div class="panel-head">
        <h3><?= icon('route', 18) ?> عمليات جارية</h3>
        <a class="link-sm" href="<?= e(url('my_requests.php?tab=' . $tab)) ?>">عرض الكل</a>
      </div>
      <div class="deal-grid">
        <?php foreach ($activeRows as $r): ?>
          <a class="deal-mini" href="<?= e(url('request.php?id=' . (int)$r['id'])) ?>">
            <div class="deal-mini-top">
              <b>#<?= (int)$r['id'] ?></b>
              <span class="badge <?= e(request_status_meta()[(string)$r['status']]['class'] ?? '') ?>">
                <?= icon(request_status_meta()[(string)$r['status']]['icon'] ?? 'clock', 12) ?>
                <?= e(request_status_meta()[(string)$r['status']]['label'] ?? $r['status']) ?>
              </span>
            </div>
            <div class="deal-mini-title"><?= e(excerpt((string)$r['product_title'], 46)) ?></div>
            <?= request_progress($r, true) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="panel">
      <?= empty_state(
          $tab === 'listings' ? 'لا توجد منتجات بعد' : 'لا توجد طلبات في هذه الحالة',
          $tab === 'listings'
              ? 'أضف أول منتج لك وابدأ رحلة القيمة والأثر.'
              : 'عندما تصلك طلبات جديدة ستجدها هنا مع إشعار فوري.',
          $tab === 'listings' ? 'package' : 'clipboard',
          'أضف منتجاً',
          url('add_product.php')
      ) ?>
    </div>
  <?php elseif ($tab === 'listings'): ?>
    <div class="product-grid">
      <?php foreach ($rows as $p):
          /* Every product that is mid-deal carries its own progress bar, so the
             owner can see how far each one has got without opening a request. */
          $dealForProduct = $dealsByProduct[(int)$p['id']] ?? null;
      ?>
        <div class="product-with-deal">
          <?= product_card($p, [], false) ?>
          <?php if ($dealForProduct): ?>
            <a class="deal-strip" href="<?= e(url('request.php?id=' . (int)$dealForProduct['id'])) ?>" title="الطلب #<?= (int)$dealForProduct['id'] ?> — <?= e(request_status_meta()[(string)$dealForProduct['status']]['label'] ?? '') ?>">
              <?= request_progress($dealForProduct, true) ?>
            </a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?= pagination_links($pager, ['tab' => 'listings']) ?>
  <?php else: ?>
    <!-- Desktop table -->
    <div class="table-wrap responsive">
      <table>
        <thead>
          <tr>
            <th>المنتج</th>
            <th><?= $tab === 'incoming' ? 'مقدّم الطلب' : 'صاحب المنتج' ?></th>
            <th>المسار</th>
            <th>الحالة</th>
            <th>آخر تحديث</th>
            <th>إجراءات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
              /* $isParty used to be derived from the STATUS — which is always
                 true here, so every row got a «محادثة» button even when the
                 viewer is not a party (a targeted donation is between the donor
                 and the association's rep). Ask the request itself instead, and
                 resolve the counterpart from the same party list. */
              $rParties  = RequestFlowRepo::expectedParties($r);
              $isParty   = in_array($uid, $rParties, true);
              $isOwner   = $tab === 'incoming';
              $rOther    = array_values(array_diff($rParties, [$uid]));
              $otherName = (string)Repo::scalar('SELECT full_name FROM users WHERE id=?', [(int)($rOther[0] ?? 0)]);
              $rStatus   = (string)$r['status'];
              $convId    = (int)($r['conversation_id'] ?? 0);
          ?>
            <tr id="req-<?= (int)$r['id'] ?>"<?= $focus === (int)$r['id'] ? ' style="background:#f0fdf4"' : '' ?>>
              <td>
                <div class="flex items-center gap-8">
                  <img class="thumb" src="<?= e(product_image($r['primary_image'] ?? null)) ?>" alt="">
                  <div>
                    <a class="table-title" href="<?= e(url('request.php?id=' . (int)$r['id'])) ?>"><?= e(excerpt((string)$r['product_title'], 40)) ?></a>
                    <span class="table-sub">#<?= (int)$r['id'] ?> · <?= e(transaction_meta()[$r['transaction_type']]['label']) ?><?= $r['offered_title'] ? ' · مقابل: ' . e(excerpt((string)$r['offered_title'], 22)) : '' ?></span>
                  </div>
                </div>
              </td>
              <td>
                <span class="table-title"><?= e((string)$otherName) ?></span>
                <span class="table-sub"><?= stars((float)$r['requester_rating']) ?></span>
              </td>
              <td style="min-width:190px"><?= request_progress($r, true) ?></td>
              <td><?= req_status($rStatus) ?></td>
              <td><span class="table-sub"><?= e(time_ago((string)$r['updated_at'])) ?><br><?= e(date('m/d H:i', strtotime((string)$r['updated_at']))) ?></span></td>
              <td>
                <div class="row-actions">
                  <?php if ($isOwner && $rStatus === 'awaiting_owner'): ?>
                    <form method="post" action="<?= e(url('request.php?id=' . (int)$r['id'])) ?>" data-confirm="قبول الطلب وفتح محادثة؟">
                      <?= csrf_field() ?><input type="hidden" name="action" value="accept">
                      <button class="icon-btn" type="submit" style="color:#15803d"><?= icon('check-circle', 13) ?>قبول</button>
                    </form>
                    <form method="post" action="<?= e(url('request.php?id=' . (int)$r['id'])) ?>" data-confirm="رفض الطلب؟">
                      <?= csrf_field() ?><input type="hidden" name="action" value="reject">
                      <button class="icon-btn danger" type="submit"><?= icon('ban', 13) ?>رفض</button>
                    </form>
                  <?php endif; ?>
                  <a class="icon-btn" href="<?= e(url('request.php?id=' . (int)$r['id'])) ?>"><?= icon('route', 13) ?>المسار</a>
                  <?php if ($convId && $isParty): ?>
                    <a class="icon-btn" href="<?= e(url('chat.php?c=' . $convId)) ?>"><?= icon('message-circle', 13) ?>محادثة<?= (int)($r['unread_messages'] ?? 0) > 0 ? ' (' . (int)$r['unread_messages'] . ')' : '' ?></a>
                  <?php endif; ?>
                  <?php if ($rStatus === 'delivered' && empty($ratedRequests[(int)$r['id']])): ?>
                    <button class="icon-btn" type="button" data-rate-request="<?= (int)$r['id'] ?>" data-rate-name="<?= e((string)$otherName) ?>">
                      <?= icon('star', 13) ?>تقييم
                    </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Mobile cards -->
    <div class="req-cards">
      <?php foreach ($rows as $r):
          $rParties  = RequestFlowRepo::expectedParties($r);
          $isParty   = in_array($uid, $rParties, true);
          $isOwner   = $tab === 'incoming';
          $rOther    = array_values(array_diff($rParties, [$uid]));
          $otherName = (string)Repo::scalar('SELECT full_name FROM users WHERE id=?', [(int)($rOther[0] ?? 0)]);
          $rStatus   = (string)$r['status'];
          $convId    = (int)($r['conversation_id'] ?? 0);
      ?>
        <article class="req-card" id="reqm-<?= (int)$r['id'] ?>">
          <div class="req-top">
            <span class="req-title"><?= e(excerpt((string)$r['product_title'], 44)) ?></span>
            <?= req_status($rStatus) ?>
          </div>
          <div class="req-meta">
            <span><?= icon('user', 13) ?><?= e((string)$otherName) ?></span>
            <span><?= icon('tag', 13) ?><?= e(transaction_meta()[$r['transaction_type']]['label']) ?></span>
            <span><?= icon('clock', 13) ?><?= e(time_ago((string)$r['updated_at'])) ?></span>
          </div>
          <?= request_progress($r, true) ?>
          <?php if (!empty($r['message'])): ?>
            <div class="req-msg"><?= e(excerpt((string)$r['message'], 110)) ?></div>
          <?php endif; ?>
          <div class="row-actions">
            <a class="icon-btn" href="<?= e(url('request.php?id=' . (int)$r['id'])) ?>"><?= icon('route', 14) ?>المسار</a>
            <?php if ($convId && $isParty): ?>
              <a class="icon-btn" href="<?= e(url('chat.php?c=' . $convId)) ?>"><?= icon('message-circle', 14) ?>محادثة<?= (int)($r['unread_messages'] ?? 0) > 0 ? ' (' . (int)$r['unread_messages'] . ')' : '' ?></a>
            <?php endif; ?>
            <?php if ($isOwner && $rStatus === 'awaiting_owner'): ?>
              <form method="post" action="<?= e(url('request.php?id=' . (int)$r['id'])) ?>" data-confirm="قبول الطلب وفتح محادثة؟">
                <?= csrf_field() ?><input type="hidden" name="action" value="accept">
                <button class="icon-btn" type="submit" style="color:#15803d"><?= icon('check-circle', 14) ?>قبول</button>
              </form>
              <form method="post" action="<?= e(url('request.php?id=' . (int)$r['id'])) ?>" data-confirm="رفض الطلب؟">
                <?= csrf_field() ?><input type="hidden" name="action" value="reject">
                <button class="icon-btn danger" type="submit"><?= icon('ban', 14) ?>رفض</button>
              </form>
            <?php endif; ?>
            <?php if ($rStatus === 'delivered' && empty($ratedRequests[(int)$r['id']])): ?>
              <button class="icon-btn" type="button" data-rate-request="<?= (int)$r['id'] ?>" data-rate-name="<?= e((string)$otherName) ?>"><?= icon('star', 14) ?>تقييم</button>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <?= pagination_links($pager, ['tab' => $tab, 'status' => $status]) ?>
  <?php endif; ?>
</div>

<!-- Rating modal -->
<div class="modal-backdrop" id="ratingModal" role="dialog" aria-modal="true" aria-labelledby="ratingTitle">
  <div class="modal">
    <button type="button" class="modal-close" data-close-modal aria-label="إغلاق"><?= icon('x', 17) ?></button>
    <h3 id="ratingTitle"><?= icon('star', 19) ?>تقييم الطرف الآخر</h3>
    <p class="modal-sub">كيف كانت تجربتك مع <b id="ratingName">—</b>؟ تقييمك يساعد المجتمع على الثقة.</p>
    <form method="post" data-ajax="api/request_action.php" data-done="reload">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="rate">
      <input type="hidden" name="request_id" id="rateRequestId" value="">
      <div class="field mb-16">
        <label>التقييم</label>
        <div class="star-input">
          <input type="hidden" name="score" value="5">
          <?php for ($i = 0; $i < 5; $i++): ?>
            <button type="button" class="on" aria-label="نجمة <?= $i + 1 ?>"><?= icon('star', 26) ?></button>
          <?php endfor; ?>
        </div>
      </div>
      <div class="field mb-16">
        <label for="ratingComment">ملاحظتك (اختياري)</label>
        <textarea name="comment" id="ratingComment" rows="3" placeholder="التزام بالموعد، حالة المنتج، التعامل…"></textarea>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" data-close-modal>إلغاء</button>
        <button class="btn btn-primary" type="submit"><?= icon('check', 16) ?>إرسال التقييم</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('ratingModal');
  if (!modal) return;
  document.querySelectorAll('[data-rate-request]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('rateRequestId').value = btn.getAttribute('data-rate-request');
      document.getElementById('ratingName').textContent = btn.getAttribute('data-rate-name') || '';
      modal.classList.add('open');
    });
  });
  modal.querySelectorAll('[data-close-modal]').forEach(function (b) {
    b.addEventListener('click', function () { modal.classList.remove('open'); });
  });
  modal.addEventListener('click', function (e) { if (e.target === modal) modal.classList.remove('open'); });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
