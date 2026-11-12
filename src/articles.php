<?php
/**
 * ملفات المواد التشريعية الإضافية:
 *  - الديوان: CSV (مثل reg_articles.csv) — صف لكل مادة، يرتبط بالتشريع عبر Bylow_pmk_ID.
 *  - قسطاس: JSON (مثل Bylaws_16.json) — سجل لكل تشريع مع مصفوفة Articles، ويُستخرج معرّفه من الرابط /laws/info/{ID}/.
 * عند الاستيراد يُبنى فهرس: ملف JSON صغير لكل تشريع داخل data/index/{source}/{id}.json
 * حتى تُسترجع المواد بسرعة دون قراءة الملفات الضخمة في كل طلب. الملفات الأصلية تُحفظ كما هي.
 */
declare(strict_types=1);

const SOURCES = ['diwan' => 'ديوان التشريع والرأي', 'qistas' => 'قسطاس'];

const DIWAN_REQUIRED_HEADERS = ['Bylow_pmk_ID', 'Article_Number', 'Article'];

function html_to_text(?string $html): string
{
    if ($html === null || $html === '' || $html === 'NULL') {
        return '';
    }
    $h = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $html);
    $h = preg_replace('~</\s*(p|div|li|h[1-6]|figure)\s*>~i', "\n", $h);
    $h = preg_replace('~</\s*tr\s*>~i', "\n", $h);
    $h = preg_replace('~</\s*t[dh]\s*>~i', ' ¦ ', $h);
    $t = strip_tags($h);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = str_replace(["\xC2\xA0", "\r"], [' ', ''], $t);
    $lines = [];
    foreach (explode("\n", $t) as $line) {
        $line = preg_replace('/[ \t]+/u', ' ', $line);
        $line = trim(preg_replace('/(\s*¦\s*)+$/u', '', $line));
        $line = preg_replace('/^(¦\s*)+/u', '', $line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    return implode("\n", $lines);
}

function nv($v)
{
    if ($v === null) {
        return null;
    }
    $v = is_string($v) ? trim($v) : $v;
    return ($v === '' || $v === 'NULL') ? null : $v;
}

function index_dir(string $source): string
{
    return data_path('index/' . $source);
}

function index_info(string $source): ?array
{
    return read_json(data_path('index/' . $source . '_info.json'));
}

/** استخراج الملف المطلوب من ZIP إن لزم. يعيد المسار الفعلي للملف. */
function resolve_articles_file(string $source, string $path, string $originalName): string
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $want = $source === 'diwan' ? 'csv' : 'json';
    if ($ext === $want) {
        return $path;
    }
    if ($ext !== 'zip') {
        throw new ValidationException(['نوع الملف غير مدعوم. المطلوب ملف ' . strtoupper($want) . ' أو ملف ZIP يحتويه.']);
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new ValidationException(['تعذر فتح ملف ZIP.']);
    }
    $found = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (str_contains($name, '__MACOSX') || str_ends_with($name, '/')) {
            continue;
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === $want) {
            $found = $i;
            break;
        }
    }
    if ($found === null) {
        $zip->close();
        throw new ValidationException(['لا يحتوي ملف ZIP على ملف ' . strtoupper($want) . '.']);
    }
    $dest = data_path('tmp/extract_' . $source . '_' . bin2hex(random_bytes(4)) . '.' . $want);
    $in = $zip->getStream($zip->getNameIndex($found));
    $out = fopen($dest, 'wb');
    if (!$in || !$out) {
        $zip->close();
        throw new AppException('تعذر استخراج الملف من ZIP.');
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    $zip->close();
    return $dest;
}

/**
 * استيراد ملف مواد لمصدر معين وبناء الفهرس بشكل ذري.
 * @return array معلومات الاستيراد
 */
function import_articles(string $source, string $path, string $originalName, ?callable $progress = null): array
{
    if (!isset(SOURCES[$source])) {
        throw new ValidationException(['مصدر غير معروف.']);
    }
    @ini_set('memory_limit', '2048M');
    @set_time_limit(0);
    ignore_user_abort(true);

    return with_lock('index_' . $source, function () use ($source, $path, $originalName, $progress) {
        $file = resolve_articles_file($source, $path, $originalName);
        $work = data_path('index/' . $source . '.building-' . bin2hex(random_bytes(3)));
        if (!@mkdir($work, 0775, true)) {
            throw new AppException('تعذر إنشاء مجلد الفهرس المؤقت.');
        }
        try {
            $result = $source === 'diwan'
                ? build_diwan_index($file, $work, $progress)
                : build_qistas_index($file, $work, $progress);
        } catch (Throwable $e) {
            rrmdir($work);
            throw $e;
        } finally {
            if ($file !== $path && str_starts_with($file, data_path('tmp/'))) {
                // نحتفظ بنسخة من الملف الأصلي في source قبل حذف المؤقت
                @copy($file, data_path('source/' . $source . '_articles.' . ($source === 'diwan' ? 'csv' : 'json')));
                @unlink($file);
            }
        }
        if ($file === $path) {
            @copy($path, data_path('source/' . $source . '_articles.' . ($source === 'diwan' ? 'csv' : 'json')));
        }
        // تبديل ذري للفهرس
        $final = index_dir($source);
        $old = null;
        if (is_dir($final)) {
            $old = $final . '.old-' . date('YmdHis');
            rename($final, $old);
        }
        rename($work, $final);
        write_json(data_path('index/' . $source . '_meta.json'), $result['meta'], false);
        $info = [
            'source' => $source,
            'original_name' => $originalName,
            'imported_at' => now_str(),
            'legislations' => count($result['meta']),
            'articles' => $result['articles'],
            'warnings' => array_slice($result['warnings'], 0, 50),
            'warnings_total' => count($result['warnings']),
        ];
        write_json(data_path('index/' . $source . '_info.json'), $info);
        if ($old) {
            rrmdir($old);
        }
        app_log('info', 'articles imported', ['source' => $source, 'legislations' => $info['legislations']]);
        return $info;
    }, 60);
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

function article_sort(array &$arts): void
{
    foreach ($arts as $i => &$a) {
        $a['_i'] = $i;
    }
    unset($a);
    usort($arts, function ($x, $y) {
        $c = strnatcmp((string)$x['number'], (string)$y['number']);
        return $c !== 0 ? $c : $x['_i'] <=> $y['_i'];
    });
    foreach ($arts as &$a) {
        unset($a['_i']);
    }
}

function build_diwan_index(string $csv, string $work, ?callable $progress): array
{
    $fh = fopen($csv, 'rb');
    if (!$fh) {
        throw new AppException('تعذر فتح ملف CSV.');
    }
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($fh);
    }
    $headers = fgetcsv($fh, 0, ',', '"', '');
    if (!$headers) {
        throw new ValidationException(['ملف CSV فارغ.']);
    }
    $headers = array_map('trim', $headers);
    $missing = array_diff(DIWAN_REQUIRED_HEADERS, $headers);
    if ($missing) {
        throw new ValidationException(['ملف مواد الديوان لا يحتوي الأعمدة المطلوبة: ' . implode('، ', $missing)]);
    }
    $col = array_flip($headers);
    $get = fn(array $row, string $k) => isset($col[$k]) ? nv($row[$col[$k]] ?? null) : null;

    $tmpDir = $work . '/_parts';
    mkdir($tmpDir);
    $warnings = [];
    $count = 0;
    $metas = [];
    $buffer = [];
    $bufId = null;
    $flush = function () use (&$buffer, &$bufId, $tmpDir) {
        if ($bufId !== null && $buffer) {
            file_put_contents($tmpDir . '/' . $bufId . '.jsonl', implode("\n", $buffer) . "\n", FILE_APPEND);
        }
        $buffer = [];
    };
    $line = 1;
    while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $line++;
        if ($row === [null]) {
            continue;
        }
        if (count($row) !== count($headers)) {
            $warnings[] = 'السطر ' . $line . ': عدد الأعمدة غير مطابق — تم تجاهله.';
            continue;
        }
        $id = $get($row, 'Bylow_pmk_ID');
        if ($id === null || !ctype_digit((string)$id)) {
            $warnings[] = 'السطر ' . $line . ': معرّف تشريع غير صالح — تم تجاهله.';
            continue;
        }
        if (!isset($metas[$id])) {
            $metas[$id] = [
                'id' => (int)$id,
                'name' => $get($row, 'ByLawName'),
                'number' => $get($row, 'ByLawNumber'),
                'year' => $get($row, 'Year'),
                'replaced_for' => $get($row, 'Replaced_For'),
                'active_date' => $get($row, 'Active_Date'),
                'magazine_number' => $get($row, 'Magazine_Number'),
                'magazine_date' => $get($row, 'Magazine_Date'),
                'magazine_page' => $get($row, 'Magazine_Page_Number'),
                'status' => $get($row, 'Status'),
                'category' => $get($row, 'Category'),
                'mod_leg_type' => $get($row, 'ModLegType'),
                'mod_leg' => $get($row, 'ModLeg'),
                'count' => 0,
            ];
        }
        $metas[$id]['count']++;
        $old = $get($row, 'OldArticle');
        $art = [
            'id' => $get($row, 'Article_pmk_ID'),
            'number' => (string)($get($row, 'Article_Number') ?? ''),
            'text' => html_to_text($get($row, 'Article')),
            'deleted' => $get($row, 'DeleteArticle') === '1',
            'old_text' => $old !== null ? html_to_text($old) : null,
        ];
        if ($bufId !== $id) {
            $flush();
            $bufId = $id;
        }
        $buffer[] = json_encode($art, JSON_UNESCAPED_UNICODE);
        $count++;
        if ($progress && $count % 20000 === 0) {
            $progress("قراءة مواد الديوان: $count");
        }
    }
    $flush();
    fclose($fh);
    if (!$metas) {
        throw new ValidationException(['لم يتم العثور على أي مواد في ملف الديوان.']);
    }
    foreach ($metas as $id => &$meta) {
        $arts = [];
        foreach (file($tmpDir . '/' . $id . '.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
            $arts[] = json_decode($l, true);
        }
        article_sort($arts);
        $nums = array_count_values(array_map(fn($a) => $a['number'], $arts));
        $meta['duplicate_numbers'] = array_keys(array_filter($nums, fn($c) => $c > 1));
        file_put_contents($work . '/' . $id . '.json', json_encode(['source' => 'diwan', 'meta' => $meta, 'articles' => $arts], JSON_UNESCAPED_UNICODE));
        unlink($tmpDir . '/' . $id . '.jsonl');
    }
    unset($meta);
    rmdir($tmpDir);
    return ['meta' => $metas, 'articles' => $count, 'warnings' => $warnings];
}

function qistas_id_from(array $rec): ?string
{
    foreach (['ID', 'Id', 'id', 'Qistas_ID', 'Leg_ID'] as $k) {
        if (isset($rec[$k]) && ctype_digit((string)$rec[$k])) {
            return (string)$rec[$k];
        }
    }
    if (!empty($rec['URL']) && preg_match('~/laws/info/(\d+)/~', (string)$rec['URL'], $m)) {
        return $m[1];
    }
    return null;
}

function build_qistas_index(string $jsonPath, string $work, ?callable $progress): array
{
    $raw = file_get_contents($jsonPath);
    if ($raw === false) {
        throw new AppException('تعذر قراءة ملف JSON.');
    }
    $data = json_decode($raw, true);
    unset($raw);
    if (!is_array($data)) {
        throw new ValidationException(['ملف JSON غير صالح أو تالف: ' . json_last_error_msg()]);
    }
    if (!array_is_list($data)) {
        $data = $data['data'] ?? $data['records'] ?? array_values($data);
    }
    $warnings = [];
    $metas = [];
    $count = 0;
    foreach ($data as $i => $rec) {
        if (!is_array($rec)) {
            continue;
        }
        $id = qistas_id_from($rec);
        if ($id === null) {
            $warnings[] = 'السجل ' . ($i + 1) . ': لم يتم العثور على معرّف قسطاس (في الرابط أو حقل ID) — تم تجاهله.';
            continue;
        }
        if (isset($metas[$id])) {
            $warnings[] = 'معرّف قسطاس مكرر: ' . $id . ' — تم الاحتفاظ بالسجل الأول.';
            continue;
        }
        $arts = [];
        foreach (($rec['Articles'] ?? []) as $a) {
            if (!is_array($a)) {
                continue;
            }
            $num = (string)($a['article_number'] ?? '');
            if ($num === '' && !empty($a['title']) && preg_match('/(\d+)/u', (string)$a['title'], $m)) {
                $num = $m[1];
            }
            $arts[] = [
                'number' => $num,
                'title' => nv($a['title'] ?? null),
                'text' => trim(str_replace("\r", '', (string)($a['text'] ?? ''))),
                'enforcement_date' => nv($a['enforcement_date'] ?? null),
            ];
        }
        article_sort($arts);
        $nums = array_count_values(array_map(fn($a) => $a['number'], $arts));
        $meta = [
            'id' => (int)$id,
            'name' => nv($rec['Leg_Name'] ?? null),
            'number' => nv($rec['Leg_Number'] ?? null),
            'year' => nv($rec['Year'] ?? null),
            'magazine_number' => nv($rec['Magazine_Number'] ?? null),
            'magazine_date' => nv($rec['Magazine_Date'] ?? null),
            'magazine_page' => nv($rec['Magazine_Page'] ?? null),
            'status' => nv($rec['Status'] ?? null),
            'is_amendment' => !empty($rec['is_amendment']),
            'active_date' => nv($rec['Active_Date'] ?? null),
            'end_date' => nv($rec['End_Date'] ?? null),
            'canceled_by' => nv($rec['Canceled_By'] ?? null),
            'replaced_for' => nv($rec['Replaced_For'] ?? null),
            'url' => nv($rec['URL'] ?? null),
            'count' => count($arts),
            'duplicate_numbers' => array_keys(array_filter($nums, fn($c) => $c > 1)),
        ];
        file_put_contents($work . '/' . $id . '.json', json_encode(['source' => 'qistas', 'meta' => $meta, 'articles' => $arts], JSON_UNESCAPED_UNICODE));
        $metas[$id] = $meta;
        $count += count($arts);
        if ($progress && count($metas) % 1000 === 0) {
            $progress('قراءة سجلات قسطاس: ' . count($metas));
        }
    }
    if (!$metas) {
        throw new ValidationException(['لم يتم العثور على أي تشريعات صالحة في ملف قسطاس.']);
    }
    return ['meta' => $metas, 'articles' => $count, 'warnings' => $warnings];
}

/** استرجاع مواد تشريع من الفهرس. يعيد null إذا لم يكن موجوداً. */
function articles_get(string $source, $id): ?array
{
    if (!isset(SOURCES[$source]) || !preg_match('/^\d{1,12}$/', (string)$id)) {
        return null;
    }
    $p = index_dir($source) . '/' . $id . '.json';
    if (!is_file($p)) {
        return null;
    }
    return read_json($p);
}

function articles_meta_all(string $source): array
{
    static $cache = [];
    if (!isset($cache[$source])) {
        $cache[$source] = read_json(data_path('index/' . $source . '_meta.json'), []) ?: [];
    }
    return $cache[$source];
}

function articles_meta(string $source, $id): ?array
{
    if ($id === null || $id === '') {
        return null;
    }
    return articles_meta_all($source)[(string)$id] ?? null;
}

/** بحث يدوي في فهرس مصدر (بالرقم/السنة/الاسم/الجريدة) — أداة مساعدة للمتطوع وليست مطابقة آلية. */
function articles_search(string $source, array $q, int $limit = 30): array
{
    $num = trim((string)($q['number'] ?? ''));
    $year = trim((string)($q['year'] ?? ''));
    $mag = trim((string)($q['magazine'] ?? ''));
    $name = ar_normalize((string)($q['name'] ?? ''));
    if ($num === '' && $year === '' && $name === '' && $mag === '') {
        return [];
    }
    $out = [];
    foreach (articles_meta_all($source) as $m) {
        if ($num !== '' && (string)$m['number'] !== $num) {
            continue;
        }
        if ($year !== '' && (string)$m['year'] !== $year) {
            continue;
        }
        if ($mag !== '' && (string)$m['magazine_number'] !== $mag) {
            continue;
        }
        if ($name !== '') {
            $hay = ar_normalize((string)$m['name']);
            foreach (explode(' ', $name) as $w) {
                if ($w !== '' && mb_strpos($hay, $w) === false) {
                    continue 2;
                }
            }
        }
        $out[] = [
            'id' => $m['id'], 'name' => $m['name'], 'number' => $m['number'], 'year' => $m['year'],
            'magazine_number' => $m['magazine_number'], 'magazine_page' => $m['magazine_page'], 'count' => $m['count'],
        ];
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}
