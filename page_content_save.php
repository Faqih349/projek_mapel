<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pageContentReply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pageContentReply(405, ['ok'=>false, 'message'=>'Metode permintaan tidak didukung.']);
if (($_SESSION['user']['role'] ?? '') !== 'teacher') pageContentReply(403, ['ok'=>false, 'message'=>'Hanya akun guru yang dapat mengubah isi halaman.']);
$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($payload['csrfToken'] ?? ''))) {
    pageContentReply(403, ['ok'=>false, 'message'=>'Sesi berakhir. Muat ulang halaman lalu coba lagi.']);
}
$page = (string)($payload['page'] ?? '');
$allowedPages = ['beranda','kelas','materi','tugas','informasi','leaderboard','bantuan'];
$classId = null;
if (preg_match('/^class_(\d+)_informasi$/', $page, $matches)) {
    $classId = (int)$matches[1];
    try {
        $owner = mutqinDb()->prepare('SELECT id FROM class_groups WHERE id=:class_id AND teacher_id=(SELECT id FROM users WHERE username=:username AND role=\'teacher\') LIMIT 1');
        $owner->execute(['class_id'=>$classId,'username'=>(string)$_SESSION['user']['username']]);
        if (!$owner->fetchColumn()) pageContentReply(403, ['ok'=>false,'message'=>'Kamu tidak memiliki akses mengelola informasi kelas ini.']);
    } catch (Throwable $exception) {
        error_log('MutqinSpace class information access error: ' . $exception->getMessage());
        pageContentReply(503, ['ok'=>false,'message'=>'Database kelas belum dapat diakses.']);
    }
} elseif (!in_array($page, $allowedPages, true)) pageContentReply(422, ['ok'=>false, 'message'=>'Halaman tujuan tidak valid.']);
$path = __DIR__ . '/storage/page_content.json';
$allContent = json_decode((string)file_get_contents($path), true);
if (!is_array($allContent)) pageContentReply(500, ['ok'=>false, 'message'=>'Data isi halaman tidak dapat dibaca.']);
if (!isset($allContent[$page]) || !is_array($allContent[$page])) $allContent[$page] = [];
$action = (string)($payload['action'] ?? 'save');
$id = (string)($payload['id'] ?? '');
$index = null;
foreach ($allContent[$page] as $key => $item) if (($item['id'] ?? '') === $id && $id !== '') { $index = $key; break; }

if ($action === 'delete') {
    if ($index === null) pageContentReply(404, ['ok'=>false, 'message'=>'Konten yang ingin dihapus tidak ditemukan.']);
    array_splice($allContent[$page], $index, 1);
} elseif ($action === 'save') {
    $category = trim((string)($payload['category'] ?? ''));
    $title = trim((string)($payload['title'] ?? ''));
    $body = trim((string)($payload['body'] ?? ''));
    $length = static fn(string $value): int => function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($title === '' || $length($title) > 120 || $body === '' || $length($body) > 10000 || $length($category) > 40) {
        pageContentReply(422, ['ok'=>false, 'message'=>'Isi judul dan konten. Judul maksimal 120 karakter dan isi maksimal 10.000 karakter.']);
    }
    if ($index === null) {
        $id = 'content_' . bin2hex(random_bytes(8));
        $allContent[$page][] = ['id'=>$id, 'category'=>$category, 'title'=>$title, 'body'=>$body];
    } else {
        $allContent[$page][$index] = ['id'=>$id, 'category'=>$category, 'title'=>$title, 'body'=>$body];
    }
} else {
    pageContentReply(422, ['ok'=>false, 'message'=>'Aksi tidak dikenal.']);
}

$encoded = json_encode($allContent, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
if ($encoded === false || file_put_contents($path, $encoded, LOCK_EX) === false) pageContentReply(500, ['ok'=>false, 'message'=>'Isi halaman belum bisa disimpan. Periksa izin tulis folder storage.']);
pageContentReply(200, ['ok'=>true, 'items'=>$allContent[$page]]);