<?php
/**
 * includes/auth.php
 * -----------------------------------------------------------------------------
 * Authentication, the current-user container and the permission gate.
 *
 *   Auth::start()          – start the hardened session (idempotent)
 *   Auth::attempt()        – email + password login with throttling
 *   Auth::user()           – cached current user row (or null)
 *   Auth::id()             – current user id (0 when guest)
 *   Auth::check()          – is somebody signed in
 *   Auth::requireLogin()   – redirect guests to login.php keeping the target
 *   Auth::logout()         – revoke the DB session, clear cookies
 *   Gate::allows()/require() – permission checks backed by roles/permissions
 */
declare(strict_types=1);

use Qeemah\Config\Database;

final class Auth
{
    private static ?array $user = null;
    private static bool $resolved = false;
    private static ?array $permissions = null;
    private static ?array $roles = null;
    private static ?array $association = null;
    private static bool $associationFetched = false;

    private const MAX_ATTEMPTS = 6;      // per identifier per window
    private const ATTEMPT_WINDOW = 900;  // 15 minutes

    /* ── session ─────────────────────────────────────────────────────────── */

    public static function start(): void
    {
        Database::boot_session();
    }

    public static function id(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    public static function check(): bool
    {
        return self::id() > 0 && self::user() !== null;
    }

    public static function isGuest(): bool
    {
        return !self::check();
    }

    /** Alias of isGuest() — reads naturally in endpoint guards. */
    public static function guest(): bool
    {
        return !self::check();
    }

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        $id = (int)($_SESSION['user_id'] ?? 0);
        if ($id <= 0) {
            self::$user = null;
            return null;
        }

        $user = UserRepo::find($id);
        if (!$user || $user['status'] === 'deleted' || $user['status'] === 'suspended') {
            // Account vanished or was blocked while the session lived on.
            self::clearSession();
            self::$user = null;
            return null;
        }

        self::$user       = $user;
        self::$permissions = null;
        self::$roles       = null;
        self::$association = null;
        self::$associationFetched = false;
        return self::$user;
    }

    public static function refresh(): void
    {
        self::$resolved    = false;
        self::$user        = null;
        self::$permissions = null;
        self::$roles       = null;
        self::$association = null;
        self::$associationFetched = false;
    }

    /* ── login / logout ──────────────────────────────────────────────────── */

    /**
     * @return array{ok:bool,message:string,user?:array}
     */
    public static function attempt(string $email, string $password, bool $remember = false): array
    {
        $email = mb_strtolower(trim($email));

        $throttle = self::throttle($email);
        if (!$throttle['ok']) {
            SessionRepo::logEvent(null, 'locked', null, 'محاولات دخول متكررة: ' . $email, client_ip(), user_agent());
            return ['ok' => false, 'message' => $throttle['message']];
        }

        $user = UserRepo::findByEmail($email);

        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            self::recordFailure($email);
            SessionRepo::logEvent($user['id'] ?? null, 'failed_login', null, 'كلمة مرور خاطئة: ' . $email, client_ip(), user_agent());
            return ['ok' => false, 'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.'];
        }

        if ($user['status'] === 'suspended') {
            SessionRepo::logEvent((int)$user['id'], 'locked', null, 'حساب موقوف', client_ip(), user_agent());
            return ['ok' => false, 'message' => 'هذا الحساب موقوف. تواصل مع إدارة المنصة.'];
        }
        if ($user['status'] === 'deleted') {
            return ['ok' => false, 'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.'];
        }

        // Rehash transparently when PHP's defaults change.
        if (password_needs_rehash((string)$user['password_hash'], PASSWORD_BCRYPT)) {
            UserRepo::setPassword((int)$user['id'], password_hash($password, PASSWORD_BCRYPT));
        }

        self::establish((int)$user['id'], $remember);
        self::clearFailures($email);

        if ($user['status'] === 'pending') {
            // Pending accounts (e.g. association reps awaiting approval) may browse.
            flash('info', 'حسابك قيد المراجعة من إدارة المنصة.');
        }

        return ['ok' => true, 'message' => 'مرحباً بك مجدداً، ' . $user['full_name'] . '.', 'user' => UserRepo::find((int)$user['id'])];
    }

    public static function establish(int $userId, bool $remember = false): void
    {
        // Fresh session id on privilege change (fixation defence).
        session_regenerate_id(true);
        $_SESSION['user_id']   = $userId;
        $_SESSION['login_at']  = time();
        $_SESSION['_csrf']     = bin2hex(random_bytes(32));
        $_SESSION['remember']  = $remember ? 1 : 0;

        SessionRepo::create(
            session_id(),
            $userId,
            (string)$_SESSION['_csrf'],
            client_ip(),
            user_agent()
        );
        UserRepo::touchLogin($userId);
        SessionRepo::logEvent($userId, 'login', session_id(), null, client_ip(), user_agent());
        ActivityRepo::log($userId, 'auth.login');

        if ($remember) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), session_id(), [
                'expires'  => time() + 60 * 60 * 24 * 30,
                'path'     => $cookie['path'] ?: '/',
                'domain'   => $cookie['domain'] ?: '',
                'secure'   => (bool)$cookie['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        self::refresh();
        self::user();
    }

    public static function logout(): void
    {
        $userId = self::id();
        if ($userId > 0) {
            if (session_id() !== '') {
                SessionRepo::revoke(session_id());
            }
            SessionRepo::logEvent($userId, 'logout', session_id(), null, client_ip(), user_agent());
            ActivityRepo::log($userId, 'auth.logout');
        }
        self::clearSession();
        self::refresh();
    }

    private static function clearSession(): void
    {
        $_SESSION = [];
        if (session_id() !== '' && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'] ?: '/',
                'domain'   => $params['domain'] ?: '',
                'secure'   => (bool)$params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    /* ── guards ──────────────────────────────────────────────────────────── */

    public static function requireLogin(string $target = ''): void
    {
        if (self::check()) {
            return;
        }
        if (wants_json() || is_ajax()) {
            json_err('انتهت الجلسة. يرجى تسجيل الدخول من جديد.', 401);
        }
        $back = $target !== '' ? $target : ($_SERVER['REQUEST_URI'] ?? '');
        $_SESSION['intended'] = $back;
        flash('info', 'يرجى تسجيل الدخول للمتابعة.');
        redirect('login.php');
    }

    public static function intended(): string
    {
        $back = (string)($_SESSION['intended'] ?? '');
        unset($_SESSION['intended']);
        // Only allow same-site relative paths.
        if ($back === '' || str_starts_with($back, '//') || preg_match('#^https?://#i', $back)) {
            return 'index.php';
        }
        return $back;
    }

    /* ── roles, permissions, association ─────────────────────────────────── */

    public static function roles(): array
    {
        if (self::$roles === null) {
            self::$roles = self::check() ? UserRepo::rolesOf((int)self::id()) : [];
        }
        return self::$roles;
    }

    public static function hasRole(string ...$codes): bool
    {
        foreach ($codes as $c) {
            if (in_array($c, self::roles(), true)) {
                return true;
            }
        }
        return false;
    }

    public static function isAdmin(): bool
    {
        return self::hasRole('admin', 'moderator');
    }

    public static function isFullAdmin(): bool
    {
        return self::hasRole('admin');
    }

    public static function isAssociationMember(): bool
    {
        return self::check() && self::association() !== null;
    }

    /** The association the signed-in user works for (if any), with member permissions. */
    public static function association(): ?array
    {
        if (!self::check()) {
            return null;
        }
        if (self::$associationFetched === false) {
            self::$associationFetched = true;
            self::$association = AssociationRepo::primaryForUser((int)self::id());
        }
        return self::$association;
    }

    /** The granular member permission flags for the current association. */
    public static function memberPerms(): array
    {
        $assoc = self::association();
        if (!$assoc) {
            return ['campaigns' => false, 'donations' => false, 'beneficiaries' => false, 'members' => false];
        }
        if (self::isFullAdmin()) {
            return ['campaigns' => true, 'donations' => true, 'beneficiaries' => true, 'members' => true];
        }
        return [
            'campaigns'     => (bool)($assoc['can_campaigns'] ?? true),
            'donations'     => (bool)($assoc['can_donations'] ?? true),
            'beneficiaries' => (bool)($assoc['can_beneficiaries'] ?? false),
            'members'       => (bool)($assoc['can_members'] ?? false),
        ];
    }

    public static function permissions(): array
    {
        if (self::$permissions === null) {
            self::$permissions = self::check() ? UserRepo::permissionsOf((int)self::id()) : [];
        }
        return self::$permissions;
    }

    public static function can(string $permission): bool
    {
        if (!self::check()) {
            return false;
        }
        if (self::isFullAdmin()) {
            return true;
        }
        return in_array($permission, self::permissions(), true);
    }

    /** Convenience flags used by the layout. */
    public static function firstName(): string
    {
        $u = self::user();
        if (!$u) {
            return 'زائر';
        }
        $parts = preg_split('/\s+/u', (string)$u['full_name']) ?: [];
        return (string)($parts[0] ?? $u['full_name']);
    }

    public static function initials(): string
    {
        $u = self::user();
        if (!$u) {
            return '؟';
        }
        return mb_substr((string)$u['full_name'], 0, 1);
    }

    /* ── throttling ──────────────────────────────────────────────────────── */

    private static function throttle(string $email): array
    {
        $bucket = $_SESSION['_login_attempts'] ?? [];
        $now    = time();
        $bucket = array_values(array_filter($bucket, static fn ($t) => $now - (int)$t < self::ATTEMPT_WINDOW));
        $_SESSION['_login_attempts'] = $bucket;

        if (count($bucket) >= self::MAX_ATTEMPTS) {
            $wait = (int)ceil((self::ATTEMPT_WINDOW - ($now - (int)$bucket[0])) / 60);
            return [
                'ok'      => false,
                'message' => 'تم تجاوز عدد المحاولات. أعد المحاولة بعد ' . max(1, $wait) . ' دقيقة.',
            ];
        }
        return ['ok' => true, 'message' => ''];
    }

    private static function recordFailure(string $email): void
    {
        $_SESSION['_login_attempts']   = $_SESSION['_login_attempts'] ?? [];
        $_SESSION['_login_attempts'][] = time();
    }

    private static function clearFailures(string $email): void
    {
        unset($_SESSION['_login_attempts']);
    }
}

/**
 * Permission gate used by pages and API endpoints.
 */
final class Gate
{
    public static function allows(string $permission): bool
    {
        return Auth::can($permission);
    }

    public static function require(string $permission, string $message = 'لا تملك صلاحية للوصول إلى هذه الصفحة.'): void
    {
        if (self::allows($permission)) {
            return;
        }
        if (wants_json()) {
            json_err($message, 403);
        }
        http_response_code(403);
        flash('error', $message);
        redirect('index.php');
    }

    public static function requireAdmin(): void
    {
        Auth::requireLogin();
        if (!Auth::isAdmin()) {
            if (wants_json()) {
                json_err('هذه الصفحة مخصّصة لإدارة المنصة.', 403);
            }
            http_response_code(403);
            flash('error', 'هذه الصفحة مخصّصة لإدارة المنصة.');
            redirect('index.php');
        }
    }

    public static function requireFullAdmin(): void
    {
        self::requireAdmin();
        if (!Auth::isFullAdmin()) {
            if (wants_json()) {
                json_err('هذا الإجراء متاح لمسؤول النظام فقط.', 403);
            }
            http_response_code(403);
            flash('error', 'هذا الإجراء متاح لمسؤول النظام فقط.');
            redirect('admin/dashboard.php');
        }
    }

    /**
     * Association pages: any member may view, but each capability is checked
     * separately through memberPerms().
     */
    public static function requireAssociation(): array
    {
        Auth::requireLogin();
        $assoc = Auth::association();
        if ($assoc === null) {
            if (wants_json()) {
                json_err('هذا القسم مخصّص لحسابات الجمعيات.', 403);
            }
            http_response_code(403);
            flash('error', 'هذا القسم مخصّص لحسابات الجمعيات الخيرية.');
            redirect('index.php');
        }
        return $assoc;
    }

    public static function requireMemberCapability(string $key): void
    {
        $perms = Auth::memberPerms();
        if (!empty($perms[$key])) {
            return;
        }
        $labels = [
            'campaigns'     => 'إدارة الحملات',
            'donations'     => 'استلام التبرعات',
            'beneficiaries' => 'إدارة المستفيدين',
            'members'       => 'إدارة الأعضاء',
        ];
        $msg = 'ليس لديك صلاحية «' . ($labels[$key] ?? $key) . '» في هذه الجمعية.';
        if (wants_json()) {
            json_err($msg, 403);
        }
        http_response_code(403);
        flash('error', $msg);
        redirect('charity/dashboard.php');
    }

    public static function canManageProduct(array $product): bool
    {
        return Auth::check() && ((int)$product['owner_id'] === Auth::id() || Auth::can('product.moderate'));
    }
}
