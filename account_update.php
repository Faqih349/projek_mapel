<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function accountReply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['user'])) {
    accountReply(401, ['ok'=>false,'message'=>'Silakan masuk kembali.']);
}
$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($payload['csrfToken'] ?? ''))) {
    accountReply(403, ['ok'=>false,'message'=>'Sesi berakhir. Muat ulang halaman lalu coba lagi.']);
}

$email = strtolower(trim((string)($payload['email'] ?? '')));
$name = trim((string)($payload['name'] ?? ($_SESSION['user']['name'] ?? '')));
$nisn = trim((string)($payload['nisn'] ?? ($_SESSION['user']['nisn'] ?? '')));
$className = trim((string)($payload['className'] ?? ($_SESSION['user']['class'] ?? '')));
$isTeacher = (($_SESSION['user']['role'] ?? '') === 'teacher');
$nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
if ($name === '' || $nameLength > 150) accountReply(422, ['ok'=>false,'message'=>'Nama wajib diisi dan maksimal 150 karakter.']);
if (strlen($nisn) > 30 || strlen($className) > 40) accountReply(422, ['ok'=>false,'message'=>'NISN maksimal 30 karakter dan kelas maksimal 40 karakter.']);
$currentPassword = (string)($payload['currentPassword'] ?? '');
$newPassword = (string)($payload['newPassword'] ?? '');
$confirmPassword = (string)($payload['confirmPassword'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    accountReply(422, ['ok'=>false,'message'=>'Masukkan alamat email yang valid.']);
}
if ($currentPassword === '') accountReply(422, ['ok'=>false,'message'=>'Masukkan kata sandi saat ini untuk menyimpan perubahan.']);
if ($newPassword !== '' && (strlen($newPassword) < 8 || $newPassword !== $confirmPassword)) {
    accountReply(422, ['ok'=>false,'message'=>'Kata sandi baru minimal 8 karakter dan konfirmasinya harus sama.']);
}

try {
    $db = mutqinDb();
    $statement = $db->prepare('SELECT id,email,password_hash,name,initials FROM users WHERE username=:username LIMIT 1');
    $statement->execute(['username'=>(string)$_SESSION['user']['username']]);
    $account = $statement->fetch();
    if (!$account || !password_verify($currentPassword, (string)$account['password_hash'])) accountReply(403, ['ok'=>false,'message'=>'Kata sandi saat ini tidak benar.']);
    $duplicate = $db->prepare('SELECT id FROM users WHERE email=:email AND id<>:id LIMIT 1');
    $duplicate->execute(['email'=>$email, 'id'=>$account['id']]);
    if ($duplicate->fetch()) accountReply(409, ['ok'=>false,'message'=>'Email tersebut sudah digunakan oleh akun lain.']);
    $passwordHash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_DEFAULT) : $account['password_hash'];
$parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) $initials .= function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
    $initials = function_exists('mb_strtoupper') ? mb_strtoupper($initials, 'UTF-8') : strtoupper($initials);
    $updatedName = $name;
    $update = $db->prepare('UPDATE users SET name=:name,initials=:initials,email=:email,password_hash=:password_hash,nisn=:nisn,class_name=:class_name WHERE id=:id');
    $update->execute(['name'=>$updatedName,'initials'=>$initials,'email'=>$email,'password_hash'=>$passwordHash,'nisn'=>$nisn,'class_name'=>$className,'id'=>$account['id']]);
} catch (PDOException $exception) {
    error_log('MutqinSpace account update database error: ' . $exception->getMessage());
    accountReply(500, ['ok'=>false,'message'=>'Pengaturan akun belum tersimpan. Pastikan database MySQL sudah disiapkan.']);
}

$_SESSION['user']['email'] = $email;
$_SESSION['user']['name'] = $updatedName;
$_SESSION['user']['initials'] = $initials;
$_SESSION['user']['nisn'] = $nisn;
$_SESSION['user']['class'] = $className;
if ($newPassword !== '') session_regenerate_id(true);
accountReply(200, ['ok'=>true,'email'=>$email,'name'=>$updatedName,'initials'=>$initials,'nisn'=>$nisn,'className'=>$className]);
