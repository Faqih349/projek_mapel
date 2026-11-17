<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function submissionGradeReply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['user'])) {
    submissionGradeReply(401, ['ok'=>false,'message'=>'Silakan masuk kembali.']);
}
if (($_SESSION['user']['role'] ?? '') !== 'teacher') {
    submissionGradeReply(403, ['ok'=>false,'message'=>'Hanya guru yang dapat menilai pengumpulan.']);
}
$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($payload['csrfToken'] ?? ''))) {
    submissionGradeReply(403, ['ok'=>false,'message'=>'Sesi berakhir. Muat ulang halaman lalu coba lagi.']);
}
$score = $payload['score'] ?? null;
$feedback = trim((string)($payload['feedback'] ?? ''));
$feedbackLength = function_exists('mb_strlen') ? mb_strlen($feedback, 'UTF-8') : strlen($feedback);
if (!is_numeric($score) || !is_finite((float)$score) || (float)$score < 0 || (float)$score > 100 || $feedbackLength > 3000) {
    submissionGradeReply(422, ['ok'=>false,'message'=>'Nilai harus 0–100 dan umpan balik maksimal 3.000 karakter.']);
}

try {
    $db = mutqinDb();
    $statement = $db->prepare('SELECT s.task_id,s.student_id,a.type FROM submissions s JOIN assignments a ON a.task_id=s.task_id JOIN class_groups cg ON cg.id=a.class_group_id JOIN users teacher ON teacher.id=cg.teacher_id JOIN users pupil ON pupil.id=s.student_id JOIN class_memberships cm ON cm.class_group_id=a.class_group_id AND cm.student_id=s.student_id WHERE s.task_id=:task_id AND pupil.username=:student AND pupil.role=\'student\' AND teacher.username=:teacher AND teacher.role=\'teacher\' LIMIT 1');
    $statement->execute([
        'task_id'=>(string)($payload['taskId'] ?? ''),
        'student'=>(string)($payload['username'] ?? ''),
        'teacher'=>(string)$_SESSION['user']['username'],
    ]);
    $submission = $statement->fetch();
    if (!$submission) submissionGradeReply(404, ['ok'=>false,'message'=>'Pengumpulan tidak ditemukan atau bukan milik kelas yang Anda ajar.']);
    if (!in_array($submission['type'], ['essay','essay_quiz'], true)) {
        submissionGradeReply(422, ['ok'=>false,'message'=>'Penilaian manual hanya tersedia untuk tugas esai.']);
    }
    $gradedAt = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
    $update = $db->prepare('UPDATE submissions SET score=:score,feedback=:feedback,graded_by=(SELECT id FROM users WHERE username=:teacher AND role=\'teacher\' LIMIT 1),graded_at=:graded_at WHERE task_id=:task_id AND student_id=:student_id');
    $update->execute([
        'score'=>round((float)$score, 2),
        'feedback'=>$feedback === '' ? null : $feedback,
        'teacher'=>(string)$_SESSION['user']['username'],
        'graded_at'=>$gradedAt->format('Y-m-d H:i:s'),
        'task_id'=>$submission['task_id'],
        'student_id'=>(int)$submission['student_id'],
    ]);
    submissionGradeReply(200, ['ok'=>true,'score'=>round((float)$score, 2),'feedback'=>$feedback,'gradedAt'=>$gradedAt->format(DATE_ATOM)]);
} catch (Throwable $exception) {
    error_log('MutqinSpace submission grading failed: ' . $exception->getMessage());
    submissionGradeReply(500, ['ok'=>false,'message'=>'Penilaian belum dapat disimpan. Periksa koneksi dan struktur database.']);
}
