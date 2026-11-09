<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function libraryReply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (($_SESSION['user']['role'] ?? '') !== 'teacher')) libraryReply(403, ['ok'=>false,'message'=>'Hanya guru yang dapat mengelola perpustakaan.']);
$data = $_POST;
if (!$data) $data = json_decode((string)file_get_contents('php://input'), true) ?: [];
if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($data['csrfToken'] ?? ''))) libraryReply(403, ['ok'=>false,'message'=>'Sesi berakhir. Muat ulang halaman.']);
try {
    $db = mutqinDb();
    $user = $db->prepare('SELECT id FROM users WHERE username=:username AND role=\'teacher\' LIMIT 1');
    $user->execute(['username'=>(string)$_SESSION['user']['username']]);
    $userId = (int)$user->fetchColumn();
    if (!$userId) libraryReply(403, ['ok'=>false,'message'=>'Akun guru tidak ditemukan.']);
    if (($data['action'] ?? '') === 'delete') {
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) libraryReply(404, ['ok'=>false,'message'=>'Bahan tidak ditemukan.']);
        $find = $db->prepare('SELECT file_name FROM library_items WHERE id=:id');
        $find->execute(['id'=>$id]);
        $file = $find->fetchColumn();
        if ($file === false) libraryReply(404, ['ok'=>false,'message'=>'Bahan tidak ditemukan.']);
        $db->prepare('DELETE FROM library_items WHERE id=:id')->execute(['id'=>$id]);
        if (preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp|mp4|webm)$/', (string)$file)) @unlink(__DIR__.'/storage/library_uploads/'.$file);
    } else {
        $category = (string)($data['category'] ?? '');
        $title = trim((string)($data['title'] ?? ''));
        $grade = filter_var($data['gradeLevel'] ?? null, FILTER_VALIDATE_INT);
        $juz = filter_var($data['juzNumber'] ?? null, FILTER_VALIDATE_INT);
        $length = function_exists('mb_strlen') ? mb_strlen($title,'UTF-8') : strlen($title);
        if (!in_array($category,['textbook','juz_amma'],true) || $title==='' || $length>150 || ($category==='textbook' && !in_array($grade,[10,11,12],true)) || ($category==='juz_amma' && ($juz<1 || $juz>30))) libraryReply(422,['ok'=>false,'message'=>'Periksa jenis bahan, kelas atau nomor juz, dan judul.']);
        $upload = $_FILES['attachment'] ?? null;
        if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) libraryReply(422,['ok'=>false,'message'=>'Pilih file PDF, foto, atau video yang valid.']);
        $size=(int)$upload['size'];
        if ($size<1 || $size>35*1024*1024 || !is_uploaded_file((string)$upload['tmp_name'])) libraryReply(422,['ok'=>false,'message'=>'Ukuran file maksimal 35 MB dan file harus berasal dari unggahan.']);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$upload['tmp_name']);
        $allowed=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','video/mp4'=>'mp4','video/webm'=>'webm'];
        if (!isset($allowed[$mime])) libraryReply(415,['ok'=>false,'message'=>'Format file harus PDF, JPG, PNG, WEBP, MP4, atau WEBM.']);
        $directory=__DIR__.'/storage/library_uploads';
        if (!is_dir($directory) && !mkdir($directory,0755,true) && !is_dir($directory)) libraryReply(500,['ok'=>false,'message'=>'Folder penyimpanan tidak dapat dibuat.']);
        $stored=bin2hex(random_bytes(16)).'.'.$allowed[$mime];
        if (!move_uploaded_file((string)$upload['tmp_name'],$directory.'/'.$stored)) libraryReply(500,['ok'=>false,'message'=>'File gagal disimpan.']);
        $original=basename((string)$upload['name']);
        try {
            $insert=$db->prepare('INSERT INTO library_items (uploader_id,category,grade_level,juz_number,title,file_name,file_original_name,file_mime,file_size) VALUES (:user,:category,:grade,:juz,:title,:file,:original,:mime,:size)');
            $insert->execute(['user'=>$userId,'category'=>$category,'grade'=>$category==='textbook'?$grade:null,'juz'=>$category==='juz_amma'?$juz:null,'title'=>$title,'file'=>$stored,'original'=>$original,'mime'=>$mime,'size'=>$size]);
        } catch (Throwable $exception) { @unlink($directory.'/'.$stored); throw $exception; }
    }
    $items=$db->query('SELECT id,category,grade_level,juz_number,title,file_original_name,file_mime AS mime,file_size FROM library_items ORDER BY category,grade_level,juz_number DESC,created_at DESC')->fetchAll();
    $items=array_map(static fn(array $item):array=>['id'=>(int)$item['id'],'category'=>$item['category'],'gradeLevel'=>$item['grade_level']===null?null:(int)$item['grade_level'],'juzNumber'=>$item['juz_number']===null?null:(int)$item['juz_number'],'title'=>$item['title'],'originalName'=>$item['file_original_name'],'mime'=>$item['mime'],'size'=>(int)$item['file_size']],$items);
    libraryReply(200,['ok'=>true,'items'=>$items]);
} catch (Throwable $exception) {
    error_log('MutqinSpace library error: '.$exception->getMessage());
    libraryReply(500,['ok'=>false,'message'=>'Perpustakaan gagal diperbarui. Pastikan tabel library_items sudah tersedia di MySQL.']);
}
