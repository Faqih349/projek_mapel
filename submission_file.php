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
$requestedUsername = (string)($_GET['username'] ?? '');
$user = $_SESSION['user'];
if (($user['role'] ?? '') !== 'teacher') $requestedUsername = (string)$user['username'];
if ($taskId === '' || $requestedUsername === '') submissionFileError(404, 'Lampiran tidak ditemukan.');

$db = mutqinDb();
$task = $db->prepare('SELECT task_id FROM assignments WHERE task_id=:task_id LIMIT 1');
$task->execute(['task_id'=>$taskId]);
if (!$task->fetchColumn()) submissionFileError(404, 'Tugas tidak ditemukan.');
$accounts = $db->prepare('SELECT username FROM users WHERE username=:username AND role=\'student\' LIMIT 1');
$accounts->execute(['username'=>$requestedUsername]);
if (!$accounts->fetchColumn()) submissionFileError(404, 'Siswa tidak ditemukan.');
$submissions = json_decode((string)file_get_contents(__DIR__ . '/storage/submissions.json'), true) ?: [];
$asset = $submissions[$requestedUsername][$taskId]['attachment'] ?? null;
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
