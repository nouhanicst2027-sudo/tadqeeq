<?php
/** الصفحة الثالثة: تفاصيل التدقيق — مساحة العمل للتحقق من تشريع واحد. */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$id = (string)($_GET['id'] ?? '');
$record = $id !== '' ? record_get($id) : null;
if (!$record) {
    http_response_code(404);
    render_page('السجل غير موجود', '<div class="alert alert-error">لم يتم العثور على هذا السجل في ملف المطابقة الحالي.</div><p><a class="btn" href="cards.php">العودة إلى بطاقات التشريعات</a></p>', 'cards');
    exit;
}
$self = 'review.php?id=' . rawurlencode($record['id']);
$formErrors = [];
$postedView = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'start':
                review_start($record['id'], $user);
                flash('success', 'تم حجز التشريع باسمك وبدأت المراجعة.');
                redirect($self);
            case 'save':
            case 'submit':
                review_save($record['id'], $user, $_POST, $action === 'submit');
                flash('success', $action === 'submit' ? 'انتهت المراجعة وأُرسلت النتيجة إلى المسؤول للاعتماد.' : 'تم حفظ المسودة بنجاح.');
                redirect($self . ($action === 'save' ? '#form' : ''));
            case 'approve':
            case 'return':
            case 'release':
            case 'reassign':
                admin_action($record['id'], $user, $action, (string)($_POST['admin_notes'] ?? ''), (string)($_POST['target_user'] ?? ''));
                $msgs = ['approve' => 'تم اعتماد النتيجة.', 'return' => 'أعيدت النتيجة للمتطوع لتصحيحها.', 'release' => 'تم تحرير التشريع من الحجز.', 'reassign' => 'تمت إعادة التخصيص.'];
                flash('success', $msgs[$action]);
                redirect($self);
            default:
                throw new ValidationException(['إجراء غير معروف.']);
        }
    } catch (Throwable $e) {
        $formErrors = explode("\n", error_message($e));
        if (in_array($action, ['save', 'submit'], true)) {
            // عدم فقدان ما أدخله المتطوع: نعيد عرض النموذج بقيمه المرسلة
            $postedView = parse_review_input($record, $_POST, false);
            $postedView['version'] = (int)($_POST['version'] ?? 0);
        }
    }
}

$rev = review_get($record['id']);
$status = review_status($rev);
$approval = approval_status($rev);
$editable = can_edit_review($rev, $user);
$view = $rev ?? review_blank($record);
if ($postedView && $editable) {
    unset($postedView['_errors']);
    $view = array_merge($view, $postedView);
}
$ownedByOther = $rev && !empty($rev['volunteer_id']) && $rev['volunteer_id'] !== $user['id'];
$cat = $record['category'];
$d = $record['diwan'];
$q = $record['qistas'];
$dMeta = $d ? articles_meta('diwan', $d['id']) : null;
$qMeta = $q ? articles_meta('qistas', $q['id']) : null;
$pct = review_progress($rev);

ob_start();
?>
<a class="back-link" href="cards.php">→ العودة إلى بطاقات التشريعات</a>

<section class="review-head card">
  <div class="rh-main">
    <div class="rh-badges"><?= category_badge($cat) ?>
      <span class="badge prio">أولوية <?= (int)$record['priority'] ?>: <?= h(PRIORITY_LABELS[$record['priority']] ?? '') ?></span>
      <?php if ($record['is_amendment']): ?><span class="badge">نظام معدل</span><?php endif; ?>
      <?php if (!record_in_scope($record)): ?><span class="badge warn">خارج نطاق التدقيق الحالي</span><?php endif; ?>
    </div>
    <h1><?= h(record_title($record)) ?></h1>
    <p class="rh-ref"><?= h(record_ref($record)) ?> <span class="muted">· معرّف السجل: <?= h($record['id']) ?></span></p>
    <div class="rh-states">
      <div><span class="muted small">حالة المراجعة</span><?= status_badge($status) ?></div>
      <div><span class="muted small">حالة الاعتماد</span><?= $approval !== '' ? approval_badge($approval) : '<span class="badge">—</span>' ?></div>
    </div>
    <p class="rh-owner">المتطوع المسؤول: <strong><?= !empty($view['volunteer_name']) ? h($view['volunteer_name']) : 'لا يوجد' ?></strong>
      <?php if (!empty($view['started_at'])): ?><span class="muted"> · بدأت في <?= h($view['started_at']) ?></span><?php endif; ?>
      <?php if ($status === 'done' && !empty($view['submitted_at'])): ?><span class="muted"> · انتهت في <?= h($view['submitted_at']) ?></span><?php endif; ?>
      <?php if ($approval === 'approved'): ?><span class="muted"> · اعتمدها <?= h($view['approved_by']) ?> في <?= h($view['approved_at']) ?></span><?php endif; ?>
    </p>
  </div>
  <div class="rh-progress">
    <div class="pct-big"><?= $pct ?>%</div>
    <?= progress_bar($pct) ?>
    <div class="muted small">لم تبدأ 0% · قيد التنفيذ 50% · منتهية 100%</div>
  </div>
</section>

<?php if ($formErrors): ?>
<div class="alert alert-error" role="alert"><strong>لم يتم الحفظ:</strong><ul><?php foreach ($formErrors as $er): ?><li><?= h($er) ?></li><?php endforeach; ?></ul>
<?php if ($postedView): ?><p>تم الإبقاء على ما أدخلته في النموذج أدناه؛ صحّح الملاحظات ثم أعد المحاولة.</p><?php endif; ?></div>
<?php endif; ?>

<?php if ($ownedByOther && !is_admin($user)): ?>
<div class="alert alert-info">هذا التشريع قيد التنفيذ من قبل <strong><?= h($rev['volunteer_name']) ?></strong>. يمكنك الاطلاع على البيانات فقط، ولا يمكنك تعديل المراجعة.</div>
<?php endif; ?>
<?php if ($approval === 'returned' && $status === 'in_progress' && !empty($view['admin_notes'])): ?>
<div class="alert alert-warning"><strong>أعاد المسؤول النتيجة للتصحيح:</strong><br><?= nl2br(h($view['admin_notes'])) ?></div>
<?php endif; ?>

<?php if ($status === 'not_started' && !is_admin($user)): ?>
<form method="post" class="start-box card"><?= csrf_field() ?>
  <input type="hidden" name="action" value="start">
  <p>لم يبدأ أحد بمراجعة هذا التشريع بعد. عند البدء سيُسجَّل اسمك كمتطوع مسؤول ولن يتمكن غيرك من تعديل المراجعة.</p>
  <button class="btn btn-primary" type="submit">ابدأ التدقيق</button>
</form>
<?php endif; ?>

<!-- أولاً: معلومات التشريع -->
<details class="card section collapsible">
  <summary><h2><span class="sec-num">١</span> معلومات التشريع <small class="muted">من ملف المطابقة الأساسي — اضغط للعرض</small></h2></summary>
  <dl class="info-grid">
    <div><dt>اسم التشريع</dt><dd><?= val($record['name']) ?></dd></div>
    <div><dt>رقم التشريع</dt><dd><?= val($record['number']) ?></dd></div>
    <div><dt>سنة الصدور</dt><dd><?= val($record['year']) ?></dd></div>
    <?php if ($record['is_amendment']): ?><div><dt>سنة التشريع الأصلي</dt><dd><?= val($record['base_year']) ?></dd></div><?php endif; ?>
    <div><dt>النوع</dt><dd><?= val($record['type']) ?></dd></div>
    <div><dt>رقم الجريدة الرسمية</dt><dd><?= val($d['magazine'] ?? $q['magazine'] ?? null) ?></dd></div>
    <div><dt>رقم الصفحة</dt><dd><?= val($d['page'] ?? $q['page'] ?? null) ?></dd></div>
    <div><dt>عدد المواد</dt><dd><?php if ($d): ?>الديوان: <?= val($d['articles_count']) ?><?php endif; ?><?php if ($d && $q): ?> · <?php endif; ?><?php if ($q): ?>قسطاس: <?= val($q['articles_count']) ?><?php endif; ?></dd></div>
    <div><dt>معرّف الديوان</dt><dd><?= val($d['id'] ?? null, 'لا يوجد') ?></dd></div>
    <div><dt>معرّف قسطاس</dt><dd><?= val($q['id'] ?? null, 'لا يوجد') ?></dd></div>
    <?php if (!empty($q['url'])): ?><div class="wide"><dt>رابط قسطاس</dt><dd><a href="<?= h($q['url']) ?>" target="_blank" rel="noopener noreferrer">فتح السجل في قسطاس ↗</a></dd></div><?php endif; ?>
    <?php if ($record['match']): $m = $record['match']; ?>
      <div><dt>مستوى المطابقة</dt><dd><?= val($m['match_level']) ?></dd></div>
      <div><dt>مستوى الثقة في المطابقة</dt><dd><?= val($m['confidence']) ?></dd></div>
      <div class="wide"><dt>أساس المطابقة</dt><dd><?= val($m['basis']) ?></dd></div>
      <div><dt>إشعار المواد</dt><dd><?= val($m['articles_flag']) ?></dd></div>
      <div><dt>تطابق المواد % (حسب ملف المطابقة)</dt><dd><?= val($m['articles_similarity']) ?></dd></div>
      <?php if (!empty($m['different_articles'])): ?><div class="wide"><dt>أرقام المواد المختلفة (الديوان)</dt><dd><?= val($m['different_articles']) ?></dd></div><?php endif; ?>
      <?php if (!empty($m['notes'])): ?><div class="wide"><dt>ملاحظات ملف المطابقة</dt><dd><?= val($m['notes']) ?></dd></div><?php endif; ?>
    <?php endif; ?>
  </dl>
  <details class="raw">
    <summary>جميع القيم الأصلية لهذا السجل (ورقة «<?= h($record['sheet']) ?>»)</summary>
    <dl class="raw-list">
      <?php foreach (record_raw($record) as [$hd, $v]): ?><div><dt><?= h($hd) ?></dt><dd><?= val($v) ?></dd></div><?php endforeach; ?>
    </dl>
  </details>
</details>

<!-- ثانياً: مقارنة بيانات المصدرين — البطاقتان كما هما، مع دوائر ✓ ✗ ✎ جنب كل قيمة -->
<?php
$fv = field_values($record);
$av = $view['approved_values'] ?: [];
$unres = unresolved_fields($record, $view, $fv);
$both = $d && $q;
$xflag = function (string $field) use ($record) {
    $k = FIELD_EXCEL_FLAGS[$field] ?? null;
    if (!$k || !$record['match'] || ($record['match'][$k] ?? null) === null) return '';
    $v = $record['match'][$k];
    return ' <span class="flag ' . ($v === 'نعم' ? 'flag-yes' : 'flag-no') . '" title="حسب ملف المطابقة الأولية">' . ($v === 'نعم' ? 'متطابق في ملف المطابقة' : 'غير متطابق في ملف المطابقة') . '</span>';
};
/** حالة الدوائر لبطاقة مصدر معيّن في حقل معيّن */
$icState = function (string $fk, string $src) use ($av, $fv) {
    $a = $av[$fk] ?? [];
    $s = $a['src'] ?? '';
    return [
        'ok' => $s === $src || (in_array($s, ['diwan', 'qistas'], true) && $fv[$fk]['equal']),
        'no' => in_array($src, $a['wrong'] ?? [], true),
        'pe' => $s === 'manual' && ($a['card'] ?? '') === $src,
    ];
};
$icons = function (string $fk, string $src) use ($icState, $editable) {
    $st = $icState($fk, $src);
    $tag = $editable ? 'button type="button"' : 'span';
    $end = $editable ? 'button' : 'span';
    $o = '<div class="ic" data-f="' . h($fk) . '" data-c="' . $src . '">';
    foreach (['ok' => ['✓', 'صح'], 'no' => ['✗', 'خطأ'], 'pe' => ['✎', 'كتابة القيمة الصحيحة']] as $k => [$sym, $lbl]) {
        $o .= '<' . $tag . ' class="icb ' . $k . ($st[$k] ? ' on' : '') . '" data-s="' . $k . '" aria-label="' . $lbl . '"' . ($editable ? ' aria-pressed="' . ($st[$k] ? 'true' : 'false') . '"' : '') . '>' . $sym . '</' . $end . '>';
    }
    return $o . '</div>';
};
$cell = function (string $fk, string $src, bool $withFlag) use ($fv, $av, $icons, $xflag) {
    $f = $fv[$fk];
    $a = $av[$fk] ?? [];
    $manual = ($a['src'] ?? '') === 'manual' && ($a['card'] ?? '') === $src;
    return '<div class="cg-body"><span class="cg-label">' . h($f['label']) . ($withFlag ? $xflag($fk) : '') . '</span>'
        . '<span class="cg-val">' . val($f[$src], 'غير متوفر') . '</span>'
        . '<span class="mv" data-mv="' . h($fk) . '" data-c="' . $src . '"' . ($manual ? '' : ' hidden') . '>القيمة الصحيحة: <strong>' . h($manual ? $a['value'] : '') . '</strong></span>'
        . '<span class="mv-edit" data-edit="' . h($fk) . '" data-c="' . $src . '" hidden><input type="text" maxlength="500" placeholder="القيمة الصحيحة" aria-label="القيمة الصحيحة: ' . h($f['label']) . '"><button type="button" class="btn btn-sm" data-save>حفظ</button></span>'
        . '</div>' . $icons($fk, $src);
};
$hidden = function (string $fk) use ($av, $fv, $both, $editable) {
    if (!$editable) return '';
    $a = $av[$fk] ?? [];
    $f = $fv[$fk];
    $need = !$f['equal'] && !in_array($fk, UNRESOLVED_SKIP, true) && ($both || ($f['has_diwan'] ? $f['diwan'] : $f['qistas']) === null);
    return '<span class="av-state" data-f="' . h($fk) . '" data-label="' . h($f['label']) . '" data-need="' . ($need ? '1' : '0') . '" data-equal="' . ($f['equal'] ? '1' : '0') . '" hidden>'
        . '<input type="hidden" form="review-form" name="av_src[' . h($fk) . ']" value="' . h($a['src'] ?? '') . '">'
        . '<input type="hidden" form="review-form" name="av_wrong[' . h($fk) . ']" value="' . h(implode(',', $a['wrong'] ?? [])) . '">'
        . '<input type="hidden" form="review-form" name="av_val[' . h($fk) . ']" value="' . h(($a['src'] ?? '') === 'manual' ? $a['value'] : '') . '">'
        . '<input type="hidden" form="review-form" name="av_card[' . h($fk) . ']" value="' . h($a['card'] ?? '') . '">'
        . '</span>';
};
$order = ['name', 'number', 'year', 'magazine', 'page', 'magazine_date', 'type', 'status', 'active_date', '#articles', 'parent', '#id'];
?>
<section class="card section" id="sources">
  <h2><span class="sec-num">٢</span> مقارنة بيانات المصدرين</h2>
  <?php if ($unres && !$editable): ?><p class="small"><span class="av-missing">حقول غير محسومة:</span> <?= h(unresolved_labels($unres)) ?></p><?php endif; ?>
  <div class="compare-grid">
    <div class="cg-head diwan">بيانات ديوان التشريع والرأي</div>
    <div class="cg-head qistas">بيانات قسطاس</div>
    <?php if ($both): foreach ($order as $fk):
      if ($fk === '#articles' || $fk === '#id'):
        [$lbl, $x, $y] = $fk === '#articles' ? ['عدد المواد', $d['articles_count'] ?? null, $q['articles_count'] ?? null] : ['المعرّف', $d['id'] ?? null, $q['id'] ?? null]; ?>
        <div class="cg-cell diwan"><span class="cg-label"><?= h($lbl) ?></span><span class="cg-val"><?= val($x, 'غير متوفر') ?></span></div>
        <div class="cg-cell qistas"><span class="cg-label"><?= h($lbl) ?></span><span class="cg-val"><?= val($y, 'غير متوفر') ?></span></div>
      <?php else: ?>
        <div class="cg-cell diwan has-ic"><?= $cell($fk, 'diwan', true) ?><?= $hidden($fk) ?></div>
        <div class="cg-cell qistas has-ic"><?= $cell($fk, 'qistas', false) ?></div>
      <?php endif; endforeach;
    else:
      $src = $d ? 'diwan' : 'qistas';
      $cand = $record['candidate'] ?? [];
      ob_start();
      foreach ($order as $fk):
        if ($fk === '#articles' || $fk === '#id'):
          [$lbl, $x] = $fk === '#articles' ? ['عدد المواد', $record[$src]['articles_count'] ?? null] : ['المعرّف', $record[$src]['id'] ?? null]; ?>
          <div class="cg-line"><span class="cg-label"><?= h($lbl) ?></span><span class="cg-val"><?= val($x, 'غير متوفر') ?></span></div>
        <?php else: if ($fk === 'parent' && !$d) continue; ?>
          <div class="cg-line has-ic"><?= $cell($fk, $src, false) ?><?= $hidden($fk) ?></div>
        <?php endif; endforeach;
      $presentHtml = ob_get_clean(); ob_start(); ?>
        <div class="empty-note">لا يوجد سجل مقابل في <?= $d ? 'قسطاس' : 'الديوان' ?> ضمن بيانات المطابقة الأولية.</div>
        <?php if (array_filter($cand)): ?>
        <div class="candidate">
          <div class="cand-title">أقرب مرشح ورد في ملف المطابقة <small>(للاسترشاد فقط، وليس مطابقة)</small></div>
          <?php foreach ([['المعرّف', $cand['id']], ['الرقم/السنة', $cand['number_year']], ['العدد/الصفحة', $cand['magazine_page']], ['الاسم', $cand['name']], ['حقول متطابقة (من 4)', $cand['matching_fields']], ['تشابه الاسم %', $cand['name_similarity']], ['تطابق المواد %', $cand['articles_similarity']], ['سبب عدم المطابقة', $cand['reason']]] as [$l, $v]): if ($v === null || $v === '') continue; ?>
            <div class="cg-line"><span class="cg-label"><?= h($l) ?></span><span class="cg-val"><?= h($v) ?></span></div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      <?php $absentHtml = ob_get_clean(); ?>
      <div class="cg-panel"><?= $d ? $presentHtml : $absentHtml ?></div>
      <div class="cg-panel"><?= $d ? $absentHtml : $presentHtml ?></div>
    <?php endif; ?>
  </div>
</section>

<!-- ثالثاً: مقارنة المواد التشريعية -->
<section class="card section" id="articles">
  <h2><span class="sec-num">٣</span> مقارنة المواد التشريعية</h2>
  <div id="article-compare"
       data-diwan-id="<?= h($view['compare_diwan_id'] ?: ($d['id'] ?? '')) ?>"
       data-qistas-id="<?= h($view['compare_qistas_id'] ?: ($q['id'] ?? '')) ?>"
       data-default-diwan="<?= h($d['id'] ?? '') ?>"
       data-default-qistas="<?= h($q['id'] ?? '') ?>"
       data-editable="<?= $editable ? '1' : '0' ?>">
    <noscript><div class="alert alert-warning">تحتاج مقارنة المواد إلى تفعيل JavaScript في المتصفح.</div></noscript>
  </div>
</section>

<!-- رابعاً: نموذج توثيق نتائج التدقيق -->
<section class="card section" id="form">
  <h2><span class="sec-num">٤</span> توثيق نتائج التدقيق</h2>
  <?php if (!$rev && is_admin($user)): ?>
    <p class="muted">لم تبدأ مراجعة هذا التشريع بعد.</p>
  <?php elseif ($editable): ?>
  <form method="post" id="review-form" class="review-form" novalidate data-unresolved-base="1">
    <?= csrf_field() ?>
    <input type="hidden" name="version" value="<?= (int)$view['version'] ?>">
    <input type="hidden" name="compare_diwan_id" id="f-compare-diwan" value="<?= h($view['compare_diwan_id']) ?>">
    <input type="hidden" name="compare_qistas_id" id="f-compare-qistas" value="<?= h($view['compare_qistas_id']) ?>">

    <fieldset>
      <legend>١. القرار النهائي</legend>
      <select name="final_decision" id="final-decision">
        <option value="">— اختر القرار —</option>
        <?php foreach (decisions_for($cat) as $dd): ?><option value="<?= h($dd['key']) ?>" data-full="<?= in_array('full', $dd['flags'], true) ? '1' : '0' ?>" <?= $view['final_decision'] === $dd['key'] ? 'selected' : '' ?>><?= h($dd['label']) ?></option><?php endforeach; ?>
      </select>
    </fieldset>

    <fieldset>
      <legend>٢. ملاحظات المراجع <span class="muted small">(اختيارية)</span></legend>
      <textarea name="reviewer_notes" id="reviewer-notes" rows="5" maxlength="8000" placeholder="مثال: الرقم والسنة متطابقان لكن الاسم مختلف، وقسطاس يعرضه ضمن سجل التعديلات…"><?= h($view['reviewer_notes']) ?></textarea>
    </fieldset>

    <fieldset>
      <legend>٣. الاختلافات في المحتوى</legend>
      <p class="muted small">يمكن إضافة اختلاف مباشرة من لوحة مقارنة المواد أعلاه بزر «توثيق اختلاف». لا تعتبر كل اختلاف خطأً؛ قد يكون ناتجاً عن تعديل تشريعي صحيح.</p>
      <div id="diffs">
        <?php foreach ($view['content_differences'] ?: [] as $df): ?>
          <?= diff_row_html($df) ?>
        <?php endforeach; ?>
      </div>
      <template id="diff-template"><?= diff_row_html(['diwan_article' => '', 'qistas_article' => '', 'type' => 'text_difference', 'note' => '']) ?></template>
      <button type="button" class="btn btn-sm" id="add-diff">+ إضافة اختلاف</button>
    </fieldset>


    <div class="form-actions sticky-actions">
      <button class="btn" type="submit" name="action" value="save">حفظ كمسودة</button>
      <button class="btn btn-primary" type="submit" name="action" value="submit" data-confirm="إنهاء المراجعة وإرسال النتيجة إلى المسؤول؟ لن تتمكن من تعديلها إلا إذا أعادها المسؤول للتصحيح.">إرسال نتيجة التدقيق</button>
      <a class="btn btn-ghost" href="cards.php">العودة إلى بطاقات التشريعات</a>
      <span class="save-state muted small" id="save-state" aria-live="polite"></span>
    </div>
  </form>
  <?php else: ?>
    <?= review_readonly_html($record, $view) ?>
  <?php endif; ?>
</section>

<?php if (is_admin($user)): ?>
<section class="card section admin-box">
  <h2>إجراءات المسؤول</h2>
  <form method="post" class="admin-actions"><?= csrf_field() ?>
    <label>ملاحظات المسؤول <span class="muted small">(مطلوبة عند الإعادة للتصحيح)</span>
      <textarea name="admin_notes" rows="3" maxlength="4000"><?= h($view['admin_notes'] ?? '') ?></textarea></label>
    <div class="form-actions">
      <?php if ($status === 'done' && $approval === 'pending'): ?>
        <button class="btn btn-primary" name="action" value="approve" data-confirm="اعتماد هذه النتيجة؟">اعتماد النتيجة</button>
      <?php endif; ?>
      <?php if ($status === 'done' && !empty($view['volunteer_id'])): ?>
        <button class="btn" name="action" value="return">إعادة للتصحيح</button>
      <?php endif; ?>
      <?php if (!empty($view['volunteer_id']) && $approval !== 'approved'): ?>
        <button class="btn btn-danger" name="action" value="release" data-confirm="تحرير التشريع من حجز المتطوع؟ ستبقى البيانات الموثقة محفوظة.">تحرير من الحجز</button>
      <?php endif; ?>
    </div>
    <?php if ($approval !== 'approved'):
      $vols = array_filter(users_all(), fn($u) => $u['role'] === 'volunteer' && !empty($u['active'])); ?>
    <div class="reassign">
      <label>إعادة التخصيص إلى متطوع
        <select name="target_user"><option value="">— اختر —</option><?php foreach ($vols as $vu): ?><option value="<?= h($vu['id']) ?>"><?= h($vu['name']) ?> (<?= h($vu['username']) ?>)</option><?php endforeach; ?></select>
      </label>
      <button class="btn" name="action" value="reassign">إعادة التخصيص</button>
    </div>
    <?php endif; ?>
  </form>
</section>
<?php endif; ?>

<?php if (!empty($view['history'])): ?>
<section class="card section">
  <h2>سجل العمليات</h2>
  <ol class="history">
    <?php foreach (array_reverse($view['history']) as $hst): ?>
      <li><span class="muted"><?= h($hst['at']) ?></span> — <strong><?= h($hst['name']) ?></strong>: <?= h($hst['action']) ?><?php if (!empty($hst['note'])): ?> <span class="muted">(<?= h($hst['note']) ?>)</span><?php endif; ?></li>
    <?php endforeach; ?>
  </ol>
</section>
<?php endif; ?>
<?php
$content = ob_get_clean();
render_page(record_title($record), $content, 'cards', ['header' => ' ', 'scripts' => ['assets/review.js?v=3']]);

function diff_row_html(array $df): string
{
    $opts = '';
    foreach (DIFF_TYPES as $k => $l) {
        $opts .= '<option value="' . h($k) . '"' . ($df['type'] === $k ? ' selected' : '') . '>' . h($l) . '</option>';
    }
    return '<div class="diff-row repeat-row">'
        . '<label class="sm">مادة الديوان<input type="text" name="diff_diwan[]" value="' . h($df['diwan_article']) . '" maxlength="40"></label>'
        . '<label class="sm">مادة قسطاس<input type="text" name="diff_qistas[]" value="' . h($df['qistas_article']) . '" maxlength="40"></label>'
        . '<label class="md">نوع الاختلاف<select name="diff_type[]">' . $opts . '</select></label>'
        . '<label class="lg">الوصف<textarea name="diff_note[]" rows="2" maxlength="1500">' . h($df['note']) . '</textarea></label>'
        . '<button type="button" class="btn btn-ghost btn-sm remove-row" aria-label="حذف الاختلاف">حذف</button>'
        . '</div>';
}

function review_readonly_html(array $record, array $v): string
{
    $cat = $record['category'];
    $o = '<dl class="readonly">';
    $o .= '<div class="wide"><dt>القرار النهائي</dt><dd><strong>' . ($v['final_decision'] ? h(decision_label($cat, $v['final_decision'])) : '<span class="muted">لم يُحدد بعد</span>') . '</strong></dd></div>';
    $o .= '<div class="wide"><dt>ملاحظات المراجع</dt><dd>' . ($v['reviewer_notes'] !== '' ? nl2br(h($v['reviewer_notes'])) : '<span class="muted">لا يوجد</span>') . '</dd></div>';
    $o .= '<div class="wide"><dt>الاختلافات في المحتوى</dt><dd>';
    if ($v['content_differences']) {
        $o .= '<ul>';
        foreach ($v['content_differences'] as $df) {
            $o .= '<li><strong>' . h(DIFF_TYPES[$df['type']] ?? $df['type']) . '</strong> — الديوان: ' . val($df['diwan_article']) . ' / قسطاس: ' . val($df['qistas_article']) . ($df['note'] !== '' ? '<br>' . nl2br(h($df['note'])) : '') . '</li>';
        }
        $o .= '</ul>';
    } else {
        $o .= '<span class="muted">لا يوجد</span>';
    }
    $o .= '</dd></div>';
    if (!empty($v['admin_notes'])) {
        $o .= '<div class="wide"><dt>ملاحظات المسؤول</dt><dd>' . nl2br(h($v['admin_notes'])) . '</dd></div>';
    }
    return $o . '</dl>';
}
