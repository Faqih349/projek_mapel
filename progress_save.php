<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function progressReply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['user'])) progressReply(401, ['ok'=>false,'message'=>'Silakan masuk kembali.']);
$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($data['csrfToken'] ?? ''))) progressReply(403, ['ok'=>false,'message'=>'Sesi berakhir. Muat ulang halaman.']);
$lessonId = filter_var($data['lessonId'] ?? null, FILTER_VALIDATE_INT);
if ($lessonId === false) progressReply(404, ['ok'=>false,'message'=>'Materi tidak ditemukan.']);
$material = mutqinDb()->prepare('SELECT position FROM materials WHERE position=:position LIMIT 1');
$material->execute(['position'=>(int)$lessonId]);
if ($material->fetchColumn() === false) progressReply(404, ['ok'=>false,'message'=>'Materi tidak ditemukan.']);

$path = __DIR__ . '/storage/progress.json';
$progress = json_decode((string)file_get_contents($path), true) ?: [];
$username = (string)$_SESSION['user']['username'];
$done = $progress[$username] ?? [];
if (!in_array((int)$lessonId, $done, true)) $done[] = (int)$lessonId;
$progress[$username] = $done;
if (file_put_contents($path, json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) progressReply(500, ['ok'=>false,'message'=>'Progres belum dapat disimpan.']);
progressReply(200, ['ok'=>true,'completedLessons'=>$done]);
