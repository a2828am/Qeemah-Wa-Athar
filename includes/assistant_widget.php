<?php
/**
 * includes/assistant_widget.php
 * -----------------------------------------------------------------------------
 * The floating chat-bot that sits on every public page.
 *
 * It is deliberately *not* a second assistant: it posts to the same
 * api/assistant.php endpoint the full page uses, so the answers, the retention
 * window and the privacy rules are identical. If the assistant is switched off
 * in settings, nothing is rendered at all.
 *
 * Loaded from includes/footer.php, so it also appears for guests on the
 * marketplace (the moment someone is deciding whether to donate).
 */
declare(strict_types=1);

if (!function_exists('assistant_widget_render')) {
    /**
     * @param bool $enabled Admin switch (system_settings.ai_enabled).
     * @param string $here  Current page key, so the widget is not shown on the
     *                      full assistant page (it would be a chat inside a chat).
     */
    function assistant_widget_render(bool $enabled, string $here = ''): void
    {
        // Never inside the admin panel, the association panel or the full page.
        $skip = in_array($here, ['assistant', 'admin', 'charity_panel'], true);
        if (!$enabled || $skip) {
            return;
        }
        $suggestions = array_slice(AssistantGuides::suggestions(), 0, 4);
        ?>
<div class="bot" id="botWidget" data-endpoint="<?= e(url('api/assistant.php')) ?>" hidden>
  <div class="bot-panel" role="dialog" aria-modal="false" aria-labelledby="botTitle">
    <div class="bot-head">
      <span class="bot-ic"><?= icon('sparkles', 20) ?></span>
      <div class="grow">
        <b id="botTitle">مساعد قيمة وأثر</b>
        <small>مساعد آلي — يشرح التبرّع والطلب من بيانات المنصة</small>
      </div>
      <button type="button" class="bot-close" data-bot-close aria-label="إغلاق"><?= icon('x', 16) ?></button>
    </div>

    <div class="bot-body" id="botBody" aria-live="polite">
      <div class="msg them">
        <span>أهلاً بك 👋 اسألني عن <b>كيف تتبرّع</b> أو <b>كيف تطلب</b> أو <b>المقايضة</b>.
        أجيب من بيانات المنصة نفسها، ولا أطلب بياناتك الخاصة.</span>
      </div>
    </div>

    <div class="bot-chips" id="botChips">
      <?php foreach ($suggestions as $s): ?>
        <button type="button" class="chip" data-bot-ask="<?= e($s) ?>"><?= e($s) ?></button>
      <?php endforeach; ?>
    </div>

    <form class="bot-form" id="botForm">
      <?= csrf_field() ?>
      <input type="text" id="botInput" name="question" maxlength="500" required
             placeholder="اكتب سؤالك… مثال: كيف أتبرّع بمنتج؟" aria-label="سؤالك للمساعد">
      <button class="bot-send" type="submit" id="botSend" aria-label="إرسال"><?= icon('send', 17) ?></button>
    </form>
    <div class="bot-foot">
      <a href="<?= e(url('assistant.php')) ?>">الصفحة الكاملة</a>
      <span>·</span>
      <span>إجابات آلية من بيانات المنصة</span>
    </div>
  </div>
</div>

<button type="button" class="bot-fab" id="botFab" aria-expanded="false" aria-controls="botWidget"
        aria-label="افتح مساعد قيمة وأثر">
  <?= icon('sparkles', 22) ?>
  <span class="bot-fab-label">مساعد</span>
  <span class="bot-dot" id="botDot" hidden></span>
</button>
        <?php
    }
}
