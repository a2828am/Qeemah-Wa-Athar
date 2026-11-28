<?php
/**
 * includes/bootstrap.php
 * -----------------------------------------------------------------------------
 * Single entry point for every page and API endpoint:
 *   require_once __DIR__ . '/includes/bootstrap.php';   (root pages)
 *   require_once __DIR__ . '/../includes/bootstrap.php'; (sub-folder pages)
 *
 * Loads configuration, helper functions and repositories, then starts the
 * hardened session so CSRF tokens and flash messages are always available.
 */
declare(strict_types=1);

if (!defined('QEEMAH_BOOTED')) {
    define('QEEMAH_BOOTED', true);
    define('QEEMAH_ROOT', dirname(__DIR__));

    require_once QEEMAH_ROOT . '/config/database.php';
    require_once QEEMAH_ROOT . '/config/ai.php';
    require_once QEEMAH_ROOT . '/includes/helpers.php';
    require_once QEEMAH_ROOT . '/includes/repository.php';
    require_once QEEMAH_ROOT . '/includes/request_flow.php';
    require_once QEEMAH_ROOT . '/includes/assistant.php';

    Qeemah\Config\Database::boot_session();

    /* A stopped MySQL or an un-imported database must never look like a broken
       site: show an Arabic setup page that points at install.php. */
    set_exception_handler(static function (Throwable $ex): void {
        $isSetup = $ex instanceof RuntimeException
            && (str_contains($ex->getMessage(), 'قاعدة البيانات')
                || str_contains($ex->getMessage(), 'الاتصال')
                || str_contains($ex->getMessage(), 'MySQL'));
        if (!$isSetup) {
            error_log('[Qeemah] ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
        }
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/html; charset=utf-8');
        }
        $base   = htmlspecialchars(url('install.php'), ENT_QUOTES, 'UTF-8');
        $home   = htmlspecialchars(url('index.php'), ENT_QUOTES, 'UTF-8');
        $detail = htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>قيمة وأثر — تهيئة مطلوبة</title><style>'
            . 'body{margin:0;background:#f6faf7;color:#14251c;font-family:"Segoe UI",Tahoma,Arial,sans-serif;line-height:1.8}'
            . '.box{max-width:640px;margin:8vh auto;background:#fff;border:1px solid #dfeae3;border-radius:18px;padding:30px;box-shadow:0 8px 30px rgba(16,64,43,.07)}'
            . 'h1{font-size:20px;margin:0 0 10px;color:#0f5132}p{color:#5b7268;font-size:14.5px}'
            . 'code{background:#eef7f1;padding:2px 7px;border-radius:6px;direction:ltr;display:inline-block;font-size:13px}'
            . 'a.btn{display:inline-block;background:#0f5132;color:#fff;text-decoration:none;padding:11px 20px;border-radius:11px;font-weight:800;margin:6px 6px 0 0}'
            . 'a.ghost{background:#fff;color:#0f5132;border:1px solid #dfeae3}'
            . '.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:12px;padding:11px 14px;font-size:13.5px;margin:14px 0}'
            . 'ul{font-size:14px;color:#5b7268;padding-inline-start:20px}'
            . '</style></head><body><div class="box">'
            . '<h1>المنصة تحتاج خطوة تهيئة واحدة</h1>'
            . '<p>لم يتمكّن النظام من الوصول إلى قاعدة البيانات. هذا يحدث عادةً لأن MySQL متوقّف، أو لأن قاعدة البيانات لم تُستورد بعد.</p>'
            . '<div class="err">' . $detail . '</div>'
            . '<ol><li>افتح لوحة <b>XAMPP</b> وشغّل <b>MySQL</b> (المنفذ 3306).</li>'
            . '<li>اضغط «تهيئة قاعدة البيانات» لإنشاء القاعدة واستيراد <code>database.sql</code>.</li>'
            . '<li>إن كنت قد استوردت القاعدة بالفعل، راجع بيانات الاتصال في <code>config/database.php</code>.</li></ol>'
            . '<p><a class="btn" href="' . $base . '">تهيئة قاعدة البيانات</a>'
            . '<a class="btn ghost" href="' . $home . '">إعادة المحاولة</a></p>'
            . '</div></body></html>';
    });
    /* url() now derives the base from DOCUMENT_ROOT, so QEEMAH_BASE_URL must not
       be defined from it (that would freeze an empty value). Leave it undefined
       unless a caller sets it explicitly. */
}
