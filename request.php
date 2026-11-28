<?php
/**
 * request.php — the deal room for a single request: progress bar, agreement
 * and delivery confirmations, dispute reporting and the private thread.
 * Access is enforced server-side; anyone else gets a plain 404.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/components.php';

Auth::requireLogin('request.php');
$me      = Auth::user();
$uid     = (int)$me['id'];
$isAdmin = Auth::isAdmin();
$id      = get_int('id', 0) ?? 0;

$request = $id > 0 ? RequestRepo::detail($id, $uid, $isAdmin) : null;
if (!$request) {
    http_response_code(404);
    $pageTitle = 'الطلب غير موجود | قيمة وأثر';
    require __DIR__ . '/includes/header.php';
    echo '<div class="container section">'
        . empty_state('الطلب غير موجود أو لا تملك صلاحية الوصول إليه', 'تحقّق من الرابط أو ارجع إلى قائمة طلباتك.', 'lock', 'طلباتي', url('my_requests.php'))
        . '</div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$roles   = $request['roles'];
$isOwner = in_array('owner', $roles, true);
$isReq   = in_array('requester', $roles, true);

/* Who may act on this deal — and it must be the SAME list the confirmation
   steps enforce, or the page shows buttons that fail on submit. In a targeted
   donation the product owner is only the publisher; the association's
   representative replaces them, so owner alone must not count as a party. */
$parties = $request['parties'] ?? RequestFlowRepo::expectedParties($request);
$isParty = in_array($uid, array_map('intval', $parties), true);
$canRate = $isParty;   // rating is limited to the two parties of a delivered deal
$isPublisherOnly = $isOwner && !$isParty;
$status  = (string)$request['status'];
$convId  = (int)($request['conversation_id'] ?? 0);
$isDonation = (string)$request['request_type'] === 'donate';

/* A general donation is visible to every verified association; the first
   representative to claim it binds it to that association. */
$claimableAssociations = [];
if ($isDonation && empty($request['association_id']) && $status === 'awaiting_owner' && $uid) {
    $claimableAssociations = array_values(array_filter(
        AssociationRepo::actingFor($uid),
        static fn ($a) => (int)($a['can_donations'] ?? 0) === 1 || ($a['member_role'] ?? '') === 'representative'
    ));
}

/* ── actions ─────────────────────────────────────────────────────────────── */
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)post('action', '');
    try {
        switch ($action) {
            case 'claim':
                $claimAssoc = (int)post_int('association_id', 0);
                RequestFlowRepo::claimAsAssociation($id, $claimAssoc, $uid);
                flash('success', 'تم ربط التبرّع بجمعيتك — أكمل الاتفاق مع مقدّم التبرّع.');
                redirect('request.php?id=' . $id);
            case 'accept':
                RequestFlowRepo::accept($id, $uid);
                flash('success', 'تم قبول الطلب وفُتحت محادثة مع مقدّم الطلب.');
                redirect('request.php?id=' . $id);
            case 'reject':
                RequestFlowRepo::reject($id, $uid, post('reason'));
                flash('success', 'تم رفض الطلب وإبلاغ مقدّمه.');
                redirect('request.php?id=' . $id);
            case 'cancel':
                RequestFlowRepo::cancel($id, $uid, post('reason'));
                flash('success', 'تم إلغاء الطلب.');
                redirect('request.php?id=' . $id);
            case 'agree':
                $res = RequestFlowRepo::confirmAgreement($id, $uid, [
                    'summary' => (string)post('summary', ''),
                    'place'   => (string)post('place', ''),
                    'when'    => (string)post('when', ''),
                ]);
                flash('success', $res['all']
                    ? 'تم تأكيد الاتفاق من الطرفين — يمكنكم الآن بدء التسليم.'
                    : 'تم تسجيل تأكيدك. بانتظار تأكيد الطرف الآخر.');
                redirect('request.php?id=' . $id);
            case 'start_delivery':
                RequestFlowRepo::startDelivery($id, $uid);
                flash('success', 'تم تسجيل بدء التسليم — ينتظر النظام تأكيد الطرفين.');
                redirect('request.php?id=' . $id);
            case 'confirm_delivery':
                $res = RequestFlowRepo::confirmDelivery($id, $uid, post('note'));
                flash('success', $res['all']
                    ? 'تم تأكيد الاستلام من الطرفين — اكتملت العملية.'
                    : 'تم تسجيل تأكيدك. بانتظار تأكيد الطرف الآخر لإتمام العملية.');
                redirect('request.php?id=' . $id);
            case 'dispute':
                RequestFlowRepo::dispute($id, $uid, (string)post('reason', ''));
                flash('success', 'تم تسجيل الخلاف وإبلاغ الإدارة.');
                redirect('request.php?id=' . $id);
        }
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    }
}

// reload after a state change so the page never shows stale permissions
$request = RequestRepo::detail($id, $uid, $isAdmin) ?? $request;
$status  = (string)$request['status'];
$convId  = (int)($request['conversation_id'] ?? 0);
$isDonation = (string)$request['request_type'] === 'donate';

/* A general donation is visible to every verified association; the first
   representative to claim it binds it to that association. */
$claimableAssociations = [];
if ($isDonation && empty($request['association_id']) && $status === 'awaiting_owner' && $uid) {
    $claimableAssociations = array_values(array_filter(
        AssociationRepo::actingFor($uid),
        static fn ($a) => (int)($a['can_donations'] ?? 0) === 1 || ($a['member_role'] ?? '') === 'representative'
    ));
}
$steps   = request_steps();
$myAgreement = null;
foreach (($request['agreements'] ?? []) as $a) {
    if ((int)$a['user_id'] === $uid) {
        $myAgreement = $a;
    }
}
$agreementDone = array_filter(($request['agreements'] ?? []), static fn ($a) => !empty($a['confirmed_at']));
$deliveryDone  = array_filter(($request['deliveries'] ?? []), static fn ($a) => !empty($a['confirmed_at']));

$pageTitle   = 'الطلب #' . $id . ' | قيمة وأثر';
$currentPage = 'requests';
require __DIR__ . '/includes/header.php';
?>

<div class="container section" style="padding-top:22px">
  <nav class="breadcrumb" aria-label="المسار">
    <a href="<?= e(url('my_requests.php')) ?>"><?= icon('clipboard', 14) ?>طلباتي</a>
    <?= icon('chevron-left', 13) ?>
    <span>الطلب #<?= $id ?></span>
  </nav>

  <?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= icon('alert-circle', 18) ?><div class="grow"><?= e($error) ?></div></div>
  <?php endif; ?>

  <?php if ($isPublisherOnly): ?>
    <?php /* Targeted donation: the item was published by this user, but the deal
             is between the donor and the association, so no action buttons. */ ?>
    <div class="alert alert-info"><?= icon('info', 18) ?><div class="grow">
      <b>أنت ناشر هذا المنتج.</b>
      <div class="text-sm">التبرّع الموجّه جرى بين المتبرّع والجمعية المستفيدة، لذلك لا تُطلب منك تأكيدات ولا تقييم. تابع المسار من هنا للاطلاع فقط.</div>
    </div></div>
  <?php endif; ?>

  <div class="deal-grid">
    <div>
      <!-- ── progress ──────────────────────────────────────────────────── -->
      <section class="panel">
        <div class="flex justify-between items-center gap-8 flex-wrap mb-16">
          <h2 style="font-size:19px;font-weight:900"><?= icon('route', 20) ?> مسار العملية</h2>
          <?= req_status($status) ?>
        </div>
        <?= request_progress($request) ?>

        <?php if (!empty($request['agreement_summary'])): ?>
          <div class="agreement-box mt-16">
            <b><?= icon('handshake', 16) ?> ملخص الاتفاق</b>
            <p><?= e((string)$request['agreement_summary']) ?></p>
            <div class="kv">
              <?php if (!empty($request['agreement_place'])): ?>
                <div class="kv-row"><span>مكان التسليم</span><b><?= e((string)$request['agreement_place']) ?></b></div>
              <?php endif; ?>
              <?php if (!empty($request['agreement_when'])): ?>
                <div class="kv-row"><span>الموعد</span><b><?= e((string)$request['agreement_when']) ?></b></div>
              <?php endif; ?>
              <div class="kv-row"><span>تم الاتفاق</span><b><?= !empty($request['agreed_at']) ? e(date('Y-m-d H:i', strtotime((string)$request['agreed_at']))) : '—' ?></b></div>
            </div>
          </div>
        <?php endif; ?>
      </section>

      <!-- ── actions by stage ─────────────────────────────────────────── -->
      <section class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('zap', 18) ?> الخطوة التالية</h3>

        <?php if ($status === 'awaiting_owner' && $isOwner): ?>
          <p class="text-sm text-muted">
            <?php if ($isDonation): ?>
              راجع تفاصيل التبرّع. القبول يعني استلام الجمعية له، ويفتح محادثة مع مقدّم التبرّع للاتفاق على التسليم.
            <?php else: ?>
              راجع تفاصيل الطلب. القبول يفتح محادثة خاصة بينكما ويفتح الباب للاتفاق.
            <?php endif; ?>
          </p>
          <div class="row-actions mt-16">
            <form method="post" data-confirm="<?= $isDonation ? 'قبول التبرّع باسم الجمعية وفتح محادثة مع مقدّمه؟' : 'قبول الطلب وفتح محادثة مع مقدّمه؟' ?>">
              <?= csrf_field() ?><input type="hidden" name="action" value="accept">
              <button class="btn btn-primary" type="submit"><?= icon('check-circle', 16) ?><?= $isDonation ? 'قبول التبرّع' : 'قبول الطلب' ?></button>
            </form>
            <button class="btn btn-outline" type="button" data-open-modal="rejectModal"><?= icon('ban', 16) ?>رفض الطلب</button>
          </div>

        <?php elseif ($status === 'awaiting_owner' && $claimableAssociations !== []): ?>
          <p class="text-sm text-muted">
            <?= icon('handshake', 14) ?>
            هذا تبرّع عام متاح للجمعيات الموثّقة. اربط جمعيتك به ليصبح التبرّع باسمها، ثم أكمل الاتفاق مع مقدّمه.
          </p>
          <form method="post" class="mt-16" data-confirm="ربط هذا التبرّع بجمعيتك؟">
            <?= csrf_field() ?><input type="hidden" name="action" value="claim">
            <?php if (count($claimableAssociations) === 1): ?>
              <input type="hidden" name="association_id" value="<?= (int)$claimableAssociations[0]['id'] ?>">
              <button class="btn btn-primary" type="submit"><?= icon('handshake', 16) ?>ربط التبرّع بـ «<?= e((string)$claimableAssociations[0]['name']) ?>»</button>
            <?php else: ?>
              <div class="field"><label for="association_id">الجمعية</label>
                <select id="association_id" name="association_id" required>
                  <?php foreach ($claimableAssociations as $a): ?>
                    <option value="<?= (int)$a['id'] ?>"><?= e((string)$a['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="btn btn-primary" type="submit"><?= icon('handshake', 16) ?>ربط التبرّع</button>
            <?php endif; ?>
          </form>

        <?php elseif ($status === 'awaiting_owner'): ?>
          <p class="text-sm text-muted"><?= icon('clock', 14) ?> طلبك بانتظار رد صاحب المنتج. ستصلك رسالة داخل المنصة عند الرد.</p>
          <form method="post" class="mt-16" data-confirm="إلغاء طلبك؟">
            <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
            <button class="btn btn-outline" type="submit"><?= icon('x', 16) ?>إلغاء الطلب</button>
          </form>

        <?php elseif ($status === 'accepted' || $status === 'negotiating'): ?>
          <p class="text-sm text-muted">
            <?= icon('message-circle', 14) ?>
            اتفقا في المحادثة على السعر وطريقة التسليم، ثم أكّد كل طرف الاتفاق. لا تُكمَل العملية إلا بتأكيد الطرفين.
          </p>
          <?php if ($myAgreement && !empty($myAgreement['confirmed_at'])): ?>
            <div class="alert alert-success mt-16"><?= icon('check-circle', 18) ?><div class="grow">أكّدت الاتفاق في <?= e(date('Y-m-d H:i', strtotime((string)$myAgreement['confirmed_at']))) ?>.</div></div>
            <p class="text-sm text-muted mt-8">بانتظار تأكيد الطرف الآخر (<?= count($agreementDone) ?> من <?= count($request['parties']) ?>).</p>
          <?php elseif ($isParty): ?>
            <form method="post" class="mt-16">
              <?= csrf_field() ?><input type="hidden" name="action" value="agree">
              <div class="field"><label for="summary">ملخص الاتفاق <i>*</i></label>
                <input type="text" id="summary" name="summary" required maxlength="500"
                       value="<?= e((string)($request['agreement_summary'] ?? '')) ?>"
                       placeholder="مثال: شراء الكنبة بـ 115,000 ر.ي نقداً عند الاستلام">
              </div>
              <div class="field-row">
                <div class="field"><label for="place">مكان التسليم</label>
                  <input type="text" id="place" name="place" maxlength="120" value="<?= e((string)($request['agreement_place'] ?? '')) ?>" placeholder="شارع حدة — أمام المسجد">
                </div>
                <div class="field"><label for="when">الموعد</label>
                  <input type="text" id="when" name="when" maxlength="120" value="<?= e((string)($request['agreement_when'] ?? '')) ?>" placeholder="غداً بعد العصر">
                </div>
              </div>
              <button class="btn btn-primary" type="submit"><?= icon('handshake', 16) ?>أؤكد الاتفاق</button>
            </form>
          <?php endif; ?>

        <?php elseif ($status === 'agreed'): ?>
          <p class="text-sm text-muted">تم الاتفاق. عندما يصبح المنتج جاهزاً للتسليم سجّل بدء التسليم لتصل الخطوة التالية للطرفين.</p>
          <?php if ($isParty): ?>
            <form method="post" class="mt-16" data-confirm="تسجيل بدء التسليم؟">
              <?= csrf_field() ?><input type="hidden" name="action" value="start_delivery">
              <button class="btn btn-primary" type="submit"><?= icon('truck', 16) ?>بدء التسليم</button>
            </form>
          <?php endif; ?>

        <?php elseif ($status === 'delivery_pending'): ?>
          <?php
            $myDelivery = null;
            foreach (($request['deliveries'] ?? []) as $d) {
                if ((int)$d['user_id'] === $uid) {
                    $myDelivery = $d;
                }
            }
          ?>
          <p class="text-sm text-muted">
            بعد استلام/تسليم المنتج فعلياً، أكّد ذلك من جهتك. تُعلن العملية «تم التسليم» فقط بعد تأكيد الطرفين.
            (<?= count($deliveryDone) ?> من <?= count($request['parties']) ?> أكّدوا)
          </p>
          <?php if ($myDelivery && !empty($myDelivery['confirmed_at'])): ?>
            <div class="alert alert-info mt-16"><?= icon('check', 18) ?><div class="grow">أكّدت التسليم في <?= e(date('Y-m-d H:i', strtotime((string)$myDelivery['confirmed_at']))) ?>. بانتظار الطرف الآخر.</div></div>
          <?php elseif ($isParty): ?>
            <form method="post" class="mt-16" data-confirm="تأكيد استلام/تسليم المنتج؟">
              <?= csrf_field() ?><input type="hidden" name="action" value="confirm_delivery">
              <div class="field"><label for="note">ملاحظة (اختياري)</label>
                <input type="text" id="note" name="note" maxlength="255" placeholder="تم التسليم بحالة جيدة">
              </div>
              <button class="btn btn-primary" type="submit"><?= icon('check-circle', 16) ?>أؤكد التسليم/الاستلام</button>
            </form>
          <?php endif; ?>

        <?php elseif ($status === 'delivered'): ?>
          <?php
            $rated = RatingRepo::hasRated((int)$request['id'], $uid);
            $rateeName = '';
            if ($isParty) {
                $others = array_values(array_diff(array_map('intval', $parties), [$uid]));
                $rateeName = (string)Repo::scalar('SELECT full_name FROM users WHERE id=?', [(int)($others[0] ?? 0)]);
            }
          ?>
          <div class="alert alert-success"><?= icon('check-circle', 20) ?><div class="grow">
            <b>اكتملت العملية بنجاح.</b>
            <div class="text-sm">تم تأكيد التسليم من الطرفين في <?= e(date('Y-m-d H:i', strtotime((string)($request['delivered_at'] ?? $request['completed_at'])))) ?>.
              <?php if ($canRate): ?>
                <?= $rated ? 'سجّلت تقييمك لهذه العملية.' : 'يمكنك الآن تقييم الطرف الآخر.' ?>
              <?php else: ?>
                التقييم متاح لطرفي العملية فقط.
              <?php endif; ?>
            </div>
          </div></div>
          <div class="row-actions mt-16">
            <?php if ($canRate && !$rated): ?>
              <a class="btn btn-outline" href="<?= e(url('my_requests.php?tab=' . ($isReq ? 'outgoing' : 'incoming'))) ?>#req-<?= (int)$request['id'] ?>"><?= icon('star', 16) ?>تقييم <?= e(excerpt($rateeName, 24) !== '' ? excerpt($rateeName, 24) : 'التجربة') ?></a>
            <?php elseif ($canRate): ?>
              <span class="badge badge-soft"><?= icon('check', 12) ?>قيّمت هذه العملية</span>
            <?php endif; ?>
            <?php if ($convId): ?><a class="btn btn-ghost" href="<?= e(url('chat.php?c=' . $convId)) ?>"><?= icon('message-circle', 16) ?>أرشيف المحادثة</a><?php endif; ?>
          </div>

        <?php elseif ($status === 'rejected'): ?>
          <div class="alert alert-error"><?= icon('ban', 20) ?><div class="grow">
            <b>تم رفض الطلب.</b>
            <?php if (!empty($request['reject_reason'])): ?><div class="text-sm">السبب: <?= e((string)$request['reject_reason']) ?></div><?php endif; ?>
          </div></div>

        <?php elseif ($status === 'cancelled'): ?>
          <div class="alert alert-warning"><?= icon('x', 20) ?><div class="grow">
            <b>تم إلغاء الطلب.</b>
            <?php if (!empty($request['cancelled_reason'])): ?><div class="text-sm">السبب: <?= e((string)$request['cancelled_reason']) ?></div><?php endif; ?>
          </div></div>

        <?php elseif ($status === 'disputed'): ?>
          <div class="alert alert-error"><?= icon('alert-triangle', 20) ?><div class="grow">
            <b>يوجد خلاف — أوقف النظام الإكمال التلقائي.</b>
            <div class="text-sm"><?= e((string)$request['dispute_reason']) ?></div>
            <div class="text-xs text-muted" style="margin-top:6px">راجعت الإدارة البلاغ وستتواصل معكما. يمكنكما متابعة التواصل في المحادثة.</div>
          </div></div>
        <?php endif; ?>

        <?php if ($convId && in_array($status, ['accepted', 'negotiating', 'agreed', 'delivery_pending', 'disputed'], true)): ?>
          <a class="btn btn-outline btn-block mt-16" style="margin-top:16px" href="<?= e(url('chat.php?c=' . $convId)) ?>">
            <?= icon('message-circle', 16) ?>فتح المحادثة والاتفاق
            <?php if ((int)($request['unread_messages'] ?? 0) > 0): ?>
              <span class="badge" style="margin-inline-start:6px"><?= (int)$request['unread_messages'] ?> جديدة</span>
            <?php endif; ?>
          </a>
        <?php endif; ?>
      </section>

      <!-- ── confirmations ───────────────────────────────────────────── -->
      <?php if (in_array($status, ['accepted', 'negotiating', 'agreed', 'delivery_pending', 'delivered'], true)): ?>
        <div class="two-col mt-16" style="margin-top:16px">
          <section class="panel">
            <h3><?= icon('handshake', 18) ?> تأكيد الاتفاق</h3>
            <?= request_confirmations($request, 'agreement') ?>
          </section>
          <section class="panel">
            <h3><?= icon('truck', 18) ?> تأكيد التسليم</h3>
            <?= request_confirmations($request, 'delivery') ?>
          </section>
        </div>
      <?php endif; ?>

      <!-- ── history ─────────────────────────────────────────────────── -->
      <section class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('history', 18) ?> سجل تغيّر الحالة</h3>
        <div class="timeline">
          <?php foreach (array_reverse($request['history']) as $h): ?>
            <div class="timeline-item">
              <span class="timeline-dot"><?= icon(request_status_meta()[$h['to_status']]['icon'] ?? 'clock', 12) ?></span>
              <span class="grow">
                <b><?= e(request_status_meta()[$h['to_status']]['label'] ?? $h['to_status']) ?></b>
                <?php if (!empty($h['reason'])): ?><span> — <?= e((string)$h['reason']) ?></span><?php endif; ?>
                <small class="text-xs text-muted" style="display:block">
                  <?= e((string)($h['actor_name'] ?? 'النظام')) ?> · <?= e(date('Y-m-d H:i', strtotime((string)$h['created_at']))) ?>
                </small>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="text-xs text-muted mt-16"><?= icon('lock', 12) ?> هذا السجل يُكتب تلقائياً ولا يمكن تعديله أو حذفه — هو المرجع الرسمي لما جرى على الطلب.</p>
      </section>
    </div>

    <!-- ── side: product + parties + dispute ───────────────────────── -->
    <aside>
      <div class="panel">
        <div class="deal-product">
          <img src="<?= e(product_image($request['primary_image'] ?? null)) ?>" alt="" width="72" height="56" loading="lazy">
          <div class="deal-product-body">
            <a class="deal-product-title" href="<?= e(url('product_details.php?slug=' . urlencode((string)$request['product_slug']))) ?>"><?= e(excerpt((string)$request['product_title'], 60)) ?></a>
            <span class="deal-product-sub"><?= e(transaction_meta()[$request['transaction_type']]['label'] ?? '') ?> · <?= e((string)$request['product_city']) ?></span>
          </div>
        </div>
        <div class="kv mt-16">
          <div class="kv-row"><span>رقم الطلب</span><b>#<?= $id ?></b></div>
          <div class="kv-row"><span>مقدّم الطلب</span><b><?= e((string)$request['requester_name']) ?></b></div>
          <div class="kv-row"><span>صاحب المنتج</span><b><?= e((string)$request['owner_name']) ?></b></div>
          <?php if ($request['offered_title']): ?>
            <div class="kv-row"><span>المعروض للمقايضة</span><b><?= e(excerpt((string)$request['offered_title'], 30)) ?></b></div>
          <?php endif; ?>
          <?php if ($request['request_type'] === 'sell' && $request['product_price']): ?>
            <div class="kv-row"><span>السعر المعلن</span><b><?= e(money($request['product_price'])) ?></b></div>
          <?php endif; ?>
          <div class="kv-row"><span>أُنشئ</span><b><?= e(date('Y-m-d H:i', strtotime((string)$request['created_at']))) ?></b></div>
        </div>
        <?php if (!empty($request['message'])): ?>
          <div class="req-msg mt-16"><?= e((string)$request['message']) ?></div>
        <?php endif; ?>
        <?php if (!empty($request['contact_phone']) && $isParty): ?>
          <p class="text-xs text-muted mt-8"><?= icon('phone', 12) ?> هاتف مقدّم الطلب: <span dir="ltr"><?= e((string)$request['contact_phone']) ?></span> — للأطراف فقط</p>
        <?php endif; ?>
      </div>

      <?php if ($isParty && !in_array($status, ['delivered', 'rejected', 'cancelled', 'disputed'], true)): ?>
        <div class="panel mt-16" style="margin-top:16px">
          <h3><?= icon('alert-triangle', 18) ?> الإبلاغ عن مشكلة</h3>
          <p class="text-sm text-muted">إن تعذّر إتمام الاتفاق أو التسليم، أوقف الإكمال وأبلغ الإدارة.</p>
          <form method="post" class="mt-8" data-confirm="فتح بلاغ خلاف؟ سيوقف النظام الإكمال التلقائي.">
            <?= csrf_field() ?><input type="hidden" name="action" value="dispute">
            <div class="field"><label for="reason">وصف المشكلة <i>*</i></label>
              <textarea id="reason" name="reason" rows="3" required minlength="5" maxlength="255" placeholder="مثال: لم يحضر الطرف الآخر في الموعد المتفق عليه"></textarea>
            </div>
            <button class="btn btn-outline btn-block" type="submit"><?= icon('flag', 15) ?>إرسال بلاغ</button>
          </form>
        </div>
      <?php endif; ?>

      <?php if ($isParty && in_array($status, ['accepted', 'negotiating', 'agreed', 'delivery_pending', 'disputed'], true)): ?>
        <div class="panel mt-16" style="margin-top:16px">
          <h3><?= icon('x', 18) ?> إلغاء الطلب</h3>
          <form method="post" data-confirm="إلغاء الطلب؟ لا يمكن التراجع.">
            <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
            <div class="field"><label for="cancel_reason">سبب الإلغاء (اختياري)</label>
              <input type="text" id="cancel_reason" name="reason" maxlength="255">
            </div>
            <button class="btn btn-ghost btn-block" type="submit">إلغاء الطلب</button>
          </form>
        </div>
      <?php endif; ?>
    </aside>
  </div>
</div>

<!-- reject modal -->
<div class="modal" id="rejectModal" hidden>
  <div class="modal-card">
    <div class="modal-head">
      <h3><?= icon('ban', 18) ?> رفض الطلب</h3>
      <button class="icon-btn" type="button" data-close-modal><?= icon('x', 16) ?></button>
    </div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="reject">
      <p class="text-sm text-muted">سيُغلق الطلب دون فتح محادثة، ويُبلَّغ مقدّم الطلب بالسبب إن كتبته.</p>
      <div class="field"><label for="reject_reason">سبب الرفض (اختياري)</label>
        <input type="text" id="reject_reason" name="reason" maxlength="255" placeholder="مثال: المنتج محجوز حالياً">
      </div>
      <div class="form-actions">
        <button class="btn btn-danger" type="submit">تأكيد الرفض</button>
        <button class="btn btn-ghost" type="button" data-close-modal>تراجع</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
