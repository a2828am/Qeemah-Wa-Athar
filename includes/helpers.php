<?php
/**
 * includes/helpers.php — small, dependency-free utilities used everywhere.
 */
declare(strict_types=1);

/* ───────────────────────────── Output / escaping ───────────────────────────── */

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_ok(array $data = [], int $status = 200): void
{
    json_out(['ok' => true] + $data, $status);
}

function json_err(string $message, int $status = 400, array $extra = []): void
{
    json_out(['ok' => false, 'error' => $message] + $extra, $status);
}

/* ──────────────────────────────── Time / dates ─────────────────────────────── */

/** Always produced in PHP so MySQL and SQLite store identical strings. */
function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    $ts   = strtotime($datetime);
    $diff = time() - $ts;

    if ($diff < 60)     return 'الآن';
    if ($diff < 3600)   return 'قبل ' . intdiv($diff, 60) . ' دقيقة';
    if ($diff < 86400)  return 'قبل ' . intdiv($diff, 3600) . ' ساعة';
    if ($diff < 604800) return 'قبل ' . intdiv($diff, 86400) . ' يوم';

    return date('Y-m-d', $ts);
}

function money(float|int|string|null $amount, string $currency = 'ر.ي'): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    return number_format((float)$amount, 0, '.', ',') . ' ' . $currency;
}

/* ─────────────────────────────────── Input ─────────────────────────────────── */

function post(string $key, ?string $default = null): ?string
{
    $v = $_POST[$key] ?? null;
    return is_string($v) ? trim($v) : $default;
}

function get_q(string $key, ?string $default = null): ?string
{
    $v = $_GET[$key] ?? null;
    return is_string($v) ? trim($v) : $default;
}

function post_int(string $key, ?int $default = null): ?int
{
    $v = post($key);
    return ($v === null || $v === '' || !is_numeric($v)) ? $default : (int)$v;
}

function get_int(string $key, ?int $default = null): ?int
{
    $v = get_q($key);
    return ($v === null || $v === '' || !is_numeric($v)) ? $default : (int)$v;
}

function valid_email(string $email): bool
{
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

/** Arabic-aware slug (keeps Arabic letters, latin, digits). */
function slugify(string $text, string $suffix = ''): string
{
    $text = trim($text);
    $text = preg_replace('/[\x{0640}]/u', '', $text);          // tatweel
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text) ?? '';
    $text = trim($text, '-');
    $text = mb_strtolower($text, 'UTF-8');
    if ($suffix !== '') {
        $text .= '-' . $suffix;
    }
    return mb_substr($text !== '' ? $text : 'item', 0, 180, 'UTF-8');
}

function excerpt(?string $text, int $limit = 120): string
{
    $text = trim((string)$text);
    if (mb_strlen($text, 'UTF-8') <= $limit) {
        return $text;
    }
    return mb_substr($text, 0, $limit, 'UTF-8') . '…';
}

/* ────────────────────────────── CSRF protection ────────────────────────────── */

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(?string $token = null): bool
{
    $token ??= $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($token) && $token !== '' && hash_equals($_SESSION['_csrf'] ?? '', $token);
}

function require_csrf(): void
{
    if (!csrf_ok()) {
        if (is_ajax()) {
            json_err('انتهت صلاحية الجلسة. حدّث الصفحة وحاول مرة أخرى.', 419);
        }
        http_response_code(419);
        exit('انتهت صلاحية الجلسة.');
    }
}

/* ────────────────────────────── Request helpers ────────────────────────────── */

function is_ajax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function wants_json(): bool
{
    return is_ajax() || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
}

function redirect(string $to, int $status = 302): void
{
    // App paths are always resolved from the site root: a bare 'login.php'
    // must not turn into 'charity/login.php' when called from a sub-folder.
    if (!preg_match('#^([a-z][a-z0-9+.\-]*:)?//#i', $to) && !str_starts_with($to, '/')) {
        $to = url($to);
    }
    header('Location: ' . $to, true, $status);
    exit;
}

/**
 * Base URL of the application, discovered from the file system — so the folder
 * can be called anything (qeemah-platform, qeemah, graduation-project …) and can
 * sit in htdocs, a sub-folder or a document root.
 *
 * QEEMAH_BASE_URI in the environment or the QEEMAH_BASE_URI constant overrides
 * it (useful behind a proxy or for a pretty URL).
 */
function base_url(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    if (defined('QEEMAH_BASE_URL') && QEEMAH_BASE_URL !== null && QEEMAH_BASE_URL !== '') {
        return $cached = rtrim((string)QEEMAH_BASE_URL, '/');
    }
    $env = getenv('QEEMAH_BASE_URI');
    if (is_string($env) && $env !== '') {
        return $cached = rtrim('/' . trim($env, '/'), '/');
    }

    $root    = defined('QEEMAH_ROOT') ? str_replace('\\', '/', (string)QEEMAH_ROOT) : str_replace('\\', '/', dirname(__DIR__));
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/')) : '';
    $script  = (string)($_SERVER['SCRIPT_NAME'] ?? '');

    // (a) The project folder is physically inside the web root (normal XAMPP).
    if ($docRoot !== '' && $root !== '' && str_starts_with($root . '/', rtrim($docRoot, '/') . '/')) {
        $rel = substr($root, strlen($docRoot));
        return $cached = rtrim('/' . trim((string)$rel, '/'), '/');
    }

    // (b) Symlinked / aliased folder: resolve the running script on disk and
    //     subtract the part of its URL that belongs to the project.
    if ($docRoot !== '' && $script !== '' && $root !== '') {
        $scriptFile = realpath($docRoot . $script);
        if ($scriptFile !== false) {
            $scriptFile = str_replace('\\', '/', $scriptFile);
            if (str_starts_with($scriptFile . '/', rtrim($root, '/') . '/')) {
                $inner = substr($scriptFile, strlen($root));          // e.g. /admin/dashboard.php
                $base  = substr($script, 0, strlen($script) - strlen($inner));
                return $cached = rtrim('/' . trim((string)$base, '/'), '/');
            }
        }
    }

    // (c) Last resort: the directory of the running script.
    if ($script !== '') {
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if (in_array($base, ['', '/', '.'], true)) {
            $base = '';
        }
        // Pages inside a known sub-folder still belong to the same base.
        foreach (['/charity', '/admin', '/api', '/pages', '/tools'] as $sub) {
            if (str_ends_with($base, $sub)) {
                $base = substr($base, 0, -strlen($sub));
                break;
            }
        }
        return $cached = $base;
    }
    return $cached = '';
}

function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $base = base_url();
    return ($base === '' ? '' : $base) . '/' . $path;
}

function asset(string $path): string
{
    // Accept both 'css/style.css' and 'assets/css/style.css'.
    $path = ltrim($path, '/');
    if (str_starts_with($path, 'assets/')) {
        $path = substr($path, strlen('assets/'));
    }
    return url('assets/' . $path);
}

function client_ip(): ?string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', (string)$_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return null;
}

function user_agent(): string
{
    return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250, 'UTF-8');
}

/* ───────────────────────────── Flash messages ──────────────────────────────── */

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $items;
}

/* ─────────────────────────────── Pagination ────────────────────────────────── */

function paginate(int $total, int $perPage, int $page): array
{
    $perPage  = max(1, $perPage);
    $pages    = max(1, (int)ceil($total / $perPage));
    $page     = min(max(1, $page), $pages);
    return [
        'total'    => $total,
        'per_page' => $perPage,
        'page'     => $page,
        'pages'    => $pages,
        'offset'   => ($page - 1) * $perPage,
        'has_prev' => $page > 1,
        'has_next' => $page < $pages,
    ];
}

/* ─────────────────────────────── Image upload ──────────────────────────────── */

/**
 * Validates and stores one uploaded image. Returns the public relative path.
 * Throws RuntimeException with an Arabic message on any validation failure.
 */
function store_uploaded_image(array $file, string $folder = 'products'): string
{
    $maxMb   = 5;
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('لم يتم اختيار صورة.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('تعذّر رفع الصورة (رمز ' . (int)$file['error'] . ').');
    }
    if (($file['size'] ?? 0) > $maxMb * 1024 * 1024) {
        throw new RuntimeException('حجم الصورة يتجاوز ' . $maxMb . ' ميجابايت.');
    }

    $info = @getimagesize($file['tmp_name']);
    $mime = $info['mime'] ?? mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('نوع الصورة غير مدعوم. المسموح: JPG أو PNG أو WEBP.');
    }

    $dir = __DIR__ . '/../storage/uploads/' . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('تعذّر إنشاء مجلد الرفع.');
    }

    $name = date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('تعذّر حفظ الصورة على الخادم.');
    }

    return 'storage/uploads/' . $folder . '/' . $name;
}

function delete_uploaded_image(?string $relativePath): void
{
    if (!$relativePath || !str_starts_with($relativePath, 'storage/uploads/')) {
        return;
    }
    $full = __DIR__ . '/../' . $relativePath;
    if (is_file($full)) {
        @unlink($full);
    }
}

/* ──────────────────────────────── Icons (SVG) ──────────────────────────────── */

/**
 * Single source of truth for every glyph in the UI: 24×24 stroke icons.
 * Zero emojis anywhere in the rendered output.
 */
function icon_paths(): array
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $p = [
        /* navigation */
        'home'          => '<path d="M3 9.5 12 3l9 6.5V20a1.5 1.5 0 0 1-1.5 1.5H15v-7H9v7H4.5A1.5 1.5 0 0 1 3 20Z"/>',
        'store'         => '<path d="M3 9 4.5 4h15L21 9"/><path d="M4 9h16v10.5A1.5 1.5 0 0 1 18.5 21h-13A1.5 1.5 0 0 1 4 19.5Z"/><path d="M9 21v-6h6v6"/>',
        'dashboard'     => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'plus'          => '<path d="M12 5v14M5 12h14"/>',
        'link'          => '<path d="M10 13a5 5 0 0 0 7.1 0l3-3a5 5 0 0 0-7.1-7.1L11 4.9"/><path d="M14 11a5 5 0 0 0-7.1 0l-3 3a5 5 0 0 0 7.1 7.1L13 19.1"/>',
        'share'         => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5 15.4 17.5M15.4 6.5 8.6 10.5"/>',
        'lock'          => '<rect x="4" y="10.5" width="16" height="10" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>',
        'clipboard'     => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4V3h6v1M9 9h6M9 13h6M9 17h4"/>',
                'truck'         => '<path d="M3 16V6.5A1.5 1.5 0 0 1 4.5 5H14v11"/><path d="M14 9h4l3 4v3h-7"/><circle cx="7.5" cy="18" r="2"/><circle cx="17.5" cy="18" r="2"/><path d="M3 16h2.5M9.5 16h6M19.5 16H21"/>',
        'zap'           => '<path d="M13 2 4.5 13.5H11l-1 8.5 8.5-11.5H12Z"/>',
        'history'       => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 8v4.5l3 1.8"/>',
'filter'        => '<path d="M3 5h18l-7 8v6l-4 2v-8Z"/>',
        'refresh'       => '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/>',
        'plus-circle'   => '<circle cx="12" cy="12" r="9"/><path d="M12 8.5v7M8.5 12h7"/>',
        'help'          => '<circle cx="12" cy="12" r="9"/><path d="M9.2 9.3a2.9 2.9 0 0 1 5.6 1c0 1.9-2.8 2.3-2.8 4"/><circle cx="12" cy="17.6" r=".6" fill="currentColor" stroke="none"/>',
        'menu'          => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'x'             => '<path d="M18 6 6 18M6 6l12 12"/>',
        'chevron-down'  => '<path d="m6 9 6 6 6-6"/>',
        'chevron-left'  => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'arrow-right'   => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'arrow-left'    => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'arrow-up-right'=> '<path d="M7 7h10v10"/><path d="M7 17 17 7"/>',
        'external'      => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',

        /* identity / navigation meta */
        'leaf'          => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>',
        'sprout'        => '<path d="M7 20h10"/><path d="M10 20c5.5-2.5.8-6.4 3-10"/><path d="M9.5 9.4c1.1.8 1.8 2.2 2.3 3.7-2 .4-3.5.4-4.8-.3-1.2-.6-2.3-1.9-3-4.2 2.8-.5 4.4 0 5.5.8z"/><path d="M14.1 6a7 7 0 0 0-1.1 4c1.9-.1 3.3-.6 4.3-1.4 1-1 1.6-2.3 1.7-4.6-2.7.1-4 1-4.9 2z"/>',
        'user'          => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'user-plus'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
        'users'         => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13A4 4 0 0 1 16 11"/>',
        'log-in'        => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
        'log-out'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'heart'         => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
        'bell'          => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'message-circle'=> '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        'mail'          => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'phone'         => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'map-pin'       => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
        'search'        => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.34-4.34"/>',
        'filter'        => '<path d="M22 3H2l8 9.46V19l4 2v-8.54Z"/>',
        'sliders'       => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h10M18 18h2"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="16" cy="18" r="2"/>',
        'lock'          => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'key'           => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
        'eye'           => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
        'eye-off'       => '<path d="M10.7 5.1A10.9 10.9 0 0 1 12 5c7 0 10 7 10 7a18 18 0 0 1-2.2 3.2"/><path d="M6.6 6.6A18 18 0 0 0 2 12s3 7 10 7a10.9 10.9 0 0 0 4.4-.9"/><path d="m2 2 20 20"/>',

        /* commerce */
        'tag'           => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
        'repeat'        => '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
        'hand-heart'    => '<path d="M11 14h2a2 2 0 1 0 0-4h-3c-.6 0-1.1.2-1.4.6L3 16"/><path d="m7 20 1.6-1.4c.3-.4.8-.6 1.4-.6h4c1.1 0 2.1-.4 2.8-1.2l4.6-4.4a2 2 0 0 0-2.75-2.91l-4.2 3.9"/><path d="m2 15 6 6"/><path d="M19.5 8.5c.7-.7 1.5-1.6 1.5-2.7A2.73 2.73 0 0 0 16 4a2.78 2.78 0 0 0-5 1.8c0 1.2.8 2 1.5 2.8L16 12Z"/>',
        'gift'          => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"/>',
        'package'       => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'smartphone'    => '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
        'laptop'        => '<path d="M18 5a2 2 0 0 1 2 2v8.526a2 2 0 0 0 .212.897l1.068 2.127a1 1 0 0 1-.9 1.45H3.62a1 1 0 0 1-.9-1.45l1.068-2.127A2 2 0 0 0 4 15.526V7a2 2 0 0 1 2-2z"/><path d="M2 18h20"/>',
        'shirt'         => '<path d="M20.38 3.46 16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23z"/>',
        'armchair'      => '<path d="M19 9V6a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v3"/><path d="M3 16a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-5a2 2 0 0 0-4 0v2H7v-2a2 2 0 0 0-4 0Z"/><path d="M5 18v2M19 18v2"/>',
        'book-open'     => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
        'baby'          => '<path d="M9 12h.01M15 12h.01"/><path d="M10 16c.5.3 1.2.5 2 .5s1.5-.2 2-.5"/><path d="M19 6.3a9 9 0 0 1 1.8 3.9 2 2 0 0 1 0 3.6 9 9 0 0 1-17.6 0 2 2 0 0 1 0-3.6A9 9 0 0 1 12 3c2 0 3.5 1.1 3.5 2.5s-.9 2.5-3.5 2.5"/><path d="M3.5 11.5c1.5 0 2.5 1 2.5 2.5"/>',
        'house'         => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'wallet'        => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
        'trending'      => '<path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/>',
        'shopping-bag'  => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
        'sparkles'      => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>',
        'badge-dollar'  => '<path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 18V6"/>',

        /* status */
        'check'         => '<path d="M20 6 9 17l-5-5"/>',
        'check-circle'  => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'clock'         => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'loader'        => '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>',
        'alert-circle'  => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
        'alert-triangle'=> '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><path d="M12 9v4M12 17h.01"/>',
        'info'          => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        'ban'           => '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
        'shield'        => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'star'          => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.12 2.12 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.12 2.12 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.12 2.12 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.12 2.12 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.12 2.12 0 0 0 1.597-1.16z"/>',
        'flag'          => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22v-7"/>',
        'thumbs-up'     => '<path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/>',

        /* admin / charity modules */
        'landmark'      => '<path d="M10 18v-7"/><path d="M11.12 2.198a2 2 0 0 1 1.76.006l7.866 3.847c.476.233.31.949-.22.949H3.474c-.53 0-.695-.716-.22-.949z"/><path d="M14 18v-7"/><path d="M18 18v-7"/><path d="M3 22h18"/><path d="M6 18v-7"/>',
        'megaphone'     => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'receipt'       => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M8 7h8M8 11h8M8 15h5"/>',
        'handshake'     => '<path d="m11 17 2 2a1 1 0 1 0 3-3"/><path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="m21 3 1 11h-2"/><path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/>',
        'clipboard'     => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 13h6M9 17h4"/>',
        'settings'      => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'activity'      => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'file-text'     => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v5h5"/><path d="M8 13h8M8 17h5"/>',
        'trash'         => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'edit'          => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/>',
        'download'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'upload'        => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
        'image'         => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
        'cpu'           => '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 2v2M15 2v2M9 20v2M15 20v2M2 9h2M2 15h2M20 9h2M20 15h2"/>',
        'refresh'       => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        'clock-history' => '<path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l4 2"/>',
        'inbox'         => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'send'          => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
        'calendar'      => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/>',
        'printer'       => '<path d="M6 9V3h12v6"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8" rx="1"/>',
        'list'          => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
        'bar-chart'     => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'user-check'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>',
        'target'        => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/>',
        'archive'       => '<rect x="2" y="4" width="20" height="5" rx="1"/><path d="M4 9v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9"/><path d="M10 13h4"/>',
        'hash'          => '<path d="M4 9h16M4 15h16M10 3 8 21M16 3l-2 18"/>',
        'shield-check'  => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'route'         => '<circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/>',
    ];
    return $p;
}

function icon(string $name, int $size = 20, string $class = ''): string
{
    $paths = icon_paths();
    $d     = $paths[$name] ?? $paths['info'];
    return '<svg class="ic ' . e($class) . '" width="' . $size . '" height="' . $size . '" style="width:' . $size . 'px;height:' . $size . 'px"'
        . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . $d . '</svg>';
}

/* ───────────────────────── Domain vocabulary (single source) ───────────────── */

function transaction_meta(): array
{
    return [
        'sell'   => ['label' => 'للبيع',  'icon' => 'tag',        'class' => 'badge-sell'],
        'swap'   => ['label' => 'تبادل',  'icon' => 'repeat',     'class' => 'badge-swap'],
        'donate' => ['label' => 'تبرّع',  'icon' => 'hand-heart', 'class' => 'badge-donate'],
        'free'   => ['label' => 'مجاني',  'icon' => 'gift',       'class' => 'badge-free'],
    ];
}

function op_badge(string $type): string
{
    $meta = transaction_meta()[$type] ?? transaction_meta()['free'];
    return '<span class="badge ' . $meta['class'] . '">' . icon($meta['icon'], 13) . $meta['label'] . '</span>';
}

function condition_label(string $condition): string
{
    return [
        'new'      => 'جديد',
        'like_new' => 'كأنه جديد',
        'good'     => 'جيد',
        'fair'     => 'مقبول',
        'used'     => 'مستعمل',
    ][$condition] ?? $condition;
}

function condition_icon_class(string $condition): string
{
    return 'cond-' . $condition;
}

/**
 * Six-step delivery lifecycle shared by every transaction type.
 * Order here drives the progress bar; terminal states sit outside it.
 */
function request_steps(): array
{
    return [
        'awaiting_owner'   => ['label' => 'تم إرسال الطلب',                 'short' => 'إرسال الطلب', 'icon' => 'send'],
        'accepted'         => ['label' => 'قُبل الطلب',                     'short' => 'قبول الطلب',  'icon' => 'check-circle'],
        'negotiating'      => ['label' => 'المحادثة والاتفاق جارٍ',          'short' => 'الاتفاق',     'icon' => 'message-circle'],
        'agreed'           => ['label' => 'تم الاتفاق',                     'short' => 'تم الاتفاق',  'icon' => 'handshake'],
        'delivery_pending' => ['label' => 'بانتظار تأكيد التسليم/الاستلام',  'short' => 'التسليم',     'icon' => 'truck'],
        'delivered'        => ['label' => 'تم التسليم',                     'short' => 'تم التسليم',  'icon' => 'check'],
    ];
}

/** Terminal states: shown beside the bar, never as a finished deal. */
function request_closed_states(): array
{
    return [
        'rejected'  => ['label' => 'مرفوض',                       'icon' => 'ban',            'class' => 'st-rejected'],
        'cancelled' => ['label' => 'ملغى',                        'icon' => 'x',              'class' => 'st-cancelled'],
        'disputed'  => ['label' => 'يوجد خلاف — بحاجة إلى مراجعة', 'icon' => 'alert-triangle', 'class' => 'st-disputed'],
    ];
}

function request_status_meta(): array
{
    $classes = [
        'awaiting_owner'   => 'st-review',
        'accepted'         => 'st-accepted',
        'negotiating'      => 'st-progress',
        'agreed'           => 'st-agreed',
        'delivery_pending' => 'st-progress',
        'delivered'        => 'st-completed',
    ];
    $meta = [];
    foreach (request_steps() as $key => $step) {
        $meta[$key] = ['label' => $step['label'], 'icon' => $step['icon'], 'class' => $classes[$key]];
    }
    foreach (request_closed_states() as $key => $state) {
        $meta[$key] = $state;
    }
    return $meta;
}

/** 1-based position in the six-step bar, 0 for terminal states. */
function request_step_index(string $status): int
{
    $i = array_search($status, array_keys(request_steps()), true);
    return $i === false ? 0 : $i + 1;
}

function request_is_closed(string $status): bool
{
    return isset(request_closed_states()[$status]);
}

function req_status(string $status): string
{
    $all = request_status_meta();
    $m = $all[$status] ?? $all['awaiting_owner'];
    return '<span class="status ' . $m['class'] . '">' . icon($m['icon'], 13) . e($m['label']) . '</span>';
}

function product_status_meta(): array
{
    return [
        'draft'     => ['label' => 'مسودة',      'icon' => 'file-text',    'class' => 'st-new'],
        'available' => ['label' => 'متاح',       'icon' => 'check-circle', 'class' => 'st-accepted'],
        'reserved'  => ['label' => 'محجوز',      'icon' => 'clock',        'class' => 'st-review'],
        'completed' => ['label' => 'مكتمل',      'icon' => 'check',        'class' => 'st-completed'],
        'archived'  => ['label' => 'مؤرشف',      'icon' => 'package',      'class' => 'st-cancelled'],
        'removed'   => ['label' => 'محذوف',      'icon' => 'trash',        'class' => 'st-rejected'],
    ];
}

function prod_status(string $status): string
{
    $m = product_status_meta()[$status] ?? product_status_meta()['available'];
    return '<span class="status ' . $m['class'] . '">' . icon($m['icon'], 13) . $m['label'] . '</span>';
}

function donation_status_meta(): array
{
    return [
        'offered'     => ['label' => 'معروض',   'icon' => 'hand-heart',   'class' => 'st-new'],
        'claimed'     => ['label' => 'محجوز',   'icon' => 'clock',        'class' => 'st-review'],
        'received'    => ['label' => 'مستلَم',  'icon' => 'check-circle', 'class' => 'st-accepted'],
        'distributed' => ['label' => 'موزَّع',   'icon' => 'check',        'class' => 'st-completed'],
        'cancelled'   => ['label' => 'ملغى',    'icon' => 'x',            'class' => 'st-cancelled'],
    ];
}

function donation_status(string $status): string
{
    $m = donation_status_meta()[$status] ?? donation_status_meta()['offered'];
    return '<span class="status ' . $m['class'] . '">' . icon($m['icon'], 13) . $m['label'] . '</span>';
}

/**
 * Allowed transitions. Agreement and delivery steps are only reachable
 * through the two-sided confirmations in RequestFlowRepo — never by calling
 * a status update directly.
 */
function request_transitions(): array
{
    return [
        'awaiting_owner'   => ['accepted', 'rejected', 'cancelled'],
        'accepted'         => ['negotiating', 'cancelled', 'disputed'],
        'negotiating'      => ['agreed', 'cancelled', 'disputed'],
        'agreed'           => ['delivery_pending', 'cancelled', 'disputed'],
        'delivery_pending' => ['delivered', 'cancelled', 'disputed'],
        'delivered'        => [],
        'rejected'         => [],
        'cancelled'        => [],
        'disputed'         => ['negotiating', 'delivery_pending', 'cancelled'],
    ];
}

function can_transition(string $from, string $to): bool
{
    return in_array($to, request_transitions()[$from] ?? [], true);
}

function avatar_letter(string $name): string
{
    return mb_substr(trim($name), 0, 1, 'UTF-8');
}
