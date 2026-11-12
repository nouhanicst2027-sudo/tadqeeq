<?php
/** الإعداد الأول: إنشاء حساب المسؤول (يعمل فقط عند عدم وجود أي مسؤول). */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if (has_admin()) redirect('login.php');
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) throw new ValidationException(['كلمتا المرور غير متطابقتين.']);
        // التحقق مجدداً تحت القفل لمنع إنشاء مسؤولين متزامنين
        if (has_admin()) redirect('login.php');
        user_create(trim((string)$_POST['username']), (string)$_POST['name'], (string)$_POST['password'], 'admin');
        attempt_login(trim((string)$_POST['username']), (string)$_POST['password']);
        flash('success', 'تم إنشاء حساب المسؤول. ابدأ برفع ملف المطابقة وملفات المواد.');
        redirect('admin.php');
    } catch (Throwable $e) {
        $errors = explode("\n", error_message($e));
    }
}
ob_start(); ?>
<div class="auth-box card">
  <div class="brand-mark big" aria-hidden="true">⚖</div>
  <h1>الإعداد الأول للنظام</h1>
  <p class="muted">أنشئ حساب المسؤول. تظهر هذه الصفحة مرة واحدة فقط.</p>
  <?php if ($errors): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <form method="post" class="stack-form"><?= csrf_field() ?>
    <label>الاسم الظاهر<input type="text" name="name" required maxlength="80" value="<?= h($_POST['name'] ?? '') ?>"></label>
    <label>اسم المستخدم (أحرف لاتينية)<input type="text" name="username" required dir="ltr" pattern="[A-Za-z0-9_.\-]{3,32}" value="<?= h($_POST['username'] ?? '') ?>"></label>
    <label>كلمة المرور (8 أحرف على الأقل)<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
    <label>تأكيد كلمة المرور<input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
    <button class="btn btn-primary btn-block" type="submit">إنشاء حساب المسؤول</button>
  </form>
</div>
<?php render_page('الإعداد الأول', ob_get_clean(), '', ['header' => ' ']);
