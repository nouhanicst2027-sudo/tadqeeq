<?php
/**
 * قراءة ملف المطابقة الأساسي (Excel) عبر PHPSpreadsheet وبناء ذاكرة مؤقتة للسجلات.
 * الملف الأصلي لا يُعدَّل إطلاقاً؛ تُحفظ نسخة منه في data/source/main.xlsx.
 * كل سجل يأخذ معرّفاً ثابتاً مبنياً على معرّفات المصدر (وليس رقم الصف):
 *   M-{pmk_ID الديوان} للمتطابقة، D-{pmk_ID} للديوان فقط، Q-{معرّف قسطاس} لقسطاس فقط.
 */
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;

const SHEET_MATCHED = 'المتطابقة';
const SHEET_DIWAN_ONLY = 'الديوان فقط';
const SHEET_QISTAS_ONLY = 'قسطاس فقط';
const RESULTS_SHEET = 'نتائج التدقيق';

function main_workbook_path(): string
{
    return data_path('source/main.xlsx');
}

/** خرائط الأعمدة: المفتاح الداخلي => [العنوان العربي، العنوان الإنجليزي] (يدعم نسختي الملف). */
function sheet_column_maps(): array
{
    return [
        SHEET_MATCHED => [
            'match_level' => ['مستوى المطابقة', 'Match_Level'],
            'confidence' => ['درجة الثقة', 'Confidence'],
            'basis' => ['أساس المطابقة', 'Match_Basis'],
            'articles_flag' => ['إشعار المواد', 'Articles_100_Flag'],
            'd_id' => ['الديوان: pmk_ID', 'Diwan_pmk_ID'],
            'd_guid' => ['الديوان: GUID', 'Diwan_GUID'],
            'd_type' => ['الديوان: النوع', 'Diwan_RowType'],
            'd_deleted' => ['الديوان: محذوف', 'Diwan_isDelete'],
            'd_number' => ['الديوان: الرقم', 'Diwan_Number'],
            'd_year' => ['الديوان: السنة', 'Diwan_Year'],
            'd_name' => ['الديوان: الاسم', 'Diwan_Name'],
            'd_magazine' => ['الديوان: عدد الجريدة', 'Diwan_Magazine_Number'],
            'd_page' => ['الديوان: الصفحة', 'Diwan_Page_Number'],
            'd_parent' => ['الديوان: النظام الأصلي (للتعديل)', 'Diwan_Parent_FullName'],
            'q_id' => ['قسطاس: المعرف', 'Qistas_ID'],
            'q_type' => ['قسطاس: النوع', 'Qistas_Type'],
            'q_number' => ['قسطاس: الرقم', 'Qistas_Number'],
            'q_year' => ['قسطاس: السنة', 'Qistas_Year'],
            'q_name' => ['قسطاس: الاسم', 'Qistas_Name'],
            'q_magazine' => ['قسطاس: عدد الجريدة', 'Qistas_Magazine_Number'],
            'q_page' => ['قسطاس: الصفحة', 'Qistas_Page_Number'],
            'q_status' => ['قسطاس: الحالة', 'Qistas_Status'],
            'number_match' => ['الرقم متطابق', 'Number_Match'],
            'year_match' => ['السنة متطابقة', 'Year_Match'],
            'magazine_match' => ['العدد متطابق', 'Magazine_Match'],
            'page_match' => ['الصفحة متطابقة', 'Page_Match'],
            'name_similarity' => ['تشابه الاسم %', 'Name_Similarity_%'],
            'd_articles_count' => ['عدد مواد الديوان', 'Diwan_Articles_Count'],
            'q_articles_count' => ['عدد مواد قسطاس', 'Qistas_Articles_Count'],
            'articles_similarity' => ['تطابق المواد %', 'Articles_Similarity_%'],
            'identical_articles' => ['مواد متطابقة حرفياً', 'Identical_Articles_Count'],
            'different_articles' => ['أرقام المواد المختلفة (الديوان)', 'Different_Articles_Diwan_Numbers'],
            'notes' => ['ملاحظات', 'Notes'],
            'q_url' => ['قسطاس: الرابط', 'Qistas_URL'],
        ],
        SHEET_DIWAN_ONLY => [
            'd_id' => ['الديوان: pmk_ID', 'Diwan_pmk_ID'],
            'd_guid' => ['الديوان: GUID', 'Diwan_GUID'],
            'd_type' => ['النوع', 'Diwan_RowType'],
            'd_deleted' => ['محذوف', 'Diwan_isDelete'],
            'd_number' => ['الرقم', 'Diwan_Number'],
            'd_year' => ['السنة', 'Diwan_Year'],
            'd_name' => ['الاسم', 'Diwan_Name'],
            'd_magazine' => ['عدد الجريدة', 'Diwan_Magazine_Number'],
            'd_page' => ['الصفحة', 'Diwan_Page_Number'],
            'd_articles_count' => ['عدد المواد', 'Diwan_Articles_Count'],
            'd_parent' => ['النظام الأصلي (للتعديل)', 'Diwan_Parent_FullName'],
            'reason' => ['السبب', 'Unmatched_Reason'],
            'cand_id' => ['أقرب مرشح بقسطاس: المعرف', 'Best_Candidate_Qistas_ID'],
            'cand_number_year' => ['المرشح: الرقم/السنة', 'Candidate_Number_Year'],
            'cand_magazine_page' => ['المرشح: العدد/الصفحة', 'Candidate_Magazine_Page'],
            'cand_name' => ['المرشح: الاسم', 'Candidate_Name'],
            'cand_matching_fields' => ['حقول متطابقة (من 4)', 'Matching_Fields_of_4'],
            'cand_name_similarity' => ['تشابه الاسم %', 'Name_Similarity_%'],
            'cand_articles_similarity' => ['تطابق المواد %', 'Articles_Similarity_%'],
        ],
        SHEET_QISTAS_ONLY => [
            'q_id' => ['قسطاس: المعرف', 'Qistas_ID'],
            'q_type' => ['النوع', 'Qistas_Type'],
            'q_number' => ['الرقم', 'Qistas_Number'],
            'q_year' => ['السنة', 'Qistas_Year'],
            'q_name' => ['الاسم', 'Qistas_Name'],
            'q_magazine' => ['عدد الجريدة', 'Qistas_Magazine_Number'],
            'q_page' => ['الصفحة', 'Qistas_Page_Number'],
            'q_status' => ['الحالة', 'Qistas_Status'],
            'q_articles_count' => ['عدد المواد', 'Qistas_Articles_Count'],
            'reason' => ['السبب', 'Unmatched_Reason'],
            'cand_id' => ['أقرب مرشح بالديوان: pmk_ID', 'Best_Candidate_Diwan_pmk_ID'],
            'cand_number_year' => ['المرشح: الرقم/السنة', 'Candidate_Number_Year'],
            'cand_magazine_page' => ['المرشح: العدد/الصفحة', 'Candidate_Magazine_Page'],
            'cand_name' => ['المرشح: الاسم', 'Candidate_Name'],
            'cand_matching_fields' => ['حقول متطابقة (من 4)', 'Matching_Fields_of_4'],
            'cand_name_similarity' => ['تشابه الاسم %', 'Name_Similarity_%'],
            'q_url' => ['الرابط', 'Qistas_URL'],
        ],
    ];
}

const REQUIRED_COLUMNS = [
    SHEET_MATCHED => ['d_id', 'q_id'],
    SHEET_DIWAN_ONLY => ['d_id'],
    SHEET_QISTAS_ONLY => ['q_id'],
];

function clean_cell($v)
{
    if ($v === null) {
        return null;
    }
    if (is_float($v)) {
        if (floor($v) == $v && abs($v) < 1e15) {
            return (int)$v;
        }
        return round($v, 1);
    }
    if (is_string($v)) {
        $v = trim($v);
        return ($v === '' || $v === 'NULL') ? null : $v;
    }
    return $v;
}

function year_from_parent(?string $parent): ?int
{
    if ($parent && preg_match('~^\s*\d*\s*/\s*(\d{4})~u', $parent, $m)) {
        return (int)$m[1];
    }
    return null;
}

/**
 * فحص بنية ملف المطابقة وقراءته وتحويله إلى سجلات. لا يكتب شيئاً.
 * @return array{records: array, warnings: array, sheets: array}
 */
function parse_workbook(string $path): array
{
    if (!is_file($path)) {
        throw new AppException('ملف Excel غير موجود.');
    }
    @ini_set('memory_limit', '1024M');
    @set_time_limit(600);
    try {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
    } catch (Throwable $e) {
        throw new AppException('تعذر قراءة ملف Excel: ' . $e->getMessage());
    }

    $maps = sheet_column_maps();
    $errors = [];
    $warnings = [];
    $records = [];
    $sheetsInfo = [];
    $rawHeaders = [];
    foreach ($book->getSheetNames() as $sn) {
        $sheetsInfo[] = ['name' => $sn, 'rows' => $book->getSheetByName($sn)->getHighestDataRow()];
    }

    $category = [SHEET_MATCHED => 'matched', SHEET_DIWAN_ONLY => 'diwan_only', SHEET_QISTAS_ONLY => 'qistas_only'];
    foreach ($maps as $sheetName => $map) {
        $ws = $book->getSheetByName($sheetName);
        if (!$ws) {
            $errors[] = 'ورقة العمل «' . $sheetName . '» غير موجودة في الملف.';
            continue;
        }
        $rows = $ws->toArray(null, false, false, false);
        if (!$rows) {
            $errors[] = 'ورقة العمل «' . $sheetName . '» فارغة.';
            continue;
        }
        $headers = array_map(fn($h) => is_string($h) ? trim($h) : (string)$h, $rows[0]);
        $colIndex = [];
        foreach ($map as $key => [$ar, $en]) {
            $idx = array_search($ar, $headers, true);
            if ($idx === false) {
                $idx = array_search($en, $headers, true);
            }
            if ($idx !== false) {
                $colIndex[$key] = $idx;
            }
        }
        foreach (REQUIRED_COLUMNS[$sheetName] as $req) {
            if (!isset($colIndex[$req])) {
                $errors[] = 'العمود «' . $map[$req][0] . '» (' . $map[$req][1] . ') غير موجود في ورقة «' . $sheetName . '».';
            }
        }
        $missingOptional = array_diff(array_keys($map), array_keys($colIndex));
        if ($missingOptional) {
            $warnings[] = 'أعمدة اختيارية غير موجودة في «' . $sheetName . '»: ' . implode('، ', array_map(fn($k) => $map[$k][0], $missingOptional));
        }
        if ($errors) {
            continue;
        }
        $cat = $category[$sheetName];
        $seen = [];
        for ($r = 1, $n = count($rows); $r < $n; $r++) {
            $row = $rows[$r];
            $v = [];
            foreach ($colIndex as $key => $idx) {
                $v[$key] = clean_cell($row[$idx] ?? null);
            }
            $raw = [];
            foreach ($headers as $i => $h) {
                $raw[] = clean_cell($row[$i] ?? null);
            }
            $pk = $cat === 'qistas_only' ? ($v['q_id'] ?? null) : ($v['d_id'] ?? null);
            if ($pk === null || $pk === '') {
                if (array_filter($row, fn($x) => $x !== null && $x !== '')) {
                    $warnings[] = 'صف بدون معرّف في «' . $sheetName . '» (الصف ' . ($r + 1) . ') — تم تجاهله.';
                }
                continue;
            }
            $prefix = ['matched' => 'M', 'diwan_only' => 'D', 'qistas_only' => 'Q'][$cat];
            $id = $prefix . '-' . $pk;
            if (isset($seen[$id])) {
                $warnings[] = 'معرّف مكرر ' . $id . ' في «' . $sheetName . '» (الصف ' . ($r + 1) . ') — أضيفت لاحقة.';
                $id .= '-' . ($r + 1);
            }
            $seen[$id] = true;
            $records[$id] = build_record($id, $cat, $v, $raw, $sheetName);
        }
        $rawHeaders[$sheetName] = $headers;
    }
    $book->disconnectWorksheets();
    unset($book);
    if ($errors) {
        throw new ValidationException($errors);
    }
    if (!$records) {
        throw new ValidationException(['لم يتم العثور على أي سجلات في الملف.']);
    }
    return ['records' => $records, 'warnings' => $warnings, 'sheets' => $sheetsInfo, 'raw_headers' => $rawHeaders];
}

function build_record(string $id, string $cat, array $v, array $raw, string $sheet): array
{
    $g = fn($k) => $v[$k] ?? null;
    $diwan = null;
    $qistas = null;
    if ($cat !== 'qistas_only') {
        $diwan = [
            'id' => $g('d_id'), 'guid' => $g('d_guid'), 'type' => $g('d_type'), 'deleted' => $g('d_deleted'),
            'number' => $g('d_number'), 'year' => $g('d_year'), 'name' => $g('d_name'),
            'magazine' => $g('d_magazine'), 'page' => $g('d_page'), 'parent' => $g('d_parent'),
            'articles_count' => $g('d_articles_count'),
        ];
    }
    if ($cat !== 'diwan_only') {
        $qistas = [
            'id' => $g('q_id'), 'type' => $g('q_type'), 'number' => $g('q_number'), 'year' => $g('q_year'),
            'name' => $g('q_name'), 'magazine' => $g('q_magazine'), 'page' => $g('q_page'),
            'status' => $g('q_status'), 'url' => $g('q_url'), 'articles_count' => $g('q_articles_count'),
        ];
    }
    $primary = $diwan ?? $qistas;
    $isAmend = in_array($primary['type'] ?? '', ['تعديل'], true);
    $year = is_numeric($primary['year'] ?? null) ? (int)$primary['year'] : null;
    $baseYear = $year;
    if ($diwan && $isAmend) {
        $baseYear = year_from_parent((string)$diwan['parent']) ?? $year;
    }

    $match = null;
    $candidate = null;
    if ($cat === 'matched') {
        $match = [];
        foreach (['match_level', 'confidence', 'basis', 'articles_flag', 'number_match', 'year_match', 'magazine_match',
                  'page_match', 'name_similarity', 'articles_similarity', 'identical_articles', 'different_articles', 'notes'] as $k) {
            $match[$k] = $g($k);
        }
    } else {
        $candidate = [
            'id' => $g('cand_id'), 'number_year' => $g('cand_number_year'), 'magazine_page' => $g('cand_magazine_page'),
            'name' => $g('cand_name'), 'matching_fields' => $g('cand_matching_fields'),
            'name_similarity' => $g('cand_name_similarity'), 'articles_similarity' => $g('cand_articles_similarity'),
            'reason' => $g('reason'),
        ];
    }

    $rec = [
        'id' => $id,
        'category' => $cat,
        'sheet' => $sheet,
        'name' => (string)($primary['name'] ?? ''),
        'number' => $primary['number'] ?? null,
        'year' => $year,
        'base_year' => $baseYear,
        'is_amendment' => $isAmend,
        'type' => $primary['type'] ?? null,
        'diwan' => $diwan,
        'qistas' => $qistas,
        'match' => $match,
        'candidate' => $candidate,
        'raw' => $raw,
    ];
    $rec['priority'] = record_priority($rec);
    $rec['search'] = ar_normalize(trim(($diwan['name'] ?? '') . ' ' . ($qistas['name'] ?? '')));
    return $rec;
}

/** أولوية العمل وفق جدول أولويات فريق التدقيق في SOP. */
function record_priority(array $r): int
{
    if ($r['category'] === 'diwan_only') {
        return 3;
    }
    if ($r['category'] === 'qistas_only') {
        return 4;
    }
    $conf = $r['match']['confidence'] ?? '';
    if ($conf === 'تحتاج مراجعة') {
        return 1;
    }
    if ($conf === 'متوسطة') {
        return 2;
    }
    $flag = (string)($r['match']['articles_flag'] ?? '');
    $sim = $r['match']['articles_similarity'] ?? null;
    if (!$r['is_amendment'] && $sim !== null && (float)$sim < 100 && mb_strpos($flag, '100%') === false) {
        return 5;
    }
    return 6;
}

const PRIORITY_LABELS = [
    1 => 'تطابق يحتاج مراجعة',
    2 => 'تطابق متوسط الثقة',
    3 => 'الديوان فقط',
    4 => 'قسطاس فقط',
    5 => 'اختلاف في المواد',
    6 => 'تطابق عالي الثقة (عينة)',
];

/** استيراد ملف جديد: فحص → نسخ احتياطي للقديم → استبدال ذري → إعادة بناء الذاكرة المؤقتة. */
function import_main_workbook(string $uploadedPath, string $originalName): array
{
    $parsed = parse_workbook($uploadedPath);
    return with_lock('main_workbook', function () use ($uploadedPath, $originalName, $parsed) {
        $dest = main_workbook_path();
        if (is_file($dest)) {
            backup_file($dest, 'backups/source', 'main', 30);
        }
        $tmp = $dest . '.tmp-' . bin2hex(random_bytes(4));
        if (!@copy($uploadedPath, $tmp) || !@rename($tmp, $dest)) {
            @unlink($tmp);
            throw new AppException('تعذر حفظ ملف Excel على الخادم.');
        }
        $meta = [
            'original_name' => $originalName,
            'uploaded_at' => now_str(),
            'sha1' => sha1_file($dest),
            'size' => filesize($dest),
            'sheets' => $parsed['sheets'],
            'warnings' => $parsed['warnings'],
            'records' => count($parsed['records']),
        ];
        write_records_cache($parsed['records'], $meta, $parsed['raw_headers']);
        return $meta;
    });
}

/** الذاكرة المؤقتة: السجلات (خفيفة) + القيم الأصلية الكاملة لكل صف في ملف منفصل يُقرأ في صفحة التفاصيل فقط. */
function write_records_cache(array $records, array $meta, array $rawHeaders): void
{
    $raw = [];
    foreach ($records as $id => &$r) {
        $raw[$id] = $r['raw'];
        unset($r['raw']);
    }
    unset($r);
    write_json(data_path('cache/records_raw.json'), ['headers' => $rawHeaders, 'values' => $raw], false);
    write_json(data_path('cache/records.json'), ['meta' => $meta, 'records' => $records], false);
    write_json(data_path('source/main_meta.json'), $meta);
}

function main_meta(): ?array
{
    return read_json(data_path('source/main_meta.json'));
}

function records_all(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $path = data_path('cache/records.json');
    if (!is_file($path)) {
        // الملف موجود لكن الذاكرة المؤقتة مفقودة: إعادة بنائها
        if (is_file(main_workbook_path())) {
            $parsed = parse_workbook(main_workbook_path());
            $meta = main_meta() ?? ['original_name' => 'main.xlsx', 'uploaded_at' => now_str()];
            $meta['records'] = count($parsed['records']);
            write_records_cache($parsed['records'], $meta, $parsed['raw_headers']);
            return $cache = read_json($path, [])['records'] ?? [];
        }
        return $cache = [];
    }
    $d = read_json($path, []);
    return $cache = ($d['records'] ?? []);
}

function record_get(string $id): ?array
{
    return records_all()[$id] ?? null;
}

/** جميع القيم الأصلية لصف السجل في ملف المطابقة: [[عنوان العمود، القيمة]...] */
function record_raw(array $record): array
{
    $d = read_json(data_path('cache/records_raw.json'), []);
    $headers = $d['headers'][$record['sheet']] ?? [];
    $vals = $d['values'][$record['id']] ?? [];
    $out = [];
    foreach ($headers as $i => $hd) {
        if ($hd !== '') {
            $out[] = [$hd, $vals[$i] ?? null];
        }
    }
    return $out;
}

function record_in_scope(array $r): bool
{
    $s = settings()['scope'];
    if (empty($s['enabled'])) {
        return true;
    }
    $y = ($s['basis'] ?? 'base') === 'base' ? $r['base_year'] : $r['year'];
    if ($y === null) {
        return true; // لا نستبعد سجلاً بسبب نقص البيانات
    }
    if ($s['min_year'] !== null && $s['min_year'] !== '' && $y < (int)$s['min_year']) {
        return false;
    }
    if ($s['max_year'] !== null && $s['max_year'] !== '' && $y > (int)$s['max_year']) {
        return false;
    }
    return true;
}

function scope_label(): string
{
    $s = settings()['scope'];
    if (empty($s['enabled'])) {
        return 'جميع السنوات';
    }
    $basis = ($s['basis'] ?? 'base') === 'base' ? 'سنة التشريع الأصلي' : 'سنة السجل';
    $parts = [];
    if (!empty($s['min_year'])) {
        $parts[] = 'من ' . (int)$s['min_year'];
    }
    if (!empty($s['max_year'])) {
        $parts[] = 'حتى ' . (int)$s['max_year'] . ' (شاملة)';
    }
    return ($parts ? implode(' ', $parts) : 'جميع السنوات') . ' — حسب ' . $basis;
}

function record_title(array $r): string
{
    $t = $r['name'] !== '' ? $r['name'] : 'تشريع بدون اسم';
    return $t;
}

function record_ref(array $r): string
{
    $parts = [];
    if ($r['number'] !== null && $r['number'] !== '') {
        $parts[] = 'رقم ' . $r['number'];
    }
    if ($r['year']) {
        $parts[] = 'لسنة ' . $r['year'];
    }
    return implode(' ', $parts);
}
