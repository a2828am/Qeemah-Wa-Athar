<?php
/**
 * api/notifications.php
 *   GET  ?poll=1   → live badge counters (no body)
 *   POST action=read_all | action=read&id=123
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

if (Auth::guest()) {
    json_err('انتهت الجلسة. يرجى تسجيل الدخول.', 401);
}
$uid = (int)Auth::id();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $items = NotificationRepo::forUser($uid, 8);
    $out   = [];
    foreach ($items as $n) {
        $out[] = [
            'id'         => (int)$n['id'],
            'type'       => (string)$n['type'],
            'title'      => (string)$n['title'],
            'body'       => (string)($n['body'] ?? ''),
            'link'       => (string)($n['link_url'] ?? ''),
            'is_read'    => (bool)(int)$n['is_read'],
            'created_at' => (string)$n['created_at'],
            'ago'        => time_ago((string)$n['created_at']),
        ];
    }
    json_ok([
        'notifications'        => $out,
        'unread_notifications' => NotificationRepo::unreadCount($uid),
        'unread_messages'      => ChatRepo::unreadTotal($uid),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('طريقة غير مدعومة.', 405);
}
require_csrf();

$action = (string)post('action', 'read_all');
if ($action === 'read') {
    $id = post_int('id', 0) ?? 0;
    if (!$id) {
        json_err('رقم الإشعار مطلوب.');
    }
    NotificationRepo::markRead($uid, $id);
    json_ok(['message' => 'تم تعليم الإشعار كمقروء.', 'unread_notifications' => NotificationRepo::unreadCount($uid)]);
}

NotificationRepo::markRead($uid);
json_ok([
    'message'              => 'تم تعليم كل الإشعارات كمقروءة.',
    'unread_notifications' => 0,
    'reload'               => false,
]);
