<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function classLeaveReply(int $status, array $payload): never { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['user'])) classLeaveReply(401, ['ok'=>false,'message'=>'Silakan masuk kembali.']);
if (($_SESSION['user']['role'] ?? '') !== 'student') classLeaveReply(403, ['ok'=>false,'message'=>'Hanya siswa yang dapat keluar dari kelas.']);
$data=json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($data['csrfToken'] ?? ''))) classLeaveReply(403, ['ok'=>false,'message'=>'Sesi berakhir. Muat ulang halaman.']);
$classId=filter_var($data['classId'] ?? null, FILTER_VALIDATE_INT);
if ($classId === false || $classId < 1) classLeaveReply(422, ['ok'=>false,'message'=>'Kelas tidak valid.']);
try { $db=mutqinDb(); $query=$db->prepare('DELETE cm FROM class_memberships cm JOIN users u ON u.id=cm.student_id WHERE cm.class_group_id=:class_id AND u.username=:username AND u.role=\'student\''); $query->execute(['class_id'=>$classId,'username'=>(string)$_SESSION['user']['username']]); if (!$query->rowCount()) classLeaveReply(404, ['ok'=>false,'message'=>'Keanggotaan kelas tidak ditemukan.']); classLeaveReply(200, ['ok'=>true,'classId'=>$classId]); } catch (PDOException $exception) { error_log('MutqinSpace student class leave database error: '.$exception->getMessage()); classLeaveReply(500, ['ok'=>false,'message'=>'Belum dapat keluar dari kelas.']); }
