<?php
/**
 * includes/admin_nav.php — shared admin navigation + page shell helpers.
 * Expects: $adminPage (key), $pageHead (title/subtitle/actions).
 */
declare(strict_types=1);

$adminItems = [
    'dashboard'    => ['label' => 'نظرة عامة',      'icon' => 'dashboard',   'href' => url('admin/dashboard.php')],
    'users'        => ['label' => 'المستخدمون',      'icon' => 'users',       'href' => url('admin/users.php')],
    'associations' => ['label' => 'الجمعيات',        'icon' => 'landmark',    'href' => url('admin/associations.php')],
    'products'     => ['label' => 'المنتجات',        'icon' => 'package',     'href' => url('admin/products.php')],
    'requests'     => ['label' => 'الطلبات',         'icon' => 'route',       'href' => url('admin/requests.php')],
    'reports'      => ['label' => 'البلاغات',        'icon' => 'flag',        'href' => url('admin/reports.php')],
    'audit'        => ['label' => 'سجل التدقيق',     'icon' => 'shield-check','href' => url('admin/audit_logs.php')],
    'settings'     => ['label' => 'الإعدادات',       'icon' => 'settings',    'href' => url('admin/settings.php')],
];
$adminPage = $adminPage ?? 'dashboard';
?>
<div class="admin-bar">
  <div class="container">
    <nav class="dash-tabs" style="margin:0" aria-label="تنقل الإدارة">
      <?php foreach ($adminItems as $key => $item): ?>
        <a class="dash-tab<?= $adminPage === $key ? ' active' : '' ?>" href="<?= e($item['href']) ?>">
          <?= icon($item['icon'], 15) ?><?= e($item['label']) ?>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>
</div>
