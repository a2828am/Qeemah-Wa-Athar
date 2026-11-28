<?php
/**
 * add_product.php — publish a new listing, or edit one you own (?edit=ID).
 * Supports the four transaction types, up to four photos, targeted/general
 * donations and the non-blocking AI listing helper.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

Auth::requireLogin('add_product.php');

$me      = Auth::user();
$userId  = (int)$me['id'];
$editId  = get_int('edit');
$editing = null;
$errors  = [];

// Opened from a campaign page: prefill a donation aimed at that campaign.
$campaignId = get_int('campaign', 0) ?? 0;
$campaign   = $campaignId > 0 ? CampaignRepo::find($campaignId) : null;
if (!$campaign || $campaign['status'] !== 'active') {
    $campaignId = 0;
    $campaign   = null;
}

if ($editId) {
    $editing = ProductRepo::find($editId);
    if (!$editing) {
        http_response_code(404);
        flash('error', 'المنتج غير موجود.');
        redirect('index.php');
    }
    if (!Gate::canManageProduct($editing)) {
        http_response_code(403);
        flash('error', 'لا تملك صلاحية تعديل هذا المنتج.');
        redirect('index.php');
    }
}

$defaults = [
    'title'                => $editing['title'] ?? '',
    'description'          => $editing['description'] ?? '',
    'category_id'          => (int)($editing['category_id'] ?? 0),
    'condition_type'       => $editing['condition_type'] ?? 'good',
    'transaction_type'     => $editing['transaction_type'] ?? ($campaign ? 'donate' : 'sell'),
    'price'                => $editing['price'] ?? '',
    'swap_wanted'          => $editing['swap_wanted'] ?? '',
    'city'                 => $editing['city'] ?? ($me['city'] ?? ''),
    'address_hint'         => $editing['address_hint'] ?? '',
    'donation_scope'       => $editing['donation_scope'] ?? 'general',
    'target_association_id'=> (int)($editing['target_association_id'] ?? 0),
    'campaign_id'          => $campaign ? (int)$campaign['id'] : 0,
];

$categories  = CategoryRepo::withCounts();
$cities      = ['صنعاء', 'عدن', 'تعز', 'إب', 'الحديدة', 'حضرموت', 'المكلا', 'ذمار'];
$associations = AssociationRepo::verified();
$currentImages = $editing ? ProductRepo::images((int)$editing['id']) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $data = [
        'title'            => (string)post('title', ''),
        'description'      => (string)post('description', ''),
        'category_id'      => post_int('category_id', 0),
        'condition_type'   => (string)post('condition_type', 'good'),
        'transaction_type' => (string)post('transaction_type', 'sell'),
        'price'            => post('price', ''),
        'swap_wanted'      => (string)post('swap_wanted', ''),
        'city'             => (string)post('city', ''),
        'address_hint'     => (string)post('address_hint', ''),
        'donation_scope'   => (string)post('donation_scope', 'general'),
        'target_association_id' => post_int('target_association_id', 0),
        'campaign_id'      => post_int('campaign_id', 0) ?? 0,
        'quantity'         => (string)post('quantity', ''),
    ];

    if (mb_strlen($data['title']) < 5) {
        $errors['title'] = 'العنوان قصير جداً (5 أحرف على الأقل).';
    }
    if (mb_strlen($data['description']) < 20) {
        $errors['description'] = 'اكتب وصفاً أوضح (20 حرفاً على الأقل).';
    }
    if (!in_array($data['condition_type'], ['new', 'like_new', 'good', 'fair', 'used'], true)) {
        $errors['condition_type'] = 'اختر حالة المنتج.';
    }
    if (!in_array($data['transaction_type'], ['sell', 'swap', 'donate', 'free'], true)) {
        $errors['transaction_type'] = 'اختر نوع المعاملة.';
    }
    if ($data['category_id'] <= 0) {
        $errors['category_id'] = 'اختر التصنيف.';
    }
    if ($data['city'] === '') {
        $errors['city'] = 'المدينة مطلوبة.';
    }
    if ($data['transaction_type'] === 'sell') {
        $price = (float)str_replace(',', '', (string)$data['price']);
        if ($price <= 0) {
            $errors['price'] = 'أدخل سعراً صحيحاً أكبر من صفر.';
        }
        $data['price'] = $price;
    } else {
        $data['price'] = null;
    }
    if ($data['transaction_type'] === 'swap' && mb_strlen((string)$data['swap_wanted']) < 3) {
        $errors['swap_wanted'] = 'اذكر ما ترغب بمقايضته (مثال: هاتف أو ساعة).';
    }
    if ($data['transaction_type'] === 'donate') {
        if (!in_array($data['donation_scope'], ['general', 'targeted'], true)) {
            $data['donation_scope'] = 'general';
        }
        if ($data['donation_scope'] === 'targeted' && !$data['target_association_id']) {
            $errors['target_association_id'] = 'اختر الجمعية التي تريد توجيه التبرّع إليها.';
        }
        // A general donation has no association; targeted needs a real, verified one.
        $data['target_association_id'] = $data['donation_scope'] === 'targeted'
            ? (int)$data['target_association_id'] : null;
    } else {
        $data['donation_scope'] = null;
        $data['target_association_id'] = null;
    }

    // Photos: validate up to four files before touching the database.
    $uploads = [];
    if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {
        $count = count($_FILES['images']['name']);
        for ($i = 0; $i < min($count, 4); $i++) {
            if (($_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $uploads[] = [
                'name'     => $_FILES['images']['name'][$i],
                'type'     => $_FILES['images']['type'][$i],
                'tmp_name' => $_FILES['images']['tmp_name'][$i],
                'error'    => $_FILES['images']['error'][$i],
                'size'     => $_FILES['images']['size'][$i],
            ];
        }
    }

    if (!$errors) {
        try {
            if ($editing) {
                ProductRepo::update((int)$editing['id'], $data);
                foreach ($uploads as $file) {
                    $path = store_uploaded_image($file, 'products');
                    ProductRepo::addImage((int)$editing['id'], $path);
                }
                ActivityRepo::log($userId, 'product.updated', 'product', (int)$editing['id']);
                flash('success', 'تم تحديث المنتج بنجاح.');
                redirect('product_details.php?slug=' . urlencode((string)$editing['slug']));
            }

            $data['owner_id'] = $userId;
            $data['status']   = 'available';
            $paths = [];
            foreach ($uploads as $file) {
                $paths[] = store_uploaded_image($file, 'products');
            }
            $newId = ProductRepo::create($data, $paths);

            // Donations started from a campaign page join that campaign immediately.
            if ((int)$data['campaign_id'] > 0 && $data['transaction_type'] === 'donate') {
                $donationId = (int)Repo::q(
                    'SELECT id FROM donations WHERE product_id=? AND donor_id=? ORDER BY id DESC LIMIT 1',
                    [$newId, $userId]
                )[0]['id'] ?? 0;
                if ($donationId > 0) {
                    CampaignRepo::attachDonation((int)$data['campaign_id'], $donationId, $userId);
                    flash('success', 'تم تسجيل تبرّعك ضمن الحملة. شكراً لك.');
                    redirect('campaign.php?id=' . (int)$data['campaign_id']);
                }
            }

            flash('success', 'تم نشر منتجك بنجاح. يمكنك الآن متابعة الطلبات الواردة.');
            redirect('product_details.php?id=' . $newId);
        } catch (Throwable $ex) {
            $errors['general'] = $ex->getMessage();
        }
    }
    $defaults = array_merge($defaults, $data);
}

$pageTitle   = ($editing ? 'تعديل منتج' : 'إضافة منتج') . ' | قيمة وأثر';
$currentPage = 'add';
$pageHead    = [
    'title'    => icon($editing ? 'edit' : 'plus-circle', 28) . ' ' . ($editing ? 'تعديل المنتج' : 'أضف منتجك'),
    'subtitle' => $editing
        ? 'حدّث بيانات الإعلان أو أضف صوراً جديدة.'
        : 'اختر نوع المعاملة، واكتب وصفاً صادقاً، وأضف صوراً واضحة — مساعد الإعلان يعمل تلقائياً.',
    'actions'  => '<a class="btn btn-outline btn-sm" href="' . e(url('my_requests.php?tab=listings')) . '">' . icon('package', 15) . 'منتجاتي</a>',
];
require __DIR__ . '/includes/header.php';
?>

<div class="container section" style="padding-top:24px">
  <div class="form-wrap wide">
    <?php if (!empty($errors['general'])): ?>
      <div class="alert alert-error"><?= icon('alert-circle', 18) ?><div class="grow"><?= e($errors['general']) ?></div></div>
    <?php elseif ($errors): ?>
      <div class="alert alert-error"><?= icon('alert-circle', 18) ?><div class="grow">يرجى تصحيح الحقول المميّزة بالأحمر.</div></div>
    <?php endif; ?>

    <form class="form-card" method="post" enctype="multipart/form-data" id="addProductForm" novalidate>
      <?= csrf_field() ?>

      <div class="form-grid">
        <div class="field full<?= isset($errors['title']) ? ' has-error' : '' ?>">
          <label for="title">عنوان المنتج <i>*</i></label>
          <input type="text" id="title" name="title" value="<?= e((string)$defaults['title']) ?>" required maxlength="160" placeholder="مثال: هاتف Samsung Galaxy A54 — 128GB">
          <span class="hint">اذكر النوع والمواصفات الأساسية في العنوان.</span>
          <?php if (isset($errors['title'])): ?><span class="err"><?= e($errors['title']) ?></span><?php endif; ?>
        </div>

        <div class="field full">
          <label>نوع المعاملة <i>*</i></label>
          <div class="op-types">
            <?php foreach (transaction_meta() as $key => $meta): ?>
              <label class="op-type">
                <input type="radio" name="transaction_type" value="<?= e($key) ?>" <?= $defaults['transaction_type'] === $key ? 'checked' : '' ?>>
                <span><?= icon($meta['icon'], 24) ?></span>
                <b><?= e($meta['label']) ?></b>
              </label>
            <?php endforeach; ?>
          </div>
          <?php if (isset($errors['transaction_type'])): ?><span class="err"><?= e($errors['transaction_type']) ?></span><?php endif; ?>
        </div>

        <div class="field full dynamic-box" id="dyn-price" <?= $defaults['transaction_type'] === 'sell' ? '' : 'hidden' ?>>
          <label for="price">السعر (ر.ي) <i>*</i></label>
          <input type="number" id="price" name="price" min="1" step="1" value="<?= e((string)$defaults['price']) ?>" placeholder="85000" inputmode="numeric">
          <span class="hint">لا توجد بوابة دفع — السعر للتفاهم فقط بينكما.</span>
          <?php if (isset($errors['price'])): ?><span class="err"><?= e($errors['price']) ?></span><?php endif; ?>
        </div>

        <div class="field full dynamic-box" id="dyn-swap" <?= $defaults['transaction_type'] === 'swap' ? '' : 'hidden' ?>>
          <label for="swap_wanted">ما الذي ترغب بمقايضته؟ <i>*</i></label>
          <input type="text" id="swap_wanted" name="swap_wanted" value="<?= e((string)$defaults['swap_wanted']) ?>" placeholder="مثال: هاتف أو ساعة ذكية">
          <span class="hint">يُربط طلب التبادل بمنتجك المعروض وبالمنتج المطلوب لتوثيق المقايضة.</span>
          <?php if (isset($errors['swap_wanted'])): ?><span class="err"><?= e($errors['swap_wanted']) ?></span><?php endif; ?>
        </div>

        <div class="field full dynamic-box" id="dyn-donate" <?= $defaults['transaction_type'] === 'donate' ? '' : 'hidden' ?>>
          <?php if ($campaign): ?>
            <input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>">
            <div class="exchange-note mb-16"><?= icon('target', 14) ?>
              <b>ضمن حملة:</b> <?= e((string)$campaign['title']) ?> —
              <?= e((string)$campaign['association_name']) ?>
              <a href="<?= e(url('campaign.php?id=' . (int)$campaign['id'])) ?>" style="text-decoration:underline">عرض الحملة</a>
            </div>
          <?php endif; ?>
          <label>نطاق التبرّع <i>*</i></label>
          <div class="type-cards" style="grid-template-columns:1fr 1fr">
            <label class="type-card">
              <input type="radio" name="donation_scope" value="general" <?= $defaults['donation_scope'] === 'general' ? 'checked' : '' ?>>
              <b>تبرّع عام</b>
              <small>متاح لكل الجمعيات الموثّقة</small>
            </label>
            <label class="type-card">
              <input type="radio" name="donation_scope" value="targeted" <?= $defaults['donation_scope'] === 'targeted' ? 'checked' : '' ?>>
              <b>تبرّع موجّه</b>
              <small>لجمعية واحدة فقط</small>
            </label>
          </div>
          <div class="field mt-8" id="targetAssocBox" <?= $defaults['donation_scope'] === 'targeted' ? '' : 'hidden' ?>>
            <label for="target_association_id">الجمعية المستهدفة</label>
            <select name="target_association_id" id="target_association_id">
              <option value="">اختر الجمعية</option>
              <?php foreach ($associations as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= (int)$defaults['target_association_id'] === (int)$a['id'] ? 'selected' : '' ?>>
                  <?= e((string)$a['name']) ?> — <?= e((string)$a['city']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['target_association_id'])): ?><span class="err"><?= e($errors['target_association_id']) ?></span><?php endif; ?>
          </div>
        </div>

        <div class="field full dynamic-box" id="dyn-free" <?= $defaults['transaction_type'] === 'free' ? '' : 'hidden' ?>>
          <span class="hint"><?= icon('gift', 15) ?> سيكون منتجك متاحاً مجاناً لكل من يحتاجه — لا سعر ولا مقايضة.</span>
        </div>

        <div class="field<?= isset($errors['category_id']) ? ' has-error' : '' ?>">
          <label for="category_id">التصنيف <i>*</i></label>
          <select id="category_id" name="category_id" required>
            <option value="">اختر التصنيف</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)$defaults['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e((string)$c['name_ar']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['category_id'])): ?><span class="err"><?= e($errors['category_id']) ?></span><?php endif; ?>
        </div>

        <div class="field<?= isset($errors['condition_type']) ? ' has-error' : '' ?>">
          <label for="condition_type">حالة المنتج <i>*</i></label>
          <select id="condition_type" name="condition_type" required>
            <?php foreach (['new', 'like_new', 'good', 'fair', 'used'] as $cond): ?>
              <option value="<?= e($cond) ?>" <?= $defaults['condition_type'] === $cond ? 'selected' : '' ?>><?= e(condition_label($cond)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field<?= isset($errors['city']) ? ' has-error' : '' ?>">
          <label for="city">المدينة <i>*</i></label>
          <select id="city" name="city" required>
            <option value="">اختر المدينة</option>
            <?php foreach ($cities as $c): ?>
              <option value="<?= e($c) ?>" <?= $defaults['city'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['city'])): ?><span class="err"><?= e($errors['city']) ?></span><?php endif; ?>
        </div>

        <div class="field">
          <label for="address_hint">عنوان تقريبي (اختياري)</label>
          <input type="text" id="address_hint" name="address_hint" value="<?= e((string)$defaults['address_hint']) ?>" placeholder="مثال: حي حدة — قرب السوق">
        </div>

        <div class="field full<?= isset($errors['description']) ? ' has-error' : '' ?>">
          <label for="description">الوصف <i>*</i></label>
          <textarea id="description" name="description" rows="5" required placeholder="اكتب تفاصيل المنتج: العيوب إن وجدت، طريقة الاستخدام، الملحقات…"><?= e((string)$defaults['description']) ?></textarea>
          <?php if (isset($errors['description'])): ?><span class="err"><?= e($errors['description']) ?></span><?php endif; ?>
        </div>

        <div class="field full">
          <div id="aiHelper" aria-live="polite"></div>
        </div>

        <div class="field full">
          <label for="productImages">الصور (حتى 4 صور)</label>
          <label class="upload-zone" for="productImages">
            <span class="up-icon"><?= icon('image', 30) ?></span>
            <b>اضغط لاختيار الصور</b>
            <span>JPG أو PNG أو WEBP — بحد أقصى 5 ميجابايت للصورة</span>
            <input type="file" id="productImages" name="images[]" accept="image/jpeg,image/png,image/webp" multiple>
            <div id="imagePreview" class="flex gap-8 flex-wrap" style="justify-content:center;margin-top:10px"></div>
          </label>
          <?php if ($currentImages): ?>
            <div class="tag-list mt-8">
              <?php foreach ($currentImages as $img): ?>
                <span class="tag"><?= icon('check', 12) ?> صورة مرفقة</span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="form-actions">
        <a class="btn btn-ghost" href="<?= e($editing ? url('product_details.php?slug=' . urlencode((string)$editing['slug'])) : url('index.php')) ?>">إلغاء</a>
        <button class="btn btn-primary btn-lg" type="submit"><?= icon($editing ? 'check' : 'upload', 17) ?><?= $editing ? 'حفظ التعديلات' : 'نشر المنتج' ?></button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var scopeRadios = document.querySelectorAll('input[name="donation_scope"]');
  var box = document.getElementById('targetAssocBox');
  if (box) {
    function sync() {
      var checked = document.querySelector('input[name="donation_scope"]:checked');
      var targeted = checked && checked.value === 'targeted';
      box.hidden = !targeted;
      var sel = document.getElementById('target_association_id');
      if (sel) sel.required = !!targeted;
    }
    scopeRadios.forEach(function (r) { r.addEventListener('change', sync); });
    sync();
  }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
