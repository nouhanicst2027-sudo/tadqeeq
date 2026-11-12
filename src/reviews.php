<?php
/**
 * نتائج التدقيق: تُخزَّن في ملف Excel (data/reviews/reviews.xlsx، ورقة «نتائج التدقيق») عبر PHPSpreadsheet.
 * كل تعديل: قفل حصري ← قراءة أحدث نسخة ← التحقق من رقم النسخة (منع الاستبدال غير المقصود)
 *            ← نسخة احتياطية ← كتابة ملف مؤقت ← التحقق من قراءته ← استبدال ذري ← تحديث الذاكرة المؤقتة ← سجل العمليات.
 * ملف المطابقة الأصلي لا يُلمس؛ وعند التصدير تُضاف ورقة النتائج إلى نسخة منه.
 */
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

const REVIEW_COLUMNS = [
    'record_id' => 'معرّف سجل التشريع',
    'category' => 'فئة السجل',
    'legislation' => 'اسم التشريع',
    'number' => 'رقم التشريع',
    'year' => 'سنة التشريع',
    'status' => 'حالة التدقيق',
    'volunteer_id' => 'معرّف المتطوع',
    'volunteer_name' => 'اسم المتطوع',
    'started_at' => 'تاريخ بدء المراجعة',
    'submitted_at' => 'تاريخ إرسال المراجعة',
    'updated_at' => 'تاريخ آخر تحديث',
    'final_decision' => 'القرار النهائي',
    'reviewer_notes' => 'ملاحظات المراجع',
    'content_differences' => 'الاختلافات في المحتوى',
    'approved_values' => 'القيم المعتمدة',
    'compare_diwan_id' => 'معرّف الديوان المستخدم في المقارنة',
    'compare_qistas_id' => 'معرّف قسطاس المستخدم في المقارنة',
    'approval_status' => 'حالة الاعتماد',
    'admin_notes' => 'ملاحظات المسؤول',
    'approved_at' => 'تاريخ الاعتماد',
    'approved_by' => 'المعتمِد',
    'version' => 'رقم النسخة',
    'history' => 'سجل العمليات',
];

const JSON_REVIEW_FIELDS = ['content_differences', 'approved_values', 'history'];
const MAX_CELL = 32000;

function reviews_xlsx_path(): string
{
    return data_path('reviews/reviews.xlsx');
}

function reviews_cache_path(): string
{
    return data_path('cache/reviews.json');
}

function file_stamp(string $p): string
{
    clearstatcache(true, $p);
    return is_file($p) ? (filesize($p) . ':' . filemtime($p) . ':' . md5_file($p)) : 'none';
}

/**
 * تحويل المراجعات المحفوظة بالنسخة السابقة (6 حالات) إلى النموذج الحالي:
 * 3 حالات للمراجعة (لم تبدأ / قيد التنفيذ / منتهية) + حالة اعتماد منفصلة. لا يُحذف أي حقل.
 */
function normalize_review(array $rev): array
{
    $st = (string)($rev['status'] ?? '');
    $ap = (string)($rev['approval_status'] ?? '');
    switch ($st) {
        case 'in_review':
            $st = 'in_progress';
            break;
        case 'returned':
        case 'needs_research':
            $st = 'in_progress';
            $ap = 'returned';
            break;
        case 'submitted':
            $st = 'done';
            $ap = 'pending';
            break;
        case 'approved':
            $st = 'done';
            $ap = 'approved';
            break;
    }
    if (!isset(STATUSES[$st])) {
        $st = empty($rev['volunteer_id']) ? 'not_started' : 'in_progress';
    }
    if ($ap === 'research') {
        $ap = 'returned';
    }
    if (!isset(APPROVAL_STATUSES[$ap])) {
        $ap = $st === 'done' ? 'pending' : '';
    }
    $rev['status'] = $st;
    $rev['approval_status'] = $ap;
    foreach (REVIEW_COLUMNS as $k => $_) {
        if (!array_key_exists($k, $rev)) {
            $rev[$k] = in_array($k, JSON_REVIEW_FIELDS, true) ? [] : '';
        }
    }
    foreach (JSON_REVIEW_FIELDS as $k) {
        if (!is_array($rev[$k])) {
            $rev[$k] = [];
        }
    }
    $rev['version'] = (int)$rev['version'];
    return $rev;
}

/** قراءة ملف Excel للنتائج وتحويله إلى مصفوفة مراجعات مفهرسة بمعرّف السجل. */
function reviews_parse_xlsx(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $reader = IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(true);
    $book = $reader->load($path);
    $ws = $book->getSheetByName(RESULTS_SHEET) ?? $book->getSheet(0);
    $rows = $ws->toArray(null, false, false, false);
    $book->disconnectWorksheets();
    if (!$rows) {
        return [];
    }
    $labelToKey = array_flip(REVIEW_COLUMNS);
    $idx = [];
    foreach ($rows[0] as $i => $h) {
        $h = trim((string)$h);
        if (isset($labelToKey[$h])) {
            $idx[$labelToKey[$h]] = $i;
        } elseif (isset(REVIEW_COLUMNS[$h])) {
            $idx[$h] = $i;
        }
    }
    if (!isset($idx['record_id'])) {
        throw new AppException('ملف نتائج التدقيق لا يحتوي عمود «معرّف سجل التشريع».');
    }
    $out = [];
    for ($r = 1, $n = count($rows); $r < $n; $r++) {
        $rid = trim((string)($rows[$r][$idx['record_id']] ?? ''));
        if ($rid === '') {
            continue;
        }
        $rev = [];
        foreach (REVIEW_COLUMNS as $k => $_) {
            $v = isset($idx[$k]) ? ($rows[$r][$idx[$k]] ?? null) : null;
            $v = $v === null ? '' : (string)$v;
            if (in_array($k, JSON_REVIEW_FIELDS, true)) {
                $d = $v === '' ? [] : json_decode($v, true);
                $v = is_array($d) ? $d : [];
            }
            $rev[$k] = $v;
        }
        $rev['version'] = (int)$rev['version'];
        $out[$rid] = normalize_review($rev);
    }
    return $out;
}

function reviews_write_xlsx(array $reviews, string $dest): void
{
    $book = new Spreadsheet();
    $book->getProperties()->setTitle(RESULTS_SHEET)->setCreator('نظام تدقيق التشريعات');
    $ws = $book->getActiveSheet();
    $ws->setTitle(RESULTS_SHEET);
    $ws->setRightToLeft(true);
    $col = 1;
    foreach (REVIEW_COLUMNS as $label) {
        $ws->setCellValueExplicit([$col++, 1], $label, DataType::TYPE_STRING);
    }
    $row = 2;
    foreach ($reviews as $rev) {
        $col = 1;
        foreach (REVIEW_COLUMNS as $k => $_) {
            $v = $rev[$k] ?? '';
            if (in_array($k, JSON_REVIEW_FIELDS, true)) {
                $v = $v ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
            }
            $v = (string)$v;
            if (mb_strlen($v) > MAX_CELL) {
                throw new ValidationException(['حجم الحقل «' . REVIEW_COLUMNS[$k] . '» يتجاوز الحد المسموح في خلية Excel.']);
            }
            $ws->setCellValueExplicit([$col++, $row], $v, DataType::TYPE_STRING);
        }
        $row++;
    }
    $ws->freezePane('A2');
    $writer = IOFactory::createWriter($book, 'Xlsx');
    $writer->save($dest);
    $book->disconnectWorksheets();
}

/** التحقق من أن الملف المكتوب قابل للقراءة ويحتوي العدد المتوقع من السجلات. */
function reviews_verify_xlsx(string $path, int $expected): void
{
    $reader = IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(true);
    $reader->setReadFilter(new class implements IReadFilter {
        public function readCell($columnAddress, $row, $worksheetName = ''): bool
        {
            return $columnAddress === 'A';
        }
    });
    $book = $reader->load($path);
    $ws = $book->getSheet(0);
    $n = 0;
    foreach ($ws->getColumnIterator('A', 'A') as $c) {
        foreach ($c->getCellIterator(2) as $cell) {
            if ((string)$cell->getValue() !== '') {
                $n++;
            }
        }
    }
    $book->disconnectWorksheets();
    if ($n !== $expected) {
        throw new AppException("فشل التحقق من ملف النتائج بعد الكتابة (المتوقع $expected، الموجود $n). لم يتم الحفظ.");
    }
}

/** قراءة النتائج (من الذاكرة المؤقتة إن كانت متزامنة مع ملف Excel، وإلا من Excel مباشرة). */
function reviews_read(): array
{
    $xlsx = reviews_xlsx_path();
    $stamp = file_stamp($xlsx);
    $cache = read_json(reviews_cache_path());
    if (is_array($cache) && ($cache['stamp'] ?? null) === $stamp) {
        return array_map('normalize_review', $cache['reviews'] ?? []);
    }
    $reviews = reviews_parse_xlsx($xlsx);
    try {
        write_json(reviews_cache_path(), ['stamp' => $stamp, 'reviews' => $reviews], false);
    } catch (Throwable $e) {
        app_log('warning', 'reviews cache write failed', ['e' => $e->getMessage()]);
    }
    return $reviews;
}

function reviews_all(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = reviews_read();
    }
    return $cache;
}

function review_get(string $recordId): ?array
{
    return reviews_all()[$recordId] ?? null;
}

/**
 * تنفيذ تعديل على النتائج بشكل آمن. الدالة $fn تستقبل المصفوفة بالمرجع وتعيد نتيجة.
 */
function reviews_mutate(callable $fn, array $journalCtx = [])
{
    return with_lock('reviews', function () use ($fn, $journalCtx) {
        $reviews = reviews_read();
        $result = $fn($reviews);
        $xlsx = reviews_xlsx_path();
        if (is_file($xlsx)) {
            backup_file($xlsx, 'backups/reviews', 'reviews', 300);
        }
        $tmp = $xlsx . '.tmp-' . bin2hex(random_bytes(4)) . '.xlsx';
        try {
            reviews_write_xlsx($reviews, $tmp);
            reviews_verify_xlsx($tmp, count($reviews));
            if (!@rename($tmp, $xlsx)) {
                throw new AppException('تعذر استبدال ملف النتائج.');
            }
        } catch (Throwable $e) {
            @unlink($tmp);
            app_log('error', 'reviews write failed', ['e' => $e->getMessage()] + $journalCtx);
            throw $e instanceof AppException ? $e : new AppException('تعذر حفظ نتائج التدقيق في ملف Excel: ' . $e->getMessage());
        }
        write_json(reviews_cache_path(), ['stamp' => file_stamp($xlsx), 'reviews' => $reviews], false);
        journal($journalCtx + ['ok' => true]);
        return $result;
    }, 25);
}

function review_blank(array $record): array
{
    $rev = array_fill_keys(array_keys(REVIEW_COLUMNS), '');
    foreach (JSON_REVIEW_FIELDS as $k) {
        $rev[$k] = [];
    }
    $rev['record_id'] = $record['id'];
    $rev['category'] = $record['category'];
    $rev['legislation'] = $record['name'];
    $rev['number'] = (string)($record['number'] ?? '');
    $rev['year'] = (string)($record['year'] ?? '');
    $rev['status'] = 'not_started';
    $rev['compare_diwan_id'] = (string)($record['diwan']['id'] ?? ($record['category'] === 'qistas_only' ? ($record['candidate']['id'] ?? '') : ''));
    $rev['compare_qistas_id'] = (string)($record['qistas']['id'] ?? ($record['category'] === 'diwan_only' ? ($record['candidate']['id'] ?? '') : ''));
    $rev['version'] = 0;
    return $rev;
}

function add_history(array &$rev, array $user, string $action, string $note = ''): void
{
    $rev['history'][] = ['at' => now_str(), 'by' => $user['id'], 'name' => $user['name'], 'action' => $action, 'note' => mb_substr($note, 0, 500)];
    while (count($rev['history']) > 1 && mb_strlen(json_encode($rev['history'], JSON_UNESCAPED_UNICODE)) > 20000) {
        array_shift($rev['history']);
    }
}

function review_status(?array $rev): string
{
    return $rev['status'] ?? 'not_started';
}

function approval_status(?array $rev): string
{
    return $rev['approval_status'] ?? '';
}

/** نسبة الإنجاز حسب الحالة: لم تبدأ 0%، قيد التنفيذ 50%، منتهية 100%. */
function review_progress(?array $rev): int
{
    return ['not_started' => 0, 'in_progress' => 50, 'done' => 100][review_status($rev)] ?? 0;
}

function can_edit_review(?array $rev, array $user): bool
{
    return $rev && !is_admin($user) && ($rev['volunteer_id'] ?? '') === $user['id']
        && in_array(review_status($rev), EDITABLE_STATUSES, true);
}

/* ===================== العمليات ===================== */

function review_start(string $recordId, array $user): void
{
    $record = record_get($recordId);
    if (!$record) {
        throw new ValidationException(['السجل غير موجود.']);
    }
    if (is_admin($user)) {
        throw new ValidationException(['المسؤول لا يبدأ مراجعات؛ يمكنه إعادة التخصيص لمتطوع.']);
    }
    reviews_mutate(function (array &$reviews) use ($record, $user) {
        $rev = $reviews[$record['id']] ?? review_blank($record);
        if (!empty($rev['volunteer_id']) && $rev['volunteer_id'] !== $user['id']) {
            throw new ConflictException('هذا التشريع قيد التنفيذ من قبل «' . $rev['volunteer_name'] . '». لا يمكن لمتطوعين العمل على المراجعة نفسها.');
        }
        if (review_status($rev) !== 'not_started') {
            return; // سبق أن بدأها المتطوع نفسه
        }
        $rev['status'] = 'in_progress';
        $rev['volunteer_id'] = $user['id'];
        $rev['volunteer_name'] = $user['name'];
        $rev['started_at'] = $rev['started_at'] ?: now_str();
        $rev['updated_at'] = now_str();
        $rev['version'] = (int)$rev['version'] + 1;
        add_history($rev, $user, 'بدء المراجعة');
        $reviews[$record['id']] = $rev;
    }, ['action' => 'start', 'record' => $recordId, 'user' => $user['id']]);
}

/** قراءة مدخلات النموذج والتحقق منها (بدون حفظ). في الوضع غير الصارم تُعاد القيم مع الأخطاء. */
function parse_review_input(array $record, array $post, bool $strict = true): array
{
    $errors = [];
    $cat = $record['category'];
    $in = [];
    $in['final_decision'] = (string)($post['final_decision'] ?? '');
    if ($in['final_decision'] !== '' && !decision_def($cat, $in['final_decision'])) {
        $errors[] = 'القرار النهائي غير صالح لهذه الفئة.';
        if ($strict) {
            $in['final_decision'] = '';
        }
    }
    $notes = trim(str_replace("\r", '', (string)($post['reviewer_notes'] ?? '')));
    if (mb_strlen($notes) > 8000) {
        $errors[] = 'ملاحظات المراجع أطول من 8000 حرف.';
        $notes = mb_substr($notes, 0, 8000);
    }
    $in['reviewer_notes'] = $notes;
    $diffs = [];
    $dd = (array)($post['diff_diwan'] ?? []);
    $dq = (array)($post['diff_qistas'] ?? []);
    $dt = (array)($post['diff_type'] ?? []);
    $dn = (array)($post['diff_note'] ?? []);
    foreach ($dn as $i => $note) {
        $note = trim(str_replace("\r", '', (string)$note));
        $a = mb_substr(trim((string)($dd[$i] ?? '')), 0, 40);
        $b = mb_substr(trim((string)($dq[$i] ?? '')), 0, 40);
        $t = (string)($dt[$i] ?? 'other');
        if ($note === '' && $a === '' && $b === '') {
            continue;
        }
        if (!isset(DIFF_TYPES[$t])) {
            $t = 'other';
        }
        if (mb_strlen($note) > 1500) {
            $errors[] = 'وصف أحد الاختلافات أطول من 1500 حرف.';
            $note = mb_substr($note, 0, 1500);
        }
        $diffs[] = ['diwan_article' => $a, 'qistas_article' => $b, 'type' => $t, 'note' => $note];
    }
    if (count($diffs) > 120) {
        $errors[] = 'عدد الاختلافات كبير جداً (الحد 120)؛ يرجى تجميعها.';
    }
    $in['content_differences'] = array_slice($diffs, 0, 120);
    if (mb_strlen(json_encode($in['content_differences'], JSON_UNESCAPED_UNICODE)) > MAX_CELL) {
        $errors[] = 'حجم بيانات «الاختلافات في المحتوى» كبير جداً.';
    }
    $in['approved_values'] = parse_approved_values($record, $post, $errors);
    if (mb_strlen(json_encode($in['approved_values'], JSON_UNESCAPED_UNICODE)) > MAX_CELL) {
        $errors[] = 'حجم «القيم المعتمدة» كبير جداً.';
    }
    foreach (['compare_diwan_id', 'compare_qistas_id'] as $k) {
        $v = trim((string)($post[$k] ?? ''));
        if ($v !== '' && !ctype_digit($v)) {
            $errors[] = 'معرّف المقارنة يجب أن يكون رقماً.';
            $v = '';
        }
        $in[$k] = $v;
    }
    if ($errors && $strict) {
        throw new ValidationException($errors);
    }
    if (!$strict) {
        $in['_errors'] = $errors;
    }
    return $in;
}

/** شرط الإرسال الوحيد: اختيار القرار النهائي (الملاحظات واعتماد القيم اختيارية). */
function submit_errors(array $record, array $rev): array
{
    return empty($rev['final_decision']) ? ['اختر القرار النهائي.'] : [];
}

function review_save(string $recordId, array $user, array $post, bool $submit): array
{
    $record = record_get($recordId);
    if (!$record) {
        throw new ValidationException(['السجل غير موجود.']);
    }
    $in = parse_review_input($record, $post);
    $clientVersion = (int)($post['version'] ?? -1);
    return reviews_mutate(function (array &$reviews) use ($record, $user, $in, $submit, $clientVersion) {
        $rev = $reviews[$record['id']] ?? null;
        if (!$rev || ($rev['volunteer_id'] ?? '') !== $user['id']) {
            throw new ConflictException('لا تملك صلاحية تعديل هذه المراجعة (قد تكون محجوزة لمتطوع آخر أو تم تحريرها من قبل المسؤول).');
        }
        if (!in_array(review_status($rev), EDITABLE_STATUSES, true)) {
            throw new ConflictException('لا يمكن تعديل المراجعة في حالتها الحالية: ' . STATUSES[review_status($rev)] . '.');
        }
        if ((int)$rev['version'] !== $clientVersion) {
            throw new ConflictException('تم تعديل هذه المراجعة من نافذة أو جهاز آخر بعد فتحك للصفحة. لم يتم استبدال التعديلات السابقة؛ يرجى تحديث الصفحة ثم إعادة إدخال تغييراتك.');
        }
        foreach ($in as $k => $v) {
            $rev[$k] = $v;
        }
        $rev['legislation'] = $record['name'];
        $rev['updated_at'] = now_str();
        if ($submit) {
            $errs = submit_errors($record, $rev);
            if ($errs) {
                throw new ValidationException($errs);
            }
            $rev['status'] = 'done';
            $rev['submitted_at'] = now_str();
            $rev['approval_status'] = 'pending';
            $rev['approved_at'] = '';
            $rev['approved_by'] = '';
            $unres = unresolved_fields($record, $rev);
            add_history($rev, $user, 'إنهاء المراجعة وإرسالها للمسؤول', decision_label($record['category'], $rev['final_decision'])
                . ($unres ? ' — حقول غير محسومة: ' . unresolved_labels($unres) : ''));
        } else {
            add_history($rev, $user, 'حفظ مسودة');
        }
        $rev['version'] = (int)$rev['version'] + 1;
        $reviews[$record['id']] = $rev;
        return $rev;
    }, ['action' => $submit ? 'submit' : 'save', 'record' => $recordId, 'user' => $user['id']]);
}

/** تطبيق الاعتماد على مراجعة واحدة داخل عملية كتابة. يعيد false إذا لم تكن مؤهلة. */
function apply_approve(array &$rev, array $admin, string $notes): bool
{
    if (review_status($rev) !== 'done' || approval_status($rev) !== 'pending') {
        return false;
    }
    $rev['approval_status'] = 'approved';
    $rev['approved_at'] = now_str();
    $rev['approved_by'] = $admin['name'];
    if ($notes !== '') {
        $rev['admin_notes'] = $notes;
    }
    $rev['updated_at'] = now_str();
    $rev['version'] = (int)$rev['version'] + 1;
    add_history($rev, $admin, 'اعتماد النتيجة', $notes);
    return true;
}

function admin_action(string $recordId, array $admin, string $action, string $notes, ?string $targetUserId = null): void
{
    if (!is_admin($admin)) {
        throw new ValidationException(['صلاحية غير كافية.']);
    }
    $record = record_get($recordId);
    if (!$record) {
        throw new ValidationException(['السجل غير موجود.']);
    }
    $notes = trim(str_replace("\r", '', $notes));
    if (mb_strlen($notes) > 4000) {
        throw new ValidationException(['ملاحظات المسؤول أطول من 4000 حرف.']);
    }
    $target = null;
    if ($action === 'reassign') {
        $target = $targetUserId ? user_find($targetUserId) : null;
        if (!$target || $target['role'] !== 'volunteer' || empty($target['active'])) {
            throw new ValidationException(['اختر متطوعاً فعّالاً لإعادة التخصيص.']);
        }
    }
    if ($action === 'return' && $notes === '') {
        throw new ValidationException(['اكتب ملاحظة توضح للمتطوع المطلوب تصحيحه.']);
    }
    reviews_mutate(function (array &$reviews) use ($record, $admin, $action, $notes, $target) {
        $rev = $reviews[$record['id']] ?? review_blank($record);
        $st = review_status($rev);
        $ap = approval_status($rev);
        switch ($action) {
            case 'approve':
                if (!apply_approve($rev, $admin, $notes)) {
                    throw new ConflictException('يمكن اعتماد المراجعات المنتهية التي بانتظار الموافقة فقط.');
                }
                $reviews[$record['id']] = $rev;
                return;
            case 'return':
                if ($st !== 'done') {
                    throw new ConflictException('يمكن إعادة المراجعات المنتهية فقط.');
                }
                if (empty($rev['volunteer_id'])) {
                    throw new ConflictException('لا يوجد متطوع مرتبط بهذه المراجعة؛ استخدم إعادة التخصيص.');
                }
                $rev['status'] = 'in_progress';
                $rev['approval_status'] = 'returned';
                $rev['approved_at'] = '';
                $rev['approved_by'] = '';
                $label = 'إعادة للتصحيح';
                break;
            case 'release':
                if ($ap === 'approved') {
                    throw new ConflictException('لا يمكن تحرير مراجعة معتمدة؛ أعدها للتصحيح أولاً.');
                }
                if (empty($rev['volunteer_id'])) {
                    throw new ConflictException('السجل غير محجوز لأي متطوع.');
                }
                $label = 'تحرير من حجز «' . $rev['volunteer_name'] . '»';
                $rev['status'] = 'not_started';
                $rev['volunteer_id'] = '';
                $rev['volunteer_name'] = '';
                $rev['approval_status'] = '';
                break;
            case 'reassign':
                if ($ap === 'approved') {
                    throw new ConflictException('لا يمكن إعادة تخصيص مراجعة معتمدة.');
                }
                $label = 'إعادة تخصيص إلى «' . $target['name'] . '»' . (!empty($rev['volunteer_name']) ? ' بدلاً من «' . $rev['volunteer_name'] . '»' : '');
                $rev['volunteer_id'] = $target['id'];
                $rev['volunteer_name'] = $target['name'];
                $rev['status'] = 'in_progress';
                if ($st === 'done') {
                    $rev['approval_status'] = 'returned';
                }
                $rev['started_at'] = $rev['started_at'] ?: now_str();
                break;
            default:
                throw new ValidationException(['إجراء غير معروف.']);
        }
        if ($notes !== '') {
            $rev['admin_notes'] = $notes;
        }
        $rev['updated_at'] = now_str();
        $rev['version'] = (int)$rev['version'] + 1;
        add_history($rev, $admin, $label, $notes);
        $reviews[$record['id']] = $rev;
    }, ['action' => 'admin_' . $action, 'record' => $recordId, 'user' => $admin['id']]);
}

/**
 * اعتماد جماعي: كل المراجعات المحددة تُعتمد في عملية كتابة واحدة إلى Excel
 * (قفل ← نسخة احتياطية ← كتابة ← تحقق ← استبدال ذري). إما أن تُحفظ كلها أو لا يُحفظ شيء.
 * المراجعات التي تغيّرت حالتها قبل التنفيذ تُتجاهل وتُذكر في النتيجة.
 * @return array{approved: string[], skipped: string[]}
 */
function bulk_approve(array $recordIds, array $admin): array
{
    if (!is_admin($admin)) {
        throw new ValidationException(['صلاحية غير كافية.']);
    }
    $ids = array_values(array_unique(array_filter(array_map('strval', $recordIds), fn($x) => preg_match('/^[MDQ]-[0-9]+(-[0-9]+)?$/', $x))));
    if (!$ids) {
        throw new ValidationException(['لم يتم تحديد أي تشريع.']);
    }
    if (count($ids) > 2000) {
        throw new ValidationException(['عدد كبير جداً في عملية واحدة (الحد 2000).']);
    }
    return reviews_mutate(function (array &$reviews) use ($ids, $admin) {
        $approved = [];
        $skipped = [];
        foreach ($ids as $id) {
            if (!isset($reviews[$id])) {
                $skipped[] = $id;
                continue;
            }
            $rev = $reviews[$id];
            if (apply_approve($rev, $admin, '')) {
                $reviews[$id] = $rev;
                $approved[] = $id;
            } else {
                $skipped[] = $id;
            }
        }
        if (!$approved) {
            // لا شيء مؤهل: نلغي العملية دون أي كتابة
            throw new ConflictException('لم يُعتمد أي تشريع: التشريعات المحددة لم تعد بانتظار الموافقة.');
        }
        return ['approved' => $approved, 'skipped' => $skipped];
    }, ['action' => 'bulk_approve', 'count' => count($ids), 'user' => $admin['id']]);
}

/** استعادة نسخة احتياطية من ملف النتائج (مع حفظ النسخة الحالية أولاً). */
function reviews_restore_backup(string $name, array $admin): int
{
    if (!preg_match('/^reviews_[0-9_a-f]+\.xlsx$/', $name)) {
        throw new ValidationException(['اسم نسخة غير صالح.']);
    }
    $src = data_path('backups/reviews/' . $name);
    if (!is_file($src)) {
        throw new ValidationException(['النسخة غير موجودة.']);
    }
    $parsed = reviews_parse_xlsx($src); // التحقق من صلاحية النسخة قبل الاستعادة
    return with_lock('reviews', function () use ($src, $parsed, $admin, $name) {
        $xlsx = reviews_xlsx_path();
        if (is_file($xlsx)) {
            backup_file($xlsx, 'backups/reviews', 'reviews', 300);
        }
        $tmp = $xlsx . '.tmp-' . bin2hex(random_bytes(4)) . '.xlsx';
        if (!@copy($src, $tmp) || !@rename($tmp, $xlsx)) {
            @unlink($tmp);
            throw new AppException('تعذر استعادة النسخة.');
        }
        write_json(reviews_cache_path(), ['stamp' => file_stamp($xlsx), 'reviews' => $parsed], false);
        journal(['action' => 'restore', 'backup' => $name, 'user' => $admin['id'], 'ok' => true]);
        return count($parsed);
    });
}
