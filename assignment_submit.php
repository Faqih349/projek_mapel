<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['user'])) reply(401, ['ok'=>false,'message'=>'Silakan masuk kembali.']);
if (($_SESSION['user']['role'] ?? '') === 'teacher') reply(403, ['ok'=>false,'message'=>'Akun guru hanya dapat membuat dan mengelola tugas, bukan mengerjakannya.']);
$data = $_POST;
if (!$data && str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data') && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) reply(413, ['ok'=>false,'message'=>'Ukuran unggahan melebihi batas PHP pada server. Coba file yang lebih kecil.']);
if (!$data) $data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($data['csrfToken'] ?? ''))) reply(403, ['ok'=>false,'message'=>'Sesi berakhir. Muat ulang halaman.']);
$db = mutqinDb();
$statement = $db->prepare('SELECT task_id,class_group_id,type,questions FROM assignments WHERE task_id=:id LIMIT 1');
$statement->execute(['id'=>(string)($data['taskId'] ?? '')]);
$assignment = $statement->fetch();
if ($assignment) {
    $assignment['id'] = $assignment['task_id'];
    $assignment['questions'] = json_decode((string)$assignment['questions'], true) ?: [];
}
if (!$assignment) reply(404, ['ok'=>false,'message'=>'Tugas tidak ditemukan.']);
if ($assignment['class_group_id'] === null) reply(403, ['ok'=>false,'message'=>'Tugas ini belum ditujukan ke kelas tertentu.']);
$membership=$db->prepare('SELECT 1 FROM class_memberships cm JOIN users u ON u.id=cm.student_id WHERE cm.class_group_id=:class_id AND u.username=:username AND u.role=\'student\' LIMIT 1');
$membership->execute(['class_id'=>$assignment['class_group_id'],'username'=>(string)$_SESSION['user']['username']]);
if (!$membership->fetchColumn()) reply(403, ['ok'=>false,'message'=>'Tugas ini bukan untuk kelas yang kamu ikuti.']);

$submissionsPath = __DIR__ . '/storage/submissions.json';
$submissions = json_decode((string)file_get_contents($submissionsPath), true) ?: [];
$username = (string)$_SESSION['user']['username'];
$oldSubmission = $submissions[$username][$assignment['id']] ?? [];
if ($assignment['type'] === 'quiz' && is_array($oldSubmission) && $oldSubmission) reply(409, ['ok'=>false,'message'=>'Jawaban kuis sudah dikumpulkan dan dikunci. Kamu tidak dapat mengubahnya lagi.']);
$submission = ['score'=>null,'submittedAt'=>date(DATE_ATOM)];
if ($assignment['type'] === 'quiz') {
    $answers = $data['answers'] ?? [];
    if (is_string($answers)) $answers = json_decode($answers, true);
    if (!is_array($answers) || count($answers) !== count($assignment['questions'])) reply(422, ['ok'=>false,'message'=>'Jawab semua pertanyaan terlebih dahulu.']);
    $score = 0;
    foreach ($assignment['questions'] as $index => $question) {
        if (!isset($answers[$index]) || !is_numeric($answers[$index]) || (int)$answers[$index] < 0 || (int)$answers[$index] >= count($question['options'])) reply(422, ['ok'=>false,'message'=>'Pilihan jawaban tidak valid.']);
        if ((int)$answers[$index] === (int)$question['answer']) $score++;
    }
    $submission['answers'] = array_map('intval', $answers);
    $submission['score'] = $score;
} else {
    $answerMode = (string)($data['answerMode'] ?? 'text');
    if (!in_array($answerMode, ['text','photo'], true)) reply(422, ['ok'=>false,'message'=>'Pilih cara pengumpulan jawaban.']);
    $response = trim((string)($data['response'] ?? ''));
    if ($answerMode === 'photo' && $response !== '') reply(422, ['ok'=>false,'message'=>'Pilih satu cara pengumpulan: teks atau foto/video.']);
    $length = function_exists('mb_strlen') ? mb_strlen($response, 'UTF-8') : strlen($response);
    if ($length > 5000) reply(422, ['ok'=>false,'message'=>'Jawaban teks maksimal 5.000 karakter.']);
    if ($answerMode === 'text' && $response !== '') $submission['response'] = $response;
}

$upload = $_FILES['attachment'] ?? null;
$hasUpload = is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
if ($assignment['type'] === 'quiz' && $hasUpload) reply(422, ['ok'=>false,'message'=>'Lampiran foto atau video hanya tersedia untuk tugas esai.']);
if ($assignment['type'] === 'essay' && $answerMode === 'text' && $hasUpload) reply(422, ['ok'=>false,'message'=>'Pilih satu cara pengumpulan: teks atau foto/video.']);
if ($assignment['type'] === 'essay' && $answerMode === 'text' && empty($submission['response'])) reply(422, ['ok'=>false,'message'=>'Tuliskan jawaban teks terlebih dahulu.']);
if ($assignment['type'] === 'essay' && $answerMode === 'photo' && !$hasUpload && empty($oldSubmission['attachment']['file'])) reply(422, ['ok'=>false,'message'=>'Pilih foto atau video untuk dikumpulkan.']);
$newFile = null;
if ($hasUpload) {
    $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) reply(422, ['ok'=>false,'message'=>in_array($uploadError, [UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE], true) ? 'Ukuran file melebihi batas unggahan PHP di server. Coba foto atau video yang lebih kecil.' : 'Unggahan gagal. Periksa file dan koneksi lalu coba lagi.']);
    $size = (int)($upload['size'] ?? 0);
    if ($size < 1 || $size > 35 * 1024 * 1024) reply(422, ['ok'=>false,'message'=>'Ukuran foto/video maksimal 35 MB.']);
    if (!is_uploaded_file((string)($upload['tmp_name'] ?? '')) || !class_exists('finfo')) reply(422, ['ok'=>false,'message'=>'File unggahan tidak valid.']);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file((string)$upload['tmp_name']);
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','video/mp4'=>'mp4','video/webm'=>'webm','video/ogg'=>'ogv'];
    if (!isset($allowed[$mime])) reply(415, ['ok'=>false,'message'=>'Lampiran harus berupa foto JPG, PNG, WEBP, GIF atau video MP4, WEBM, OGG.']);
    $uploadDir = __DIR__ . '/storage/material_uploads';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) reply(500, ['ok'=>false,'message'=>'Folder penyimpanan lampiran tidak dapat dibuat.']);
    $newFile = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file((string)$upload['tmp_name'], $uploadDir . '/' . $newFile)) reply(500, ['ok'=>false,'message'=>'File belum dapat disimpan. Periksa izin folder storage.']);
    $submission['attachment'] = ['file'=>$newFile,'name'=>basename((string)($upload['name'] ?? 'lampiran')),'mime'=>$mime,'size'=>$size];
} elseif ($assignment['type'] === 'essay' && $answerMode === 'photo' && is_array($oldSubmission['attachment'] ?? null)) {
    $submission['attachment'] = $oldSubmission['attachment'];
}

if (!isset($submissions[$username]) || !is_array($submissions[$username])) $submissions[$username] = [];
$submissions[$username][$assignment['id']] = $submission;
$encoded = json_encode($submissions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
if ($encoded === false || file_put_contents($submissionsPath, $encoded, LOCK_EX) === false) {
    if ($newFile !== null) @unlink(__DIR__ . '/storage/material_uploads/' . $newFile);
    reply(500, ['ok'=>false,'message'=>'Jawaban belum dapat disimpan. Periksa izin folder storage.']);
}
if ($newFile !== null && !empty($oldSubmission['attachment']['file']) && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif|mp4|webm|ogv)$/', (string)$oldSubmission['attachment']['file'])) @unlink(__DIR__ . '/storage/material_uploads/' . $oldSubmission['attachment']['file']);
if ($assignment['type'] === 'essay' && ($data['answerMode'] ?? 'text') === 'text' && !empty($oldSubmission['attachment']['file']) && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif|mp4|webm|ogv)$/', (string)$oldSubmission['attachment']['file'])) @unlink(__DIR__ . '/storage/material_uploads/' . $oldSubmission['attachment']['file']);
reply(200, ['ok'=>true,'score'=>$submission['score'],'total'=>count($assignment['questions']),'status'=>$assignment['type'] === 'quiz' ? 'Nilai ' . $submission['score'] . '/' . count($assignment['questions']) : 'Terkumpul','attachment'=>$submission['attachment'] ?? null]);
