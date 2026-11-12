<?php
// استيراد ملفات المواد من سطر الأوامر (مناسب للملفات الكبيرة):
//   php tools/import-articles.php diwan  /path/reg_articles.csv   (أو .zip)
//   php tools/import-articles.php qistas /path/Bylaws_16.json     (أو .zip)
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
[$_, $source, $path] = $argv + [null, '', ''];
if (!isset(SOURCES[$source]) || !is_file($path)) { fwrite(STDERR, "الاستخدام: php tools/import-articles.php diwan|qistas <ملف>\n"); exit(1); }
try {
    $info = import_articles($source, $path, basename($path), fn($m) => print($m . "\n"));
    echo "تم: {$info['legislations']} تشريع، {$info['articles']} مادة. تنبيهات: {$info['warnings_total']}\n";
} catch (Throwable $e) { fwrite(STDERR, 'خطأ: ' . error_message($e) . "\n"); exit(1); }
