<?php
/**
 * includes/components.php
 * -----------------------------------------------------------------------------
 * Reusable HTML fragments shared by the public pages, the charity panel and
 * the admin panel. Every card/row still uses the approved design classes.
 */
declare(strict_types=1);

/** Resolves the on-disk picture for a product, falling back to a category icon placeholder. */
function product_image(?string $path): string
{
    if ($path && is_file(QEEMAH_ROOT . '/' . $path)) {
        // Uploads live under storage/, not assets/ — link them directly.
        return str_starts_with($path, 'assets/') ? asset($path) : url($path);
    }
    return asset('img/placeholder.png');
}

/**
 * Product card used on the home grid, favourites, my listings and profile.
 */
function product_card(array $p, array $favIds = [], bool $showOwner = true): string
{
    $isFav   = in_array((int)$p['id'], $favIds, true);
    $img     = product_image($p['primary_image'] ?? null);
    $url     = url('product_details.php?slug=' . urlencode((string)$p['slug']));
    $statusBadge = '';
    if (($p['status'] ?? 'available') !== 'available') {
        $statusBadge = '<span class="status ' . e(product_status_meta()[$p['status']]['class'] ?? 'st-cancelled') . '">'
            . icon(product_status_meta()[$p['status']]['icon'] ?? 'clock', 12)
            . e(product_status_meta()[$p['status']]['label'] ?? $p['status']) . '</span>';
    }
    $priceLine = match ($p['transaction_type']) {
        'sell'   => '<span class="price">' . e(money($p['price'])) . '</span>',
        'swap'   => '<span class="exchange-note">' . icon('repeat', 13) . ' ' . e(excerpt($p['swap_wanted'] ?? 'يبحث عن بديل', 40)) . '</span>',
        'donate' => '<span class="badge badge-donate">' . icon('hand-heart', 13) . 'تبرّع مباشر</span>',
        default  => '<span class="price" style="color:var(--teal)">مجاناً</span>',
    };

    return '<article class="product-card card" data-category="' . (int)$p['category_id'] . '" data-city="' . e((string)$p['city']) . '" data-type="' . e((string)$p['transaction_type']) . '" data-title="' . e((string)$p['title']) . '">'
        . '<div class="card-img">'
        .   '<a href="' . e($url) . '" aria-label="' . e((string)$p['title']) . '"><img src="' . e($img) . '" alt="' . e((string)$p['title']) . '" loading="lazy"></a>'
        .   op_badge((string)$p['transaction_type'])
        .   $statusBadge
        .   '<button type="button" class="fav-btn' . ($isFav ? ' active' : '') . '" data-fav="' . (int)$p['id'] . '"'
        .     ' aria-pressed="' . ($isFav ? 'true' : 'false') . '" aria-label="' . ($isFav ? 'إزالة من المفضلة' : 'إضافة إلى المفضلة') . '"'
        .     ' style="color:' . ($isFav ? '#e11d48' : '') . '">' . icon('heart', 17) . '</button>'
        . '</div>'
        . '<div class="card-body">'
        .   '<span class="cat">' . e((string)$p['category_name']) . '</span>'
        .   '<h3><a href="' . e($url) . '">' . e(excerpt((string)$p['title'], 60)) . '</a></h3>'
        .   '<div class="meta">'
        .     '<span>' . icon('map-pin', 13) . e((string)$p['city']) . '</span>'
        .     '<span>' . icon('package', 13) . e(condition_label((string)$p['condition_type'])) . '</span>'
        .     '<span>' . icon('eye', 13) . (int)$p['views_count'] . '</span>'
        .   '</div>'
        .   ($showOwner ? '<div class="owner-mini"><span class="avatar avatar-sm">' . e(avatar_letter((string)$p['owner_name'])) . '</span><span>' . e((string)$p['owner_name']) . '</span></div>' : '')
        .   $priceLine
        .   '<div class="card-foot"><a class="btn btn-outline btn-sm" href="' . e($url) . '">' . icon('eye', 15) . 'التفاصيل</a></div>'
        . '</div>'
        . '</article>';
}

/** Empty state block. */
function empty_state(string $title, string $text, string $iconName = 'package', string $cta = '', string $ctaUrl = ''): string
{
    $html = '<div class="empty-state"><div class="empty-ic">' . icon($iconName, 32) . '</div>'
        . '<h3>' . e($title) . '</h3><p>' . e($text) . '</p>';
    if ($cta !== '') {
        $html .= '<a class="btn btn-primary btn-sm" href="' . e($ctaUrl) . '">' . icon('plus', 15) . e($cta) . '</a>';
    }
    return $html . '</div>';
}

/** Pagination links that preserve the current query string. */
function pagination_links(array $p, array $query = []): string
{
    if (($p['pages'] ?? 1) <= 1) {
        return '';
    }
    $mk = static function (int $page, string $label, string $cls = '') use ($query): string {
        $q = $query;
        $q['page'] = $page;
        return '<a class="' . $cls . '" href="?' . e(http_build_query($q)) . '">' . $label . '</a>';
    };
    $out  = '<nav class="pagination" aria-label="ترقيم الصفحات">';
    $out .= $p['has_prev'] ? $mk($p['page'] - 1, icon('chevron-right', 16)) : '<span class="disabled">' . icon('chevron-right', 16) . '</span>';
    $start = max(1, $p['page'] - 2);
    $end   = min($p['pages'], $p['page'] + 2);
    if ($start > 1) {
        $out .= $mk(1, '1');
        if ($start > 2) {
            $out .= '<span class="disabled">…</span>';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        $out .= $i === $p['page']
            ? '<span class="current">' . $i . '</span>'
            : $mk($i, (string)$i);
    }
    if ($end < $p['pages']) {
        if ($end < $p['pages'] - 1) {
            $out .= '<span class="disabled">…</span>';
        }
        $out .= $mk($p['pages'], (string)$p['pages']);
    }
    $out .= $p['has_next'] ? $mk($p['page'] + 1, icon('chevron-left', 16)) : '<span class="disabled">' . icon('chevron-left', 16) . '</span>';
    return $out . '</nav>';
}

/** Star row for ratings (read only). */
function stars(float $avg, int $count = 0): string
{
    $full = (int)round($avg);
    $out  = '<span class="rating-row" title="' . e(number_format($avg, 1)) . ' من 5">';
    for ($i = 1; $i <= 5; $i++) {
        $out .= '<span style="opacity:' . ($i <= $full ? '1' : '.28') . '">' . icon('star', 14) . '</span>';
    }
    $out .= '<b>' . e(number_format($avg, 1)) . '</b>';
    if ($count > 0) {
        $out .= '<span class="text-xs text-muted">(' . $count . ' تقييم)</span>';
    }
    return $out . '</span>';
}

/** Public status timeline for a request. */

/** A single conversation preview row for chat.php / my dashboard. */
function conversation_row(array $c, int $activeId = 0): string
{
    $url   = url('chat.php?c=' . (int)$c['id']);
    $name  = (string)$c['other_name'];
    $last  = excerpt((string)($c['last_body'] ?? 'ابدأ المحادثة الآن'), 42);
    $unread = (int)$c['unread'];
    return '<a class="conv' . ($activeId === (int)$c['id'] ? ' active' : '') . '" href="' . e($url) . '">'
        . '<span class="avatar avatar-sm">' . e(avatar_letter($name)) . '</span>'
        . '<span class="grow"><b>' . e($name) . '</b><span>' . e($last) . '</span></span>'
        . '<span class="conv-meta"><time>' . e(time_ago($c['last_at'] ?? $c['created_at'])) . '</time>'
        . ($unread > 0 ? '<span class="unread">' . $unread . '</span>' : '')
        . '</span></a>';
}

/** Notification icon per type. */
function notification_icon(string $type): string
{
    $map = [
        'message_new'       => 'message-circle',
        'request_new'       => 'clipboard',
        'request_accepted'  => 'check-circle',
        'request_rejected'  => 'ban',
        'request_completed' => 'check',
        'donation_claimed'  => 'hand-heart',
        'rating'            => 'star',
        'system'            => 'info',
    ];
    return $map[$type] ?? 'bell';
}

/**
 * Six-step progress bar for a request. Completed steps carry their real
 * timestamp; terminal states render beside the bar instead of as "done".
 */
function request_progress(array $request, bool $compact = false): string
{
    $status = (string)$request['status'];
    $steps  = request_steps();
    $index  = request_step_index($status);
    $closed = request_is_closed($status);
    $dates  = [];
    foreach (($request['history'] ?? []) as $h) {
        $dates[(string)$h['to_status']] = (string)$h['created_at'];
    }
    $html = '<div class="req-progress' . ($compact ? ' compact' : '') . '">';
    $html .= '<ol class="req-steps" role="list">';
    $i = 0;
    foreach ($steps as $key => $step) {
        $i++;
        $done    = $index > 0 && $i <= $index;
        $current = $index === $i;
        $when    = $dates[$key] ?? '';
        $html .= '<li class="req-step' . ($done ? ' done' : '') . ($current ? ' current' : '') . '">'
              . '<span class="req-dot">' . ($done ? icon('check', 12) : ($i)) . '</span>'
              . '<span class="req-step-body">'
              . '<b>' . e($step['short']) . '</b>'
              . ($compact ? '' : '<small>' . e($step['label']) . '</small>')
              . (!$compact && $when !== '' ? '<i>' . e(date('Y-m-d H:i', strtotime($when))) . '</i>' : '')
              . '</span></li>';
    }
    $html .= '</ol>';
    if ($closed) {
        $state = request_closed_states()[$status];
        $html .= '<div class="req-closed">' . icon($state['icon'], 15)
              . '<b>' . e($state['label']) . '</b>'
              . (!empty($request['reject_reason']) ? '<span>' . e((string)$request['reject_reason']) . '</span>' : '')
              . (!empty($request['cancelled_reason']) ? '<span>' . e((string)$request['cancelled_reason']) . '</span>' : '')
              . (!empty($request['dispute_reason']) ? '<span>' . e((string)$request['dispute_reason']) . '</span>' : '')
              . '</div>';
    } else {
        $html .= '<div class="req-current">' . icon('route', 14) . ' المرحلة الحالية: <b>'
              . e($steps[$status]['label'] ?? $status) . '</b></div>';
    }
    return $html . '</div>';
}

/** Two-sided confirmation widget: who confirmed, who is still needed. */
function request_confirmations(array $request, string $type): string
{
    $rows = $type === 'agreement' ? ($request['agreements'] ?? []) : ($request['deliveries'] ?? []);
    $parties = $request['parties'] ?? [];
    $confirmed = [];
    foreach ($rows as $r) {
        if (!empty($r['confirmed_at'])) {
            $confirmed[(int)$r['user_id']] = $r;
        }
    }
    $html = '<ul class="confirm-list">';
    foreach ($parties as $uid) {
        $isDone = isset($confirmed[$uid]);
        $name   = $isDone ? (string)$confirmed[$uid]['full_name'] : (string)Repo::scalar('SELECT full_name FROM users WHERE id=?', [$uid]);
        $html .= '<li class="' . ($isDone ? 'done' : '') . '">'
              . '<span class="confirm-ic">' . icon($isDone ? 'check-circle' : 'clock', 15) . '</span>'
              . '<span class="grow"><b>' . e($name) . '</b>'
              . '<small>' . ($isDone ? 'أكّد في ' . e(date('Y-m-d H:i', strtotime((string)$confirmed[$uid]['confirmed_at']))) : 'بانتظار التأكيد') . '</small></span>'
              . '</li>';
    }
    return $html . '</ul>';
}

/**
 * The three deal buttons, inside the conversation where the two sides talk.
 *
 * Order follows the lifecycle: «تم الاتفاق» → «بدء التسليم» → «تم التسليم»,
 * with a disabled «جاري الاتفاق…» while the negotiation is still running.
 *
 * Two rules from the rest of the platform are preserved here rather than
 * bypassed: a deal needs BOTH parties to confirm, and nothing can skip a step.
 * So each button reports the viewer's own state (لم تؤكد / أكدت وتنتظر الآخر)
 * instead of pretending one click finishes the deal.
 *
 * Rendered only for a party to the request — an outsider or a closed deal gets
 * an explanation, never a button that would fail server-side.
 */
function chat_deal_actions(array $request, int $viewerId, bool $isAdmin = false): string
{
    $status = (string)$request['status'];
    $id     = (int)$request['id'];
    /* Only the two parties of THIS request. Do not fall back to owner_id: in a
       targeted donation the owner is just the publisher, and the association's
       representative is the real counterpart (see RequestFlowRepo). */
    $parties = array_map('intval', $request['parties'] ?? []);
    if ($parties === []) {
        $parties = RequestFlowRepo::expectedParties($request);
    }
    $isParty = in_array($viewerId, $parties, true);

    $agreeAt = [];
    foreach (($request['agreements'] ?? []) as $a) {
        if (!empty($a['confirmed_at'])) {
            $agreeAt[(int)$a['user_id']] = (string)$a['confirmed_at'];
        }
    }
    $deliverAt = [];
    foreach (($request['deliveries'] ?? []) as $d) {
        if (!empty($d['confirmed_at'])) {
            $deliverAt[(int)$d['user_id']] = (string)$d['confirmed_at'];
        }
    }
    $others = array_values(array_diff($parties, [$viewerId]));
    $otherName = $others ? (string)Repo::scalar('SELECT full_name FROM users WHERE id=?', [$others[0]]) : 'الطرف الآخر';
    $iAgreed    = isset($agreeAt[$viewerId]);
    $iDelivered = isset($deliverAt[$viewerId]);
    $allAgreed  = $parties !== [] && count(array_intersect($parties, array_keys($agreeAt))) === count($parties);
    $allDelivered = $parties !== [] && count(array_intersect($parties, array_keys($deliverAt))) === count($parties);

    /* These post to the JSON API, so they MUST go through the data-ajax
       submitter: without it the browser navigates to the endpoint and the user
       is left staring at raw {"ok":true,…} on a blank page. data-done="reload"
       then re-renders the chat so the new state shows immediately. */
    $btn = static function (string $action, string $label, string $icon, string $class, string $extraAttr = '') use ($id): string {
        return '<form method="post" data-ajax="api/request_action.php" data-done="deal" class="deal-act">'
            . csrf_field()
            . '<input type="hidden" name="request_id" value="' . $id . '">'
            . '<input type="hidden" name="action" value="' . e($action) . '">'
            . $extraAttr
            . '<button class="btn ' . $class . ' btn-block" type="submit">' . icon($icon, 16) . e($label) . '</button>'
            . '</form>';
    };

    $html = '<div class="deal-actions" data-deal-actions>';

    /* ── a closed deal explains itself ─────────────────────────────────── */
    if (request_is_closed($status)) {
        $closed = request_closed_states()[$status];
        return $html . '<div class="deal-note deal-note-closed">' . icon($closed['icon'], 15)
            . '<span>' . e($closed['label']) . ' — لا إجراءات متاحة على هذا الطلب.</span></div></div>';
    }

    if (!$isParty) {
        return $html . '<div class="deal-note">' . icon('info', 15)
            . '<span>هذه الإجراءات متاحة لطرفي الطلب فقط.</span></div></div>';
    }

    /* ── delivered: everything is done ─────────────────────────────────── */
    if ($status === 'delivered' || $allDelivered) {
        return $html . '<div class="deal-note deal-note-done">' . icon('check-circle', 16)
            . '<span>تم التسليم وأُكملت العملية. شكراً لكما!</span>'
            . '<a class="link-sm" href="' . e(url('my_requests.php?tab=outgoing')) . '">قيّم التجربة</a>'
            . '</div></div>';
    }

    /* ── step 1: the agreement ─────────────────────────────────────────── */
    if (in_array($status, ['accepted', 'negotiating', 'agreed'], true)) {
        if ($allAgreed) {
            $html .= '<div class="deal-note deal-note-done">' . icon('check-circle', 16)
                . '<span>تم الاتفاق من الطرفين — الخطوة التالية التسليم.</span></div>';
        } elseif ($iAgreed) {
            $html .= '<div class="deal-row"><span class="deal-pill done">' . icon('check', 13)
                . 'أكّدت الاتفاق</span><span class="deal-hint">بانتظار تأكيد ' . e($otherName) . '</span></div>';
        } else {
            $html .= '<div class="deal-row"><span class="deal-pill waiting">' . icon('clock', 13)
                . 'جاري الاتفاق</span><span class="deal-hint">اتفقا في الرسائل أعلاه ثم اضغط الزر</span></div>';
            $html .= $btn(
                'agree',
                'تم الاتفاق',
                'handshake',
                'btn-primary',
                '<input type="hidden" name="summary" value="اتفاق على الطلب #' . $id . ' — التفاصيل في المحادثة">'
            );
        }
    }

    /* ── step 2: hand-over starts only once both agreed ────────────────── */
    if ($status === 'agreed' || ($allAgreed && $status !== 'delivery_pending')) {
        $html .= '<div class="deal-row"><span class="deal-pill done">' . icon('check', 13)
            . 'الاتفاق مكتمل</span><span class="deal-hint">سلّم المنتج ثم سجّل البدء</span></div>';
        $html .= $btn('start_delivery', 'بدء التسليم', 'truck', 'btn-outline');
    }

    /* ── step 3: delivery, confirmed by both ───────────────────────────── */
    if ($status === 'delivery_pending') {
        if ($iDelivered) {
            $html .= '<div class="deal-row"><span class="deal-pill done">' . icon('check', 13)
                . 'أكّدت التسليم</span><span class="deal-hint">بانتظار تأكيد ' . e($otherName) . '</span></div>';
        } else {
            $html .= '<div class="deal-row"><span class="deal-pill waiting">' . icon('truck', 13)
                . 'التسليم جارٍ</span><span class="deal-hint">أكّد بعد استلام/تسليم المنتج فعلياً</span></div>';
            $html .= $btn('confirm_delivery', 'تم التسليم', 'check-circle', 'btn-primary');
        }
    }

    return $html . '</div>';
}
