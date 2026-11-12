<?php
/**
 * طبقة التخزين: مسارات البيانات، الأقفال، الكتابة الآمنة، النسخ الاحتياطي، السجلات.
 * لا توجد قاعدة بيانات؛ كل شيء ملفات (Excel + JSON) داخل مجلد data.
 */
declare(strict_types=1);

class AppException extends RuntimeException {}
class LockException extends AppException {}
class ConflictException extends AppException {}
class ValidationException extends AppException
{
    public array $errors;
    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct(implode(' — ', $errors));
    }
}

function data_path(string $rel = ''): string
{
    return rtrim(DATA_DIR, '/') . ($rel === '' ? '' : '/' . ltrim($rel, '/'));
}

function ensure_data_dirs(): void
{
    $dirs = ['', 'source', 'index', 'cache', 'reviews', 'backups/reviews', 'backups/source',
             'backups/users', 'locks', 'logs', 'exports', 'incoming', 'tmp'];
    foreach ($dirs as $d) {
        $p = data_path($d);
        if (!is_dir($p) && !@mkdir($p, 0775, true) && !is_dir($p)) {
            throw new AppException('تعذر إنشاء مجلد البيانات: ' . $p);
        }
    }
    $ht = data_path('.htaccess');
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
}

/**
 * تنفيذ دالة تحت قفل حصري على مستوى الملفات (flock).
 * يمنع عمليات الحفظ المتزامنة؛ وإذا تعذر الحصول على القفل خلال المهلة تُرفع رسالة واضحة.
 */
function with_lock(string $name, callable $fn, float $timeout = 20.0)
{
    $path = data_path('locks/' . preg_replace('/[^a-z0-9_\-]/i', '_', $name) . '.lock');
    $fh = @fopen($path, 'c');
    if (!$fh) {
        throw new LockException('تعذر فتح ملف القفل. تحقق من صلاحيات مجلد data.');
    }
    $start = microtime(true);
    while (!flock($fh, LOCK_EX | LOCK_NB)) {
        if (microtime(true) - $start > $timeout) {
            fclose($fh);
            throw new LockException('الملف مستخدم حالياً من عملية أخرى. يرجى إعادة المحاولة بعد لحظات.');
        }
        usleep(150000);
    }
    try {
        ftruncate($fh, 0);
        fwrite($fh, (string)getmypid() . ' ' . date('c'));
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** كتابة ذرية: ملف مؤقت في نفس المجلد ثم rename، فلا يبقى ملف نصف مكتوب أبداً. */
function atomic_write(string $path, string $data): void
{
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    $ok = @file_put_contents($tmp, $data, LOCK_EX);
    if ($ok === false || $ok !== strlen($data)) {
        @unlink($tmp);
        throw new AppException('تعذر الكتابة إلى الملف: ' . basename($path));
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new AppException('تعذر استبدال الملف: ' . basename($path));
    }
}

function read_json(string $path, $default = null)
{
    if (!is_file($path)) {
        return $default;
    }
    $s = file_get_contents($path);
    if ($s === false) {
        throw new AppException('تعذر قراءة الملف: ' . basename($path));
    }
    if ($s === '') {
        return $default;
    }
    $d = json_decode($s, true);
    if ($d === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new AppException('ملف بيانات تالف: ' . basename($path));
    }
    return $d;
}

function write_json(string $path, $data, bool $pretty = true): void
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    atomic_write($path, json_encode($data, $flags));
}

/** نسخة احتياطية مع حذف الأقدم بعد تجاوز الحد. */
function backup_file(string $src, string $dir, string $prefix, int $keep = 300): ?string
{
    if (!is_file($src)) {
        return null;
    }
    $dirPath = data_path($dir);
    if (!is_dir($dirPath)) {
        @mkdir($dirPath, 0775, true);
    }
    $ext = pathinfo($src, PATHINFO_EXTENSION);
    $dest = $dirPath . '/' . $prefix . '_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . ($ext ? '.' . $ext : '');
    if (!@copy($src, $dest)) {
        throw new AppException('تعذر إنشاء النسخة الاحتياطية قبل التعديل؛ أُلغيت العملية حفاظاً على البيانات.');
    }
    $files = glob($dirPath . '/' . $prefix . '_*') ?: [];
    if (count($files) > $keep) {
        sort($files);
        foreach (array_slice($files, 0, count($files) - $keep) as $old) {
            @unlink($old);
        }
    }
    return $dest;
}

function list_backups(string $dir): array
{
    $files = glob(data_path($dir) . '/*') ?: [];
    $out = [];
    foreach ($files as $f) {
        if (is_file($f)) {
            $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
        }
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

function app_log(string $level, string $msg, array $ctx = []): void
{
    $line = json_encode(['t' => date('c'), 'level' => $level, 'msg' => $msg, 'ctx' => $ctx], JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents(data_path('logs/app.log'), $line, FILE_APPEND | LOCK_EX);
}

/** سجل عمليات إلحاقي لكل تعديل على نتائج التدقيق (يساعد على الاسترجاع ولا يُمسح). */
function journal(array $entry): void
{
    $entry['t'] = date('c');
    @file_put_contents(data_path('logs/journal.jsonl'), json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

function now_str(): string
{
    return date('Y-m-d H:i:s');
}

function human_size(int $bytes): string
{
    $u = ['بايت', 'ك.ب', 'م.ب', 'ج.ب'];
    $i = 0;
    $v = (float)$bytes;
    while ($v >= 1024 && $i < 3) {
        $v /= 1024;
        $i++;
    }
    return round($v, $i ? 1 : 0) . ' ' . $u[$i];
}

/** توحيد النص العربي لأغراض البحث فقط (لا يُستخدم لأي مطابقة آلية). */
function ar_normalize(string $s): string
{
    $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
    $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي']);
    $s = mb_strtolower($s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}
