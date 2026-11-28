<?php
/**
 * includes/assistant.php — the optional assistant engine.
 *
 * Two layers, in this order:
 *   1. FAQ / data layer — always available, answers only from MySQL: how the
 *      process works, which cities have listings, what a donation needs, what is
 *      available in a category or under a price. No network, no invention.
 *   2. Model layer — only when the admin switch is on AND a key exists in the
 *      environment (config/ai.php). The model receives the user's question plus
 *      a compact snapshot of *public* rows; anything about phones, addresses or
 *      beneficiaries is never assembled here.
 *
 * Every exchange is stored in `assistant_threads` (retention from settings) and
 * is labelled to the user as an automated assistant.
 */
declare(strict_types=1);

use Qeemah\Config\Ai;

require_once __DIR__ . '/assistant_guides.php';

final class Assistant
{
    /** Public Q&A material — the only facts the assistant is allowed to state. */
    public static function facts(): array
    {
        return [
            'steps' => array_map(
                static fn ($s) => $s['label'],
                array_values(request_steps())
            ),
            'cities' => array_values(array_filter(array_map(
                static fn ($r) => (string)($r['city'] ?? ''),
                Repo::q("SELECT DISTINCT city FROM products WHERE status='available' ORDER BY city")
            ))),
            'categories' => array_map(
                static fn ($r) => (string)$r['name_ar'],
                Repo::q('SELECT name_ar FROM categories WHERE is_active=1 ORDER BY sort_order, name_ar')
            ),
            'counts' => [
                'available'     => (int)Repo::scalar("SELECT COUNT(*) FROM products WHERE status='available'"),
                'sell'          => (int)Repo::scalar("SELECT COUNT(*) FROM products WHERE status='available' AND transaction_type='sell'"),
                'swap'          => (int)Repo::scalar("SELECT COUNT(*) FROM products WHERE status='available' AND transaction_type='swap'"),
                'donate'        => (int)Repo::scalar("SELECT COUNT(*) FROM products WHERE status='available' AND transaction_type='donate'"),
                'free'          => (int)Repo::scalar("SELECT COUNT(*) FROM products WHERE status='available' AND transaction_type='free'"),
                'associations'  => (int)Repo::scalar("SELECT COUNT(*) FROM associations WHERE status='verified'"),
                'campaigns'     => (int)Repo::scalar("SELECT COUNT(*) FROM donation_campaigns WHERE status='active'"),
                'receipts'      => (int)Repo::scalar('SELECT COUNT(*) FROM donation_receipts'),
                'distributed'   => (int)Repo::scalar('SELECT COUNT(*) FROM distribution_logs'),
            ],
            'associations' => Repo::q("SELECT name, city, license_number FROM associations WHERE status='verified' ORDER BY name LIMIT 6"),
            'campaigns' => Repo::q("SELECT c.title, c.target_items, c.received_items, a.name AS association_name
                                      FROM donation_campaigns c JOIN associations a ON a.id=c.association_id
                                     WHERE c.status='active' ORDER BY c.created_at DESC LIMIT 4"),
            'cheapest' => Repo::q("SELECT title, price, city FROM products
                                    WHERE status='available' AND transaction_type='sell' AND price IS NOT NULL
                                    ORDER BY price ASC LIMIT 3"),
        ];
    }

    /** Normalise Arabic so «أ/إ/آ» and diacritics never decide a match. */
    public static function normalise(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = str_replace(['أ', 'إ', 'آ', 'ٱ', 'ى'], ['ا', 'ا', 'ا', 'ا', 'ي'], $text);
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    /** Does the normalised question contain this word/phrase as a whole token? */
    private static function hasToken(string $haystack, string $needle): bool
    {
        $needle = self::normalise($needle);
        if ($needle === '') {
            return false;
        }
        // Arabic attaches particles and the article to the front of a word:
        // التعريف (ال) plus و/ف/ب/ل/ك. «كيف أتبرع» must also match «التبرع».
        $pattern = '/(?:^|[\s،,.؟?!:;\-\x{060C}\x{061B}])(?:و|ف|ب|ل|ك)?(?:ال)?' . preg_quote($needle, '/')
                 . '(?:$|[\s،,.؟?!:;\-\x{060C}\x{061B}])/u';
        if (preg_match($pattern, $haystack) === 1) {
            return true;
        }
        // Multi-word phrases are matched anywhere (word boundaries are noisy).
        return mb_strpos($needle, ' ') !== false && mb_strpos($haystack, $needle) !== false;
    }

    /**
     * Words too common to decide a topic on their own («أين»، «عام»، «مشكلة»).
     * They only count when they appear with a word that narrows the meaning,
     * so «أين أجد الإشعارات» is not answered by the cities guide.
     */
    private const WEAK = ['اين', 'عام', 'مشكلة', 'خلاف', 'كم', 'صور', 'صورة', 'سعر', 'اسعار'];

    /**
     * Deterministic answers. Guidance from AssistantGuides is scored against the
     * question, and the live numbers (how many, which cities, which campaigns)
     * are appended from MySQL so the text is never stale.
     */
    public static function faq(string $question, bool $signedIn = false): ?array
    {
        $q = self::normalise($question);
        if (mb_strlen($q) < 2) {
            return null;
        }

        // Social openers: a visitor who says «مرحبا» should get a welcome, not
        // a "I don't know" answer.
        $social = [
            ['w' => ['مرحبا', 'اهلا', 'اهلين', 'هاي', 'هلا', 'صباح الخير', 'مساء الخير', 'السلام عليكم'], 'k' => 'hello'],
            ['w' => ['شكرا', 'شكراً', 'مشكور', 'يعطيك العافية', 'جزاك الله'], 'k' => 'thanks'],
            ['w' => ['من انت', 'ما اسمك', 'عرف بنفسك', 'انت مين', 'هل انت روبوت', 'بوت'], 'k' => 'who'],
        ];
        foreach ($social as $set) {
            foreach ($set['w'] as $word) {
                if (self::hasToken($q, $word)) {
                    return self::social($set['k'], $signedIn);
                }
            }
        }

        $best      = null;
        $bestScore = 0;
        foreach (AssistantGuides::all() as $guide) {
            $hits = 0;
            $strong = 0;
            $long = 0;
            foreach ($guide['match'] as $word) {
                if (self::hasToken($q, $word)) {
                    $hits++;
                    $len = mb_strlen($word);
                    $long = max($long, $len);
                    if (!in_array(self::normalise($word), self::WEAK, true)) {
                        $strong++;
                    }
                }
            }
            // A weak word on its own never decides the topic.
            if ($strong === 0) {
                continue;
            }
            // Longest matched phrase wins ties: «كيف أتبرع» must beat «تبرع».
            $score = (int)$guide['weight'] + $strong * 6 + $hits * 2 + $long;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $guide;
            }
        }

        if ($best === null) {
            return null;
        }

        $answer = self::interpolate($best, $signedIn);
        $out = [
            'intent' => 'guide',
            'title'  => (string)$best['title'],
            'answer' => $answer,
            'engine' => 'faq',
        ];
        if (!empty($best['links'])) {
            $links = [];
            foreach ($best['links'] as $l) {
                $links[] = ['label' => (string)$l['label'], 'url' => url((string)$l['url'])];
            }
            $out['links'] = $links;
        }
        return $out;
    }

    /** Small talk, answered honestly and pointed at what the assistant can do. */
    private static function social(string $kind, bool $signedIn): array
    {
        $f = self::facts();
        return match ($kind) {
            'thanks' => [
                'intent' => 'social', 'engine' => 'faq', 'title' => 'على الرحب والسعة',
                'answer' => 'العفو — أسعدني ذلك. إن احتجت شيئاً آخر: مراحل الطلب، التبرّع، المقايضة، أو ما هو متاح الآن.',
            ],
            'who' => [
                'intent' => 'social', 'engine' => 'faq', 'title' => 'من أنا',
                'answer' => 'أنا مساعد «قيمة وأثر» الآلي. أشرح خطوات التبرّع والطلب والمقايضة والتسجيل، '
                    . 'وأعرض ما هو متاح فعلاً في المنصة (منتجات، مدن، جمعيات، حملات). '
                    . 'لا أُنفّذ مدفوعات، ولا أرى بياناتك الخاصة، وإجاباتي تُعلَّم دائماً بأنها آلية.',
            ],
            default => [
                'intent' => 'social', 'engine' => 'faq', 'title' => 'أهلاً بك',
                'answer' => 'أهلاً بك في «قيمة وأثر». أستطيع أن أشرح لك: كيف تتبرّع بمنتج خطوة بخطوة، الفرق بين '
                    . 'التبرّع الموجّه والعام، مراحل الطلب، المقايضة، التسجيل كجمعية، أو الدفع والرسوم. '
                    . 'وحالياً لدينا ' . $f['counts']['available'] . ' منتج متاح و'
                    . $f['counts']['associations'] . ' جمعية موثّقة. بماذا أبدأ؟',
                'links' => [
                    ['label' => 'كيف أتبرّع؟', 'url' => url('add_product.php?type=donate')],
                    ['label' => 'السوق',       'url' => url('index.php#market')],
                ],
            ],
        };
    }

    /** Append the live numbers so a written guide never states stale figures. */
    private static function interpolate(array $guide, bool $signedIn): string
    {
        $f    = self::facts();
        $text = (string)$guide['answer'];
        $live = (string)($guide['live'] ?? '');

        if ($live === 'donation') {
            $text .= "\n\n**الآن:** " . $f['counts']['donate'] . ' إعلان تبرّع متاح، و'
                . $f['counts']['associations'] . ' جمعية موثّقة تستقبل التبرّعات، و'
                . $f['counts']['campaigns'] . ' حملة نشطة.';
        } elseif ($live === 'associations' && $f['associations'] !== []) {
            $names = array_map(
                static fn ($a) => (string)$a['name'] . ' (' . (string)$a['city'] . ')',
                $f['associations']
            );
            $text .= "\n\n**الجمعيات الموثّقة:** " . implode('، ', $names) . '.';
        } elseif ($live === 'campaigns' && $f['campaigns'] !== []) {
            $lines = array_map(
                static fn ($c) => '«' . $c['title'] . '» — ' . (int)$c['received_items'] . '/' . (int)$c['target_items']
                    . ' عنصر · ' . (string)$c['association_name'],
                $f['campaigns']
            );
            $text .= "\n\n**حملات جارية:** " . implode(' | ', $lines) . '.';
        } elseif ($live === 'market') {
            $text .= "\n\n**الآن:** " . $f['counts']['available'] . ' منتج متاح — للبيع ' . $f['counts']['sell']
                . '، مقايضة ' . $f['counts']['swap'] . '، تبرّع ' . $f['counts']['donate']
                . '، مجاني ' . $f['counts']['free'] . '.';
        } elseif ($live === 'cities' && $f['cities'] !== []) {
            $text .= "\n\n**مدن فيها إعلانات:** " . implode('، ', array_slice($f['cities'], 0, 12)) . '.';
        }

        if ($signedIn && in_array($live, ['donation', 'market'], true)) {
            $text .= " يمكنك البدء الآن من «أضف منتج».";
        }
        return $text;
    }

    /**
     * Ask the model. Returns null on any failure (no key, network down, bad
     * response) so the caller can fall back to the written guide.
     *
     * @param array<int,array{role:string,body:string}> $history
     */
    public static function ask(string $question, ?array $history = [], bool $signedIn = false): ?string
    {
        // A local model needs no key at all; only a hosted API does.
        if (!Ai::configured()) {
            return null;
        }
        $key = Ai::apiKey();

        $messages = [
            ['role' => 'system', 'content' => self::systemPrompt($signedIn)],
        ];
        foreach (array_slice($history ?? [], -6) as $turn) {
            if (isset($turn['role'], $turn['body']) && in_array($turn['role'], ['user', 'assistant'], true)) {
                $messages[] = ['role' => $turn['role'], 'content' => mb_substr((string)$turn['body'], 0, 1500)];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        /* Local models on a CPU are slow and prone to looping, so they get
           tighter sampling plus a length cap; a hosted model needs none of it. */
        $local = Ai::isLocal();
        $body  = [
            'model'       => Ai::model(),
            'messages'    => $messages,
            'temperature' => $local ? 0.2 : 0.3,
            'max_tokens'  => $local ? 380 : 500,
        ];
        if ($local) {
            // Penalise both the token frequency and the mere presence of a token,
            // which is what stops «تأكد من أن التبرعات تُقبل» repeating forever.
            $body['frequency_penalty'] = 0.6;
            $body['presence_penalty']  = 0.4;
            // Stop as soon as the model starts inventing another turn.
            $body['stop'] = ["\nسؤال:", "\nالمستخدم:", "\nUser:", "\nAssistant:"];
        }
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);

        $headers = "Content-Type: application/json\r\n";
        if ($key !== null) {
            $headers .= "Authorization: Bearer {$key}\r\n";
        }
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => $headers,
            'content'       => $payload,
            'timeout'       => Ai::timeout(),
            'ignore_errors' => true,
        ]]);

        $startedAt = microtime(true);
        $raw = @file_get_contents(Ai::baseUrl() . '/chat/completions', false, $ctx);
        $elapsed = static fn() => (int)round((microtime(true) - $startedAt) * 1000);

        if ($raw === false) {
            // A local model is often still loading — one retry buys the warm-up.
            if (Ai::isLocal()) {
                $raw = @file_get_contents(Ai::baseUrl() . '/chat/completions', false, $ctx);
            }
            if ($raw === false) {
                error_log('[Qeemah] assistant: model unreachable at ' . Ai::baseUrl());
                self::recordHealth(false, $elapsed());
                return null;
            }
        }
        $json = json_decode($raw, true);
        $text = $json['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            error_log('[Qeemah] assistant: empty model response');
            self::recordHealth(false, $elapsed());
            return null;
        }
        $text = trim($text);

        /* A small local model sometimes degenerates into a loop. Returning that
           to a visitor is worse than the written guide, so we reject it and let
           the caller fall back. */
        if (self::looksDegenerate($text)) {
            error_log('[Qeemah] assistant: degenerate model output rejected');
            self::recordHealth(false, $elapsed());
            return null;
        }

        // Collapse the same paragraph repeated back to back.
        $text = self::dedupeParagraphs($text);

        self::recordHealth(true, $elapsed());
        return mb_substr($text, 0, 1800);
    }

    /** The instructions that keep the model inside the platform's facts. */
    private static function systemPrompt(bool $signedIn): string
    {
        $f = self::facts();
        $topics = array_map(static fn ($g) => (string)$g['title'], AssistantGuides::all());

        $ctx = json_encode([
            'منتجات_متاحة'   => $f['counts']['available'],
            'للبيع'          => $f['counts']['sell'],
            'للمقايضة'       => $f['counts']['swap'],
            'تبرعات'         => $f['counts']['donate'],
            'مجاني'          => $f['counts']['free'],
            'جمعيات_موثقة'   => $f['counts']['associations'],
            'حملات_نشطة'     => $f['counts']['campaigns'],
            'مدن'            => array_slice($f['cities'], 0, 10),
            'تصنيفات'        => array_slice($f['categories'], 0, 12),
            'ارخص_للبيع'     => array_map(static fn ($r) => $r['title'] . ' — ' . $r['price'] . ' — ' . $r['city'], $f['cheapest']),
            'مراحل_الطلب'    => $f['steps'],
        ], JSON_UNESCAPED_UNICODE);

        return "أنت مساعد منصة «قيمة وأثر»، منصة مجتمعية يمنية للبيع والتبادل والتبرّع العيني والمجاني، تعمل بالعربية (RTL).\n\n"
            . "قواعد ملزمة:\n"
            . "1) أجب بالعربية الفصحى المبسّطة، بفقرات قصيرة أو نقاط، وبأسلوب ودود ومباشر. لا تتجاوز ٨ أسطر إلا إذا طُلب تفصيل.\n"
            . "2) استخدم الأرقام الواردة في بيانات المنصة أدناه فقط. لا تخترع منتجات ولا أسعاراً ولا توفّراً ولا أسماء جمعيات.\n"
            . "3) التبرّع نوعان: «موجّه» إلى جمعية يختارها المتبرّع، و«عام» يُعرض على الجمعيات الموثّقة وأول جمعية تقبله تستلمه. لا تحتفظ المنصة بأموال ولا تنفّذ مدفوعات ولا تأخذ عمولة.\n"
            . "4) المسار إلزامي: طلب ← قبول صاحب المنتج (تُفتح المحادثة) ← اتفاق بتأكيد الطرفين ← تسليم بتأكيد الطرفين. تأكيد طرف واحد لا يُكمل أي خطوة.\n"
            . "5) لا تُفصح عن أي بيانات شخصية: هواتف، عناوين، بريد، أسماء مستفيدين، أو بيانات حساب المستخدم. لا تطلب كلمة مرور أبداً.\n"
            . "6) إن سُئلت عن شيء خارج المنصة أو لا تعرفه من البيانات، قل بصراحة إنك لا تملك المعلومة واقترح ما تستطيع مساعدتهم به. لا تخمّن.\n"
            . "7) أشر إلى الصفحات عند الحاجة: add_product.php للتبرّع أو النشر، charity_campaigns.php للحملات، index.php#market للسوق، register.php للتسجيل، my_requests.php للطلبات.\n"
            . "8) لا تقل إنك إنسان؛ إن سُئلت، قل إنك مساعد آلي للمنصة.\n\n"
            . "مواضيع يجيدها الدليل المكتوب: " . implode('، ', $topics) . ".\n\n"
            . "بيانات المنصة الآن: " . $ctx;
    }

    /** Persist a one-line health signal so the admin can see if the model is alive. */
    private static function recordHealth(bool $ok, int $ms): void
    {
        try {
            SettingsRepo::set('ai_last_ok_at', $ok ? date('Y-m-d H:i:s') : (string)SettingsRepo::get('ai_last_ok_at', ''));
            SettingsRepo::set('ai_last_error_at', $ok ? (string)SettingsRepo::get('ai_last_error_at', '') : date('Y-m-d H:i:s'));
            if ($ok) {
                SettingsRepo::set('ai_last_latency_ms', (string)$ms);
            }
        } catch (Throwable $e) {
            // health tracking must never break an answer
        }
    }

    /** True when the switches allow the model and a key is present. */
    public static function modelReady(bool $switchOn): bool
    {
        return Ai::ready($switchOn);
    }

    /**
     * Answer in order — model first, exactly as the operator asked:
     *   1. the model, when a key exists and the admin switch is on;
     *   2. the written guide (assistant_guides.php) if the model is off or fails;
     *   3. an honest "I don't know", never an invented answer.
     *
     * Every branch returns the same shape, so the widget and the full page do not
     * care which layer answered.
     */
    public static function reply(string $question, bool $modelEnabled, array $history = [], bool $signedIn = false): array
    {
        $guide = self::faq($question, $signedIn);

        // 1) the model answers whenever it is reachable.
        if ($modelEnabled) {
            $answer = self::ask($question, $history, $signedIn);
            if ($answer !== null) {
                return [
                    'intent' => 'model',
                    'engine' => 'model',
                    'title'  => (string)($guide['title'] ?? ''),
                    'answer' => $answer,
                    'links'  => $guide['links'] ?? self::defaultLinks(),
                    'model'  => Ai::model(),
                ];
            }
            // The model was expected but did not answer: say so once, quietly,
            // and keep the conversation useful through the guide below.
            if ($guide !== null) {
                $guide['degraded'] = true;
            }
        }

        // 2) the written guide.
        if ($guide !== null) {
            return $guide;
        }

        // 3) honest fallback.
        return [
            'intent' => 'fallback',
            'engine' => 'faq',
            'title'  => 'لم أتأكد من سؤالك',
            'answer' => 'لم أجد في بيانات المنصة ما يجيب سؤالك بثقة، ولن أخترع إجابة. جرّب صياغة أقصر، أو اختر أحد المواضيع: '
                . 'التبرّع خطوة بخطوة، الفرق بين التبرّع الموجّه والعام، مراحل الطلب، المقايضة، التسجيل كجمعية، '
                . 'الدفع والرسوم، الأمان، أو ما هو متاح الآن في السوق.',
            'links'  => self::defaultLinks(),
        ];
    }

    /** Where a visitor can go next when the assistant has nothing specific. */
    public static function defaultLinks(): array
    {
        return [
            ['label' => 'المساعد الكامل', 'url' => url('assistant.php')],
            ['label' => 'السوق',          'url' => url('index.php#market')],
            ['label' => 'الحملات الخيرية', 'url' => url('charity_campaigns.php')],
        ];
    }

    /**
     * True when an answer is mostly one sentence repeated — the classic failure
     * mode of a tiny local model. Healthy prose scores far below the threshold.
     */
    private static function looksDegenerate(string $text): bool
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $lines = array_values(array_filter(array_map(
            static fn($l) => trim(preg_replace('/^[\d\.\)\-\*•\s]+/u', '', $l) ?? ''),
            $lines
        ), static fn($l) => $l !== ''));

        if (count($lines) < 6) {
            return false;
        }
        $unique = count(array_unique($lines));
        // Six or more lines with under a third of them distinct = a loop.
        return ($unique / count($lines)) < 0.34;
    }

    /** Keep the first occurrence of each paragraph, dropping back-to-back repeats. */
    private static function dedupeParagraphs(string $text): string
    {
        $parts = preg_split('/\R{2,}/u', $text) ?: [$text];
        $out   = [];
        $seen  = [];
        foreach ($parts as $part) {
            $key = trim($part);
            if ($key === '') {
                continue;
            }
            $norm = preg_replace('/\s+/u', ' ', $key) ?? $key;
            if (isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;
            $out[]       = $key;
        }
        return implode("\n\n", $out);
    }

    /**
     * A light per-session throttle. With the model first, every question can be
     * a paid API call, so a runaway loop (or a bored visitor) must not be able
     * to bill the operator. 12 questions a minute is far above real use.
     * Returns seconds to wait, or 0 when the caller may proceed.
     */
    public static function throttle(string $sid): int
    {
        $key  = 'assist_thr_' . substr(sha1($sid), 0, 16);
        $now  = time();
        $hits = $_SESSION[$key] ?? [];
        if (!is_array($hits)) {
            $hits = [];
        }
        $hits = array_values(array_filter($hits, static fn($t) => is_int($t) && $t > $now - 60));

        if (count($hits) >= 12) {
            $wait = 60 - ($now - (int)$hits[0]);
            return max(1, $wait);
        }
        $hits[] = $now;
        $_SESSION[$key] = $hits;
        return 0;
    }

    /** Keep only what the user typed, and only for the configured window. */
    public static function remember(?int $userId, string $sessionKey, string $role, string $body, string $engine): void
    {
        Repo::exec(
            'INSERT INTO assistant_threads (user_id, session_key, role, body, engine, created_at) VALUES (?,?,?,?,?,?)',
            [$userId, $sessionKey, $role, mb_substr($body, 0, 2000), $engine, now()]
        );
        $days = max(1, (int)(SettingsRepo::get('ai_retention_days', 7) ?: 7));
        Repo::exec('DELETE FROM assistant_threads WHERE created_at < ?', [date('Y-m-d H:i:s', strtotime("-{$days} days"))]);
    }

    public static function history(string $sessionKey, ?int $userId, int $limit = 6): array
    {
        return Repo::q(
            'SELECT role, body FROM assistant_threads
              WHERE session_key=? AND (user_id IS NULL OR user_id=?)
              ORDER BY id DESC LIMIT ' . max(1, $limit),
            [$sessionKey, $userId ?? 0]
        );
    }
}
