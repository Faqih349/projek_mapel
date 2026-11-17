<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/db.php';

$db = mutqinDb();
$columns = $db->query('SHOW TABLES LIKE \'submissions\'')->fetchColumn();
if (!$columns) {
    fwrite(STDERR, "Tabel pengumpulan belum ada. Impor database.sql terlebih dahulu.\n");
    exit(1);
}

$path = __DIR__ . '/storage/submissions.json';
$legacy = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
if (!is_array($legacy)) {
    fwrite(STDERR, "File storage/submissions.json tidak berisi data JSON yang valid.\n");
    exit(1);
}

$studentLookup = $db->prepare('SELECT id FROM users WHERE username=:username AND role=\'student\' LIMIT 1');
$save = $db->prepare('INSERT IGNORE INTO submissions (task_id,student_id,submission_data,revision,score,submitted_at) SELECT :task_id,:student_id,:submission_data,1,:score,:submitted_at WHERE EXISTS (SELECT 1 FROM assignments WHERE task_id=:task_id_check)');
$migrated = 0;
$skipped = 0;

try {
    $db->beginTransaction();
    foreach ($legacy as $username => $items) {
        if (!is_string($username) || !is_array($items)) {
            $skipped++;
            continue;
        }
        $studentLookup->execute(['username'=>$username]);
        $studentId = $studentLookup->fetchColumn();
        if (!$studentId) {
            $skipped += count($items);
            continue;
        }
        foreach ($items as $taskId => $submission) {
            if (!is_string($taskId) || !is_array($submission)) {
                $skipped++;
                continue;
            }
            $encoded = json_encode($submission, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded === false) {
                $skipped++;
                continue;
            }
            try {
                $submittedAt = new DateTimeImmutable((string)($submission['submittedAt'] ?? 'now'));
            } catch (Throwable) {
                $submittedAt = new DateTimeImmutable('now');
            }
            $submittedAt = $submittedAt->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('Y-m-d H:i:s');
            $score = isset($submission['score']) && is_numeric($submission['score'])
                ? max(0, min(100, (float)$submission['score']))
                : null;
            $save->execute([
                'task_id'=>$taskId,
                'student_id'=>(int)$studentId,
                'submission_data'=>$encoded,
                'score'=>$score,
                'submitted_at'=>$submittedAt,
                'task_id_check'=>$taskId,
            ]);
            if ($save->rowCount() > 0) $migrated++;
            else $skipped++;
        }
    }
    $db->commit();
    fwrite(STDOUT, "Migrasi pengumpulan selesai: {$migrated} data dipindahkan, {$skipped} dilewati.\n");
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'Migrasi pengumpulan gagal: ' . $error->getMessage() . "\n");
    exit(1);
}
