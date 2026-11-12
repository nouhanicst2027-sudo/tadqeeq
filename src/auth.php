<?php
/**
 * المستخدمون (ملف users.json) وتسجيل الدخول والصلاحيات وحماية CSRF.
 */
declare(strict_types=1);

const ROLES = ['admin' => 'مسؤول', 'volunteer' => 'متطوع'];

function users_path(): string
{
    return data_path('users.json');
}

function users_all(): array
{
    $u = read_json(users_path(), []);
    return is_array($u) ? $u : [];
}

function user_find(string $id): ?array
{
    foreach (users_all() as $u) {
        if ($u['id'] === $id) {
            return $u;
        }
    }
    return null;
}

function user_by_username(string $username): ?array
{
    $username = mb_strtolower(trim($username));
    foreach (users_all() as $u) {
        if (mb_strtolower($u['username']) === $username) {
            return $u;
        }
    }
    return null;
}

function has_admin(): bool
{
    foreach (users_all() as $u) {
        if ($u['role'] === 'admin' && !empty($u['active'])) {
            return true;
        }
    }
    return false;
}

/** تعديل ملف المستخدمين تحت قفل مع نسخة احتياطية. */
function users_mutate(callable $fn)
{
    return with_lock('users', function () use ($fn) {
        $users = users_all();
        $result = $fn($users);
        backup_file(users_path(), 'backups/users', 'users', 100);
        write_json(users_path(), array_values($users));
        return $result;
    });
}

function validate_user_input(string $username, string $name, ?string $password, bool $requirePassword): array
{
    $errors = [];
    if (!preg_match('/^[A-Za-z0-9_.\-]{3,32}$/', $username)) {
        $errors[] = 'اسم المستخدم يجب أن يكون من 3 إلى 32 حرفاً لاتينياً أو رقماً (يسمح بـ _ . -).';
    }
    if (mb_strlen(trim($name)) < 2 || mb_strlen($name) > 80) {
        $errors[] = 'الاسم الظاهر مطلوب (من 2 إلى 80 حرفاً).';
    }
    if ($requirePassword || ($password !== null && $password !== '')) {
        if ($password === null || mb_strlen($password) < 8) {
            $errors[] = 'كلمة المرور يجب ألا تقل عن 8 أحرف.';
        }
    }
    return $errors;
}

function user_create(string $username, string $name, string $password, string $role): array
{
    $errors = validate_user_input($username, $name, $password, true);
    if (!isset(ROLES[$role])) {
        $errors[] = 'نوع المستخدم غير صالح.';
    }
    if ($errors) {
        throw new ValidationException($errors);
    }
    return users_mutate(function (array &$users) use ($username, $name, $password, $role) {
        foreach ($users as $u) {
            if (mb_strtolower($u['username']) === mb_strtolower($username)) {
                throw new ValidationException(['اسم المستخدم مستخدم مسبقاً.']);
            }
        }
        $user = [
            'id' => 'u' . bin2hex(random_bytes(5)),
            'username' => $username,
            'name' => trim($name),
            'role' => $role,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'active' => true,
            'created_at' => now_str(),
            'last_login' => null,
        ];
        $users[] = $user;
        return $user;
    });
}

function user_update(string $id, array $changes): void
{
    users_mutate(function (array &$users) use ($id, $changes) {
        foreach ($users as &$u) {
            if ($u['id'] !== $id) {
                continue;
            }
            if (isset($changes['name'])) {
                $errors = validate_user_input($u['username'], $changes['name'], $changes['password'] ?? null, false);
                if ($errors) {
                    throw new ValidationException($errors);
                }
                $u['name'] = trim($changes['name']);
            }
            if (!empty($changes['password'])) {
                if (mb_strlen($changes['password']) < 8) {
                    throw new ValidationException(['كلمة المرور يجب ألا تقل عن 8 أحرف.']);
                }
                $u['password_hash'] = password_hash($changes['password'], PASSWORD_DEFAULT);
            }
            if (array_key_exists('active', $changes)) {
                if (!$changes['active'] && $u['role'] === 'admin') {
                    $admins = array_filter($users, fn($x) => $x['role'] === 'admin' && !empty($x['active']));
                    if (count($admins) <= 1) {
                        throw new ValidationException(['لا يمكن تعطيل آخر حساب مسؤول.']);
                    }
                }
                $u['active'] = (bool)$changes['active'];
            }
            if (isset($changes['last_login'])) {
                $u['last_login'] = $changes['last_login'];
            }
            return;
        }
        throw new ValidationException(['المستخدم غير موجود.']);
    });
}

function attempt_login(string $username, string $password): ?array
{
    $u = user_by_username($username);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        usleep(400000); // إبطاء محاولات التخمين
        return null;
    }
    if (empty($u['active'])) {
        throw new ValidationException(['هذا الحساب معطل. يرجى التواصل مع المسؤول.']);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = $u['id'];
    try {
        user_update($u['id'], ['last_login' => now_str()]);
    } catch (Throwable $e) {
        app_log('warning', 'last_login update failed', ['e' => $e->getMessage()]);
    }
    return $u;
}

function current_user(): ?array
{
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }
    $cached = null;
    if (!empty($_SESSION['uid'])) {
        $u = user_find($_SESSION['uid']);
        if ($u && !empty($u['active'])) {
            $cached = $u;
        } else {
            unset($_SESSION['uid']);
        }
    }
    return $cached;
}

function is_admin(?array $u = null): bool
{
    $u = $u ?? current_user();
    return $u && $u['role'] === 'admin';
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        if (!has_admin()) {
            redirect('setup.php');
        }
        redirect('login.php');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if (!is_admin($u)) {
        http_response_code(403);
        render_page('غير مصرح', '<div class="alert alert-error">هذه الصفحة متاحة للمسؤول فقط.</div>', '');
        exit;
    }
    return $u;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        render_page('انتهت الجلسة', '<div class="alert alert-error">انتهت صلاحية النموذج أو الجلسة. يرجى تحديث الصفحة والمحاولة مرة أخرى.</div>', '');
        exit;
    }
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}
