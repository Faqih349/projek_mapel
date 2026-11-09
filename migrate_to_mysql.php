<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/db.php';

$db = mutqinDb();
$userColumns = $db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('nisn', $userColumns, true)) {
    $db->exec("ALTER TABLE users ADD COLUMN nisn VARCHAR(30) NOT NULL DEFAULT '' AFTER class_name");
}
$assignmentColumns = $db->query('SHOW COLUMNS FROM assignments')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('exam_mode', $assignmentColumns, true)) {
    $db->exec('ALTER TABLE assignments ADD COLUMN exam_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER type');
}
$materialColumns = $db->query('SHOW COLUMNS FROM materials')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('class_group_id', $materialColumns, true)) $db->exec('ALTER TABLE materials ADD COLUMN class_group_id BIGINT UNSIGNED NULL AFTER position');
$readJson = static function (string $name): array {
    $path = __DIR__ . '/storage/' . $name;
    $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    return is_array($data) ? $data : [];
};
$accounts = $readJson('users.json');
$materials = $readJson('materials.json');
$assignments = $readJson('assignments.json');
$db->beginTransaction();

try {
    $saveUser = $db->prepare('INSERT INTO users (username,name,email,initials,class_name,nisn,role,password_hash) VALUES (:username,:name,:email,:initials,:class_name,:nisn,:role,:password_hash) ON DUPLICATE KEY UPDATE name=VALUES(name),email=VALUES(email),initials=VALUES(initials),class_name=VALUES(class_name),role=VALUES(role),password_hash=VALUES(password_hash)');
    foreach ($accounts as $account) {
        if (!is_array($account) || empty($account['username']) || empty($account['password_hash'])) continue;
        $saveUser->execute([
            'username'=>(string)$account['username'], 'name'=>(string)($account['name'] ?? $account['username']),
            'email'=>(string)($account['email'] ?? ''), 'initials'=>(string)($account['initials'] ?? ''),
            'class_name'=>(string)($account['class'] ?? ''), 'role'=>($account['role'] ?? '') === 'teacher' ? 'teacher' : 'student',
            'nisn'=>(string)($account['nisn'] ?? ''),
            'password_hash'=>(string)$account['password_hash'],
        ]);
    }

    $saveMaterial = $db->prepare('INSERT INTO materials (position,title,description,course_title,course_id,body,file_name,file_original_name,file_mime,file_size) VALUES (:position,:title,:description,:course_title,:course_id,:body,:file_name,:file_original_name,:file_mime,:file_size) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),course_title=VALUES(course_title),course_id=VALUES(course_id),body=VALUES(body),file_name=VALUES(file_name),file_original_name=VALUES(file_original_name),file_mime=VALUES(file_mime),file_size=VALUES(file_size)');
    foreach (array_values($materials) as $position=>$material) {
        if (!is_array($material)) continue;
        $asset = is_array($material[5] ?? null) ? $material[5] : [];
        $saveMaterial->execute([
            'position'=>$position, 'title'=>(string)($material[0] ?? ''), 'description'=>(string)($material[1] ?? ''),
            'course_title'=>(string)($material[2] ?? ''), 'course_id'=>(string)($material[3] ?? ''), 'body'=>(string)($material[4] ?? ''),
            'file_name'=>$asset['file'] ?? null, 'file_original_name'=>$asset['name'] ?? null, 'file_mime'=>$asset['mime'] ?? null, 'file_size'=>$asset['size'] ?? null,
        ]);
    }

    $saveAssignment = $db->prepare('INSERT INTO assignments (task_id,course_id,course_title,type,icon,title,due,description,prompt,questions,file_name,file_original_name,file_mime,file_size) VALUES (:task_id,:course_id,:course_title,:type,:icon,:title,:due,:description,:prompt,:questions,:file_name,:file_original_name,:file_mime,:file_size) ON DUPLICATE KEY UPDATE course_id=VALUES(course_id),course_title=VALUES(course_title),type=VALUES(type),icon=VALUES(icon),title=VALUES(title),due=VALUES(due),description=VALUES(description),prompt=VALUES(prompt),questions=VALUES(questions),file_name=VALUES(file_name),file_original_name=VALUES(file_original_name),file_mime=VALUES(file_mime),file_size=VALUES(file_size)');
    foreach ($assignments as $assignment) {
        if (!is_array($assignment) || empty($assignment['id'])) continue;
        $asset = is_array($assignment['attachment'] ?? null) ? $assignment['attachment'] : [];
        $saveAssignment->execute([
            'task_id'=>(string)$assignment['id'], 'course_id'=>(string)($assignment['courseId'] ?? ''),
            'course_title'=>(string)($assignment['courseTitle'] ?? ''), 'type'=>($assignment['type'] ?? '') === 'quiz' ? 'quiz' : 'essay',
            'icon'=>(string)($assignment['icon'] ?? ''), 'title'=>(string)($assignment['title'] ?? ''), 'due'=>(string)($assignment['due'] ?? ''),
            'description'=>(string)($assignment['description'] ?? ''), 'prompt'=>(string)($assignment['prompt'] ?? ''),
            'questions'=>json_encode($assignment['questions'] ?? [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'file_name'=>$asset['file'] ?? null, 'file_original_name'=>$asset['name'] ?? null, 'file_mime'=>$asset['mime'] ?? null, 'file_size'=>$asset['size'] ?? null,
        ]);
    }
    $db->commit();
    fwrite(STDOUT, 'Migrasi selesai: ' . count($accounts) . ' akun, ' . count($materials) . ' materi, ' . count($assignments) . " tugas/kuis diproses.\n");
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'Migrasi gagal: ' . $error->getMessage() . "\n");
    exit(1);
}
