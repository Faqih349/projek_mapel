<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function classMembersReply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') classMembersReply(405, ['ok'=>false,'message'=>'Metode permintaan tidak didukung.']);
if (($_SESSION['user']['role'] ?? '') !== 'teacher') classMembersReply(403, ['ok'=>false,'message'=>'Hanya guru yang dapat mengelola anggota kelas.']);
$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($data['csrfToken'] ?? ''))) classMembersReply(403, ['ok'=>false,'message'=>'Sesi berakhir. Muat ulang halaman.']);
$classId = filter_var($data['classId'] ?? null, FILTER_VALIDATE_INT);
$username = trim((string)($data['username'] ?? ''));
$action = (string)($data['action'] ?? 'add');
if ($classId === false || $classId < 1 || !in_array($action, ['add','remove'], true) || $username === '') classMembersReply(422, ['ok'=>false,'message'=>'Data anggota kelas tidak valid.']);

try {
    $db = mutqinDb();
    $owner = $db->prepare('SELECT id FROM class_groups WHERE id=:class_id AND teacher_id=(SELECT id FROM users WHERE username=:username AND role=\'teacher\') LIMIT 1');
    $owner->execute(['class_id'=>$classId,'username'=>(string)$_SESSION['user']['username']]);
    if (!$owner->fetchColumn()) classMembersReply(403, ['ok'=>false,'message'=>'Kelas tidak ditemukan atau bukan milik akun guru ini.']);
    $student = $db->prepare('SELECT id FROM users WHERE username=:username AND role=\'student\' LIMIT 1');
    $student->execute(['username'=>$username]);
    $studentId = $student->fetchColumn();
    if ($studentId === false) classMembersReply(404, ['ok'=>false,'message'=>'Akun siswa tidak ditemukan.']);
    if ($action === 'add') {
        $change = $db->prepare('INSERT IGNORE INTO class_memberships (class_group_id,student_id) VALUES (:class_id,:student_id)');
        $change->execute(['class_id'=>$classId,'student_id'=>$studentId]);
    } else {
        $change = $db->prepare('DELETE FROM class_memberships WHERE class_group_id=:class_id AND student_id=:student_id');
        $change->execute(['class_id'=>$classId,'student_id'=>$studentId]);
    }
    $list = $db->prepare('SELECT u.username,u.name,u.initials FROM class_memberships cm JOIN users u ON u.id=cm.student_id WHERE cm.class_group_id=:class_id ORDER BY u.name');
    $list->execute(['class_id'=>$classId]);
    $students = $list->fetchAll();
    classMembersReply(200, ['ok'=>true,'students'=>$students,'memberCount'=>count($students),'action'=>$action]);
} catch (Throwable $exception) {
    error_log('MutqinSpace class member management error: ' . $exception->getMessage());
    classMembersReply(500, ['ok'=>false,'message'=>'Daftar siswa belum dapat diperbarui. Coba lagi.']);
}
