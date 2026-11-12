<?php
/** الصفحة الأولى: لوحة التحكم. */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$records = records_all();
$reviews = reviews_all();

$catCount = array_fill_keys(array_keys(CATEGORIES), 0);
$stCount = array_fill_keys(array_keys(STATUSES), 0);
$apCount = ['pending' => 0, 'approved' => 0, 'returned' => 0];
$catSeg = [];
$decisions = [];
$mine = ['active' => [], 'pending' => 0, 'approved' => 0];
$total = 0;
foreach ($records as $r) {
    if (!record_in_scope($r)) continue;
    $total++;
    $rev = $reviews[$r['id']] ?? null;
    $st = review_status($rev);
    $ap = approval_status($rev);
    $catCount[$r['category']]++;
    $stCount[$st]++;
    if (isset($apCount[$ap])) $apCount[$ap]++;
    // مقاطع شريط التقدم: معتمد / منتهية بانتظار الاعتماد / قيد التنفيذ
    $seg = $st === 'done' ? ($ap === 'approved' ? 'approved' : 'pending') : ($st === 'in_progress' ? 'in_progress' : 'not_started');
    $catSeg[$r['category']][$seg] = ($catSeg[$r['category']][$seg] ?? 0) + 1;
    if ($ap === 'approved' && !empty($rev['final_decision'])) {
        $decisions[$r['category']][$rev['final_decision']] = ($decisions[$r['category']][$rev['final_decision']] ?? 0) + 1;
    }
    if ($rev && ($rev['volunteer_id'] ?? '') === $user['id']) {
        if ($st === 'in_progress') $mine['active'][] = [$r, $rev];
        if ($ap === 'pending') $mine['pending']++;
        if ($ap === 'approved') $mine['approved']++;
    }
}
$completion = $total ? round($apCount['approved'] * 100 / $total, 1) : 0;
$doneRate = $total ? round($stCount['done'] * 100 / $total, 1) : 0;
$pctOf = fn($n, $t) => $t ? round($n * 100 / $t) : 0;
$segLabels = ['approved' => 'معتمد', 'pending' => 'منتهية بانتظار الاعتماد', 'in_progress' => 'قيد التنفيذ'];

ob_start();
if (!$records): ?>
  <div class="alert alert-info">لا توجد بيانات بعد. <?= is_admin($user) ? '<a href="admin.php">ابدأ برفع ملف المطابقة الأساسي وملفات المواد</a>.' : 'يرجى انتظار المسؤول لرفع ملفات المشروع.' ?></div>
<?php else: ?>
<p class="scope-note">نطاق التدقيق الحالي: <strong><?= h(scope_label()) ?></strong><?php if (is_admin($user)): ?> · <a href="admin.php?tab=settings">تعديل النطاق</a><?php endif; ?></p>

<section class="overall card">
  <div>
    <div class="overall-label">نسبة الإنجاز الإجمالية <span class="muted small">(السجلات المعتمدة من المسؤول ÷ إجمالي السجلات)</span></div>
    <div class="overall-num"><?= $completion ?>%</div>
    <?= progress_bar((int)round($completion), 'نسبة الإنجاز الإجمالية') ?>
    <p class="muted small">المراجعات المنتهية: <?= $doneRate ?>% · المعتمد <?= number_format($apCount['approved']) ?> من <?= number_format($total) ?></p>
  </div>
</section>

<section class="stat-grid" aria-label="إحصائيات السجلات">
  <a class="stat" href="cards.php"><span class="stat-num"><?= number_format($total) ?></span><span class="stat-label">إجمالي التشريعات</span></a>
  <a class="stat cat-matched" href="cards.php?category=matched"><span class="stat-num"><?= number_format($catCount['matched']) ?></span><span class="stat-label">التشريعات المتطابقة</span></a>
  <a class="stat cat-diwan_only" href="cards.php?category=diwan_only"><span class="stat-num"><?= number_format($catCount['diwan_only']) ?></span><span class="stat-label">موجودة في الديوان فقط</span></a>
  <a class="stat cat-qistas_only" href="cards.php?category=qistas_only"><span class="stat-num"><?= number_format($catCount['qistas_only']) ?></span><span class="stat-label">موجودة في قسطاس فقط</span></a>
</section>

<section class="stat-grid six" aria-label="حالات المراجعة والاعتماد">
  <?php foreach (STATUSES as $k => $l): ?>
  <a class="stat st-<?= h($k) ?>" href="cards.php?status=<?= h($k) ?>"><span class="stat-num"><?= number_format($stCount[$k]) ?></span><span class="stat-label"><?= h($l) ?></span><span class="stat-pct muted small"><?= $pctOf($stCount[$k], $total) ?>%</span></a>
  <?php endforeach; ?>
  <?php foreach ($apCount as $k => $n): ?>
  <a class="stat ap-<?= h($k) ?>" href="<?= $k === 'pending' && is_admin($user) ? 'approvals.php' : 'cards.php?approval=' . h($k) ?>"><span class="stat-num"><?= number_format($n) ?></span><span class="stat-label"><?= h(APPROVAL_STATUSES[$k]) ?></span><span class="stat-pct muted small"><?= $pctOf($n, $total) ?>%</span></a>
  <?php endforeach; ?>
</section>

<section class="card section">
  <h2>التقدم حسب فئة السجل</h2>
  <div class="cat-progress">
  <?php foreach (CATEGORIES as $c => $l): $t = $catCount[$c]; if (!$t) continue; $cs = $catSeg[$c] ?? []; ?>
    <div class="cp-row">
      <div class="cp-label"><strong><?= h($l) ?></strong> <span class="muted small"><?= number_format($t) ?> سجل</span></div>
      <div class="stack" role="img" aria-label="<?= h($l) ?>: معتمد <?= (int)($cs['approved'] ?? 0) ?>، بانتظار الاعتماد <?= (int)($cs['pending'] ?? 0) ?>، قيد التنفيذ <?= (int)($cs['in_progress'] ?? 0) ?>">
        <?php foreach ($segLabels as $sg => $sl): $n = $cs[$sg] ?? 0; if (!$n) continue; ?>
          <span class="seg sg-<?= $sg ?>" style="width:<?= max(0.5, $n * 100 / $t) ?>%" title="<?= h($sl) ?>: <?= $n ?>"></span>
        <?php endforeach; ?>
      </div>
      <div class="cp-legend small muted">معتمد <?= (int)($cs['approved'] ?? 0) ?> · بانتظار الاعتماد <?= (int)($cs['pending'] ?? 0) ?> · قيد التنفيذ <?= (int)($cs['in_progress'] ?? 0) ?> · لم تبدأ <?= (int)($cs['not_started'] ?? 0) ?></div>
    </div>
  <?php endforeach; ?>
  </div>
  <div class="legend small"><?php foreach ($segLabels as $sg => $sl): ?><span><i class="seg-key sg-<?= $sg ?>"></i><?= h($sl) ?></span><?php endforeach; ?></div>
</section>

<?php if (!is_admin($user)): ?>
<section class="card section">
  <h2>مراجعاتي</h2>
  <p>قيد التنفيذ: <strong><?= count($mine['active']) ?></strong> · بانتظار الاعتماد: <strong><?= $mine['pending'] ?></strong> · معتمدة: <strong><?= $mine['approved'] ?></strong></p>
  <?php if ($mine['active']): ?>
  <ul class="plain-list">
    <?php foreach (array_slice($mine['active'], 0, 15) as [$r, $rev]): ?>
      <li><a href="review.php?id=<?= h(rawurlencode($r['id'])) ?>"><?= h(record_title($r)) ?></a> <span class="muted small"><?= h(record_ref($r)) ?></span> <?= status_badge(review_status($rev)) ?> <?= approval_badge(approval_status($rev)) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?><p class="muted">لا توجد مراجعات قيد التنفيذ. <a href="cards.php?status=not_started">اختر تشريعاً للبدء</a>.</p><?php endif; ?>
</section>
<?php endif; ?>

<section class="card section">
  <h2>نتائج التدقيق المعتمدة حسب القرار</h2>
  <?php if (!$decisions): ?><p class="muted">لا توجد نتائج معتمدة بعد.</p><?php else: ?>
  <div class="decision-cols">
  <?php foreach (CATEGORIES as $c => $l): if (empty($decisions[$c])) continue; ?>
    <div><h3><?= h($l) ?></h3><ul class="plain-list">
      <?php foreach ($decisions[$c] as $k => $n): ?><li><?= h(decision_label($c, $k)) ?>: <strong><?= $n ?></strong></li><?php endforeach; ?>
    </ul></div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<?php if (is_admin($user)):
  $vstats = [];
  foreach ($reviews as $rev) {
      if (empty($rev['volunteer_id'])) continue;
      $v = &$vstats[$rev['volunteer_id']];
      $v['name'] = $rev['volunteer_name'];
      $v[$rev['status']] = ($v[$rev['status']] ?? 0) + 1;
      $ap = approval_status($rev);
      if ($ap !== '') $v['ap_' . $ap] = ($v['ap_' . $ap] ?? 0) + 1;
      $v['last'] = max($v['last'] ?? '', $rev['updated_at']);
      unset($v);
  } ?>
<section class="card section">
  <h2>نشاط المتطوعين</h2>
  <?php if (!$vstats): ?><p class="muted">لم يبدأ أي متطوع بعد.</p><?php else: ?>
  <div class="table-wrap"><table class="simple">
    <thead><tr><th>المتطوع</th><th>قيد التنفيذ</th><th>منتهية</th><th>بانتظار الاعتماد</th><th>معتمدة</th><th>أعيدت للتصحيح</th><th>آخر نشاط</th></tr></thead>
    <tbody><?php foreach ($vstats as $vid => $v): ?><tr>
      <td><a href="cards.php?volunteer=<?= h(rawurlencode($vid)) ?>&all=1"><?= h($v['name']) ?></a></td>
      <td><?= (int)($v['in_progress'] ?? 0) ?></td><td><?= (int)($v['done'] ?? 0) ?></td><td><?= (int)($v['ap_pending'] ?? 0) ?></td><td><?= (int)($v['ap_approved'] ?? 0) ?></td><td><?= (int)($v['ap_returned'] ?? 0) ?></td><td class="muted small"><?= h($v['last']) ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php endif;
render_page('لوحة التحكم', ob_get_clean(), 'dashboard');
