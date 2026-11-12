<?php
/** الصفحة الثانية: بطاقات التشريعات — اختيار ذاتي للتشريعات من قبل المتطوعين. */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$records = records_all();
$reviews = reviews_all();

$f = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'number' => trim((string)($_GET['number'] ?? '')),
    'year' => trim((string)($_GET['year'] ?? '')),
    'category' => (string)($_GET['category'] ?? ''),
    'status' => (string)($_GET['status'] ?? ''),
    'approval' => (string)($_GET['approval'] ?? ''),
    'volunteer' => (string)($_GET['volunteer'] ?? ''),
    'priority' => (string)($_GET['priority'] ?? ''),
    'sort' => (string)($_GET['sort'] ?? 'priority'),
    'mine' => !empty($_GET['mine']),
    'all' => !empty($_GET['all']) && is_admin($user),
];
$active = $f['mine'] ? 'mine' : 'cards';
$qn = $f['q'] !== '' ? ar_normalize($f['q']) : '';

$list = [];
foreach ($records as $r) {
    $rev = $reviews[$r['id']] ?? null;
    $st = review_status($rev);
    if (!$f['all'] && !record_in_scope($r)) continue;
    if ($f['mine'] && ($rev['volunteer_id'] ?? '') !== $user['id']) continue;
    if ($f['category'] !== '' && $r['category'] !== $f['category']) continue;
    if ($f['status'] !== '' && $st !== $f['status']) continue;
    if ($f['approval'] !== '' && approval_status($rev) !== $f['approval']) continue;
    if ($f['priority'] !== '' && (string)$r['priority'] !== $f['priority']) continue;
    if ($f['volunteer'] !== '') {
        if ($f['volunteer'] === '_none' ? !empty($rev['volunteer_id']) : ($rev['volunteer_id'] ?? '') !== $f['volunteer']) continue;
    }
    if ($f['number'] !== '' && (string)($r['diwan']['number'] ?? '') !== $f['number'] && (string)($r['qistas']['number'] ?? '') !== $f['number']) continue;
    if ($f['year'] !== '' && (string)($r['diwan']['year'] ?? '') !== $f['year'] && (string)($r['qistas']['year'] ?? '') !== $f['year']) continue;
    if ($qn !== '') {
        foreach (explode(' ', $qn) as $w) {
            if ($w !== '' && mb_strpos($r['search'], $w) === false) continue 2;
        }
    }
    $list[] = [$r, $rev];
}

usort($list, function ($a, $b) use ($f) {
    [$ra] = $a; [$rb] = $b;
    switch ($f['sort']) {
        case 'year': return [$ra['year'] ?? 9999, $ra['id']] <=> [$rb['year'] ?? 9999, $rb['id']];
        case 'year_desc': return [$rb['year'] ?? 0, $ra['id']] <=> [$ra['year'] ?? 0, $rb['id']];
        case 'name': return strcmp($ra['name'], $rb['name']);
        case 'updated': return strcmp((string)($b[1]['updated_at'] ?? ''), (string)($a[1]['updated_at'] ?? ''));
        default: return [$ra['priority'], $ra['year'] ?? 9999, $ra['id']] <=> [$rb['priority'], $rb['year'] ?? 9999, $rb['id']];
    }
});

$per = 30;
$total = count($list);
$pages = max(1, (int)ceil($total / $per));
$page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
$slice = array_slice($list, ($page - 1) * $per, $per);
$users = users_all();
$qsBase = $_GET;
unset($qsBase['page']);

ob_start();
if (!$records): ?>
  <div class="alert alert-info">لم يتم رفع ملف المطابقة الأساسي بعد.<?= is_admin($user) ? ' <a href="admin.php">ارفعه من صفحة إدارة النظام</a>.' : ' يرجى التواصل مع المسؤول.' ?></div>
<?php else: ?>
<form class="filters card" method="get" role="search" aria-label="البحث والتصفية">
  <?php if ($f['mine']): ?><input type="hidden" name="mine" value="1"><?php endif; ?>
  <label class="grow">اسم التشريع<input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="كلمات من الاسم"></label>
  <label>الرقم<input type="text" name="number" value="<?= h($f['number']) ?>" inputmode="numeric" size="6"></label>
  <label>سنة الصدور<input type="text" name="year" value="<?= h($f['year']) ?>" inputmode="numeric" size="6"></label>
  <label>فئة السجل<select name="category"><option value="">الكل</option><?php foreach (CATEGORIES as $k => $l): ?><option value="<?= h($k) ?>" <?= $f['category'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
  <label>حالة التدقيق<select name="status"><option value="">الكل</option><?php foreach (STATUSES as $k => $l): ?><option value="<?= h($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
  <label>حالة الاعتماد<select name="approval"><option value="">الكل</option><?php foreach (APPROVAL_STATUSES as $k => $l): if ($k === '') continue; ?><option value="<?= h($k) ?>" <?= $f['approval'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
  <label>المتطوع المسؤول<select name="volunteer"><option value="">الكل</option><option value="_none" <?= $f['volunteer'] === '_none' ? 'selected' : '' ?>>لا يوجد</option><?php foreach ($users as $u): if ($u['role'] !== 'volunteer') continue; ?><option value="<?= h($u['id']) ?>" <?= $f['volunteer'] === $u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; ?></select></label>
  <label>الأولوية<select name="priority"><option value="">الكل</option><?php foreach (PRIORITY_LABELS as $k => $l): ?><option value="<?= $k ?>" <?= $f['priority'] === (string)$k ? 'selected' : '' ?>><?= $k ?> — <?= h($l) ?></option><?php endforeach; ?></select></label>
  <label>الترتيب<select name="sort"><?php foreach (['priority' => 'حسب الأولوية', 'year' => 'الأقدم أولاً', 'year_desc' => 'الأحدث أولاً', 'name' => 'الاسم', 'updated' => 'آخر تحديث'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['sort'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
  <?php if (is_admin($user)): ?><label class="check inline"><input type="checkbox" name="all" value="1" <?= $f['all'] ? 'checked' : '' ?>><span>إظهار السجلات خارج النطاق</span></label><?php endif; ?>
  <div class="filter-actions"><button class="btn btn-primary" type="submit">تطبيق</button><a class="btn btn-ghost" href="cards.php<?= $f['mine'] ? '?mine=1' : '' ?>">مسح</a></div>
</form>

<p class="result-count muted"><?= number_format($total) ?> تشريع<?= !$f['all'] ? ' ضمن نطاق التدقيق (' . h(scope_label()) . ')' : '' ?></p>

<div class="cards-grid">
<?php foreach ($slice as [$r, $rev]):
  $st = review_status($rev);
  $owner = $rev['volunteer_name'] ?? '';
  $mineCard = ($rev['volunteer_id'] ?? '') === $user['id'];
  $pct = review_progress($rev);
  $sources = ['matched' => 'الديوان وقسطاس', 'diwan_only' => 'الديوان فقط', 'qistas_only' => 'قسطاس فقط'][$r['category']];
  $url = 'review.php?id=' . rawurlencode($r['id']);
?>
  <article class="leg-card st-border-<?= h($st) ?><?= $mineCard ? ' mine' : '' ?>">
    <header>
      <div class="lc-badges"><?= category_badge($r['category']) ?><?= status_badge($st) ?><?= approval_badge(approval_status($rev)) ?></div>
      <h3><a href="<?= h($url) ?>"><?= h(record_title($r)) ?></a></h3>
      <p class="lc-ref"><?= h(record_ref($r)) ?><?php if ($r['is_amendment']): ?> · <span class="muted">نظام معدل<?= $r['base_year'] && $r['base_year'] !== $r['year'] ? ' (الأصلي ' . (int)$r['base_year'] . ')' : '' ?></span><?php endif; ?></p>
    </header>
    <dl class="lc-meta">
      <div><dt>المصادر</dt><dd><?= h($sources) ?></dd></div>
      <div><dt>حالة المراجعة</dt><dd><?= h(STATUSES[$st]) ?></dd></div>
      <div><dt>المتطوع المسؤول</dt><dd><?= $owner !== '' ? '<strong>' . h($owner) . '</strong>' . ($mineCard ? ' <span class="muted">(أنت)</span>' : '') : 'لا يوجد' ?></dd></div>
      <?php if ($rev && approval_status($rev) !== ''): ?><div><dt>حالة الاعتماد</dt><dd><?= h(APPROVAL_STATUSES[approval_status($rev)]) ?></dd></div><?php endif; ?>
      <?php if ($rev && !empty($rev['final_decision'])): ?><div><dt>القرار</dt><dd><?= h(decision_label($r['category'], $rev['final_decision'])) ?></dd></div><?php endif; ?>
      <?php if (!empty($r['match']['confidence'])): ?><div><dt>الثقة في المطابقة</dt><dd><?= h($r['match']['confidence']) ?></dd></div><?php endif; ?>
    </dl>
    <?php if ($rev && $st !== 'not_started'): ?>
      <div class="lc-progress"><span>نسبة الإنجاز: <?= $pct ?>%</span><?= progress_bar($pct) ?></div>
    <?php endif; ?>
    <footer>
      <?php if ($st === 'not_started' && !is_admin($user)): ?>
        <form method="post" action="<?= h($url) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="start"><button class="btn btn-primary" type="submit">ابدأ التدقيق</button></form>
        <a class="btn btn-ghost" href="<?= h($url) ?>">عرض التفاصيل</a>
      <?php elseif ($mineCard && in_array($st, EDITABLE_STATUSES, true)): ?>
        <a class="btn btn-primary" href="<?= h($url) ?>">متابعة التدقيق</a>
      <?php elseif (is_admin($user) && $st === 'done' && approval_status($rev) === 'pending'): ?>
        <a class="btn btn-primary" href="<?= h($url) ?>#form">مراجعة واعتماد</a>
      <?php else: ?>
        <a class="btn" href="<?= h($url) ?>">عرض التفاصيل</a>
      <?php endif; ?>
    </footer>
  </article>
<?php endforeach; ?>
</div>
<?php if (!$slice): ?><div class="empty-note">لا توجد تشريعات مطابقة لخيارات البحث.</div><?php endif; ?>

<?php if ($pages > 1): ?>
<nav class="pager" aria-label="الصفحات">
  <?php $mk = fn($p) => 'cards.php?' . http_build_query($qsBase + ['page' => $p]); ?>
  <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= h($mk($page - 1)) ?>">→ السابق</a><?php endif; ?>
  <span>صفحة <?= $page ?> من <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= h($mk($page + 1)) ?>">التالي ←</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif;
$titles = ['mine' => 'مراجعاتي', 'cards' => 'بطاقات التشريعات'];
render_page($titles[$active], ob_get_clean(), $active, ['subtitle' => $active === 'cards' ? 'اختر التشريع الذي تريد مراجعته بنفسك. التشريع قيد التنفيذ لدى متطوع آخر يظهر باسمه ولا يمكن تعديل مراجعته.' : '', 'scripts' => ['assets/app.js?v=3']]);
