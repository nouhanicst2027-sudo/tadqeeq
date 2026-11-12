<?php
/**
 * «القيمة المعتمدة» لحقول بيانات التشريع: المتطوع يختار لكل حقل قيمة الديوان أو قسطاس أو يكتب قيمة يدوية.
 * القيم تُحفظ مع نتيجة التدقيق فقط؛ ملف المطابقة الأصلي لا يُعدَّل.
 */
declare(strict_types=1);

const VALUE_FIELDS = [
    'name' => 'الاسم',
    'number' => 'الرقم',
    'year' => 'السنة',
    'magazine' => 'عدد الجريدة الرسمية',
    'page' => 'الصفحة',
    'magazine_date' => 'تاريخ الجريدة',
    'type' => 'النوع',
    'status' => 'الحالة',
    'active_date' => 'تاريخ النفاذ',
    'parent' => 'النظام الأصلي (للتعديل)',
];

/** حقول لها دوائر الاعتماد لكنها لا تُحسب ضمن «غير المحسوم» (قسطاس لا يحمل هذا الحقل أصلاً). */
const UNRESOLVED_SKIP = ['parent'];

/** ربط الحقل بعلامة التطابق الموجودة في ملف المطابقة (للعرض فقط). */
const FIELD_EXCEL_FLAGS = ['number' => 'number_match', 'year' => 'year_match', 'magazine' => 'magazine_match', 'page' => 'page_match'];

const VALUE_SOURCES = ['diwan' => 'الديوان', 'qistas' => 'قسطاس', 'manual' => 'إدخال يدوي'];

function fv_norm_date($v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    $v = trim((string)$v);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
        return "$m[1]-$m[2]-$m[3]";
    }
    if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $v, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    return $v;
}

function fv_norm_status($v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    $map = ['Active' => 'ساري', 'Not Active' => 'غير ساري', 'Inactive' => 'غير ساري'];
    return $map[trim((string)$v)] ?? trim((string)$v);
}

function fv_str($v): ?string
{
    if ($v === null) {
        return null;
    }
    $s = trim((string)$v);
    return $s === '' ? null : $s;
}

/**
 * قيم الحقول من المصدرين. null للمصدر = لا يوجد سجل في هذا المصدر.
 * @return array<string, array{label:string, diwan:?string, qistas:?string, has_diwan:bool, has_qistas:bool, equal:bool}>
 */
function field_values(array $record): array
{
    $d = $record['diwan'];
    $q = $record['qistas'];
    $dm = $d ? articles_meta('diwan', $d['id']) : null;
    $qm = $q ? articles_meta('qistas', $q['id']) : null;
    $dv = $d ? [
        'name' => fv_str($d['name']), 'number' => fv_str($d['number']), 'year' => fv_str($d['year']),
        'magazine' => fv_str($d['magazine']), 'page' => fv_str($d['page']),
        'magazine_date' => fv_norm_date($dm['magazine_date'] ?? null), 'type' => fv_str($d['type']),
        'status' => fv_norm_status($dm['status'] ?? null), 'active_date' => fv_norm_date($dm['active_date'] ?? null),
        'parent' => fv_str($d['parent']),
    ] : null;
    $qv = $q ? [
        'name' => fv_str($q['name']), 'number' => fv_str($q['number']), 'year' => fv_str($q['year']),
        'magazine' => fv_str($q['magazine']), 'page' => fv_str($q['page']),
        'magazine_date' => fv_norm_date($qm['magazine_date'] ?? null), 'type' => fv_str($q['type']),
        'status' => fv_norm_status($q['status'] ?? ($qm['status'] ?? null)), 'active_date' => fv_norm_date($qm['active_date'] ?? null),
        'parent' => null,
    ] : null;
    $out = [];
    foreach (VALUE_FIELDS as $k => $label) {
        $a = $dv[$k] ?? null;
        $b = $qv[$k] ?? null;
        $out[$k] = [
            'label' => $label,
            'diwan' => $a,
            'qistas' => $b,
            'has_diwan' => $dv !== null,
            'has_qistas' => $qv !== null,
            // متطابق فقط إذا كان السجل في المصدرين والقيمتان غير فارغتين ومتساويتان
            'equal' => $dv !== null && $qv !== null && $a !== null && $b !== null && $a === $b,
        ];
    }
    return $out;
}

/**
 * الحقول غير المحسومة: قيمة مختلفة بين المصدرين أو فارغة، ولم يحدد المتطوع قيمة معتمدة.
 * @return string[] مفاتيح الحقول
 */
function unresolved_fields(array $record, ?array $rev, ?array $fv = null): array
{
    $fv = $fv ?? field_values($record);
    $av = $rev['approved_values'] ?? [];
    $out = [];
    foreach ($fv as $k => $f) {
        if ($f['equal'] || in_array($k, UNRESOLVED_SKIP, true) || (($av[$k]['src'] ?? '') !== '')) {
            continue;
        }
        $both = $f['has_diwan'] && $f['has_qistas'];
        $present = $f['has_diwan'] ? $f['diwan'] : $f['qistas'];
        if ($both || $present === null) {
            $out[] = $k;
        }
    }
    return $out;
}

/**
 * تحويل مدخلات النموذج إلى اعتماد لكل حقل: {src: diwan|qistas|manual|'', value, wrong: [مصادر عُلّمت خطأ], card}.
 * قيمة المصدر تؤخذ من الخادم وليس من المتصفح.
 */
function parse_approved_values(array $record, array $post, array &$errors): array
{
    $fv = field_values($record);
    $srcIn = (array)($post['av_src'] ?? []);
    $valIn = (array)($post['av_val'] ?? []);
    $wrongIn = (array)($post['av_wrong'] ?? []);
    $cardIn = (array)($post['av_card'] ?? []);
    $out = [];
    foreach ($fv as $k => $f) {
        $present = array_values(array_filter(['diwan', 'qistas'], fn($s) => $f['has_' . $s]));
        $wrong = array_values(array_intersect($present, array_map('trim', explode(',', (string)($wrongIn[$k] ?? '')))));
        $s = (string)($srcIn[$k] ?? '');
        $entry = null;
        if (($s === 'diwan' || $s === 'qistas') && $f['has_' . $s] && $f[$s] !== null) {
            $other = $s === 'diwan' ? 'qistas' : 'diwan';
            $wrong = array_values(array_diff($wrong, [$s]));
            if ($f['has_' . $other] && $f[$other] !== $f[$s] && !in_array($other, $wrong, true)) {
                $wrong[] = $other;
            }
            $entry = ['src' => $s, 'value' => $f[$s], 'wrong' => $wrong];
        } elseif ($s === 'manual') {
            $v = trim(str_replace(["\r", "\n"], ' ', (string)($valIn[$k] ?? '')));
            if (mb_strlen($v) > 500) {
                $errors[] = 'القيمة الصحيحة لحقل «' . $f['label'] . '» أطول من 500 حرف.';
                $v = mb_substr($v, 0, 500);
            }
            if ($v !== '') {
                $card = in_array($cardIn[$k] ?? '', $present, true) ? $cardIn[$k] : ($present[0] ?? 'diwan');
                $entry = ['src' => 'manual', 'value' => $v, 'wrong' => array_values(array_filter($present, fn($x) => $f[$x] !== $v)), 'card' => $card];
            }
        }
        if (!$entry && $wrong) {
            $entry = ['src' => '', 'value' => '', 'wrong' => $wrong];
        }
        if ($entry) {
            $out[$k] = $entry;
        }
    }
    return $out;
}

function unresolved_labels(array $keys): string
{
    return implode('، ', array_map(fn($k) => VALUE_FIELDS[$k] ?? $k, $keys));
}

/**
 * أخطاء كل مصدر حسب اختيارات المتطوع: الحقل خاطئ في مصدر إذا اعتُمدت قيمة مختلفة عن قيمته (أو كان فارغاً).
 * @return array{diwan: string[], qistas: string[]}
 */
function source_errors(array $record, ?array $rev, ?array $fv = null): array
{
    $fv = $fv ?? field_values($record);
    $av = $rev['approved_values'] ?? [];
    $err = ['diwan' => [], 'qistas' => []];
    foreach ($fv as $k => $f) {
        $a = $av[$k] ?? null;
        if (!$a) {
            continue;
        }
        $bad = $a['wrong'] ?? [];
        if (($a['src'] ?? '') !== '' && !$f['equal']) {
            foreach (['diwan', 'qistas'] as $s) {
                if ($f['has_' . $s] && $f[$s] !== $a['value']) {
                    $bad[] = $s;
                }
            }
        }
        foreach (array_unique($bad) as $s) {
            if (isset($err[$s])) {
                $err[$s][] = $f['label'];
            }
        }
    }
    return $err;
}

/** عدد مواد كل مصدر من فهرس المواد (أو من ملف المطابقة). */
function source_article_count(array $record, string $source): ?int
{
    if (!$record[$source]) {
        return null;
    }
    $m = articles_meta($source, $record[$source]['id']);
    if ($m) {
        return (int)$m['count'];
    }
    $c = $record[$source]['articles_count'] ?? null;
    return $c === null ? null : (int)$c;
}
