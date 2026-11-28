<?php
/**
 * chat.php — conversations list + live message thread.
 *   ?c=ID          open an existing conversation
 *   ?with=USER_ID  open (or create) the conversation with that user
 *   &product=ID    tie the conversation to a product
 * Polling uses api/fetch_messages.php every chat_poll_interval_ms.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/components.php';

Auth::requireLogin('chat.php');
$me  = Auth::user();
$uid = (int)$me['id'];

$activeId = get_int('c', 0) ?? 0;
$audit    = (get_q('audit', '') ?? '') === '1' && Auth::isAdmin();
$withId   = get_int('with', 0) ?? 0;
$prodId   = get_int('product', 0) ?? 0;

// A conversation is never created from a bare user id: it opens when the
// product owner accepts a request. Old "?with=" links are routed to the
// matching request instead of silently creating a thread.
if (!$activeId && $withId) {
    $link = Repo::one(
        "SELECT id FROM user_requests
          WHERE product_id=? AND ((requester_id=? AND owner_id=?) OR (requester_id=? AND owner_id=?))
          ORDER BY id DESC LIMIT 1",
        [$prodId, $uid, $withId, $withId, $uid]
    );
    if ($link) {
        flash('info', 'المحادثة تُفتح من صفحة الطلب بعد قبوله.');
        redirect('request.php?id=' . (int)$link['id']);
    }
    flash('error', 'لا توجد محادثة بينكما. أرسل طلباً على المنتج، وتُفتح المحادثة بعد قبوله.');
    redirect('chat.php');
}

$conversations = ChatRepo::forUser($uid);
$active = null;
$messages = [];
$other = null;
$productTitle = '';
$productImg = null;

if ($activeId) {
    $active = ChatRepo::find($activeId);
    // Only the two parties may read a thread; an unknown id behaves exactly
    // like someone else's id so conversations cannot be enumerated.
    if (!$active || !ChatRepo::isParticipant($activeId, $uid)) {
        // An admin reads a thread only through the audited entry point and only
        // for conversations that belong to a request (never private DMs).
        if ($audit && $active && !empty($active['request_id'])) {
            AuditRepo::log($uid, 'chat.audited', 'conversation', $activeId, null, ['request_id' => (int)$active['request_id']]);
        } else {
            flash('error', 'المحادثة غير موجودة أو لا تملك صلاحية الوصول إليها.');
            redirect('chat.php');
        }
    }
    $otherId = (int)$active['user_a_id'] === $uid ? (int)$active['user_b_id'] : (int)$active['user_a_id'];
    $other   = UserRepo::find($otherId);
    $messages = ChatRepo::messages($activeId, 0, 200);
    ChatRepo::markRead($activeId, $uid);
    if (!empty($active['product_id'])) {
        $prod = ProductRepo::find((int)$active['product_id']);
        if ($prod) {
            $productTitle = (string)$prod['title'];
            $productImg   = product_image($prod['primary_image'] ?? null);
        }
    }
}

/* The deal behind this thread. Without it the progress bar in the chat never
   rendered: the template below tested $dealRequest but nothing ever assigned
   it. A conversation is tied to a request, and the request carries the six-step
   lifecycle plus both parties' confirmations. */
$dealRequest = null;
if ($active && !empty($active['request_id'])) {
    $dealRequest = RequestRepo::detail((int)$active['request_id'], $uid);
}

$lastId = $messages ? (int)end($messages)['id'] : 0;
$pollMs = (int)SettingsRepo::get('chat_poll_interval_ms', 4000);
$lastDay = $messages ? substr((string)end($messages)['created_at'], 0, 10) : '';

$pageTitle   = 'المحادثات | قيمة وأثر';
$currentPage = 'chat';
$pageHead    = [
    'title'    => icon('message-circle', 28) . ' المحادثات',
    'subtitle' => 'تواصل بأمان مع الطرف الآخر لتنسيق التسليم والتفاصيل.',
];
require __DIR__ . '/includes/header.php';
?>

<div class="container">
  <?php /* has-active lets the small-screen layout put the open thread first:
           with a conversation selected, the list of other threads belongs below
           it, not above the deal buttons. */ ?>
  <div class="chat-shell<?= $activeId ? ' has-active' : '' ?>" id="chatApp"
       data-conversation="<?= (int)$activeId ?>" data-me="<?= $uid ?>"
       data-last-id="<?= $lastId ?>" data-poll="<?= $pollMs ?>">

    <aside class="chat-list">
      <div class="chat-list-head">
        <b><?= icon('inbox', 16) ?> كل المحادثات (<?= count($conversations) ?>)</b>
        <a class="icon-btn" href="<?= e(url('index.php')) ?>#market"><?= icon('plus', 14) ?>جديدة</a>
      </div>
      <div class="chat-convs">
        <?php if (!$conversations): ?>
          <div class="chat-empty">
            <div>
              <div class="empty-ic"><?= icon('message-circle', 30) ?></div>
              <p>لا توجد محادثات بعد.<br>ابدأ محادثة من صفحة أي منتج.</p>
              <a class="btn btn-primary btn-sm mt-8" href="<?= e(url('index.php')) ?>#market">تصفّح السوق</a>
            </div>
          </div>
        <?php else: ?>
          <?php foreach ($conversations as $c): ?>
            <?= conversation_row($c, (int)$activeId) ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </aside>

    <section class="chat-main">
      <?php if (!$active): ?>
        <div class="chat-empty">
          <div>
            <div class="empty-ic"><?= icon('message-circle', 32) ?></div>
            <h3 style="color:var(--ink);font-size:18px;margin-bottom:6px">لا توجد محادثات بعد</h3>
            <p>المحادثة تُفتح تلقائياً عند قبول صاحب المنتج لطلبك. أرسل طلباً على المنتج الذي يعجبك، وستجد المحادثة هنا بعد القبول.</p>
            <a class="btn btn-outline btn-sm mt-8" style="margin-top:10px" href="<?= e(url('index.php#market')) ?>"><?= icon('store', 14) ?>تصفّح السوق</a>
          </div>
        </div>
      <?php else: ?>
        <header class="chat-head">
          <span class="avatar avatar-sm"><?= e(avatar_letter((string)($other['full_name'] ?? '؟'))) ?></span>
          <div class="grow">
            <b><?= e((string)($other['full_name'] ?? 'مستخدم')) ?></b>
            <span>
              <?= e((string)($other['city'] ?? '')) ?>
              <?php if ($other): ?> · <?= stars((float)$other['rating_avg'], (int)$other['rating_count']) ?><?php endif; ?>
            </span>
          </div>
          <?php if ($other): ?>
            <a class="icon-btn" href="<?= e(url('index.php?q=' . urlencode((string)$other['full_name']))) ?>#market"><?= icon('store', 14) ?>منتجاته</a>
          <?php endif; ?>
        </header>

        <?php if (!empty($dealRequest)): ?>
          <div class="chat-deal" id="chatDeal" data-deal-status="<?= e((string)$dealRequest['status']) ?>">
            <div class="deal-head">
              <b><?= icon('route', 15) ?> طلب #<?= (int)$dealRequest['id'] ?></b>
              <span class="status <?= e(request_status_meta()[(string)$dealRequest['status']]['class'] ?? '') ?>" data-deal-label>
                <?= icon(request_status_meta()[(string)$dealRequest['status']]['icon'] ?? 'clock', 12) ?>
                <?= e(request_status_meta()[(string)$dealRequest['status']]['label'] ?? '') ?>
              </span>
            </div>

            <?php /* Progress for this product's deal, with the product itself beside it. */ ?>
            <?php if ($productTitle !== ''): ?>
              <div class="deal-product">
                <img src="<?= e((string)$productImg) ?>" alt="" loading="lazy">
                <span class="grow"><?= e(excerpt($productTitle, 46)) ?></span>
              </div>
            <?php endif; ?>

            <?= request_progress($dealRequest, true) ?>

            <?php /* The three buttons live where the negotiation happens. */ ?>
            <div id="dealActions" data-deal-panel>
              <?= chat_deal_actions($dealRequest, $uid) ?>
            </div>

            <a class="deal-full" data-deal-link href="<?= e(url('request.php?id=' . (int)$dealRequest['id'])) ?>">
              <?= icon('eye', 13) ?>تفاصيل الطلب الكاملة
            </a>
            <p class="text-xs text-muted mt-8"><?= icon('info', 12) ?> لا تشارك بيانات دفع حساسة. الدفع والتسليم باتفاق الطرفين خارج المنصة.</p>
          </div>
        <?php endif; ?>

        <?php if ($productTitle !== ''): ?>
          <div class="chat-product">
            <img src="<?= e((string)$productImg) ?>" alt="">
            <span class="grow"><?= icon('package', 14) ?> <?= e(excerpt($productTitle, 60)) ?></span>
            <a class="icon-btn" href="<?= e(url('product_details.php?id=' . (int)$active['product_id'])) ?>"><?= icon('eye', 13) ?>عرض</a>
          </div>
        <?php endif; ?>

        <div class="chat-body" id="chatBody" data-last-day="<?= e($lastDay) ?>" aria-live="polite">
          <?php $day = '';
          foreach ($messages as $m):
              $mDay = substr((string)$m['created_at'], 0, 10);
              if ($mDay !== $day):
                  $day = $mDay;
                  $isToday = $mDay === date('Y-m-d');
                  $isYest  = $mDay === date('Y-m-d', strtotime('-1 day'));
                  $label   = $isToday ? 'اليوم' : ($isYest ? 'أمس' : date('Y-m-d', strtotime($mDay)));
          ?>
            <div class="chat-day"><?= e($label) ?></div>
          <?php endif; ?>
            <?php if ($m['kind'] === 'system'): ?>
              <div class="msg system"><?= e((string)$m['body']) ?></div>
            <?php else: ?>
              <div class="msg <?= (int)$m['sender_id'] === $uid ? 'me' : 'them' ?>" data-msg-id="<?= (int)$m['id'] ?>">
                <span><?= e((string)$m['body']) ?></span>
                <span class="msg-time"><?= e(substr((string)$m['created_at'], 11, 5)) ?><?= (int)$m['sender_id'] === $uid
                    ? ' · ' . (!empty($m['read_at']) ? 'مقروءة' : (!empty($m['delivered_at']) ? 'وصلت' : 'أُرسلت'))
                    : '' ?></span>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php if (!$messages): ?>
            <div class="chat-day">ابدأ المحادثة بتحية ودّية</div>
          <?php endif; ?>
        </div>

        <?php $dealClosed = !empty($dealRequest) && request_is_closed((string)$dealRequest['status']); ?>
        <?php if ($dealClosed): ?>
          <div class="chat-closed"><?= icon('lock', 15) ?> أُغلق هذا الطلب — المحادثة متاحة للقراءة فقط.</div>
        <?php else: ?>
          <form class="chat-form" id="chatForm" method="post" action="<?= e(url('api/send_message.php')) ?>" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="conversation_id" value="<?= (int)$activeId ?>">
            <textarea id="chatInput" name="body" rows="1" maxlength="2000" placeholder="اكتب رسالتك… (Enter للإرسال)" aria-label="نص الرسالة"></textarea>
            <button class="btn btn-primary" type="submit" id="chatSend" aria-label="إرسال"><?= icon('send', 18) ?>إرسال</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
