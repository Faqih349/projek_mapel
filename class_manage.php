<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function classManageReply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') classManageReply(405, ['ok'=>false,'message'=>'Metode permintaan tidak didukung.']);
if (($_SESSION['user']['role'] ?? '') !== 'teacher') classManageReply(403, ['ok'=>false,'message'=>'Hanya guru yang dapat membuat kelas.']);
$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($data['csrfToken'] ?? ''))) classManageReply(403, ['ok'=>false,'message'=>'Sesi berakhir. Muat ulang halaman.']);

$className = trim((string)($data['className'] ?? ''));
$courseId = 'quran'; // All created classes are Pendidikan Agama.
$courseTitles = ['quran'=>'Pendidikan Agama Islam'];
$length = function_exists('mb_strlen') ? mb_strlen($className, 'UTF-8') : strlen($className);
if ($className === '' || $length > 100) classManageReply(422, ['ok'=>false,'message'=>'Isi nama kelas maksimal 100 karakter.']);

try {
    $db = mutqinDb();
    $teacher = $db->prepare('SELECT id,name FROM users WHERE username=:username AND role=\'teacher\' LIMIT 1');
    $teacher->execute(['username'=>(string)$_SESSION['user']['username']]);
    $teacherRow = $teacher->fetch();
    if (!$teacherRow) classManageReply(403, ['ok'=>false,'message'=>'Akun guru tidak ditemukan.']);
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i=0; $i<8; $i++) $code .= $alphabet[random_int(0, strlen($alphabet)-1)];
        $check = $db->prepare('SELECT id FROM class_groups WHERE join_code=:code');
        $check->execute(['code'=>$code]);
    } while ($check->fetchColumn() !== false);
    $insert = $db->prepare('INSERT INTO class_groups (teacher_id,course_id,class_name,join_code) VALUES (:teacher_id,:course_id,:class_name,:join_code)');
    $insert->execute(['teacher_id'=>$teacherRow['id'],'course_id'=>$courseId,'class_name'=>$className,'join_code'=>$code]);
    classManageReply(201, ['ok'=>true,'classGroup'=>['id'=>(int)$db->lastInsertId(),'className'=>$className,'courseId'=>$courseId,'courseTitle'=>$courseTitles[$courseId],'joinCode'=>$code,'teacherName'=>$teacherRow['name'],'memberCount'=>0,'students'=>[]]]);
} catch (PDOException $exception) {
    error_log('MutqinSpace class creation database error: ' . $exception->getMessage());
    classManageReply(500, ['ok'=>false,'message'=>'Kelas belum dapat disimpan ke database.']);
} catch (Throwable $exception) {
    error_log('MutqinSpace class creation error: ' . $exception->getMessage());
    classManageReply(500, ['ok'=>false,'message'=>'Terjadi kesalahan saat membuat kelas. Muat ulang halaman dan coba kembali.']);
}
