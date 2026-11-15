<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/email_notifications.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function smtpSettingsReply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SESSION['user']['role'] ?? '') !== 'teacher') {
    smtpSettingsReply(403, ['ok'=>false,'message'=>'Pengaturan email hanya dapat diubah oleh guru.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $settings = mutqinSmtpSettings();
        smtpSettingsReply(200, [
            'ok'=>true,
            'configured'=>mutqinSmtpIsConfigured(),
            'email'=>filter_var((string)$settings['username'], FILTER_VALIDATE_EMAIL) ? (string)$settings['username'] : '',
        ]);
    } catch (Throwable $exception) {
        error_log('MutqinSpace SMTP settings read failed: ' . $exception->getMessage());
        smtpSettingsReply(500, ['ok'=>false,'message'=>'Pengaturan Gmail belum dapat dibaca.']);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    smtpSettingsReply(405, ['ok'=>false,'message'=>'Metode permintaan tidak didukung.']);
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($payload['csrfToken'] ?? ''))) {
    smtpSettingsReply(403, ['ok'=>false,'message'=>'Sesi berakhir. Muat ulang halaman lalu coba lagi.']);
}

$email = strtolower(trim((string)($payload['email'] ?? '')));
$appPassword = (string)($payload['appPassword'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    smtpSettingsReply(422, ['ok'=>false,'message'=>'Masukkan alamat Gmail yang valid.']);
}

try {
    mutqinSaveSmtpSettings($email, $appPassword);
} catch (InvalidArgumentException $exception) {
    smtpSettingsReply(422, ['ok'=>false,'message'=>$exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('MutqinSpace SMTP settings save failed: ' . $exception->getMessage());
    smtpSettingsReply(500, ['ok'=>false,'message'=>'Pengaturan Gmail belum dapat disimpan. Pastikan folder storage dapat ditulis.']);
}

try {
    $sent = mutqinSendTaskNotification(
        [$email],
        ['title'=>'Uji konfigurasi Gmail','courseTitle'=>'MutqinSpace','due'=>'','description'=>'Pengaturan notifikasi email berhasil.','type'=>'essay'],
        'Uji konfigurasi',
        'MutqinSpace'
    );
    if ($sent < 1) throw new RuntimeException('Alamat email pengirim tidak valid.');
} catch (Throwable $exception) {
    error_log('MutqinSpace SMTP test email failed: ' . $exception->getMessage());
    smtpSettingsReply(502, ['ok'=>false,'message'=>'Pengaturan tersimpan, tetapi email percobaan gagal dikirim. Periksa alamat Gmail dan App Password.']);
}

smtpSettingsReply(200, ['ok'=>true,'message'=>'Gmail berhasil disimpan dan email percobaan sudah dikirim.']);
