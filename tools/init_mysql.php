<?php
/**
 * tools/init_mysql.php — create (or refresh) the MySQL database from database.sql.
 *
 * The browser equivalent is install.php, which calls this same code path; the
 * CLI form is handy on a server:
 *
 *   php tools/init_mysql.php            # create DB if missing + import schema
 *   php tools/init_mysql.php --reset    # DROP the database first (destructive!)
 *   php tools/init_mysql.php --refresh   # restore the demo rows, keep the schema
 *
 * Safe to run repeatedly: database.sql creates tables with IF NOT EXISTS and
 * every seed INSERT carries ON DUPLICATE KEY UPDATE, so an existing database
 * keeps its live rows and only receives what is missing.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

final class MysqlInstaller
{
    /**
     * @param array{host:string,port:string,user:string,pass:string,name:string} $cfg
     * @param bool $reset     DROP the database first (destructive).
     * @param bool $refresh   Restore the demo/seed rows to their pristine values
     *                        without dropping anything else: every seeded table is
     *                        emptied and re-filled from database.sql. Used when the
     *                        demo data was changed by test runs.
     * @return array{created_db:bool,tables:int,seeded:bool,refreshed:int,log:string[]}
     */
    public static function run(array $cfg, bool $reset = false, bool $refresh = false): array
    {
        $log = [];
        $base = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $cfg['host'], $cfg['port']);
        $pdo  = self::connect($base, $cfg['user'], $cfg['pass']);

        if (preg_match('/^[A-Za-z0-9_]+$/', $cfg['name']) !== 1) {
            throw new RuntimeException('اسم قاعدة البيانات يجب أن يحتوي حروفاً إنجليزية وأرقاماً وشرطة سفلية فقط.');
        }
        if ($reset) {
            $pdo->exec('DROP DATABASE IF EXISTS `' . $cfg['name'] . '`');
            $log[] = 'حُذفت قاعدة البيانات القديمة بطلب منك (--reset).';
        }
        $exists = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $pdo->quote($cfg['name'])
        )->fetchColumn();
        if ($exists === 0) {
            $pdo->exec('CREATE DATABASE `' . $cfg['name'] . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $log[] = 'أُنشئت قاعدة البيانات «' . $cfg['name'] . '».';
        }

        $pdo->exec('USE `' . $cfg['name'] . '`');
        $sqlFile = dirname(__DIR__) . '/database.sql';
        if (!is_file($sqlFile)) {
            throw new RuntimeException('ملف database.sql غير موجود.');
        }
        $before = self::tableCount($pdo);

        $sql = (string)file_get_contents($sqlFile);
        // The file creates/selects its own database; keep the target we were given.
        $sql = preg_replace('/^\s*CREATE DATABASE[^;]+;/mi', '', $sql) ?? $sql;
        $sql = preg_replace('/^\s*USE\s+`?[\w]+`?\s*;/mi', '', $sql) ?? $sql;

        $pdo->exec('SET NAMES utf8mb4');
        $pdo->exec("SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");

        $statements = self::statements($sql);

        // A seeded INSERT only inserts "if missing" (ON DUPLICATE KEY UPDATE <col>=<col>),
        // so a demo row that a test run mutated keeps its mutated values forever.
        // --refresh empties exactly the tables the file seeds, then re-inserts them.
        $refreshed = 0;
        if ($refresh) {
            $tables = self::seededTables($statements);
            if ($tables !== []) {
                // Children first: reverse the order the file seeds them in.
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                foreach (array_reverse($tables) as $t) {
                    $pdo->exec('DELETE FROM `' . $t . '`');
                    // Seed rows carry explicit ids; reset the counter so a later
                    // insert cannot collide with the demo data.
                    $pdo->exec('ALTER TABLE `' . $t . '` AUTO_INCREMENT = 1');
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                $refreshed = count($tables);
                $log[] = sprintf('أُعيدت بيانات العرض: فُرِّغت %d جدولاً وسُتعاد من database.sql.', $refreshed);
            }
        }

        $done = 0;
        $skipped = 0;
        foreach ($statements as $stmt) {
            $u = strtoupper(ltrim($stmt));
            if ($u === '' || str_starts_with($u, '--')) {
                continue;
            }
            try {
                $pdo->exec($stmt);
                $done++;
            } catch (PDOException $e) {
                // Re-running the installer must never break a live database.
                // "already exists" (1050) on a table/index and duplicate keys on
                // seed rows are expected on the second run: skip and keep going.
                $code = (int)($e->errorInfo[1] ?? 0);
                $msg  = $e->getMessage();
                $benign = in_array($code, [1050, 1061, 1060, 1062, 1826], true)
                    || stripos($msg, 'already exists') !== false
                    || stripos($msg, 'Duplicate entry') !== false;
                if (!$benign) {
                    throw new RuntimeException(
                        sprintf('فشل تنفيذ عبارة SQL (%d): %s', $code, self::firstLine($stmt)),
                        0,
                        $e
                    );
                }
                $skipped++;
            }
        }
        $after = self::tableCount($pdo);
        $log[] = sprintf('نُفِّذت %d عبارة SQL؛ الجداول الآن %d.', $done, $after);
        if ($skipped > 0) {
            $log[] = sprintf('%d عبارة كانت موجودة مسبقاً فلم تُعَد (هذا طبيعي عند إعادة التثبيت).', $skipped);
        }

        return [
            'created_db' => $exists === 0,
            'tables'     => $after,
            'seeded'     => $after > $before || $after > 0,
            'refreshed'  => $refreshed,
            'log'        => $log,
        ];
    }

    public static function connect(string $dsn, string $user, string $pass): PDO
    {
        try {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, '1045') || stripos($msg, 'Access denied') !== false) {
                throw new RuntimeException('اسم مستخدم MySQL أو كلمة المرور غير صحيحة. في XAMPP الافتراضي هو root بدون كلمة مرور.');
            }
            if (str_contains($msg, '2002') || stripos($msg, 'No such file') !== false || stripos($msg, 'refused') !== false) {
                throw new RuntimeException('تعذّر الوصول إلى MySQL على المنفذ ' . (getenv('QEEMAH_DB_PORT') ?: '3306') . '. افتح لوحة XAMPP واضغط Start أمام MySQL.');
            }
            throw new RuntimeException('تعذّر الاتصال بخادم MySQL: ' . $msg);
        }
    }

    /**
     * Tables that database.sql seeds with data (the ones --refresh may empty).
     * Only tables the file fills are touched, so operator data elsewhere survives.
     *
     * @param string[] $statements
     * @return string[]
     */
    private static function seededTables(array $statements): array
    {
        $tables = [];
        foreach ($statements as $stmt) {
            if (preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?/i', ltrim($stmt), $m) === 1) {
                $tables[strtolower($m[1])] = true;
            }
        }
        // Delete children before parents so foreign keys never block the refresh.
        return array_keys($tables);
    }

    /** First line of a statement, for readable error messages. */
    private static function firstLine(string $stmt): string
    {
        $line = trim(strtok($stmt, "\n") ?: $stmt);
        return mb_strlen($line) > 120 ? mb_substr($line, 0, 120) . '…' : $line;
    }

    private static function tableCount(PDO $pdo): int
    {
        return (int)$pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE"'
        )->fetchColumn();
    }

    /** Split on semicolons outside quotes and comments (the file has Arabic text and ';' inside strings). */
    private static function statements(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $inS = $inD = $inBt = false;
        $inLine = $inBlock = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($inLine) { if ($c === "\n") { $inLine = false; $buf .= $c; } continue; }
            if ($inBlock) { if ($c === '*' && $next === '/') { $inBlock = false; $i++; } continue; }
            if (!$inS && !$inD && !$inBt) {
                if ($c === '-' && $next === '-') { $inLine = true; $i++; continue; }
                if ($c === '#') { $inLine = true; continue; }
                if ($c === '/' && $next === '*') { $inBlock = true; $i++; continue; }
            }
            if ($c === "'" && !$inD && !$inBt) {
                $inS = !$inS;
            } elseif ($c === '"' && !$inS && !$inBt) {
                $inD = !$inD;
            } elseif ($c === '`' && !$inS && !$inD) {
                $inBt = !$inBt;
            } elseif ($c === '\\' && ($inS || $inD)) {
                $buf .= $c . $next;
                $i++;
                continue;
            } elseif ($c === ';' && !$inS && !$inD && !$inBt) {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}

/* ── CLI entry point ─────────────────────────────────────────────────────── */
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $reset   = in_array('--reset', $argv, true);
    $refresh = in_array('--refresh', $argv, true);
    $cfg = [
        'host' => (string)(getenv('QEEMAH_DB_HOST') ?: \Qeemah\Config\Database::LOCAL_HOST),
        'port' => (string)(getenv('QEEMAH_DB_PORT') ?: \Qeemah\Config\Database::LOCAL_PORT),
        'user' => (string)(getenv('QEEMAH_DB_USER') ?: \Qeemah\Config\Database::LOCAL_USER),
        'pass' => (string)(getenv('QEEMAH_DB_PASS') !== false ? getenv('QEEMAH_DB_PASS') : \Qeemah\Config\Database::LOCAL_PASS),
        'name' => (string)(getenv('QEEMAH_DB_NAME') ?: \Qeemah\Config\Database::LOCAL_NAME),
    ];
    echo "قيمة وأثر — تهيئة MySQL\n", str_repeat('=', 60), "\n";
    printf("الخادم: %s:%s   المستخدم: %s   القاعدة: %s\n\n", $cfg['host'], $cfg['port'], $cfg['user'], $cfg['name']);
    try {
        $res = MysqlInstaller::run($cfg, $reset, $refresh);
        foreach ($res['log'] as $l) {
            echo " • {$l}\n";
        }
        echo "\nتمت التهيئة بنجاح — " . $res['tables'] . " جدولاً.\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "\nتعذّرت التهيئة: " . $e->getMessage() . "\n");
        exit(1);
    }
}
