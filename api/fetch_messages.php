<?php
/**
 * api/fetch_messages.php — chat polling.
 *   GET conversation=ID&after=LAST_MESSAGE_ID
 * Returns the new messages, marks them read for the current user and reports
 * the unread counters used by the header badges.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

if (Auth::guest()) {
    json_err('انتهت الجلسة. يرجى تسجيل الدخول.', 401);
}
$me  = Auth::user();
$uid = (int)$me['id'];

$convId = get_int('conversation', 0) ?? 0;
$after  = max(0, get_int('after', 0) ?? 0);

if (!$convId || !ChatRepo::isParticipant($convId, $uid)) {
    json_err('المحادثة غير متاحة.', 403);
}

$rows = ChatRepo::messages($convId, $after, 200);
ChatRepo::markRead($convId, $uid);

$out = [];
foreach ($rows as $m) {
    $out[] = [
        'id'         => (int)$m['id'],
        'sender_id'  => (int)$m['sender_id'],
        'sender'     => (string)$m['sender_name'],
        'body'       => (string)$m['body'],
        'kind'       => (string)$m['kind'],
        'created_at' => (string)$m['created_at'],
        'read_at'    => $m['read_at'] ?? null,
        'delivered_at' => $m['delivered_at'] ?? null,
    ];
}

// Keep the deal progress in sync inside the chat without a second request.
$conv = ChatRepo::find($convId);
$request = !empty($conv['request_id']) ? RequestRepo::find((int)$conv['request_id'], $uid) : null;
$progress = null;
if ($request) {
    $progress = [
        'id'     => (int)$request['id'],
        'status' => (string)$request['status'],
        'step'   => request_step_index((string)$request['status']),
        'total'  => count(request_steps()),
        'label'  => request_status_meta()[$request['status']]['label'] ?? $request['status'],
        'url'    => url('request.php?id=' . (int)$request['id']),
        'closed' => request_is_closed((string)$request['status']),
    ];
}

json_ok([
    'messages'             => $out,
    'unread'               => ChatRepo::unreadTotal($uid),
    'unread_notifications' => NotificationRepo::unreadCount($uid),
    'server_time'          => now(),
    'request'              => $progress,
]);
