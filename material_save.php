<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['ok'=>false, 'message'=>'Metode permintaan tidak didukung.']);
if (($_SESSION['user']['role'] ?? '') !== 'teacher') respond(403, ['ok'=>false, 'message'=>'Hanya akun Guru yang dapat mengubah materi.']);
$payload = $_POST;
if (empty($payload)) $payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($payload['csrfToken'] ?? ''))) {
    respond(403, ['ok'=>false, 'message'=>'Sesi berakhir. Muat ulang halaman lalu coba lagi.']);
}
try { $db = mutqinDb(); } catch (Throwable $exception) {
    error_log('MutqinSpace material database error: ' . $exception->getMessage());
    respond(503, ['ok'=>false, 'message'=>'Database MySQL belum siap. Impor database.sql dan jalankan migrasi data.']);
}
$materialColumns = $db->query('SHOW COLUMNS FROM materials')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('class_group_id', $materialColumns, true)) $db->exec('ALTER TABLE materials ADD COLUMN class_group_id BIGINT UNSIGNED NULL AFTER position');

$courses = ['quran'=>'Al-Qur’an dan Hadis', 'akhlak'=>'Akidah Akhlak', 'sejarah'=>'Sejarah Kebudayaan Islam', 'fikih'=>'Fikih'];
$action = (string)($payload['action'] ?? 'save');
$id = $payload['id'] ?? null;
$uploadDir = __DIR__ . '/storage/material_uploads';
$validStoredFile = static fn($file): bool => is_string($file) && preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp|gif|mp4|webm|ogv)$/', $file) === 1;

if ($action === 'delete') {
    if (filter_var($id, FILTER_VALIDATE_INT) === false) respond(404, ['ok'=>false, 'message'=>'Materi yang ingin dihapus tidak ditemukan.']);
    $id = (int)$id;
    $find = $db->prepare('SELECT file_name FROM materials WHERE position=:position');
    $find->execute(['position'=>$id]);
    $oldFile = $find->fetchColumn();
    if ($oldFile === false) respond(404, ['ok'=>false, 'message'=>'Materi yang ingin dihapus tidak ditemukan.']);
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM materials WHERE position=:position')->execute(['position'=>$id]);
        $db->prepare('UPDATE materials SET position=position+1000000 WHERE position>:position')->execute(['position'=>$id]);
        $db->prepare('UPDATE materials SET position=position-1000001 WHERE position>=1000000')->execute();
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        respond(500, ['ok'=>false, 'message'=>'Materi belum dapat dihapus dari database.']);
    }
    if ($validStoredFile($oldFile)) @unlink($uploadDir . '/' . $oldFile);

    $progressPath = __DIR__ . '/storage/progress.json';
    $progress = json_decode((string)file_get_contents($progressPath), true);
    if (is_array($progress)) {
        foreach ($progress as &$completed) {
            if (!is_array($completed)) continue;
            $completed = array_values(array_unique(array_map('intval', array_filter($completed, static fn($lessonId): bool => (int)$lessonId !== $id))));
            foreach ($completed as &$lessonId) if ($lessonId > $id) $lessonId--;
            unset($lessonId);
        }
        unset($completed);
        file_put_contents($progressPath, json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }
    respond(200, ['ok'=>true, 'id'=>$id]);
}
if ($action !== 'save') respond(422, ['ok'=>false, 'message'=>'Aksi tidak dikenal.']);

$title = trim((string)($payload['title'] ?? ''));
$description = trim((string)($payload['description'] ?? ''));
$body = trim((string)($payload['body'] ?? ''));
$courseId = (string)($payload['courseId'] ?? '');
$classGroupId = filter_var($payload['classGroupId'] ?? null, FILTER_VALIDATE_INT);
$classGroupId = $classGroupId === false ? 0 : (int)$classGroupId;
$classCheck = $db->prepare("SELECT id,class_name,course_id FROM class_groups WHERE id=:id AND teacher_id=(SELECT id FROM users WHERE username=:username AND role='teacher' LIMIT 1) LIMIT 1");
$classCheck->execute(['id'=>$classGroupId,'username'=>(string)$_SESSION['user']['username']]);
$targetClass = $classCheck->fetch();
$length = static fn(string $value): int => function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
if ($title === '' || $length($title) > 150 || $description === '' || $length($description) > 300 || $body === '' || $length($body) > 20000 || !isset($courses[$courseId]) || !$targetClass || (string)$targetClass['course_id'] !== $courseId) {
    respond(422, ['ok'=>false, 'message'=>'Lengkapi semua kolom. Periksa batas panjang judul, ringkasan, dan isi materi.']);
}
$isNew = $id === null || $id === '';
$oldAsset = null;
if (!$isNew) {
    if (filter_var($id, FILTER_VALIDATE_INT) === false) respond(404, ['ok'=>false, 'message'=>'Materi yang akan diubah tidak ditemukan.']);
    $id = (int)$id;
    $find = $db->prepare('SELECT file_name,file_original_name,file_mime,file_size FROM materials WHERE position=:position');
    $find->execute(['position'=>$id]);
    $oldAsset = $find->fetch();
    if (!$oldAsset) respond(404, ['ok'=>false, 'message'=>'Materi yang akan diubah tidak ditemukan.']);
}
$asset = $oldAsset && !empty($oldAsset['file_name']) ? ['file'=>$oldAsset['file_name'],'name'=>$oldAsset['file_original_name'],'mime'=>$oldAsset['file_mime'],'size'=>(int)$oldAsset['file_size']] : null;
$uploaded = $_FILES['attachment'] ?? null;
$newUploadName = null;
if (is_array($uploaded) && (int)($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $uploadError = (int)($uploaded['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) respond(422, ['ok'=>false, 'message'=>$uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE ? 'Ukuran file melewati batas unggahan server. Coba file yang lebih kecil.' : 'File gagal diunggah. Pilih kembali PDF, foto, atau video yang valid.']);
    $size = (int)($uploaded['size'] ?? 0);
    if ($size < 1 || $size > 35 * 1024 * 1024) respond(422, ['ok'=>false, 'message'=>'Ukuran file maksimal 35 MB sesuai batas unggahan server.']);
    if (!is_uploaded_file((string)($uploaded['tmp_name'] ?? '')) || !class_exists('finfo')) respond(422, ['ok'=>false, 'message'=>'File unggahan tidak valid atau pemeriksaan tipe file tidak tersedia.']);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file((string)$uploaded['tmp_name']);
    $allowed = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','video/mp4'=>'mp4','video/webm'=>'webm','video/ogg'=>'ogv','application/ogg'=>'ogv'];
    if (!isset($allowed[$mime])) respond(415, ['ok'=>false, 'message'=>'Format belum didukung. Gunakan PDF, JPG, PNG, WEBP, GIF, MP4, WEBM, atau OGG.']);
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) respond(500, ['ok'=>false, 'message'=>'Folder penyimpanan lampiran tidak dapat dibuat.']);
    $newUploadName = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file((string)$uploaded['tmp_name'], $uploadDir . '/' . $newUploadName)) respond(500, ['ok'=>false, 'message'=>'File belum dapat disimpan. Periksa izin folder storage.']);
    $asset = ['file'=>$newUploadName,'name'=>basename((string)($uploaded['name'] ?? 'lampiran')),'mime'=>$mime,'size'=>$size];
} elseif (($payload['removeAttachment'] ?? '') === '1') {
    $asset = null;
}

if ($isNew) $id = (int)$db->query('SELECT COALESCE(MAX(position),-1)+1 FROM materials')->fetchColumn();
$assetFields = $asset ? [$asset['file'],$asset['name'],$asset['mime'],$asset['size']] : [null,null,null,null];
try {
    $statement = $db->prepare('INSERT INTO materials (position,class_group_id,title,description,course_title,course_id,body,file_name,file_original_name,file_mime,file_size) VALUES (:position,:class_group_id,:title,:description,:course_title,:course_id,:body,:file_name,:file_original_name,:file_mime,:file_size) ON DUPLICATE KEY UPDATE class_group_id=VALUES(class_group_id),title=VALUES(title),description=VALUES(description),course_title=VALUES(course_title),course_id=VALUES(course_id),body=VALUES(body),file_name=VALUES(file_name),file_original_name=VALUES(file_original_name),file_mime=VALUES(file_mime),file_size=VALUES(file_size)');
    $statement->execute(['position'=>$id,'class_group_id'=>$classGroupId,'title'=>$title,'description'=>$description,'course_title'=>$courses[$courseId],'course_id'=>$courseId,'body'=>$body,'file_name'=>$assetFields[0],'file_original_name'=>$assetFields[1],'file_mime'=>$assetFields[2],'file_size'=>$assetFields[3]]);
} catch (Throwable $exception) {
    if ($newUploadName !== null) @unlink($uploadDir . '/' . $newUploadName);
    error_log('MutqinSpace material save database error: ' . $exception->getMessage());
    respond(500, ['ok'=>false, 'message'=>'Materi belum dapat disimpan ke database.']);
}
if (($newUploadName !== null || (($payload['removeAttachment'] ?? '') === '1')) && $oldAsset && $validStoredFile($oldAsset['file_name'] ?? null)) @unlink($uploadDir . '/' . $oldAsset['file_name']);
$material = [$title,$description,$courses[$courseId],$courseId,$body,$asset ?: null,$classGroupId];
respond(200, ['ok'=>true, 'id'=>$id, 'material'=>$material]);
