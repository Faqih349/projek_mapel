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
$statement = $db->prepare('SELECT task_id,class_group_id,type,exam_mode,questions,due FROM assignments WHERE task_id=:id LIMIT 1');
$statement->execute(['id'=>(string)($data['taskId'] ?? '')]);
$assignment = $statement->fetch();
if ($assignment) {
    $assignment['id'] = $assignment['task_id'];
    $assignment['questions'] = json_decode((string)$assignment['questions'], true) ?: [];
}
if (!$assignment) reply(404, ['ok'=>false,'message'=>'Tugas tidak ditemukan.']);
$dueDate = DateTime::createFromFormat('!Y-m-d\TH:i', (string)($assignment['due'] ?? ''), new DateTimeZone('Asia/Jakarta'));
if ($dueDate && new DateTime('now', new DateTimeZone('Asia/Jakarta')) > $dueDate) reply(403, ['ok'=>false,'message'=>'Waktu pengerjaan tugas atau kuis sudah berakhir.']);
if ((bool)$assignment['exam_mode'] && !filter_var($data['fullscreen'] ?? false, FILTER_VALIDATE_BOOLEAN)) reply(403, ['ok'=>false,'message'=>'Tugas ini harus dikumpulkan dalam mode layar penuh.']);
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
if (in_array($assignment['type'], ['quiz','essay_quiz'], true)) {
    $answers = $data['answers'] ?? [];
    if (is_string($answers)) $answers = json_decode($answers, true);
    if (!is_array($answers) || count($answers) !== count($assignment['questions'])) reply(422, ['ok'=>false,'message'=>'Jawab semua pertanyaan terlebih dahulu.']);
    $essayResponses = $data['essayResponses'] ?? [];
    if (is_string($essayResponses)) $essayResponses = json_decode($essayResponses, true);
    if (!is_array($essayResponses)) $essayResponses = [];
    $score = 0.0;
    foreach ($assignment['questions'] as $index => $question) {
        if (($question['kind'] ?? 'choice') === 'essay') {
            $essayAnswer = trim((string)($essayResponses[$index] ?? ''));
            if ($essayAnswer === '') reply(422, ['ok'=>false,'message'=>'Jawab semua soal esai terlebih dahulu.']);
            continue;
        }
        if (!isset($answers[$index]) || !is_numeric($answers[$index]) || (int)$answers[$index] < 0 || (int)$answers[$index] >= count($question['options'])) reply(422, ['ok'=>false,'message'=>'Pilihan jawaban tidak valid.']);
        if ((int)$answers[$index] === (int)$question['answer']) {
            $score += isset($question['weight']) && is_numeric($question['weight']) ? (float)$question['weight'] : 100 / max(1, count($assignment['questions']));
        }
    }
    $submission['answers'] = array_map('intval', $answers);
    if ($essayResponses) $submission['essayResponses'] = array_map(static fn($value): string => trim((string)$value), $essayResponses);
    $submission['score'] = round(min(100, $score), 2);
} else {
    $answerMode = (string)($data['answerMode'] ?? 'text');
    if (!in_array($answerMode, ['text','photo'], true)) reply(422, ['ok'=>false,'message'=>'Pilih cara pengumpulan jawaban.']);
    $response = trim((string)($data['response'] ?? ''));
    if ($answerMode === 'photo' && $response !== '') reply(422, ['ok'=>false,'message'=>'Pilih satu cara pengumpulan: teks atau foto/video.']);
    $length = function_exists('mb_strlen') ? mb_strlen($response, 'UTF-8') : strlen($response);
    if ($length > 5000) reply(422, ['ok'=>false,'message'=>'Jawaban teks maksimal 5.000 karakter.']);
    if ($answerMode === 'text' && $response !== '') $submission['response'] = $response;
}

$upload = $_FILES['attachments'] ?? ($_FILES['attachment'] ?? null);
$uploads = [];
if (is_array($upload) && isset($upload['name']) && is_array($upload['name'])) {
    foreach ($upload['name'] as $index => $name) $uploads[] = ['name'=>$name,'type'=>$upload['type'][$index] ?? '','tmp_name'=>$upload['tmp_name'][$index] ?? '','error'=>$upload['error'][$index] ?? UPLOAD_ERR_NO_FILE,'size'=>$upload['size'][$index] ?? 0];
} elseif (is_array($upload)) $uploads[] = $upload;
$uploads = array_values(array_filter($uploads, static fn(array $item): bool => (int)($item['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
$hasUpload = count($uploads) > 0;
if (in_array($assignment['type'], ['quiz','essay_quiz'], true) && $hasUpload) reply(422, ['ok'=>false,'message'=>'Lampiran foto atau video hanya tersedia untuk tugas esai.']);
if ($assignment['type'] === 'essay' && $answerMode === 'text' && $hasUpload) reply(422, ['ok'=>false,'message'=>'Pilih satu cara pengumpulan: teks atau foto/video.']);
if ($assignment['type'] === 'essay' && $answerMode === 'text' && empty($submission['response'])) reply(422, ['ok'=>false,'message'=>'Tuliskan jawaban teks terlebih dahulu.']);
if ($assignment['type'] === 'essay' && $answerMode === 'photo' && !$hasUpload && empty($oldSubmission['attachment']['file']) && empty($oldSubmission['attachments'])) reply(422, ['ok'=>false,'message'=>'Pilih foto atau video untuk dikumpulkan.']);
$newFiles = [];
if ($hasUpload) {
    if (count($uploads) > 8) reply(422, ['ok'=>false,'message'=>'Maksimal 8 foto atau video dapat dikumpulkan.']);
    foreach ($uploads as $uploadItem) {
    $uploadError = (int)($uploadItem['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) reply(422, ['ok'=>false,'message'=>in_array($uploadError, [UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE], true) ? 'Ukuran file melebihi batas unggahan PHP di server. Coba foto atau video yang lebih kecil.' : 'Unggahan gagal. Periksa file dan koneksi lalu coba lagi.']);
    $size = (int)($uploadItem['size'] ?? 0);
    if ($size < 1 || $size > 35 * 1024 * 1024) reply(422, ['ok'=>false,'message'=>'Ukuran foto/video maksimal 35 MB.']);
    if (!is_uploaded_file((string)($uploadItem['tmp_name'] ?? '')) || !class_exists('finfo')) reply(422, ['ok'=>false,'message'=>'File unggahan tidak valid.']);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file((string)$uploadItem['tmp_name']);
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','video/mp4'=>'mp4','video/webm'=>'webm','video/ogg'=>'ogv'];
    if (!isset($allowed[$mime])) reply(415, ['ok'=>false,'message'=>'Lampiran harus berupa foto JPG, PNG, WEBP, GIF atau video MP4, WEBM, OGG.']);
    $uploadDir = __DIR__ . '/storage/material_uploads';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) reply(500, ['ok'=>false,'message'=>'Folder penyimpanan lampiran tidak dapat dibuat.']);
    $newFile = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $newFile = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file((string)$uploadItem['tmp_name'], $uploadDir . '/' . $newFile)) reply(500, ['ok'=>false,'message'=>'File belum dapat disimpan. Periksa izin folder storage.']);
    $newFiles[] = ['file'=>$newFile,'name'=>basename((string)($uploadItem['name'] ?? 'lampiran')),'mime'=>$mime,'size'=>$size];
    }
    $submission['attachments'] = $newFiles;
    $submission['attachment'] = $newFiles[0];
} elseif ($assignment['type'] === 'essay' && $answerMode === 'photo') {
    if (is_array($oldSubmission['attachments'] ?? null)) $submission['attachments'] = $oldSubmission['attachments'];
    if (is_array($oldSubmission['attachment'] ?? null)) $submission['attachment'] = $oldSubmission['attachment'];
}

if (!isset($submissions[$username]) || !is_array($submissions[$username])) $submissions[$username] = [];
$submissions[$username][$assignment['id']] = $submission;
$encoded = json_encode($submissions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
if ($encoded === false || file_put_contents($submissionsPath, $encoded, LOCK_EX) === false) {
    foreach ($newFiles as $newFile) @unlink(__DIR__ . '/storage/material_uploads/' . $newFile['file']);
    reply(500, ['ok'=>false,'message'=>'Jawaban belum dapat disimpan. Periksa izin folder storage.']);
}
foreach (array_merge(is_array($oldSubmission['attachments'] ?? null) ? $oldSubmission['attachments'] : [], is_array($oldSubmission['attachment'] ?? null) ? [$oldSubmission['attachment']] : []) as $oldFile) if (preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif|mp4|webm|ogv)$/', (string)($oldFile['file'] ?? ''))) @unlink(__DIR__ . '/storage/material_uploads/' . $oldFile['file']);
if ($assignment['type'] === 'essay' && ($data['answerMode'] ?? 'text') === 'text' && !empty($oldSubmission['attachment']['file']) && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif|mp4|webm|ogv)$/', (string)$oldSubmission['attachment']['file'])) @unlink(__DIR__ . '/storage/material_uploads/' . $oldSubmission['attachment']['file']);
reply(200, ['ok'=>true,'score'=>$submission['score'],'total'=>100,'status'=>in_array($assignment['type'], ['quiz','essay_quiz'], true) ? 'Nilai ' . $submission['score'] . '/100' : 'Terkumpul','attachment'=>$submission['attachment'] ?? null,'attachments'=>$submission['attachments'] ?? ($submission['attachment'] ?? null ? [$submission['attachment']] : [])]);
