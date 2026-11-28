<?php
/**
 * admin/users.php — search, filter, suspend/activate and role management.
 * Every mutating action is written to admin_audit_logs (immutable).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/components.php';

Gate::requireAdmin();
$me      = Auth::user();
$adminId = (int)$me['id'];

$filters = [
    'q'       => get_q('q', '') ?? '',
    'status'  => get_q('status', '') ?? '',
    'type_id' => get_int('type', 0),
];
$page = max(1, get_int('page', 1) ?? 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)post('action', '');
    $userId = post_int('user_id', 0) ?? 0;
    $target = $userId ? UserRepo::find($userId) : null;

    try {
        if (!$target) {
            throw new RuntimeException('المستخدم غير موجود.');
        }
        if ($userId === $adminId && in_array($action, ['suspend', 'delete'], true)) {
            throw new RuntimeException('لا يمكنك إيقاف حسابك الشخصي.');
        }

        if ($action === 'suspend' || $action === 'activate') {
            $status = $action === 'suspend' ? 'suspended' : 'active';
            UserRepo::setStatus($userId, $status);
            AuditRepo::log($adminId, 'user.' . $action, 'user', $userId, ['status' => $target['status']], ['status' => $status]);
            NotificationRepo::push($userId, 'system', $action === 'suspend' ? 'تم إيقاف حسابك' : 'تم تفعيل حسابك',
                $action === 'suspend' ? 'أوقف فريق الإدارة حسابك مؤقتاً. تواصل معنا للاستفسار.' : 'حسابك أصبح نشطاً من جديد.', 'index.php', 'shield');
            flash('success', $action === 'suspend' ? 'تم إيقاف الحساب.' : 'تم تفعيل الحساب.');
        }
        Auth::refresh();
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/users.php?' . http_build_query(array_filter($filters)));
}

[$users, $pager] = UserRepo::paginate($filters, $page, 15);
$types = UserRepo::userTypes();
$allRoles = Repo::q('SELECT * FROM roles ORDER BY id');
$roleLabels = [];
foreach ($allRoles as $r) {
    $roleLabels[(string)$r['code']] = (string)$r['name_ar'];
}

$pageTitle   = 'إدارة المستخدمين | قيمة وأثر';
$currentPage = 'admin';
$adminPage   = 'users';
$pageHead    = [
    'title'    => icon('users', 28) . ' المستخدمون',
    'subtitle' => 'بحث وإيقاف/تفعيل الحسابات وإدارة الأدوار — كل إجراء يُوثّق في سجل التدقيق.',
];
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_nav.php';
?>

<div class="container section" style="padding-top:24px">
  <form class="filters" method="get" action="<?= e(url('admin/users.php')) ?>">
    <div class="search-field">
      <?= icon('search', 16) ?>
      <input type="search" name="q" value="<?= e((string)$filters['q']) ?>" placeholder="الاسم أو البريد…">
    </div>
    <select name="status">
      <option value="">كل الحالات</option>
      <?php foreach (['active' => 'نشط', 'pending' => 'قيد المراجعة', 'suspended' => 'موقوف'] as $k => $v): ?>
        <option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="type">
      <option value="">كل الأنواع</option>
      <?php foreach ($types as $t): ?>
        <option value="<?= (int)$t['id'] ?>" <?= (int)$filters['type_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= e((string)$t['name_ar']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary btn-sm" type="submit"><?= icon('filter', 15) ?>تصفية</button>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/users.php')) ?>"><?= icon('refresh', 15) ?>إعادة تعيين</a>
  </form>

  <p class="results-count"><?= (int)$pager['total'] ?> مستخدم</p>

  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>المستخدم</th><th>النوع</th><th>المدينة</th><th>التقييم</th><th>الحالة</th><th>انضم</th><th>إجراءات</th></tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u):
            $roles = UserRepo::rolesOf((int)$u['id']);
        ?>
          <tr>
            <td>
              <div class="flex items-center gap-8">
                <span class="avatar avatar-sm"><?= e(avatar_letter((string)$u['full_name'])) ?></span>
                <div>
                  <span class="table-title"><?= e((string)$u['full_name']) ?></span>
                  <span class="table-sub" dir="ltr"><?= e((string)$u['email']) ?></span>
                  <div class="tag-list mt-8">
                    <?php foreach ($roles as $rc): ?>
                      <span class="tag"><?= e($roleLabels[$rc] ?? $rc) ?></span>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </td>
            <td><?= e((string)$u['type_name']) ?></td>
            <td><?= e((string)($u['city'] ?? '—')) ?></td>
            <td><?= (int)$u['rating_count'] > 0 ? number_format((float)$u['rating_avg'], 1) . ' ★' : '—' ?></td>
            <td>
              <span class="status <?= $u['status'] === 'active' ? 'st-accepted' : ($u['status'] === 'suspended' ? 'st-rejected' : 'st-review') ?>">
                <?= $u['status'] === 'active' ? 'نشط' : ($u['status'] === 'suspended' ? 'موقوف' : 'قيد المراجعة') ?>
              </span>
            </td>
            <td><span class="table-sub"><?= e(date('Y-m-d', strtotime((string)$u['created_at']))) ?></span></td>
            <td>
              <div class="row-actions">
                <?php if ($u['status'] === 'suspended'): ?>
                  <form method="post" data-ajax="api/admin_action.php" data-confirm="تفعيل الحساب؟" data-done="reload">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="activate">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <button class="icon-btn" type="submit"><?= icon('user-check', 13) ?>تفعيل</button>
                  </form>
                <?php else: ?>
                  <form method="post" data-ajax="api/admin_action.php" data-confirm="إيقاف الحساب؟ لن يستطيع الدخول بعدها." data-done="reload">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="suspend">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <button class="icon-btn danger" type="submit"><?= icon('ban', 13) ?>إيقاف</button>
                  </form>
                <?php endif; ?>
                <?php if (Auth::isFullAdmin()): ?>
                  <form method="post" class="flex gap-6" action="<?= e(url('api/user_role.php')) ?>" data-ajax="api/user_role.php" data-done="reload">
                    <?= csrf_field() ?>
                    <select name="role_code" required style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font-size:12.5px">
                      <option value="">دور…</option>
                      <?php foreach ($allRoles as $r): ?>
                        <option value="<?= e((string)$r['code']) ?>"><?= e((string)$r['name_ar']) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <button class="icon-btn" type="submit" name="action" value="grant_role"><?= icon('plus', 12) ?></button>
                    <button class="icon-btn danger" type="submit" name="action" value="revoke_role"><?= icon('x', 12) ?></button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (!$users): ?>
    <?= empty_state('لا يوجد مستخدمون مطابقون', 'جرّب تغيير الفلاتر.', 'users') ?>
  <?php endif; ?>

  <?= pagination_links($pager, array_filter(['q' => $filters['q'], 'status' => $filters['status'], 'type' => $filters['type_id']])) ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
