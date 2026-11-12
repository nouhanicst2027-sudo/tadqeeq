<?php
/** الصفحة الرابعة: إدارة النظام والملفات (للمسؤول فقط). */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$user = require_admin();
$tab = (string)($_GET['tab'] ?? 'files');
$tabs = ['files' => 'إدارة الملفات', 'users' => 'إدارة المتطوعين', 'reviews' => 'إدارة عمليات التدقيق', 'export' => 'التصدير والنسخ الاحتياطية', 'settings' => 'إعدادات التدقيق'];
if (!isset($tabs[$tab])) $tab = 'files';

function upload_error_text(int $code): string
{
    return [
        UPLOAD_ERR_INI_SIZE => 'حجم الملف أكبر من الحد المسموح في إعدادات PHP (upload_max_filesize). استخدم الاستيراد من مجلد data/incoming للملفات الكبيرة.',
        UPLOAD_ERR_FORM_SIZE => 'حجم الملف أكبر من المسموح.',
        UPLOAD_ERR_PARTIAL => 'تم رفع جزء من الملف فقط. أعد المحاولة.',
        UPLOAD_ERR_NO_FILE => 'لم يتم اختيار ملف.',
        UPLOAD_ERR_NO_TMP_DIR => 'مجلد الملفات المؤقتة غير موجود على الخادم.',
        UPLOAD_ERR_CANT_WRITE => 'تعذر كتابة الملف على القرص.',
    ][$code] ?? 'تعذر رفع الملف.';
}

function uploaded(string $field, array $exts): array
{
    if (empty($_FILES[$field]) && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        throw new ValidationException(['حجم الطلب أكبر من post_max_size في إعدادات PHP.']);
    }
    $f = $_FILES[$field] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        throw new ValidationException([upload_error_text($f['error'] ?? UPLOAD_ERR_NO_FILE)]);
    }
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $exts, true)) {
        throw new ValidationException(['امتداد الملف غير مدعوم. المسموح: ' . implode('، ', $exts)]);
    }
    return [$f['tmp_name'], basename((string)$f['name'])];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' || (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0)) {
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('error', 'حجم الملف المرفوع أكبر من post_max_size في إعدادات PHP. استخدم الاستيراد من مجلد data/incoming أو أداة سطر الأوامر.');
        redirect('admin.php?tab=' . $tab);
    }
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    session_write_close(); // لا نحجز الجلسة أثناء العمليات الطويلة
    try {
        switch ($action) {
            case 'upload_main':
                [$tmp, $name] = uploaded('file', ['xlsx']);
                $meta = import_main_workbook($tmp, $name);
                $msg = 'تم رفع ملف المطابقة وفحص بنيته: ' . number_format($meta['records']) . ' سجل.';
                if ($meta['warnings']) $msg .= "\nتنبيهات:\n- " . implode("\n- ", array_slice($meta['warnings'], 0, 10));
                session_start(); flash('success', $msg);
                break;
            case 'upload_articles':
            case 'import_incoming':
                $source = (string)($_POST['source'] ?? '');
                if (!isset(SOURCES[$source])) throw new ValidationException(['مصدر غير معروف.']);
                if ($action === 'upload_articles') {
                    [$tmp, $name] = uploaded('file', $source === 'diwan' ? ['csv', 'zip'] : ['json', 'zip']);
                } else {
                    $name = basename((string)($_POST['incoming'] ?? ''));
                    $tmp = data_path('incoming/' . $name);
                    if ($name === '' || !is_file($tmp)) throw new ValidationException(['الملف غير موجود في مجلد data/incoming.']);
                }
                $info = import_articles($source, $tmp, $name);
                $msg = 'تم استيراد مواد ' . SOURCES[$source] . ': ' . number_format($info['legislations']) . ' تشريع و' . number_format($info['articles']) . ' مادة.';
                if ($info['warnings_total']) $msg .= "\nتنبيهات (" . $info['warnings_total'] . "):\n- " . implode("\n- ", array_slice($info['warnings'], 0, 8));
                session_start(); flash('success', $msg);
                break;
            case 'user_create':
                $role = ($_POST['role'] ?? 'volunteer') === 'admin' ? 'admin' : 'volunteer';
                $u = user_create(trim((string)$_POST['username']), (string)$_POST['name'], (string)$_POST['password'], $role);
                session_start(); flash('success', 'تم إنشاء الحساب: ' . $u['name'] . ' (' . $u['username'] . ')');
                break;
            case 'user_update':
                $changes = ['name' => (string)$_POST['name']];
                if (($_POST['password'] ?? '') !== '') $changes['password'] = (string)$_POST['password'];
                user_update((string)$_POST['id'], $changes);
                session_start(); flash('success', 'تم تحديث بيانات المستخدم.');
                break;
            case 'user_toggle':
                $target = user_find((string)$_POST['id']);
                if (!$target) throw new ValidationException(['المستخدم غير موجود.']);
                user_update($target['id'], ['active' => empty($target['active'])]);
                session_start(); flash('success', empty($target['active']) ? 'تم تفعيل الحساب.' : 'تم تعطيل الحساب. مراجعاته النشطة تبقى محجوزة باسمه ويمكنك تحريرها أو إعادة تخصيصها.');
                break;
            case 'settings_save':
                $s = settings();
                $s['project_title'] = mb_substr(trim((string)($_POST['project_title'] ?? '')), 0, 80) ?: default_settings()['project_title'];
                $min = trim((string)($_POST['min_year'] ?? ''));
                $max = trim((string)($_POST['max_year'] ?? ''));
                foreach ([$min, $max] as $y) if ($y !== '' && !preg_match('/^\d{4}$/', $y)) throw new ValidationException(['السنة يجب أن تكون من 4 أرقام.']);
                $s['scope'] = ['enabled' => !empty($_POST['scope_enabled']), 'min_year' => $min === '' ? null : (int)$min, 'max_year' => $max === '' ? null : (int)$max, 'basis' => ($_POST['basis'] ?? 'base') === 'record' ? 'record' : 'base'];
                foreach (array_keys(CATEGORIES) as $c) {
                    $s['decisions'][$c] = text_to_options((string)($_POST['decisions'][$c] ?? ''), 'decisions');
                }
                save_settings($s);
                session_start(); flash('success', 'تم حفظ الإعدادات.');
                break;
            case 'restore_backup':
                $n = reviews_restore_backup((string)$_POST['name'], $user);
                session_start(); flash('success', 'تمت استعادة النسخة الاحتياطية (' . $n . ' مراجعة). حُفظت النسخة السابقة كنسخة احتياطية أيضاً.');
                break;
            default:
                throw new ValidationException(['إجراء غير معروف.']);
        }
    } catch (Throwable $e) {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        flash('error', error_message($e));
    }
    redirect('admin.php?tab=' . $tab);
}

ob_start();
?>
<nav class="tabs" aria-label="أقسام الإدارة">
  <?php foreach ($tabs as $k => $l): ?><a href="admin.php?tab=<?= $k ?>" class="<?= $k === $tab ? 'active' : '' ?>" <?= $k === $tab ? 'aria-current="page"' : '' ?>><?= h($l) ?></a><?php endforeach; ?>
</nav>
<?php
if ($tab === 'files'):
    $mm = main_meta();
    $incoming = array_map('basename', array_filter(glob(data_path('incoming/*')) ?: [], 'is_file'));
?>
<section class="card section">
  <h2>ملف المطابقة الأساسي (Excel)</h2>
  <?php if ($mm): ?>
  <dl class="info-grid">
    <div><dt>اسم الملف</dt><dd><?= h($mm['original_name']) ?></dd></div>
    <div><dt>تاريخ الرفع</dt><dd><?= h($mm['uploaded_at']) ?></dd></div>
    <div><dt>الحجم</dt><dd><?= h(human_size((int)$mm['size'])) ?></dd></div>
    <div><dt>عدد السجلات</dt><dd><?= number_format((int)$mm['records']) ?></dd></div>
    <div class="wide"><dt>أوراق العمل</dt><dd><?= h(implode(' · ', array_map(fn($s) => $s['name'] . ' (' . $s['rows'] . ' صف)', $mm['sheets']))) ?></dd></div>
    <?php if (!empty($mm['warnings'])): ?><div class="wide"><dt>تنبيهات الفحص</dt><dd><ul><?php foreach (array_slice($mm['warnings'], 0, 10) as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul></dd></div><?php endif; ?>
  </dl>
  <?php else: ?><p class="alert alert-info">لم يتم رفع ملف المطابقة بعد.</p><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="upload-form"><?= csrf_field() ?>
    <input type="hidden" name="action" value="upload_main">
    <label><?= $mm ? 'استبدال الملف أو تحديثه' : 'رفع ملف المطابقة' ?> (.xlsx)<input type="file" name="file" accept=".xlsx" required></label>
    <button class="btn btn-primary" type="submit" data-confirm="<?= $mm ? 'سيُفحص الملف الجديد ثم يستبدل الحالي (مع نسخة احتياطية). نتائج التدقيق مرتبطة بمعرّفات ثابتة ولن تُحذف. متابعة؟' : 'رفع الملف وفحص بنيته؟' ?>">رفع وفحص</button>
  </form>
  <p class="muted small">يُفحص الملف قبل اعتماده: يجب أن يحتوي أوراق «المتطابقة» و«الديوان فقط» و«قسطاس فقط» بأعمدة المعرّفات (تُقبل العناوين العربية أو الإنجليزية). الملف الأصلي يُحفظ كما هو ولا يُعدَّل.</p>
</section>

<?php foreach (SOURCES as $src => $label): $info = index_info($src); ?>
<section class="card section">
  <h2>ملفات مواد <?= h($label) ?></h2>
  <?php if ($info): ?>
  <dl class="info-grid">
    <div><dt>اسم الملف</dt><dd><?= h($info['original_name']) ?></dd></div>
    <div><dt>تاريخ الاستيراد</dt><dd><?= h($info['imported_at']) ?></dd></div>
    <div><dt>عدد التشريعات</dt><dd><?= number_format((int)$info['legislations']) ?></dd></div>
    <div><dt>عدد المواد</dt><dd><?= number_format((int)$info['articles']) ?></dd></div>
    <?php if (!empty($info['warnings_total'])): ?><div class="wide"><dt>تنبيهات (<?= (int)$info['warnings_total'] ?>)</dt><dd><ul><?php foreach (array_slice($info['warnings'], 0, 8) as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul></dd></div><?php endif; ?>
  </dl>
  <?php else: ?><p class="alert alert-info">لم يتم استيراد ملف مواد <?= h($label) ?> بعد؛ ستظهر للمتطوعين رسالة توضيحية في قسم مقارنة المواد.</p><?php endif; ?>
  <p class="muted small"><?= $src === 'diwan'
      ? 'الصيغة المتوقعة: ملف CSV (مثل reg_articles.csv) فيه صف لكل مادة، والأعمدة الأساسية: Bylow_pmk_ID و Article_Number و Article. يرتبط بالتشريع عبر «الديوان: pmk_ID».'
      : 'الصيغة المتوقعة: ملف JSON (مثل Bylaws_16.json) فيه سجل لكل تشريع مع مصفوفة Articles. يُستخرج معرّف قسطاس من الرابط (/laws/info/{المعرّف}/) ويرتبط بـ«قسطاس: المعرف».' ?> يمكن رفع الملف داخل ZIP.</p>
  <form method="post" enctype="multipart/form-data" class="upload-form"><?= csrf_field() ?>
    <input type="hidden" name="action" value="upload_articles"><input type="hidden" name="source" value="<?= $src ?>">
    <label>رفع ملف <?= $src === 'diwan' ? '(.csv أو .zip)' : '(.json أو .zip)' ?><input type="file" name="file" accept="<?= $src === 'diwan' ? '.csv,.zip' : '.json,.zip' ?>" required></label>
    <button class="btn btn-primary" type="submit" data-confirm="سيُبنى فهرس المواد من جديد وقد يستغرق ذلك دقيقة أو أكثر. متابعة؟">رفع واستيراد</button>
  </form>
  <?php if ($incoming): ?>
  <form method="post" class="upload-form"><?= csrf_field() ?>
    <input type="hidden" name="action" value="import_incoming"><input type="hidden" name="source" value="<?= $src ?>">
    <label>أو استيراد ملف كبير من مجلد data/incoming<select name="incoming"><?php foreach ($incoming as $fn): ?><option value="<?= h($fn) ?>"><?= h($fn) ?></option><?php endforeach; ?></select></label>
    <button class="btn" type="submit" data-confirm="استيراد الملف المختار كمواد <?= h($label) ?>؟">استيراد</button>
  </form>
  <?php endif; ?>
</section>
<?php endforeach; ?>
<p class="muted small">حد الرفع الحالي في PHP: upload_max_filesize = <?= h(ini_get('upload_max_filesize')) ?>، post_max_size = <?= h(ini_get('post_max_size')) ?>. للملفات الأكبر ضعها في مجلد <code>data/incoming</code> أو استخدم أداة سطر الأوامر (راجع README).</p>

<?php elseif ($tab === 'users'):
    $reviews = reviews_all();
    $act = [];
    foreach ($reviews as $rv) {
        if (empty($rv['volunteer_id'])) continue;
        $act[$rv['volunteer_id']][$rv['status']] = ($act[$rv['volunteer_id']][$rv['status']] ?? 0) + 1;
        if (approval_status($rv) !== '') $act[$rv['volunteer_id']]['ap_' . approval_status($rv)] = ($act[$rv['volunteer_id']]['ap_' . approval_status($rv)] ?? 0) + 1;
    }
?>
<section class="card section">
  <h2>إنشاء حساب</h2>
  <form method="post" class="inline-form"><?= csrf_field() ?>
    <input type="hidden" name="action" value="user_create">
    <label>الاسم الظاهر<input type="text" name="name" required maxlength="80"></label>
    <label>اسم المستخدم<input type="text" name="username" required pattern="[A-Za-z0-9_.\-]{3,32}" dir="ltr" title="3-32 حرفاً لاتينياً أو رقماً"></label>
    <label>كلمة المرور<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
    <label>النوع<select name="role"><option value="volunteer">متطوع</option><option value="admin">مسؤول</option></select></label>
    <button class="btn btn-primary" type="submit">إنشاء</button>
  </form>
</section>
<section class="card section">
  <h2>الحسابات</h2>
  <div class="user-list">
  <?php foreach (users_all() as $u): $a = $act[$u['id']] ?? []; ?>
    <div class="user-row <?= empty($u['active']) ? 'inactive' : '' ?>">
      <div class="ur-info">
        <strong><?= h($u['name']) ?></strong> <span class="muted" dir="ltr">@<?= h($u['username']) ?></span>
        <span class="badge"><?= h(ROLES[$u['role']]) ?></span> <?= empty($u['active']) ? '<span class="badge warn">معطل</span>' : '<span class="badge ok">فعّال</span>' ?>
        <div class="muted small">آخر دخول: <?= h($u['last_login'] ?? '—') ?> · أُنشئ: <?= h($u['created_at']) ?></div>
        <?php if ($u['role'] === 'volunteer'): ?><div class="small">قيد التنفيذ <?= (int)($a['in_progress'] ?? 0) ?> · منتهية <?= (int)($a['done'] ?? 0) ?> · بانتظار الاعتماد <?= (int)($a['ap_pending'] ?? 0) ?> · معتمدة <?= (int)($a['ap_approved'] ?? 0) ?> · أعيدت للتصحيح <?= (int)($a['ap_returned'] ?? 0) ?> — <a href="cards.php?volunteer=<?= h(rawurlencode($u['id'])) ?>&all=1">عرض مراجعاته</a></div><?php endif; ?>
      </div>
      <details class="ur-edit"><summary>تعديل</summary>
        <form method="post" class="inline-form"><?= csrf_field() ?>
          <input type="hidden" name="action" value="user_update"><input type="hidden" name="id" value="<?= h($u['id']) ?>">
          <label>الاسم الظاهر<input type="text" name="name" value="<?= h($u['name']) ?>" required maxlength="80"></label>
          <label>كلمة مرور جديدة (اختياري)<input type="password" name="password" minlength="8" autocomplete="new-password"></label>
          <button class="btn btn-sm" type="submit">حفظ</button>
        </form>
      </details>
      <?php if ($u['id'] !== $user['id']): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="user_toggle"><input type="hidden" name="id" value="<?= h($u['id']) ?>">
        <button class="btn btn-sm <?= empty($u['active']) ? '' : 'btn-danger' ?>" type="submit"><?= empty($u['active']) ? 'تفعيل' : 'تعطيل' ?></button></form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
</section>

<?php elseif ($tab === 'reviews'):
    $records = records_all();
    $reviews = reviews_all();
    $filter = (string)($_GET['st'] ?? 'in_progress');
    $match = fn($r, $f) => match ($f) {
        'all' => true,
        'in_progress' => $r['status'] === 'in_progress',
        'pending' => $r['status'] === 'done' && $r['approval_status'] === 'pending',
        'approved' => $r['approval_status'] === 'approved',
        'returned' => $r['status'] === 'in_progress' && $r['approval_status'] === 'returned',
        default => false,
    };
    $rows = array_filter($reviews, fn($r) => $match($r, $filter));
    uasort($rows, fn($a, $b) => strcmp((string)$b['updated_at'], (string)$a['updated_at']));
    $orphans = array_diff_key($reviews, $records);
    $counts = [];
    foreach (['in_progress', 'pending', 'returned', 'approved'] as $k) {
        $counts[$k] = count(array_filter($reviews, fn($r) => $match($r, $k)));
    }
?>
<section class="card section">
  <h2>متابعة عمليات التدقيق</h2>
  <p class="muted small">للاعتماد الجماعي استخدم صفحة <a href="approvals.php">بانتظار الاعتماد</a>.</p>
  <div class="chips">
    <?php foreach (['in_progress' => 'قيد التنفيذ (مسودات)', 'returned' => 'أعيدت للتصحيح', 'pending' => 'بانتظار الاعتماد', 'approved' => 'معتمدة', 'all' => 'الكل'] as $k => $l): ?>
      <a class="chip <?= $filter === $k ? 'active' : '' ?>" href="admin.php?tab=reviews&st=<?= $k ?>"><?= h($l) ?> (<?= $k === 'all' ? count($reviews) : (int)($counts[$k] ?? 0) ?>)</a>
    <?php endforeach; ?>
  </div>
  <?php if ($orphans): ?><div class="alert alert-warning">يوجد <?= count($orphans) ?> نتيجة تدقيق مرتبطة بسجلات غير موجودة في ملف المطابقة الحالي (بعد استبدال الملف). البيانات محفوظة ولم تُحذف: <?= h(implode('، ', array_slice(array_keys($orphans), 0, 20))) ?></div><?php endif; ?>
  <?php if (!$rows): ?><p class="muted">لا توجد عناصر.</p><?php else: ?>
  <ul class="review-list">
    <?php foreach (array_slice($rows, 0, 300, true) as $rid => $rv): $rec = $records[$rid] ?? null; ?>
      <li>
        <div><a href="review.php?id=<?= h(rawurlencode($rid)) ?>"><strong><?= h($rec ? record_title($rec) : $rv['legislation']) ?></strong></a> <span class="muted small"><?= h($rec ? record_ref($rec) : '') ?></span></div>
        <div class="small"><?= category_badge($rv['category']) ?> <?= status_badge($rv['status']) ?> <?= approval_badge(approval_status($rv)) ?> المتطوع: <strong><?= h($rv['volunteer_name'] ?: 'لا يوجد') ?></strong> · القرار: <?= h(decision_label($rv['category'], $rv['final_decision'])) ?> · <?= review_progress($rv) ?>% · <span class="muted">آخر تحديث <?= h($rv['updated_at']) ?></span></div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if (count($rows) > 300): ?><p class="muted small">يُعرض أحدث 300 عنصر. استخدم صفحة البطاقات للتصفية الدقيقة.</p><?php endif; ?>
  <?php endif; ?>
</section>

<?php elseif ($tab === 'export'):
    $exports = array_filter(list_backups('exports'), fn($f) => str_ends_with($f['name'], '.xlsx'));
    $backs = array_filter(list_backups('backups/reviews'), fn($f) => str_starts_with($f['name'], 'reviews_'));
    $rx = reviews_xlsx_path();
?>
<section class="card section">
  <h2>تصدير البيانات</h2>
  <p>تنزيل نسخة من ملف المطابقة الأصلي بجميع أوراقه وقيمه كما هي، مضافاً إليها ورقة «<?= h(RESULTS_SHEET) ?>» بجميع نتائج التدقيق الحالية.</p>
  <form method="post" action="download.php"><?= csrf_field() ?><input type="hidden" name="type" value="export">
    <button class="btn btn-primary" type="submit" <?= is_file(main_workbook_path()) ? '' : 'disabled' ?>>إنشاء وتنزيل ملف Excel المحدَّث</button>
    <span class="muted small">قد يستغرق الإنشاء 10–30 ثانية.</span>
  </form>
  <div class="btn-row">
    <?php if (is_file(main_workbook_path())): ?><a class="btn" href="download.php?type=original">تنزيل ملف المطابقة الأصلي</a><?php endif; ?>
    <?php if (is_file($rx)): ?><a class="btn" href="download.php?type=reviews">تنزيل ملف نتائج التدقيق الخام (reviews.xlsx)</a><?php endif; ?>
  </div>
  <?php if ($exports): ?><h3>ملفات مصدَّرة سابقاً</h3><ul class="plain-list">
    <?php foreach (array_slice($exports, 0, 10) as $f): ?><li><a href="download.php?type=exportfile&name=<?= h(rawurlencode($f['name'])) ?>"><?= h($f['name']) ?></a> <span class="muted small"><?= h(human_size($f['size'])) ?> · <?= date('Y-m-d H:i', $f['time']) ?></span></li><?php endforeach; ?>
  </ul><?php endif; ?>
</section>
<section class="card section">
  <h2>النسخ الاحتياطية لنتائج التدقيق</h2>
  <p class="muted small">تُنشأ نسخة تلقائية من ملف النتائج قبل كل تعديل (يُحتفظ بآخر 300 نسخة)، إضافة إلى سجل عمليات دائم في <code>data/logs/journal.jsonl</code>. الاستعادة تحفظ النسخة الحالية أولاً.</p>
  <?php if (!$backs): ?><p class="muted">لا توجد نسخ احتياطية بعد.</p><?php else: ?>
  <ul class="backup-list">
    <?php foreach (array_slice($backs, 0, 40) as $b): ?>
    <li><span><?= h($b['name']) ?> <span class="muted small"><?= h(human_size($b['size'])) ?> · <?= date('Y-m-d H:i:s', $b['time']) ?></span></span>
      <span class="btn-row"><a class="btn btn-sm btn-ghost" href="download.php?type=backup&name=<?= h(rawurlencode($b['name'])) ?>">تنزيل</a>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="restore_backup"><input type="hidden" name="name" value="<?= h($b['name']) ?>"><button class="btn btn-sm" type="submit" data-confirm="استعادة هذه النسخة؟ ستُستبدل نتائج التدقيق الحالية بها (مع حفظ نسخة من الحالية).">استعادة</button></form></span></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<?php elseif ($tab === 'settings'): $s = settings(); ?>
<form method="post" class="settings-form"><?= csrf_field() ?>
  <input type="hidden" name="action" value="settings_save">
  <section class="card section">
    <h2>نطاق التدقيق</h2>
    <p class="muted small">وفق إجراءات العمل: يشمل التدقيق التشريعات الصادرة في 1960 وما قبلها. للتعديلات تُعتمد سنة صدور التشريع الأصلي.</p>
    <label class="check"><input type="checkbox" name="scope_enabled" value="1" <?= !empty($s['scope']['enabled']) ? 'checked' : '' ?>><span>تفعيل تصفية السنوات (السجلات خارج النطاق لا تظهر للمتطوعين)</span></label>
    <div class="inline-form">
      <label>من سنة (اختياري)<input type="text" name="min_year" value="<?= h($s['scope']['min_year'] ?? '') ?>" inputmode="numeric" size="6"></label>
      <label>حتى سنة (شاملة)<input type="text" name="max_year" value="<?= h($s['scope']['max_year'] ?? '') ?>" inputmode="numeric" size="6"></label>
      <label>أساس السنة<select name="basis"><option value="base" <?= ($s['scope']['basis'] ?? 'base') === 'base' ? 'selected' : '' ?>>سنة التشريع الأصلي</option><option value="record" <?= ($s['scope']['basis'] ?? '') === 'record' ? 'selected' : '' ?>>سنة السجل نفسه</option></select></label>
      <label>عنوان المشروع<input type="text" name="project_title" value="<?= h($s['project_title']) ?>" maxlength="80"></label>
    </div>
  </section>
  <section class="card section">
    <h2>خيارات القرار النهائي</h2>
    <p class="muted small">سطر لكل خيار بالشكل: <code>مفتاح_لاتيني | التسمية | full</code>. العلامة <code>full</code> تعني تطابقاً أو وجوداً كاملاً (ملاحظات المراجع اختيارية)؛ باقي القرارات تتطلب ملاحظة. لا تغيّر مفاتيح خيارات مستخدمة سابقاً.</p>
    <?php foreach (CATEGORIES as $c => $l): ?>
      <h3><?= h($l) ?></h3>
      <label>القرارات<textarea name="decisions[<?= $c ?>]" rows="5" dir="auto"><?= h(options_to_text($s['decisions'][$c], 'decisions')) ?></textarea></label>
    <?php endforeach; ?>
  </section>
  <div class="form-actions"><button class="btn btn-primary" type="submit">حفظ الإعدادات</button></div>
</form>
<?php endif;
render_page('إدارة النظام والملفات', ob_get_clean(), 'admin', ['scripts' => ['assets/app.js?v=3']]);
