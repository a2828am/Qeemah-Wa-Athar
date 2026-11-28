<?php
/**
 * product_details.php — product page: gallery, specs, owner card, AI panel,
 * request modal (all four transaction types) and AI recommendations.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/components.php';

$slug = get_q('slug', '') ?? '';
$id   = get_int('id');
$product = null;

if ($slug !== '') {
    $product = ProductRepo::findBySlug($slug);
} elseif ($id) {
    $product = ProductRepo::find($id);
}
if (!$product || $product['status'] === 'removed') {
    http_response_code(404);
    $pageTitle = 'المنتج غير موجود | قيمة وأثر';
    require __DIR__ . '/includes/header.php';
    echo '<div class="container section">' . empty_state('المنتج غير موجود أو أُزيل', 'ربما حُذف الإعلان أو تغيّر رابطه.', 'package', 'عودة إلى السوق', url('index.php')) . '</div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$me      = Auth::user();
$isOwner = $me && (int)$me['id'] === (int)$product['owner_id'];
$isAdmin = Auth::isAdmin();
$images  = ProductRepo::images((int)$product['id']);
if (!$images) {
    $images = [['file_path' => null, 'id' => 0]];
}
$favIds  = $me ? FavoriteRepo::idsFor((int)$me['id']) : [];
$isFav   = in_array((int)$product['id'], $favIds, true);

if (!$isOwner) {
    ProductRepo::incrementViews((int)$product['id']);
}

$flashError = '';
$offerCandidates = [];

// Swap offer candidates: my available products.
if ($me && !$isOwner) {
    $offerCandidates = array_slice(array_values(array_filter(
        ProductRepo::search(['owner_id' => (int)$me['id'], 'status' => 'available'], 1, 30)[0],
        static fn ($p) => (int)$p['id'] !== (int)$product['id']
    )), 0, 30);
}

/* ── request submission ─────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request') {
    require_csrf();
    if (!$me) {
        flash('info', 'يرجى تسجيل الدخول لإرسال الطلب.');
        redirect('login.php?next=' . urlencode('product_details.php?slug=' . (string)$product['slug']));
    }
    $message   = (string)post('message', '');
    $phone     = (string)post('contact_phone', '');
    $offeredId = post_int('offered_product_id');
    $meetup    = (string)post('meetup_city', '');

    try {
        if ($product['transaction_type'] === 'swap' && !$offeredId) {
            throw new RuntimeException('اختر أحد منتجاتك لعرضه في المقايضة.');
        }
        $requestId = RequestFlowRepo::create((int)$product['id'], (int)$me['id'], [
            'message'            => $message !== '' ? $message : null,
            'contact_phone'      => $phone !== '' ? $phone : null,
            'offered_product_id' => $offeredId,
            'meetup_city'        => $meetup !== '' ? $meetup : (string)$product['city'],
        ]);
        flash('success', 'تم إرسال طلبك رقم #' . $requestId . '. أُشعر صاحب المنتج، وسيصلك ردّه هنا.');
        redirect('request.php?id=' . $requestId);
    } catch (Throwable $ex) {
        $flashError = $ex->getMessage();
    }
}

/*
 * There is deliberately NO "contact the owner" shortcut here.
 *
 * A conversation is opened by the platform at the moment the owner accepts a
 * request (RequestFlowRepo::accept → ChatRepo::openForRequest). Letting a
 * visitor open a thread straight from the product page meant anyone could
 * message any seller before a request existed — which bypasses the whole
 * request → accept → negotiate lifecycle. The button below now only sends a
 * request; the thread appears once that request is accepted.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'contact') {
    require_csrf();
    if (!$me) {
        redirect('login.php?next=' . urlencode('product_details.php?slug=' . (string)$product['slug']));
    }
    // Old bookmarks or a stale form: point at the correct path instead of opening a thread.
    flash('info', 'المحادثة تُفتح بعد قبول صاحب المنتج لطلبك — أرسل طلباً أولاً.');
    if ($myRequest && !empty($myRequest['conversation_id'])) {
        redirect('chat.php?c=' . (int)$myRequest['conversation_id']);
    }
    redirect('product_details.php?slug=' . urlencode((string)$product['slug']));
}

// Live deal with the viewer (if any) drives the progress strip on this page.
$myRequest = $me ? RequestRepo::activeForProduct((int)$product['id'], (int)$me['id']) : null;
$ownerRatings = RatingRepo::recentFor((int)$product['owner_id'], 4);

/* Rateable delivery for this product, for the current viewer.
   Any signed-in user may rate a completed deal — except whoever published the
   product. The request page is party-only, so this is where everybody else
   finds the button. */
$rateable = null;
if ($me && (int)$product['owner_id'] !== (int)$me['id']) {
    $rows = Repo::q(
        "SELECT * FROM user_requests
          WHERE product_id=? AND status='delivered'
            AND NOT EXISTS (SELECT 1 FROM ratings rt
                             WHERE rt.request_id=user_requests.id AND rt.rater_id=?)
          ORDER BY delivered_at DESC, id DESC LIMIT 1",
        [(int)$product['id'], (int)$me['id']]
    );
    $rateable = $rows[0] ?? null;
}
$rateTargetName = '';
if ($rateable) {
    $rateTargetId   = RatingRepo::counterpartyFor($rateable, (int)$me['id']);
    $rateTargetName = (string)Repo::scalar('SELECT full_name FROM users WHERE id=?', [$rateTargetId]);
}
$similar      = ProductRepo::search([
    'category_id' => (int)$product['category_id'],
    'exclude_id'  => (int)$product['id'],
], 1, 4)[0];

$pageTitle   = excerpt((string)$product['title'], 60) . ' | قيمة وأثر';
$pageDesc    = excerpt((string)$product['description'], 150);
$currentPage = 'market';
require __DIR__ . '/includes/header.php';
?>

<div class="container">
  <nav class="breadcrumb" aria-label="المسار">
    <a href="<?= e(url('index.php')) ?>"><?= icon('home', 14) ?>الرئيسية</a>
    <?= icon('chevron-left', 13) ?>
    <a href="<?= e(url('index.php?category=' . (int)$product['category_id'])) ?>#market"><?= e((string)$product['category_name']) ?></a>
    <?= icon('chevron-left', 13) ?>
    <span><?= e(excerpt((string)$product['title'], 40)) ?></span>
  </nav>

  <?php if ($flashError !== ''): ?>
    <div class="alert alert-error"><?= icon('alert-circle', 18) ?><div class="grow"><?= e($flashError) ?></div></div>
  <?php endif; ?>

  <?php if ($myRequest): ?>
    <section class="panel mb-16" style="margin-bottom:16px">
      <div class="flex justify-between items-center gap-8 flex-wrap mb-8">
        <b style="font-size:15px"><?= icon('route', 16) ?> طلبك على هذا المنتج #<?= (int)$myRequest['id'] ?></b>
        <?= req_status((string)$myRequest['status']) ?>
      </div>
      <?= request_progress($myRequest, true) ?>
      <div class="row-actions mt-16">
        <a class="btn btn-primary btn-sm" href="<?= e(url('request.php?id=' . (int)$myRequest['id'])) ?>"><?= icon('arrow-left', 14) ?>فتح صفحة الطلب</a>
        <?php if (!empty($myRequest['conversation_id'])): ?>
          <a class="btn btn-outline btn-sm" href="<?= e(url('chat.php?c=' . (int)$myRequest['conversation_id'])) ?>"><?= icon('message-circle', 14) ?>المحادثة</a>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <div class="detail-grid">
    <div>
      <div class="gallery-main">
        <img id="galleryMain" src="<?= e(product_image($images[0]['file_path'] ?? null)) ?>" alt="<?= e((string)$product['title']) ?>">
        <?= op_badge((string)$product['transaction_type']) ?>
      </div>
      <?php if (count($images) > 1): ?>
        <div class="gallery-thumbs">
          <?php foreach ($images as $i => $img): ?>
            <button type="button" class="<?= $i === 0 ? 'active' : '' ?>" data-full="<?= e(product_image($img['file_path'] ?? null)) ?>" aria-label="صورة <?= $i + 1 ?>">
              <img src="<?= e(product_image($img['file_path'] ?? null)) ?>" alt="">
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="panel mt-24" style="margin-top:24px">
        <h3><?= icon('file-text', 18) ?> الوصف</h3>
        <p style="font-size:14.5px;line-height:1.95;color:var(--ink);white-space:pre-line"><?= e((string)$product['description']) ?></p>
        <?php if ($product['transaction_type'] === 'swap' && !empty($product['swap_wanted'])): ?>
          <div class="exchange-note mt-16"><?= icon('repeat', 14) ?> <b>يبحث عن:</b> <?= e((string)$product['swap_wanted']) ?></div>
        <?php endif; ?>
        <?php if ($product['transaction_type'] === 'donate'): ?>
          <div class="trust-note">
            <?= icon('hand-heart', 16) ?>
            <span>
              <?php if (($product['donation_scope'] ?? 'general') === 'targeted' && !empty($product['association_name'])): ?>
                تبرّع موجّه لجمعية <b><?= e((string)$product['association_name']) ?></b> — لا يظهر إلا لها.
              <?php else: ?>
                تبرّع عام — يمكن لأي جمعية موثّقة استلامه وتوزيعه على المستفيدين.
              <?php endif; ?>
            </span>
          </div>
        <?php endif; ?>
      </div>

      <div class="panel mt-16" style="margin-top:16px" id="recPanel">
        <h3><?= icon('sparkles', 18) ?> منتجات قد تهمّك</h3>
        <p class="panel-sub">اختيارات مبنية على التصنيف والاهتمامات — تعمل حتى دون اتصال بالإنترنت.</p>
        <div class="rec-list" data-recommendations="<?= (int)$product['id'] ?>">
          <div class="ai-loading"><?= icon('loader', 15) ?> جارٍ التحميل…</div>
        </div>
      </div>
    </div>

    <aside>
      <div class="detail-info">
        <div class="flex items-center gap-8 flex-wrap mb-8">
          <?= op_badge((string)$product['transaction_type']) ?>
          <?= prod_status((string)$product['status']) ?>
          <span class="cat"><?= e((string)$product['category_name']) ?></span>
        </div>
        <h1><?= e((string)$product['title']) ?></h1>

        <?php if ($product['transaction_type'] === 'sell'): ?>
          <div class="price-big"><?= e(money($product['price'])) ?></div>
        <?php elseif ($product['transaction_type'] === 'swap'): ?>
          <div class="price-big" style="font-size:22px;color:var(--amber)"><?= icon('repeat', 20) ?> مقايضة <?= !empty($product['swap_wanted']) ? 'بـ: ' . e(excerpt((string)$product['swap_wanted'], 40)) : '' ?></div>
        <?php elseif ($product['transaction_type'] === 'donate'): ?>
          <div class="price-big" style="font-size:22px;color:var(--indigo)"><?= icon('hand-heart', 20) ?> تبرّع مجاني</div>
        <?php else: ?>
          <div class="price-big" style="font-size:22px;color:var(--teal)"><?= icon('gift', 20) ?> مجاناً</div>
        <?php endif; ?>

        <div class="meta mb-16">
          <span><?= icon('map-pin', 14) ?><?= e((string)$product['city']) ?></span>
          <span><?= icon('package', 14) ?><?= e(condition_label((string)$product['condition_type'])) ?></span>
          <span><?= icon('eye', 14) ?><?= (int)$product['views_count'] ?> مشاهدة</span>
          <span><?= icon('clock', 14) ?><?= e(time_ago((string)$product['created_at'])) ?></span>
        </div>

        <div class="detail-actions">
          <?php if ($isOwner || $isAdmin): ?>
            <a class="btn btn-primary" href="<?= e(url('add_product.php?edit=' . (int)$product['id'])) ?>"><?= icon('edit', 16) ?>تعديل المنتج</a>
            <a class="btn btn-outline" href="<?= e(url('my_requests.php?tab=incoming')) ?>"><?= icon('clipboard', 16) ?>الطلبات الواردة</a>
          <?php elseif (Auth::check()): ?>
            <button class="btn btn-primary" type="button" data-open-request="<?= (int)$product['id'] ?>" data-product-title="<?= e((string)$product['title']) ?>"
              <?= $product['status'] !== 'available' ? 'disabled' : '' ?>>
              <?= icon('clipboard', 16) ?><?= $product['transaction_type'] === 'donate' ? 'التقدّم للتبرّع' : ($product['transaction_type'] === 'swap' ? 'اقترح تبادلاً' : 'أرسل طلباً') ?>
            </button>
            <?php /* Sending a request is the only door. The thread opens on acceptance. */ ?>
            <p class="text-xs text-muted" style="flex:1 1 100%;margin-top:2px">
              <?= icon('lock', 12) ?> المحادثة تُفتح تلقائياً بعد قبول صاحب المنتج لطلبك.
            </p>
          <?php else: ?>
            <a class="btn btn-primary" href="<?= e(url('login.php?next=' . urlencode('product_details.php?slug=' . (string)$product['slug']))) ?>"><?= icon('log-in', 16) ?>سجّل دخولك للطلب</a>
          <?php endif; ?>
          <button type="button" class="btn btn-outline fav-btn-inline<?= $isFav ? ' active' : '' ?>" data-fav="<?= (int)$product['id'] ?>" aria-pressed="<?= $isFav ? 'true' : 'false' ?>"
                  style="flex:0 0 auto;<?= $isFav ? 'background:#fee2e2;color:#e11d48;border-color:#fecdd3' : '' ?>">
            <?= icon('heart', 16) ?><?= $isFav ? 'في المفضلة' : 'المفضلة' ?>
          </button>
        </div>

        <div class="spec-grid">
          <div class="spec"><span>الحالة</span><b><?= e(condition_label((string)$product['condition_type'])) ?></b></div>
          <div class="spec"><span>المدينة</span><b><?= e((string)$product['city']) ?></b></div>
          <div class="spec"><span>نوع المعاملة</span><b><?= e(transaction_meta()[$product['transaction_type']]['label']) ?></b></div>
          <div class="spec"><span>التصنيف</span><b><?= e((string)$product['category_name']) ?></b></div>
        </div>

        <div class="owner-card">
          <span class="avatar"><?= e(avatar_letter((string)$product['owner_name'])) ?></span>
          <div class="owner-meta">
            <b><?= e((string)$product['owner_name']) ?></b>
            <span><?= e((string)$product['owner_type'] === 'store' ? 'متجر موثّق' : 'عضو') ?> · <?= stars((float)$product['owner_rating']) ?></span>
          </div>
          <?php /* No direct message button: the thread opens when this owner
                   accepts the viewer's request, not before. */ ?>
        </div>

        <?php if ($rateable): ?>
          <div class="panel mt-16" style="margin-top:16px" id="rateDeal">
            <h3><?= icon('star', 18) ?> قيّم هذه العملية</h3>
            <p class="text-sm text-muted">تم تسليم هذا المنتج. تقييمك يبني ثقة المجتمع.</p>
            <?php /* Same markup the rating modal uses, so .star-input and the
                     data-ajax submitter work without new JS. */ ?>
            <form method="post" data-ajax="api/request_action.php" data-done="reload">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="rate">
              <input type="hidden" name="request_id" value="<?= (int)$rateable['id'] ?>">
              <div class="field" style="margin-bottom:10px">
                <div class="star-input">
                  <input type="hidden" name="score" value="5">
                  <?php for ($i = 0; $i < 5; $i++): ?>
                    <button type="button" class="on" aria-label="نجمة <?= $i + 1 ?>"><?= icon('star', 26) ?></button>
                  <?php endfor; ?>
                </div>
              </div>
              <div class="field" style="margin-bottom:10px">
                <label for="pdComment">ملاحظتك (اختياري)</label>
                <input type="text" id="pdComment" name="comment" maxlength="500" placeholder="التزام بالموعد، حالة المنتج، التعامل…">
              </div>
              <button class="btn btn-primary btn-block" type="submit"><?= icon('check', 16) ?>إرسال التقييم<?= $rateTargetName !== '' ? ' — ' . e(excerpt($rateTargetName, 26)) : '' ?></button>
            </form>
          </div>
        <?php endif; ?>

        <?php if ($ownerRatings): ?>
          <div class="panel mt-16" style="margin-top:16px">
            <h3><?= icon('star', 18) ?> آخر التقييمات</h3>
            <div class="list">
              <?php foreach ($ownerRatings as $rt): ?>
                <div class="list-row">
                  <span class="avatar avatar-sm"><?= e(avatar_letter((string)$rt['rater_name'])) ?></span>
                  <span class="grow">
                    <b><?= e((string)$rt['rater_name']) ?> <?= stars((float)$rt['score']) ?></b>
                    <?php if (!empty($rt['comment'])): ?><span><?= e(excerpt((string)$rt['comment'], 90)) ?></span><?php endif; ?>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="trust-note">
          <?= icon('shield', 16) ?>
          <span>لا تدفع أي مبلغ قبل معاينة المنتج. المنصة لا تتوسط في المدفوعات — تتم المعاملة بينكما مباشرة بأمان.</span>
        </div>

        <form method="post" class="mt-16" data-ajax="api/report.php" data-confirm="سيتم إرسال بلاغ إلى فريق الإدارة. متابعة؟" style="margin-top:16px">
          <?= csrf_field() ?>
          <input type="hidden" name="entity_type" value="product">
          <input type="hidden" name="entity_id" value="<?= (int)$product['id'] ?>">
          <input type="hidden" name="reason" value="inappropriate">
          <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--danger)"><?= icon('flag', 15) ?>الإبلاغ عن هذا الإعلان</button>
        </form>
      </div>
    </aside>
  </div>

  <?php if ($similar): ?>
    <section class="section" style="padding-top:0">
      <div class="section-head">
        <div>
          <span class="eyebrow">اقتراحات</span>
          <h2><?= icon('package', 24) ?> منتجات مشابهة</h2>
        </div>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('index.php?category=' . (int)$product['category_id'])) ?>#market">الكل<?= icon('arrow-left', 15) ?></a>
      </div>
      <div class="product-grid">
        <?php foreach ($similar as $p): ?>
          <?= product_card($p, $favIds) ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>

<div class="modal-backdrop" id="requestModal" role="dialog" aria-modal="true" aria-labelledby="reqTitle">
  <div class="modal">
    <button type="button" class="modal-close" data-close-modal aria-label="إغلاق"><?= icon('x', 17) ?></button>
    <h3 id="reqTitle"><?= icon('clipboard', 19) ?>إرسال طلب</h3>
    <p class="modal-sub">المنتج: <b id="reqProductTitle"><?= e((string)$product['title']) ?></b></p>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="request">
      <input type="hidden" name="product_id" id="reqProductId" value="<?= (int)$product['id'] ?>">

      <?php if ($product['transaction_type'] === 'swap'): ?>
        <div class="field mb-16">
          <label for="offered_product_id">المنتج الذي تعرضه للمقايضة <i>*</i></label>
          <select name="offered_product_id" id="offered_product_id" required>
            <option value="">اختر من منتجاتك المتاحة</option>
            <?php foreach ($offerCandidates as $op): ?>
              <option value="<?= (int)$op['id'] ?>"><?= e(excerpt((string)$op['title'], 50)) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$offerCandidates): ?>
            <span class="hint">لا تملك منتجات متاحة. <a href="<?= e(url('add_product.php')) ?>" style="color:var(--green-700);font-weight:700">أضف منتجاً أولاً</a>.</span>
          <?php endif; ?>
        </div>
        <div class="field mb-16">
          <label for="meetup_city">مدينة التسليم</label>
          <input type="text" name="meetup_city" id="meetup_city" value="<?= e((string)$product['city']) ?>">
        </div>
      <?php endif; ?>

      <div class="field mb-16">
        <label for="message">رسالتك لصاحب المنتج</label>
        <textarea name="message" id="message" rows="4" placeholder="اشرح رغبتك وطريقة الاستلام المناسبة…"></textarea>
      </div>

      <div class="field mb-16">
        <label for="contact_phone">رقم للتواصل (اختياري)</label>
        <input type="tel" name="contact_phone" id="contact_phone" value="<?= e((string)($me['phone'] ?? '')) ?>" dir="ltr">
      </div>

      <div class="alert alert-info" style="font-size:12.5px">
        <?= icon('info', 16) ?>
        <div class="grow">عند قبول طلبك سيُحجز المنتج لك تلقائياً، وتُرفض الطلبات الأخرى على نفس المنتج.</div>
      </div>

      <div class="form-actions">
        <button type="button" class="btn btn-ghost" data-close-modal>إلغاء</button>
        <button class="btn btn-primary" type="submit"><?= icon('check', 16) ?>إرسال الطلب</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
