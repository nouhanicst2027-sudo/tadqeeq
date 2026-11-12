<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (!has_admin()) redirect('setup.php');
if (current_user()) redirect('index.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (attempt_login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
            redirect('index.php');
        }
        $error = 'اسم المستخدم أو كلمة المرور غير صحيحة.';
    } catch (Throwable $e) {
        $error = error_message($e);
    }
}
ob_start(); ?>
<div class="auth-box card">
  <div class="brand-mark big" aria-hidden="true">⚖</div>
  <h1><?= h(settings()['project_title']) ?></h1>
  <p class="muted">تدقيق التشريعات الأردنية بين ديوان التشريع والرأي وقسطاس</p>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= nl2br(h($error)) ?></div><?php endif; ?>
  <form method="post" class="stack-form"><?= csrf_field() ?>
    <label>اسم المستخدم<input type="text" name="username" required autofocus autocomplete="username" dir="ltr" value="<?= h($_POST['username'] ?? '') ?>"></label>
    <label>كلمة المرور<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn btn-primary btn-block" type="submit">تسجيل الدخول</button>
  </form>
</div>
<?php render_page('تسجيل الدخول', ob_get_clean(), '', ['header' => ' ']);
