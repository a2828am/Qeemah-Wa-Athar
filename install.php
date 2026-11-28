<?php
/**
 * install.php — browser installer (XAMPP friendly).
 *
 *   http://localhost:8080/qeemah-platform/install.php
 *
 * 1. Shows the detected environment (PHP, extensions, folder URL, MySQL target).
 * 2. Creates the database if it is missing and imports database.sql.
 * 3. Refuses to run when the platform is already installed unless the operator
 *    ticks the confirmation box, so a live site cannot be re-initialised by a
 *    casual visit.
 *
 * It never prints a password and never writes one to disk: what you type is used
 * for this request only.
 */
declare(strict_types=1);

$root = __DIR__;
require_once $root . '/includes/helpers.php';   // url(), e(), base_url() — no DB touch
require_once $root . '/tools/init_mysql.php';

use Qeemah\Config\Database;

$env = [
    'host' => (string)(getenv('QEEMAH_DB_HOST') ?: Database::LOCAL_HOST),
    'port' => (string)(getenv('QEEMAH_DB_PORT') ?: Database::LOCAL_PORT),
    'user' => (string)(getenv('QEEMAH_DB_USER') ?: Database::LOCAL_USER),
    'pass' => '',
    'name' => (string)(getenv('QEEMAH_DB_NAME') ?: Database::LOCAL_NAME),
];
$passFromEnv = getenv('QEEMAH_DB_PASS') !== false;

$checks = [];
$checks[] = ['إصدار PHP', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>=')];
foreach (['pdo', 'pdo_mysql', 'mbstring', 'json', 'openssl', 'fileinfo'] as $ext) {
    $checks[] = ['امتداد ' . $ext, extension_loaded($ext) ? 'محمّل' : 'غير محمّل', extension_loaded($ext)];
}
foreach (['storage', 'storage/uploads'] as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    $checks[] = ['مجلد ' . $dir, is_writable($path) ? 'قابل للكتابة' : 'غير قابل للكتابة', is_writable($path)];
}
$checks[] = ['رابط التثبيت', base_url() . '/install.php', true];

$dbStatus = null;
$dbError  = '';
try {
    $probe = MysqlInstaller::connect(
        sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $env['host'], $env['port']),
        $env['user'],
        $env['pass']
    );
    $hasDb = (int)$probe->query(
        'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . $probe->quote($env['name'])
    )->fetchColumn() > 0;
    $tables = 0;
    if ($hasDb) {
        $tables = (int)$probe->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . $probe->quote($env['name'])
            . ' AND TABLE_TYPE = "BASE TABLE"'
        )->fetchColumn();
    }
    $dbStatus = ['server' => true, 'has_db' => $hasDb, 'tables' => $tables];
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$installed = ($dbStatus['has_db'] ?? false) && ($dbStatus['tables'] ?? 0) >= 43;
$result    = null;
$error     = '';

/**
 * Is the database the operator is pointing at already initialised?
 * The guard must look at the submitted name, not at the default one, otherwise
 * installing into a fresh empty database is refused because another database on
 * the same server happens to be full.
 *
 * @param array{host:string,port:string,user:string,pass:string,name:string} $cfg
 */
$isInstalled = static function (array $cfg): bool {
    try {
        $pdo = MysqlInstaller::connect(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $cfg['host'], $cfg['port']),
            $cfg['user'],
            $cfg['pass']
        );
        $n = (int)$pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '
            . $pdo->quote($cfg['name']) . ' AND TABLE_TYPE = "BASE TABLE"'
        )->fetchColumn();
        return $n >= 43;
    } catch (Throwable $e) {
        return false;
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$passFromEnv && !empty($_POST['db_pass']) && is_string($_POST['db_pass'])) {
            $env['pass'] = $_POST['db_pass'];
        }
        foreach (['host', 'port', 'user', 'name'] as $k) {
            if (!empty($_POST['db_' . $k]) && is_string($_POST['db_' . $k])) {
                $env[$k] = trim($_POST['db_' . $k]);
            }
        }
        if ($isInstalled($env) && empty($_POST['confirm']) && empty($_POST['reset'])) {
            throw new RuntimeException('قاعدة البيانات «' . $env['name'] . '» مثبّتة بالفعل. ضع علامة التأكيد إن كنت تريد الاستيراد مرة أخرى (لن تُحذف البيانات القائمة).');
        }
        $result = MysqlInstaller::run($env, !empty($_POST['reset']), !empty($_POST['refresh']));
        $dbStatus = ['server' => true, 'has_db' => true, 'tables' => $result['tables']];
        $installed = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تثبيت قيمة وأثر</title>
<style>
  :root{--green:#0f5132;--green-600:#16a34a;--mint:#eef7f1;--ink:#14251c;--muted:#5b7268;--line:#dfeae3;--danger:#b91c1c;--ok:#15803d}
  *{box-sizing:border-box}
  body{margin:0;background:#f6faf7;color:var(--ink);font-family:"Segoe UI",Tahoma,Arial,sans-serif;line-height:1.7}
  .wrap{max-width:860px;margin:0 auto;padding:28px 18px 60px}
  .brand{display:flex;align-items:center;gap:12px;margin-bottom:6px}
  .brand svg{width:44px;height:44px;color:var(--green)}
  .brand b{font-size:22px}
  .lead{color:var(--muted);margin:0 0 22px}
  .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px 22px;margin-bottom:16px;box-shadow:0 6px 20px rgba(16,64,43,.05)}
  .card h2{font-size:16px;margin:0 0 14px;display:flex;align-items:center;gap:8px}
  table{width:100%;border-collapse:collapse;font-size:14px}
  td{padding:8px 4px;border-bottom:1px solid #eef3ef}
  tr:last-child td{border-bottom:0}
  td:last-child{text-align:left;font-weight:700}
  .ok{color:var(--ok)} .bad{color:var(--danger)} .warn{color:#b45309}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  label{display:block;font-size:13px;font-weight:700;margin-bottom:6px}
  input[type=text],input[type=password]{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font:inherit;background:#fbfdfb}
  input:focus{outline:none;border-color:var(--green-600);box-shadow:0 0 0 3px var(--mint)}
  .btn{display:inline-flex;align-items:center;gap:8px;border:0;border-radius:11px;padding:12px 20px;font:inherit;font-weight:800;cursor:pointer;background:var(--green);color:#fff}
  .btn[disabled]{opacity:.55;cursor:not-allowed}
  .btn.ghost{background:#fff;color:var(--green);border:1px solid var(--line)}
  .alert{border-radius:12px;padding:12px 16px;font-size:14px;margin-bottom:14px}
  .alert.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
  .alert.good{background:#f0fdf4;border:1px solid #bbf7d0;color:#14532d}
  .chk{display:flex;gap:9px;align-items:flex-start;font-size:13.5px;margin:10px 0}
  code{background:var(--mint);padding:2px 6px;border-radius:6px;font-size:13px;direction:ltr;display:inline-block}
  .steps{font-size:13.5px;color:var(--muted);margin:0;padding-inline-start:20px}
  .foot{text-align:center;color:var(--muted);font-size:12.5px;margin-top:22px}
  @media(max-width:600px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21c5-3 8-7 8-11a4 4 0 0 0-8-1 4 4 0 0 0-8 1c0 4 3 8 8 11Z"/></svg>
    <b>قيمة وأثر — التثبيت</b>
  </div>
  <p class="lead">تثبيت قاعدة البيانات وملفاتها دفعةً واحدة على XAMPP. لن تُحذف أي بيانات قائمة.</p>

  <?php if ($error !== ''): ?>
    <div class="alert err"><b>تعذّر التثبيت:</b> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($result !== null): ?>
    <div class="alert good">
      <b>تم التثبيت بنجاح.</b>
      <ul style="margin:8px 0 0;padding-inline-start:20px">
        <?php foreach ($result['log'] as $line): ?><li><?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?>
      </ul>
      <p style="margin:10px 0 0"><a class="btn" href="<?= htmlspecialchars(url('index.php'), ENT_QUOTES, 'UTF-8') ?>">افتح المنصة الآن</a></p>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>1) فحص البيئة</h2>
    <table>
      <?php foreach ($checks as [$label, $value, $good]): ?>
        <tr>
          <td><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
          <td class="<?= $good ? 'ok' : 'bad' ?>"><?= $good ? '✓' : '✕' ?> <?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
      <?php endforeach; ?>
      <tr>
        <td>خادم MySQL</td>
        <td class="<?= $dbStatus ? 'ok' : 'bad' ?>">
          <?= $dbStatus ? '✓ متصل على ' . htmlspecialchars($env['host'] . ':' . $env['port'], ENT_QUOTES, 'UTF-8')
                        : '✕ ' . htmlspecialchars($dbError !== '' ? $dbError : 'غير متاح', ENT_QUOTES, 'UTF-8') ?>
        </td>
      </tr>
      <tr>
        <td>قاعدة البيانات</td>
        <td class="<?= $installed ? 'ok' : 'warn' ?>">
          <?php if ($installed): ?>✓ «<?= htmlspecialchars($env['name'], ENT_QUOTES, 'UTF-8') ?>» جاهزة — <?= (int)$dbStatus['tables'] ?> جدولاً
          <?php elseif ($dbStatus && $dbStatus['has_db']): ?>موجودة لكن غير مكتملة — <?= (int)$dbStatus['tables'] ?> جدولاً
          <?php else: ?>غير موجودة بعد — سيتم إنشاؤها الآن<?php endif; ?>
        </td>
      </tr>
    </table>
  </div>

  <div class="card">
    <h2>2) إعدادات الاتصال</h2>
    <form method="post" action="<?= htmlspecialchars(url('install.php'), ENT_QUOTES, 'UTF-8') ?>">
      <div class="grid">
        <div><label for="db_host">الخادم</label><input type="text" id="db_host" name="db_host" value="<?= htmlspecialchars($env['host'], ENT_QUOTES, 'UTF-8') ?>"></div>
        <div><label for="db_port">المنفذ</label><input type="text" id="db_port" name="db_port" value="<?= htmlspecialchars($env['port'], ENT_QUOTES, 'UTF-8') ?>"></div>
        <div><label for="db_user">مستخدم MySQL</label><input type="text" id="db_user" name="db_user" value="<?= htmlspecialchars($env['user'], ENT_QUOTES, 'UTF-8') ?>"></div>
        <div><label for="db_pass">كلمة المرور<?= $passFromEnv ? ' (تُقرأ من البيئة)' : '' ?></label>
          <input type="password" id="db_pass" name="db_pass" autocomplete="off" <?= $passFromEnv ? 'placeholder="مضبوطة في البيئة"' : '' ?>></div>
        <div><label for="db_name">اسم قاعدة البيانات</label><input type="text" id="db_name" name="db_name" value="<?= htmlspecialchars($env['name'], ENT_QUOTES, 'UTF-8') ?>"></div>
      </div>

      <label class="chk">
        <input type="checkbox" name="confirm" value="1" <?= $installed ? '' : 'checked' ?>>
        <span>أؤكد إنشاء/استيراد قاعدة البيانات. على قاعدة قائمة لا يُحذف شيء — تُضاف الجداول الناقصة فقط.</span>
      </label>
      <label class="chk">
        <input type="checkbox" name="refresh" value="1">
        <span>إعادة بيانات العرض إلى حالتها الأصلية (يُفرَّغ ما زرعته database.sql ويُعاد — يصلح بعد التجارب، ولا يمسّ بنية الجداول).</span>
      </label>
      <label class="chk">
        <input type="checkbox" name="reset" value="1">
        <span class="bad">حذف قاعدة البيانات بالكامل أولاً (خيار خطير — يفقد كل البيانات الحقيقية).</span>
      </label>

      <p style="margin-top:14px">
        <button class="btn" type="submit">تنفيذ التثبيت</button>
        <a class="btn ghost" href="<?= htmlspecialchars(url('index.php'), ENT_QUOTES, 'UTF-8') ?>">فتح المنصة</a>
      </p>
    </form>
  </div>

  <div class="card">
    <h2>3) الخطوات المعتادة على XAMPP</h2>
    <ol class="steps">
      <li>افتح لوحة XAMPP وشغّل <b>Apache</b> ثم <b>MySQL</b>.</li>
      <li>ضع مجلد المشروع داخل <code>C:\xampp\htdocs\</code> (اسمه لا يهم — والرابط الافتراضي <code>http://localhost:8080/qeemah-platform</code>).</li>
      <li>افتح <code>install.php</code> واضغط «تنفيذ التثبيت».</li>
      <li>ادخل بحساب المسؤول: <code>admin@qeemah.test</code> وكلمة المرور <code>Password123!</code>.</li>
    </ol>
    <p class="steps" style="margin-top:10px">بديل سطر الأوامر: <code>php tools/init_mysql.php</code> أو استيراد <code>database.sql</code> من phpMyAdmin.</p>
  </div>

  <p class="foot">قيمة وأثر — تثبيت محلي. احذف هذا الملف بعد التثبيت على استضافة عامة.</p>
</div>
</body>
</html>
