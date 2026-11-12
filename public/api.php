<?php
/** واجهة JSON داخلية لاسترجاع المواد التشريعية والبحث اليدوي في فهارس المصادر. */
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$user = current_user();
session_write_close();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'يجب تسجيل الدخول.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_GET['action'] ?? '');
$source = (string)($_GET['source'] ?? '');
try {
    if (!isset(SOURCES[$source])) {
        throw new ValidationException(['مصدر غير معروف.']);
    }
    if ($action === 'articles') {
        $id = (string)($_GET['id'] ?? '');
        if (!index_info($source)) {
            echo json_encode(['ok' => false, 'reason' => 'no_index', 'message' => 'لم يتم رفع ملف مواد ' . SOURCES[$source] . ' بعد. يرجى التواصل مع المسؤول.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($id === '') {
            echo json_encode(['ok' => false, 'reason' => 'no_id', 'message' => 'لا يوجد معرّف ' . SOURCES[$source] . ' لهذا السجل. يمكنك البحث عن سجل للمقارنة يدوياً.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $data = articles_get($source, $id);
        if (!$data) {
            echo json_encode(['ok' => false, 'reason' => 'not_found', 'message' => 'لا توجد مواد لهذا التشريع (المعرّف ' . $id . ') في ملف مواد ' . SOURCES[$source] . ' المتوفر.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'search') {
        $res = articles_search($source, [
            'number' => $_GET['number'] ?? '', 'year' => $_GET['year'] ?? '',
            'name' => $_GET['name'] ?? '', 'magazine' => $_GET['magazine'] ?? '',
        ]);
        echo json_encode(['ok' => true, 'results' => $res], JSON_UNESCAPED_UNICODE);
    } else {
        throw new ValidationException(['إجراء غير معروف.']);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => error_message($e)], JSON_UNESCAPED_UNICODE);
}
