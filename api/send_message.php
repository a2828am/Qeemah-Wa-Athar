<?php
/**
 * api/send_message.php — posts a chat message and notifies the other party.
 *   POST conversation_id, body
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('استخدم POST لإرسال رسالة.', 405);
}
if (Auth::guest()) {
    json_err('انتهت الجلسة. يرجى تسجيل الدخول.', 401);
}
require_csrf();

$me      = Auth::user();
$convId  = post_int('conversation_id', 0) ?? 0;
$body    = (string)post('body', '');

if ($convId <= 0) {
    json_err('المحادثة مطلوبة.');
}
if ($body === '') {
    json_err('لا يمكن إرسال رسالة فارغة.');
}
if (mb_strlen($body) > 2000) {
    json_err('الرسالة طويلة جداً (2000 حرف كحد أقصى).');
}

try {
    $msg = ChatRepo::send($convId, (int)$me['id'], $body);
    json_ok([
        'message' => [
            'id'         => (int)$msg['id'],
            'sender_id'  => (int)$msg['sender_id'],
            'sender'     => (string)$me['full_name'],
            'body'       => (string)$msg['body'],
            'kind'       => (string)($msg['kind'] ?? 'text'),
            'created_at' => (string)$msg['created_at'],
            'read_at'    => null,
            'delivered_at' => null,
        ],
        'unread' => ChatRepo::unreadTotal((int)$me['id']),
    ]);
} catch (RuntimeException $ex) {
    json_err($ex->getMessage(), 422);
} catch (Throwable $ex) {
    error_log('[qeemah] send_message: ' . $ex->getMessage());
    json_err('تعذّر إرسال الرسالة حالياً.', 500);
}
