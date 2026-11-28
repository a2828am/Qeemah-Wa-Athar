<?php
/**
 * includes/header.php
 * -----------------------------------------------------------------------------
 * Shared document head, top bar, site header, flash messages and mobile nav.
 *
 * Expected variables (all optional):
 *   $pageTitle   string   – <title> text
 *   $pageDesc    string   – meta description
 *   $currentPage string   – key used to highlight navigation
 *   $hideTopbar  bool     – hide the dark utility bar
 *   $bodyClass   string   – extra body classes
 *   $pageHead    array|null – ['title'=>, 'subtitle'=>, 'actions'=>html]
 */
declare(strict_types=1);

if (!defined('QEEMAH_BOOTED')) {
    require_once __DIR__ . '/bootstrap.php';
}

$pageTitle   = $pageTitle   ?? 'قيمة وأثر | Qeemah wa Athar';
$pageDesc    = $pageDesc    ?? 'منصة مجتمعية للبيع والتبادل والتبرّع والمجاني — نمنح كل منتج قيمة، ونصنع أثراً.';
$currentPage = $currentPage ?? 'home';
$hideTopbar  = $hideTopbar  ?? false;
$bodyClass   = $bodyClass   ?? '';
$pageHead    = $pageHead    ?? null;

$me        = Auth::user();
$isAdmin   = Auth::isAdmin();
$isAssoc   = Auth::isAssociationMember();
$siteName  = (string)SettingsRepo::get('site_name', 'قيمة وأثر');
$unreadMsg = $me ? ChatRepo::unreadTotal((int)$me['id']) : 0;
$unreadNtf = $me ? NotificationRepo::unreadCount((int)$me['id']) : 0;
$favCount  = $me ? FavoriteRepo::countFor((int)$me['id']) : 0;
$flashBag  = take_flashes();

$navLink = static function (string $key, string $href, string $icon, string $label, string $current): string {
    $active = $current === $key ? ' class="active" aria-current="page"' : '';
    return '<a href="' . e($href) . '"' . $active . '>' . icon($icon, 17, 'vert-ic') . $label . '</a>';
};
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#14532d">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($pageDesc) ?>">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:description" content="<?= e($pageDesc) ?>">
<meta property="og:type" content="website">
<meta property="og:locale" content="ar_YE">
<link rel="icon" href="<?= e(url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="<?= e($bodyClass) ?>" data-base="<?= e(url('')) ?>" data-notif-poll="<?= (int)SettingsRepo::get('notif_poll_interval_ms', 15000) ?>">
<a class="skip-link" href="#main">تجاوز إلى المحتوى</a>

<?php if (!$hideTopbar): ?>
<div class="topbar">
  <div class="container">
    <span><?= icon('leaf', 15) ?>منصة مجتمعية لتقليل الهدر وتعزيز التكافل الاجتماعي</span>
    <span>
      <a href="<?= e(url('charity/campaigns.php')) ?>">الحملات الخيرية</a>
      ·
      <a href="<?= e(url('add_product.php')) ?>">أضف منتجك مجاناً</a>
    </span>
  </div>
</div>
<?php endif; ?>

<header class="site-header">
  <div class="container nav-wrap">
    <a class="logo" href="<?= e(url('index.php')) ?>" aria-label="<?= e($siteName) ?> — الرئيسية">
      <span class="logo-mark"><?= icon('leaf', 24) ?></span>
      <span class="logo-text"><b><?= e($siteName) ?></b><span>Qeemah wa Athar</span></span>
    </a>

    <nav class="main-nav" id="mainNav" aria-label="التنقل الرئيسي">
      <?= $navLink('home', url('index.php'), 'home', 'الرئيسية', $currentPage) ?>
      <?= $navLink('market', url('index.php') . '#market', 'store', 'السوق', $currentPage) ?>
      <?= $navLink('charity', url('charity/campaigns.php'), 'hand-heart', 'التبرعات', $currentPage) ?>
      <?= $navLink('assistant', url('assistant.php'), 'help', 'المساعد', $currentPage) ?>
      <?= $navLink('add', url('add_product.php'), 'plus-circle', 'أضف منتج', $currentPage) ?>
      <?= $navLink('requests', url('my_requests.php'), 'clipboard', 'طلباتي', $currentPage) ?>
      <?php if ($isAssoc): ?>
        <?= $navLink('charity_panel', url('charity/dashboard.php'), 'landmark', 'لوحة الجمعية', $currentPage) ?>
      <?php elseif ($me): ?>
        <?php /* Individuals looking for an association need a way back to their
                 request after leaving the registration page. */ ?>
        <?= $navLink('my_associations', url('my_associations.php'), 'landmark', 'جمعياتي', $currentPage) ?>
      <?php endif; ?>
      <?php if ($isAdmin): ?>
        <?= $navLink('admin', url('admin/dashboard.php'), 'shield', 'الإدارة', $currentPage) ?>
      <?php endif; ?>
    </nav>

    <div class="nav-actions">
      <?php if ($me): ?>
        <a class="icon-badge" href="<?= e(url('notifications.php')) ?>" title="الإشعارات" aria-label="الإشعارات">
          <?= icon('bell', 19) ?>
          <span class="dot" data-badge="notifications" <?= $unreadNtf > 0 ? '' : 'hidden' ?>><?= $unreadNtf > 99 ? '99+' : (int)$unreadNtf ?></span>
        </a>
        <a class="icon-badge" href="<?= e(url('chat.php')) ?>" title="المحادثات" aria-label="المحادثات">
          <?= icon('message-circle', 19) ?>
          <span class="dot" data-badge="messages" <?= $unreadMsg > 0 ? '' : 'hidden' ?>><?= $unreadMsg > 99 ? '99+' : (int)$unreadMsg ?></span>
        </a>
      <?php endif; ?>

      <a class="btn btn-ghost btn-sm" href="<?= e(url('favorites.php')) ?>" title="المفضلة" aria-label="المفضلة">
        <?= icon('heart', 18) ?><span class="nowrap"><?= $favCount > 0 ? (int)$favCount : 'المفضلة' ?></span>
      </a>

      <?php if ($me): ?>
        <a class="user-chip" href="<?= e(url('profile.php')) ?>" title="ملفي الشخصي">
          <span class="avatar avatar-sm"><?= e(avatar_letter((string)$me['full_name'])) ?></span>
          <span><?= e(Auth::firstName()) ?></span>
        </a>
        <a class="btn btn-ghost btn-sm logout-link" href="<?= e(url('logout.php')) ?>" data-logout title="تسجيل الخروج" aria-label="تسجيل الخروج">
          <?= icon('log-out', 16) ?>خروج
        </a>
      <?php else: ?>
        <a class="btn btn-outline btn-sm" href="<?= e(url('login.php')) ?>"><?= icon('log-in', 16) ?>دخول</a>
        <a class="btn btn-primary btn-sm" href="<?= e(url('register.php')) ?>"><?= icon('user-plus', 16) ?>حساب جديد</a>
      <?php endif; ?>

      <button class="hamburger" type="button" aria-label="فتح القائمة" aria-expanded="false" aria-controls="mainNav">
        <?= icon('menu', 22, 'ic-menu') ?><?= icon('x', 22, 'ic-close') ?>
      </button>
    </div>
  </div>
</header>

<main id="main">
<?php if ($pageHead !== null): ?>
  <section class="page-head">
    <div class="container">
      <h1><?= $pageHead['title'] ?? '' ?></h1>
      <?php if (!empty($pageHead['subtitle'])): ?><p><?= e((string)$pageHead['subtitle']) ?></p><?php endif; ?>
      <?php if (!empty($pageHead['actions'])): ?><div class="page-head-actions"><?= $pageHead['actions'] ?></div><?php endif; ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($flashBag): ?>
  <div class="container" style="padding-top:18px">
    <?php foreach ($flashBag as $f):
        $type = in_array($f['type'], ['success', 'error', 'info', 'warning'], true) ? $f['type'] : 'info';
        $ico  = ['success' => 'check-circle', 'error' => 'alert-circle', 'warning' => 'alert-triangle', 'info' => 'info'][$type];
    ?>
      <div class="alert alert-<?= e($type) ?>" role="status">
        <?= icon($ico, 18) ?>
        <div class="grow"><?= e($f['message']) ?></div>
        <button type="button" class="modal-close" data-dismiss aria-label="إغلاق" style="float:none;flex-shrink:0"><?= icon('x', 15) ?></button>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
