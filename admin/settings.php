<?php
/**
 * admin/settings.php — platform settings grouped by module.
 * Full admins only; every change is written to the audit log with before/after.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/components.php';

Gate::requireFullAdmin();
$adminId = (int)Auth::id();
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $values = $_POST['setting'] ?? [];
    if (!is_array($values)) {
        $values = [];
    }
    try {
        foreach ($values as $key => $value) {
            $key = (string)$key;
            $old = Repo::q('SELECT setting_value FROM system_settings WHERE setting_key=?', [$key])[0] ?? null;
            if (!$old) {
                continue;
            }
            // Booleans arrive only when checked.
            $row = Repo::q('SELECT value_type FROM system_settings WHERE setting_key=?', [$key])[0] ?? null;
            $type = (string)($row['value_type'] ?? 'string');
            if ($type === 'bool') {
                $exists = Repo::q('SELECT 1 FROM system_settings WHERE setting_key=?', [$key]);
                $value  = '1';
            }
            SettingsRepo::set($key, (string)$value, $adminId);
            if ((string)$old['setting_value'] !== (string)$value) {
                AuditRepo::log($adminId, 'setting.updated', 'setting', null,
                    ['key' => $key, 'value' => $old['setting_value']],
                    ['key' => $key, 'value' => (string)$value]);
            }
        }
        // Unchecked booleans: set to 0 explicitly.
        foreach (Repo::q("SELECT setting_key FROM system_settings WHERE value_type='bool'") as $b) {
            if (!isset($values[$b['setting_key']])) {
                SettingsRepo::set((string)$b['setting_key'], '0', $adminId);
            }
        }
        flash('success', 'تم حفظ الإعدادات وتوثيق التغييرات في سجل التدقيق.');
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
    }
    redirect('admin/settings.php');
}

$rows = SettingsRepo::settingRows();
$groups = [];
foreach ($rows as $r) {
    $groups[(string)$r['group_name']][] = $r;
}
$groupLabels = [
    'general' => ['عام', 'settings'],
    'auth'    => ['الحسابات والتسجيل', 'shield'],
    'chat'    => ['المحادثات والإشعارات', 'message-circle'],
    'uploads' => ['الرفع والصور', 'image'],
    'ai'      => ['المساعد الذكي', 'sparkles'],
];

/* Model status at a glance: is a key present, and is the model answering?
   The key itself is never printed — only whether it exists. */
$aiKeySet    = \Qeemah\Config\Ai::configured();
$aiIsLocal   = \Qeemah\Config\Ai::isLocal();
$aiWhere     = $aiIsLocal ? 'نموذج محلي على هذا الجهاز' : 'خدمة سحابية عبر مفتاح في البيئة';
$aiProvider  = \Qeemah\Config\Ai::provider();
$aiModelName = \Qeemah\Config\Ai::model();
$aiLastOk    = (string)SettingsRepo::get('ai_last_ok_at', '');
$aiLastErr   = (string)SettingsRepo::get('ai_last_error_at', '');
$aiLatency   = (int)SettingsRepo::get('ai_last_latency_ms', 0);
$aiSwitch    = (bool)SettingsRepo::get('ai_chat_enabled', false);
/* A key present means the model answers (the switch is only a fallback gate
   for installs that keep a key but want to pause the model). */
$aiModeOn    = \Qeemah\Config\Ai::ready(true) && $aiKeySet;

$pageTitle   = 'إعدادات المنصة | قيمة وأثر';
$currentPage = 'admin';
$adminPage   = 'settings';
$pageHead    = [
    'title'    => icon('settings', 28) . ' إعدادات المنصة',
    'subtitle' => 'تحكّم في الهوية العامة، التسجيل، المحادثات، الرفع، والمساعد الذكي.',
];
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/admin_nav.php';
?>

<div class="container section" style="padding-top:24px">
  <?php if ($errors): ?>
    <div class="alert alert-error"><?= icon('alert-circle', 18) ?><div class="grow"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
  <?php endif; ?>

  <div class="panel" style="margin-bottom:18px">
    <h3><?= icon('sparkles', 18) ?> حالة المساعد الذكي</h3>
    <div class="kv">
      <div class="kv-row"><span>طريقة التشغيل</span>
        <b style="color:<?= $aiKeySet ? '#15803d' : '#b45309' ?>">
          <?= $aiKeySet ? (!$aiIsLocal ? 'مفتاح سحابي ✓' : 'نموذج محلي ✓ (بلا مفتاح)') : 'لا يوجد أي نموذج' ?></b></div>
      <div class="kv-row"><span>المصدر</span><b><?= e($aiWhere) ?></b></div>
      <div class="kv-row"><span>العنوان / النموذج</span>
        <b dir="ltr"><?= e(\Qeemah\Config\Ai::describe()) ?></b></div>
      <div class="kv-row"><span>الوضع</span>
        <b><?= $aiModeOn
            ? 'النموذج هو من يرد — والدليل بديل عند الانقطاع'
            : ($aiKeySet ? 'ادفع المفتاح إلى البيئة وأعد التشغيل' : 'الدليل المحلي فقط (بلا نموذج)') ?></b></div>
      <div class="kv-row"><span>آخر ردّ ناجح من النموذج</span>
        <b><?= $aiLastOk !== '' ? e($aiLastOk) . ($aiLatency > 0 ? ' · ' . $aiLatency . 'ms' : '') : '—' ?></b></div>
      <div class="kv-row"><span>آخر فشل اتصال</span>
        <b><?= $aiLastErr !== '' ? e($aiLastErr) : 'لا يوجد' ?></b></div>
    </div>
    <p class="text-xs text-muted mt-8">
      <?= $aiKeySet
          ? ($aiIsLocal
              ? 'النموذج المحلي يرد على الأسئلة الحرة بلا إنترنت وبلا تكلفة. إن توقّف خادم النموذج يجيب دليل المنصة تلقائياً فلا يتوقف الموقع. (شغّله بالأمر: ollama serve)'
              : 'بوجود المفتاح يرد النموذج على الأسئلة الحرة، وإن تعذّر الوصول إليه يجيب دليل المنصة تلقائياً فلا يتوقف الموقع.')
          : 'لتشغيل نموذج محلي بلا مفتاح: انسخ config/ai.local.example.php إلى config/ai.local.php وعدّل العنوان والنموذج. أو اضبط QEEMAH_AI_API_KEY لخدمة سحابية. بلا نموذج يعمل المساعد من دليل المنصة كاملاً.' ?>
    </p>
  </div>

  <form method="post">
    <?= csrf_field() ?>
    <div class="three-col">
      <?php foreach ($groups as $group => $items):
          $label = $groupLabels[$group] ?? [$group, 'settings'];
      ?>
        <section class="panel">
          <h3><?= icon($label[1], 18) ?> <?= e($label[0]) ?></h3>
          <?php foreach ($items as $item):
              $key   = (string)$item['setting_key'];
              $type  = (string)$item['value_type'];
              $value = (string)$item['setting_value'];
              $multi = str_contains($key, 'types');
          ?>
            <?php if ($type === 'bool'): ?>
              <div class="switch-row">
                <span class="grow">
                  <b><?= e((string)$item['label_ar']) ?></b>
                  <small dir="ltr"><?= e($key) ?></small>
                  <?php if ($key === 'ai_chat_enabled' && $aiKeySet): ?>
                    <small style="color:#b45309">يوجد نموذج مُعدّ، فهو يعمل بغضّ النظر عن هذا المفتاح.</small>
                  <?php elseif ($key === 'ai_chat_enabled' && !$aiKeySet): ?>
                    <small>لا أثر له الآن: لا يوجد أي نموذج. أضف <span dir="ltr">config/ai.local.php</span> أو <span dir="ltr">QEEMAH_AI_API_KEY</span> ليبدأ النموذج بالرد.</small>
                  <?php endif; ?>
                </span>
                <label class="toggle">
                  <input type="checkbox" name="setting[<?= e($key) ?>]" value="1" <?= $value === '1' ? 'checked' : '' ?>>
                  <i></i>
                </label>
              </div>
            <?php else: ?>
              <div class="field mb-16">
                <label for="s_<?= e($key) ?>"><?= e((string)$item['label_ar']) ?></label>
                <?php if ($type === 'int'): ?>
                  <input type="number" id="s_<?= e($key) ?>" name="setting[<?= e($key) ?>]" value="<?= e($value) ?>" min="0">
                <?php elseif ($multi): ?>
                  <textarea id="s_<?= e($key) ?>" name="setting[<?= e($key) ?>]" rows="3" dir="ltr"><?= e($value) ?></textarea>
                <?php else: ?>
                  <input type="text" id="s_<?= e($key) ?>" name="setting[<?= e($key) ?>]" value="<?= e($value) ?>">
                <?php endif; ?>
                <span class="hint" dir="ltr"><?= e($key) ?> · <?= e($type) ?></span>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>
    </div>

    <div class="form-actions">
      <button class="btn btn-primary btn-lg" type="submit"><?= icon('check', 17) ?>حفظ كل الإعدادات</button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
