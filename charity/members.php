<?php
/**
 * charity/members.php — team management with granular per-member permissions:
 *   campaigns · donations · beneficiaries · members
 * The representative's row cannot be removed; only owners of the "members"
 * capability can edit permissions.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/components.php';

$assoc   = Gate::requireAssociation();
Gate::requireMemberCapability('members');
$assocId = (int)$assoc['id'];
$me      = Auth::user();
$errors  = [];

$roleLabels = [
    'representative' => 'ممثل الجمعية',
    'manager'        => 'مدير',
    'coordinator'    => 'منسّق',
    'viewer'         => 'مشاهد',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)post('action', '');

    try {
        if ($action === 'add') {
            $email = mb_strtolower((string)post('email', ''));
            $user  = $email !== '' ? UserRepo::findByEmail($email) : null;
            if (!$user) {
                throw new RuntimeException('لا يوجد مستخدم مسجّل بهذا البريد. اطلب منه إنشاء حساب أولاً.');
            }
            if ((int)$user['id'] === (int)$me['id']) {
                throw new RuntimeException('أنت عضو بالفعل.');
            }
            $permsIn = [
                'campaigns'     => post('can_campaigns') !== null,
                'donations'     => post('can_donations') !== null,
                'beneficiaries' => post('can_beneficiaries') !== null,
                'members'       => post('can_members') !== null,
            ];
            $role = (string)post('role', 'coordinator');
            if (!isset($roleLabels[$role]) || $role === 'representative') {
                $role = 'coordinator';
            }
            AssociationRepo::addMember($assocId, (int)$user['id'], $role, $permsIn, (int)$me['id']);
            NotificationRepo::push((int)$user['id'], 'system', 'تمت إضافتك إلى جمعية', 'أضافتك «' . (string)$assoc['name'] . '» إلى فريقها.', 'charity/dashboard.php', 'landmark');
            AuditRepo::log(null, 'member.added', 'association', $assocId, null, ['user' => (int)$user['id'], 'role' => $role]);
            flash('success', 'تمت إضافة ' . (string)$user['full_name'] . ' إلى فريق الجمعية.');
            redirect('charity/members.php');
        }

        if ($action === 'update') {
            $memberId = post_int('member_id', 0) ?? 0;
            $row = Repo::q(
                'SELECT * FROM association_members WHERE id=? AND association_id=?',
                [$memberId, $assocId]
            )[0] ?? null;
            if (!$row) {
                throw new RuntimeException('العضو غير موجود.');
            }
            $role = (string)post('role', (string)$row['role']);
            if (!isset($roleLabels[$role])) {
                $role = (string)$row['role'];
            }
            if ((string)$row['role'] === 'representative' && $role !== 'representative') {
                throw new RuntimeException('لا يمكن تغيير دور ممثل الجمعية.');
            }
            AssociationRepo::updateMemberPerms($memberId, $assocId, [
                'campaigns'     => post('can_campaigns') !== null,
                'donations'     => post('can_donations') !== null,
                'beneficiaries' => post('can_beneficiaries') !== null,
                'members'       => post('can_members') !== null,
            ], $role);
            AuditRepo::log(null, 'member.updated', 'association', $assocId, ['role' => $row['role']], ['role' => $role]);
            flash('success', 'تم تحديث صلاحيات العضو.');
            redirect('charity/members.php');
        }

        if ($action === 'remove') {
            $memberId = post_int('member_id', 0) ?? 0;
            AssociationRepo::removeMember($memberId, $assocId);
            AuditRepo::log(null, 'member.removed', 'association', $assocId, null, ['member' => $memberId]);
            flash('success', 'تم إزالة العضو من الجمعية.');
            redirect('charity/members.php');
        }
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
    }
}

$members = AssociationRepo::members($assocId);

$pageTitle   = 'أعضاء الجمعية | قيمة وأثر';
$currentPage = 'charity_panel';
$pageHead    = [
    'title'    => icon('users', 28) . ' أعضاء الجمعية',
    'subtitle' => 'أضف فريقك وحدّد لكل عضو صلاحياته: الحملات، التبرعات، المستفيدون، والأعضاء.',
    'actions'  => '<a class="btn btn-outline btn-sm" href="' . e(url('charity/dashboard.php')) . '">' . icon('dashboard', 15) . 'لوحة الجمعية</a>',
];
require __DIR__ . '/../includes/header.php';
?>

<div class="container section" style="padding-top:24px">
  <?php if ($errors): ?>
    <div class="alert alert-error"><?= icon('alert-circle', 18) ?><div class="grow"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
  <?php endif; ?>

  <div class="two-col">
    <div class="panel">
      <h3><?= icon('users', 18) ?> الفريق <span class="badge badge-soft"><?= count($members) ?></span></h3>
      <div class="table-wrap" style="box-shadow:none;border:0">
        <table>
          <thead><tr><th>العضو</th><th>الدور</th><th>الصلاحيات</th><th>إجراءات</th></tr></thead>
          <tbody>
            <?php foreach ($members as $m): ?>
              <tr>
                <td>
                  <div class="flex items-center gap-8">
                    <span class="avatar avatar-sm"><?= e(avatar_letter((string)$m['full_name'])) ?></span>
                    <div>
                      <span class="table-title"><?= e((string)$m['full_name']) ?></span>
                      <span class="table-sub" dir="ltr"><?= e((string)$m['email']) ?></span>
                    </div>
                  </div>
                </td>
                <td><span class="badge <?= $m['role'] === 'representative' ? 'badge-completed' : 'badge-soft' ?>"><?= e($roleLabels[(string)$m['role']] ?? (string)$m['role']) ?></span></td>
                <td>
                  <div class="tag-list">
                    <?php if ((int)$m['can_campaigns']): ?><span class="tag"><?= icon('megaphone', 11) ?>حملات</span><?php endif; ?>
                    <?php if ((int)$m['can_donations']): ?><span class="tag"><?= icon('hand-heart', 11) ?>تبرعات</span><?php endif; ?>
                    <?php if ((int)$m['can_beneficiaries']): ?><span class="tag"><?= icon('users', 11) ?>مستفيدون</span><?php endif; ?>
                    <?php if ((int)$m['can_members']): ?><span class="tag"><?= icon('user-check', 11) ?>أعضاء</span><?php endif; ?>
                    <?php if (!(int)$m['can_campaigns'] && !(int)$m['can_donations'] && !(int)$m['can_beneficiaries'] && !(int)$m['can_members']): ?>
                      <span class="badge badge-muted">مشاهدة فقط</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <?php if ((string)$m['role'] === 'representative' || (int)$m['user_id'] === (int)$me['id']): ?>
                    <span class="text-xs text-muted">—</span>
                  <?php else: ?>
                    <div class="row-actions">
                      <button class="icon-btn" type="button" data-edit-member="<?= (int)$m['id'] ?>"
                        data-role="<?= e((string)$m['role']) ?>"
                        data-c="<?= (int)$m['can_campaigns'] ?>" data-d="<?= (int)$m['can_donations'] ?>"
                        data-b="<?= (int)$m['can_beneficiaries'] ?>" data-m="<?= (int)$m['can_members'] ?>"
                        data-name="<?= e((string)$m['full_name']) ?>">
                        <?= icon('edit', 13) ?>تعديل
                      </button>
                      <form method="post" data-ajax="api/member_action.php" data-confirm="إزالة العضو من الجمعية؟" data-done="reload">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                        <button class="icon-btn danger" type="submit"><?= icon('trash', 13) ?>إزالة</button>
                      </form>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <aside>
      <form class="form-card" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <h2 style="font-size:19px"><?= icon('user-plus', 18) ?> إضافة عضو</h2>
        <div class="form-grid" style="grid-template-columns:1fr">
          <div class="field">
            <label for="email">بريد العضو المسجّل <i>*</i></label>
            <input type="email" id="email" name="email" required dir="ltr" placeholder="member@example.com">
            <span class="hint">يجب أن يكون لديه حساب على المنصة أولاً.</span>
          </div>
          <div class="field">
            <label for="role">الدور</label>
            <select id="role" name="role">
              <option value="coordinator">منسّق</option>
              <option value="manager">مدير</option>
              <option value="viewer">مشاهد</option>
            </select>
          </div>
          <div class="field">
            <label>الصلاحيات</label>
            <div class="kv">
              <label class="checkbox"><input type="checkbox" name="can_campaigns" value="1" checked> إدارة الحملات</label>
              <label class="checkbox"><input type="checkbox" name="can_donations" value="1" checked> استلام التبرعات والإيصالات</label>
              <label class="checkbox"><input type="checkbox" name="can_beneficiaries" value="1"> إدارة المستفيدين والتوزيع</label>
              <label class="checkbox"><input type="checkbox" name="can_members" value="1"> إدارة الأعضاء</label>
            </div>
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary btn-sm btn-block" type="submit"><?= icon('plus', 15) ?>إضافة للفريق</button>
        </div>
      </form>

      <div class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('shield', 18) ?> دليل الأدوار</h3>
        <div class="kv">
          <div class="kv-row"><span>ممثل الجمعية</span><b>كل الصلاحيات</b></div>
          <div class="kv-row"><span>مدير</span><b>حسب التحديد</b></div>
          <div class="kv-row"><span>منسّق</span><b>تشغيل يومي</b></div>
          <div class="kv-row"><span>مشاهد</span><b>قراءة فقط</b></div>
        </div>
        <p class="text-xs text-muted mt-8">يمكنك منح أي عضو أي مزيج من الصلاحيات بغض النظر عن دوره.</p>
      </div>
    </aside>
  </div>
</div>

<!-- edit member modal -->
<div class="modal-backdrop" id="memberModal" role="dialog" aria-modal="true" aria-labelledby="memberTitle">
  <div class="modal">
    <button type="button" class="modal-close" data-close-modal aria-label="إغلاق"><?= icon('x', 17) ?></button>
    <h3 id="memberTitle"><?= icon('user-check', 19) ?> تعديل صلاحيات العضو</h3>
    <p class="modal-sub">العضو: <b id="memberName">—</b></p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="member_id" id="memberId" value="">
      <div class="field mb-16">
        <label for="editRole">الدور</label>
        <select id="editRole" name="role">
          <option value="manager">مدير</option>
          <option value="coordinator">منسّق</option>
          <option value="viewer">مشاهد</option>
        </select>
      </div>
      <div class="field mb-16">
        <label>الصلاحيات</label>
        <div class="kv">
          <label class="checkbox"><input type="checkbox" id="editC" name="can_campaigns" value="1"> إدارة الحملات</label>
          <label class="checkbox"><input type="checkbox" id="editD" name="can_donations" value="1"> استلام التبرعات والإيصالات</label>
          <label class="checkbox"><input type="checkbox" id="editB" name="can_beneficiaries" value="1"> إدارة المستفيدين والتوزيع</label>
          <label class="checkbox"><input type="checkbox" id="editM" name="can_members" value="1"> إدارة الأعضاء</label>
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" data-close-modal>إلغاء</button>
        <button class="btn btn-primary" type="submit"><?= icon('check', 16) ?>حفظ</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('memberModal');
  if (!modal) return;
  document.querySelectorAll('[data-edit-member]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.getElementById('memberId').value = btn.getAttribute('data-edit-member');
      document.getElementById('memberName').textContent = btn.getAttribute('data-name') || '';
      document.getElementById('editRole').value = btn.getAttribute('data-role') || 'coordinator';
      document.getElementById('editC').checked = btn.getAttribute('data-c') === '1';
      document.getElementById('editD').checked = btn.getAttribute('data-d') === '1';
      document.getElementById('editB').checked = btn.getAttribute('data-b') === '1';
      document.getElementById('editM').checked = btn.getAttribute('data-m') === '1';
      modal.classList.add('open');
    });
  });
  modal.querySelectorAll('[data-close-modal]').forEach(function (b) {
    b.addEventListener('click', function () { modal.classList.remove('open'); });
  });
  modal.addEventListener('click', function (e) { if (e.target === modal) modal.classList.remove('open'); });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
