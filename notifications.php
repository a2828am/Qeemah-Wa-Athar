<?php
/**
 * notifications.php — full notification list with mark-as-read actions.
 * Live counts keep updating through api/notifications.php (see app.js).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/components.php';

Auth::requireLogin('notifications.php');
$me      = Auth::user();
$uid     = (int)$me['id'];
$notifs  = NotificationRepo::forUser($uid, 50);
$unread  = NotificationRepo::unreadCount($uid);

// Single notification read (non-AJAX fallback).
$readId = get_int('read');
if ($readId) {
    require_csrf();
    NotificationRepo::markRead($uid, $readId);
    redirect('notifications.php');
}

$pageTitle   = 'الإشعارات | قيمة وأثر';
$currentPage = 'notifications';
$pageHead    = [
    'title'    => icon('bell', 28) . ' الإشعارات',
    'subtitle' => $unread > 0 ? 'لديك ' . $unread . ' إشعاراً غير مقروء.' : 'كل إشعاراتك مقروءة.',
    'actions'  => $unread > 0
        ? '<button class="btn btn-primary btn-sm" type="button" id="markAllRead">' . icon('check-circle', 15) . 'تعليم الكل كمقروء</button>'
        : '',
];
require __DIR__ . '/includes/header.php';
?>

<div class="container section" style="padding-top:26px">
  <?php if (!$notifs): ?>
    <div class="panel">
      <?= empty_state('لا توجد إشعارات بعد', 'ستصلك إشعارات عند الطلبات الجديدة، قبولها، الرسائل، واستلام التبرعات.', 'bell') ?>
    </div>
  <?php else: ?>
    <div class="notif-list" id="notifList">
      <?php foreach ($notifs as $n): ?>
        <?php $isUnread = !(int)$n['is_read']; ?>
        <article class="notif<?= $isUnread ? ' unread' : '' ?>">
          <span class="notif-ic"><?= icon(notification_icon((string)$n['type']), 18) ?></span>
          <div class="grow">
            <b><?= e((string)$n['title']) ?></b>
            <?php if (!empty($n['body'])): ?><p><?= e((string)$n['body']) ?></p><?php endif; ?>
            <?php if (!empty($n['link_url'])): ?>
              <a class="icon-btn mt-8" href="<?= e(url((string)$n['link_url'])) ?>" style="margin-top:8px"><?= icon('arrow-left', 13) ?>عرض</a>
            <?php endif; ?>
          </div>
          <time datetime="<?= e((string)$n['created_at']) ?>"><?= e(time_ago((string)$n['created_at'])) ?></time>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
