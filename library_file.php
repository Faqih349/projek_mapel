<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user'])) { http_response_code(401); exit('Silakan masuk terlebih dahulu.'); }
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id) { http_response_code(404); exit('Bahan tidak ditemukan.'); }
try {
    $db = mutqinDb();
    $query = $db->prepare('SELECT file_name,file_original_name,file_mime,file_size FROM library_items WHERE id=:id LIMIT 1');
    $query->execute(['id'=>$id]);
    $item = $query->fetch();
} catch (Throwable $exception) { http_response_code(503); exit('Perpustakaan belum tersedia.'); }
if (!$item || !preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp|mp4|webm)$/', (string)$item['file_name'])) { http_response_code(404); exit('Bahan tidak ditemukan.'); }
$path = __DIR__ . '/storage/library_uploads/' . $item['file_name'];
if (!is_file($path)) { http_response_code(404); exit('File bahan tidak ditemukan.'); }
header('Content-Type: ' . $item['file_mime']);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="' . rawurlencode((string)$item['file_original_name']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
