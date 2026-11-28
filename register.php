<?php
/**
 * register.php — create a user / store / charity-representative account.
 * A charity representative also gets an association record in "pending" state
 * plus an automatic login_requests row for the admin to approve.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

if (Auth::check()) {
    redirect('index.php');
}

$allowRegistration = (bool)SettingsRepo::get('allow_registration', true);

$errors = [];
$old    = [
    'full_name' => '', 'email' => '', 'phone' => '', 'city' => '',
    'user_type' => (static fn ($t) => in_array($t, ['association', 'association_rep'], true) ? 'association' : $t)((string)(get_q('type', 'individual') ?? 'individual')),
    'assoc_name' => '', 'assoc_city' => '', 'assoc_license' => '', 'assoc_desc' => '',
    /* An individual may declare up front that they act for an association.
       That creates a join request the association's representative must
       approve — registration alone grants nothing. */
    'join_assoc' => '', 'join_role' => 'coordinator', 'join_note' => '',
];

$types = [];
foreach (UserRepo::userTypes() as $t) {
    $types[$t['code']] = $t;
}
/* The database stores the third account type under the code "association".
   Older internal links used "association_rep"; both are accepted here so a
   bookmark or a link from the marketing pages never lands on a missing option. */
const ASSOC_CODE_ALIASES = ['association', 'association_rep'];
$assocCode = isset($types['association']) ? 'association' : 'association_rep';
$selectableTypes = array_intersect_key($types, array_flip(['individual', 'store', $assocCode]));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $allowRegistration) {
    require_csrf();

    foreach ($old as $k => $v) {
        if ($k === 'user_type') {
            $old[$k] = (string)post('user_type', 'individual');
            if (in_array($old[$k], ASSOC_CODE_ALIASES, true)) {
                $old[$k] = $assocCode;
            }
        } else {
            $old[$k] = (string)post($k, '');
        }
    }
    $password  = (string)post('password', '');
    $password2 = (string)post('password_confirm', '');
    $agree     = post('agree') !== null;

    if (!isset($selectableTypes[$old['user_type']])) {
        $errors['user_type'] = 'اختر نوع الحساب.';
    }
    if (mb_strlen($old['full_name']) < 3) {
        $errors['full_name'] = 'الاسم الكامل مطلوب (3 أحرف على الأقل).';
    }
    if (!valid_email($old['email'])) {
        $errors['email'] = 'البريد الإلكتروني غير صحيح.';
    } elseif (UserRepo::emailExists(mb_strtolower($old['email']))) {
        $errors['email'] = 'هذا البريد مسجّل مسبقاً.';
    }
    if ($old['phone'] !== '' && !preg_match('/^[0-9+\-\s]{7,24}$/', $old['phone'])) {
        $errors['phone'] = 'رقم الهاتف غير صحيح.';
    }
    if ($old['city'] === '') {
        $errors['city'] = 'المدينة مطلوبة.';
    }
    if (mb_strlen($password) < 8) {
        $errors['password'] = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.';
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors['password'] = 'استخدم حروفاً وأرقاماً معاً في كلمة المرور.';
    }
    if ($password !== $password2) {
        $errors['password_confirm'] = 'كلمتا المرور غير متطابقتين.';
    }
    if (!$agree) {
        $errors['agree'] = 'يجب الموافقة على شروط الاستخدام.';
    }

    $isAssocRep = in_array($old['user_type'], ASSOC_CODE_ALIASES, true);
    if ($isAssocRep) {
        if (mb_strlen($old['assoc_name']) < 3) {
            $errors['assoc_name'] = 'اسم الجمعية مطلوب.';
        }
        if ($old['assoc_city'] === '') {
            $errors['assoc_city'] = 'مدينة الجمعية مطلوبة.';
        }
        if ($old['assoc_license'] === '') {
            $errors['assoc_license'] = 'رقم الترخيص مطلوب للتوثيق.';
        }
    }

    /* A join request is optional; if an association was picked, make sure it is
       a real, verified one before we create the account. */
    $joinAssocId = (int)$old['join_assoc'];
    $joinAssoc   = null;
    if (!$isAssocRep && $joinAssocId > 0) {
        $joinAssoc = AssociationRepo::find($joinAssocId);
        if (!$joinAssoc || (string)$joinAssoc['status'] !== 'verified') {
            $errors['join_assoc'] = 'الجمعية المختارة غير متاحة.';
        }
    }
    if (!in_array($old['join_role'], ['manager', 'coordinator', 'viewer'], true)) {
        $old['join_role'] = 'coordinator';
    }

    if (!$errors) {
        try {
            $userId = Qeemah\Config\Database::transaction(function () use ($old, $password, $isAssocRep, $types, $joinAssoc) {
                $userId = UserRepo::create([
                    'user_type_id'  => (int)$types[$old['user_type']]['id'],
                    'full_name'     => $old['full_name'],
                    'email'         => mb_strtolower($old['email']),
                    'phone'         => $old['phone'] !== '' ? $old['phone'] : null,
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    'city'          => $old['city'],
                    'status'        => $isAssocRep ? 'pending' : 'active',
                ]);

                UserRepo::grantRole($userId, $isAssocRep ? 'association_representative' : ($old['user_type'] === 'store' ? 'store' : 'user'));

                if ($isAssocRep) {
                    $assocId = AssociationRepo::create($userId, [
                        'name'           => $old['assoc_name'],
                        'license_number' => $old['assoc_license'],
                        'description'    => $old['assoc_desc'],
                        'city'           => $old['assoc_city'],
                        'email'          => mb_strtolower($old['email']),
                        'phone'          => $old['phone'],
                    ]);
                    // Admin approval queue entry for the licensing review.
                    Repo::exec(
                        'INSERT INTO login_requests (user_id, identifier, kind, status, created_at)
                         VALUES (?,?,?,?,?)',
                        [$userId, mb_strtolower($old['email']), 'assoc_approval', 'pending', now()]
                    );
                }
                if ($joinAssoc !== null) {
                    AssociationRepo::requestJoin(
                        (int)$joinAssoc['id'],
                        (int)$userId,
                        (string)$old['join_role'],
                        (string)$old['join_note']
                    );
                    $repId = (int)Repo::scalar(
                        "SELECT user_id FROM association_members
                          WHERE association_id=? AND role='representative' AND status='active' LIMIT 1",
                        [(int)$joinAssoc['id']]
                    ) ?: (int)$joinAssoc['owner_user_id'];
                    if ($repId > 0) {
                        NotificationRepo::push(
                            $repId,
                            'system',
                            'طلب انضمام جديد للجمعية',
                            $old['full_name'] . ' يطلب الانضمام إلى «' . (string)$joinAssoc['name'] . '» — راجعه للقبول أو الرفض.',
                            'charity/join_requests.php',
                            'user-plus'
                        );
                    }
                }
                return $userId;
            });
            Auth::establish((int)$userId);
            flash('success', $isAssocRep
                ? 'تم إنشاء حساب الجمعية. سيراجع فريق الإدارة بيانات الترخيص ويصلك إشعار عند التوثيق.'
                : ($joinAssoc !== null
                    ? 'تم إنشاء حسابك. أُرسل طلبك للانضمام إلى «' . (string)$joinAssoc['name'] . '» وينتظر موافقتها.'
                    : 'تم إنشاء حسابك بنجاح. أهلاً بك في قيمة وأثر.'));
            redirect($isAssocRep ? 'charity/dashboard.php' : 'index.php');
        } catch (Throwable $ex) {
            $errors['general'] = 'تعذّر إنشاء الحساب: ' . $ex->getMessage();
        }
    }
}

$joinableAssocs = AssociationRepo::verifiedList();

$pageTitle   = 'إنشاء حساب | قيمة وأثر';
$currentPage = 'register';
require __DIR__ . '/includes/header.php';
?>

<div class="auth-layout">
  <aside class="auth-side">
    <span class="badge badge-soft" style="background:rgba(255,255,255,.14);color:#d1fae5;width:fit-content">
      <?= icon('sprout', 14) ?> انضم إلى المجتمع
    </span>
    <h2>سجّل مرة، <em>واستفد دائماً</em></h2>
    <p>حساب واحد يمنحك: سوقاً كاملاً، طلبات ومحادثات، مفضلة وإشعارات، وتبرعات موثّقة.</p>
    <div class="auth-feat"><?= icon('store', 20) ?><span><b>أفراد ومتاجر</b><span>بيع، تبادل، تبرّع، ومجاني — بلا رسوم ولا بوابة دفع.</span></span></div>
    <div class="auth-feat"><?= icon('landmark', 20) ?><span><b>جمعيات خيرية</b><span>لوحة كاملة للحملات والمستفيدين والإيصالات.</span></span></div>
    <div class="auth-feat"><?= icon('shield', 20) ?><span><b>بيانات محمية</b><span>تشفير كلمات المرور وجلسات آمنة ضد الاختراق.</span></span></div>
  </aside>

  <div class="auth-main">
    <div class="auth-card wide">
      <div class="auth-logo"><?= icon('user-plus', 30) ?></div>
      <h1>إنشاء حساب جديد</h1>
      <p class="sub">اختر نوع الحساب وأكمل بياناتك</p>

      <?php if (!$allowRegistration): ?>
        <div class="alert alert-warning"><?= icon('alert-triangle', 18) ?><div class="grow">التسجيل متوقف مؤقتاً. يرجى المحاولة لاحقاً.</div></div>
      <?php endif; ?>

      <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error"><?= icon('alert-circle', 18) ?><div class="grow"><?= e($errors['general']) ?></div></div>
      <?php elseif ($errors): ?>
        <div class="alert alert-error"><?= icon('alert-circle', 18) ?><div class="grow">تحقّق من الحقول المميّزة بالأحمر ثم أعد المحاولة.</div></div>
      <?php endif; ?>

      <form method="post" action="<?= e(url('register.php')) ?>" novalidate id="registerForm">
        <?= csrf_field() ?>

        <div class="field mb-16">
          <label>نوع الحساب <i>*</i></label>
          <div class="type-cards">
            <?php foreach ($selectableTypes as $code => $t):
                $labels = [
                    'individual'      => ['فرد', 'بيع وتبادل وتبرّع'],
                    'store'           => ['متجر', 'بيع منتجات مستعملة'],
                    'association'     => ['جمعية', 'استلام تبرعات وإدارتها'],
                    'association_rep' => ['جمعية', 'استلام تبرعات وإدارتها'],
                ];
                $label = $labels[$code] ?? [$t['name_ar'], $t['description'] ?? ''];
            ?>
              <label class="type-card">
                <input type="radio" name="user_type" value="<?= e($code) ?>" <?= $old['user_type'] === $code ? 'checked' : '' ?>>
                <b><?= e($label[0]) ?></b>
                <small><?= e($label[1]) ?></small>
              </label>
            <?php endforeach; ?>
          </div>
          <?php if (!empty($errors['user_type'])): ?><span class="err"><?= e($errors['user_type']) ?></span><?php endif; ?>
        </div>

        <div class="reg-grid">
          <div class="field<?= isset($errors['full_name']) ? ' has-error' : '' ?>">
            <label for="full_name">الاسم الكامل <i>*</i></label>
            <input type="text" id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" required autocomplete="name" placeholder="مثال: أحمد العريفي">
            <?php if (isset($errors['full_name'])): ?><span class="err"><?= e($errors['full_name']) ?></span><?php endif; ?>
          </div>

          <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>">
            <label for="email">البريد الإلكتروني <i>*</i></label>
            <input type="email" id="email" name="email" value="<?= e($old['email']) ?>" required autocomplete="email" dir="ltr" placeholder="name@example.com">
            <?php if (isset($errors['email'])): ?><span class="err"><?= e($errors['email']) ?></span><?php endif; ?>
          </div>

          <div class="field<?= isset($errors['phone']) ? ' has-error' : '' ?>">
            <label for="phone">رقم الهاتف</label>
            <input type="tel" id="phone" name="phone" value="<?= e($old['phone']) ?>" dir="ltr" placeholder="7xxxxxxxx" autocomplete="tel">
            <?php if (isset($errors['phone'])): ?><span class="err"><?= e($errors['phone']) ?></span><?php endif; ?>
          </div>

          <div class="field<?= isset($errors['city']) ? ' has-error' : '' ?>">
            <label for="city">المدينة <i>*</i></label>
            <select id="city" name="city" required>
              <option value="">اختر المدينة</option>
              <?php foreach (['صنعاء', 'عدن', 'تعز', 'إب', 'الحديدة', 'حضرموت', 'المكلا', 'ذمار'] as $c): ?>
                <option value="<?= e($c) ?>" <?= $old['city'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['city'])): ?><span class="err"><?= e($errors['city']) ?></span><?php endif; ?>
          </div>

          <div class="field<?= isset($errors['password']) ? ' has-error' : '' ?>">
            <label for="password">كلمة المرور <i>*</i></label>
            <input type="password" id="password" name="password" required autocomplete="new-password" placeholder="8 أحرف وأرقام على الأقل">
            <?php if (isset($errors['password'])): ?><span class="err"><?= e($errors['password']) ?></span><?php endif; ?>
          </div>

          <div class="field<?= isset($errors['password_confirm']) ? ' has-error' : '' ?>">
            <label for="password_confirm">تأكيد كلمة المرور <i>*</i></label>
            <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password" placeholder="أعد كتابة كلمة المرور">
            <?php if (isset($errors['password_confirm'])): ?><span class="err"><?= e($errors['password_confirm']) ?></span><?php endif; ?>
          </div>
        </div>

        <div id="assocFields" class="dynamic-box mt-16" <?= in_array($old['user_type'], ASSOC_CODE_ALIASES, true) ? '' : 'hidden' ?>>
          <b style="font-size:14px"><?= icon('landmark', 16) ?> بيانات الجمعية</b>
          <div class="reg-grid">
            <div class="field<?= isset($errors['assoc_name']) ? ' has-error' : '' ?>">
              <label for="assoc_name">اسم الجمعية <i>*</i></label>
              <input type="text" id="assoc_name" name="assoc_name" value="<?= e($old['assoc_name']) ?>" placeholder="مثال: جمعية البر الخيرية">
              <?php if (isset($errors['assoc_name'])): ?><span class="err"><?= e($errors['assoc_name']) ?></span><?php endif; ?>
            </div>
            <div class="field<?= isset($errors['assoc_license']) ? ' has-error' : '' ?>">
              <label for="assoc_license">رقم الترخيص <i>*</i></label>
              <input type="text" id="assoc_license" name="assoc_license" value="<?= e($old['assoc_license']) ?>" placeholder="LIC-0000">
              <?php if (isset($errors['assoc_license'])): ?><span class="err"><?= e($errors['assoc_license']) ?></span><?php endif; ?>
            </div>
            <div class="field<?= isset($errors['assoc_city']) ? ' has-error' : '' ?>">
              <label for="assoc_city">مدينة الجمعية <i>*</i></label>
              <select id="assoc_city" name="assoc_city">
                <option value="">اختر المدينة</option>
                <?php foreach (['صنعاء', 'عدن', 'تعز', 'إب', 'الحديدة', 'حضرموت', 'المكلا'] as $c): ?>
                  <option value="<?= e($c) ?>" <?= $old['assoc_city'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($errors['assoc_city'])): ?><span class="err"><?= e($errors['assoc_city']) ?></span><?php endif; ?>
            </div>
            <div class="field full">
              <label for="assoc_desc">نبذة عن الجمعية</label>
              <textarea id="assoc_desc" name="assoc_desc" rows="3" placeholder="مجالات العمل والاحتياجات الأساسية…"><?= e($old['assoc_desc']) ?></textarea>
            </div>
          </div>
          <p class="text-xs text-muted"><?= icon('info', 13) ?> تتم مراجعة الجمعيات وتوثيقها من إدارة المنصة قبل تفعيل استلام التبرعات.</p>
        </div>

        <div id="joinFields" class="dynamic-box mt-16" <?= in_array($old['user_type'], ASSOC_CODE_ALIASES, true) ? 'hidden' : '' ?>>
          <b style="font-size:14px"><?= icon('landmark', 16) ?> هل تمثّل جمعية؟ <span class="text-muted" style="font-weight:500">(اختياري)</span></b>
          <p class="text-xs text-muted" style="margin:6px 0 12px">إن كنت متطوّعاً أو منسّقاً في جمعية، اخترها هنا. سيصل طلبك إلى الجمعية، ويتفعّل حسابك لديها <b>بعد موافقتها فقط</b>.</p>
          <div class="reg-grid">
            <div class="field<?= isset($errors['join_assoc']) ? ' has-error' : '' ?>">
              <label for="join_assoc">الجمعية</label>
              <select id="join_assoc" name="join_assoc">
                <option value="">لا، أنا مستقل</option>
                <?php foreach ($joinableAssocs as $a): ?>
                  <option value="<?= (int)$a['id'] ?>" <?= (int)$old['join_assoc'] === (int)$a['id'] ? 'selected' : '' ?>><?= e((string)$a['name']) ?> — <?= e((string)$a['city']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($errors['join_assoc'])): ?><span class="err"><?= e($errors['join_assoc']) ?></span><?php endif; ?>
            </div>
            <div class="field">
              <label for="join_role">دورك المتوقّع</label>
              <select id="join_role" name="join_role">
                <?php foreach (['coordinator' => 'منسّق', 'manager' => 'مدير', 'viewer' => 'مشاهد'] as $rv => $rl): ?>
                  <option value="<?= $rv ?>" <?= $old['join_role'] === $rv ? 'selected' : '' ?>><?= $rl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field full">
              <label for="join_note">رسالة للجمعية (اختياري)</label>
              <textarea id="join_note" name="join_note" rows="2" placeholder="مثال: أتطوّع في جمع التبرعات وتوزيعها منذ سنتين…"><?= e($old['join_note']) ?></textarea>
            </div>
          </div>
          <p class="text-xs text-muted"><?= icon('clock', 13) ?> تبقى عضويتك «قيد الانتظار» حتى ترد الجمعية. تستطيع استخدام حسابك بشكل طبيعي كفرد في هذه الأثناء.</p>
        </div>

        <label class="auth-check mt-16">
          <input type="checkbox" name="agree" value="1" <?= post('agree') !== null ? 'checked' : '' ?>>
          <span>أوافق على شروط الاستخدام وسياسة الخصوصية، وألتزم بعدم نشر أي محتوى مخالف أو منتجات محظورة.</span>
        </label>
        <?php if (isset($errors['agree'])): ?><span class="err" style="color:var(--danger);font-size:12px"><?= e($errors['agree']) ?></span><?php endif; ?>

        <button class="btn btn-primary btn-block btn-lg mt-24" type="submit" <?= $allowRegistration ? '' : 'disabled' ?>>
          <?= icon('user-plus', 18) ?>إنشاء الحساب
        </button>
      </form>

      <p class="auth-switch">لديك حساب بالفعل؟ <a href="<?= e(url('login.php')) ?>">تسجيل الدخول</a></p>
    </div>
  </div>
</div>

<script>
(function () {
  var radios = document.querySelectorAll('input[name="user_type"]');
  var box = document.getElementById('assocFields');
  if (!box) return;
  var joinBox = document.getElementById('joinFields');
  function sync() {
    var checked = document.querySelector('input[name="user_type"]:checked');
    var isAssoc = checked && (checked.value === 'association' || checked.value === 'association_rep');
    box.hidden = !isAssoc;
    if (joinBox) joinBox.hidden = isAssoc;
    ['assoc_name', 'assoc_license', 'assoc_city'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.required = !!isAssoc;
    });
  }
  radios.forEach(function (r) { r.addEventListener('change', sync); });
  sync();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
