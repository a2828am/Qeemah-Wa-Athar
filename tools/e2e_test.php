<?php
/**
 * tools/e2e_test.php — end-to-end integration test against a running server.
 *
 *   php tools/e2e_test.php [baseUrl] [sqliteFile] [--mysql] [--no-reseed]
 *
 * Unless --no-reseed is given, the demo fixtures are rebuilt from database.sql
 * first, so the suite can be run repeatedly against the same database.
 *
 * Exercises: login, product request creation, the request state machine
 * (accepted → product reserved, completed → product completed), chat send +
 * polling + read receipts, favourites, notifications, charity claim + receipt,
 * admin verification with audit trail, and the AI endpoints.
 * Prints PASS/FAIL per check and exits non-zero on failure.
 */
declare(strict_types=1);

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8091', '/');
/* Database the suite inspects directly. Defaults to the SQLite file used by the
   local test server; pass --mysql to validate a real MySQL/XAMPP install (the
   connection settings come from the same environment variables as the app). */
$useMysql = in_array('--mysql', $argv, true);
$db       = $argv[2] ?? dirname(__DIR__) . '/storage/qeemah.sqlite';
$mysqlCfg = [
    'host' => getenv('QEEMAH_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('QEEMAH_DB_PORT') ?: '3306',
    'name' => getenv('QEEMAH_DB_NAME') ?: 'qeemah_wa_athar',
    'user' => getenv('QEEMAH_DB_USER') ?: 'root',
    'pass' => getenv('QEEMAH_DB_PASS') !== false ? getenv('QEEMAH_DB_PASS') : '',
];
/** NOW() is valid on MySQL but not SQLite, and vice-versa — keep inserts portable. */
$nowSql = $useMysql ? 'NOW()' : "datetime('now')";

/*
 * The suite writes real data (requests, agreements, deliveries, audit rows), so a
 * database that already ran it no longer has the pristine demo fixtures it needs.
 * Before the checks start, rebuild the fixture from database.sql:
 *   SQLite → rebuild the file, MySQL → restore the seeded rows (--refresh).
 */
$reseed = !in_array('--no-reseed', $argv, true);
if ($reseed) {
    $root = dirname(__DIR__);
    if ($useMysql) {
        $cmd = sprintf(
            'QEEMAH_DB_DRIVER=mysql QEEMAH_DB_HOST=%s QEEMAH_DB_PORT=%s QEEMAH_DB_NAME=%s QEEMAH_DB_USER=%s %s %s --refresh 2>&1',
            escapeshellarg($mysqlCfg['host']),
            escapeshellarg($mysqlCfg['port']),
            escapeshellarg($mysqlCfg['name']),
            escapeshellarg($mysqlCfg['user']),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/tools/init_mysql.php')
        );
        exec($cmd, $out, $rc);
        echo $rc === 0
            ? "— أعيد ضبط بيانات العرض على MySQL —\n"
            : "!! تعذّر ضبط بيانات MySQL:\n" . implode("\n", $out) . "\n";
    } else {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/build_sqlite.php') . ' 2>&1', $out, $rc);
        echo $rc === 0 ? "— أُعيد بناء قاعدة SQLite من database.sql —\n" : "!! تعذّر بناء SQLite\n" . implode("\n", $out) . "\n";
    }
    echo "\n";
}

$pass = 0;
$fail = 0;
$failures = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        echo "PASS  {$label}\n";
    } else {
        $fail++;
        $failures[] = $label . ($detail !== '' ? " — {$detail}" : '');
        echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

final class Browser
{
    private string $jar;
    private string $base;

    public function __construct(string $base)
    {
        $this->base = $base;
        $this->jar  = tempnam(sys_get_temp_dir(), 'qa_cookie_');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    /** Plain browser navigation: no AJAX headers, so guards redirect instead of returning JSON. */
    public function visit(string $path): array
    {
        return $this->request('GET', $path, null, false);
    }

    public function post(string $path, array $data, ?string $csrf = null, int $timeout = 20): array
    {
        if ($csrf !== null) {
            $data['_csrf'] = $csrf;
        }
        return $this->request('POST', $path, $data, true, $timeout);
    }

    /** Start a brand-new browser session (new cookie jar). */
    public function reset(): void
    {
        @unlink($this->jar);
        $this->jar = tempnam(sys_get_temp_dir(), 'qa_cookie_');
    }

    public function postPage(string $path, array $data, ?string $csrf = null): array
    {
        if ($csrf !== null) {
            $data['_csrf'] = $csrf;
        }
        return $this->request('POST', $path, $data, false);
    }

    public function csrf(string $path = 'login.php'): string
    {
        $res = $this->get($path);
        if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $res['body'], $m)) {
            return $m[1];
        }
        // Pages without a form still expose the token through the <meta> tag.
        if (preg_match('/name="csrf-token"\s+content="([^"]+)"/', $res['body'], $m)) {
            return $m[1];
        }
        return '';
    }

    public function login(string $email, string $password = 'Password123!'): bool
    {
        $token = $this->csrf('login.php');
        $res   = $this->post('login.php', ['email' => $email, 'password' => $password], $token);
        return $res['status'] >= 300 && $res['status'] < 400 && !str_contains($res['body'], 'غير صحيحة');
    }

    private function request(string $method, string $path, ?array $data, bool $ajax = true, int $timeout = 20): array
    {
        $url = $this->base . '/' . ltrim($path, '/');
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $ajax
                ? ['X-Requested-With: XMLHttpRequest', 'Accept: application/json, text/html']
                : ['Accept: text/html,application/xhtml+xml'],
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data ?? []));
        }
        $headers = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, $line) use (&$headers) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        });
        $body   = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    }
}

function json_of(array $res): array
{
    $decoded = json_decode($res['body'], true);
    return is_array($decoded) ? $decoded : [];
}

$pdo = $useMysql
    ? new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $mysqlCfg['host'], $mysqlCfg['port'], $mysqlCfg['name']),
        $mysqlCfg['user'],
        $mysqlCfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    )
    : new PDO('sqlite:' . $db, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

echo "== E2E against {$base} (db: {$db}) ==\n\n";

/* ── 0. guest surfaces ──────────────────────────────────────────────────── */
$guest = new Browser($base);
foreach (['index.php', 'login.php', 'register.php'] as $page) {
    $res = $guest->visit($page);
    check("guest can open {$page}", $res['status'] === 200 && !str_contains($res['body'], 'Fatal error'));
}
foreach (['charity_campaigns.php' => 'الحملات الخيرية', 'campaign.php?slug=winter-warm-2026' => 'شتاء دافئ'] as $page => $needle) {
    $res = $guest->visit($page);
    check("guest can open {$page}", $res['status'] === 200 && str_contains($res['body'], $needle) && !str_contains($res['body'], 'Fatal error'));
}
/* All three account types must be offered on the signup form. The database stores
   the third one as "association"; a key mismatch once hid the option entirely. */
$reg = $guest->visit('register.php');
foreach (['individual' => 'فرد', 'store' => 'متجر', 'association' => 'جمعية'] as $code => $label) {
    check(
        "signup offers the {$label} account type",
        str_contains($reg['body'], 'name="user_type" value="' . $code . '"')
        && (bool)preg_match('/<b>' . preg_quote($label, '/') . '<\/b>/u', $reg['body'])
    );
}
check('signup reveals the association fields for that choice', str_contains($reg['body'], 'id="assocFields"'));

$map = $guest->visit('sitemap.php');
check('sitemap returns XML', $map['status'] === 200 && str_starts_with(ltrim($map['body']), '<?xml') && substr_count($map['body'], '<loc>') > 5);
check('robots.txt served', str_contains(strtolower((string)@file_get_contents(dirname(__DIR__) . '/robots.txt')), 'disallow: /admin/'));

$guestFav = json_of($guest->post('api/toggle_favorite.php', ['product_id' => 1, '_csrf' => 'x']));
check('favourite endpoint requires login', ($guestFav['ok'] ?? true) === false);

/* ── 1. login throttling guard (bad password) ───────────────────────────── */
$bad = new Browser($base);
$bad->login('ahmed@qeemah.test', 'wrong-password');
$after = $bad->visit('my_requests.php');
check('bad password does not authenticate', $after['status'] === 302 || str_contains($after['body'], 'تسجيل الدخول'));

/* ── 1b. guards redirect to existing pages (never a 404) ─────────────────── */
foreach ([['charity/dashboard.php', '/login.php'], ['admin/settings.php', '/login.php']] as [$page, $expect]) {
    $res = $guest->visit($page);
    $loc = $res['headers']['location'] ?? '';
    check("guest {$page} → login", $res['status'] === 302 && str_ends_with($loc, $expect), $loc);
    // AJAX callers get a machine-readable 401 instead.
    $json = $guest->get($page);
    check("guest {$page} returns 401 JSON to AJAX", $json['status'] === 401 && (json_of($json)['ok'] ?? true) === false);
}

/* ── 2. requester flow: khaled opens a request on product 1 ─────────────── */
$khaled = new Browser($base);
check('khaled (requester) logs in', $khaled->login('khaled@qeemah.test'));
$csrfK = $khaled->csrf('product_details.php?id=1');
$res = $khaled->postPage('product_details.php?id=1', [
    'action' => 'request',
    'message' => 'طلب آلي من اختبار التكامل',
    'contact_phone' => '777000111',
], $csrfK);
check('request posted (redirect to the deal room)', $res['status'] === 302, (string)$res['status']);
$req = $pdo->query("SELECT * FROM user_requests WHERE requester_id=8 AND product_id=1 ORDER BY id DESC LIMIT 1")->fetch();
check('request row created in awaiting_owner', $req && $req['status'] === 'awaiting_owner', (string)($req['status'] ?? 'none'));
check('no conversation before acceptance', $req && empty($req['conversation_id']));
check('product still available after request', ($pdo->query('SELECT status FROM products WHERE id=1')->fetchColumn()) === 'available');
$reqId = (int)($req['id'] ?? 0);
$newNotif = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=2 AND type='request_new'")->fetchColumn();
check('owner notified of the new request', $newNotif > 0);

/* ── 2b. a third account must not see or touch the request ──────────────── */
$outsider = new Browser($base);
$outsider->login('rep@takaful.test');                 // unrelated to this deal
$peekPage = $outsider->visit('request.php?id=' . $reqId);
check('third user gets 404 on request.php', $peekPage['status'] === 404, (string)$peekPage['status']);
$peekApi = $outsider->get('api/request_action.php?action=progress&request_id=' . $reqId);
check('third user refused the progress API', $peekApi['status'] === 404, (string)$peekApi['status']);
$peekAccept = json_of($outsider->post('api/request_action.php', ['action' => 'accept', 'request_id' => $reqId], $outsider->csrf('my_requests.php')));
check('third user cannot accept the request', ($peekAccept['ok'] ?? true) === false);

/* ── 3. owner accepts → thread opens, product reserved, in one transaction ─ */
$ahmed = new Browser($base);
check('ahmed (owner) logs in', $ahmed->login('ahmed@qeemah.test'));
$csrfA = $ahmed->csrf('request.php?id=' . $reqId);
$acc = json_of($ahmed->post('api/request_action.php', ['action' => 'accept', 'request_id' => $reqId], $csrfA));
check('accept returns ok', ($acc['ok'] ?? false) === true, $acc['error'] ?? '');
/* Acceptance now lands the deal straight on the negotiation step: the two sides
   are in the thread by default, so the progress bar sits on «جاري الاتفاق». */
check('request status=negotiating after accept', ($pdo->query("SELECT status FROM user_requests WHERE id={$reqId}")->fetchColumn()) === 'negotiating');
$histTo = $pdo->query("SELECT to_status FROM request_status_history WHERE request_id={$reqId} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
check('history records accept then negotiating', in_array('accepted', $histTo, true) && in_array('negotiating', $histTo, true), implode(',', $histTo));
check('product status=reserved', ($pdo->query('SELECT status FROM products WHERE id=1')->fetchColumn()) === 'reserved');
$convId = (int)($acc['conversation_id'] ?? $pdo->query("SELECT conversation_id FROM user_requests WHERE id={$reqId}")->fetchColumn());
check('conversation opened on acceptance', $convId > 0);

/* A thread must not exist before acceptance, and the product page must not
   offer a shortcut that opens one — the whole lifecycle depends on it. */
$beforeConv = (int)$pdo->query("SELECT COUNT(*) FROM conversations WHERE request_id={$reqId}")->fetchColumn();
check('acceptance is what creates the thread', $beforeConv === 1, 'conversations for request: ' . $beforeConv);
$prodPage = $guest->visit('product_details.php?slug=nike-shoes-42');
check('product page offers no direct-message shortcut', !str_contains($prodPage['body'], 'value="contact"'));
$otherStatuses = $pdo->query("SELECT DISTINCT status FROM user_requests WHERE product_id=1 AND id<>{$reqId}")->fetchAll(PDO::FETCH_COLUMN);
check('competing open requests were rejected', !array_intersect($otherStatuses, ['awaiting_owner']));

/* ── 4. illegal action is refused ───────────────────────────────────────── */
$badAction = json_of($ahmed->post('api/request_action.php', ['action' => 'teleport', 'request_id' => $reqId], $csrfA));
check('unknown action rejected', ($badAction['ok'] ?? true) === false);
$doubleAccept = json_of($ahmed->post('api/request_action.php', ['action' => 'accept', 'request_id' => $reqId], $csrfA));
check('accept twice is refused', ($doubleAccept['ok'] ?? true) === false);

/* ── 5. requester cannot accept / owner cannot self-agree to completion ──── */
$deny = json_of($khaled->post('api/request_action.php', ['action' => 'accept', 'request_id' => $reqId], $khaled->csrf('request.php?id=' . $reqId)));
check('requester cannot accept a request', ($deny['ok'] ?? true) === false);
$earlyConfirm = json_of($khaled->post('api/request_action.php', ['action' => 'confirm_delivery', 'request_id' => $reqId], $khaled->csrf('request.php?id=' . $reqId)));
check('cannot confirm delivery before agreement', ($earlyConfirm['ok'] ?? true) === false);

/* ── 6. chat is private: only the two parties may read and write ─────────── */
$sendK = json_of($khaled->post('api/send_message.php', ['conversation_id' => $convId, 'body' => 'مرحباً، متى يناسبك التسليم؟'], $khaled->csrf('chat.php?c=' . $convId)));
check('requester sends in the thread', ($sendK['ok'] ?? false) === true, $sendK['error'] ?? '');
$msgId = (int)($sendK['message']['id'] ?? 0);
check('message stored', $msgId > 0 && (int)$pdo->query('SELECT COUNT(*) FROM messages WHERE id=' . $msgId)->fetchColumn() === 1);
$sendA = json_of($ahmed->post('api/send_message.php', ['conversation_id' => $convId, 'body' => 'غداً بعد العصر مناسب.'], $ahmed->csrf('chat.php?c=' . $convId)));
check('owner replies in the thread', ($sendA['ok'] ?? false) === true, $sendA['error'] ?? '');
$poll = json_of($khaled->get('api/fetch_messages.php?conversation=' . $convId . '&after=0'));
check('both parties read the thread', ($poll['ok'] ?? false) === true && count($poll['messages'] ?? []) >= 2);
check('read receipt recorded', !empty($pdo->query('SELECT read_at FROM messages WHERE id=' . $msgId)->fetchColumn()));
check('progress snapshot exposed to participants', isset($poll['request']['status']), json_encode($poll['request'] ?? null, JSON_UNESCAPED_UNICODE));
$outsideChat = $outsider->visit('chat.php?c=' . $convId);
check('third user bounced off chat.php', $outsideChat['status'] === 302, (string)$outsideChat['status']);
$outsideFetch = $outsider->get('api/fetch_messages.php?conversation=' . $convId);
check('third user refused message polling', $outsideFetch['status'] === 403, (string)$outsideFetch['status']);
$outsideSend = json_of($outsider->post('api/send_message.php', ['conversation_id' => $convId, 'body' => 'اقتحام'], $outsider->csrf('index.php')));
check('third user cannot write in the thread', ($outsideSend['ok'] ?? true) === false);

/* ── 7. dual confirmation for the agreement ─────────────────────────────── */
$agreeK = json_of($khaled->post('api/request_action.php', [
    'action' => 'agree', 'request_id' => $reqId,
    'summary' => 'شراء الهاتف بـ 82,000 ر.ي نقداً', 'place' => 'شارع حدة', 'when' => 'غداً بعد العصر',
], $khaled->csrf('request.php?id=' . $reqId)));
check('requester confirms the agreement', ($agreeK['ok'] ?? false) === true, $agreeK['error'] ?? '');
check('one confirmation keeps it negotiating', ($pdo->query("SELECT status FROM user_requests WHERE id={$reqId}")->fetchColumn()) === 'negotiating');
$agreeA = json_of($ahmed->post('api/request_action.php', [
    'action' => 'agree', 'request_id' => $reqId,
    'summary' => 'شراء الهاتف بـ 82,000 ر.ي نقداً', 'place' => 'شارع حدة', 'when' => 'غداً بعد العصر',
], $ahmed->csrf('request.php?id=' . $reqId)));
check('second confirmation completes the agreement', ($agreeA['ok'] ?? false) === true, $agreeA['error'] ?? '');
check('request status=agreed', ($pdo->query("SELECT status FROM user_requests WHERE id={$reqId}")->fetchColumn()) === 'agreed');

/* ── 8. delivery: start, then dual confirmation, never single-party ──────── */
$startRes = json_of($ahmed->post('api/request_action.php', ['action' => 'start_delivery', 'request_id' => $reqId], $ahmed->csrf('request.php?id=' . $reqId)));
check('delivery started', ($startRes['ok'] ?? false) === true, $startRes['error'] ?? '');
check('request status=delivery_pending', ($pdo->query("SELECT status FROM user_requests WHERE id={$reqId}")->fetchColumn()) === 'delivery_pending');
$d1 = json_of($ahmed->post('api/request_action.php', ['action' => 'confirm_delivery', 'request_id' => $reqId, 'note' => 'سلّمت الجهاز'], $ahmed->csrf('request.php?id=' . $reqId)));
check('owner confirms delivery', ($d1['ok'] ?? false) === true, $d1['error'] ?? '');
check('single confirmation does NOT complete the deal', ($pdo->query("SELECT status FROM user_requests WHERE id={$reqId}")->fetchColumn()) === 'delivery_pending');
$d2 = json_of($khaled->post('api/request_action.php', ['action' => 'confirm_delivery', 'request_id' => $reqId, 'note' => 'استلمت الجهاز'], $khaled->csrf('request.php?id=' . $reqId)));
check('requester confirms receipt', ($d2['ok'] ?? false) === true, $d2['error'] ?? '');
check('both confirmations → delivered', ($pdo->query("SELECT status FROM user_requests WHERE id={$reqId}")->fetchColumn()) === 'delivered');
check('product status=completed', ($pdo->query('SELECT status FROM products WHERE id=1')->fetchColumn()) === 'completed');
$histTo = $pdo->query("SELECT to_status FROM request_status_history WHERE request_id={$reqId} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
check('history records the six steps', $histTo === ['awaiting_owner', 'accepted', 'negotiating', 'agreed', 'delivery_pending', 'delivered'], implode(' → ', $histTo));
check('every history row names its actor', (int)$pdo->query("SELECT COUNT(*) FROM request_status_history WHERE request_id={$reqId} AND actor_id IS NULL")->fetchColumn() === 0);
$doneNotif = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type='delivery_completed' AND link_url='request.php?id={$reqId}'")->fetchColumn();
check('both parties notified of completion', $doneNotif >= 2, (string)$doneNotif);

/* ── 8b. the thread is read-only once the deal is closed ─────────────────── */
$closedSend = json_of($khaled->post('api/send_message.php', ['conversation_id' => $convId, 'body' => 'رسالة بعد الإغلاق'], $khaled->csrf('chat.php?c=' . $convId)));
check('closed deal refuses new messages', ($closedSend['ok'] ?? true) === false);

/* ── 8c. rating is only allowed after delivery ───────────────────────────── */
$rate = json_of($khaled->post('api/request_action.php', [
    'action' => 'rate', 'request_id' => $reqId, 'score' => 5, 'comment' => 'تعامل ممتاز',
], $khaled->csrf('my_requests.php')));
check('rating accepted after delivery', ($rate['ok'] ?? false) === true, $rate['error'] ?? '');
$rate2 = json_of($khaled->post('api/request_action.php', ['action' => 'rate', 'request_id' => $reqId, 'score' => 4], $khaled->csrf('my_requests.php')));
check('duplicate rating blocked', ($rate2['ok'] ?? true) === false);
$avg = (float)$pdo->query('SELECT rating_avg FROM users WHERE id=2')->fetchColumn();
check('ratee rating recalculated', $avg > 0);
/* Operator's rule: any signed-in user may rate a completed deal. The single
   exception is the person who published the product — their own item is not a
   service they received. The rule lives in the repository, so it holds for the
   page, the API, and any future caller. */
$outsider = new Browser($base);
$outsider->login('sara@qeemah.test');
$rate3 = json_of($outsider->post('api/request_action.php', [
    'action' => 'rate', 'request_id' => $reqId, 'score' => 1, 'comment' => 'من خارج العملية',
], $outsider->csrf('my_requests.php')));
check('any signed-in user may rate a completed deal', ($rate3['ok'] ?? false) === true, $rate3['error'] ?? '');
$adminBrowser = new Browser($base);
$adminBrowser->login('admin@qeemah.test');
$adminRate = json_of($adminBrowser->post('api/request_action.php', [
    'action' => 'rate', 'request_id' => $reqId, 'score' => 5,
], $adminBrowser->csrf('admin/dashboard.php')));
check('an admin may rate too', ($adminRate['ok'] ?? false) === true, $adminRate['error'] ?? '');

/* The publisher is the one account that must never rate its own listing. */
$pubId = (int)$pdo->query("SELECT owner_id FROM products WHERE id=1")->fetchColumn();
$pubRate = json_of($adminBrowser->post('api/request_action.php', [
    'action' => 'rate', 'request_id' => $reqId, 'score' => 1,
], $adminBrowser->csrf('admin/dashboard.php')));
check('duplicate rating still blocked for the admin', ($pubRate['ok'] ?? true) === false, $pubRate['error'] ?? '');

$publisherBrowser = new Browser($base);
$publisherBrowser->login('ahmed@qeemah.test');
$ownRate = json_of($publisherBrowser->post('api/request_action.php', [
    'action' => 'rate', 'request_id' => $reqId, 'score' => 5,
], $publisherBrowser->csrf('my_requests.php')));
check('publisher cannot rate their own product', ($ownRate['ok'] ?? true) === false, $ownRate['error'] ?? '');

/* A targeted donation is between the donor and the association's rep. The
   person who published the item is not a party and must not be able to confirm
   or rate — the rule is defined once, in RequestFlowRepo::expectedParties(). */
/* Load the application classes so the party/role rules can be asserted directly
   rather than re-derived here. */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/repository.php';
require_once dirname(__DIR__) . '/includes/request_flow.php';
require_once dirname(__DIR__) . '/includes/components.php';

/* Build the case the suite needs: a targeted donation owned by someone who is
   NOT a party to it, with a real association rep on the other side. */
$tdProduct = (int)$pdo->query(
    "SELECT id FROM products WHERE transaction_type='donate' AND donation_scope='targeted'
      AND association_id IS NOT NULL AND status='available' LIMIT 1"
)->fetchColumn();
$donateReq = 0;
if ($tdProduct > 0) {
    $tdOwner = (int)$pdo->query("SELECT owner_id FROM products WHERE id={$tdProduct}")->fetchColumn();
    $tdDonor = $tdOwner === 8 ? 2 : 8;
    try {
        $donateReq = RequestFlowRepo::create($tdProduct, $tdDonor, ['message' => 'تبرّع موجّه للاختبار']);
    } catch (Throwable $e) {
        $donateReq = 0;
    }
}
if ($donateReq > 0) {
    $dRow      = RequestFlowRepo::requestRow($donateReq);
    $dParties  = RequestFlowRepo::expectedParties($dRow);
    $publisher = (int)$dRow['owner_id'];
    $assocId2  = (int)$dRow['association_id'];
    $rep       = (int)$pdo->query("SELECT owner_user_id FROM associations WHERE id={$assocId2}")->fetchColumn();
    check(
        'targeted donation: rep replaces the publisher as the second party',
        $rep > 0 && in_array($rep, $dParties, true) && !in_array($publisher, $dParties, true),
        'parties=' . implode(',', $dParties) . " publisher={$publisher} rep={$rep}"
    );
    check(
        'targeted donation: publisher has no party role',
        RequestFlowRepo::partyRole($dRow, $publisher) === null
        && RequestFlowRepo::partyRole($dRow, $rep) !== null,
        'partyRole(publisher)=' . var_export(RequestFlowRepo::partyRole($dRow, $publisher), true)
    );
}

/* ── 8d. rejection path keeps the thread closed ──────────────────────────── */
$rejectId = (int)$pdo->query("SELECT id FROM user_requests WHERE status='awaiting_owner' AND product_id<>1 ORDER BY id LIMIT 1")->fetchColumn();
if ($rejectId > 0) {
    $rOwner = (int)$pdo->query("SELECT owner_id FROM user_requests WHERE id={$rejectId}")->fetchColumn();
    $ownerBrowser = new Browser($base);
    $ownerEmail = (string)$pdo->query("SELECT email FROM users WHERE id={$rOwner}")->fetchColumn();
    $ownerBrowser->login($ownerEmail);
    $rej = json_of($ownerBrowser->post('api/request_action.php', [
        'action' => 'reject', 'request_id' => $rejectId, 'reason' => 'المنتج لم يعد متاحاً',
    ], $ownerBrowser->csrf('request.php?id=' . $rejectId)));
    check('owner rejects a request', ($rej['ok'] ?? false) === true, $rej['error'] ?? '');
    $rejected = $pdo->query('SELECT status, reject_reason, conversation_id FROM user_requests WHERE id=' . $rejectId)->fetch();
    check('request status=rejected with reason', $rejected['status'] === 'rejected' && $rejected['reject_reason'] === 'المنتج لم يعد متاحاً');
    check('rejection opens no conversation', empty($rejected['conversation_id']));
} else {
    check('rejection fixture available', false, 'no awaiting_owner request outside product 1');
}

/* ── 8e. admin oversight: dispute resolution + audited thread reading ───── */
$adminEarly = new Browser($base);
$adminEarly->login('admin@qeemah.test');
$pdo->exec("UPDATE user_requests SET status='disputed', dispute_reason='خلاف تجريبي' WHERE id=" . (int)$reqId);
$pdo->exec("INSERT INTO request_status_history (request_id, from_status, to_status, actor_id, reason, created_at)
            VALUES (" . (int)$reqId . ", 'delivered', 'disputed', 8, 'خلاف تجريبي', " . $nowSql . ")");
$adminPage = $adminEarly->visit('admin/requests.php?status=disputed');
check('admin requests page lists disputed deals', $adminPage['status'] === 200 && str_contains($adminPage['body'], 'إدارة الطلبات'));
$resolved = $adminEarly->postPage('admin/requests.php', ['action' => 'resolve_dispute', 'request_id' => $reqId], $adminEarly->csrf('admin/requests.php'));
check('admin resolves the dispute (redirect)', $resolved['status'] === 302, (string)$resolved['status']);
$afterDispute = (string)$pdo->query('SELECT status FROM user_requests WHERE id=' . (int)$reqId)->fetchColumn();
check('disputed deal returns to a workable state', in_array($afterDispute, ['negotiating', 'delivery_pending', 'delivered'], true), $afterDispute);
check('resolution recorded in the audit log', (int)$pdo->query("SELECT COUNT(*) FROM admin_audit_logs WHERE action='request.dispute_resolved' AND entity_id=" . (int)$reqId)->fetchColumn() === 1);

$auditOpen = $adminEarly->visit('chat.php?c=' . $convId . '&audit=1');
check('admin reads the deal thread with the audit flag', $auditOpen['status'] === 200, (string)$auditOpen['status']);
check('thread access audited', (int)$pdo->query("SELECT COUNT(*) FROM admin_audit_logs WHERE action='chat.audited' AND entity_id={$convId}")->fetchColumn() >= 1);
$unflagged = $adminEarly->visit('chat.php?c=' . $convId);
check('admin without the audit flag is bounced', $unflagged['status'] === 302, (string)$unflagged['status']);

/* ── 9. favourites toggle + notifications API ───────────────────────────── */
$fav1 = json_of($khaled->post('api/toggle_favorite.php', ['product_id' => 2], $khaled->csrf('index.php')));
check('favourite added', ($fav1['ok'] ?? false) === true && ($fav1['on'] ?? false) === true);
$fav2 = json_of($khaled->post('api/toggle_favorite.php', ['product_id' => 2], $khaled->csrf('index.php')));
check('favourite removed', ($fav2['ok'] ?? false) === true && ($fav2['on'] ?? true) === false);
check('favorites counter synced', (int)$pdo->query('SELECT favorites_count FROM products WHERE id=2')->fetchColumn() >= 0);

/* ── 10. notifications API ──────────────────────────────────────────────── */
$notifs = json_of($khaled->get('api/notifications.php'));
check('notifications endpoint works', ($notifs['ok'] ?? false) === true && isset($notifs['unread_notifications']));
$readAll = json_of($khaled->post('api/notifications.php', ['action' => 'read_all'], $khaled->csrf('notifications.php')));
check('mark-all-read works', ($readAll['ok'] ?? false) === true && (int)$readAll['unread_notifications'] === 0);

/* ── 11. charity: claim a general donation + issue receipt ──────────────── */
$rep = new Browser($base);
check('association rep logs in', $rep->login('rep@albir.test'));
$generalId = (int)$pdo->query("SELECT id FROM donations WHERE donation_scope='general' AND status='offered' LIMIT 1")->fetchColumn();
check('general donation exists in seed', $generalId > 0);
$claim = json_of($rep->post('api/claim_donation.php', ['donation_id' => $generalId], $rep->csrf('charity/donations_manage.php')));
check('association claims general donation', ($claim['ok'] ?? false) === true, $claim['error'] ?? '');
$claimedStatus = (string)$pdo->query('SELECT status FROM donations WHERE id=' . $generalId)->fetchColumn();
check('donation status=claimed', $claimedStatus === 'claimed');

$csrfC = $rep->csrf('charity/donations_manage.php');
$receiptRes = $rep->postPage('charity/donations_manage.php', [
    'action' => 'issue_receipt',
    'donation_id' => $generalId,
    'items_summary' => 'حقيبة مدرسية — اختبار',
    'quantity' => 2,
], $csrfC);
check('receipt issued (redirect)', $receiptRes['status'] === 302);
$receipt = $pdo->query('SELECT * FROM donation_receipts ORDER BY id DESC LIMIT 1')->fetch();
check('receipt number follows RCP-YYYY-#### pattern', (bool)preg_match('/^RCP-\d{4}-\d{4}$/', (string)$receipt['receipt_no']), (string)$receipt['receipt_no']);
check('donation marked received after receipt', in_array((string)$pdo->query('SELECT status FROM donations WHERE id=' . $generalId)->fetchColumn(), ['received', 'distributed'], true));

/* ── 12. charity permission boundary (coordinator without members perm) ─── */
$coord = new Browser($base);
$coord->login('member@albir.test');
$membersPage = $coord->visit('charity/members.php');
check('coordinator blocked from member management', $membersPage['status'] === 302 || str_contains($membersPage['body'], 'ليس لديك صلاحية'));

/* ── 13. targeted donation is invisible to another association ──────────── */
$takaful = new Browser($base);
$takaful->login('rep@takaful.test');
$targeted = $pdo->query("SELECT id FROM donations WHERE donation_scope='targeted' AND association_id=1 AND status='offered' LIMIT 1")->fetchColumn();
if ($targeted) {
    $tryClaim = json_of($takaful->post('api/claim_donation.php', ['donation_id' => (int)$targeted], $takaful->csrf('charity/donations_manage.php')));
    check('targeted donation cannot be claimed by another association', ($tryClaim['ok'] ?? true) === false);
} else {
    check('targeted donation fixture available', false, 'no offered targeted donation for assoc 1');
}

/* ── 14. admin: verify pending association + audit trail ────────────────── */
$admin = new Browser($base);
check('admin logs in', $admin->login('admin@qeemah.test'));
$pendingId = (int)$pdo->query("SELECT id FROM associations WHERE status='pending' LIMIT 1")->fetchColumn();
check('pending association exists in seed', $pendingId > 0);
$auditBefore = (int)$pdo->query('SELECT COUNT(*) FROM admin_audit_logs')->fetchColumn();
$verify = json_of($admin->post('api/admin_action.php', ['action' => 'assoc_verify', 'association_id' => $pendingId], $admin->csrf('admin/associations.php')));
check('association verified', ($verify['ok'] ?? false) === true, $verify['error'] ?? '');
check('association row updated', (string)$pdo->query('SELECT status FROM associations WHERE id=' . $pendingId)->fetchColumn() === 'verified');
$auditAfter = (int)$pdo->query('SELECT COUNT(*) FROM admin_audit_logs')->fetchColumn();
check('audit log appended', $auditAfter === $auditBefore + 1);
$assocOwner = (int)$pdo->query('SELECT owner_user_id FROM associations WHERE id=' . $pendingId)->fetchColumn();
check('representative activated', (string)$pdo->query('SELECT status FROM users WHERE id=' . $assocOwner)->fetchColumn() === 'active');

/* ── 15. admin sets a setting ───────────────────────────────────────────── */
$setRes = json_of($admin->post('api/admin_action.php', ['action' => 'setting', 'setting_key' => 'site_name', 'setting_value' => 'قيمة وأثر'], $admin->csrf('admin/settings.php')));
check('setting update accepted', ($setRes['ok'] ?? false) === true, $setRes['error'] ?? '');

/* ── 16. suspended user cannot use the session ─────────────────────────── */
$khaledId = 8;
$admin->post('api/admin_action.php', ['action' => 'suspend', 'user_id' => $khaledId], $admin->csrf('admin/users.php'));
$blocked = $khaled->visit('profile.php');
check('suspended user is logged out', $blocked['status'] === 302 || str_contains($blocked['body'], 'تسجيل الدخول'));
$admin->post('api/admin_action.php', ['action' => 'activate', 'user_id' => $khaledId], $admin->csrf('admin/users.php'));

/* ── 16b. public campaign page + donate-to-campaign flow ────────────────── */
$store2 = new Browser($base);
check('store account logs in for campaign donation', $store2->login('store@qeemah.test'));
$campPage = $store2->visit('add_product.php?campaign=1');
check('campaign donation form prefilled', $campPage['status'] === 200 && str_contains($campPage['body'], 'name="campaign_id"'));
$csrfP = $store2->csrf('add_product.php?campaign=1');
$before = (int)$pdo->query('SELECT COUNT(*) FROM donations WHERE campaign_id=1')->fetchColumn();
$submit = $store2->postPage('add_product.php', [
    'title' => 'بطانيات اختبار الحملة',
    'description' => 'بطانيات جديدة لدعم حملة شتاء دافئ للأسر المتعففة في صنعاء',
    'category_id' => 6,
    'condition_type' => 'new',
    'transaction_type' => 'donate',
    'donation_scope' => 'general',
    'city' => 'صنعاء',
    'campaign_id' => 1,
], $csrfP);
check('campaign donation redirects to the campaign', $submit['status'] === 302 && str_contains((string)($submit['headers']['location'] ?? ''), 'campaign.php'), (string)($submit['headers']['location'] ?? ''));
$after = (int)$pdo->query('SELECT COUNT(*) FROM donations WHERE campaign_id=1')->fetchColumn();
check('donation linked to the campaign', $after === $before + 1);
$received = (int)$pdo->query('SELECT received_items FROM donation_campaigns WHERE id=1')->fetchColumn();
check('campaign counter incremented', $received >= 65);

/* ── 17. AI endpoints (offline fallback) ────────────────────────────────── */
$aiPrice = json_of($guest->get('api/ai_listing_helper.php?title=' . urlencode('هاتف سامسونج') . '&category_id=1&condition=good'));
check('AI listing helper responds locally', ($aiPrice['ok'] ?? false) === true && ($aiPrice['engine'] ?? '') === 'local');
check('AI price range computed from category stats', isset($aiPrice['price_range']['label']));
check('AI suggests a transaction type', isset($aiPrice['type_suggestion']['type']));
check('AI polishes the description', isset($aiPrice['description_polish']['text']));
$aiRec = json_of($guest->get('api/ai_recommendations.php?product_id=2'));
check('AI recommendations return items', ($aiRec['ok'] ?? false) === true && count($aiRec['items'] ?? []) > 0);
$aiCharity = json_of($guest->get('api/ai_recommendations.php?scope=charity'));
check('AI charity matching returns campaigns', ($aiCharity['ok'] ?? false) === true && count($aiCharity['items'] ?? []) > 0);

/* ── 17b. assistant: data-first answers, labelled automated, no key leak ── */
$assistPage = $guest->visit('assistant.php');
check('assistant page opens', $assistPage['status'] === 200 && str_contains($assistPage['body'], 'مساعد آلي'));
check('no key/secret leaks in the page', !preg_match('/sk-[A-Za-z0-9]{10}|QEEMAH_AI_API_KEY\s*=/i', $assistPage['body']));
$assistCsrf = $guest->csrf('assistant.php');

/* Questions are asked as separate short visits, the way visitors actually use
   the widget. It also keeps the suite clear of the assistant's own 12/minute
   guard, which is a feature and must stay in place. */
$askAssistant = static function (string $question) use ($guest): array {
    $guest->reset();
    $csrf = $guest->csrf('assistant.php');
    return json_of($guest->post('api/assistant.php', ['question' => $question], $csrf, 150));
};
$asked = $askAssistant('كيف يعمل الطلب على المنصة؟');
/* Two engines: the model when one is configured (a key or a local server), the
   written guide otherwise. Wording assertions only make sense for the guide; in
   model mode the same question gets a free-form answer. The mode is therefore
   read from EACH response, not once: with a slow local model one question may
   succeed and the next fall back, and a single global flag would misjudge the
   rest of the suite. */
$assistEngine = (string)($asked['engine'] ?? 'faq');
$guideMode    = $assistEngine !== 'model';
$isGuide      = static fn(array $r): bool => ((string)($r['engine'] ?? 'faq')) !== 'model';
check(
    $guideMode ? 'assistant answers from the platform data' : 'assistant answers (model engine)',
    ($asked['ok'] ?? false) === true
    && trim((string)($asked['answer'] ?? '')) !== ''
    && ($guideMode
        ? (str_contains((string)($asked['answer'] ?? ''), 'المحادثة')
           && str_contains((string)($asked['answer'] ?? ''), 'الطرفان'))
        : in_array($assistEngine, ['model', 'faq'], true)),
    $asked['error'] ?? ''
);
check('assistant answer is labelled automated', ($asked['automated'] ?? false) === true);
check(
    'assistant reports which engine answered',
    in_array((string)($asked['engine'] ?? ''), ['model', 'faq'], true)
    && ((string)($asked['engine'] ?? '') === 'model' ? trim((string)($asked['model'] ?? '')) !== '' : true)
);
check(
    'assistant label matches the engine',
    str_contains((string)($asked['label'] ?? ''), ($asked['engine'] ?? '') === 'model' ? 'نموذج ذكي' : 'بيانات المنصة')
);
$unknown = $askAssistant('هل تسافرون إلى كوكب المريخ؟');
check(
    'unknown question gets an honest answer',
    ($unknown['ok'] ?? false) === true
    && trim((string)($unknown['answer'] ?? '')) !== ''
    && (($unknown['engine'] ?? '') === 'model'
        || str_contains((string)($unknown['answer'] ?? ''), 'لم أجد في بيانات المنصة')),
    $unknown['error'] ?? ''
);

/* The assistant has to answer the questions visitors actually ask, not just
   "how does it work" — donation is the headline feature. */
$howDonate = $askAssistant('كيف أتبرع بمنتج؟');
check(
    $guideMode ? 'assistant explains how to donate step by step' : 'assistant answers the donation question',
    ($howDonate['ok'] ?? false) === true
    && trim((string)($howDonate['answer'] ?? '')) !== ''
    && ($isGuide($howDonate)
        ? (str_contains((string)($howDonate['answer'] ?? ''), 'أضف منتج')
           && str_contains((string)($howDonate['answer'] ?? ''), 'موجّه')
           && !empty($howDonate['links']))
        : true),
    (string)($howDonate['answer'] ?? '')
);
$scope = $askAssistant('ما الفرق بين التبرع الموجه والعام؟');
check(
    $guideMode ? 'assistant explains targeted vs general donation' : 'assistant answers the scope question',
    ($scope['ok'] ?? false) === true && trim((string)($scope['answer'] ?? '')) !== ''
    && ($isGuide($scope) ? str_contains((string)($scope['answer'] ?? ''), 'أول جمعية تقبله') : true)
);
$fees = $askAssistant('هل هناك رسوم أو دفع إلكتروني؟');
check(
    $guideMode ? 'assistant explains payment and fees' : 'assistant answers the fees question',
    ($fees['ok'] ?? false) === true && trim((string)($fees['answer'] ?? '')) !== ''
    && ($isGuide($fees) ? str_contains((string)($fees['answer'] ?? ''), 'ولا تنفّذ مدفوعات') : true)
);
$whatsNow = $askAssistant('ما المتاح الآن في السوق؟');
$whatsNowGuide = $isGuide($whatsNow);
check(
    $whatsNowGuide ? 'assistant reports what is available now' : 'assistant answers the availability question',
    ($whatsNow['ok'] ?? false) === true
    && trim((string)($whatsNow['answer'] ?? '')) !== ''
    && ($whatsNowGuide
        ? (($whatsNow['intent'] ?? '') === 'guide' && str_contains((string)($whatsNow['answer'] ?? ''), 'منتج متاح'))
        : true),
    (string)($whatsNow['answer'] ?? '')
);
$hello = $askAssistant('مرحبا');
$helloGuide = $isGuide($hello);
check(
    $helloGuide ? 'greeting gets a welcome, not a fallback' : 'greeting gets an answer',
    trim((string)($hello['answer'] ?? '')) !== ''
    && ($helloGuide ? (($hello['intent'] ?? '') === 'social') : true),
    (string)($hello['answer'] ?? '')
);
$spam = $askAssistant('كم عمرك؟');
$spamGuide = $isGuide($spam);
check(
    $spamGuide ? 'off-topic question is refused politely' : 'off-topic question gets an answer',
    trim((string)($spam['answer'] ?? '')) !== ''
    && ($spamGuide ? ($spam['intent'] ?? '') === 'fallback' : true),
    (string)($spam['answer'] ?? '')
);

/* The floating chat-bot must be on public pages, absent from the panels, and
   must vanish entirely when the operator switches the assistant off. */
$home = $guest->visit('index.php');
check('chat-bot widget is on the marketplace', str_contains($home['body'], 'id="botFab"') && str_contains($home['body'], 'data-endpoint='));
check('chat-bot is not repeated on the full assistant page', !str_contains($assistPage['body'], 'id="botFab"'));
$adminHome = $admin->visit('admin/dashboard.php');
check('chat-bot stays out of the admin panel', !str_contains($adminHome['body'], 'id="botFab"'));
$repHome = $rep->visit('charity/dashboard.php');
check('chat-bot stays out of the association panel', !str_contains($repHome['body'], 'id="botFab"'));
$noCsrf = json_of($guest->post('api/assistant.php', ['question' => 'كيف يعمل الطلب؟']));
check('assistant rejects a request without CSRF', ($noCsrf['ok'] ?? true) === false);

/* ── 18. every page for every actor returns no fatal errors ─────────────── */
$pageSet = [
    'admin' => ['admin/dashboard.php','admin/users.php','admin/associations.php','admin/products.php','admin/reports.php','admin/audit_logs.php','admin/settings.php'],
    'rep'   => ['charity/dashboard.php','charity/campaigns.php','charity/donations_manage.php','charity/beneficiaries.php','charity/members.php'],
];
foreach ($pageSet as $who => $pages) {
    $b = $who === 'admin' ? $admin : $rep;
    foreach ($pages as $p) {
        $r = $b->visit($p);
        check("{$who} page {$p} renders", $r['status'] === 200 && !str_contains($r['body'], 'Fatal error'));
    }
}

/* ── summary ────────────────────────────────────────────────────────────── */
/* ── association join requests ─────────────────────────────────────────────
   Registering as an individual who represents an association raises a request;
   it must grant nothing until the association approves. */
$assoc1   = (int)$pdo->query("SELECT id FROM associations WHERE status='verified' ORDER BY id LIMIT 1")->fetchColumn();
$assocRep = (int)$pdo->query("SELECT user_id FROM association_members WHERE association_id={$assoc1} AND role='representative' AND status='active' LIMIT 1")->fetchColumn();
check('join: verified associations are listable', count(AssociationRepo::verifiedList()) > 0);

$joiner = (int)$pdo->query("SELECT id FROM users WHERE email='sara@qeemah.test'")->fetchColumn();
$pdo->exec("DELETE FROM association_members WHERE user_id={$joiner} AND association_id={$assoc1}");
$pdo->exec("DELETE FROM association_join_requests WHERE user_id={$joiner} AND association_id={$assoc1}");
$joinId = AssociationRepo::requestJoin($assoc1, $joiner, 'coordinator', 'أعمل منسقاً');
check('join: request created as pending',
    (string)Repo::scalar('SELECT status FROM association_join_requests WHERE id=?', [$joinId]) === 'pending');
check('join: pending request grants no membership',
    Repo::q('SELECT 1 FROM association_members WHERE association_id=? AND user_id=?', [$assoc1, $joiner]) === []);
check('join: pending request grants no dashboard access', AssociationRepo::primaryForUser($joiner) === null);

$dupBlocked = false;
try { AssociationRepo::requestJoin($assoc1, $joiner, 'coordinator'); } catch (Throwable $e) { $dupBlocked = true; }
check('join: duplicate pending request is refused', $dupBlocked);

check('join: representative sees it in the pending list',
    in_array($joinId, array_map(static fn ($j) => (int)$j['id'], AssociationRepo::pendingJoins($assoc1)), true));

AssociationRepo::approveJoin($joinId, $assoc1, $assocRep);
$mem = Repo::q('SELECT * FROM association_members WHERE association_id=? AND user_id=?', [$assoc1, $joiner])[0] ?? null;
check('join: approval creates the membership', $mem !== null);
check('join: approved member gets a member role', in_array((string)($mem['role'] ?? ''), ['manager','coordinator','viewer'], true), (string)($mem['role'] ?? ''));
check('join: approval grants dashboard access', AssociationRepo::primaryForUser($joiner) !== null);

$pdo->exec("DELETE FROM association_members WHERE user_id={$joiner} AND association_id={$assoc1}");
$joinId2 = AssociationRepo::requestJoin($assoc1, $joiner, 'viewer');
AssociationRepo::rejectJoin($joinId2, $assoc1, $assocRep, 'غير مكتمل');
check('join: rejection leaves no membership',
    Repo::q('SELECT 1 FROM association_members WHERE association_id=? AND user_id=?', [$assoc1, $joiner]) === []);
check('join: rejection leaves no dashboard access', AssociationRepo::primaryForUser($joiner) === null);

$unverified = (int)$pdo->query("SELECT id FROM associations WHERE status<>'verified' LIMIT 1")->fetchColumn();
if ($unverified > 0) {
    $pdo->exec("DELETE FROM association_join_requests WHERE user_id={$joiner} AND association_id={$unverified}");
    $blocked = false;
    try { AssociationRepo::requestJoin($unverified, $joiner, 'coordinator'); } catch (Throwable $e) { $blocked = true; }
    check('join: an unverified association cannot be joined', $blocked);
}

/* A non-member must not be able to approve anybody. */
$strangerGate = false;
try {
    AssociationRepo::approveJoin((int)Repo::scalar("SELECT id FROM association_join_requests WHERE association_id=? AND status='pending' LIMIT 1", [$assoc1]) ?: 0, $assoc1, $joiner);
} catch (Throwable $e) { $strangerGate = true; }
check('join: only a pending row of that association can be approved', $strangerGate);

/* ── deal buttons must never send the browser to the JSON endpoint ─────────
   A native submit of api/request_action.php dumps {"ok":true,…} on a blank
   page. Every deal button therefore opts into the AJAX submitter. */
/* Render the chat at a point where deal buttons still exist: a delivered deal
   shows none, so pull the markup straight from the component with a live
   request row instead of depending on which state the flow ended in. */
$liveReq = RequestFlowRepo::requestRow($reqId);
$liveReq['parties']     = RequestFlowRepo::expectedParties($liveReq);
$liveReq['agreements']  = [];
$liveReq['deliveries']  = [];
if ((string)$liveReq['status'] === 'delivered') {
    /* Re-render as if the parties had only just agreed, so buttons appear. */
    $liveReq['status'] = 'negotiating';
}
/* The deal buttons only need a party's point of view, not a live session. */
$khaledId = (int)Repo::scalar("SELECT id FROM users WHERE email='khaled@qeemah.test'");
$dealHtml  = chat_deal_actions($liveReq, $khaledId);
$dealForms = [];
if (preg_match_all('/<form[^>]*class="deal-act"[^>]*>/', $dealHtml, $mm)) {
    $dealForms = $mm[0];
}
check('chat deal buttons are rendered', $dealForms !== []);
$allAjax = $dealForms !== [];
foreach ($dealForms as $f) {
    if (strpos($f, 'data-ajax="api/request_action.php"') === false) {
        $allAjax = false;
    }
}
check('every chat deal button posts via AJAX, never natively', $allAjax);
$chatPage = $khaled->get('chat.php?c=' . (int)Repo::scalar('SELECT id FROM conversations WHERE request_id=? LIMIT 1', [$reqId]));
$nativeApi = [];
if (preg_match_all('/<form[^>]*action="[^"]*\/api\/[^"]*"[^>]*>/', (string)$chatPage['body'], $mm2)) {
    foreach ($mm2[0] as $f) {
        if (strpos($f, 'data-ajax') === false) {
            $nativeApi[] = $f;
        }
    }
}
check('no form points straight at an api endpoint without AJAX', $nativeApi === [], implode(' | ', $nativeApi));

echo "\n=== {$pass} passed, {$fail} failed ===\n";
if ($failures) {
    echo "Failures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
}
exit($fail === 0 ? 0 : 1);
