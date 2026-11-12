<?php
// استيراد ملف المطابقة الأساسي من سطر الأوامر:  php tools/import-workbook.php /path/Bylaws_Diwan_Qistas_Matching.xlsx
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
$path = $argv[1] ?? '';
if (!is_file($path)) { fwrite(STDERR, "الاستخدام: php tools/import-workbook.php <ملف.xlsx>\n"); exit(1); }
try {
    $meta = import_main_workbook($path, basename($path));
    echo "تم الاستيراد: {$meta['records']} سجل\n";
    foreach ($meta['warnings'] as $w) echo "تنبيه: $w\n";
} catch (Throwable $e) { fwrite(STDERR, 'خطأ: ' . error_message($e) . "\n"); exit(1); }
