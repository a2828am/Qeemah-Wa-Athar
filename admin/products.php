<?php
/**
 * admin/products.php — moderation of every listing (remove / restore),
 * with search, status and type filters.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/components.php';

Gate::requireAdmin();
$adminId = (int)Auth::id();

$filters = [
    'q'                => get_q('q', '') ?? '',
    'status'           => get_q('status', '') ?? '',
    'transaction_type' => get_q('transaction_type', '') ?? '',
    'category_id'      => get_int('category', 0),
];
$page = max(1, get_int('page', 1) ?? 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action    = (string)post('action', '');
    $productId = post_int('product_id', 0) ?? 0;
    $product   = $productId ? ProductRepo::find($productId) : null;
    try {
        if (!$product) {
            throw new RuntimeException('المنتج غير موجود.');
        }
        $newStatus = $action === 'remove' ? 'removed' : 'available';
        ProductRepo::setStatus($productId, $newStatus);
        AuditRepo::log($adminId, 'product.' . $newStatus, 'product', $productId,
            ['status' => $product['status']], ['status' => $newStatus]);
        NotificationRepo::push((int)$product['owner_id'], 'system',
            $action === 'remove' ? 'تم إزالة منتجك' : 'تمت إعادة منتجك',
            'الإعلان: ' . excerpt((string)$product['title'], 60) . ($action === 'remove' ? ' — خالف شروط النشر.' : ' — أصبح متاحاً من جديد.'),
            'my_requests.php?tab=listings', 'package');
        flash('success', $action === 'remove' ? 'تم إزالة المنتج من السوق.' : 'تمت إعادة المنتج إلى السوق.');
    } catch (Throwable $ex) {
        flash('error', $ex->getMessage());
    }
    redirect('admin/products.php?' . http_build_query(array_filter($filters)));
}

$searchFilters = $filters;
$searchFilters['status'] = $filters['status'] !== '' ? $filters['status'] : null;
if ($searchFilters['status'] === null) {
    // Show everything including removed items for moderation.
    $rows = Repo::q(
        "SELECT p.*, c.name_ar AS category_name, u.full_name AS owner_name,
                (SELECT file_path FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.is_primary DESC, pi.sort_order LIMIT 1) AS primary_image,
                (SELECT COUNT(*) FROM user_requests r WHERE r.product_id=p.id) AS requests_count
           FROM products p
           JOIN categories c ON c.id=p.category_id
           JOIN users u ON u.id=p.owner_id
          WHERE (?='' OR p.title LIKE ? OR p.description LIKE ?)
            AND (?=0 OR p.category_id=?)
            AND (?='' OR p.transaction_type=?)
          ORDER BY p.created_at DESC LIMIT 200",
        [
            $filters['q'], '%' . $filters['q'] . '%', '%' . $filters['q'] . '%',
            (int)$filters['category_id'], (int)$filters['category_id'],
            $filters['transaction_type'], $filters['transaction_type'],
        ]
    );
    $pager = paginate(count($rows), 200, 1);
} else {
    [$rows, $pager] = ProductRepo::search($searchFilters, $page, 20);
}
$categories = CategoryRepo::all();
$statusCounts = [];
foreach (Repo::q('SELECT status, COUNT(*) n FROM products GROUP BY status') as $row) {
    $statusCounts[(string)$row['status']] = (int)$row['n'];
}

$pageTitle   = 'إدارة المنتجات | قيمة وأثر';
$currentPage = 'admin';
$adminPage   = 'products';
$pageHead    = [
    'title'    => icon('package', 28) . ' المنتجات',
    'subtitle' => 'إشراف كامل على الإعلانات: إزالة المخالف أو إعادة تفعيله — مع إشعار لصاحب الإعلان.',
];
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_nav.php';
?>

<div class="container section" style="padding-top:24px">
  <div class="tabs mb-16">
    <a class="tab<?= $filters['status'] === '' ? ' active' : '' ?>" href="<?= e(url('admin/products.php')) ?>">الكل</a>
    <?php foreach (product_status_meta() as $key => $meta): ?>
      <a class="tab<?= $filters['status'] === $key ? ' active' : '' ?>" href="<?= e(url('admin/products.php?status=' . $key)) ?>">
        <?= icon($meta['icon'], 14) ?><?= e($meta['label']) ?>
        <span class="text-xs" style="opacity:.7">(<?= (int)($statusCounts[$key] ?? 0) ?>)</span>
      </a>
    <?php endforeach; ?>
  </div>

  <form class="filters mb-16" method="get" action="<?= e(url('admin/products.php')) ?>">
    <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
    <div class="search-field">
      <?= icon('search', 16) ?>
      <input type="search" name="q" value="<?= e((string)$filters['q']) ?>" placeholder="عنوان المنتج أو الوصف…">
    </div>
    <select name="category">
      <option value="">كل التصنيفات</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)$filters['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e((string)$c['name_ar']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="transaction_type">
      <option value="">كل الأنواع</option>
      <?php foreach (transaction_meta() as $k => $m): ?>
        <option value="<?= e($k) ?>" <?= $filters['transaction_type'] === $k ? 'selected' : '' ?>><?= e($m['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary btn-sm" type="submit"><?= icon('filter', 15) ?>تصفية</button>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('admin/products.php')) ?>"><?= icon('refresh', 15) ?>إعادة تعيين</a>
  </form>

  <div class="table-wrap">
    <table>
      <thead><tr><th>المنتج</th><th>المالك</th><th>النوع</th><th>السعر</th><th>الطلبات</th><th>الحالة</th><th>إجراءات</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $p): ?>
          <tr>
            <td>
              <div class="flex items-center gap-8">
                <img class="thumb" src="<?= e(product_image($p['primary_image'] ?? null)) ?>" alt="">
                <div>
                  <a class="table-title" href="<?= e(url('product_details.php?slug=' . urlencode((string)$p['slug']))) ?>" target="_blank" rel="noopener"><?= e(excerpt((string)$p['title'], 42)) ?></a>
                  <span class="table-sub"><?= e((string)$p['category_name']) ?> · <?= e((string)$p['city']) ?> · #<?= (int)$p['id'] ?></span>
                </div>
              </div>
            </td>
            <td><span class="table-title"><?= e((string)$p['owner_name']) ?></span></td>
            <td><?= op_badge((string)$p['transaction_type']) ?></td>
            <td><?= e($p['transaction_type'] === 'sell' ? money($p['price']) : '—') ?></td>
            <td><?= (int)($p['requests_count'] ?? 0) ?></td>
            <td><?= prod_status((string)$p['status']) ?></td>
            <td>
              <div class="row-actions">
                <?php if ($p['status'] === 'removed'): ?>
                  <form method="post" data-ajax="api/admin_action.php" data-confirm="إعادة المنتج للسوق؟" data-done="reload">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="product_restore">
                    <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                    <button class="icon-btn" type="submit"><?= icon('refresh', 13) ?>إعادة</button>
                  </form>
                <?php else: ?>
                  <form method="post" data-ajax="api/admin_action.php" data-confirm="إزالة المنتج من السوق؟" data-done="reload">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="product_remove">
                    <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                    <button class="icon-btn danger" type="submit"><?= icon('trash', 13) ?>إزالة</button>
                  </form>
                <?php endif; ?>
                <a class="icon-btn" href="<?= e(url('product_details.php?slug=' . urlencode((string)$p['slug']))) ?>" target="_blank" rel="noopener"><?= icon('eye', 13) ?>عرض</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (!$rows): ?>
    <?= empty_state('لا توجد منتجات مطابقة', 'جرّب تغيير الفلاتر أو كلمة البحث.', 'package') ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
