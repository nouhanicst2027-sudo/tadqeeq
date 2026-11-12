<?php
/**
 * إعدادات المشروع (ملف JSON): نطاق التدقيق، القرارات النهائية، طرق البحث.
 * القيم الافتراضية مأخوذة من إجراءات التدقيق المعتمدة (SOP).
 */
declare(strict_types=1);

const CATEGORIES = [
    'matched'     => 'متطابق',
    'diwan_only'  => 'موجود في الديوان فقط',
    'qistas_only' => 'موجود في قسطاس فقط',
];

const STATUSES = [
    'not_started' => 'لم تبدأ',
    'in_progress' => 'قيد التنفيذ',
    'done'        => 'منتهية',
];

/** الحالة الوحيدة التي يستطيع فيها المتطوع المالك تعديل المراجعة. */
const EDITABLE_STATUSES = ['in_progress'];

const APPROVAL_STATUSES = [
    ''         => '—',
    'pending'  => 'بانتظار موافقة المسؤول',
    'approved' => 'معتمد',
    'returned' => 'أعيد للتصحيح',
];

const SETTINGS_SCHEMA = 2;

const DIFF_TYPES = [
    'missing_in_qistas' => 'مادة موجودة في الديوان وغير موجودة في قسطاس',
    'missing_in_diwan'  => 'مادة موجودة في قسطاس وغير موجودة في الديوان',
    'text_difference'   => 'اختلاف في نص المادة',
    'amendment'         => 'اختلاف ناتج عن تعديل أو إلغاء',
    'version'           => 'اختلاف في نسخة النظام',
    'metadata'          => 'اختلاف في البيانات الوصفية',
    'legal_review'      => 'تحتاج إلى مراجعة قانونية',
    'other'             => 'أخرى',
];

function default_settings(): array
{
    return [
        'schema' => SETTINGS_SCHEMA,
        'project_title' => 'نظام تدقيق التشريعات الأردنية',
        'scope' => [
            'enabled'  => true,
            'min_year' => null,
            'max_year' => 1960,
            // base = سنة صدور التشريع الأصلي (للتعديلات تؤخذ سنة النظام الأصلي) وفق SOP
            'basis'    => 'base',
        ],
        // علامة full = تطابق/وجود كامل (ملاحظات المراجع اختيارية)؛ غير ذلك الملاحظات إجبارية
        'decisions' => [
            'matched' => [
                ['key' => 'confirmed_match', 'label' => 'تطابق مؤكد', 'flags' => ['full']],
                ['key' => 'partial_match', 'label' => 'تطابق جزئي', 'flags' => []],
                ['key' => 'not_matched', 'label' => 'غير متطابق', 'flags' => []],
                ['key' => 'needs_investigation', 'label' => 'يحتاج إلى تحقيق إضافي', 'flags' => []],
            ],
            'diwan_only' => [
                ['key' => 'found_in_qistas', 'label' => 'موجود في قسطاس', 'flags' => ['full']],
                ['key' => 'found_partial', 'label' => 'موجود جزئياً أو باختلاف', 'flags' => []],
                ['key' => 'missing_in_qistas', 'label' => 'غير موجود في قسطاس', 'flags' => []],
                ['key' => 'needs_investigation', 'label' => 'يحتاج إلى تحقيق إضافي', 'flags' => []],
            ],
            'qistas_only' => [
                ['key' => 'found_in_diwan', 'label' => 'موجود في الديوان', 'flags' => ['full']],
                ['key' => 'found_partial', 'label' => 'موجود جزئياً أو باختلاف', 'flags' => []],
                ['key' => 'missing_in_diwan', 'label' => 'غير موجود في الديوان', 'flags' => []],
                ['key' => 'needs_investigation', 'label' => 'يحتاج إلى تحقيق إضافي', 'flags' => []],
            ],
        ],
    ];
}

function settings(): array
{
    static $cache = null;
    if ($cache === null) {
        $saved = read_json(data_path('settings.json'), []);
        $saved = is_array($saved) ? $saved : [];
        if (($saved['schema'] ?? 1) < SETTINGS_SCHEMA) {
            // إعدادات من النسخة السابقة: نحتفظ بالنطاق والعنوان ونعتمد خيارات القرار الجديدة
            unset($saved['decisions'], $saved['search_methods']);
        }
        unset($saved['search_methods']);
        $cache = array_replace(default_settings(), $saved);
        $cache['schema'] = SETTINGS_SCHEMA;
        foreach (['decisions'] as $k) {
            foreach (array_keys(CATEGORIES) as $c) {
                if (empty($cache[$k][$c])) {
                    $cache[$k][$c] = default_settings()[$k][$c];
                }
            }
        }
    }
    return $cache;
}

function save_settings(array $s): void
{
    $s['schema'] = SETTINGS_SCHEMA;
    with_lock('settings', function () use ($s) {
        backup_file(data_path('settings.json'), 'backups/users', 'settings', 50);
        write_json(data_path('settings.json'), $s);
    });
}

function decisions_for(string $category): array
{
    return settings()['decisions'][$category] ?? [];
}

function decision_def(string $category, ?string $key): ?array
{
    foreach (decisions_for($category) as $d) {
        if ($d['key'] === $key) {
            return $d;
        }
    }
    return null;
}

function decision_label(string $category, ?string $key): string
{
    if (!$key) {
        return '—';
    }
    $d = decision_def($category, $key);
    return $d ? $d['label'] : $key;
}

/** هل القرار يعني تطابقاً/وجوداً كاملاً؟ (عندها ملاحظات المراجع اختيارية) */
function decision_is_full(string $category, ?string $key): bool
{
    $d = decision_def($category, $key);
    return $d && in_array('full', $d['flags'], true);
}

/** تحويل الخيارات إلى نص قابل للتعديل: سطر لكل خيار  «المفتاح | التسمية | علامات» */
function options_to_text(array $items, string $kind): string
{
    $lines = [];
    foreach ($items as $it) {
        if ($kind === 'decisions') {
            $lines[] = $it['key'] . ' | ' . $it['label'] . (empty($it['flags']) ? '' : ' | ' . implode(',', $it['flags']));
        } else {
            $lines[] = $it['key'] . ' | ' . $it['label'] . (!empty($it['required']) ? ' | إلزامي' : '');
        }
    }
    return implode("\n", $lines);
}

function text_to_options(string $text, string $kind): array
{
    $out = [];
    $seen = [];
    $errors = [];
    foreach (preg_split('/\R/u', $text) as $i => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        $key = $parts[0] ?? '';
        $label = $parts[1] ?? '';
        if (!preg_match('/^[a-z0-9_]{2,40}$/', $key) || $label === '') {
            $errors[] = 'السطر ' . ($i + 1) . ': يجب أن يكون بالشكل «مفتاح_لاتيني | التسمية».';
            continue;
        }
        if (isset($seen[$key])) {
            $errors[] = 'المفتاح مكرر: ' . $key;
            continue;
        }
        $seen[$key] = true;
        $extra = $parts[2] ?? '';
        if ($kind === 'decisions') {
            $flags = array_values(array_intersect(array_map('trim', explode(',', $extra)), ['full']));
            $out[] = ['key' => $key, 'label' => mb_substr($label, 0, 200), 'flags' => $flags];
        } else {
            $out[] = ['key' => $key, 'label' => mb_substr($label, 0, 300), 'required' => in_array($extra, ['إلزامي', 'required', 'الزامي'], true)];
        }
    }
    if (!$out) {
        $errors[] = 'يجب إدخال خيار واحد على الأقل.';
    }
    if ($errors) {
        throw new ValidationException($errors);
    }
    return $out;
}
