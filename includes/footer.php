<?php
/**
 * includes/footer.php
 * -----------------------------------------------------------------------------
 * Shared footer, mobile bottom navigation and script loading.
 */
declare(strict_types=1);

$currentPage = $currentPage ?? 'home';
$mobileLink  = static function (string $key, string $href, string $icon, string $label, string $current): string {
    $active = $current === $key ? ' class="active"' : '';
    return '<a href="' . e($href) . '"' . $active . '><span>' . icon($icon, 21) . '</span>' . $label . '</a>';
};
$mobileAdd = Auth::check() ? url('add_product.php') : url('login.php?next=' . urlencode('add_product.php'));
?>
</main>

<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">
      <div>
        <h4><?= icon('leaf', 18) ?>قيمة وأثر</h4>
        <p style="font-size:14px;max-width:320px;line-height:1.9">منصة مجتمعية تربط الأفراد والمتاجر والجمعيات لتقليل الهدر وتعزيز التكافل — بيع، تبادل، تبرّع، ومجاني في مكان واحد.</p>
        <div class="need-tags" style="margin-top:12px">
          <span style="background:rgba(255,255,255,.1);color:#a7f3d0">بيع</span>
          <span style="background:rgba(255,255,255,.1);color:#a7f3d0">تبادل</span>
          <span style="background:rgba(255,255,255,.1);color:#a7f3d0">تبرّع</span>
          <span style="background:rgba(255,255,255,.1);color:#a7f3d0">مجاني</span>
        </div>
      </div>

      <div>
        <h4>روابط سريعة</h4>
        <a href="<?= e(url('index.php')) ?>"><?= icon('store', 15) ?>السوق</a>
        <a href="<?= e(url('add_product.php')) ?>"><?= icon('plus-circle', 15) ?>أضف منتج</a>
        <a href="<?= e(url('my_requests.php')) ?>"><?= icon('clipboard', 15) ?>طلباتي</a>
        <a href="<?= e(url('favorites.php')) ?>"><?= icon('heart', 15) ?>المفضلة</a>
        <a href="<?= e(url('chat.php')) ?>"><?= icon('message-circle', 15) ?>المحادثات</a>
        <a href="<?= e(url('notifications.php')) ?>"><?= icon('bell', 15) ?>الإشعارات</a>
      </div>

      <div>
        <h4>أنواع المعاملات</h4>
        <a href="<?= e(url('index.php?transaction_type=sell')) ?>"><?= icon('tag', 15) ?>للبيع</a>
        <a href="<?= e(url('index.php?transaction_type=swap')) ?>"><?= icon('repeat', 15) ?>تبادل (مقايضة)</a>
        <a href="<?= e(url('charity/campaigns.php')) ?>"><?= icon('hand-heart', 15) ?>تبرّع</a>
        <a href="<?= e(url('index.php?transaction_type=free')) ?>"><?= icon('gift', 15) ?>مجاني</a>
        <a href="<?= e(url('charity/dashboard.php')) ?>"><?= icon('landmark', 15) ?>لوحة الجمعيات</a>
      </div>

      <div>
        <h4>تواصل معنا</h4>
        <?php if (!Auth::check()): ?>
          <a href="<?= e(url('login.php')) ?>"><?= icon('log-in', 15) ?>تسجيل الدخول</a>
          <a href="<?= e(url('register.php')) ?>"><?= icon('user-plus', 15) ?>إنشاء حساب</a>
        <?php else: ?>
          <a href="<?= e(url('profile.php')) ?>"><?= icon('user', 15) ?>ملفي الشخصي</a>
          <a href="<?= e(url('logout.php')) ?>" data-logout><?= icon('log-out', 15) ?>تسجيل الخروج</a>
        <?php endif; ?>
        <a href="mailto:info@qeemah-wa-athar.org"><?= icon('mail', 15) ?>info@qeemah-wa-athar.org</a>
        <a href="tel:+9671234567"><?= icon('phone', 15) ?>01-234567</a>
        <a href="<?= e(url('index.php')) ?>"><?= icon('map-pin', 15) ?>صنعاء، اليمن</a>
      </div>
    </div>

    <div class="footer-bottom">
      <span>© <?= date('Y') ?> قيمة وأثر — Qeemah wa Athar</span>
      <span>صُنع للمجتمع والبيئة · جميع الحقوق محفوظة</span>
    </div>
  </div>
</footer>

<nav class="mobile-nav" aria-label="تنقل الجوال">
  <nav>
    <?= $mobileLink('home', url('index.php'), 'home', 'الرئيسية', $currentPage) ?>
    <?= $mobileLink('market', url('index.php') . '#market', 'store', 'السوق', $currentPage) ?>
    <?= $mobileLink('add', $mobileAdd, 'plus-circle', 'أضف', $currentPage) ?>
    <?= $mobileLink('charity', url('charity/campaigns.php'), 'hand-heart', 'تبرّع', $currentPage) ?>
    <?php if (Auth::check()): ?>
      <?= $mobileLink('dashboard', url('my_requests.php'), 'dashboard', 'لوحتي', $currentPage) ?>
    <?php else: ?>
      <?= $mobileLink('dashboard', url('login.php'), 'dashboard', 'لوحتي', $currentPage) ?>
    <?php endif; ?>
  </nav>
</nav>

<div class="toast" role="status" aria-live="polite"></div>

<?php
/* Floating assistant — posts to the same endpoint as assistant.php. Renders
   nothing when the admin switched it off. */
if (!function_exists('assistant_widget_render')) {
    @require_once __DIR__ . '/assistant_widget.php';
}
if (function_exists('assistant_widget_render') && class_exists('AssistantGuides')
    && (bool)SettingsRepo::get('ai_enabled', true)) {
    assistant_widget_render(true, (string)($currentPage ?? ''));
}
?>

<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
