<?php
/**
 * tools/build_sqlite.php
 * -----------------------------------------------------------------------------
 * DEVELOPER HARNESS ONLY.
 *
 * Production ships database.sql (MySQL 8 / InnoDB). Some environments have no
 * MySQL server available, so this script transpiles the very same schema and
 * seed data into a SQLite file that the application can run against when
 * QEEMAH_DB_DRIVER=sqlite. It is not used in production.
 *
 *   php tools/build_sqlite.php [--out=storage/qeemah.sqlite]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$args = getopt('', ['out::']);
$out  = $args['out'] ?? $root . '/storage/qeemah.sqlite';
$src  = $root . '/database.sql';

if (!is_file($src)) {
    fwrite(STDERR, "database.sql not found\n");
    exit(1);
}

$sql = file_get_contents($src);

/* ── 0. inline the @H password variable ─────────────────────────────────── */
$vars = [];
$sql  = preg_replace_callback(
    '/^SET\s+@(\w+)\s*=\s*\'((?:[^\']|\'\')*)\'\s*;/mi',
    static function (array $m) use (&$vars): string {
        $vars[$m[1]] = $m[2];
        return '';
    },
    $sql
) ?? $sql;
foreach ($vars as $name => $value) {
    $sql = str_replace('@' . $name, "'" . $value . "'", $sql);
}

/* ── 1. split into statements (quote/paren/comment aware) ───────────────── */
function split_statements(string $sql): array
{
    $out    = [];
    $buf    = '';
    $len    = strlen($sql);
    $inStr  = false;
    $quote  = '';
    $inLine = false;
    $inBlk  = false;

    for ($i = 0; $i < $len; $i++) {
        $ch   = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inLine) {
            if ($ch === "\n") {
                $inLine = false;
                $buf   .= $ch;
            }
            continue;
        }
        if ($inBlk) {
            if ($ch === '*' && $next === '/') {
                $inBlk = false;
                $i++;
            }
            continue;
        }
        if ($inStr) {
            $buf .= $ch;
            if ($ch === '\\') {
                $buf .= $next;
                $i++;
                continue;
            }
            if ($ch === $quote) {
                if ($next === $quote) {
                    $buf .= $next;
                    $i++;
                    continue;
                }
                $inStr = false;
            }
            continue;
        }
        if ($ch === '-' && $next === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]) || $sql[$i + 2] === '-')) {
            $inLine = true;
            $i++;
            continue;
        }
        if ($ch === '#') {
            $inLine = true;
            continue;
        }
        if ($ch === '/' && $next === '*') {
            $inBlk = true;
            $i++;
            continue;
        }
        if ($ch === "'" || $ch === '"') {
            $inStr = true;
            $quote = $ch;
            $buf  .= $ch;
            continue;
        }
        if ($ch === ';') {
            $t = trim($buf);
            if ($t !== '') {
                $out[] = $t;
            }
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    $t = trim($buf);
    if ($t !== '') {
        $out[] = $t;
    }
    return $out;
}

function split_top_level(string $body): array
{
    $parts = [];
    $buf   = '';
    $depth = 0;
    $len   = strlen($body);
    $inStr = false;
    $quote = '';

    for ($i = 0; $i < $len; $i++) {
        $ch = $body[$i];
        if ($inStr) {
            $buf .= $ch;
            if ($ch === '\\') {
                $buf .= $body[$i + 1] ?? '';
                $i++;
                continue;
            }
            if ($ch === $quote) {
                if (($body[$i + 1] ?? '') === $quote) {
                    $buf .= $body[$i + 1];
                    $i++;
                    continue;
                }
                $inStr = false;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"') {
            $inStr = true;
            $quote = $ch;
            $buf  .= $ch;
            continue;
        }
        if ($ch === '(') {
            $depth++;
            $buf .= $ch;
            continue;
        }
        if ($ch === ')') {
            $depth--;
            $buf .= $ch;
            continue;
        }
        if ($ch === ',' && $depth === 0) {
            $parts[] = trim($buf);
            $buf     = '';
            continue;
        }
        $buf .= $ch;
    }
    if (trim($buf) !== '') {
        $parts[] = trim($buf);
    }
    return $parts;
}

function convert_column(string $line): string
{
    $line = preg_replace('/\bBIGINT\s+UNSIGNED\b/i', 'INTEGER', $line) ?? $line;
    $line = preg_replace('/\b(?:INT|INTEGER|SMALLINT|TINYINT|MEDIUMINT)\s+UNSIGNED\b/i', 'INTEGER', $line) ?? $line;
    $line = preg_replace('/\bBIGINT\b/i', 'INTEGER', $line) ?? $line;
    $line = preg_replace('/\bTINYINT\s*\(\s*1\s*\)/i', 'INTEGER', $line) ?? $line;
    $line = preg_replace('/\bTINYINT\b/i', 'INTEGER', $line) ?? $line;
    $line = preg_replace('/\bSMALLINT\b/i', 'INTEGER', $line) ?? $line;
    $line = preg_replace('/\bVARCHAR\s*\(\s*\d+\s*\)/i', 'TEXT', $line) ?? $line;
    $line = preg_replace('/\bCHAR\s*\(\s*\d+\s*\)/i', 'TEXT', $line) ?? $line;
    $line = preg_replace('/\bVARBINARY\s*\(\s*\d+\s*\)/i', 'BLOB', $line) ?? $line;
    $line = preg_replace('/\bENUM\s*\((?:[^)]*)\)/i', 'TEXT', $line) ?? $line;
    $line = preg_replace('/\bDECIMAL\s*\(\s*\d+\s*,\s*\d+\s*\)/i', 'REAL', $line) ?? $line;
    $line = preg_replace('/\b(?:DATETIME|TIMESTAMP)\b/i', 'TEXT', $line) ?? $line;
    $line = preg_replace('/\bDOUBLE\b/i', 'REAL', $line) ?? $line;
    $line = preg_replace('/\bLONGTEXT\b/i', 'TEXT', $line) ?? $line;
    $line = preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP/i', '', $line) ?? $line;
    $line = preg_replace('/\s+CHARACTER\s+SET\s+\w+/i', '', $line) ?? $line;
    $line = preg_replace('/\s+COLLATE\s+\w+/i', '', $line) ?? $line;
    $line = preg_replace('/\s+AUTO_INCREMENT\b/i', '', $line) ?? $line;

    if (preg_match('/^\s*(\w+)\s+INTEGER\b/i', $line, $m) && stripos($line, 'PRIMARY KEY') === false) {
        if (preg_match('/^\s*id\s+/i', $line)) {
            $line = preg_replace('/^\s*id\s+INTEGER/i', 'id INTEGER PRIMARY KEY AUTOINCREMENT', $line) ?? $line;
        }
    }
    $line = preg_replace('/\bUNSIGNED\b/i', '', $line) ?? $line;
    return preg_replace('/\s+/', ' ', $line) ?? $line;
}

function translate_functions(string $sql): string
{
    $sql = preg_replace_callback(
        "/\\bSHA2\\s*\\(\\s*'([^']*)'\\s*,\\s*256\\s*\\)/i",
        static fn (array $m): string => "'" . hash('sha256', $m[1]) . "'",
        $sql
    ) ?? $sql;
    $sql = preg_replace('/\bINET6_ATON\s*\(/i', '(', $sql) ?? $sql;
    $sql = preg_replace_callback(
        '/\bDATE_(ADD|SUB)\s*\(\s*(?:CURRENT_TIMESTAMP|NOW\(\)|CURDATE\(\)|date\(\'now\'\))\s*,\s*INTERVAL\s+(\d+)\s+(SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)\s*\)/i',
        static fn (array $m): string => "datetime('now','" . ($m[1] === 'ADD' ? '+' : '-') . $m[2] . ' ' . strtolower($m[3]) . "')",
        $sql
    ) ?? $sql;
    $sql = preg_replace_callback(
        '/\b(?:NOW\(\)|CURRENT_TIMESTAMP)\s*([+-])\s*INTERVAL\s+(\d+)\s+(SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)\b/i',
        static fn (array $m): string => "datetime('now','" . $m[1] . ' ' . $m[2] . ' ' . strtolower($m[3]) . "')",
        $sql
    ) ?? $sql;
    $sql = preg_replace_callback(
        '/\bDATE_ADD\s*\(\s*CURDATE\(\)\s*,\s*INTERVAL\s+(\d+)\s+DAY\s*\)/i',
        static fn (array $m): string => "date('now','+" . $m[1] . " day')",
        $sql
    ) ?? $sql;
    $sql = preg_replace_callback(
        '/\bDATE_SUB\s*\(\s*CURDATE\(\)\s*,\s*INTERVAL\s+(\d+)\s+DAY\s*\)/i',
        static fn (array $m): string => "date('now','-" . $m[1] . " day')",
        $sql
    ) ?? $sql;
    $sql = preg_replace('/\bCURDATE\(\)/i', "date('now')", $sql) ?? $sql;
    $sql = preg_replace('/\bNOW\(\)/i', 'CURRENT_TIMESTAMP', $sql) ?? $sql;
    // SQLite compares timestamps lexically; normalise to 'Y-m-d H:i:s'.
    $sql = preg_replace('/\bdate\(\s*CURRENT_TIMESTAMP\s*\)/i', "date('now','localtime')", $sql) ?? $sql;
    return $sql;
}

$statements = split_statements($sql);
$outSql     = [];
$tableCount = 0;

foreach ($statements as $st) {
    $upper = strtoupper(ltrim($st));

    if (str_starts_with($upper, 'SET ') || str_starts_with($upper, 'USE ') || str_starts_with($upper, 'CREATE DATABASE')) {
        continue;
    }
    if (str_starts_with($upper, 'CREATE OR REPLACE VIEW') || str_starts_with($upper, 'CREATE VIEW')) {
        continue; // the app never selects from the view
    }
    if (str_starts_with($upper, 'DROP TABLE') || str_starts_with($upper, 'LOCK TABLES') || str_starts_with($upper, 'UNLOCK TABLES')) {
        continue;
    }

    if (preg_match('/^CREATE TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.*)\)[^)]*$/is', $st, $m)) {
        $table = $m[1];
        $body  = $m[2];
        $tableCount++;

        $cols   = [];
        $index  = [];
        foreach (split_top_level($body) as $part) {
            $p = trim($part);
            $pu = strtoupper($p);

            if (preg_match('/^PRIMARY\s+KEY/i', $pu)) {
                continue; // the id column carries PK AUTOINCREMENT
            }
            if (preg_match('/^UNIQUE\s+KEY\s+`?(\w+)`?\s*\((.*)\)$/is', $p, $mm)) {
                $index[] = 'CREATE UNIQUE INDEX IF NOT EXISTS idx_' . $table . '_' . $mm[1]
                    . ' ON ' . $table . ' (' . preg_replace('/\(\s*\d+\s*\)/', '', $mm[2]) . ')';
                continue;
            }
            if (preg_match('/^(?:KEY|INDEX)\s+`?(\w+)`?\s*\((.*)\)$/is', $p, $mm)) {
                $index[] = 'CREATE INDEX IF NOT EXISTS idx_' . $table . '_' . $mm[1]
                    . ' ON ' . $table . ' (' . preg_replace('/\(\s*\d+\s*\)/', '', $mm[2]) . ')';
                continue;
            }
            if (preg_match('/^FULLTEXT/i', $pu)) {
                continue;
            }
            if (preg_match('/^CONSTRAINT\s+\w+\s+(.*)$/is', $p, $mm)) {
                $inner = $mm[1];
                if (preg_match('/^CHECK/i', $inner)) {
                    $cols[] = $inner; // keep CHECK constraints
                }
                continue; // foreign keys are omitted: ORDER of seeds relies on FK checks off
            }
            if (preg_match('/^(CHECK|FOREIGN)/i', $pu)) {
                if (preg_match('/^CHECK/i', $pu)) {
                    $cols[] = $p;
                }
                continue;
            }
            $cols[] = convert_column($p);
        }

        $outSql[] = 'CREATE TABLE IF NOT EXISTS ' . $table . " (\n  " . implode(",\n  ", $cols) . "\n)";
        foreach ($index as $ix) {
            $outSql[] = $ix;
        }
        continue;
    }

    if (str_starts_with($upper, 'INSERT ')) {
        $st = translate_functions($st);
        // MySQL's "ON DUPLICATE KEY UPDATE id=id" keeps imports re-runnable on a
        // live database; SQLite spells the same thing INSERT OR IGNORE.
        $st = preg_replace('/\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+\w+\s*=\s*\w+\s*;?\s*$/i', ';', rtrim($st)) ?? $st;
        $st = preg_replace('/^INSERT\s+INTO\b/i', 'INSERT OR IGNORE INTO', ltrim($st)) ?? $st;
        $outSql[] = $st;
        continue;
    }

    if (str_starts_with($upper, 'ALTER TABLE') || str_starts_with($upper, 'UPDATE ') || str_starts_with($upper, 'DELETE ')) {
        $st = translate_functions($st);
        $outSql[] = $st;
        continue;
    }

    // Anything else is reported so nothing is silently lost.
    fwrite(STDERR, "SKIPPED: " . substr($st, 0, 90) . "\n");
}

// Fallback picture for records without a photo (generated once, no emoji).
$placeholder = $root . '/assets/img/placeholder.png';
if (!is_file($placeholder)) {
    @mkdir(dirname($placeholder), 0775, true);
    if (function_exists('imagecreatetruecolor')) {
        $im = imagecreatetruecolor(800, 600);
        imagefilledrectangle($im, 0, 0, 800, 600, imagecolorallocate($im, 232, 245, 236));
        $c = imagecolorallocate($im, 21, 128, 61);
        imagesetthickness($im, 14);
        imagerectangle($im, 300, 210, 500, 390, $c);
        imageline($im, 400, 210, 400, 390, $c);
        imageline($im, 300, 270, 500, 270, $c);
        imagepng($im, $placeholder);
    }
}

if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0775, true);
}
if (is_file($out)) {
    unlink($out);
}

$pdo = new PDO('sqlite:' . $out, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = OFF');
$pdo->exec('PRAGMA journal_mode = MEMORY');

$pdo->beginTransaction();
foreach ($outSql as $s) {
    try {
        $pdo->exec($s);
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "FAILED: " . substr($s, 0, 300) . "\n  → " . $e->getMessage() . "\n");
        exit(1);
    }
}
$pdo->commit();


/* ── Demo-friendly timeline ────────────────────────────────────────────────
 * The seed inserts everything with NOW(); spread the read-only volume across
 * the last six months so dashboards, charts and "time ago" labels read as a
 * living platform instead of a single burst on one day.
 */
$months = (int)($argv[1] ?? 6);
if ($months > 0) {
    $plan = [
        'products'      => [['months_ago' => 5, 'days' => 21, 'share' => 0.10], ['months_ago' => 4, 'days' => 18, 'share' => 0.12], ['months_ago' => 3, 'days' => 20, 'share' => 0.16], ['months_ago' => 2, 'days' => 22, 'share' => 0.20], ['months_ago' => 1, 'days' => 24, 'share' => 0.22], ['months_ago' => 0, 'days' => 9, 'share' => 0.20]],
        'user_requests' => [['months_ago' => 5, 'days' => 20, 'share' => 0.10], ['months_ago' => 4, 'days' => 17, 'share' => 0.12], ['months_ago' => 3, 'days' => 19, 'share' => 0.16], ['months_ago' => 2, 'days' => 21, 'share' => 0.20], ['months_ago' => 1, 'days' => 23, 'share' => 0.22], ['months_ago' => 0, 'days' => 8, 'share' => 0.20]],
        'users'         => [['months_ago' => 5, 'days' => 12, 'share' => 0.25], ['months_ago' => 4, 'days' => 10, 'share' => 0.15], ['months_ago' => 3, 'days' => 14, 'share' => 0.20], ['months_ago' => 2, 'days' => 9, 'share' => 0.15], ['months_ago' => 1, 'days' => 11, 'share' => 0.15], ['months_ago' => 0, 'days' => 5, 'share' => 0.10]],
        'donations'     => [['months_ago' => 4, 'days' => 16, 'share' => 0.25], ['months_ago' => 3, 'days' => 12, 'share' => 0.25], ['months_ago' => 2, 'days' => 18, 'share' => 0.20], ['months_ago' => 1, 'days' => 14, 'share' => 0.20], ['months_ago' => 0, 'days' => 6, 'share' => 0.10]],
    ];
    $pdo->beginTransaction();
    foreach ($plan as $table => $buckets) {
        $ids = $pdo->query('SELECT id FROM "' . $table . '" ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $n   = count($ids);
        if ($n === 0) {
            continue;
        }
        $cursor = 0;
        foreach ($buckets as $bucket) {
            $take = (int)round($n * (float)$bucket['share']);
            for ($k = 0; $k < $take && $cursor < $n; $k++, $cursor++) {
                // Stay strictly inside the target calendar month.
                $base   = strtotime(date('Y-m-01', strtotime('-' . (int)$bucket['months_ago'] . ' months')));
                $offset = ((int)$bucket['days'] + ($k % 9)) * 86400 + (2 + ($k % 11)) * 3600;
                $stamp  = date('Y-m-d H:i:s', $base + $offset);
                $pdo->prepare('UPDATE "' . $table . '" SET created_at=? WHERE id=?')->execute([$stamp, $ids[$cursor]]);
            }
        }
    }
    $pdo->commit();
}

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
echo "SQLite build OK → {$out}\n";
echo "Tables: " . count($tables) . "\n";
foreach ($tables as $t) {
    $n = (int)$pdo->query('SELECT COUNT(*) FROM "' . $t . '"')->fetchColumn();
    printf("  %-32s %5d rows\n", $t, $n);
}
