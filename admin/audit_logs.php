<?php
/**
 * admin/audit_logs.php — immutable audit trail (full admins only).
 * Rows are append-only: no update or delete path exists anywhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/components.php';

Gate::requireFullAdmin();
$filters = [
    'action'   => get_q('action', '') ?? '',
    'admin_id' => get_int('admin_id', 0),
];
$page = max(1, get_int('page', 1) ?? 1);

[$rows, $pager] = AuditRepo::paginate($filters, $page, 25);
$admins = Repo::q(
    'SELECT DISTINCT u.id, u.full_name FROM admin_audit_logs a JOIN users u ON u.id=a.admin_id ORDER BY u.full_name'
);
$actions = Repo::q('SELECT DISTINCT action FROM admin_audit_logs ORDER BY action');

$pageTitle   = 'سجل التدقيق | قيمة وأثر';
$currentPage = 'admin';
$adminPage   = 'audit';
$pageHead    = [
    'title'    => icon('shield-check', 28) . ' سجل التدقيق',
    'subtitle' => 'سجل غير قابل للتعديل لكل إجراء إداري: من، ماذا، متى، وبأي تغيير.',
];
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_nav.php';
?>

<div class="container section" style="padding-top:24px">
  <form class="filters mb-16" method="get" action="<?= e(url('admin/audit_logs.php')) ?>">
    <select name="action">
      <option value="">كل الإجراءات</option>
      <?php foreach ($actions as $a): ?>
        <option value="<?= e((string)$a['action']) ?>" <?= $filters['action'] === (string)$a['action'] ? 'selected' : '' ?>><?= e((string)$a['action']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="admin_id">
      <option value="">كل المسؤولين</option>
      <?php foreach ($admins as $a): ?>
        <option value="<?= (int)$a['id'] ?>" <?= (int)$filters['admin_id'] === (int)$a['id'] ? 'selected' : '' ?>><?= e((string)$a['full_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary btn-sm" type="submit"><?= icon('filter', 15) ?>تصفية</button>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/audit_logs.php')) ?>"><?= icon('refresh', 15) ?>إعادة تعيين</a>
    <span class="text-xs text-muted" style="margin-inline-start:auto"><?= icon('lock', 12) ?> السجل للقراءة فقط — لا يمكن تعديله أو حذفه.</span>
  </form>

  <div class="table-wrap">
    <table>
      <thead><tr><th>#</th><th>المسؤول</th><th>الإجراء</th><th>الكيان</th><th>قبل</th><th>بعد</th><th>IP</th><th>الوقت</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><span class="table-sub"><?= (int)$r['id'] ?></span></td>
            <td><span class="table-title"><?= e((string)($r['admin_name'] ?? 'النظام')) ?></span></td>
            <td><span class="badge badge-soft" dir="ltr"><?= e((string)$r['action']) ?></span></td>
            <td><span class="table-sub"><?= e((string)($r['entity_type'] ?? '—')) ?><?= !empty($r['entity_id']) ? ' #' . (int)$r['entity_id'] : '' ?></span></td>
            <td><?php if (!empty($r['before_json'])): ?><div class="audit-json"><?= e((string)$r['before_json']) ?></div><?php else: ?><span class="table-sub">—</span><?php endif; ?></td>
            <td><?php if (!empty($r['after_json'])): ?><div class="audit-json"><?= e((string)$r['after_json']) ?></div><?php else: ?><span class="table-sub">—</span><?php endif; ?></td>
            <td><span class="table-sub" dir="ltr"><?= e((string)($r['ip_address'] ?? '—')) ?></span></td>
            <td><span class="table-sub"><?= e((string)$r['created_at']) ?><br><?= e(time_ago((string)$r['created_at'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (!$rows): ?>
    <?= empty_state('السجل فارغ', 'ستظهر هنا كل إجراءات الإدارة تلقائياً.', 'shield-check') ?>
  <?php endif; ?>

  <?= pagination_links($pager, array_filter($filters)) ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
