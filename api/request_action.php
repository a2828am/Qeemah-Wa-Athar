<?php
/**
 * api/request_action.php — every lifecycle action for a request, in one place.
 *
 *   action=accept|reject|cancel|dispute        request_id [, reason]
 *   action=agree                               request_id, summary[, place, when]
 *   action=start_delivery                      request_id
 *   action=confirm_delivery                    request_id [, note]
 *   action=rate                                request_id, score[, comment]
 *   action=progress                            request_id  (read-only JSON for polling)
 *
 * Permissions are re-checked here on the server for every call; the browser
 * never decides who may do what.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && (string)($_GET['action'] ?? '') !== 'progress') {
    json_err('استخدم POST لهذا الإجراء.', 405);
}
Auth::requireLogin();
$me      = Auth::user();
$uid     = (int)$me['id'];
$isAdmin = Auth::isAdmin();

$action  = (string)(post('action', '') ?: ($_GET['action'] ?? ''));
$requestId = (int)(post_int('request_id') ?? get_int('request_id') ?? 0);

if (!$requestId) {
    json_err('رقم الطلب مطلوب.', 422);
}

$request = RequestFlowRepo::requestRow($requestId);

/* Rating a completed deal is open to any signed-in user (except the publisher),
   so that one action may read a request the viewer cannot otherwise see. It is
   the only exception: every other action keeps the strict party guard below, and
   the check is limited to a delivered deal so ids cannot be probed apart. */
$ratingOpen = $action === 'rate' && $request && (string)$request['status'] === 'delivered';

if (!$request || (!RequestFlowRepo::canView($request, $uid, $isAdmin) && !$ratingOpen)) {
    // Same message for "missing" and "not yours" so ids cannot be enumerated.
    json_err('الطلب غير موجود أو لا تملك صلاحية الوصول إليه.', 404);
}

try {
    /* ── progress (read-only, used by the chat page to stay in sync) ────── */
    if ($action === 'progress') {
        $row = RequestRepo::detail($requestId, $uid, $isAdmin);
        json_ok([
            'status'      => (string)$row['status'],
            'closed'      => in_array((string)$row['status'], request_closed_states(), true),
            'url'         => url('request.php?id=' . $requestId),
            'step'        => request_step_index((string)$row['status']),
            'total_steps' => count(request_steps()),
            'label'       => request_status_meta()[$row['status']]['label'] ?? $row['status'],
            'agreements'  => array_map(static fn ($a) => ['user_id' => (int)$a['user_id'], 'confirmed_at' => $a['confirmed_at']], $row['agreements']),
            'deliveries'  => array_map(static fn ($d) => ['user_id' => (int)$d['user_id'], 'confirmed_at' => $d['confirmed_at']], $row['deliveries']),
        ]);
    }

    require_csrf();

    switch ($action) {
        case 'accept':
            $res = RequestFlowRepo::accept($requestId, $uid);
            json_ok([
                'message'         => 'تم قبول الطلب وفُتحت محادثة مع مقدّمه.',
                'conversation_id' => $res['conversation_id'],
                'redirect'        => url('request.php?id=' . $requestId),
            ]);

        case 'reject':
            RequestFlowRepo::reject($requestId, $uid, post('reason'));
            json_ok(['message' => 'تم رفض الطلب وإبلاغ مقدّمه.', 'redirect' => url('request.php?id=' . $requestId)]);

        case 'cancel':
            RequestFlowRepo::cancel($requestId, $uid, post('reason'));
            json_ok(['message' => 'تم إلغاء الطلب.', 'redirect' => url('request.php?id=' . $requestId)]);

        case 'agree':
            $res = RequestFlowRepo::confirmAgreement($requestId, $uid, [
                'summary' => (string)post('summary', ''),
                'place'   => (string)post('place', ''),
                'when'    => (string)post('when', ''),
            ]);
            json_ok([
                'message'   => $res['all'] ? 'تم تأكيد الاتفاق من الطرفين.' : 'سُجّل تأكيدك، بانتظار الطرف الآخر.',
                'status'    => $res['status'],
                'confirmed' => $res['confirmed'],
                'reload'    => true,
            ]);

        case 'start_delivery':
            RequestFlowRepo::startDelivery($requestId, $uid);
            json_ok(['message' => 'تم تسجيل بدء التسليم.', 'reload' => true]);

        case 'confirm_delivery':
            $res = RequestFlowRepo::confirmDelivery($requestId, $uid, post('note'));
            json_ok([
                'message'   => $res['all'] ? 'اكتملت العملية — تم التسليم من الطرفين.' : 'سُجّل تأكيدك، بانتظار الطرف الآخر.',
                'status'    => $res['status'],
                'confirmed' => $res['confirmed'],
                'reload'    => true,
            ]);

        case 'dispute':
            RequestFlowRepo::dispute($requestId, $uid, (string)post('reason', ''));
            json_ok(['message' => 'تم تسجيل الخلاف وإبلاغ الإدارة.', 'reload' => true]);

        case 'rate':
            if ((string)$request['status'] !== 'delivered') {
                json_err('التقييم متاح بعد إتمام التسليم فقط.', 422);
            }
            $score = post_int('score', 0) ?? 0;
            if ($score < 1 || $score > 5) {
                json_err('اختر تقييماً بين 1 و5 نجوم.', 422);
            }
            /* Any signed-in user may rate a completed deal; the only blocked
               person is the one who published the product. RatingRepo enforces
               the same rule, so a direct call cannot slip past this. */
            if ($uid === RatingRepo::publisherIdFor($request)) {
                json_err('لا يمكنك تقييم عملية على منتج نشرته أنت.', 422);
            }
            if (RatingRepo::hasRated($requestId, $uid)) {
                json_err('قيّمت هذه العملية مسبقاً.', 422);
            }
            RatingRepo::create($requestId, $uid, $score, post('comment'));
            json_ok(['message' => 'تم تسجيل تقييمك، شكراً لك.', 'reload' => true]);

        default:
            json_err('إجراء غير معروف.', 400);
    }
} catch (RuntimeException $ex) {
    json_err($ex->getMessage(), 422);
} catch (Throwable $ex) {
    error_log('[qeemah] request_action: ' . $ex->getMessage());
    json_err('تعذّر تنفيذ العملية، حاول لاحقاً.', 500);
}
