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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $csrfToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals((string)$_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Sesi formulir berakhir. Muat ulang halaman lalu coba lagi.';
    } else {
        try {
            $db = mutqinDb();
            $userColumns = $db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
            $nisnColumn = in_array('nisn', $userColumns, true) ? 'nisn,' : '';
            $statement = $db->prepare("SELECT username,name,email,initials,class_name,{$nisnColumn}role,password_hash FROM users WHERE LOWER(username)=LOWER(:login) OR LOWER(email)=LOWER(:email) LIMIT 1");
            $statement->execute(['login'=>$username, 'email'=>$username]);
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
  <title>Masuk — MutqinSpace</title>
  <link rel="stylesheet" href="style.css?v=20261014-1">
</head>
<body class="login-page">
  <main class="login-shell">
    <section class="login-visual">
      <a class="brand login-brand" href="login.php"><span class="brand-mark">م</span><span><b>Mutqin<span class="brand-accent">Space</span></b><small>Belajar dengan makna</small></span></a>
      <div class="login-visual-copy"><span class="login-kicker">RUANG BELAJAR PENDIDIKAN AGAMA</span><h1>Ilmu yang bertumbuh,<br>akhlak yang berbuah.</h1><p>Belajar, memahami, dan mengamalkan kebaikan setiap hari.</p></div>
      <div class="login-pattern" aria-hidden="true"><span>۞</span><i></i><b>✦</b></div>
    </section>
    <section class="login-form-side">
      <form class="login-form" method="post" action="login.php">
        <div class="login-form-heading"><span class="eyebrow">SELAMAT DATANG KEMBALI</span><h2>Masuk ke akunmu</h2><p>Gunakan akun yang diberikan oleh sekolahmu.</p></div>
        <?php if ($error !== ''): ?><div class="login-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <label for="username">Nama pengguna atau email</label>
        <input id="username" name="username" type="text" autocomplete="username" placeholder="Masukkan nama pengguna atau email" value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" required>
        <label for="password">Kata sandi</label>
        <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Masukkan kata sandi" required>
        <button class="login-submit" type="submit">Masuk <span>→</span></button>
        <details class="demo-credentials"><summary>Lihat akun demo</summary><div class="demo-account"><b>Pelajar</b><span><code>aulia</code> · <code>siswa123</code></span></div><div class="demo-account"><b>Guru</b><span><code>guru</code> · <code>admin123</code></span></div></details>
        <p class="login-footnote">Belum memiliki akun? Hubungi administrator sekolah.</p>
      </form>
    </section>
  </main>
</body>
</html>
