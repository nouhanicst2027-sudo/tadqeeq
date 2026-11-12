<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', getenv('TADQEEQ_DATA_DIR') ?: APP_ROOT . '/data');

date_default_timezone_set('Asia/Amman');
mb_internal_encoding('UTF-8');

// قراءة ذاكرة السجلات تحتاج ~200MB؛ نرفع الحد إن كان أقل من 512MB
(function () {
    $ml = trim((string)ini_get('memory_limit'));
    if ($ml === '-1') {
        return;
    }
    $n = (int)$ml;
    $u = strtoupper(substr($ml, -1));
    $mb = $u === 'G' ? $n * 1024 : ($u === 'M' ? $n : ($u === 'K' ? intdiv($n, 1024) : intdiv($n, 1048576)));
    if ($mb < 512) {
        @ini_set('memory_limit', '512M');
    }
})();

if (!is_file(APP_ROOT . '/vendor/autoload.php')) {
    http_response_code(500);
    exit('مكتبة PHPSpreadsheet غير مثبتة. نفّذ: composer install');
}
require APP_ROOT . '/vendor/autoload.php';
require __DIR__ . '/storage.php';
require __DIR__ . '/settings.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/records.php';
require __DIR__ . '/articles.php';
require __DIR__ . '/fields.php';
require __DIR__ . '/reviews.php';
require __DIR__ . '/export.php';

ensure_data_dirs();

if (PHP_SAPI !== 'cli') {
    session_name('TADQEEQ');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}
