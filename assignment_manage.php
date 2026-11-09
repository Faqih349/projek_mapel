<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function assignmentManageReply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') assignmentManageReply(405, ['ok'=>false,'message'=>'Metode permintaan tidak didukung.']);
if (($_SESSION['user']['role'] ?? '') !== 'teacher') assignmentManageReply(403, ['ok'=>false,'message'=>'Hanya akun guru yang dapat mengelola tugas dan kuis.']);
$payload = $_POST;
if (empty($payload)) $payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($payload['csrfToken'] ?? ''))) {
    assignmentManageReply(403, ['ok'=>false,'message'=>'Sesi berakhir. Muat ulang halaman lalu coba lagi.']);
}

try { $db = mutqinDb(); } catch (Throwable $exception) {
    error_log('MutqinSpace assignment database error: ' . $exception->getMessage());
    assignmentManageReply(503, ['ok'=>false,'message'=>'Database MySQL belum siap. Impor database.sql dan jalankan migrasi data.']);
}
$assignmentColumns = $db->query('SHOW COLUMNS FROM assignments')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('exam_mode', $assignmentColumns, true)) $db->exec('ALTER TABLE assignments ADD COLUMN exam_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER type');
if (!in_array('class_group_id', $assignmentColumns, true)) $db->exec('ALTER TABLE assignments ADD COLUMN class_group_id BIGINT UNSIGNED NULL AFTER course_id');
$catalog = [];
$catalogQuery = $db->prepare('SELECT a.task_id,a.course_id,a.class_group_id,a.course_title,a.type,a.exam_mode,a.icon,a.title,a.due,a.description,a.prompt,a.questions,a.file_name,a.file_original_name,a.file_mime,a.file_size FROM assignments a LEFT JOIN class_groups cg ON cg.id=a.class_group_id LEFT JOIN users u ON u.id=cg.teacher_id WHERE u.username=:username OR a.class_group_id IS NULL ORDER BY a.created_at,a.task_id');
$catalogQuery->execute(['username'=>(string)$_SESSION['user']['username']]);
foreach ($catalogQuery->fetchAll() as $row) {
    $assignmentRow = ['id'=>$row['task_id'],'courseId'=>$row['course_id'],'classGroupId'=>$row['class_group_id'] === null ? null : (int)$row['class_group_id'],'courseTitle'=>$row['course_title'],'type'=>$row['type'],'examMode'=>(bool)$row['exam_mode'],'icon'=>$row['icon'],'title'=>$row['title'],'due'=>$row['due'],'description'=>$row['description'],'prompt'=>$row['prompt'],'questions'=>json_decode((string)$row['questions'], true) ?: []];
    if (!empty($row['file_name'])) $assignmentRow['attachment'] = ['file'=>$row['file_name'],'name'=>$row['file_original_name'],'mime'=>$row['file_mime'],'size'=>(int)$row['file_size']];
    $catalog[] = $assignmentRow;
}
$action = (string)($payload['action'] ?? 'save');
$id = (string)($payload['id'] ?? '');
$index = null;
foreach ($catalog as $key => $item) if (($item['id'] ?? '') === $id && $id !== '') { $index = $key; break; }
$submissionsPath = __DIR__ . '/storage/submissions.json';
$submissions = json_decode((string)file_get_contents($submissionsPath), true);
if (!is_array($submissions)) $submissions = [];
$uploadDir = __DIR__ . '/storage/material_uploads';
$validStoredFile = static fn($file): bool => is_string($file) && preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp|gif|mp4|webm|ogv)$/', $file) === 1;
$oldAttachment = null;
$newUploadName = null;

if ($action === 'delete') {
    if ($index === null) assignmentManageReply(404, ['ok'=>false,'message'=>'Tugas yang akan dihapus tidak ditemukan.']);
    $oldAttachment = $catalog[$index]['attachment'] ?? null;
    array_splice($catalog, $index, 1);
    foreach ($submissions as &$userSubmissions) if (is_array($userSubmissions)) unset($userSubmissions[$id]);
    unset($userSubmissions);
} elseif ($action === 'save') {
    $courses = [
        'quran'=>'Al-Qur’an dan Hadis', 'akhlak'=>'Akidah Akhlak',
        'sejarah'=>'Sejarah Kebudayaan Islam', 'fikih'=>'Fikih',
    ];
    $title = trim((string)($payload['title'] ?? ''));
    $description = trim((string)($payload['description'] ?? ''));
    $prompt = trim((string)($payload['prompt'] ?? ''));
    $due = trim((string)($payload['due'] ?? ''));
    $courseId = (string)($payload['courseId'] ?? '');
    $classGroupId = filter_var($payload['classGroupId'] ?? null, FILTER_VALIDATE_INT);
    $classGroupId = $classGroupId === false ? 0 : (int)$classGroupId;
    $classCheck = $db->prepare('SELECT id,class_name,course_id FROM class_groups WHERE id=:id AND teacher_id=(SELECT id FROM users WHERE username=:username LIMIT 1) LIMIT 1');
    $classCheck->execute(['id'=>$classGroupId,'username'=>(string)$_SESSION['user']['username']]);
    $targetClass = $classCheck->fetch();
    $type = (string)($payload['type'] ?? 'essay');
    $examMode = $type === 'quiz' && filter_var($payload['examMode'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $length = static fn(string $value): int => function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($title === '' || $length($title) > 150 || $description === '' || $length($description) > 300 || $prompt === '' || $length($prompt) > 5000 || $due === '' || $length($due) > 80 || !isset($courses[$courseId]) || !$targetClass || (string)$targetClass['course_id'] !== $courseId || !in_array($type, ['essay','quiz'], true)) {
        assignmentManageReply(422, ['ok'=>false,'message'=>'Lengkapi judul, tenggat, deskripsi, dan petunjuk tugas.']);
    }
    $questions = [];
    if ($type === 'quiz') {
        $inputQuestions = $payload['questions'] ?? null;
        if (is_string($inputQuestions)) $inputQuestions = json_decode($inputQuestions, true);
        if (!is_array($inputQuestions) || count($inputQuestions) < 1 || count($inputQuestions) > 15) {
            assignmentManageReply(422, ['ok'=>false,'message'=>'Kuis harus memiliki 1 sampai 15 soal.']);
        }
        foreach ($inputQuestions as $question) {
            if (!is_array($question)) assignmentManageReply(422, ['ok'=>false,'message'=>'Format soal tidak valid.']);
            $questionText = trim((string)($question['question'] ?? ''));
            $options = $question['options'] ?? null;
            $answer = filter_var($question['answer'] ?? null, FILTER_VALIDATE_INT);
            if ($questionText === '' || $length($questionText) > 500 || !is_array($options) || count($options) !== 4 || $answer === false || $answer < 0 || $answer > 3) {
                assignmentManageReply(422, ['ok'=>false,'message'=>'Setiap soal perlu teks, empat pilihan jawaban, dan satu kunci jawaban.']);
            }
            $cleanOptions = [];
            foreach ($options as $option) {
                $option = trim((string)$option);
                if ($option === '' || $length($option) > 300) assignmentManageReply(422, ['ok'=>false,'message'=>'Semua empat pilihan jawaban harus diisi (maksimal 300 karakter per pilihan).']);
                $cleanOptions[] = $option;
            }
            $questions[] = ['question'=>$questionText,'options'=>$cleanOptions,'answer'=>$answer];
        }
    }

    if ($index === null) $id = 'task_' . bin2hex(random_bytes(8));
    $existingAttachment = $index === null ? null : ($catalog[$index]['attachment'] ?? null);
    $oldAttachment = $existingAttachment;
    $attachment = is_array($existingAttachment) ? $existingAttachment : null;
    $uploaded = $_FILES['attachment'] ?? null;
    if (is_array($uploaded) && (int)($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $uploadError = (int)($uploaded['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) assignmentManageReply(422, ['ok'=>false,'message'=>$uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE ? 'Ukuran file melewati batas unggahan server. Coba file yang lebih kecil.' : 'File gagal diunggah.']);
        $size = (int)($uploaded['size'] ?? 0);
        if ($size < 1 || $size > 35 * 1024 * 1024) assignmentManageReply(422, ['ok'=>false,'message'=>'Ukuran file maksimal 35 MB.']);
        if (!is_uploaded_file((string)($uploaded['tmp_name'] ?? '')) || !class_exists('finfo')) assignmentManageReply(422, ['ok'=>false,'message'=>'File unggahan tidak valid atau pemeriksaan tipe file tidak tersedia.']);
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file((string)$uploaded['tmp_name']);
        $allowed = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','video/mp4'=>'mp4','video/webm'=>'webm','video/ogg'=>'ogv','application/ogg'=>'ogv'];
        if (!isset($allowed[$mime])) assignmentManageReply(415, ['ok'=>false,'message'=>'Format belum didukung. Gunakan PDF, foto, atau video (maksimal 35 MB).']);
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) assignmentManageReply(500, ['ok'=>false,'message'=>'Folder penyimpanan lampiran tidak dapat dibuat.']);
        $newUploadName = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file((string)$uploaded['tmp_name'], $uploadDir . '/' . $newUploadName)) assignmentManageReply(500, ['ok'=>false,'message'=>'File belum dapat disimpan. Periksa izin folder storage.']);
        $attachment = ['file'=>$newUploadName,'name'=>basename((string)($uploaded['name'] ?? 'lampiran')),'mime'=>$mime,'size'=>$size];
    } elseif (($payload['removeAttachment'] ?? '') === '1') {
        $attachment = null;
    }
    $assignment = [
        'id'=>$id, 'courseId'=>$courseId, 'classGroupId'=>$classGroupId, 'className'=>$targetClass['class_name'], 'courseTitle'=>$courses[$courseId],
        'type'=>$type, 'icon'=>$type === 'quiz' ? '☑' : '✎', 'title'=>$title,
        'examMode'=>$examMode, 'due'=>$due, 'description'=>$description, 'prompt'=>$prompt, 'questions'=>$questions,
    ];
    if (is_array($attachment)) $assignment['attachment'] = $attachment;
    if ($index === null) {
        $catalog[] = $assignment;
    } else {
        $old = $catalog[$index];
        $catalog[$index] = $assignment;
        if (($old['type'] ?? '') !== $type || ($old['questions'] ?? []) !== $questions) {
            foreach ($submissions as &$userSubmissions) if (is_array($userSubmissions)) unset($userSubmissions[$id]);
            unset($userSubmissions);
        }
    }
} else {
    assignmentManageReply(422, ['ok'=>false,'message'=>'Aksi tidak dikenal.']);
}

$encodedSubmissions = json_encode($submissions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
try {
    if ($action === 'delete') {
        $db->prepare('DELETE FROM assignments WHERE task_id=:id')->execute(['id'=>$id]);
    } else {
        $asset = $assignment['attachment'] ?? [];
        $statement = $db->prepare('INSERT INTO assignments (task_id,course_id,class_group_id,course_title,type,exam_mode,icon,title,due,description,prompt,questions,file_name,file_original_name,file_mime,file_size) VALUES (:task_id,:course_id,:class_group_id,:course_title,:type,:exam_mode,:icon,:title,:due,:description,:prompt,:questions,:file_name,:file_original_name,:file_mime,:file_size) ON DUPLICATE KEY UPDATE course_id=VALUES(course_id),class_group_id=VALUES(class_group_id),course_title=VALUES(course_title),type=VALUES(type),exam_mode=VALUES(exam_mode),icon=VALUES(icon),title=VALUES(title),due=VALUES(due),description=VALUES(description),prompt=VALUES(prompt),questions=VALUES(questions),file_name=VALUES(file_name),file_original_name=VALUES(file_original_name),file_mime=VALUES(file_mime),file_size=VALUES(file_size)');
        $statement->execute(['task_id'=>$assignment['id'],'course_id'=>$assignment['courseId'],'class_group_id'=>$assignment['classGroupId'],'course_title'=>$assignment['courseTitle'],'type'=>$assignment['type'],'exam_mode'=>$assignment['examMode']?1:0,'icon'=>$assignment['icon'],'title'=>$assignment['title'],'due'=>$assignment['due'],'description'=>$assignment['description'],'prompt'=>$assignment['prompt'],'questions'=>json_encode($assignment['questions'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),'file_name'=>$asset['file'] ?? null,'file_original_name'=>$asset['name'] ?? null,'file_mime'=>$asset['mime'] ?? null,'file_size'=>$asset['size'] ?? null]);
    }
} catch (Throwable $exception) {
    if ($newUploadName !== null) @unlink($uploadDir . '/' . $newUploadName);
    error_log('MutqinSpace assignment database error: ' . $exception->getMessage());
    assignmentManageReply(500, ['ok'=>false,'message'=>'Perubahan tugas belum dapat disimpan ke database.']);
}
if ($action === 'delete' || $newUploadName !== null || (($payload['removeAttachment'] ?? '') === '1')) {
    if (is_array($oldAttachment) && $validStoredFile($oldAttachment['file'] ?? null)) @unlink($uploadDir . '/' . $oldAttachment['file']);
}
if ($encodedSubmissions === false || file_put_contents($submissionsPath, $encodedSubmissions, LOCK_EX) === false) assignmentManageReply(500, ['ok'=>false,'message'=>'Tugas tersimpan, tetapi data pengumpulan lama gagal diperbarui.']);
assignmentManageReply(200, ['ok'=>true,'assignment'=>$action === 'save' ? $assignment : null,'id'=>$id]);
