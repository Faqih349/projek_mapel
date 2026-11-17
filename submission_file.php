<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';

function submissionFileError(int $status, string $message): never
{
    http_response_code($status);
    exit($message);
}

if (empty($_SESSION['user'])) submissionFileError(401, 'Silakan masuk untuk melihat lampiran.');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') submissionFileError(405, 'Metode permintaan tidak didukung.');
$taskId = (string)($_GET['task'] ?? '');
$user = $_SESSION['user'];
$requestedUsername = ($user['role'] ?? '') === 'teacher'
    ? (string)($_GET['username'] ?? '')
    : (string)($user['username'] ?? '');
if ($taskId === '' || $requestedUsername === '') submissionFileError(404, 'Lampiran tidak ditemukan.');

$db = mutqinDb();
$task = $db->prepare('SELECT task_id,class_group_id FROM assignments WHERE task_id=:task_id LIMIT 1');
$task->execute(['task_id'=>$taskId]);
$assignment = $task->fetch();
if (!$assignment || $assignment['class_group_id'] === null) submissionFileError(404, 'Tugas tidak ditemukan.');

if (($user['role'] ?? '') === 'teacher') {
    $taskOwner = $db->prepare('SELECT 1 FROM assignments a JOIN class_groups cg ON cg.id=a.class_group_id JOIN users teacher ON teacher.id=cg.teacher_id WHERE a.task_id=:task_id AND teacher.username=:username AND teacher.role=\'teacher\' LIMIT 1');
    $taskOwner->execute(['task_id'=>$taskId,'username'=>(string)$user['username']]);
    if (!$taskOwner->fetchColumn()) submissionFileError(403, 'Kamu tidak memiliki akses ke tugas ini.');
}
$membership = $db->prepare('SELECT u.id FROM class_memberships cm JOIN users u ON u.id=cm.student_id WHERE cm.class_group_id=:class_id AND u.username=:username AND u.role=\'student\' LIMIT 1');
$membership->execute(['class_id'=>$assignment['class_group_id'],'username'=>$requestedUsername]);
$studentId = $membership->fetchColumn();
if (!$studentId) submissionFileError(403, 'Siswa bukan anggota kelas tugas ini.');

$revision = filter_var($_GET['revision'] ?? null, FILTER_VALIDATE_INT);
if (isset($_GET['revision']) && ($revision === false || $revision < 1)) submissionFileError(404, 'Lampiran tidak ditemukan.');
if ($revision !== false && $revision !== null) {
    $query = $db->prepare('SELECT submission_data FROM submission_history WHERE task_id=:task_id AND student_id=:student_id AND revision=:revision LIMIT 1');
    $query->execute(['task_id'=>$taskId,'student_id'=>(int)$studentId,'revision'=>$revision]);
} else {
    $query = $db->prepare('SELECT submission_data FROM submissions WHERE task_id=:task_id AND student_id=:student_id LIMIT 1');
    $query->execute(['task_id'=>$taskId,'student_id'=>(int)$studentId]);
}
$submission = json_decode((string)($query->fetchColumn() ?: ''), true);
if (!is_array($submission)) submissionFileError(404, 'Lampiran tidak ditemukan.');
$assets = is_array($submission['attachments'] ?? null) ? $submission['attachments'] : (is_array($submission['attachment'] ?? null) ? [$submission['attachment']] : []);
$assetIndex = filter_var($_GET['index'] ?? 0, FILTER_VALIDATE_INT);
if ($assetIndex === false || $assetIndex < 0) submissionFileError(404, 'Lampiran tidak ditemukan.');
$asset = $assets[$assetIndex] ?? null;
if (!is_array($asset) || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif|mp4|webm|ogv)$/', (string)($asset['file'] ?? ''))) submissionFileError(404, 'Lampiran tidak ditemukan.');
$mime = (string)($asset['mime'] ?? '');
$allowed = ['image/jpeg','image/png','image/webp','image/gif','video/mp4','video/webm','video/ogg'];
if (!in_array($mime, $allowed, true)) submissionFileError(415, 'Jenis lampiran tidak didukung.');
$path = __DIR__ . '/storage/material_uploads/' . $asset['file'];
if (!is_file($path)) submissionFileError(404, 'File lampiran tidak tersedia.');
$name = basename((string)($asset['name'] ?? 'tugas.' . pathinfo($path, PATHINFO_EXTENSION)));
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
