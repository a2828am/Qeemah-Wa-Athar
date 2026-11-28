<?php
/**
 * includes/request_flow.php
 * -----------------------------------------------------------------------------
 * The single source of truth for the request lifecycle.
 *
 *   awaiting_owner ──accept──▶ accepted ──▶ negotiating ──both agree──▶ agreed
 *        │                        │              │                        │
 *      reject                   cancel        dispute                  delivery_pending
 *        ▼                        ▼              ▼                        │ both confirm
 *    rejected                 cancelled      disputed ◀────────────────────┘
 *                                              │                        ▼
 *                                              └────────────────────▶ delivered
 *
 * Every method checks permissions on the server, writes request_status_history
 * (append-only) and pushes notifications. Anything that touches more than one
 * row runs inside Database::transaction() so two parties cannot race each
 * other into a contradictory state.
 */
declare(strict_types=1);

use Qeemah\Config\Database;

final class RequestFlowRepo extends Repo
{
    /* ── permissions ─────────────────────────────────────────────────────── */

    /** Roles a user plays on a given request. Admin sees everything (audit only). */
    public static function rolesFor(array $request, int $userId, bool $isAdmin = false): array
    {
        $roles = [];
        if ((int)$request['requester_id'] === $userId) {
            $roles[] = 'requester';
        }
        if ((int)$request['owner_id'] === $userId) {
            $roles[] = 'owner';
        }
        if (self::associationRepOf($request, $userId)) {
            $roles[] = 'association_rep';
        }
        if ($isAdmin) {
            $roles[] = 'admin';
        }
        return $roles;
    }

    /** Owners of the association a donation is targeted at act on its behalf. */
    public static function associationRepOf(array $request, int $userId): bool
    {
        if (($request['request_type'] ?? '') !== 'donate' || empty($request['association_id'])) {
            return false;
        }
        return (bool)self::scalar(
            "SELECT 1 FROM associations a
              WHERE a.id=? AND (a.owner_user_id=?
                    OR EXISTS (SELECT 1 FROM association_members m
                                WHERE m.association_id=a.id AND m.user_id=?
                                  AND m.status='active' AND m.can_donations=1))
              LIMIT 1",
            [(int)$request['association_id'], $userId, $userId]
        );
    }

    public static function canView(array $request, int $userId, bool $isAdmin = false): bool
    {
        if (self::rolesFor($request, $userId, $isAdmin) !== []) {
            return true;
        }
        // An unclaimed general donation is visible to verified associations so one
        // of them can take responsibility for collecting and distributing it.
        if (($request['request_type'] ?? '') === 'donate' && empty($request['association_id'])
            && ($request['product_donation_scope'] ?? 'general') === 'general' && $userId > 0) {
            return AssociationRepo::actingFor($userId) !== [];
        }
        return false;
    }

    /* ── create ──────────────────────────────────────────────────────────── */

    public static function create(int $productId, int $requesterId, array $d): int
    {
        return Database::transaction(function () use ($productId, $requesterId, $d) {
            $product = self::one('SELECT * FROM products WHERE id=?', [$productId]);
            if (!$product) {
                throw new RuntimeException('المنتج غير موجود.');
            }
            if ($product['status'] !== 'available') {
                throw new RuntimeException('هذا المنتج لم يعد متاحاً لاستقبال الطلبات.');
            }
            if ((int)$product['owner_id'] === $requesterId) {
                throw new RuntimeException('لا يمكنك إرسال طلب على منتجك.');
            }
            $open = (bool)self::scalar(
                "SELECT 1 FROM user_requests
                  WHERE product_id=? AND requester_id=?
                    AND status IN ('awaiting_owner','accepted','negotiating','agreed','delivery_pending')",
                [$productId, $requesterId]
            );
            if ($open) {
                throw new RuntimeException('لديك طلب مفتوح بالفعل على هذا المنتج.');
            }
            if ($product['transaction_type'] === 'swap' && empty($d['offered_product_id'])) {
                throw new RuntimeException('اختر أحد منتجاتك لعرضه في المقايضة.');
            }

            // A donation is collected by one association: the targeted one, else the
            // association offering the item. General donations stay open to all.
            $assocId = $product['transaction_type'] === 'donate'
                ? (int)($product['target_association_id'] ?: ($product['donation_scope'] === 'targeted' ? $product['association_id'] : 0))
                : 0;
            if ($assocId > 0 && (string)self::scalar('SELECT status FROM associations WHERE id=?', [$assocId]) !== 'verified') {
                throw new RuntimeException('الجمعية المستفيدة غير موثّقة بعد.');
            }

            self::exec(
                'INSERT INTO user_requests
                   (product_id, requester_id, owner_id, request_type, association_id, status, message,
                    offered_product_id, offered_amount, contact_phone, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $productId, $requesterId, (int)$product['owner_id'], $product['transaction_type'],
                    $assocId > 0 ? $assocId : null, 'awaiting_owner', $d['message'] ?? null,
                    $d['offered_product_id'] ?? null, $d['offered_amount'] ?? null,
                    $d['contact_phone'] ?? null, now(), now(),
                ]
            );
            $requestId = self::lastId();

            if ($product['transaction_type'] === 'swap' && !empty($d['offered_product_id'])) {
                self::exec(
                    'INSERT INTO exchange_requests
                       (request_id, offered_product_id, wanted_product_id, meetup_city, created_at, updated_at)
                     VALUES (?,?,?,?,?,?)',
                    [$requestId, (int)$d['offered_product_id'], $productId, $d['meetup_city'] ?? null, now(), now()]
                );
            }

            self::history($requestId, null, 'awaiting_owner', $requesterId, 'إرسال الطلب');
            ProductRepo::incrementRequests($productId);
            ActivityRepo::log($requesterId, 'request.created', 'request', $requestId, ['product' => $productId]);

            // 1) the owner is notified with a direct link to the request
            NotificationRepo::push(
                (int)$product['owner_id'],
                'request_new',
                'طلب جديد على منتجك',
                excerpt((string)self::scalar('SELECT full_name FROM users WHERE id=?', [$requesterId]), 40)
                    . ' يطلب «' . excerpt((string)$product['title'], 50) . '» — راجعه للقبول أو الرفض.',
                'my_requests.php?tab=incoming&focus=' . $requestId,
                'inbox'
            );

            return $requestId;
        });
    }

    /* ── owner decisions ─────────────────────────────────────────────────── */

    /**
     * An association's representative claims a general donation. This is how a
     * donation that was published for all associations becomes bound to one.
     */
    public static function claimAsAssociation(int $requestId, int $assocId, int $actorId): void
    {
        Database::transaction(function () use ($requestId, $assocId, $actorId) {
            $request = self::requestRow($requestId);
            if (!$request || $request['request_type'] !== 'donate') {
                throw new RuntimeException('هذا الطلب ليس تبرّعاً.');
            }
            if (!empty($request['association_id'])) {
                throw new RuntimeException('هذا التبرّع مرتبط بجمعية بالفعل.');
            }
            if (!self::associationRepOf(['request_type' => 'donate', 'association_id' => $assocId], $actorId)) {
                throw new RuntimeException('الربط متاح لممثل الجمعية فقط.');
            }
            self::exec('UPDATE user_requests SET association_id=?, updated_at=? WHERE id=? AND association_id IS NULL',
                [$assocId, now(), $requestId]);
            ActivityRepo::log($actorId, 'request.claimed_by_association', 'request', $requestId, ['association' => $assocId]);
            NotificationRepo::push(
                (int)$request['requester_id'], 'donation_claimed', 'جمعية تريد استلام تبرّعك',
                'ربطت جمعية «' . (string)self::scalar('SELECT name FROM associations WHERE id=?', [$assocId])
                    . '» نفسها بطلبك على «' . excerpt((string)$request['product_title'], 50) . '».',
                'request.php?id=' . $requestId, 'handshake'
            );
        });
    }

    /**
     * Admin surgery on a stuck deal. Both helpers write an audit row and a
     * history entry naming the admin, so nothing happens silently.
     */
    public static function resolveDisputeByAdmin(int $requestId, int $adminId, string $note = ''): void
    {
        Database::transaction(function () use ($requestId, $adminId, $note) {
            $request = self::requestRow($requestId);
            if (!$request || $request['status'] !== 'disputed') {
                throw new RuntimeException('هذا الطلب ليس في حالة خلاف.');
            }
            $back = in_array((string)self::scalar("SELECT from_status FROM request_status_history WHERE request_id=? AND to_status='disputed' ORDER BY id DESC LIMIT 1", [$requestId]), ['accepted', 'negotiating'], true)
                ? 'negotiating'
                : (empty($request['delivered_at']) && !empty($request['agreed_at']) ? 'delivery_pending' : 'negotiating');
            self::exec('UPDATE user_requests SET status=?, updated_at=? WHERE id=?', [$back, now(), $requestId]);
            self::history($requestId, 'disputed', $back, $adminId, 'حلّ الخلاف إدارياً' . ($note !== '' ? ': ' . $note : ''));
            AuditRepo::log($adminId, 'request.dispute_resolved', 'request', $requestId, null, ['to' => $back, 'note' => $note]);

            foreach ([(int)$request['requester_id'], (int)$request['owner_id']] as $uid) {
                NotificationRepo::push(
                    $uid, 'dispute_resolved', 'حُلّ الخلاف على طلبك',
                    'أعادت الإدارة الطلب #' . $requestId . ' إلى مسار الاتفاق. يرجى استكمال الخطوات.',
                    'request.php?id=' . $requestId, 'check-circle'
                );
            }
        });
    }

    public static function cancelByAdmin(int $requestId, int $adminId, string $note = ''): void
    {
        Database::transaction(function () use ($requestId, $adminId, $note) {
            $request = self::requestRow($requestId);
            if (!$request || in_array((string)$request['status'], ['delivered', 'cancelled', 'rejected'], true)) {
                throw new RuntimeException('لا يمكن إلغاء هذا الطلب في حالته الحالية.');
            }
            self::exec("UPDATE user_requests SET status='cancelled', cancelled_reason=?, handled_by=?, handled_at=?, updated_at=? WHERE id=?",
                ['إلغاء إداري' . ($note !== '' ? ': ' . $note : ''), $adminId, now(), now(), $requestId]);
            $productId  = (int)$request['product_id'];
            $otherLive  = (bool)self::scalar(
                "SELECT 1 FROM user_requests WHERE product_id=? AND id<>?
                  AND status IN ('accepted','negotiating','agreed','delivery_pending')",
                [$productId, $requestId]
            );
            if (!$otherLive) {
                ProductRepo::setStatus($productId, 'available');
            }
            self::history($requestId, (string)$request['status'], 'cancelled', $adminId, 'إلغاء إداري' . ($note !== '' ? ': ' . $note : ''));
            AuditRepo::log($adminId, 'request.cancelled_by_admin', 'request', $requestId, null, ['note' => $note, 'from' => $request['status']]);

            foreach ([(int)$request['requester_id'], (int)$request['owner_id']] as $uid) {
                NotificationRepo::push(
                    $uid, 'request_cancelled', 'أُلغيت العملية',
                    'ألغت الإدارة الطلب #' . $requestId . '. للاستفسار راجع البلاغات أو تواصل مع الدعم.',
                    'request.php?id=' . $requestId, 'ban'
                );
            }
        });
    }

    public static function accept(int $requestId, int $actorId, array $opts = []): array
    {
        return Database::transaction(function () use ($requestId, $actorId, $opts) {
            $request = self::requestRow($requestId);
            if (!$request) {
                throw new RuntimeException('الطلب غير موجود.');
            }
            if ($request['status'] !== 'awaiting_owner') {
                throw new RuntimeException('لا يمكن القبول إلا لطلب بانتظار الرد.');
            }

            $product = self::one('SELECT * FROM products WHERE id=?', [(int)$request['product_id']]);
            if (!$product) {
                throw new RuntimeException('المنتج غير موجود.');
            }
            if ($product['status'] !== 'available') {
                throw new RuntimeException('هذا المنتج لم يعد متاحاً — هناك عملية أخرى عليه.');
            }

            $assocId  = $request['request_type'] === 'donate' ? (int)($request['association_id'] ?? 0) : 0;
            $ownerIsParty = (int)$request['owner_id'] === $actorId || self::associationRepOf($request, $actorId);
            if (!$ownerIsParty) {
                throw new RuntimeException($assocId > 0
                    ? 'قبول التبرّع متاح لصاحب المنتج أو ممثل الجمعية المستفيدة فقط.'
                    : 'قبول الطلب متاح لصاحب المنتج فقط.');
            }

            // The party that speaks for the product side: the association rep for a
            // targeted donation (acting in its name), otherwise the owner.
            $ownerPartyId = (int)$request['owner_id'];
            if ($assocId > 0) {
                $repId = (int)self::scalar('SELECT owner_user_id FROM associations WHERE id=?', [$assocId]);
                if ($repId > 0) {
                    $ownerPartyId = $repId;
                }
            }

            // Thread participants: owner ↔ requester, plus the association rep for
            // targeted donations (never a beneficiary, never a random member).
            $convId = ChatRepo::openForRequest(
                $requestId,
                $ownerPartyId,
                (int)$request['requester_id'],
                (int)$request['product_id'],
                $assocId
            );

            /* Accepting opens the conversation, and the deal is then, by
               default, «جاري الاتفاق» — the two sides are talking. The old
               'accepted' stop existed only to say "waiting for a first
               confirmation"; with the negotiation step now the default, the
               progress bar lands straight on step 3 instead of stalling on a
               state that looked identical to the visitor. 'accepted' is still
               honoured everywhere (old rows, and confirmAgreement below). */
            self::exec(
                "UPDATE user_requests
                    SET status='negotiating', handled_by=?, handled_at=?, conversation_id=?, updated_at=?
                  WHERE id=? AND status='awaiting_owner'",
                [$actorId, now(), $convId, now(), $requestId]
            );
            ProductRepo::setStatus((int)$request['product_id'], 'reserved');
            /* Two rows, not one: the progress bar stamps each step from the
               history's to_status, so «قبول الطلب» and «الاتفاق» each get their
               real moment instead of one state silently swallowing the other. */
            self::history($requestId, 'awaiting_owner', 'accepted', $actorId, $assocId > 0 ? 'قبول التبرّع' : 'قبول الطلب');
            self::history($requestId, 'accepted', 'negotiating', $actorId, 'فُتحت المحادثة — الاتفاق جارٍ');

            // Competing requests on the same product are politely closed.
            $competing = self::q(
                "SELECT id, requester_id FROM user_requests
                  WHERE product_id=? AND id<>? AND status='awaiting_owner'",
                [(int)$request['product_id'], $requestId]
            );
            foreach ($competing as $c) {
                self::exec(
                    "UPDATE user_requests SET status='rejected', reject_reason=?, handled_by=?, handled_at=?, updated_at=? WHERE id=?",
                    ['تم قبول طلب آخر على المنتج', $actorId, now(), now(), (int)$c['id']]
                );
                self::history((int)$c['id'], 'awaiting_owner', 'rejected', $actorId, 'تم قبول طلب آخر');
                NotificationRepo::push(
                    (int)$c['requester_id'],
                    'request_rejected',
                    'تم قبول طلب آخر',
                    'قُبل طلب آخر على «' . excerpt((string)$product['title'], 50) . '» — نأمل أن تجد ما يناسبك.',
                    'my_requests.php?tab=outgoing',
                    'ban'
                );
            }

            if ((int)$request['requester_id'] !== $actorId) {
                NotificationRepo::push(
                    (int)$request['requester_id'],
                    'request_accepted',
                    'تم قبول طلبك',
                    'قبل ' . (string)self::scalar('SELECT full_name FROM users WHERE id=?', [$actorId])
                        . ' طلبك على «' . excerpt((string)$product['title'], 50) . '» — افتح المحادثة للاتفاق على التفاصيل.',
                    'chat.php?c=' . $convId,
                    'check-circle'
                );
            }
            if ((int)$request['owner_id'] !== $actorId) {
                NotificationRepo::push(
                    (int)$request['owner_id'],
                    'request_accepted',
                    'قُبل تبرّعك',
                    'قبلت جمعية «' . (string)self::scalar('SELECT name FROM associations WHERE id=?', [$assocId])
                        . '» تبرّعك «' . excerpt((string)$product['title'], 50) . '» — تابع الاتفاق في المحادثة.',
                    'chat.php?c=' . $convId,
                    'handshake'
                );
            }

            ActivityRepo::log($actorId, 'request.accepted', 'request', $requestId, ['product' => (int)$request['product_id']]);
            return ['conversation_id' => $convId, 'status' => 'accepted'];
        });
    }

    public static function reject(int $requestId, int $actorId, ?string $reason = null): array
    {
        return Database::transaction(function () use ($requestId, $actorId, $reason) {
            $request = self::requestRow($requestId);
            if (!$request) {
                throw new RuntimeException('الطلب غير موجود.');
            }
            if ((int)$request['owner_id'] !== $actorId && !Auth::isAdmin()) {
                throw new RuntimeException('رفض الطلب متاح لصاحب المنتج فقط.');
            }
            if ($request['status'] !== 'awaiting_owner') {
                throw new RuntimeException('يمكن الرفض فقط لطلب بانتظار الرد.');
            }
            self::exec(
                "UPDATE user_requests
                    SET status='rejected', reject_reason=?, handled_by=?, handled_at=?, updated_at=?
                  WHERE id=?",
                [$reason, $actorId, now(), now(), $requestId]
            );
            self::history($requestId, 'awaiting_owner', 'rejected', $actorId, $reason);

            $title = (string)self::scalar('SELECT title FROM products WHERE id=?', [(int)$request['product_id']]);
            NotificationRepo::push(
                (int)$request['requester_id'],
                'request_rejected',
                'تم رفض طلبك',
                'اعتذر صاحب المنتج عن طلبك على «' . excerpt($title, 50) . '»'
                    . ($reason ? ' — السبب: ' . excerpt($reason, 90) : '.'),
                'my_requests.php?tab=outgoing&focus=' . $requestId,
                'ban'
            );
            ActivityRepo::log($actorId, 'request.rejected', 'request', $requestId, ['reason' => $reason]);
            return ['status' => 'rejected'];
        });
    }

    public static function cancel(int $requestId, int $actorId, ?string $reason = null): array
    {
        return Database::transaction(function () use ($requestId, $actorId, $reason) {
            $request = self::requestRow($requestId);
            if (!$request) {
                throw new RuntimeException('الطلب غير موجود.');
            }
            if (!in_array($actorId, [(int)$request['requester_id'], (int)$request['owner_id']], true) && !Auth::isAdmin()) {
                throw new RuntimeException('لا تملك صلاحية إلغاء هذا الطلب.');
            }
            if (request_is_closed((string)$request['status']) || $request['status'] === 'delivered') {
                throw new RuntimeException('الطلب مغلق بالفعل.');
            }
            $from = (string)$request['status'];
            self::exec(
                "UPDATE user_requests SET status='cancelled', cancelled_reason=?, updated_at=? WHERE id=?",
                [$reason, now(), $requestId]
            );
            self::history($requestId, $from, 'cancelled', $actorId, $reason);

            // A cancelled deal frees the product again.
            $productId = (int)$request['product_id'];
            $otherLive = (bool)self::scalar(
                "SELECT 1 FROM user_requests WHERE product_id=? AND id<>?
                  AND status IN ('accepted','negotiating','agreed','delivery_pending')",
                [$productId, $requestId]
            );
            if (!$otherLive) {
                ProductRepo::setStatus($productId, 'available');
            }

            foreach (array_unique([(int)$request['requester_id'], (int)$request['owner_id']]) as $uid) {
                if ($uid === $actorId) {
                    continue;
                }
                NotificationRepo::push(
                    $uid,
                    'request_cancelled',
                    'تم إلغاء الطلب',
                    'أُلغي الطلب #' . $requestId . ($reason ? ' — ' . excerpt($reason, 90) : '.'),
                    'my_requests.php?focus=' . $requestId,
                    'x'
                );
            }
            ActivityRepo::log($actorId, 'request.cancelled', 'request', $requestId, ['reason' => $reason]);
            return ['status' => 'cancelled'];
        });
    }

    /* ── agreement (two-sided) ───────────────────────────────────────────── */

    public static function confirmAgreement(int $requestId, int $actorId, array $d): array
    {
        return Database::transaction(function () use ($requestId, $actorId, $d) {
            $request = self::requestRow($requestId);
            if (!$request) {
                throw new RuntimeException('الطلب غير موجود.');
            }
            $role = self::partyRole($request, $actorId);
            if ($role === null) {
                throw new RuntimeException('تأكيد الاتفاق متاح لطرفي الطلب فقط.');
            }
            if (!in_array((string)$request['status'], ['accepted', 'negotiating', 'agreed'], true)) {
                throw new RuntimeException('لا يمكن تأكيد الاتفاق في هذه المرحلة.');
            }

            $summary = trim((string)($d['summary'] ?? ''));
            $place   = trim((string)($d['place'] ?? ''));
            $when    = trim((string)($d['when'] ?? ''));
            if ($summary === '') {
                $summary = (string)($request['agreement_summary'] ?? '');
            }
            if (mb_strlen($summary) < 5) {
                throw new RuntimeException('اكتب ملخص الاتفاق قبل التأكيد.');
            }

            // Upsert this party's confirmation, keeping the shared summary in sync.
            $existing = self::one('SELECT * FROM request_agreements WHERE request_id=? AND user_id=?', [$requestId, $actorId]);
            if ($existing) {
                self::exec(
                    'UPDATE request_agreements
                        SET role=?, summary=?, place=?, when_note=?, confirmed_at=?, revoked_at=NULL, updated_at=?
                      WHERE id=?',
                    [$role, $summary, $place ?: null, $when ?: null, now(), now(), (int)$existing['id']]
                );
            } else {
                self::exec(
                    'INSERT INTO request_agreements (request_id, user_id, role, summary, place, when_note, confirmed_at, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?)',
                    [$requestId, $actorId, $role, $summary, $place ?: null, $when ?: null, now(), now(), now()]
                );
            }

            // First confirmation moves the request out of "negotiating".
            if ($request['status'] === 'accepted') {
                self::exec("UPDATE user_requests SET status='negotiating', updated_at=? WHERE id=?", [now(), $requestId]);
                self::history($requestId, 'accepted', 'negotiating', $actorId, 'بدء الاتفاق داخل المحادثة');
            }

            $parties = self::expectedParties($request);
            $confirmed = self::q(
                'SELECT user_id FROM request_agreements WHERE request_id=? AND confirmed_at IS NOT NULL AND revoked_at IS NULL',
                [$requestId]
            );
            $confirmedIds = array_map(static fn ($r) => (int)$r['user_id'], $confirmed);
            $allDone = empty(array_diff($parties, $confirmedIds));

            // Notify the other side that they still need to confirm.
            foreach (array_diff($parties, [$actorId]) as $uid) {
                $stillNeeded = !in_array($uid, $confirmedIds, true);
                NotificationRepo::push(
                    $uid,
                    $stillNeeded ? 'agreement_waiting' : 'agreement_confirmed',
                    $stillNeeded ? 'بانتظار تأكيدك على الاتفاق' : 'أكّد الطرف الآخر الاتفاق',
                    'الطلب #' . $requestId . ' — ' . excerpt($summary, 120),
                    'request.php?id=' . $requestId,
                    'handshake'
                );
            }

            if ($allDone) {
                self::exec(
                    "UPDATE user_requests
                        SET status='agreed', agreed_at=?, agreement_summary=?, agreement_place=?, agreement_when=?, updated_at=?
                      WHERE id=?",
                    [now(), $summary, $place ?: null, $when ?: null, now(), $requestId]
                );
                self::history($requestId, (string)$request['status'] === 'accepted' ? 'negotiating' : (string)$request['status'], 'agreed', $actorId, 'تأكيد الطرفين على الاتفاق');
                foreach ($parties as $uid) {
                    NotificationRepo::push(
                        $uid,
                        'agreement_reached',
                        'تم الاتفاق على العملية',
                        'الطلب #' . $requestId . ' — ' . excerpt($summary, 110) . '. الخطوة التالية: تسجيل بدء التسليم.',
                        'request.php?id=' . $requestId,
                        'handshake'
                    );
                }
                ActivityRepo::log($actorId, 'request.agreed', 'request', $requestId);
            }

            return [
                'status'      => $allDone ? 'agreed' : 'negotiating',
                'confirmed'   => $confirmedIds,
                'needed'      => $parties,
                'all'         => $allDone,
            ];
        });
    }

    /* ── delivery (two-sided) ────────────────────────────────────────────── */

    public static function startDelivery(int $requestId, int $actorId): array
    {
        return Database::transaction(function () use ($requestId, $actorId) {
            $request = self::requestRow($requestId);
            if (!$request) {
                throw new RuntimeException('الطلب غير موجود.');
            }
            $role = self::partyRole($request, $actorId);
            if ($role === null) {
                throw new RuntimeException('هذا الإجراء متاح لطرفي الطلب فقط.');
            }
            if ($request['status'] !== 'agreed') {
                throw new RuntimeException('لا يمكن البدء بالتسليم قبل تأكيد الاتفاق من الطرفين.');
            }
            self::exec("UPDATE user_requests SET status='delivery_pending', updated_at=? WHERE id=?", [now(), $requestId]);
            self::history($requestId, 'agreed', 'delivery_pending', $actorId, 'بدء تسليم المنتج');

            foreach (self::expectedParties($request) as $uid) {
                NotificationRepo::push(
                    $uid,
                    'delivery_started',
                    'التسليم جارٍ',
                    'الطلب #' . $requestId . ' — يُرجى تأكيد الاستلام/التسليم بعد إتمامه من كلا الطرفين.',
                    'request.php?id=' . $requestId,
                    'truck'
                );
            }
            ActivityRepo::log($actorId, 'request.delivery_started', 'request', $requestId);
            return ['status' => 'delivery_pending'];
        });
    }

    public static function confirmDelivery(int $requestId, int $actorId, ?string $note = null): array
    {
        return Database::transaction(function () use ($requestId, $actorId, $note) {
            $request = self::requestRow($requestId);
            if (!$request) {
                throw new RuntimeException('الطلب غير موجود.');
            }
            $role = self::partyRole($request, $actorId);
            if ($role === null) {
                throw new RuntimeException('تأكيد التسليم متاح لطرفي الطلب فقط.');
            }
            if ($request['status'] !== 'delivery_pending') {
                throw new RuntimeException('لا يمكن التأكيد إلا بعد تسجيل بدء التسليم.');
            }

            $existing = self::one('SELECT * FROM request_deliveries WHERE request_id=? AND user_id=?', [$requestId, $actorId]);
            if ($existing) {
                self::exec('UPDATE request_deliveries SET confirmed_at=?, note=? WHERE id=?', [now(), $note, (int)$existing['id']]);
            } else {
                self::exec(
                    'INSERT INTO request_deliveries (request_id, user_id, role, confirmed_at, note) VALUES (?,?,?,?,?)',
                    [$requestId, $actorId, $role, now(), $note]
                );
            }

            $parties = self::expectedParties($request);
            $done = array_map(
                static fn ($r) => (int)$r['user_id'],
                self::q('SELECT user_id FROM request_deliveries WHERE request_id=? AND confirmed_at IS NOT NULL', [$requestId])
            );
            $allDone = empty(array_diff($parties, $done));

            // Completion is news for both sides (including whoever confirmed last).
            $recipients = $allDone ? $parties : array_diff($parties, [$actorId]);
            foreach ($recipients as $uid) {
                NotificationRepo::push(
                    $uid,
                    $allDone ? 'delivery_completed' : 'delivery_confirm_needed',
                    $allDone ? 'اكتملت العملية' : 'بانتظار تأكيد الاستلام',
                    $allDone
                        ? 'الطلب #' . $requestId . ' — تم تأكيد التسليم من الطرفين. شكراً لك!'
                        : 'الطلب #' . $requestId . ' — أكّد الطرف الآخر التسليم، نحتاج تأكيدك أيضاً.',
                    'request.php?id=' . $requestId,
                    $allDone ? 'check' : 'truck'
                );
            }

            if ($allDone) {
                self::exec(
                    "UPDATE user_requests SET status='delivered', delivered_at=?, completed_at=?, updated_at=? WHERE id=?",
                    [now(), now(), now(), $requestId]
                );
                self::history($requestId, 'delivery_pending', 'delivered', $actorId, 'تأكيد الاستلام من الطرفين');
                ProductRepo::setStatus((int)$request['product_id'], 'completed');
                if ($request['request_type'] === 'donate') {
                    // Delivery to the association is documented on the donation row
                    // so the charity panel can then distribute it to a beneficiary.
                    $assocOrNull = (int)($request['association_id'] ?? 0) ?: null;
                    self::exec(
                        'UPDATE donations
                            SET request_id=?, status=?, received_at=?,
                                association_id=COALESCE(association_id, ?), updated_at=?
                          WHERE product_id=? AND request_id IS NULL AND status IN (?,?)',
                        [$requestId, 'received', now(), $assocOrNull, now(),
                         (int)$request['product_id'], 'offered', 'claimed']
                    );
                }
                ActivityRepo::log($actorId, 'request.delivered', 'request', $requestId);
            }

            return [
                'status'    => $allDone ? 'delivered' : 'delivery_pending',
                'confirmed' => $done,
                'needed'    => $parties,
                'all'       => $allDone,
            ];
        });
    }

    /* ── disputes ────────────────────────────────────────────────────────── */

    public static function dispute(int $requestId, int $actorId, string $reason): array
    {
        return Database::transaction(function () use ($requestId, $actorId, $reason) {
            $request = self::requestRow($requestId);
            if (!$request) {
                throw new RuntimeException('الطلب غير موجود.');
            }
            $role = self::partyRole($request, $actorId);
            if ($role === null && !Auth::isAdmin()) {
                throw new RuntimeException('الإبلاغ عن مشكلة متاح لطرفي الطلب فقط.');
            }
            if (in_array((string)$request['status'], ['delivered', 'rejected', 'cancelled'], true)) {
                throw new RuntimeException('لا يمكن فتح خلاف على طلب مغلق.');
            }
            if (mb_strlen(trim($reason)) < 5) {
                throw new RuntimeException('اشرح المشكلة باختصار (5 أحرف على الأقل).');
            }
            $from = (string)$request['status'];
            self::exec(
                "UPDATE user_requests SET status='disputed', dispute_reason=?, updated_at=? WHERE id=?",
                [$reason, now(), $requestId]
            );
            self::history($requestId, $from, 'disputed', $actorId, $reason);

            // A dispute also raises a moderation report so admins see it at once.
            Repo::exec(
                'INSERT INTO reports (reporter_id, entity_type, entity_id, reason, details, status, created_at)
                 VALUES (?,?,?,?,?,?,?)',
                [$actorId, 'request', $requestId, 'dispute', excerpt($reason, 400), 'open', now()]
            );

            foreach (self::expectedParties($request) as $uid) {
                NotificationRepo::push(
                    $uid,
                    'request_disputed',
                    'يوجد خلاف على الطلب',
                    'الطلب #' . $requestId . ' — ' . excerpt($reason, 110) . '. الإدارة ستراجع الحالة.',
                    'request.php?id=' . $requestId,
                    'alert-triangle'
                );
            }
            foreach (self::q("SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE r.code IN ('admin','super_admin')") as $adm) {
                NotificationRepo::push((int)$adm['user_id'], 'report_new', 'بلاغ خلاف جديد', 'الطلب #' . $requestId . ': ' . excerpt($reason, 100), 'admin/reports.php?status=open', 'flag');
            }
            ActivityRepo::log($actorId, 'request.disputed', 'request', $requestId, ['reason' => $reason]);
            return ['status' => 'disputed'];
        });
    }

    /* ── reads ───────────────────────────────────────────────────────────── */

    public static function historyFor(int $requestId): array
    {
        return self::q(
            'SELECT h.*, u.full_name AS actor_name
               FROM request_status_history h
               LEFT JOIN users u ON u.id=h.actor_id
              WHERE h.request_id=? ORDER BY h.id ASC',
            [$requestId]
        );
    }

    public static function agreementsFor(int $requestId): array
    {
        return self::q(
            'SELECT a.*, u.full_name
               FROM request_agreements a JOIN users u ON u.id=a.user_id
              WHERE a.request_id=? ORDER BY a.id',
            [$requestId]
        );
    }

    public static function deliveriesFor(int $requestId): array
    {
        return self::q(
            'SELECT d.*, u.full_name
               FROM request_deliveries d JOIN users u ON u.id=d.user_id
              WHERE d.request_id=? ORDER BY d.id',
            [$requestId]
        );
    }

    /**
     * Who must confirm agreement and delivery.
     * For a targeted donation the association's representative is a party
     * instead of the individual donor — a beneficiary never is.
     */
    public static function expectedParties(array $request): array
    {
        $isDonation = ($request['request_type'] ?? '') === 'donate';
        $assocId    = $isDonation ? (int)($request['association_id'] ?? 0) : 0;
        $repId      = $assocId > 0 ? (int)self::scalar('SELECT owner_user_id FROM associations WHERE id=?', [$assocId]) : 0;

        if ($repId > 0) {
            return array_values(array_unique([(int)$request['requester_id'], $repId]));
        }
        return array_values(array_unique([(int)$request['requester_id'], (int)$request['owner_id']]));
    }

    /**
     * The role this user holds in the deal, or null when they hold none.
     *
     * Derived from expectedParties() so there is ONE definition of "party" in
     * the system. The old version re-implemented the rule and got the targeted
     * donation backwards: once an association had a representative, the person
     * who merely published the item still fell through to 'owner' and could
     * confirm agreements and deliveries for a deal they were not part of.
     */
    public static function partyRole(array $request, int $userId): ?string
    {
        if (!in_array($userId, self::expectedParties($request), true)) {
            return null;
        }
        if ((int)$request['requester_id'] === $userId) {
            return 'requester';
        }
        // The other side of a targeted donation is the association's rep, not
        // the publisher; otherwise it is the owner.
        $isTargeted = ($request['request_type'] ?? '') === 'donate'
            && (int)($request['association_id'] ?? 0) > 0;
        return $isTargeted ? 'association_rep' : 'owner';
    }

    /** Request + the association a donation belongs to (used for permissions). */
    public static function requestRow(int $requestId): ?array
    {
        return self::one(
            "SELECT r.*, p.title AS product_title, p.association_id AS product_association_id,
                    p.donation_scope AS product_donation_scope,
                    COALESCE(r.association_id, p.target_association_id,
                             CASE WHEN p.donation_scope = 'targeted' THEN p.association_id END) AS association_id
               FROM user_requests r JOIN products p ON p.id=r.product_id
              WHERE r.id=? LIMIT 1",
            [$requestId]
        );
    }

    private static function history(int $requestId, ?string $from, string $to, ?int $actorId, ?string $reason): void
    {
        self::exec(
            'INSERT INTO request_status_history (request_id, from_status, to_status, actor_id, reason, created_at)
             VALUES (?,?,?,?,?,?)',
            [$requestId, $from, $to, $actorId, $reason, now()]
        );
    }
}

/** Test/CLI helper: parties for a request id. */
function qa_parties(int $requestId): array
{
    $row = RequestFlowRepo::requestRow($requestId);
    return $row ? RequestFlowRepo::expectedParties($row) : [];
}
