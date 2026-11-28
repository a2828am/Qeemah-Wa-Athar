<?php
/**
 * tools/ai_doctor.php — فحص اتصال المنصة بالنموذج، سطر واحد لكل مشكلة.
 *
 *   php tools/ai_doctor.php              # افحص الإعداد الحالي
 *   php tools/ai_doctor.php --ask "كيف أتبرع؟"   # جرّب سؤالك فعلياً
 *
 * لا يطبع أي مفتاح. يعمل من سطر الأوامر فقط؛ شرحه في docs/LOCAL-MODEL.md.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/bootstrap.php';

use Qeemah\Config\Ai;

$ask = null;
foreach ($argv as $i => $a) {
    if ($a === '--ask' && isset($argv[$i + 1])) {
        $ask = (string)$argv[$i + 1];
    }
}

$ok   = static fn(string $m) => "  \033[32m✓\033[0m {$m}\n";
$bad  = static fn(string $m) => "  \033[31m✗\033[0m {$m}\n";
$warn = static fn(string $m) => "  \033[33m!\033[0m {$m}\n";

echo "\nفحص المساعد الذكي\n";
echo str_repeat('─', 62) . "\n";

/* 1. من أين يأتي الإعداد؟ */
$localFile = QEEMAH_ROOT . '/config/ai.local.php';
$hasLocal  = is_file($localFile);
echo $hasLocal
    ? $ok('config/ai.local.php موجود')
    : $warn('config/ai.local.php غير موجود (انسخه من ai.local.example.php لتشغيل نموذج محلي)');

if ($hasLocal) {
    $cfg = Ai::localConfig();
    if ($cfg === []) {
        echo $bad('الملف موجود لكنه لا يُرجع مصفوفة صحيحة — راجع صياغته');
    } else {
        echo $ok('base_url = ' . Ai::baseUrl());
        echo $ok('model    = ' . Ai::model());
    }
}

$envKey = getenv('QEEMAH_AI_API_KEY');
echo ($envKey !== false && $envKey !== '')
    ? $ok('متغيّر QEEMAH_AI_API_KEY مضبوط في البيئة (يحلّ محل الملف)')
    : $warn('لا يوجد مفتاح سحابي في البيئة (غير مطلوب لنموذج محلي)');

/* 2. النموذج مُعدّ؟ */
if (!Ai::configured()) {
    echo $bad('لا يوجد أي نموذج مُعدّ — المساعد سيجيب من الدليل المكتوب فقط');
    echo "\nالخطوة التالية: cp config/ai.local.example.php config/ai.local.php ثم شغّل ollama serve\n\n";
    exit(1);
}
echo $ok('الوضع: ' . Ai::describe() . (Ai::isLocal() ? ' (محلي)' : ' (سحابي)'));
echo $ok('زمن الانتظار: ' . Ai::timeout() . ' ثانية');

/* 3. هل الخادم يستجيب؟ */
$base  = Ai::baseUrl();
$host  = (string)parse_url($base, PHP_URL_HOST);
$port  = (int)(parse_url($base, PHP_URL_PORT) ?: (str_contains($base, 'https') ? 443 : 80));
$errno = 0;
$err   = '';
$sock  = @fsockopen($host, $port, $errno, $err, 3);
if ($sock) {
    fclose($sock);
    echo $ok("الخادم يستقبل اتصالات على {$host}:{$port}");
} else {
    echo $bad("لا يمكن الاتصال بـ {$host}:{$port} — {$err}");

    if (str_contains($base, '11434')) {
        echo "     الحل: ثبّت Ollama من https://ollama.com/download ثم نفّذ:\n";
        echo "           ollama serve\n";
        echo "           ollama pull " . Ai::model() . "\n";
    } elseif (str_contains($base, '1234')) {
        echo "     الحل: افتح LM Studio ← Developer ← Start Server\n";
    } else {
        echo "     تأكد أن خادم النموذج يعمل وأن العنوان في config/ai.local.php صحيح.\n";
    }
    if (!Ai::isLocal()) {
        echo $warn('العنوان ليس محلياً — تحقّق من الشبكة أو الوكيل.');
    }
    echo "\n";
    exit(1);
}

/* 4. هل قائمة النماذج تحتوي النموذج المطلوب؟ */
$raw = @file_get_contents($base . '/models', false, stream_context_create(['http' => [
    'header'        => 'Authorization: Bearer ' . (Ai::apiKey() ?? 'local') . "\r\n",
    'timeout'       => 10,
    'ignore_errors' => true,
]]));
if ($raw !== false) {
    $json  = json_decode($raw, true);
    $names = [];
    foreach (($json['data'] ?? $json['models'] ?? []) as $m) {
        if (isset($m['id'])) {
            $names[] = (string)$m['id'];
        } elseif (isset($m['name'])) {
            $names[] = (string)$m['name'];
        }
    }
    if ($names === []) {
        echo $warn('/models استجاب لكن بلا نماذج — ربما لم تُنزّل أي نموذج بعد');
        echo "     نفّذ: ollama pull " . Ai::model() . "\n";
    } elseif (in_array(Ai::model(), $names, true)) {
        echo $ok('النموذج «' . Ai::model() . '» موجود في الخادم');
    } else {
        echo $warn('النموذج «' . Ai::model() . '» غير موجود في قائمة الخادم');
        echo '     المتاح: ' . implode('، ', array_slice($names, 0, 6)) . "\n";
        echo '     أصلح config/ai.local.php أو نفّذ: ollama pull ' . Ai::model() . "\n";
    }
}

/* 5. السؤال الحقيقي — أهم فحص. */
echo str_repeat('─', 62) . "\n";
$question = $ask ?? 'كيف أتبرع بمنتج على المنصة؟';
echo "سؤال تجريبي: {$question}\n";
$t0    = microtime(true);
$reply = Assistant::reply($question, true, [], false);
$ms    = (int)round((microtime(true) - $t0) * 1000);

if (($reply['engine'] ?? '') === 'model') {
    echo $ok('ردّ النموذج في ' . $ms . 'ms');
    echo "\n" . mb_substr(trim((string)$reply['answer']), 0, 600) . "\n";
    $stored = (string)SettingsRepo::get('ai_last_ok_at', '');
    if ($stored !== '') {
        echo "\n  (سُجّل في الإعدادات: آخر ردّ ناجح {$stored})\n";
    }
} else {
    echo $warn('لم يردّ النموذج — عاد المساعد إلى الدليل المكتوب (' . $ms . 'ms)');
    echo "  هذا يحدث إن كان الخادم يعمل لكن الرد فشل: جرّب نموذجاً أصغر،\n";
    echo "  أو ارفع 'timeout' في config/ai.local.php، أو راجع سجلّ أخطاء PHP.\n";
}
echo "\n";
