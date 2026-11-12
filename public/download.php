<?php
/** تنزيل الملفات (للمسؤول فقط). */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$user = require_admin();
$type = (string)($_POST['type'] ?? $_GET['type'] ?? '');
try {
    switch ($type) {
        case 'export':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new ValidationException(['طلب غير صالح.']);
            verify_csrf();
            session_write_close();
            $path = export_updated_workbook();
            app_log('info', 'export', ['user' => $user['id'], 'file' => basename($path)]);
            send_file_download($path, basename($path));
        case 'original':
            $meta = main_meta();
            send_file_download(main_workbook_path(), (string)($meta['original_name'] ?? 'main.xlsx'));
        case 'reviews':
            send_file_download(reviews_xlsx_path(), 'نتائج_التدقيق_' . date('Ymd_His') . '.xlsx');
        case 'backup':
            $name = basename((string)($_GET['name'] ?? ''));
            if (!preg_match('/^reviews_[0-9_a-f]+\.xlsx$/', $name)) throw new ValidationException(['اسم غير صالح.']);
            send_file_download(data_path('backups/reviews/' . $name), $name);
        case 'exportfile':
            $name = basename((string)($_GET['name'] ?? ''));
            if (!str_ends_with($name, '.xlsx') || !is_file(data_path('exports/' . $name))) throw new ValidationException(['الملف غير موجود.']);
            send_file_download(data_path('exports/' . $name), $name);
        default:
            throw new ValidationException(['نوع تنزيل غير معروف.']);
    }
} catch (Throwable $e) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    flash('error', error_message($e));
    redirect('admin.php?tab=export');
}
