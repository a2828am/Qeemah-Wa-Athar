<?php
/**
 * Maknoon | قيمة وأثر
 * database/seeders/run.php
 *
 * نقطة تشغيل البيانات المرجعية (Reference Data) — PHP خالص (MySQLi)، بدون Laravel.
 *
 * الاستخدام:
 *   php database/seeders/run.php
 *
 * أو باستخدام ملف الاتصال الموحد للمشروع (database/config/connection.php):
 *   php database/seeders/run.php --use-connection-file
 *
 * خصائص التنفيذ:
 *   - idempotent: إعادة التشغيل لا تُنشئ تكرارًا (INSERT ... ON DUPLICATE KEY UPDATE).
 *   - ترتيب الاعتماديات: user_types → roles → permissions → role_permissions
 *                        → categories → users (مدير المنصة) → system_settings.
 *   - كل سيدر داخل معاملة (InnoDB) مع تراجع كامل عند أي خطأ.
 *   - لا تُنشأ ولا تُعدَّل أي جداول أو أعمدة أو إعدادات.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("هذا الملف يعمل من سطر الأوامر فقط.\n");
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/UserTypesSeeder.php';
require_once __DIR__ . '/RolesSeeder.php';
require_once __DIR__ . '/PermissionsSeeder.php';
require_once __DIR__ . '/RolePermissionsSeeder.php';
require_once __DIR__ . '/CategoriesSeeder.php';
require_once __DIR__ . '/AdminUserSeeder.php';
require_once __DIR__ . '/SystemSettingsSeeder.php';

/**
 * إنشاء الاتصال بقاعدة البيانات.
 *
 * الافتراضيات مطابقة لـ database/config/README.md:
 * 127.0.0.1 | 3306 | social_platform_database | root | (فارغة)
 * ويمكن تجاوزها بمتغيرات البيئة DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD
 * أو باستخدام ملف الاتصال الموحد عند تمرير --use-connection-file.
 */
function seed_connect(array $argv): mysqli
{
    $host     = getenv('DB_HOST') ?: '127.0.0.1';
    $port     = (int) (getenv('DB_PORT') ?: '3306');
    $database = getenv('DB_DATABASE') ?: 'social_platform_database';
    $username = getenv('DB_USERNAME') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';

    if (in_array('--use-connection-file', $argv, true)) {
        $connectionFile = dirname(__DIR__) . '/config/connection.php'; // database/config/connection.php
        if (!is_file($connectionFile)) {
            throw new RuntimeException("ملف الاتصال غير موجود: {$connectionFile}");
        }

        $connection = require $connectionFile;
        if (!$connection instanceof mysqli) {
            throw new RuntimeException('ملف الاتصال لا يعيد كائن mysqli كما هو معتمد في database/config/README.md.');
        }

        seed_line(seed_color('• الاتصال: من database/config/connection.php', 'gray'));

        return $connection;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($host, $username, $password, $database, $port);
    $db->set_charset('utf8mb4');

    seed_line(seed_color("• الاتصال: {$username}@{$host}:{$port}/{$database} (utf8mb4)", 'gray'));

    return $db;
}

seed_line('');
seed_line(seed_color(seed_color('Maknoon | قيمة وأثر — Seeders', 'bold'), 'cyan'));
seed_line(seed_color('البيانات المرجعية وفق database/seeders/README.md', 'gray'));
seed_line(str_repeat('─', 64));

try {
    $db = seed_connect($argv);
} catch (Throwable $e) {
    seed_line(seed_color('✘ فشل الاتصال بقاعدة البيانات: ' . $e->getMessage(), 'red'));
    exit(1);
}

/** ترتيب الاعتماديات */
$seeders = [
    'user_types'        => 'UserTypesSeeder',
    'roles'             => 'RolesSeeder',
    'permissions'       => 'PermissionsSeeder',
    'role_permissions'  => 'RolePermissionsSeeder',
    'categories'        => 'CategoriesSeeder',
    'admin_user'        => 'AdminUserSeeder',
    'system_settings'   => 'SystemSettingsSeeder',
];

$totals = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];
$failed = false;

foreach ($seeders as $key => $label) {
    $function = 'seed_' . $key;

    if (!function_exists($function)) {
        seed_line(seed_color("✘ الدالة {$function} غير موجودة.", 'red'));
        $failed = true;
        break;
    }

    try {
        [$inserted, $updated, $unchanged] = seed_transaction($db, $function);

        seed_report($function, $inserted, $updated, $unchanged);

        $totals['inserted']  += $inserted;
        $totals['updated']   += $updated;
        $totals['unchanged'] += $unchanged;
    } catch (Throwable $e) {
        seed_line(seed_color("✘ فشل {$function}: " . $e->getMessage(), 'red'));
        $failed = true;
        break;
    }
}

seed_line(str_repeat('─', 64));

if ($failed) {
    seed_line(seed_color('✘ توقف التنفيذ عند أول خطأ — تم التراجع عن آخر معاملة.', 'red'));
    $db->close();
    exit(1);
}

seed_line(sprintf(
    '%s الإجمالي — مُضاف: %d، مُحدَّث: %d، بدون تغيير: %d',
    seed_color('✔', 'green'),
    $totals['inserted'],
    $totals['updated'],
    $totals['unchanged']
));

// تحقق سريع من الأعداد المرجعية المذكورة في README
$checks = [
    'user_types'     => ['SELECT COUNT(*) c FROM user_types', 3],
    'roles'          => ['SELECT COUNT(*) c FROM roles', 5],
    'permissions'    => ['SELECT COUNT(*) c FROM permissions', 42],
    'categories'     => ['SELECT COUNT(*) c FROM categories WHERE parent_category_id IS NULL', 30],
];

foreach ($checks as $label => [$sql, $expected]) {
    $count = (int) $db->query($sql)->fetch_assoc()['c'];
    $ok    = $count === $expected;
    seed_line(sprintf(
        '  %s %s: %d (المتوقع %d)',
        $ok ? seed_color('✔', 'green') : seed_color('!', 'yellow'),
        $label,
        $count,
        $expected
    ));
}

seed_line('');
$db->close();
exit(0);
