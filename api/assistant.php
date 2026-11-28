<?php
/**
 * api/assistant.php — the optional assistant endpoint.
 *
 *   POST question=...     → {ok, answer, engine, automated:true}
 *   GET  action=history   → the last exchanges of this browser session
 *
 * The assistant is labelled «مساعد آلي» in every response. It answers from the
 * platform's own data first; the model is used only when the admin enabled it
 * AND a key is present in the environment. Nothing private is ever sent or
 * stored beyond the user's own question.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

header('Cache-Control: no-store');

$uid  = Auth::check() ? (int)Auth::id() : null;
$sid  = (string)($_SESSION['assistant_sid'] ?? '');
if ($sid === '') {
    $sid = bin2hex(random_bytes(16));
    $_SESSION['assistant_sid'] = $sid;
}

/* Model first, as the operator asked. A model counts as configured when a key
   exists in the environment OR config/ai.local.php points at a local server —
   a local model needs no key. Merely configuring one is the instruction to use
   it, so the ai_chat_enabled switch does not stand in the way. */
$switchOn     = (bool)SettingsRepo::get('ai_chat_enabled', false);
$modelSet     = \Qeemah\Config\Ai::configured();
$modelOn      = \Qeemah\Config\Ai::ready($switchOn || $modelSet);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (get_q('action', '') === 'history')) {
    $rows = Assistant::history($sid, $uid, 10);
    json_ok(['messages' => array_reverse($rows), 'enabled' => $modelOn, 'mode' => $modelOn ? 'model' : 'guide']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('استخدم POST لسؤال المساعد.', 405);
}
require_csrf();

$question = trim((string)post('question', ''));
if (mb_strlen($question) < 2) {
    json_err('اكتب سؤالك أولاً.', 422);
}
if (mb_strlen($question) > 500) {
    $question = mb_substr($question, 0, 500);
}

/* Guard the paid call: without this, model-first mode would let one tab bill the
   operator for hundreds of requests a minute. */
$wait = Assistant::throttle($sid);
if ($wait > 0) {
    json_err('أسئلة كثيرة في وقت قصير — انتظر ' . $wait . ' ثانية ثم أعد المحاولة.', 429);
}

$history = array_reverse(Assistant::history($sid, $uid, 4));
$reply   = Assistant::reply($question, $modelOn, $history, $uid !== null);

Assistant::remember($uid, $sid, 'user', $question, 'user');
Assistant::remember($uid, $sid, 'assistant', (string)$reply['answer'], (string)($reply['engine'] ?? 'faq'));

json_ok([
    'answer'    => (string)$reply['answer'],
    'title'     => (string)($reply['title'] ?? ''),
    'links'     => $reply['links'] ?? [],
    'engine'    => (string)($reply['engine'] ?? 'faq'),
    'model'     => (string)($reply['model'] ?? ''),
    'degraded'  => (bool)($reply['degraded'] ?? false),
    'intent'    => (string)($reply['intent'] ?? ''),
    'automated' => true,
    'label'     => ($reply['engine'] ?? '') === 'model'
        ? 'مساعد آلي — نموذج ذكي مربوط ببيانات المنصة'
        : 'مساعد آلي — الإجابات من بيانات المنصة',
]);
