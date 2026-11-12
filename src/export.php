<?php
/**
 * تصدير ملف Excel المحدَّث: نسخة من ملف المطابقة الأصلي بكل أوراقه وقيمه وصيغه كما هي،
 * مضافاً إليها ورقة «نتائج التدقيق» بصيغة مقروءة. الملف الأصلي على الخادم لا يتغير.
 */
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

function export_rows(): array
{
    $records = records_all();
    $rows = [];
    foreach (reviews_all() as $rid => $rev) {
        $rec = $records[$rid] ?? null;
        $cat = $rev['category'] ?: ($rec['category'] ?? '');
        $diffs = implode("\n", array_map(fn($d) => '[' . (DIFF_TYPES[$d['type']] ?? $d['type']) . '] الديوان: ' . ($d['diwan_article'] ?: '—') . ' / قسطاس: ' . ($d['qistas_article'] ?: '—') . ($d['note'] !== '' ? ' — ' . $d['note'] : ''), $rev['content_differences'] ?? []));
        $rows[] = [
            'معرّف سجل التشريع' => $rid,
            'فئة السجل' => CATEGORIES[$cat] ?? $cat,
            'اسم التشريع' => $rec['name'] ?? $rev['legislation'],
            'رقم التشريع' => (string)($rec['number'] ?? $rev['number']),
            'سنة التشريع' => (string)($rec['year'] ?? $rev['year']),
            'اسم المتطوع' => $rev['volunteer_name'],
            'تاريخ البدء' => $rev['started_at'],
            'تاريخ الانتهاء' => review_status($rev) === 'done' ? $rev['submitted_at'] : '',
            'حالة المراجعة' => STATUSES[review_status($rev)] ?? $rev['status'],
            'القرار النهائي' => $rev['final_decision'] ? decision_label($cat, $rev['final_decision']) : '',
            'ملاحظات المراجع' => $rev['reviewer_notes'],
            'الاختلافات في المحتوى' => $diffs,
            'حالة الاعتماد' => APPROVAL_STATUSES[approval_status($rev)] ?? '',
            'المعتمِد' => $rev['approved_by'],
            'تاريخ الاعتماد' => $rev['approved_at'],
            'ملاحظات المسؤول' => $rev['admin_notes'],
        ];
        $avs = $rev['approved_values'] ?? [];
        $row = &$rows[count($rows) - 1];
        foreach (VALUE_FIELDS as $fk => $fl) {
            $srcK = $avs[$fk]['src'] ?? '';
            $row[$fl . ' (المعتمد)'] = $srcK !== '' ? (string)$avs[$fk]['value'] : '';
            $row['مصدر ' . $fl] = $srcK !== '' ? (VALUE_SOURCES[$srcK] ?? $srcK) : '';
        }
        $row['الحقول غير المحسومة'] = $rec ? unresolved_labels(unresolved_fields($rec, $rev)) : '';
        $errs = $rec ? source_errors($rec, $rev) : ['diwan' => [], 'qistas' => []];
        $row['أخطاء الديوان'] = implode('، ', $errs['diwan']);
        $row['أخطاء قسطاس'] = implode('، ', $errs['qistas']);
        unset($row);
    }
    return $rows;
}

function export_updated_workbook(): string
{
    $src = main_workbook_path();
    if (!is_file($src)) {
        throw new ValidationException(['لم يتم رفع ملف Excel الأساسي بعد.']);
    }
    @ini_set('memory_limit', '1536M');
    @set_time_limit(600);
    $rows = export_rows();
    return with_lock('export', function () use ($src, $rows) {
        $reader = IOFactory::createReader('Xlsx');
        $book = $reader->load($src);
        $existing = $book->getSheetByName(RESULTS_SHEET);
        if ($existing) {
            $book->removeSheetByIndex($book->getIndex($existing));
        }
        $ws = $book->createSheet();
        $ws->setTitle(RESULTS_SHEET);
        $ws->setRightToLeft(true);
        $headers = $rows ? array_keys($rows[0]) : export_rows_header();
        foreach ($headers as $i => $h) {
            $ws->setCellValueExplicit([$i + 1, 1], $h, DataType::TYPE_STRING);
        }
        $r = 2;
        foreach ($rows as $row) {
            $c = 1;
            foreach ($row as $v) {
                $ws->setCellValueExplicit([$c++, $r], (string)$v, DataType::TYPE_STRING);
            }
            $r++;
        }
        $last = $ws->getHighestColumn();
        $ws->getStyle('A1:' . $last . '1')->getFont()->setBold(true);
        $ws->getStyle('A1:' . $last . '1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCE7F0');
        $ws->getStyle('A1:' . $last . max(1, $r - 1))->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        for ($i = 1; $i <= count($headers); $i++) {
            $ws->getColumnDimensionByColumn($i)->setWidth(22);
        }
        $ws->freezePane('A2');
        $ws->setAutoFilter('A1:' . $last . max(1, $r - 1));

        $meta = main_meta();
        $base = pathinfo((string)($meta['original_name'] ?? 'Bylaws_Diwan_Qistas_Matching.xlsx'), PATHINFO_FILENAME);
        $base = preg_replace('/[^\p{L}\p{N}_\-]+/u', '_', $base) ?: 'Matching';
        $dest = data_path('exports/' . $base . '_مع_نتائج_التدقيق_' . date('Ymd_His') . '.xlsx');
        $tmp = $dest . '.tmp';
        $writer = IOFactory::createWriter($book, 'Xlsx');
        $writer->setPreCalculateFormulas(false);
        $writer->save($tmp);
        $book->disconnectWorksheets();
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            throw new AppException('تعذر حفظ الملف المصدَّر.');
        }
        // الاحتفاظ بآخر 20 ملف تصدير فقط
        $files = glob(data_path('exports/*.xlsx')) ?: [];
        if (count($files) > 20) {
            usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));
            foreach (array_slice($files, 0, count($files) - 20) as $f) {
                @unlink($f);
            }
        }
        return $dest;
    }, 30);
}

function export_rows_header(): array
{
    $h = ['معرّف سجل التشريع', 'فئة السجل', 'اسم التشريع', 'رقم التشريع', 'سنة التشريع', 'اسم المتطوع', 'تاريخ البدء', 'تاريخ الانتهاء',
          'حالة المراجعة', 'القرار النهائي', 'ملاحظات المراجع', 'الاختلافات في المحتوى', 'حالة الاعتماد', 'المعتمِد', 'تاريخ الاعتماد', 'ملاحظات المسؤول'];
    foreach (VALUE_FIELDS as $fl) {
        $h[] = $fl . ' (المعتمد)';
        $h[] = 'مصدر ' . $fl;
    }
    $h[] = 'الحقول غير المحسومة';
    $h[] = 'أخطاء الديوان';
    $h[] = 'أخطاء قسطاس';
    return $h;
}

function send_file_download(string $path, string $downloadName): void
{
    if (!is_file($path)) {
        http_response_code(404);
        exit('الملف غير موجود');
    }
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"download.xlsx\"; filename*=UTF-8''" . rawurlencode($downloadName));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}
