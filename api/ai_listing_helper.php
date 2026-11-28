<?php
/**
 * api/ai_listing_helper.php
 * -----------------------------------------------------------------------------
 * Listing assistant. Everything is computed locally from the platform data —
 * no external API, no network call, so it works offline and never blocks the
 * form. Three services:
 *
 *   1. price_range       – min/max/median of comparable listings in the same
 *                          category (+ condition adjustment factor).
 *   2. type_suggestion   – which of the four transaction types fits the title /
 *                          description / price best.
 *   3. description_polish – a clean, structured Arabic description.
 *
 *   GET title=&category_id=&condition=&transaction_type=&description=
 *   GET scope=pricing  (price only)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/components.php';

header('Cache-Control: no-store');

$enabled = (bool)SettingsRepo::get('ai_enabled', true);
if (!$enabled) {
    json_ok(['ok' => false, 'enabled' => false, 'message' => 'المساعد الذكي معطّل من الإعدادات.']);
}

$title       = trim((string)get_q('title', ''));
$categoryId  = get_int('category_id', 0) ?? 0;
$condition   = (string)get_q('condition', 'good');
$txType      = (string)get_q('transaction_type', 'sell');
$description = trim((string)get_q('description', ''));
$scope       = (string)get_q('scope', 'all');

if (!in_array($condition, ['new', 'like_new', 'good', 'fair', 'used'], true)) {
    $condition = 'good';
}
if (!in_array($txType, ['sell', 'swap', 'donate', 'free'], true)) {
    $txType = 'sell';
}

$response = [
    'ok'        => true,
    'enabled'   => true,
    'engine'    => 'local',
    'icon'      => icon('sparkles', 16),
    'generated' => now(),
];

/* ── 1. price range ──────────────────────────────────────────────────────── */
if ($categoryId > 0) {
    $minSamples = (int)SettingsRepo::get('ai_price_min_samples', 3);
    $stats      = ProductRepo::priceStats($categoryId, $condition, max(1, $minSamples));
    $category   = CategoryRepo::find($categoryId);

    if ((int)$stats['count'] > 0) {
        $low  = (int)round($stats['suggested_low'] ?? $stats['min']);
        $high = (int)round($stats['suggested_high'] ?? $stats['max']);
        if ($high < $low) {
            [$low, $high] = [$high, $low];
        }
        $response['price_range'] = [
            'label'  => money($low) . ' — ' . money($high),
            'low'    => $low,
            'high'   => $high,
            'median' => (int)round((float)$stats['median']),
            'basis'  => 'مبني على ' . (int)$stats['count'] . ' إعلاناً للبيع في تصنيف «'
                        . (string)($category['name_ar'] ?? '') . '» مع تعديل حالة المنتج ('
                        . condition_label($condition) . ').',
            'count'  => (int)$stats['count'],
        ];
        $response['price_samples'] = [
            'avg'    => (int)round((float)$stats['avg']),
            'min'    => (int)round((float)$stats['min']),
            'max'    => (int)round((float)$stats['max']),
        ];
    } else {
        $response['price_range'] = [
            'label' => 'لا توجد بيانات كافية',
            'basis' => 'لا توجد إعلانات بيع كافية في هذا التصنيف بعد — اكتب سعراً تنافسياً أو اجعله «تبادل/تبرّع».',
            'count' => 0,
        ];
    }
}

/* ── 2. transaction-type suggestion ──────────────────────────────────────── */
$haystack = mb_strtolower($title . ' ' . $description);
$rules = [
    'donate' => ['تبرع', 'تبرّع', 'خيري', 'صدقة', 'لله', 'مساعد', 'محتاج', 'charity', 'donate', 'هدية'],
    'free'   => ['مجان', 'مجاناً', 'مجاني', 'بدون مقابل', 'free', 'gift'],
    'swap'   => ['تبادل', 'مقايضة', 'أبادل', 'swap', 'exchange', 'بدل'],
    'sell'   => ['بيع', 'أبيع', 'سعر', 'ريال', 'sell', 'price'],
];
$votes = ['sell' => 1, 'swap' => 0, 'donate' => 0, 'free' => 0];
foreach ($rules as $type => $words) {
    foreach ($words as $w) {
        if ($w !== '' && mb_strpos($haystack, $w) !== false) {
            $votes[$type] += 2;
        }
    }
}
if ($txType === 'sell' && (float)preg_replace('/[^0-9.]/', '', (string)get_q('price', '0')) > 0) {
    $votes['sell'] += 1;
}
arsort($votes);
$bestType = (string)array_key_first($votes);
$meta     = transaction_meta()[$bestType];

$reasons = [
    'sell'   => 'ذكرت سعراً أو كلمة بيع — البيع المباشر بدون بوابة دفع هو الأنسب.',
    'swap'   => 'يبدو أنك تفضّل المقايضة؛ سيُربط منتجك بالمنتج المطلوب في طلب واحد.',
    'donate' => 'الكلمات المستخدمة تشير إلى رغبة في التبرّع — يمكنك اختيار جمعية موثّقة (تبرّع موجّه) أو تركه عاماً.',
    'free'   => 'تبدو راغباً بمنحه مجاناً — المنتجات المجانية تحصل على تفاعل أسرع.',
];
$response['type_suggestion'] = [
    'type'   => $bestType,
    'label'  => $meta['label'],
    'icon'   => icon($meta['icon'], 15),
    'reason' => $votes[$bestType] > 2 ? $reasons[$bestType] : 'لم تظهر مؤشرات قوية — البيع هو الافتراضي الآمن، ويمكنك تغييره.',
];

/* ── 3. description polish ───────────────────────────────────────────────── */
if ($scope !== 'pricing' && $title !== '') {
    $cleanTitle = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    $sentences  = preg_split('/(?<=[.!؟\n])\s+/u', trim($description)) ?: [];
    $sentences  = array_values(array_filter(array_map('trim', $sentences), static fn ($s) => $s !== ''));
    $bodyParts  = [];

    $bodyParts[] = 'أعرض ' . $cleanTitle . ' بحالة ' . condition_label($condition) . '.';
    $kept = 0;
    foreach ($sentences as $s) {
        if (mb_strlen($s) < 12) {
            continue;
        }
        $bodyParts[] = rtrim($s, '.') . '.';
        if (++$kept >= 3) {
            break;
        }
    }
    if ($kept === 0) {
        $bodyParts[] = 'المنتج نظيف وجاهز للتسليم، ويمكن معاينته قبل الاتفاق.';
    }
    $bodyParts[] = match ($txType) {
        'sell'   => 'السعر قابل للتفاوض البسيط، والتسليم في نفس المدينة.',
        'swap'   => 'أرغب بمقايضة عادلة، ويمكن الاتفاق على طريقة التسليم.',
        'donate' => 'أتطلع لتوجيه هذا المنتج لمن يحتاجه عبر جمعية موثّقة.',
        'free'   => 'المنتج مجاني لمن يحتاجه فعلاً، بشرط الاستلام من نفس المدينة.',
    };
    $bodyParts[] = 'التواصل عبر محادثة المنصة لضمان الخصوصية.';

    $polished = implode(' ', $bodyParts);
    $response['description_polish'] = [
        'text'       => $polished,
        'apply'      => mb_strlen(trim($description)) < mb_strlen($polished),
        'word_count' => count(preg_split('/\s+/u', $polished) ?: []),
    ];
}

/* ── 4. tips (always cheap, always useful) ───────────────────────────────── */
$response['tips'] = [
    'أضف 3 صور واضحة على الأقل من زوايا مختلفة وبإضاءة طبيعية.',
    'اذكر العيوب إن وجدت — الصدق يرفع عدد الطلبات المقبولة.',
    'اكتب المرجع أو الموديل في العنوان لتظهر في نتائج البحث.',
];

json_ok(['ok' => true] + $response);
