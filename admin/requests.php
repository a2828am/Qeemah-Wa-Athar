<?php
/**
 * admin/requests.php — oversight of every deal on the platform.
 *
 * Admins can read the full lifecycle of any request (including its private
 * thread, which is opened with an explicit audit entry), settle a disputed
 * deal back into negotiation or cancel it on the parties' behalf. Every
 * write here lands in admin_audit_logs.
 *
 *   ?status=ALL_STATE   ?q=...   ?page=N   ?focus=REQUEST_ID
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/components.php';

Gate::requireAdmin();
$adminId = (int)Auth::id();
$status  = (string)(get_q('status', '') ?? '');
$q       = trim((string)(get_q('q', '') ?? ''));
$page    = max(1, get_int('page', 1) ?? 1);
$focus   = get_int('focus', 0) ?? 0;
$meta    = request_status_meta();
$states  = array_keys($meta);

if ($status !== '' && !in_array($status, $states, true)) {
    $status = '';
}

/* ── admin actions ───────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $requestId = post_int('request_id', 0) ?? 0;
    $action    = (string)post('action', '');
    try {
        if (!$requestId || !RequestRepo::find($requestId, $adminId)) {
            throw new RuntimeException('الطلب غير موجود.');
        }
        switch ($action) {
            case 'resolve_dispute':
                RequestFlowRepo::resolveDisputeByAdmin($requestId, $adminId, (string)post('note', ''));
                flash('success', 'أُعيد الطلب إلى مسار الاتفاق بعد معالجة الخلاف.');
                break;
            case 'cancel':
                RequestFlowRepo::cancelByAdmin($requestId, $adminId, (string)post('note', ''));
                flash('success', 'أُلغي الطلب وأُبلغ الطرفان.');
                break;
            case 'audit_thread':
                $convId = (int)RequestFlowRepo::scalar('SELECT conversation_id FROM user_requests WHERE id=?', [$requestId]);
                if ($convId <= 0) {
                    throw new RuntimeException('لا توجد محادثة لهذا الطلب.');
                }
                AuditRepo::log($adminId, 'chat.audited', 'conversation', $convId, null, ['request_id' => $requestId]);
                redirect('chat.php?c=' . $convId . '&audit=1');
            default:
                throw new RuntimeException('إجراء غير معروف.');
        }
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/requests.php?status=' . urlencode($status) . ($focus ? '&focus=' . $focus : ''));
}

/* ── list ────────────────────────────────────────────────────────────────── */
$where  = ['1=1'];
$params = [];
if ($status !== '') {
    $where[]  = 'r.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = '(r.id = ? OR p.title LIKE ? OR req.full_name LIKE ? OR own.full_name LIKE ?)';
    array_push($params, ctype_digit($q) ? (int)$q : -1, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
}
$sqlWhere = implode(' AND ', $where);
$total    = (int)Repo::scalar(
    "SELECT COUNT(*) FROM user_requests r
       JOIN products p ON p.id=r.product_id
       JOIN users req ON req.id=r.requester_id
       JOIN users own ON own.id=r.owner_id
      WHERE $sqlWhere",
    $params
);
$pager = paginate($total, 15, $page);
$rows  = Repo::q(
    "SELECT r.*, p.title AS product_title, p.status AS product_status, p.city,
            req.full_name AS requester_name, own.full_name AS owner_name,
            (SELECT COUNT(*) FROM messages m WHERE m.conversation_id=r.conversation_id) AS message_count,
            (SELECT COUNT(*) FROM request_agreements a WHERE a.request_id=r.id AND a.confirmed_at IS NOT NULL) AS agrees_n,
            (SELECT COUNT(*) FROM request_deliveries d WHERE d.request_id=r.id AND d.confirmed_at IS NOT NULL) AS delivers_n
       FROM user_requests r
       JOIN products p ON p.id=r.product_id
       JOIN users req ON req.id=r.requester_id
       JOIN users own ON own.id=r.owner_id
      WHERE $sqlWhere
      ORDER BY CASE r.status WHEN 'disputed' THEN 0 WHEN 'awaiting_owner' THEN 1 ELSE 2 END, r.updated_at DESC
      LIMIT {$pager['per_page']} OFFSET {$pager['offset']}",
    $params
);
/* who must confirm each deal — one query, reused in the table */
$partyCount = [];
foreach (Repo::q("SELECT id, request_type, association_id FROM user_requests") as $row) {
    $full = RequestFlowRepo::requestRow((int)$row['id']);
    $partyCount[(int)$row['id']] = $full ? count(RequestFlowRepo::expectedParties($full)) : 2;
}

$counts = array_fill_keys($states, 0);
foreach (Repo::q('SELECT status, COUNT(*) n FROM user_requests GROUP BY status') as $row) {
    $counts[(string)$row['status']] = (int)$row['n'];
}

$pageTitle   = 'إدارة الطلبات | قيمة وأثر';
$currentPage = 'admin';
$adminPage   = 'requests';
$pageHead    = [
    'title'    => icon('route', 28) . ' إدارة الطلبات',
    'subtitle' => 'كل العمليات على المنصة: الحالة، المحادثة، تأكيدات الاتفاق والتسليم، والخلافات.',
    'actions'  => '<a class="btn btn-outline btn-sm" href="' . e(url('admin/reports.php')) . '">' . icon('flag', 15) . 'البلاغات</a>',
];
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_nav.php';
?>

<div class="container section" style="padding-top:24px">
  <div class="tabs mb-16">
    <a class="tab<?= $status === '' ? ' active' : '' ?>" href="<?= e(url('admin/requests.php')) ?>">الكل <span class="text-xs">(<?= array_sum($counts) ?>)</span></a>
    <?php foreach ($meta as $key => $m): if ((int)$counts[$key] === 0) { continue; } ?>
      <a class="tab<?= $status === $key ? ' active' : '' ?>" href="<?= e(url('admin/requests.php?status=' . $key)) ?>">
        <?= icon($m['icon'], 14) ?><?= e($m['label']) ?> <span class="text-xs">(<?= (int)$counts[$key] ?>)</span>
      </a>
    <?php endforeach; ?>
  </div>

  <form class="filter-bar mb-16" method="get" action="<?= e(url('admin/requests.php')) ?>">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <div class="field grow" style="margin:0">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="ابحث برقم الطلب أو اسم المنتج أو الطرفين">
    </div>
    <button class="btn btn-primary" type="submit"><?= icon('search', 16) ?>بحث</button>
    <?php if ($q !== ''): ?><a class="btn btn-ghost" href="<?= e(url('admin/requests.php?status=' . $status)) ?>">مسح</a><?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="panel"><?= empty_state('لا توجد طلبات مطابقة', 'جرّب حالة أخرى أو كلمة بحث مختلفة.', 'inbox', 'لوحة الإدارة', url('admin/dashboard.php')) ?></div>
  <?php else: ?>
    <div class="table-wrap admin-table">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>المنتج</th>
            <th>الطرفان</th>
            <th>الحالة</th>
            <th>الاتفاق / التسليم</th>
            <th>المحادثة</th>
            <th>التحديث</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
              $st = (string)$r['status'];
              $m  = $meta[$st] ?? ['label' => $st, 'class' => '', 'icon' => 'clock'];
              $needed = max(2, (int)($partyCount[(int)$r['id']] ?? 2));
          ?>
            <tr class="<?= $focus === (int)$r['id'] ? 'row-focus' : '' ?>">
              <td data-label="#">#<?= (int)$r['id'] ?></td>
              <td data-label="المنتج">
                <b><?= e(excerpt((string)$r['product_title'], 42)) ?></b>
                <div class="text-xs text-muted"><?= e((string)$r['city']) ?> · <?= e(transaction_meta()[(string)$r['request_type']]['label'] ?? (string)$r['request_type']) ?></div>
              </td>
              <td data-label="الطرفان">
                <div class="text-sm"><?= icon('user', 12) ?> <?= e((string)$r['requester_name']) ?></div>
                <div class="text-sm"><?= icon('user', 12) ?> <?= e((string)$r['owner_name']) ?></div>
              </td>
              <td data-label="الحالة"><span class="badge <?= e($m['class']) ?>"><?= icon($m['icon'], 12) ?><?= e($m['label']) ?></span></td>
              <td data-label="التأكيدات">
                <span class="text-xs"><?= icon('handshake', 12) ?> <?= (int)$r['agrees_n'] ?>/<?= $needed ?></span><br>
                <span class="text-xs"><?= icon('truck', 12) ?> <?= (int)$r['delivers_n'] ?>/<?= $needed ?></span>
              </td>
              <td data-label="المحادثة">
                <?php if ((int)$r['conversation_id']): ?>
                  <span class="badge badge-soft"><?= icon('message-circle', 13) ?><?= (int)$r['message_count'] ?> رسالة</span>
                <?php else: ?>
                  <span class="text-xs text-muted">—</span>
                <?php endif; ?>
              </td>
              <td data-label="التحديث"><span class="text-xs text-muted"><?= e(date('Y-m-d H:i', strtotime((string)$r['updated_at']))) ?></span></td>
              <td data-label="">
                <div class="row-actions">
                  <a class="btn btn-outline btn-sm" href="<?= e(url('request.php?id=' . (int)$r['id'])) ?>"><?= icon('eye', 14) ?>المسار</a>
                  <?php if ((int)$r['conversation_id']): ?>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="audit_thread">
                      <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-ghost btn-sm" type="submit" title="فتح المحادثة (يُسجَّل في التدقيق)"><?= icon('shield-check', 14) ?>تفتيش</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($st === 'disputed'): ?>
                    <form method="post" data-confirm="إعادة الطلب إلى مسار الاتفاق بعد حل الخلاف؟">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="resolve_dispute">
                      <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="note" value="حُلّ الخلاف من الإدارة">
                      <button class="btn btn-primary btn-sm" type="submit"><?= icon('check-circle', 14) ?>حلّ الخلاف</button>
                    </form>
                  <?php endif; ?>
                  <?php if (!in_array($st, ['delivered', 'rejected', 'cancelled'], true)): ?>
                    <form method="post" data-confirm="إلغاء هذا الطلب نيابة عن الطرفين؟ سيُبلَّغ الطرفان.">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="cancel">
                      <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                      <input type="hidden" name="note" value="أُلغي بقرار الإدارة">
                      <button class="btn btn-ghost btn-sm btn-danger-text" type="submit"><?= icon('ban', 14) ?>إلغاء</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <!-- Mobile cards — same data, readable on a phone -->
    <div class="req-cards">
      <?php foreach ($rows as $r):
          $st = (string)$r['status'];
          $m  = $meta[$st] ?? ['label' => $st, 'class' => '', 'icon' => 'clock'];
          $needed = max(2, (int)($partyCount[(int)$r['id']] ?? 2));
      ?>
        <div class="req-card<?= $focus === (int)$r['id'] ? ' row-focus' : '' ?>">
          <div class="req-top">
            <div class="req-title">#<?= (int)$r['id'] ?> — <?= e(excerpt((string)$r['product_title'], 44)) ?></div>
            <span class="badge <?= e($m['class']) ?>"><?= icon($m['icon'], 12) ?><?= e($m['label']) ?></span>
          </div>
          <div class="req-meta">
            <span><?= icon('user', 13) ?><?= e((string)$r['requester_name']) ?></span>
            <span><?= icon('user', 13) ?><?= e((string)$r['owner_name']) ?></span>
            <span><?= icon('pin', 13) ?><?= e((string)$r['city']) ?></span>
            <span><?= icon('handshake', 13) ?><?= (int)$r['agrees_n'] ?>/<?= $needed ?></span>
            <span><?= icon('truck', 13) ?><?= (int)$r['delivers_n'] ?>/<?= $needed ?></span>
            <?php if ((int)$r['conversation_id']): ?>
              <span><?= icon('message-circle', 13) ?><?= (int)$r['message_count'] ?></span>
            <?php endif; ?>
          </div>
          <div class="row-actions">
            <a class="btn btn-outline btn-sm" href="<?= e(url('request.php?id=' . (int)$r['id'])) ?>"><?= icon('eye', 14) ?>المسار</a>
            <?php if ((int)$r['conversation_id']): ?>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="audit_thread">
                <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit"><?= icon('shield-check', 14) ?>تفتيش</button>
              </form>
            <?php endif; ?>
            <?php if ($st === 'disputed'): ?>
              <form method="post" data-confirm="إعادة الطلب إلى مسار الاتفاق بعد حل الخلاف؟">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resolve_dispute">
                <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-primary btn-sm" type="submit"><?= icon('check-circle', 14) ?>حلّ الخلاف</button>
              </form>
            <?php elseif (!in_array($st, ['delivered', 'rejected', 'cancelled'], true)): ?>
              <form method="post" data-confirm="إلغاء هذا الطلب نيابة عن الطرفين؟">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-ghost btn-sm btn-danger-text" type="submit"><?= icon('ban', 14) ?>إلغاء</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?= pagination_links($pager, array_filter(['status' => $status, 'q' => $q])) ?>
  <?php endif; ?>

  <p class="text-xs text-muted mt-16">
    <?= icon('lock', 13) ?>
    المحادثات خاصة بالطرفين؛ يفتحها الإداري للتحقيق فقط، وكل فتح يُسجَّل في سجل التدقيق مع هوية الفاتح والوقت.
  </p>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
