<?php
/**
 * favorites.php — the signed-in user's saved products.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/components.php';

Auth::requireLogin('favorites.php');
$me    = Auth::user();
$uid   = (int)$me['id'];
$items = FavoriteRepo::listFor($uid);

$pageTitle   = 'المفضلة | قيمة وأثر';
$currentPage = 'favorites';
$pageHead    = [
    'title'    => icon('heart', 28) . ' المفضلة',
    'subtitle' => 'المنتجات التي أعجبتك — تابعها قبل أن يسبقك أحد.',
    'actions'  => '<a class="btn btn-outline btn-sm" href="' . e(url('index.php')) . '#market">' . icon('store', 15) . 'تصفّح السوق</a>',
];
require __DIR__ . '/includes/header.php';
?>

<div class="container section" style="padding-top:26px">
  <?php if (!$items): ?>
    <div class="fav-empty">
      <div class="empty-ic" style="width:64px;height:64px;border-radius:20px;background:var(--green-50);color:var(--green-700);display:grid;place-items:center;margin:0 auto 14px">
        <?= icon('heart', 30) ?>
      </div>
      <h3 style="font-size:18px;margin-bottom:6px">مفضلتك فارغة</h3>
      <p style="color:var(--muted);font-size:14px;max-width:420px;margin:0 auto 14px">
        اضغط أيقونة القلب على أي منتج لحفظه هنا ومتابعته لاحقاً.
      </p>
      <a class="btn btn-primary btn-sm" href="<?= e(url('index.php')) ?>#market"><?= icon('search', 15) ?>اكتشف المنتجات</a>
    </div>
  <?php else: ?>
    <p class="results-count">لديك <?= count($items) ?> منتجاً في المفضلة.</p>
    <div class="product-grid">
      <?php foreach ($items as $p): ?>
        <?= product_card($p, array_map('intval', array_column($items, 'id'))) ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
