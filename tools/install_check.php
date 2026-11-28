<?php
/**
 * tools/install_check.php — pre-flight check for a real deployment.
 *
 *   php tools/install_check.php
 *
 * Verifies PHP version, required extensions, writable directories, database
 * connectivity and that every expected table exists. Read-only: it never
 * writes to the database. Exits non-zero when something must be fixed.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$ok = 0;
$problems = [];

function line(string $status, string $label, string $detail = ''): void
{
    global $ok, $problems;
    $mark = $status === 'ok' ? '  OK  ' : ($status === 'warn' ? ' WARN ' : ' FAIL ');
    if ($status === 'ok') {
        $ok++;
    } elseif ($status === 'fail') {
        $problems[] = $label . ($detail !== '' ? " — {$detail}" : '');
    }
    printf("[%s] %-46s %s\n", trim($mark), $label, $detail);
}

echo "قيمة وأثر — فحص التهيئة\n", str_repeat('=', 74), "\n\n";

/* ── PHP ────────────────────────────────────────────────────────────────── */
version_compare(PHP_VERSION, '8.1.0', '>=')
    ? line('ok', 'إصدار PHP', PHP_VERSION)
    : line('fail', 'إصدار PHP', PHP_VERSION . ' — المطلوب 8.1 أو أحدث');

foreach (['pdo', 'mbstring', 'json', 'openssl', 'fileinfo'] as $ext) {
    extension_loaded($ext)
        ? line('ok', 'امتداد ' . $ext)
        : line('fail', 'امتداد ' . $ext, 'غير محمّل');
}
if (extension_loaded('pdo_mysql')) {
    line('ok', 'امتداد pdo_mysql');
} elseif (extension_loaded('pdo_sqlite')) {
    line('warn', 'امتداد pdo_mysql', 'غائب — سيعمل المشروع على SQLite فقط');
} else {
    line('fail', 'برنامج قاعدة البيانات', 'لا pdo_mysql ولا pdo_sqlite');
}
if (extension_loaded('gd')) {
    line('ok', 'امتداد gd', 'تحسين صور الرفع');
} else {
    line('warn', 'امتداد gd', 'غائب — ستُرفع الصور دون تصغير');
}

/* ── directories ────────────────────────────────────────────────────────── */
foreach (['storage', 'storage/uploads', 'storage/uploads/products', 'storage/uploads/avatars'] as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    is_dir($path) && is_writable($path)
        ? line('ok', 'قابل للكتابة: ' . $dir)
        : line('fail', 'قابل للكتابة: ' . $dir, 'chmod 775 or chown');
}

/* ── config ─────────────────────────────────────────────────────────────── */
$driver = strtolower((string)(getenv('QEEMAH_DB_DRIVER') ?: 'mysql'));
line('ok', 'مشغّل قاعدة البيانات', $driver);

require_once $root . '/config/database.php';
try {
    $pdo = Qeemah\Config\Database::pdo();
    line('ok', 'الاتصال بقاعدة البيانات', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
} catch (Throwable $e) {
    line('fail', 'الاتصال بقاعدة البيانات', $e->getMessage());
    echo "\n", count($problems), " مشكلة يجب معالجتها.\n";
    exit(1);
}

// Every table the application actually queries (see database.sql).
$expected = [
    'users', 'user_types', 'roles', 'user_roles', 'permissions', 'role_permissions',
    'categories', 'products', 'product_images', 'user_requests', 'offers', 'exchange_requests',
    'favorite_products', 'ratings', 'product_reports', 'conversations', 'messages', 'conversation_reads',
    'notifications', 'login_requests', 'verification_codes', 'user_sessions', 'login_logout_logs',
    'user_activities', 'search_history', 'associations', 'association_members', 'association_join_requests', 'donation_campaigns',
    'donation_campaign_items', 'donation_campaign_participants', 'donations', 'donation_receipts',
    'beneficiaries', 'distribution_logs', 'reports', 'complaints', 'system_settings', 'admin_audit_logs',
    // mandatory request → chat → agreement → delivery flow
    'request_status_history', 'request_agreements', 'request_deliveries', 'user_addresses', 'assistant_threads',
];
/* Ask the driver that is actually connected — sqlite_master only exists on
   SQLite, and querying it on MySQL throws instead of falling through. */
$existing = [];
$driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver === 'sqlite') {
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table'") as $row) {
        $existing[] = (string)$row['name'];
    }
} else {
    foreach ($pdo->query('SHOW TABLES') as $row) {
        $existing[] = (string)reset($row);
    }
}
$missing = array_values(array_diff($expected, $existing));
$missing
    ? line('fail', 'جداول قاعدة البيانات', count($missing) . ' مفقود: ' . implode(', ', array_slice($missing, 0, 6)))
    : line('ok', 'جداول قاعدة البيانات', count($expected) . ' جدول');

/* ── seeded data ────────────────────────────────────────────────────────── */
$users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$settings = (int)$pdo->query('SELECT COUNT(*) FROM system_settings')->fetchColumn();
$admins = 0;
try {
    $admins = (int)$pdo->query(
        "SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE r.code IN ('super_admin','admin')"
    )->fetchColumn();
} catch (Throwable $e) {
}
$users > 0 ? line('ok', 'حسابات المستخدمين', $users . ' حساب') : line('warn', 'حسابات المستخدمين', 'لا حسابات — استورد database.sql');
$settings > 0 ? line('ok', 'إعدادات النظام', $settings . ' إعداد') : line('warn', 'إعدادات النظام', 'فارغة');
$admins > 0 ? line('ok', 'حسابات إدارية', $admins . ' مسؤول') : line('fail', 'حسابات إدارية', 'لا يوجد مسؤول — أنشئ واحداً قبل النشر');

echo "\n", str_repeat('=', 74), "\n";
echo $problems ? count($problems) . " مشكلة يجب معالجتها قبل التشغيل.\n" : "التهيئة سليمة — {$ok} فحصاً ناجحاً.\n";
exit($problems ? 1 : 0);
