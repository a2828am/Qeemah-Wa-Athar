<?php
/**
 * profile.php — public profile + account settings of the signed-in user:
 * personal data, password change, notification preferences, my ratings.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/components.php';

Auth::requireLogin('profile.php');
$me  = Auth::user();
$uid = (int)$me['id'];

$errors = [];
$tab    = get_q('tab', 'info') ?? 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)post('action', '');

    if ($action === 'profile') {
        $data = [
            'full_name' => (string)post('full_name', ''),
            'phone'     => (string)post('phone', ''),
            'city'      => (string)post('city', ''),
            'bio'       => (string)post('bio', ''),
        ];
        if (mb_strlen($data['full_name']) < 3) {
            $errors['full_name'] = 'الاسم قصير جداً.';
        }
        if ($data['phone'] !== '' && !preg_match('/^[0-9+\-\s]{7,24}$/', $data['phone'])) {
            $errors['phone'] = 'رقم الهاتف غير صحيح.';
        }
        if (mb_strlen((string)$data['bio']) > 500) {
            $errors['bio'] = 'النبذة طويلة جداً (500 حرف كحد أقصى).';
        }
        if (!$errors) {
            UserRepo::updateProfile($uid, $data);
            ActivityRepo::log($uid, 'profile.updated');
            flash('success', 'تم تحديث بياناتك بنجاح.');
            redirect('profile.php');
        }
        $tab = 'info';
    }

    if ($action === 'password') {
        $current = (string)post('current_password', '');
        $new     = (string)post('new_password', '');
        $confirm = (string)post('new_password_confirm', '');

        if (!password_verify($current, (string)$me['password_hash'])) {
            $errors['current_password'] = 'كلمة المرور الحالية غير صحيحة.';
        }
        if (mb_strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
            $errors['new_password'] = 'كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل وتحتوي حروفاً وأرقاماً.';
        }
        if ($new !== $confirm) {
            $errors['new_password_confirm'] = 'كلمتا المرور غير متطابقتين.';
        }
        if (!$errors) {
            UserRepo::setPassword($uid, password_hash($new, PASSWORD_BCRYPT));
            SessionRepo::logEvent($uid, 'login', session_id(), 'تغيير كلمة المرور', client_ip(), user_agent());
            flash('success', 'تم تغيير كلمة المرور. استخدمها في المرة القادمة.');
            redirect('profile.php?tab=security');
        }
        $tab = 'security';
    }

    if ($action === 'notifications') {
        UserRepo::exec(
            'UPDATE users SET notify_messages=?, notify_requests=? WHERE id=?',
            [post('notify_messages') !== null ? 1 : 0, post('notify_requests') !== null ? 1 : 0, $uid]
        );
        flash('success', 'تم تحديث تفضيلات الإشعارات.');
        redirect('profile.php?tab=security');
    }
}

$recentRatings = RatingRepo::recentFor($uid, 8);
$dash          = StatsRepo::userDashboard($uid);
$memberOf      = Auth::association();

$pageTitle   = 'ملفي الشخصي | قيمة وأثر';
$currentPage = 'profile';
require __DIR__ . '/includes/header.php';
?>

<div class="container section" style="padding-top:26px">
  <div class="profile-head">
    <span class="avatar avatar-lg"><?= e(avatar_letter((string)$me['full_name'])) ?></span>
    <div class="grow">
      <h1><?= e((string)$me['full_name']) ?></h1>
      <span class="sub">
        <?= e((string)$me['type_name']) ?>
        <?php if (!empty($me['city'])): ?> · <?= icon('map-pin', 12) ?> <?= e((string)$me['city']) ?><?php endif; ?>
        · عضو منذ <?= e(date('Y', strtotime((string)$me['created_at']))) ?>
      </span>
      <div class="mt-8"><?= stars((float)$me['rating_avg'], (int)$me['rating_count']) ?></div>
    </div>
    <div class="flex gap-8 flex-wrap">
      <a class="btn btn-outline btn-sm" href="<?= e(url('my_requests.php')) ?>"><?= icon('clipboard', 15) ?>طلباتي</a>
      <a class="btn btn-primary btn-sm" href="<?= e(url('add_product.php')) ?>"><?= icon('plus', 15) ?>أضف منتج</a>
    </div>
  </div>

  <div class="stat-grid">
    <div class="stat"><span class="stat-ic"><?= icon('package', 18) ?></span><b><?= (int)$dash['my_products'] ?></b><span>منتجات منشورة</span></div>
    <div class="stat"><span class="stat-ic"><?= icon('handshake', 18) ?></span><b><?= (int)$dash['incoming'] + (int)$dash['outgoing'] ?></b><span>إجمالي الطلبات</span></div>
    <div class="stat"><span class="stat-ic"><?= icon('heart', 18) ?></span><b><?= (int)$dash['favorites'] ?></b><span>في المفضلة</span></div>
    <div class="stat"><span class="stat-ic"><?= icon('star', 18) ?></span><b><?= (int)$me['rating_count'] ?></b><span>تقييم مستلم</span></div>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-error"><?= icon('alert-circle', 18) ?><div class="grow">يرجى تصحيح الحقول المميّزة بالأحمر.</div></div>
  <?php endif; ?>

  <div class="dash-tabs">
    <a class="dash-tab<?= $tab === 'info' ? ' active' : '' ?>" href="<?= e(url('profile.php?tab=info')) ?>"><?= icon('user', 15) ?>بياناتي</a>
    <a class="dash-tab<?= $tab === 'security' ? ' active' : '' ?>" href="<?= e(url('profile.php?tab=security')) ?>"><?= icon('shield', 15) ?>الأمان والإشعارات</a>
    <a class="dash-tab<?= $tab === 'ratings' ? ' active' : '' ?>" href="<?= e(url('profile.php?tab=ratings')) ?>"><?= icon('star', 15) ?>تقييماتي</a>
  </div>

  <?php if ($tab === 'info'): ?>
    <div class="profile-grid">
      <form class="form-card" method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="profile">
        <h2><?= icon('user', 20) ?> البيانات الشخصية</h2>
        <div class="form-grid">
          <div class="field<?= isset($errors['full_name']) ? ' has-error' : '' ?>">
            <label for="full_name">الاسم الكامل</label>
            <input type="text" id="full_name" name="full_name" value="<?= e((string)$me['full_name']) ?>" required>
            <?php if (isset($errors['full_name'])): ?><span class="err"><?= e($errors['full_name']) ?></span><?php endif; ?>
          </div>
          <div class="field">
            <label for="emailRO">البريد الإلكتروني</label>
            <input type="email" id="emailRO" value="<?= e((string)$me['email']) ?>" dir="ltr" disabled>
            <span class="hint">لا يمكن تغيير البريد من هنا — تواصل مع الإدارة.</span>
          </div>
          <div class="field<?= isset($errors['phone']) ? ' has-error' : '' ?>">
            <label for="phone">رقم الهاتف</label>
            <input type="tel" id="phone" name="phone" value="<?= e((string)($me['phone'] ?? '')) ?>" dir="ltr">
            <?php if (isset($errors['phone'])): ?><span class="err"><?= e($errors['phone']) ?></span><?php endif; ?>
          </div>
          <div class="field">
            <label for="city">المدينة</label>
            <select id="city" name="city">
              <option value="">غير محددة</option>
              <?php foreach (['صنعاء', 'عدن', 'تعز', 'إب', 'الحديدة', 'حضرموت', 'المكلا', 'ذمار'] as $c): ?>
                <option value="<?= e($c) ?>" <?= ($me['city'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field full<?= isset($errors['bio']) ? ' has-error' : '' ?>">
            <label for="bio">نبذة عنك</label>
            <textarea id="bio" name="bio" rows="4" maxlength="500" placeholder="اكتب سطراً أو سطرين يعرّفان بك…"><?= e((string)($me['bio'] ?? '')) ?></textarea>
            <?php if (isset($errors['bio'])): ?><span class="err"><?= e($errors['bio']) ?></span><?php endif; ?>
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary" type="submit"><?= icon('check', 16) ?>حفظ التغييرات</button>
        </div>
      </form>

      <div>
        <div class="panel">
          <h3><?= icon('shield', 18) ?> حالة الحساب</h3>
          <div class="kv">
            <div class="kv-row"><span>نوع الحساب</span><b><?= e((string)$me['type_name']) ?></b></div>
            <div class="kv-row"><span>الحالة</span><b><?= $me['status'] === 'active' ? 'نشط' : e((string)$me['status']) ?></b></div>
            <div class="kv-row"><span>التحقق من البريد</span><b><?= !empty($me['email_verified_at']) ? 'موثّق' : 'غير موثّق' ?></b></div>
            <div class="kv-row"><span>آخر دخول</span><b><?= e(time_ago((string)($me['last_login_at'] ?? ''))) ?: '—' ?></b></div>
            <div class="kv-row"><span>عدد مرات الدخول</span><b><?= (int)$me['login_count'] ?></b></div>
          </div>
        </div>

        <?php if ($memberOf): ?>
          <div class="panel mt-16" style="margin-top:16px">
            <h3><?= icon('landmark', 18) ?> عضويتي في الجمعيات</h3>
            <div class="list-row">
              <span class="stat-ic" style="width:36px;height:36px;display:grid;place-items:center;border-radius:10px;background:var(--indigo-bg);color:var(--indigo)"><?= icon('landmark', 18) ?></span>
              <span class="grow">
                <b><?= e((string)$memberOf['name']) ?></b>
                <span>
                  <?= e(['representative' => 'ممثل الجمعية', 'manager' => 'مدير', 'coordinator' => 'منسّق', 'viewer' => 'مشاهد'][(string)($memberOf['member_role'] ?? 'representative')] ?? 'عضو') ?>
                  · <?= e($memberOf['status'] === 'verified' ? 'موثّقة' : 'قيد التوثيق') ?>
                </span>
              </span>
              <a class="icon-btn" href="<?= e(url('charity/dashboard.php')) ?>"><?= icon('arrow-left', 14) ?>لوحة الجمعية</a>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

  <?php elseif ($tab === 'security'): ?>
    <div class="profile-grid">
      <form class="form-card" method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <h2><?= icon('lock', 20) ?> تغيير كلمة المرور</h2>
        <div class="form-grid">
          <div class="field full<?= isset($errors['current_password']) ? ' has-error' : '' ?>">
            <label for="current_password">كلمة المرور الحالية</label>
            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
            <?php if (isset($errors['current_password'])): ?><span class="err"><?= e($errors['current_password']) ?></span><?php endif; ?>
          </div>
          <div class="field<?= isset($errors['new_password']) ? ' has-error' : '' ?>">
            <label for="new_password">كلمة المرور الجديدة</label>
            <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
            <?php if (isset($errors['new_password'])): ?><span class="err"><?= e($errors['new_password']) ?></span><?php endif; ?>
          </div>
          <div class="field<?= isset($errors['new_password_confirm']) ? ' has-error' : '' ?>">
            <label for="new_password_confirm">تأكيد كلمة المرور</label>
            <input type="password" id="new_password_confirm" name="new_password_confirm" required autocomplete="new-password">
            <?php if (isset($errors['new_password_confirm'])): ?><span class="err"><?= e($errors['new_password_confirm']) ?></span><?php endif; ?>
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary" type="submit"><?= icon('key', 16) ?>تحديث كلمة المرور</button>
        </div>
      </form>

      <form class="form-card" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="notifications">
        <h2><?= icon('bell', 20) ?> الإشعارات</h2>
        <p class="form-sub">تحكّم في ما يصلك من إشعارات داخل المنصة.</p>
        <div class="setting-row">
          <div class="grow"><b>رسائل المحادثات</b><span>إشعار عند وصول رسالة جديدة.</span></div>
          <label class="toggle">
            <input type="checkbox" name="notify_messages" value="1" <?= !empty($me['notify_messages']) ? 'checked' : '' ?>>
            <i></i>
          </label>
        </div>
        <div class="setting-row">
          <div class="grow"><b>الطلبات والتبرعات</b><span>إشعار عند الطلبات الجديدة وتحديث حالتها.</span></div>
          <label class="toggle">
            <input type="checkbox" name="notify_requests" value="1" <?= !empty($me['notify_requests']) ? 'checked' : '' ?>>
            <i></i>
          </label>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary" type="submit"><?= icon('check', 16) ?>حفظ التفضيلات</button>
        </div>
      </form>
    </div>

  <?php else: ?>
    <div class="panel">
      <h3><?= icon('star', 18) ?> آخر التقييمات المستلمة</h3>
      <?php if (!$recentRatings): ?>
        <?= empty_state('لا توجد تقييمات بعد', 'أكمل معاملة وستصلك تقييمات من الطرف الآخر.', 'star') ?>
      <?php else: ?>
        <div class="list">
          <?php foreach ($recentRatings as $r): ?>
            <div class="list-row">
              <span class="avatar avatar-sm"><?= e(avatar_letter((string)$r['rater_name'])) ?></span>
              <span class="grow">
                <b><?= e((string)$r['rater_name']) ?></b>
                <?php if (!empty($r['comment'])): ?><span><?= e((string)$r['comment']) ?></span><?php endif; ?>
              </span>
              <span><?= stars((float)$r['score']) ?></span>
              <time class="text-xs text-muted"><?= e(time_ago((string)$r['created_at'])) ?></time>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
