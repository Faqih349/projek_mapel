<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';

if (empty($_SESSION['user'])) {
    http_response_code(401);
    exit('Silakan masuk untuk membuka lampiran materi.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Metode permintaan tidak didukung.');
}

$file = (string)($_GET['file'] ?? '');
if (!preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp|gif|mp4|webm|ogv)$/', $file)) {
    http_response_code(404);
    exit('Lampiran tidak ditemukan.');
}

$statement = mutqinDb()->prepare('SELECT file_original_name,file_mime,file_size FROM materials WHERE file_name=:material_file UNION ALL SELECT file_original_name,file_mime,file_size FROM assignments WHERE file_name=:assignment_file LIMIT 1');
$statement->execute(['material_file'=>$file, 'assignment_file'=>$file]);
$assetRow = $statement->fetch();
if (!$assetRow) {
    http_response_code(404);
    exit('Lampiran tidak ditemukan.');
}

$path = __DIR__ . '/storage/material_uploads/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit('File lampiran tidak tersedia.');
}
$allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/webm', 'video/ogg', 'application/ogg'];
$mime = (string)($assetRow['file_mime'] ?? 'application/octet-stream');
if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(415);
    exit('Tipe lampiran tidak didukung.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="lampiran-' . pathinfo($file, PATHINFO_EXTENSION) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($path);
