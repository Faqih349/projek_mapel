<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function classJoinReply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['user'])) classJoinReply(401, ['ok'=>false,'message'=>'Silakan masuk kembali.']);
if (($_SESSION['user']['role'] ?? '') !== 'student') classJoinReply(403, ['ok'=>false,'message'=>'Fitur masuk kelas menggunakan akun siswa.']);
$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($data['csrfToken'] ?? ''))) classJoinReply(403, ['ok'=>false,'message'=>'Sesi berakhir. Muat ulang halaman.']);
$code = strtoupper(preg_replace('/\s+/', '', (string)($data['code'] ?? '')));
if (!preg_match('/^[A-Z2-9]{8}$/', $code)) classJoinReply(422, ['ok'=>false,'message'=>'Masukkan kode kelas 8 karakter dari guru.']);

try {
    $db = mutqinDb();
    $query = $db->prepare('SELECT cg.id,cg.class_name,cg.course_id,cg.join_code,u.name AS teacher_name,(SELECT COUNT(*) FROM class_memberships cm WHERE cm.class_group_id=cg.id) AS member_count FROM class_groups cg JOIN users u ON u.id=cg.teacher_id WHERE cg.join_code=:code LIMIT 1');
    $query->execute(['code'=>$code]);
    $group = $query->fetch();
    if (!$group) classJoinReply(404, ['ok'=>false,'message'=>'Kode kelas tidak ditemukan. Periksa kembali kode dari guru.']);
    $student = $db->prepare('SELECT id,username,name,initials FROM users WHERE username=:username AND role=\'student\' LIMIT 1');
    $student->execute(['username'=>(string)$_SESSION['user']['username']]);
    $studentRow = $student->fetch();
    if (!$studentRow) classJoinReply(403, ['ok'=>false,'message'=>'Akun siswa tidak ditemukan.']);
    $studentId = $studentRow['id'];
    $join = $db->prepare('INSERT IGNORE INTO class_memberships (class_group_id,student_id) VALUES (:group_id,:student_id)');
    $join->execute(['group_id'=>$group['id'],'student_id'=>$studentId]);
    $courseTitles = ['quran'=>'Al-Qur’an dan Hadis','akhlak'=>'Akidah Akhlak','sejarah'=>'Sejarah Kebudayaan Islam','fikih'=>'Fikih'];
    classJoinReply(200, ['ok'=>true,'alreadyJoined'=>$join->rowCount() === 0,'classGroup'=>['id'=>(int)$group['id'],'className'=>$group['class_name'],'courseId'=>$group['course_id'],'courseTitle'=>$courseTitles[$group['course_id']] ?? $group['course_id'],'joinCode'=>$group['join_code'],'teacherName'=>$group['teacher_name'],'memberCount'=>(int)$group['member_count']+($join->rowCount() === 1 ? 1 : 0),'students'=>[['username'=>$studentRow['username'],'name'=>$studentRow['name'],'initials'=>$studentRow['initials']]]]]);
} catch (PDOException $exception) {
    error_log('MutqinSpace student class join database error: ' . $exception->getMessage());
    classJoinReply(500, ['ok'=>false,'message'=>'Belum dapat masuk kelas. Periksa koneksi database lalu coba lagi.']);
}
