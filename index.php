<?php
/**
 * index.php — home page: hero search, category tabs, marketplace grid,
 * how-it-works, transaction types and the charity banner.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/components.php';

$q        = get_q('q', '') ?? '';
$catId    = get_int('category');
$city     = get_q('city', '') ?? '';
$type     = get_q('transaction_type', '') ?? '';
$sort     = get_q('sort', 'newest') ?? 'newest';
$page     = max(1, get_int('page', 1) ?? 1);
$perPage  = 12;

$filters = [
    'q'                => $q !== '' ? $q : null,
    'category_id'      => $catId,
    'city'             => $city !== '' ? $city : null,
    'transaction_type' => $type !== '' ? $type : null,
    'sort'             => $sort,
];

[$products, $pager] = ProductRepo::search($filters, $page, $perPage);

// Only log meaningful searches (keeps search_history useful for the AI layer).
if ($q !== '' || $catId || $city !== '' || $type !== '') {
    SearchRepo::log(
        Auth::check() ? Auth::id() : null,
        $q !== '' ? $q : null,
        $catId,
        $city !== '' ? $city : null,
        $type !== '' ? $type : null,
        (int)$pager['total']
    );
}

$categories = CategoryRepo::withCounts();
$favIds     = Auth::check() ? FavoriteRepo::idsFor(Auth::id()) : [];
$stats      = StatsRepo::heroCounters();
$latest     = array_slice($products, 0, 3);
$cities     = ['صنعاء', 'عدن', 'تعز', 'إب', 'الحديدة', 'حضرموت', 'المكلا'];
$charityCampaigns = CampaignRepo::activePublic();

$pageTitle   = 'قيمة وأثر | سوق مجتمعي للبيع والتبادل والتبرّع';
$currentPage = 'home';
require __DIR__ . '/includes/header.php';
?>

<section class="hero">
  <div class="container">
    <div class="hero-grid">
      <div>
        <span class="badge badge-soft" style="background:rgba(255,255,255,.14);color:#d1fae5;margin-bottom:14px">
          <?= icon('sprout', 14) ?> كل منتج له قيمة… وكل مشاركة لها أثر
        </span>
        <h1>نمنح ما لا نحتاجه <em>قيمة جديدة</em><br>ونصنع به أثراً في حياة غيرنا</h1>
        <p class="lead">سوق مجتمعي واحد يجمع البيع والتبادل والتبرّع والمجاني — مع جمعيات خيرية موثّقة توزّع تبرعاتك على المستفيدين فعلياً.</p>

        <form class="hero-search" method="get" action="<?= e(url('index.php')) ?>" role="search">
          <div class="search-field">
            <?= icon('search', 18) ?>
            <input type="search" name="q" id="liveSearch" value="<?= e($q) ?>" placeholder="ابحث عن هاتف، أثاث، كتب…" aria-label="ابحث في السوق">
          </div>
          <button class="btn btn-primary" type="submit"><?= icon('search', 17) ?>بحث</button>
          <select name="city" id="cityFilter" aria-label="المدينة">
            <option value="">كل المدن</option>
            <?php foreach ($cities as $c): ?>
              <option value="<?= e($c) ?>" <?= $city === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($type !== ''): ?><input type="hidden" name="transaction_type" value="<?= e($type) ?>"><?php endif; ?>
          <?php if ($catId): ?><input type="hidden" name="category" value="<?= (int)$catId ?>"><?php endif; ?>
        </form>

        <div class="hero-stats">
          <div><b><?= number_format((int)$stats['products']) ?></b><span>منتج معروض</span></div>
          <div><b><?= number_format((int)$stats['beneficiaries']) ?></b><span>تبرّع وصل مستحقيه</span></div>
          <div><b><?= number_format((int)$stats['associations']) ?></b><span>جمعية موثّقة</span></div>
        </div>
      </div>

      <aside class="hero-card">
        <h3><?= icon('trending', 17) ?> أحدث ما أضيف</h3>
        <?php if (!$latest): ?>
          <p style="font-size:13px;color:#bbf7d0">لا توجد منتجات بعد — كن أول من يضيف منتجاً.</p>
        <?php else: ?>
          <?php foreach ($latest as $p): ?>
            <a class="mini-item" href="<?= e(url('product_details.php?slug=' . urlencode((string)$p['slug']))) ?>">
              <img src="<?= e(product_image($p['primary_image'] ?? null)) ?>" alt="<?= e((string)$p['title']) ?>" loading="lazy">
              <span class="grow">
                <b><?= e(excerpt((string)$p['title'], 34)) ?></b>
                <span><?= e((string)$p['category_name']) ?> · <?= e((string)$p['city']) ?></span>
              </span>
              <span class="mini-price">
                <?= $p['transaction_type'] === 'sell' ? e(money($p['price'])) : e(transaction_meta()[$p['transaction_type']]['label']) ?>
              </span>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </aside>
    </div>
  </div>
</section>

<section class="section" id="market">
  <div class="container">
    <div class="section-head">
      <div>
        <span class="eyebrow">السوق</span>
        <h2><?= icon('store', 26) ?> تصفّح المنتجات</h2>
        <p>فلتر حسب النوع أو المدينة، وأضف ما يعجبك إلى مفضلتك.</p>
      </div>
      <a class="btn btn-outline btn-sm" href="<?= e(url('add_product.php')) ?>"><?= icon('plus', 16) ?>أضف منتجك</a>
    </div>

    <div class="cat-tabs" role="tablist" aria-label="التصنيفات">
      <a class="cat-tab<?= !$catId ? ' active' : '' ?>" href="<?= e(url('index.php') . '?' . http_build_query(array_filter(['q' => $q, 'city' => $city, 'transaction_type' => $type, 'sort' => $sort]))) ?>#market">
        <?= icon('package', 15) ?>الكل
      </a>
      <?php foreach ($categories as $c): ?>
        <a class="cat-tab<?= $catId === (int)$c['id'] ? ' active' : '' ?>"
           href="<?= e(url('index.php') . '?' . http_build_query(array_filter(['category' => (int)$c['id'], 'q' => $q, 'city' => $city, 'transaction_type' => $type]))) ?>#market">
          <?= icon((string)$c['icon'], 15) ?><?= e((string)$c['name_ar']) ?>
          <span class="text-xs" style="opacity:.75">(<?= (int)$c['products_count'] ?>)</span>
        </a>
      <?php endforeach; ?>
    </div>

    <form class="filters mt-16" method="get" action="<?= e(url('index.php')) ?>#market">
      <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
      <?php if ($catId): ?><input type="hidden" name="category" value="<?= (int)$catId ?>"><?php endif; ?>
      <select name="transaction_type" id="typeFilter" aria-label="نوع المعاملة">
        <option value="">كل الأنواع</option>
        <?php foreach (transaction_meta() as $key => $meta): ?>
          <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="city" aria-label="المدينة">
        <option value="">كل المدن</option>
        <?php foreach ($cities as $c): ?>
          <option value="<?= e($c) ?>" <?= $city === $c ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="sort" aria-label="الترتيب">
        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>الأحدث</option>
        <option value="cheapest" <?= $sort === 'cheapest' ? 'selected' : '' ?>>الأقل سعراً</option>
        <option value="priciest" <?= $sort === 'priciest' ? 'selected' : '' ?>>الأعلى سعراً</option>
        <option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>الأكثر مشاهدة</option>
      </select>
      <button class="btn btn-primary btn-sm" type="submit"><?= icon('filter', 15) ?>تطبيق</button>
      <a class="btn btn-ghost btn-sm" href="<?= e(url('index.php')) ?>#market"><?= icon('refresh', 15) ?>إعادة تعيين</a>
    </form>

    <p class="results-count" id="resultsCount">عرض <?= count($products) ?> من <?= (int)$pager['total'] ?> منتج</p>

    <div class="product-grid" id="productGrid">
      <?php foreach ($products as $p): ?>
        <?= product_card($p, $favIds) ?>
      <?php endforeach; ?>
    </div>

    <?php if (!$products): ?>
      <?= empty_state(
          'لا توجد نتائج مطابقة',
          'جرّب كلمات أخرى أو أزل بعض الفلاتر. يمكنك أيضاً إضافة منتجك الأول للمنصة.',
          'search',
          'أضف منتجاً',
          url('add_product.php')
      ) ?>
    <?php endif; ?>

    <?= pagination_links($pager, array_filter([
        'q' => $q, 'category' => $catId, 'city' => $city,
        'transaction_type' => $type, 'sort' => $sort,
    ])) ?>
  </div>
</section>

<section class="section" style="background:#fff;border-block:1px solid var(--line)">
  <div class="container">
    <div class="section-head">
      <div>
        <span class="eyebrow">كيف يعمل</span>
        <h2><?= icon('help', 26) ?> أربع خطوات فقط</h2>
        <p>من إضافة المنتج إلى تسليمه أو توزيعه على المستفيدين.</p>
      </div>
    </div>
    <div class="how-grid">
      <div class="how-card">
        <span class="how-num">1</span>
        <div class="how-icon"><?= icon('plus-circle', 30) ?></div>
        <h3>أضف منتجك</h3>
        <p>صوّر المنتج واكتب وصفاً، واختر نوع المعاملة: بيع، تبادل، تبرّع أو مجاني.</p>
      </div>
      <div class="how-card">
        <span class="how-num">2</span>
        <div class="how-icon"><?= icon('clipboard', 30) ?></div>
        <h3>استلم الطلبات</h3>
        <p>تصلك طلبات من المهتمين، وتقبل الطلب المناسب فيُحجز المنتج تلقائياً.</p>
      </div>
      <div class="how-card">
        <span class="how-num">3</span>
        <div class="how-icon"><?= icon('handshake', 30) ?></div>
        <h3>اتفق وسلّم</h3>
        <p>تواصل عبر المحادثة، وحدّد طريقة التسليم أو الاستلام في مدينتك.</p>
      </div>
      <div class="how-card">
        <span class="how-num">4</span>
        <div class="how-icon"><?= icon('star', 30) ?></div>
        <h3>قيّم التجربة</h3>
        <p>بعد إتمام المعاملة قيّم الطرف الآخر لتبني سمعة موثوقة داخل المجتمع.</p>
      </div>
    </div>

    <div class="types-grid">
      <?php foreach (transaction_meta() as $key => $meta):
          $desc = [
              'sell'   => 'بِع ما لا تحتاجه بسعر عادل، بدون بوابة دفع — الاتفاق والتسليم مباشرة بينكما.',
              'swap'   => 'بادِل منتجك بمنتج آخر، ويُربط طلبك بالمنتج المعروض والمطلوب معاً.',
              'donate' => 'تبرّع بمنتجك لجمعية موثّقة (تبرّع موجّه) أو اتركه مفتوحاً لأي جمعية (تبرّع عام).',
              'free'   => 'امنح منتجك مجاناً لمن يحتاجه فعلاً، بدون أي مقابل مادي.',
          ][$key];
      ?>
        <div class="type-box">
          <div class="type-emoji"><?= icon($meta['icon'], 32) ?></div>
          <h3><?= e($meta['label']) ?></h3>
          <p><?= e($desc) ?></p>
          <a class="btn btn-outline btn-sm" href="<?= e(url('index.php?transaction_type=' . $key)) ?>#market">تصفّح</a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($charityCampaigns): ?>
<section class="section">
  <div class="container">
    <div class="section-head">
      <div>
        <span class="eyebrow">الجمعيات</span>
        <h2><?= icon('megaphone', 26) ?> حملات تنتظر مشاركتك</h2>
        <p>تبرّعك العيني يصل لمستحقيه عبر جمعيات موثّقة، مع إيصال وتوزيع موثّق.</p>
      </div>
      <a class="btn btn-outline btn-sm" href="<?= e(url('charity_campaigns.php')) ?>">كل الحملات<?= icon('arrow-left', 15) ?></a>
    </div>
    <div class="charity-grid">
      <?php foreach (array_slice($charityCampaigns, 0, 3) as $c):
          $pct = CampaignRepo::progressPct($c);
      ?>
        <article class="charity-card">
          <div class="logo-sm"><?= icon('hand-heart', 24) ?></div>
          <b style="font-size:16px;display:block"><?= e((string)$c['title']) ?></b>
          <span class="text-xs text-muted"><?= e((string)$c['association_name']) ?> · <?= e((string)$c['association_city']) ?></span>
          <p class="text-sm mt-8" style="color:var(--muted)"><?= e(excerpt((string)$c['description'], 110)) ?></p>
          <div class="progress mt-16"><i style="width:<?= $pct ?>%"></i></div>
          <div class="progress-label"><span><?= (int)$c['received_items'] ?> من <?= (int)$c['target_items'] ?> عنصر</span><b><?= $pct ?>%</b></div>
          <a class="btn btn-primary btn-sm btn-block mt-16" href="<?= e(url('campaign.php?slug=' . urlencode((string)$c['slug']))) ?>"><?= icon('hand-heart', 15) ?>شارك في الحملة</a>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="section" style="padding-top:0">
  <div class="container">
    <div class="charity-banner">
      <div>
        <h2><?= icon('landmark', 26) ?> هل تدير جمعية خيرية؟</h2>
        <p>سجّل جمعيتك، واستلم التبرعات العينية، وأدِر الحملات والمستفيدين وسجّل التوزيع وأصدر الإيصالات — كل ذلك من لوحة واحدة.</p>
      </div>
      <a class="btn btn-lg" href="<?= Auth::check() ? e(url('register.php?type=association_rep')) : e(url('register.php')) ?>">
        <?= icon('landmark', 18) ?>انضم كجمعية
      </a>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
