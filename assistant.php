<?php
/**
 * assistant.php — the assistant page (optional feature, always safe to open).
 *
 * Answers come from the platform's own MySQL rows first; the external model is
 * only consulted when the admin switch is on and a key exists in the
 * environment. The page carries no key, no endpoint and no model name.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/components.php';

$enabled = (bool)SettingsRepo::get('ai_enabled', true);
/* Model mode: a model is configured (hosted key or local config/ai.local.php)
   and the assistant itself is enabled. Configuring a model is the instruction
   to use it, so ai_chat_enabled is not required. */
$keySet  = \Qeemah\Config\Ai::configured();
$isLocal = \Qeemah\Config\Ai::isLocal();
$chatOn  = $enabled && \Qeemah\Config\Ai::ready(true);
$uid     = Auth::check() ? (int)Auth::id() : null;
$sid     = (string)($_SESSION['assistant_sid'] ?? '');
if ($sid === '') {
    $sid = bin2hex(random_bytes(16));
    $_SESSION['assistant_sid'] = $sid;
}
$history = array_reverse(Assistant::history($sid, $uid, 20));
$facts   = $enabled ? Assistant::facts() : ['counts' => [], 'cities' => [], 'categories' => [], 'steps' => []];

$suggestions = AssistantGuides::suggestions();

$pageTitle   = 'المساعد الآلي | قيمة وأثر';
$currentPage = 'assistant';
$pageHead    = [
    'title'    => icon('help', 28) . ' المساعد الآلي',
    'subtitle' => 'يجيب من بيانات المنصة نفسها (منتجات، مدن، تصنيفات، قواعد التبرّع) ويُعلَّم دائماً بأنه آلي.',
];
require __DIR__ . '/includes/header.php';
?>

<div class="container section" style="padding-top:24px">
  <?php if (!$enabled): ?>
    <div class="panel"><?= empty_state('المساعد معطّل حالياً', 'أوقفت الإدارة المساعد الآلي. تصفّح السوق أو راسل الدعم.', 'help', 'السوق', url('index.php')) ?></div>
  <?php else: ?>

  <div class="assist-grid">
    <div class="panel assist-chat">
      <div class="assist-head">
        <span class="assist-avatar"><?= icon('help', 20) ?></span>
        <div class="grow">
          <b>مساعد قيمة وأثر</b>
          <small>
            <?= $chatOn
                ? 'مساعد آلي يعمل بنموذج ذكي مربوط ببيانات المنصة — ولا يخترع أسعاراً ولا توفّراً.'
                : 'مساعد آلي — إجابات من دليل المنصة وبياناتها، دون أي خدمة خارجية.' ?>
          </small>
        </div>
        <span class="badge badge-soft"><?= icon('lock', 12) ?>لا بيانات خاصة</span>
      </div>

      <div class="assist-body" id="assistBody">
        <div class="msg them"><span class="assist-intro">مرحباً 👋 أشرح <b>كيف تتبرّع بمنتج</b> خطوة بخطوة، الفرق بين التبرّع الموجّه والعام، مراحل الطلب، المقايضة، والتسجيل كجمعية — وأعرض ما هو متاح فعلاً الآن.</span></div>
        <?php foreach ($history as $h): ?>
          <div class="msg <?= $h['role'] === 'user' ? 'me' : 'them' ?>"><span><?= e((string)$h['body']) ?></span></div>
        <?php endforeach; ?>
      </div>

      <form class="assist-form" id="assistForm" method="post" action="<?= e(url('api/assistant.php')) ?>">
        <?= csrf_field() ?>
        <div class="assist-input">
          <textarea id="assistInput" name="question" rows="1" maxlength="500" required
                    placeholder="اكتب سؤالك… مثال: ما الفرق بين التبرّع الموجّه والعام؟"></textarea>
          <button class="btn btn-primary" id="assistSend" type="submit"><?= icon('send', 16) ?>إرسال</button>
        </div>
      </form>

      <div class="assist-suggest">
        <?php foreach ($suggestions as $s): ?>
          <button class="chip" type="button" data-suggest="<?= e($s) ?>"><?= e($s) ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <aside>
      <div class="panel">
        <h3><?= icon('info', 18) ?> ما يعرفه المساعد الآن</h3>
        <div class="kv">
          <div class="kv-row"><span>منتجات متاحة</span><b><?= (int)($facts['counts']['available'] ?? 0) ?></b></div>
          <div class="kv-row"><span>للبيع</span><b><?= (int)($facts['counts']['sell'] ?? 0) ?></b></div>
          <div class="kv-row"><span>للمقايضة</span><b><?= (int)($facts['counts']['swap'] ?? 0) ?></b></div>
          <div class="kv-row"><span>تبرّعات</span><b><?= (int)($facts['counts']['donate'] ?? 0) ?></b></div>
          <div class="kv-row"><span>مجاني</span><b><?= (int)($facts['counts']['free'] ?? 0) ?></b></div>
          <div class="kv-row"><span>جمعيات موثّقة</span><b><?= (int)($facts['counts']['associations'] ?? 0) ?></b></div>
        </div>
        <?php if (!empty($facts['cities'])): ?>
          <p class="text-xs text-muted mt-8">المدن: <?= e(implode('، ', array_slice($facts['cities'], 0, 8))) ?></p>
        <?php endif; ?>
      </div>

      <div class="panel mt-16" style="margin-top:16px">
        <h3><?= icon('shield-check', 18) ?> الخصوصية والحدود</h3>
        <ul class="tick-list">
          <li><?= icon('check', 13) ?>يبحث أولاً في قاعدة المنصة نفسها.</li>
          <li><?= icon('check', 13) ?>لا يعرض هواتف ولا عناوين ولا بيانات مستفيدين.</li>
          <li><?= icon('check', 13) ?>لا يخترع منتجات أو أسعاراً غير موجودة.</li>
          <li><?= icon('check', 13) ?>لا ينفّذ مدفوعات — الدفع والتسليم بين الطرفين.</li>
          <li><?= icon('check', 13) ?>يُحفظ السؤال فقط، ويُحذف بعد <?= (int)(SettingsRepo::get('ai_retention_days', 7) ?: 7) ?> أيام.</li>
        </ul>
        <?php if ($chatOn): ?>
          <p class="text-xs text-muted mt-8"><?= icon('check-circle', 12) ?> النموذج الذكي متصل ويجيب على الأسئلة الحرة. إن انقطع الاتصال يعود المساعد تلقائياً إلى دليل المنصة.</p>
        <?php else: ?>
          <p class="text-xs text-muted mt-8"><?= icon('info', 12) ?> النموذج الذكي غير مربوط — الإجابات كلها من دليل المنصة وبياناتها. راجع «الإعدادات ← المساعد الذكي» لربط نموذج.</p>
        <?php endif; ?>
      </div>
    </aside>
  </div>

  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
