<?php
/**
 * login.php — sign-in with throttling, CSRF validation and safe redirect.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

if (Auth::check()) {
    redirect(Auth::isAdmin() ? 'admin/dashboard.php' : 'index.php');
}

$error = '';
$email = '';
$next  = get_q('next', '') ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email    = (string)post('email', '');
    $password = (string)post('password', '');
    $remember = post('remember') !== null;
    $next     = (string)post('next', '');

    if ($email === '' || $password === '') {
        $error = 'يرجى إدخال البريد الإلكتروني وكلمة المرور.';
    } elseif (!valid_email($email)) {
        $error = 'صيغة البريد الإلكتروني غير صحيحة.';
    } else {
        $res = Auth::attempt($email, $password, $remember);
        if ($res['ok']) {
            flash('success', $res['message']);
            if ($next !== '' && !str_starts_with($next, '//') && !preg_match('#^https?://#i', $next)) {
                redirect($next);
            }
            redirect(Auth::intended());
        }
        $error = $res['message'];
    }
}

$pageTitle   = 'تسجيل الدخول | قيمة وأثر';
$currentPage = 'login';
$hideTopbar  = false;
require __DIR__ . '/includes/header.php';
?>

<div class="auth-layout">
  <aside class="auth-side">
    <span class="badge badge-soft" style="background:rgba(255,255,255,.14);color:#d1fae5;width:fit-content">
      <?= icon('leaf', 14) ?> قيمة وأثر
    </span>
    <h2>عودتك <em>قيمة</em>، ومشاركتك <em>أثر</em></h2>
    <p>سجّل دخولك لتتابع طلباتك، محادثاتك، وتبرعاتك في مكان واحد.</p>
    <div class="auth-feat"><?= icon('clipboard', 20) ?><span><b>تابع طلباتك</b><span>من الطلب إلى التسليم مع إشعارات فورية.</span></span></div>
    <div class="auth-feat"><?= icon('message-circle', 20) ?><span><b>محادثات آمنة</b><span>تفاهم مع الطرف الآخر دون كشف رقمك قبل الاتفاق.</span></span></div>
    <div class="auth-feat"><?= icon('hand-heart', 20) ?><span><b>تبرّع موثّق</b><span>إيصالات وتوزيع موثّق عبر جمعيات موثّقة.</span></span></div>
  </aside>

  <div class="auth-main">
    <div class="auth-card">
      <div class="auth-logo"><?= icon('leaf', 30) ?></div>
      <h1>تسجيل الدخول</h1>
      <p class="sub">أدخل بيانات حسابك للمتابعة</p>

      <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= icon('alert-circle', 18) ?><div class="grow"><?= e($error) ?></div></div>
      <?php endif; ?>

      <form method="post" action="<?= e(url('login.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">

        <div class="field mb-16">
          <label for="email">البريد الإلكتروني <i>*</i></label>
          <div class="input-wrap">
            <span class="icon"><?= icon('mail', 17) ?></span>
            <input type="email" id="email" name="email" value="<?= e($email) ?>" required autocomplete="email" placeholder="name@example.com" dir="ltr">
          </div>
        </div>

        <div class="field mb-16">
          <label for="password">كلمة المرور <i>*</i></label>
          <div class="input-wrap">
            <span class="icon"><?= icon('lock', 17) ?></span>
            <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="••••••••">
            <button type="button" class="eye" aria-label="إظهار كلمة المرور"><?= icon('eye', 17) ?></button>
          </div>
        </div>

        <div class="form-row mb-16">
          <label class="checkbox"><input type="checkbox" name="remember" value="1"> تذكّرني لمدة 30 يوماً</label>
          <span class="text-xs text-muted"><?= icon('shield', 13) ?> جلسة مشفّرة وآمنة</span>
        </div>

        <button class="btn btn-primary btn-block btn-lg" type="submit"><?= icon('log-in', 18) ?>دخول</button>
      </form>

      <p class="auth-switch">ليس لديك حساب؟ <a href="<?= e(url('register.php')) ?>">أنشئ حساباً الآن</a></p>

      <div class="demo-accounts">
        <b><?= icon('info', 14) ?> حسابات تجريبية (كلمة المرور: Password123!)</b>
        <div class="demo-row"><span>مسؤول المنصة</span><span dir="ltr">admin@qeemah.test</span></div>
        <div class="demo-row"><span>مستخدم</span><span dir="ltr">ahmed@qeemah.test</span></div>
        <div class="demo-row"><span>متجر</span><span dir="ltr">store@qeemah.test</span></div>
        <div class="demo-row"><span>ممثل جمعية</span><span dir="ltr">rep@albir.test</span></div>
        <div class="demo-row"><span>منسّق جمعية</span><span dir="ltr">member@albir.test</span></div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
