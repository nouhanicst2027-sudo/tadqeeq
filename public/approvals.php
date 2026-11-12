<?php
/** اعتماد النتائج: اعتماد جماعي لعدة تشريعات دفعة واحدة (للمسؤول فقط). */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$user = require_admin();
$records = records_all();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $qs = (string)($_POST['back'] ?? '');
    try {
        $res = bulk_approve((array)($_POST['ids'] ?? []), $user);
        $msg = 'تم اعتماد ' . count($res['approved']) . ' تشريع وحُفظت النتائج في ملف Excel.';
        if ($res['skipped']) {
            $names = array_map(fn($id) => isset($records[$id]) ? record_title($records[$id]) . ' (' . record_ref($records[$id]) . ')' : $id, array_slice($res['skipped'], 0, 15));
            $msg .= "\nلم يُعتمد " . count($res['skipped']) . ' لأن حالته تغيّرت قبل التنفيذ:' . "\n- " . implode("\n- ", $names);
        }
        flash('success', $msg);
    } catch (Throwable $e) {
        flash('error', error_message($e));
    }
    redirect('approvals.php' . (preg_match('/^[a-z_=&0-9%A-Z.\-]*$/', $qs) && $qs !== '' ? '?' . $qs : ''));
}

$fCat = (string)($_GET['category'] ?? '');
$fVol = (string)($_GET['volunteer'] ?? '');
$fDec = (string)($_GET['decision'] ?? '');
$fUn = !empty($_GET['unresolved']);
$list = [];
$volunteers = [];
foreach (reviews_all() as $rid => $rev) {
    if (review_status($rev) !== 'done' || approval_status($rev) !== 'pending') continue;
    $volunteers[$rev['volunteer_id']] = $rev['volunteer_name'];
    $cat = $rev['category'];
    if ($fCat !== '' && $cat !== $fCat) continue;
    if ($fVol !== '' && $rev['volunteer_id'] !== $fVol) continue;
    if ($fDec !== '' && ($fDec === 'full') !== decision_is_full($cat, $rev['final_decision'])) continue;
    $rec = $records[$rid] ?? null;
    $un = $rec ? unresolved_fields($rec, $rev) : [];
    if ($fUn && !$un) continue;
    $list[] = [$rid, $rev, $rec, $un];
}
usort($list, fn($a, $b) => strcmp((string)$a[1]['submitted_at'], (string)$b[1]['submitted_at']));
$back = http_build_query(array_filter(['category' => $fCat, 'volunteer' => $fVol, 'decision' => $fDec, 'unresolved' => $fUn ? '1' : '']));

ob_start();
?>
<form class="filters card" method="get" aria-label="تصفية">
  <label>فئة السجل<select name="category"><option value="">الكل</option><?php foreach (CATEGORIES as $k => $l): ?><option value="<?= h($k) ?>" <?= $fCat === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
  <label>المتطوع<select name="volunteer"><option value="">الكل</option><?php foreach ($volunteers as $vid => $vn): ?><option value="<?= h($vid) ?>" <?= $fVol === $vid ? 'selected' : '' ?>><?= h($vn) ?></option><?php endforeach; ?></select></label>
  <label>نوع القرار<select name="decision"><option value="">الكل</option><option value="full" <?= $fDec === 'full' ? 'selected' : '' ?>>تطابق مؤكد / موجود</option><option value="other" <?= $fDec === 'other' ? 'selected' : '' ?>>باقي القرارات</option></select></label>
  <label class="check inline"><input type="checkbox" name="unresolved" value="1" <?= $fUn ? 'checked' : '' ?>><span>فيها حقول غير محسومة فقط</span></label>
  <div class="filter-actions"><button class="btn btn-primary" type="submit">تطبيق</button><a class="btn btn-ghost" href="approvals.php">مسح</a></div>
</form>

<?php if (!$list): ?>
  <div class="empty-note">لا توجد نتائج بانتظار الاعتماد<?= ($fCat || $fVol || $fDec || $fUn) ? ' ضمن هذه التصفية' : '' ?>.</div>
<?php else: ?>
<form method="post" id="bulk-form"><?= csrf_field() ?>
  <input type="hidden" name="back" value="<?= h($back) ?>">
  <div class="bulk-bar card">
    <label class="check"><input type="checkbox" id="select-all"><span>تحديد الكل (<?= count($list) ?>)</span></label>
    <span id="sel-count" class="muted" aria-live="polite">لم يتم تحديد أي تشريع</span>
    <button class="btn btn-primary" type="submit" id="bulk-approve" disabled>اعتماد المحدد</button>
  </div>
  <ul class="approve-list">
  <?php foreach ($list as [$rid, $rev, $rec, $un]): $cat = $rev['category']; $full = decision_is_full($cat, $rev['final_decision']); ?>
    <li class="approve-item card">
      <label class="check"><input type="checkbox" name="ids[]" value="<?= h($rid) ?>" class="row-check" aria-label="تحديد <?= h($rec ? record_title($rec) : $rid) ?>"></label>
      <div class="ai-body">
        <div class="ai-title"><a href="review.php?id=<?= h(rawurlencode($rid)) ?>" target="_blank" rel="noopener"><strong><?= h($rec ? record_title($rec) : $rev['legislation']) ?></strong></a>
          <span class="muted small"><?= h($rec ? record_ref($rec) : '') ?></span> <?= category_badge($cat) ?></div>
        <div class="small">المتطوع: <strong><?= h($rev['volunteer_name']) ?></strong> · انتهت في <?= h($rev['submitted_at']) ?></div>
        <div class="ai-decision <?= $full ? 'full' : 'notfull' ?>">القرار: <strong><?= h(decision_label($cat, $rev['final_decision'])) ?></strong>
          <?php if ($rev['content_differences']): ?><span class="muted small"> · <?= count($rev['content_differences']) ?> اختلاف موثّق</span><?php endif; ?></div>
        <div class="ai-flags">
          <?php $avOk = array_filter($rev['approved_values'] ?: [], fn($a, $k) => isset(VALUE_FIELDS[$k]) && ($a['src'] ?? '') !== '', ARRAY_FILTER_USE_BOTH); if ($avOk): ?><span class="badge ok"><?= count($avOk) ?> قيمة معتمدة</span><?php endif; ?>
          <?php if ($rec): $se = source_errors($rec, $rev); foreach (['diwan' => 'الديوان', 'qistas' => 'قسطاس'] as $sk => $sl): if ($se[$sk]): ?><span class="badge warn">أخطاء <?= $sl ?>: <?= h(implode('، ', $se[$sk])) ?></span><?php endif; endforeach; endif; ?>
          <?php if ($un): ?><span class="badge danger" title="<?= h(unresolved_labels($un)) ?>"><?= count($un) ?> حقل غير محسوم: <?= h(unresolved_labels($un)) ?></span><?php endif; ?>
        </div>
        <?php if ($avOk): ?><div class="small muted"><?php foreach ($avOk as $fk => $a): ?><span class="av-line"><?= h(VALUE_FIELDS[$fk] ?? $fk) ?>: <strong><?= h(mb_strimwidth($a['value'], 0, 60, '…')) ?></strong> (<?= h(VALUE_SOURCES[$a['src']] ?? '') ?>)</span> · <?php endforeach; ?></div><?php endif; ?>
        <?php if ($rev['reviewer_notes'] !== ''): ?><div class="ai-notes small"><?= nl2br(h(mb_strimwidth($rev['reviewer_notes'], 0, 400, '…'))) ?></div><?php endif; ?>
      </div>
      <a class="btn btn-sm btn-ghost" href="review.php?id=<?= h(rawurlencode($rid)) ?>#form">التفاصيل / إعادة للتصحيح</a>
    </li>
  <?php endforeach; ?>
  </ul>
</form>
<script>
(function () {
  var all = document.getElementById('select-all'), rows = document.querySelectorAll('.row-check'),
      btn = document.getElementById('bulk-approve'), cnt = document.getElementById('sel-count'), form = document.getElementById('bulk-form');
  function n() { return document.querySelectorAll('.row-check:checked').length; }
  function upd() {
    var k = n(); btn.disabled = k === 0;
    cnt.textContent = k ? ('تم تحديد ' + k + ' تشريع') : 'لم يتم تحديد أي تشريع';
    all.checked = k === rows.length; all.indeterminate = k > 0 && k < rows.length;
  }
  all.addEventListener('change', function () { rows.forEach(function (r) { r.checked = all.checked; }); upd(); });
  rows.forEach(function (r) { r.addEventListener('change', upd); });
  form.addEventListener('submit', function (e) {
    if (!window.confirm('اعتماد ' + n() + ' تشريع دفعة واحدة؟ ستُحفظ النتائج في ملف Excel.')) { e.preventDefault(); return; }
    btn.disabled = true; btn.textContent = 'جارٍ الاعتماد والحفظ...';
  });
  upd();
})();
</script>
<?php endif;
render_page('بانتظار الاعتماد', ob_get_clean(), 'pending', ['subtitle' => 'حدد التشريعات التي راجعتها ثم اضغط «اعتماد المحدد». لإعادة تشريع للتصحيح افتح تفاصيله.']);
