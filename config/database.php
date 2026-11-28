<?php
/**
 * config/database.php
 * -----------------------------------------------------------------------------
 * Portable PDO connection + hardened session bootstrap.
 *
 * Default (XAMPP / WAMP / Laragon / any host): MySQL on 127.0.0.1:3306 with the
 * account you normally use — XAMPP's default is root with an empty password, so
 * the platform runs out of the box at http://localhost:8080/qeemah-platform
 * with nothing to edit. If your MySQL uses a password, either edit the two
 * constants below (LOCAL_PASS) or export QEEMAH_DB_PASS before starting Apache;
 * the environment always wins, so secrets can stay out of the code.
 *
 * SQLite stays available for machines without a MySQL server
 * (QEEMAH_DB_DRIVER=sqlite) — the automated test-suite uses it. Every query is
 * written with `?` placeholders and PHP-generated timestamps so both drivers
 * behave identically.
 */
declare(strict_types=1);

namespace Qeemah\Config;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    /* ── Local (XAMPP) defaults — edit only if your MySQL differs ────────────
       XAMPP ships MySQL with user "root" and no password on port 3306, which is
       exactly what a fresh install needs. Put a real password here ONLY if the
       server is not your own machine; otherwise use the environment variable. */
    public const LOCAL_HOST = '127.0.0.1';
    public const LOCAL_PORT = '3306';
    public const LOCAL_NAME = 'qeemah_wa_athar';
    public const LOCAL_USER = 'root';
    public const LOCAL_PASS = '';

    public const DRIVER_MYSQL  = 'mysql';
    public const DRIVER_SQLITE = 'sqlite';

    public static function driver(): string
    {
        return strtolower((string)(getenv('QEEMAH_DB_DRIVER') ?: 'mysql'));
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = self::driver();
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            if ($driver === self::DRIVER_SQLITE) {
                $file = getenv('QEEMAH_DB_FILE') ?: __DIR__ . '/../storage/qeemah.sqlite';
                $pdo  = new PDO('sqlite:' . $file, null, null, $options);
                $pdo->exec('PRAGMA foreign_keys = ON');
                $pdo->exec('PRAGMA journal_mode = WAL');
            } else {
                $host = (string)(getenv('QEEMAH_DB_HOST') ?: self::LOCAL_HOST);
                $port = (string)(getenv('QEEMAH_DB_PORT') ?: self::LOCAL_PORT);
                $name = (string)(getenv('QEEMAH_DB_NAME') ?: self::LOCAL_NAME);
                $user = (string)(getenv('QEEMAH_DB_USER') ?: self::LOCAL_USER);
                $pass = (string)(getenv('QEEMAH_DB_PASS') !== false ? getenv('QEEMAH_DB_PASS') : self::LOCAL_PASS);
                $dsn  = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
                try {
                    $pdo = new PDO($dsn, $user, $pass, $options);
                } catch (PDOException $e) {
                    // 1049 = unknown database: say so in Arabic instead of a blank page.
                    if (str_contains($e->getMessage(), 'Unknown database') || (int)($e->errorInfo[1] ?? 0) === 1049) {
                        error_log('[Qeemah] database ' . $name . ' is missing');
                        throw new RuntimeException(
                            'قاعدة البيانات «' . $name . '» غير موجودة بعد. استورد ملف database.sql من phpMyAdmin، '
                            . 'أو شغّل: mysql -u root < database.sql',
                            0,
                            $e
                        );
                    }
                    throw $e;
                }
                $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
        } catch (PDOException $e) {
            // Never leak credentials in production output.
            error_log('[Qeemah] DB connection failed: ' . $e->getMessage());
            throw new RuntimeException(
                'تعذّر الاتصال بقاعدة البيانات. تأكّد أن MySQL يعمل في XAMPP على المنفذ 3306، '
                . 'ثم راجع إعدادات الاتصال في config/database.php',
                0,
                $e
            );
        }

        self::$pdo = $pdo;
        return $pdo;
    }

    public static function isSqlite(): bool
    {
        return self::driver() === self::DRIVER_SQLITE;
    }

    /** Run a callback inside a transaction (commit / rollBack guaranteed). */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn($pdo);
        }
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function boot_session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name('QEEMAH_SID');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', '86400');
        session_start();

        if (empty($_SESSION['_started_at'])) {
            $_SESSION['_started_at'] = time();
            // Rotate the id on first touch of a new session.
            session_regenerate_id(true);
        }

        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
    }
}
