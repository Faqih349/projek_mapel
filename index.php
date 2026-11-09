<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';

if (empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

try {
    $db = mutqinDb();
    $materialColumns = $db->query('SHOW COLUMNS FROM materials')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('class_group_id', $materialColumns, true)) $db->exec('ALTER TABLE materials ADD COLUMN class_group_id BIGINT UNSIGNED NULL AFTER position');
} catch (Throwable $exception) {
    error_log('MutqinSpace page database error: ' . $exception->getMessage());
    http_response_code(503);
    exit('Database belum siap. Impor database.sql dan jalankan: php migrate_to_mysql.php');
}

// Data kelas tetap disiapkan di aplikasi; akun, materi, dan tugas dibaca dari MySQL.
$courses = [
    ['id'=>'quran','title'=>'Al-Qur’an dan Hadis','desc'=>'Memahami ayat tentang hidup damai dan saling menghargai.','unit'=>'Bab 4 · Toleransi dalam Islam','teacher'=>'Ust. Naufal Hakim','progress'=>68,'lessons'=>8,'done'=>5,'cover'=>'cover-quran','mark'=>'✧'],
    ['id'=>'akhlak','title'=>'Akidah Akhlak','desc'=>'Membiasakan perilaku jujur, santun, dan bertanggung jawab.','unit'=>'Bab 3 · Adab pergaulan Islami','teacher'=>'Ustazah Siti Rahmah','progress'=>42,'lessons'=>7,'done'=>3,'cover'=>'cover-akhlak','mark'=>'✦'],
    ['id'=>'sejarah','title'=>'Sejarah Kebudayaan Islam','desc'=>'Meneladani dakwah dan perkembangan Islam di Nusantara.','unit'=>'Bab 2 · Islam di Nusantara','teacher'=>'Ust. Fajar Maulana','progress'=>85,'lessons'=>6,'done'=>5,'cover'=>'cover-sejarah','mark'=>'✧'],
    ['id'=>'fikih','title'=>'Fikih','desc'=>'Mengenal prinsip ibadah dan muamalah dalam kehidupan.','unit'=>'Bab 5 · Prinsip ekonomi syariah','teacher'=>'Ustazah Siti Rahmah','progress'=>25,'lessons'=>8,'done'=>2,'cover'=>'cover-fikih','mark'=>'✧'],
];
$tasks = [
    ['icon'=>'▤','title'=>'Refleksi: Toleransi dalam Kehidupan','meta'=>'Al-Qur’an dan Hadis · Dikumpulkan 12 Okt 2026','status'=>'Segera','kind'=>'due'],
    ['icon'=>'✎','title'=>'Kuis Adab Pergaulan Islami','meta'=>'Akidah Akhlak · Dikumpulkan 15 Okt 2026','status'=>'Dikerjakan','kind'=>'done'],
    ['icon'=>'▣','title'=>'Rangkuman Islam di Nusantara','meta'=>'Sejarah Kebudayaan Islam · Dikumpulkan 18 Okt 2026','status'=>'Belum mulai','kind'=>'due'],
];
$lessons = [];
foreach ($db->query('SELECT position,class_group_id,title,description,course_title,course_id,body,file_name,file_original_name,file_mime,file_size FROM materials ORDER BY position')->fetchAll() as $row) {
    $lesson = [$row['title'],$row['description'],$row['course_title'],$row['course_id'],$row['body']];
    $lesson[] = !empty($row['file_name']) ? ['file'=>$row['file_name'],'name'=>$row['file_original_name'],'mime'=>$row['file_mime'],'size'=>(int)$row['file_size']] : null;
    $lesson[] = $row['class_group_id'] === null ? null : (int)$row['class_group_id'];
    $lessons[] = $lesson;
}
$libraryItems = $db->query('SELECT id,category,grade_level,juz_number,title,file_original_name,file_mime AS mime,file_size FROM library_items ORDER BY category,grade_level,juz_number DESC,created_at DESC')->fetchAll();
$libraryItems = array_map(static fn(array $item): array => ['id'=>(int)$item['id'],'category'=>$item['category'],'gradeLevel'=>$item['grade_level']===null?null:(int)$item['grade_level'],'juzNumber'=>$item['juz_number']===null?null:(int)$item['juz_number'],'title'=>$item['title'],'originalName'=>$item['file_original_name'],'mime'=>$item['mime'],'size'=>(int)$item['file_size']], $libraryItems);
$pageContents = json_decode((string)file_get_contents(__DIR__ . '/storage/page_content.json'), true);
if (!is_array($pageContents)) $pageContents = [];
$student = $_SESSION['user'];
$student['email'] = (string)($student['email'] ?? '');
$student['nisn'] = (string)($student['nisn'] ?? '');
$userQuery = $db->prepare('SELECT id FROM users WHERE username=:username LIMIT 1');
$userQuery->execute(['username'=>(string)$student['username']]);
$currentUserId = (int)$userQuery->fetchColumn();
$classSql = "SELECT cg.id,cg.class_name,cg.course_id,cg.join_code,u.name AS teacher_name,(SELECT COUNT(*) FROM class_memberships cm WHERE cm.class_group_id=cg.id) AS member_count FROM class_groups cg JOIN users u ON u.id=cg.teacher_id";
if (($student['role'] ?? '') === 'teacher') {
    $classQuery = $db->prepare($classSql . ' WHERE cg.teacher_id=:user_id ORDER BY cg.created_at DESC');
    $classQuery->execute(['user_id'=>$currentUserId]);
} else {
    $classQuery = $db->prepare($classSql . ' JOIN class_memberships mine ON mine.class_group_id=cg.id WHERE mine.student_id=:user_id ORDER BY mine.joined_at DESC');
    $classQuery->execute(['user_id'=>$currentUserId]);
}
$courseTitles = ['quran'=>'Pendidikan Agama Islam','akhlak'=>'Pendidikan Agama Islam','sejarah'=>'Pendidikan Agama Islam','fikih'=>'Pendidikan Agama Islam'];
$classGroups = array_map(static fn(array $row): array => ['id'=>(int)$row['id'],'className'=>$row['class_name'],'courseId'=>$row['course_id'],'courseTitle'=>$courseTitles[$row['course_id']] ?? $row['course_id'],'joinCode'=>$row['join_code'],'teacherName'=>$row['teacher_name'],'memberCount'=>(int)$row['member_count']], $classQuery->fetchAll());
$classIds = array_column($classGroups, 'id');
foreach ($classGroups as &$classGroup) $classGroup['students'] = [];
unset($classGroup);
if ($classIds) {
    $placeholders = implode(',', array_fill(0, count($classIds), '?'));
    $memberQuery = $db->prepare("SELECT cm.class_group_id,u.username,u.name,u.initials FROM class_memberships cm JOIN users u ON u.id=cm.student_id WHERE cm.class_group_id IN ($placeholders) ORDER BY u.name");
    $memberQuery->execute($classIds);
    $membersByClass = [];
    foreach ($memberQuery->fetchAll() as $member) $membersByClass[(int)$member['class_group_id']][] = ['username'=>$member['username'],'name'=>$member['name'],'initials'=>$member['initials']];
    foreach ($classGroups as &$classGroup) $classGroup['students'] = $membersByClass[$classGroup['id']] ?? [];
    unset($classGroup);
}
$submissionData = json_decode((string)file_get_contents(__DIR__ . '/storage/submissions.json'), true) ?: [];
$studentAccounts = $db->query("SELECT username,name,initials,class_name,role FROM users WHERE role='student' ORDER BY name")->fetchAll();
$studentByUsername = [];
foreach ($studentAccounts as $account) $studentByUsername[$account['username']] = $account;
$submissionsByTask = [];
foreach ($submissionData as $username => $userSubmissions) {
    if (!isset($studentByUsername[$username]) || !is_array($userSubmissions)) continue;
    foreach ($userSubmissions as $taskId => $submission) {
        if (!is_array($submission)) continue;
        $account = $studentByUsername[$username];
        $entry = ['username'=>$username,'name'=>$account['name'],'className'=>$account['class_name'],'response'=>(string)($submission['response'] ?? ''),'answers'=>is_array($submission['answers'] ?? null) ? $submission['answers'] : [],'score'=>$submission['score'] ?? null,'submittedAt'=>(string)($submission['submittedAt'] ?? '')];
        if (is_array($submission['attachment'] ?? null)) $entry['attachment'] = $submission['attachment'];
        $submissionsByTask[$taskId][] = $entry;
    }
}
$studentSubmissions = $submissionData[$student['username']] ?? [];
$assignmentCatalog = [];
$assignmentColumns = $db->query('SHOW COLUMNS FROM assignments')->fetchAll(PDO::FETCH_COLUMN);
$examModeColumn = in_array('exam_mode', $assignmentColumns, true) ? 'exam_mode' : '0 AS exam_mode';
if (!in_array('class_group_id', $assignmentColumns, true)) { $db->exec('ALTER TABLE assignments ADD COLUMN class_group_id BIGINT UNSIGNED NULL AFTER course_id'); $assignmentColumns[] = 'class_group_id'; }
$classGroupColumn = 'class_group_id';
foreach ($db->query("SELECT task_id,course_id,{$classGroupColumn},course_title,type,{$examModeColumn},icon,title,due,description,prompt,questions,file_name,file_original_name,file_mime,file_size FROM assignments ORDER BY created_at,task_id")->fetchAll() as $row) {
    $row['id'] = $row['task_id'];
    $row['courseId'] = $row['course_id'];
    $row['classGroupId'] = $row['class_group_id'] === null ? null : (int)$row['class_group_id'];
    $targetGroup = null; foreach ($classGroups as $group) if ($group['id'] === $row['classGroupId']) { $targetGroup = $group; break; }
    $row['className'] = $targetGroup['className'] ?? '';
    if ($row['class_group_id'] !== null && !$targetGroup) continue;
    if (($student['role'] ?? '') !== 'teacher' && !$targetGroup) continue;
    $row['courseTitle'] = $row['course_title'];
    $row['questions'] = json_decode((string)$row['questions'], true) ?: [];
    if (!empty($row['file_name'])) $row['attachment'] = ['file'=>$row['file_name'],'name'=>$row['file_original_name'],'mime'=>$row['file_mime'],'size'=>(int)$row['file_size']];
    foreach (['task_id','course_id','class_group_id','course_title','file_name','file_original_name','file_mime','file_size'] as $column) unset($row[$column]);
    $assignmentCatalog[] = $row;
}
$tasks = [];
foreach ($assignmentCatalog as $assignment) {
    $submission = $studentSubmissions[$assignment['id']] ?? null;
    if (($student['role'] ?? '') !== 'teacher') {
        foreach ($assignment['questions'] as &$question) unset($question['answer']);
        unset($question);
    }
    $tasks[] = [
        'id'=>$assignment['id'], 'courseId'=>$assignment['courseId'], 'classGroupId'=>$assignment['classGroupId'], 'className'=>$assignment['className'], 'courseTitle'=>$assignment['courseTitle'],
        'type'=>$assignment['type'], 'examMode'=>(bool)($assignment['exam_mode'] ?? false), 'icon'=>$assignment['icon'], 'title'=>$assignment['title'],
        'meta'=>$assignment['courseTitle'] . ' · Dikumpulkan ' . $assignment['due'], 'due'=>$assignment['due'],
        'description'=>$assignment['description'], 'prompt'=>$assignment['prompt'], 'questions'=>$assignment['questions'], 'attachment'=>$assignment['attachment'] ?? null,
        'submissions'=>($student['role'] ?? '') === 'teacher' ? ($submissionsByTask[$assignment['id']] ?? []) : [],
        'status'=>$submission ? ($assignment['type'] === 'quiz' ? 'Nilai ' . $submission['score'] . '/' . count($assignment['questions']) : 'Terkumpul') : 'Belum dikerjakan',
        'kind'=>$submission ? 'done' : 'due', 'submitted'=>(bool)$submission, 'score'=>$submission['score'] ?? null, 'submission'=>$submission,
    ];
}
$progressData = json_decode((string)file_get_contents(__DIR__ . '/storage/progress.json'), true) ?: [];
$completedLessons = $progressData[$student['username']] ?? [];
$leaderboard = [];
$leaderboardStudentUsernames = [];
if ($classIds) {
    $leaderboardPlaceholders = implode(',', array_fill(0, count($classIds), '?'));
    $leaderboardMembers = $db->prepare("SELECT DISTINCT u.username FROM class_memberships cm JOIN users u ON u.id=cm.student_id WHERE cm.class_group_id IN ($leaderboardPlaceholders) AND u.role='student'");
    $leaderboardMembers->execute(array_map('intval', $classIds));
    $leaderboardStudentUsernames = array_fill_keys($leaderboardMembers->fetchAll(PDO::FETCH_COLUMN), true);
}
foreach ($studentAccounts as $account) {
    if (($account['role'] ?? '') !== 'student') continue;
    if (!isset($leaderboardStudentUsernames[$account['username']])) continue;
    $userSubmissions = $submissionData[$account['username']] ?? [];
    $quizScore = 0;
    $quizMaximum = 0;
    foreach ($assignmentCatalog as $assignment) {
        if ($assignment['type'] !== 'quiz') continue;
        $quizMaximum += count($assignment['questions']);
        $quizScore += (int)($userSubmissions[$assignment['id']]['score'] ?? 0);
    }
    $leaderboard[] = [
        'username'=>$account['username'], 'name'=>$account['name'], 'initials'=>$account['initials'],
        'score'=>$quizMaximum > 0 ? (int)round($quizScore / $quizMaximum * 100) : 0,
        'quizScore'=>$quizScore, 'quizMaximum'=>$quizMaximum,
        'completed'=>count($progressData[$account['username']] ?? []),
        'submitted'=>count($userSubmissions),
    ];
}
usort($leaderboard, static fn(array $a, array $b): int => [$b['score'], $b['completed'], $a['name']] <=> [$a['score'], $a['completed'], $b['name']]);
foreach ($courses as &$course) {
    $courseLessonIndexes = [];
    foreach ($lessons as $index => $lesson) if (($lesson[3] ?? '') === $course['id']) $courseLessonIndexes[] = $index;
    $course['lessons'] = count($courseLessonIndexes);
    $course['done'] = count(array_intersect($completedLessons, $courseLessonIndexes));
    $course['progress'] = $course['lessons'] > 0 ? (int)round($course['done'] / $course['lessons'] * 100) : 0;
}
unset($course);
$studentDirectory = array_map(static fn(array $account): array => ['username'=>$account['username'],'name'=>$account['name'],'initials'=>$account['initials']], $studentAccounts);
$lmsData = json_encode(['courses'=>$courses,'tasks'=>$tasks,'lessons'=>$lessons,'student'=>$student,'csrfToken'=>$_SESSION['csrf_token'],'completedLessons'=>$completedLessons,'leaderboard'=>$leaderboard,'studentDirectory'=>$studentDirectory,'pageContents'=>$pageContents,'classGroups'=>$classGroups,'libraryItems'=>$libraryItems], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!doctype html>
<html lang="id"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f5f7f2">
  <title>MutqinSpace — Belajar Agama</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="style.css?v=20261104-encoding-fix">
</head><body>
<div class="app-shell"><aside class="sidebar" id="sidebar">
  <div class="night-stars" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div><div class="cartoon-moon" aria-hidden="true"><span></span></div>
  <a class="brand" href="index.php"><span class="brand-mark">م</span><span><b>Mutqin<span class="brand-accent">Space</span></b><small>Belajar dengan makna</small></span></a>
  <div class="side-label">MENU UTAMA</div><nav class="nav-list" aria-label="Navigasi utama">
    <button class="nav-item active" data-page="beranda"><span class="nav-icon">&#8962;</span> Beranda</button><button class="nav-item" data-page="kelas"><span class="nav-icon">&#9638;</span> Kelas saya <span class="nav-count"><?= count($classGroups) ?></span></button><button class="nav-item" data-page="perpustakaan"><span class="nav-icon">&#128218;</span> Perpustakaan</button><?php if ($student['role'] !== 'teacher'): ?><button class="nav-item" data-page="materi"><span class="nav-icon">&#9635;</span> Materi belajar</button><button class="nav-item" data-page="tugas"><span class="nav-icon">&#9745;</span> Tugas & kuis</button><button class="nav-item" data-page="informasi"><span class="nav-icon">&#9711;</span> Ruang informasi</button><?php endif; ?>
  </nav><div class="side-label side-label-spaced">LAINNYA</div><nav class="nav-list"><?php if ($student['role'] !== 'teacher'): ?><button class="nav-item" data-page="leaderboard"><span class="nav-icon">&#127942;</span> Leaderboard nilai siswa</button><?php endif; ?><button class="nav-item" data-page="bantuan"><span class="nav-icon">?</span> Pusat bantuan</button></nav>  <div class="sidebar-bottom"><div class="school-brand"><span class="school-brand-mark">24</span><span><b>SMAN 24 Bandung</b><small>Ruang belajar digital</small></span></div></div>
</aside><main class="main-area"><header class="topbar"><button class="mobile-menu" id="mobileMenu" aria-label="Buka navigasi">&#9776;</button><div class="breadcrumb">Ruang belajar <span>/</span> <b id="crumb">Beranda</b></div><div class="top-actions"><button class="icon-button" aria-label="Notifikasi" id="notifications">&#128276;<i></i></button><div class="profile-menu-wrap"><button type="button" class="profile-trigger" id="profileMenuToggle" aria-expanded="false" aria-controls="profileMenu" aria-label="Buka menu akun"><span class="top-avatar"><?= htmlspecialchars($student['initials'], ENT_QUOTES, 'UTF-8') ?></span><span class="top-user"><b><?= htmlspecialchars($student['name'], ENT_QUOTES, 'UTF-8') ?></b><small><?= $student['role'] === 'teacher' ? 'Guru Pendidikan Agama' : 'Pelajar · Kelas ' . htmlspecialchars($student['class'], ENT_QUOTES, 'UTF-8') ?></small></span><span class="chevron">&#8964;</span></button><div class="profile-menu" id="profileMenu" hidden><div class="profile-menu-heading"><?= htmlspecialchars($student['name'], ENT_QUOTES, 'UTF-8') ?></div><button type="button" class="profile-menu-item" data-page="pengaturan"><span>&#9881;</span> Pengaturan</button><form action="logout.php" method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"><button class="profile-menu-item profile-logout" type="submit"><span>&#8618;</span> Logout</button></form></div></div></div></header><section class="content" id="pageContent"></section></main></div>
<div class="toast" id="toast" role="status"></div><dialog id="lessonDialog"><form method="dialog" class="dialog-inner"><button type="button" class="dialog-close" id="dialogClose" aria-label="Tutup">&times;</button><div id="dialogContent"></div></form></dialog>
<script>window.LMS_DATA = <?= $lmsData ?>;</script><script src="app.js?v=20261104-quiz-editor-fix"></script>
</body></html>

