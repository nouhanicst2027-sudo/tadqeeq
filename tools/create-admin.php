<?php
// إنشاء حساب مسؤول من سطر الأوامر:  php tools/create-admin.php <اسم_المستخدم> "<الاسم الظاهر>" <كلمة_المرور>
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
[$_, $u, $n, $p] = $argv + [null, '', '', ''];
try { user_create($u, $n, $p, 'admin'); echo "تم إنشاء المسؤول: $u\n"; }
catch (Throwable $e) { fwrite(STDERR, 'خطأ: ' . error_message($e) . "\n"); exit(1); }
