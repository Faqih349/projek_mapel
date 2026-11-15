<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/db.php';

if (!empty($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$username = '';
$fullName = '';
$email = '';
$nisn = '';
$mode = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string)($_POST['mode'] ?? 'login')
    : (string)($_GET['mode'] ?? 'login');
if (!in_array($mode, ['login', 'register'], true)) $mode = 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)$_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Sesi formulir berakhir. Muat ulang halaman lalu coba lagi.';
    } elseif ($mode === 'register') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $nisn = trim((string)($_POST['nisn'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $nameLength = preg_match_all('/./us', $fullName);

        if ($fullName === '' || $nameLength === false || $nameLength > 150) {
            $error = 'Nama lengkap wajib diisi dan maksimal 150 karakter.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $error = 'Masukkan alamat email yang valid.';
        } elseif (!preg_match('/^\d{10}$/', $nisn)) {
            $error = 'NISN harus terdiri dari 10 angka.';
        } elseif (strlen($password) < 8) {
            $error = 'Kata sandi harus terdiri dari minimal 8 karakter.';
        } else {
            try {
                $db = mutqinDb();
                $duplicate = $db->prepare('SELECT id FROM users WHERE LOWER(email)=LOWER(:email) OR nisn=:nisn OR username=:username LIMIT 1');
                $duplicate->execute(['email'=>$email, 'nisn'=>$nisn, 'username'=>$nisn]);
                if ($duplicate->fetch()) {
                    $error = 'Email atau NISN sudah terdaftar. Silakan masuk atau gunakan data lain.';
                } else {
                    $nameParts = preg_split('/\s+/', $fullName) ?: [$fullName];
                    $firstName = $nameParts[0];
                    $lastName = $nameParts[count($nameParts) - 1];
                    $initials = strtoupper(substr($firstName, 0, 1) . (count($nameParts) > 1 ? substr($lastName, 0, 1) : ''));
                    $statement = $db->prepare('INSERT INTO users (username,name,email,initials,class_name,nisn,role,password_hash) VALUES (:username,:name,:email,:initials,:class_name,:nisn,:role,:password_hash)');
                    $statement->execute([
                        'username'=>$nisn,
                        'name'=>$fullName,
                        'email'=>$email,
                        'initials'=>$initials,
                        'class_name'=>'',
                        'nisn'=>$nisn,
                        'role'=>'student',
                        'password_hash'=>password_hash($password, PASSWORD_DEFAULT),
                    ]);

                    session_regenerate_id(true);
                    $_SESSION['user'] = [
                        'username'=>$nisn,
                        'name'=>$fullName,
                        'email'=>$email,
                        'initials'=>$initials,
                        'class'=>'',
                        'nisn'=>$nisn,
                        'role'=>'student',
                    ];
                    header('Location: index.php');
                    exit;
                }
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $error = 'Email atau NISN sudah terdaftar. Silakan masuk atau gunakan data lain.';
                } else {
                    error_log('MutqinSpace registration database error: ' . $exception->getMessage());
                    $error = 'Pendaftaran belum dapat diproses. Pastikan MySQL dan database MutqinSpace sudah siap.';
                }
            }
        }
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        try {
            $db = mutqinDb();
            $userColumns = $db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
            $hasNisnColumn = in_array('nisn', $userColumns, true);
            $nisnColumn = $hasNisnColumn ? 'nisn,' : '';
            $nisnSearch = $hasNisnColumn ? ' OR nisn=:nisn' : '';
            $statement = $db->prepare("SELECT username,name,email,initials,class_name,{$nisnColumn}role,password_hash FROM users WHERE LOWER(username)=LOWER(:login) OR LOWER(email)=LOWER(:email){$nisnSearch} LIMIT 1");
            $loginValues = ['login'=>$username, 'email'=>$username];
            if ($hasNisnColumn) $loginValues['nisn'] = $username;
            $statement->execute($loginValues);
            $account = $statement->fetch();
        } catch (PDOException $exception) {
            error_log('MutqinSpace login database error: ' . $exception->getMessage());
            $account = false;
            $error = 'Tidak dapat terhubung ke MySQL. Jalankan MySQL di XAMPP dan pastikan database mutqinspace sudah tersedia.';
        }

        if ($account && password_verify($password, (string)($account['password_hash'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'username' => (string)($account['username'] ?? ''),
                'name' => (string)($account['name'] ?? ''),
                'email' => $account['email'] ?? '',
                'initials' => (string)($account['initials'] ?? ''),
                'class' => (string)($account['class_name'] ?? ''),
                'nisn' => (string)($account['nisn'] ?? ''),
                'role' => (string)($account['role'] ?? 'student'),
            ];
            header('Location: index.php');
            exit;
        }

        if ($error === '') $error = 'Nama pengguna atau kata sandi belum sesuai.';
    }
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f4f8f4">
  <title><?= $mode === 'register' ? 'Buat Akun' : 'Masuk' ?> — MutqinSpace</title>
  <link rel="stylesheet" href="style.css?v=20261014-1">
</head>
<body class="login-page">
  <main class="login-shell">
    <section class="login-visual">
      <a class="brand login-brand" href="login.php"><img class="brand-mark brand-logo" src="mutqinspace-logo.png" alt="Logo MutqinSpace"><span><b>Mutqin<span class="brand-accent">Space</span></b><small>Belajar dengan makna</small></span></a>
      <div class="login-visual-copy"><span class="login-kicker">RUANG BELAJAR PENDIDIKAN AGAMA</span><h1>Ilmu yang bertumbuh,<br>akhlak yang berbuah.</h1><p>Belajar, memahami, dan mengamalkan kebaikan setiap hari.</p></div>
      <div class="login-pattern" aria-hidden="true"><span>۞</span><i></i><b>✦</b></div>
    </section>
    <section class="login-form-side">
      <form class="login-form" method="post" action="login.php">
        <div class="login-form-heading"><span class="eyebrow"><?= $mode === 'register' ? 'MULAI BELAJAR BERSAMA' : 'SELAMAT DATANG KEMBALI' ?></span><h2><?= $mode === 'register' ? 'Buat akun siswa' : 'Masuk ke akunmu' ?></h2><p><?= $mode === 'register' ? 'Isi data diri untuk membuat akun baru.' : 'Gunakan akun yang sudah terdaftar.' ?></p></div>
        <?php if ($error !== ''): ?><div class="login-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($mode === 'register'): ?>
          <label for="full_name">Nama lengkap</label>
          <input id="full_name" name="full_name" type="text" autocomplete="name" maxlength="150" placeholder="Masukkan nama lengkap" value="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>" required>
          <label for="email">Email</label>
          <input id="email" name="email" type="email" autocomplete="email" maxlength="190" placeholder="nama@email.com" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" required>
          <label for="nisn">NISN</label>
          <input id="nisn" name="nisn" type="text" inputmode="numeric" pattern="[0-9]{10}" maxlength="10" placeholder="Masukkan 10 digit NISN" value="<?= htmlspecialchars($nisn, ENT_QUOTES, 'UTF-8') ?>" required>
          <label for="password">Kata sandi</label>
          <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" placeholder="Minimal 8 karakter" required>
          <button class="login-submit" type="submit">Buat akun <span>→</span></button>
          <p class="login-footnote">Sudah memiliki akun? <a href="login.php">Masuk di sini</a>.</p>
        <?php else: ?>
          <label for="username">Nama pengguna, email, atau NISN</label>
          <input id="username" name="username" type="text" autocomplete="username" placeholder="Masukkan nama pengguna, email, atau NISN" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" required>
          <label for="password">Kata sandi</label>
          <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Masukkan kata sandi" required>
          <button class="login-submit" type="submit">Masuk <span>→</span></button>
          <details class="demo-credentials"><summary>Lihat akun demo</summary><div class="demo-account"><b>Pelajar</b><span><code>aulia</code> · <code>siswa123</code></span></div><div class="demo-account"><b>Guru</b><span><code>guru</code> · <code>admin123</code></span></div></details>
          <p class="login-footnote">Belum memiliki akun? <a href="login.php?mode=register">Buat akun siswa</a>.</p>
        <?php endif; ?>
      </form>
    </section>
  </main>
</body>
</html>
