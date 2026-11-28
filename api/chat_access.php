<?php
/**
 * api/chat_access.php — one place that answers "may I open this thread?".
 * The chat page calls it before rendering, and it is the only path that
 * creates a conversation from a URL — never from a raw user id.
 *
 *   GET  ?conversation=ID   → may I read it?
 *   POST  request_id=ID     → open (or create) the thread for an accepted request
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

if (Auth::guest()) {
    json_err('انتهت الجلسة. يرجى تسجيل الدخول.', 401);
}
$me      = Auth::user();
$uid     = (int)$me['id'];
$isAdmin = Auth::isAdmin();

/* ── open the thread of an accepted request ─────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $requestId = post_int('request_id', 0) ?? 0;
    $request = $requestId ? RequestRepo::find($requestId, $uid) : null;
    if (!$request) {
        json_err('الطلب غير موجود.', 404);
    }
    if (!RequestFlowRepo::canView($request, $uid, $isAdmin)) {
        json_err('لا تملك صلاحية الوصول إلى هذا الطلب.', 403);
    }
    if (in_array((string)$request['status'], ['awaiting_owner', 'rejected', 'cancelled'], true)) {
        json_err('لا تُفتح المحادثة قبل قبول صاحب المنتج للطلب.', 422);
    }
    $convId = (int)($request['conversation_id'] ?? 0);
    if (!$convId) {
        json_err('المحادثة لم تُنشأ بعد لهذا الطلب.', 404);
    }
    json_ok(['conversation_id' => $convId, 'redirect' => url('chat.php?c=' . $convId)]);
}

/* ── read access check ──────────────────────────────────────────────────── */
$convId = get_int('conversation', 0) ?? 0;
$conv   = $convId ? ChatRepo::find($convId) : null;
if (!$conv) {
    json_err('المحادثة غير موجودة.', 404);
}
$allowed = ChatRepo::isParticipant($convId, $uid);
// An admin may audit a thread only through the admin panel, with an audit row.
if (!$allowed && $isAdmin && !empty($_GET['audit'])) {
    AuditRepo::log($uid, 'chat.audit_view', 'conversation', $convId, null, ['by' => 'admin']);
    $allowed = true;
}
if (!$allowed) {
    json_err('هذه المحادثة ليست لك.', 403);
}
json_ok(['conversation_id' => $convId, 'request_id' => $conv['request_id'] ? (int)$conv['request_id'] : null]);
