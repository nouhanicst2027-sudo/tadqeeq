<?php
/** قالب الصفحات والأدوات المساعدة للعرض. */
declare(strict_types=1);

function h($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function val($v, string $empty = '—'): string
{
    return ($v === null || $v === '') ? '<span class="muted">' . $empty . '</span>' : h($v);
}

function status_badge(string $status): string
{
    return '<span class="badge st-' . h($status) . '">' . h(STATUSES[$status] ?? $status) . '</span>';
}

function approval_badge(string $ap): string
{
    if ($ap === '') {
        return '';
    }
    return '<span class="badge ap-' . h($ap) . '">' . h(APPROVAL_STATUSES[$ap] ?? $ap) . '</span>';
}

function category_badge(string $cat): string
{
    return '<span class="badge cat-' . h($cat) . '">' . h(CATEGORIES[$cat] ?? $cat) . '</span>';
}

function progress_bar(int $pct, string $label = ''): string
{
    $pct = max(0, min(100, $pct));
    return '<div class="progress" role="progressbar" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100" aria-label="' . h($label ?: 'نسبة الإنجاز') . '">'
        . '<div class="progress-fill" style="width:' . $pct . '%"></div></div>';
}

function nav_item(string $href, string $label, string $key, string $active, string $icon): string
{
    $cls = $key === $active ? ' class="active" aria-current="page"' : '';
    return '<a href="' . h($href) . '"' . $cls . '><span class="nav-ico" aria-hidden="true">' . $icon . '</span>' . h($label) . '</a>';
}

function render_page(string $title, string $content, string $active, array $opts = []): void
{
    $user = function_exists('current_user') ? current_user() : null;
    $flashes = take_flashes();
    $projectTitle = settings()['project_title'] ?? 'نظام تدقيق التشريعات';
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — <?= h($projectTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Noto+Naskh+Arabic:wght@400;600&display=swap">
<link rel="stylesheet" href="assets/style.css?v=3">
</head>
<body class="<?= $user ? 'has-nav' : 'no-nav' ?>">
<?php if ($user): ?>
<aside class="sidebar" id="sidebar">
  <div class="brand">
    <div class="brand-mark" aria-hidden="true">⚖</div>
    <div><div class="brand-title"><?= h($projectTitle) ?></div><div class="brand-sub">الديوان · قسطاس</div></div>
  </div>
  <nav>
    <?= nav_item('index.php', 'لوحة التحكم', 'dashboard', $active, '◧') ?>
    <?= nav_item('cards.php', 'بطاقات التشريعات', 'cards', $active, '▤') ?>
    <?php if (!is_admin($user)): ?>
    <?= nav_item('cards.php?mine=1', 'مراجعاتي', 'mine', $active, '✎') ?>
    <?php endif; ?>
    <?php if (is_admin($user)): ?>
    <?= nav_item('approvals.php', 'بانتظار الاعتماد', 'pending', $active, '✓') ?>
    <?= nav_item('admin.php', 'إدارة النظام والملفات', 'admin', $active, '⚙') ?>
    <?php endif; ?>
  </nav>
  <div class="sidebar-foot">
    <div class="who"><strong><?= h($user['name']) ?></strong><span><?= h(ROLES[$user['role']]) ?></span></div>
    <form method="post" action="logout.php"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">تسجيل الخروج</button></form>
  </div>
</aside>
<button class="nav-toggle" type="button" aria-controls="sidebar" aria-expanded="false" onclick="document.body.classList.toggle('nav-open');this.setAttribute('aria-expanded',document.body.classList.contains('nav-open'))">☰ القائمة</button>
<?php endif; ?>
<main class="main">
  <?php if (!empty($opts['header'])): ?><?= $opts['header'] ?><?php else: ?>
  <header class="page-head"><h1><?= h($title) ?></h1><?php if (!empty($opts['subtitle'])): ?><p class="muted"><?= $opts['subtitle'] ?></p><?php endif; ?></header>
  <?php endif; ?>
  <?php foreach ($flashes as $f): ?>
    <div class="alert alert-<?= h($f['type']) ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= nl2br(h($f['msg'])) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<?php foreach (($opts['scripts'] ?? []) as $s): ?><script src="<?= h($s) ?>"></script><?php endforeach; ?>
</body>
</html>
<?php
}

/** معالجة موحدة للأخطاء في الطلبات: عرض رسالة واضحة وعدم فقدان أي شيء بصمت. */
function error_message(Throwable $e): string
{
    if ($e instanceof ValidationException) {
        return implode("\n", $e->errors);
    }
    if ($e instanceof LockException) {
        return $e->getMessage() . "\nلم يتم حفظ أي تغيير. يمكنك الضغط على زر الحفظ مرة أخرى بعد لحظات.";
    }
    if ($e instanceof AppException) {
        return $e->getMessage();
    }
    app_log('error', 'unexpected', ['e' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    return 'حدث خطأ غير متوقع ولم يتم حفظ التغيير. تم تسجيل الخطأ في سجل النظام. (' . $e->getMessage() . ')';
}
