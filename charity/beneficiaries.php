<?php
/**
 * charity/beneficiaries.php — private beneficiary registry + distribution log.
 * This data is only ever visible to the association's own members (and admins)
 * and is never exposed on the public site.
 *
 *   ?tab=list (default) | distributions
 *   POST action=create|update|distribute
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/components.php';

$assoc   = Gate::requireAssociation();
Gate::requireMemberCapability('beneficiaries');
$assocId = (int)$assoc['id'];
$me      = Auth::user();
$tab     = get_q('tab', 'list') ?? 'list';
$editId  = get_int('edit', 0) ?? 0;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)post('action', '');
    try {
        if ($action === 'create') {
            $data = [
                'full_name'      => (string)post('full_name', ''),
                'national_id'    => post('national_id'),
                'phone'          => post('phone'),
                'city'           => post('city'),
                'household_size' => post_int('household_size', 1),
                'needs_summary'  => post('needs_summary'),
                'code'           => post('code'),
            ];
            if (mb_strlen($data['full_name']) < 3) {
                throw new RuntimeException('اسم المستفيد مطلوب.');
            }
            $newId = BeneficiaryRepo::create($assocId, (int)$me['id'], $data);
            AuditRepo::log(null, 'beneficiary.created', 'beneficiary', (int)$newId);
            flash('success', 'تم تسجيل المستفيد بنجاح. البيانات سرّية ولا تُعرض علناً.');
            redirect('charity/beneficiaries.php');
        }
        if ($action === 'update') {
            BeneficiaryRepo::update((int)post_int('id', 0), $assocId, [
                'full_name'      => (string)post('full_name', ''),
                'national_id'    => post('national_id'),
                'phone'          => post('phone'),
                'city'           => post('city'),
                'household_size' => post_int('household_size', 1),
                'needs_summary'  => post('needs_summary'),
                'status'         => post('status', 'active'),
            ]);
            flash('success', 'تم تحديث بيانات المستفيد.');
            redirect('charity/beneficiaries.php');
        }
        if ($action === 'distribute') {
            $beneficiaryId = post_int('beneficiary_id', 0) ?? 0;
            if (!$beneficiaryId) {
                throw new RuntimeException('اختر المستفيد.');
            }
            $row = BeneficiaryRepo::find($beneficiaryId);
            if (!$row || (int)$row['association_id'] !== $assocId) {
                throw new RuntimeException('المستفيد لا يخصّ جمعيتك.');
            }
            BeneficiaryRepo::logDistribution($assocId, (int)$me['id'], [
                'beneficiary_id' => $beneficiaryId,
                'donation_id'    => post_int('donation_id'),
                'items_summary'  => (string)post('items_summary', ''),
                'quantity'       => post_int('quantity', 1),
                'distributed_on' => (string)post('distributed_on', today()),
                'note'           => post('note'),
            ]);
            flash('success', 'تم تسجيل التوزيع للمستفيد «' . (string)$row['full_name'] . '».');
            redirect('charity/beneficiaries.php?tab=distributions');
        }
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
    }
}

$beneficiaries = BeneficiaryRepo::forAssociation($assocId);
$distributions = BeneficiaryRepo::distributions($assocId);
$donations     = DonationRepo::claimedByAssociation($assocId);
$editing       = $editId ? BeneficiaryRepo::find($editId) : null;
if ($editing && (int)$editing['association_id'] !== $assocId) {
    $editing = null;
}

$pageTitle   = 'المستفيدون والتوزيع | قيمة وأثر';
$currentPage = 'charity_panel';
$pageHead    = [
    'title'    => icon('users', 28) . ' المستفيدون والتوزيع',
    'subtitle' => 'سجل سرّي خاص بالجمعية — يظهر لأعضائها فقط، مع توثيق كل عملية توزيع.',
    'actions'  => '<a class="btn btn-outline btn-sm" href="' . e(url('charity/donations_manage.php')) . '">' . icon('hand-heart', 15) . 'التبرّعات</a>',
];
require __DIR__ . '/../includes/header.php';
?>

<div class="container section" style="padding-top:24px">
  <?php if ($errors): ?>
    <div class="alert alert-error"><?= icon('alert-circle', 18) ?><div class="grow"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
  <?php endif; ?>

  <div class="alert alert-info">
    <?= icon('shield', 18) ?>
    <div class="grow">بيانات المستفيدين محمية: لا تظهر في أي صفحة عامة، ولا يمكن لأي جمعية أخرى الوصول إليها.</div>
  </div>

  <div class="dash-tabs">
    <a class="dash-tab<?= $tab === 'list' ? ' active' : '' ?>" href="<?= e(url('charity/beneficiaries.php?tab=list')) ?>">
      <?= icon('users', 15) ?>المستفيدون <span class="badge badge-soft"><?= count($beneficiaries) ?></span>
    </a>
    <a class="dash-tab<?= $tab === 'distributions' ? ' active' : '' ?>" href="<?= e(url('charity/beneficiaries.php?tab=distributions')) ?>">
      <?= icon('route', 15) ?>سجل التوزيع <span class="badge badge-soft"><?= count($distributions) ?></span>
    </a>
  </div>

  <?php if ($tab === 'list'): ?>
    <div class="two-col">
      <div>
        <div class="panel">
          <h3><?= icon('users', 18) ?> سجل المستفيدين</h3>
          <?php if (!$beneficiaries): ?>
            <?= empty_state('لا يوجد مستفيدون مسجّلون', 'أضف المستفيدين لتوثيق توزيع التبرّعات عليهم.', 'users') ?>
          <?php else: ?>
            <div class="table-wrap">
              <table>
                <thead><tr><th>الرمز</th><th>الاسم</th><th>المدينة</th><th>أفراد الأسرة</th><th>التوزيعات</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                  <?php foreach ($beneficiaries as $b): ?>
                    <tr>
                      <td><span class="table-title" dir="ltr"><?= e((string)$b['code']) ?></span></td>
                      <td><span class="table-title"><?= e((string)$b['full_name']) ?></span><span class="table-sub"><?= e(excerpt((string)($b['needs_summary'] ?? ''), 40)) ?></span></td>
                      <td><?= e((string)($b['city'] ?? '—')) ?></td>
                      <td><?= (int)$b['household_size'] ?></td>
                      <td><?= (int)$b['distributions'] ?></td>
                      <td><span class="status <?= $b['status'] === 'active' ? 'st-accepted' : 'st-cancelled' ?>"><?= $b['status'] === 'active' ? 'نشط' : 'موقوف' ?></span></td>
                      <td><a class="icon-btn" href="<?= e(url('charity/beneficiaries.php?tab=list&edit=' . (int)$b['id'])) ?>"><?= icon('edit', 13) ?>تعديل</a></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <aside>
        <?php if ($editing): ?>
          <form class="form-card" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
            <h2 style="font-size:19px"><?= icon('edit', 18) ?> تعديل مستفيد</h2>
            <div class="form-grid" style="grid-template-columns:1fr">
              <div class="field"><label for="full_name">الاسم</label><input type="text" id="full_name" name="full_name" required value="<?= e((string)$editing['full_name']) ?>"></div>
              <div class="field"><label for="national_id">رقم الهوية</label><input type="text" id="national_id" name="national_id" dir="ltr" value="<?= e((string)($editing['national_id'] ?? '')) ?>"></div>
              <div class="field"><label for="phone">الهاتف</label><input type="tel" id="phone" name="phone" dir="ltr" value="<?= e((string)($editing['phone'] ?? '')) ?>"></div>
              <div class="field"><label for="city">المدينة</label><input type="text" id="city" name="city" value="<?= e((string)($editing['city'] ?? '')) ?>"></div>
              <div class="field"><label for="household_size">أفراد الأسرة</label><input type="number" id="household_size" name="household_size" min="1" value="<?= (int)$editing['household_size'] ?>"></div>
              <div class="field"><label for="needs_summary">الاحتياجات</label><textarea id="needs_summary" name="needs_summary" rows="3"><?= e((string)($editing['needs_summary'] ?? '')) ?></textarea></div>
              <div class="field"><label for="status">الحالة</label>
                <select id="status" name="status">
                  <option value="active" <?= $editing['status'] === 'active' ? 'selected' : '' ?>>نشط</option>
                  <option value="inactive" <?= $editing['status'] === 'inactive' ? 'selected' : '' ?>>موقوف</option>
                </select>
              </div>
            </div>
            <div class="form-actions">
              <a class="btn btn-ghost btn-sm" href="<?= e(url('charity/beneficiaries.php')) ?>">إلغاء</a>
              <button class="btn btn-primary btn-sm" type="submit"><?= icon('check', 15) ?>حفظ</button>
            </div>
          </form>
        <?php else: ?>
          <form class="form-card" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <h2 style="font-size:19px"><?= icon('user-plus', 18) ?> تسجيل مستفيد</h2>
            <div class="form-grid" style="grid-template-columns:1fr">
              <div class="field"><label for="full_name">الاسم الكامل <i>*</i></label><input type="text" id="full_name" name="full_name" required></div>
              <div class="field"><label for="national_id">رقم الهوية</label><input type="text" id="national_id" name="national_id" dir="ltr"></div>
              <div class="field"><label for="phone">الهاتف</label><input type="tel" id="phone" name="phone" dir="ltr"></div>
              <div class="field"><label for="city">المدينة</label><input type="text" id="city" name="city" value="<?= e((string)$assoc['city']) ?>"></div>
              <div class="field"><label for="household_size">عدد أفراد الأسرة</label><input type="number" id="household_size" name="household_size" min="1" value="1"></div>
              <div class="field"><label for="needs_summary">ملخّص الاحتياجات</label><textarea id="needs_summary" name="needs_summary" rows="3" placeholder="ملابس أطفال، أدوات مدرسية…"></textarea></div>
            </div>
            <div class="form-actions">
              <button class="btn btn-primary btn-sm btn-block" type="submit"><?= icon('plus', 15) ?>تسجيل المستفيد</button>
            </div>
          </form>
        <?php endif; ?>

        <form class="form-card mt-16" method="post" style="margin-top:16px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="distribute">
          <h2 style="font-size:19px"><?= icon('route', 18) ?> تسجيل توزيع</h2>
          <div class="form-grid" style="grid-template-columns:1fr">
            <div class="field">
              <label for="beneficiary_id">المستفيد <i>*</i></label>
              <select id="beneficiary_id" name="beneficiary_id" required>
                <option value="">اختر المستفيد</option>
                <?php foreach ($beneficiaries as $b): ?>
                  <option value="<?= (int)$b['id'] ?>"><?= e((string)$b['code']) ?> — <?= e((string)$b['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="donation_id">من تبرّع</label>
              <select id="donation_id" name="donation_id">
                <option value="">— غير مرتبط —</option>
                <?php foreach ($donations as $d): ?>
                  <option value="<?= (int)$d['id'] ?>"><?= e(excerpt((string)$d['title'], 34)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field"><label for="items_summary">العناصر الموزّعة <i>*</i></label><input type="text" id="items_summary" name="items_summary" required placeholder="بطانيتان + جاكيت"></div>
            <div class="field"><label for="quantity">الكمية</label><input type="number" id="quantity" name="quantity" min="1" value="1"></div>
            <div class="field"><label for="distributed_on">تاريخ التوزيع</label><input type="date" id="distributed_on" name="distributed_on" value="<?= e(today()) ?>"></div>
            <div class="field"><label for="note">ملاحظة</label><input type="text" id="note" name="note" placeholder="توقيع المستلم / ملاحظة"></div>
          </div>
          <div class="form-actions">
            <button class="btn btn-primary btn-sm btn-block" type="submit"><?= icon('check', 15) ?>تسجيل التوزيع</button>
          </div>
        </form>
      </aside>
    </div>

  <?php else: ?>
    <div class="panel">
      <h3><?= icon('route', 18) ?> سجل التوزيع <span class="badge badge-soft"><?= count($distributions) ?></span></h3>
      <?php if (!$distributions): ?>
        <?= empty_state('لا توجد عمليات توزيع بعد', 'سجّل أول عملية توزيع من تبويب المستفيدين.', 'route') ?>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th>المستفيد</th><th>العناصر</th><th>الكمية</th><th>التاريخ</th><th>الموثّق</th><th>ملاحظة</th></tr></thead>
            <tbody>
              <?php foreach ($distributions as $l): ?>
                <tr>
                  <td><span class="table-title"><?= e((string)$l['beneficiary_name']) ?></span><span class="table-sub" dir="ltr"><?= e((string)$l['beneficiary_code']) ?></span></td>
                  <td><?= e(excerpt((string)$l['items_summary'], 46)) ?></td>
                  <td><?= (int)$l['quantity'] ?></td>
                  <td><?= e((string)$l['distributed_on']) ?></td>
                  <td><?= e((string)$l['handler_name']) ?></td>
                  <td><span class="table-sub"><?= e((string)($l['note'] ?? '—')) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
