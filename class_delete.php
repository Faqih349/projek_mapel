<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function classDeleteReply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') classDeleteReply(405, ['ok'=>false,'message'=>'Metode permintaan tidak didukung.']);
if (($_SESSION['user']['role'] ?? '') !== 'teacher') classDeleteReply(403, ['ok'=>false,'message'=>'Hanya guru yang dapat menghapus kelas.']);
$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($data['csrfToken'] ?? ''))) classDeleteReply(403, ['ok'=>false,'message'=>'Sesi berakhir. Muat ulang halaman.']);
$classId = filter_var($data['classId'] ?? null, FILTER_VALIDATE_INT);
if ($classId === false || $classId < 1) classDeleteReply(422, ['ok'=>false,'message'=>'Kelas yang dipilih tidak valid.']);

try {
    $db = mutqinDb();
    $delete = $db->prepare('DELETE cg FROM class_groups cg JOIN users u ON u.id=cg.teacher_id WHERE cg.id=:class_id AND u.username=:username AND u.role=\'teacher\'');
    $delete->execute(['class_id'=>$classId, 'username'=>(string)$_SESSION['user']['username']]);
    if ($delete->rowCount() !== 1) classDeleteReply(404, ['ok'=>false,'message'=>'Kelas tidak ditemukan atau bukan milik akun guru ini.']);
    classDeleteReply(200, ['ok'=>true,'classId'=>(int)$classId]);
} catch (Throwable $exception) {
    error_log('MutqinSpace class deletion error: '.$exception->getMessage());
    classDeleteReply(500, ['ok'=>false,'message'=>'Kelas belum dapat dihapus. Coba lagi.']);
}
