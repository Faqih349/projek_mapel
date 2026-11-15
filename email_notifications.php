<?php
declare(strict_types=1);

function mutqinSmtpSettings(): array
{
    $settings = [
        'host'=>'smtp.gmail.com',
        'port'=>'587',
        'security'=>'starttls',
        'username'=>'',
        'password'=>'',
        'fromEmail'=>'',
        'fromName'=>'MutqinSpace',
    ];
    $settingsPath = __DIR__ . '/storage/email_settings.json';

    if (is_file($settingsPath)) {
        $contents = file_get_contents($settingsPath);
        $stored = $contents === false ? null : json_decode($contents, true);
        if (!is_array($stored)) throw new RuntimeException('Pengaturan email tersimpan tidak dapat dibaca.');
        foreach (array_keys($settings) as $key) {
            if (isset($stored[$key]) && is_scalar($stored[$key])) $settings[$key] = (string)$stored[$key];
        }
    } else {
        $settings['host'] = trim((string)(getenv('MUTQIN_SMTP_HOST') ?: $settings['host']));
        $settings['port'] = (string)(getenv('MUTQIN_SMTP_PORT') ?: $settings['port']);
        $settings['security'] = strtolower(trim((string)(getenv('MUTQIN_SMTP_SECURITY') ?: $settings['security'])));
        $settings['username'] = trim((string)(getenv('MUTQIN_SMTP_USER') ?: ''));
        $settings['password'] = preg_replace('/\s+/', '', (string)(getenv('MUTQIN_SMTP_PASSWORD') ?: '')) ?? '';
        $settings['fromEmail'] = strtolower(trim((string)(getenv('MUTQIN_SMTP_FROM_EMAIL') ?: $settings['username'])));
        $settings['fromName'] = trim((string)(getenv('MUTQIN_SMTP_FROM_NAME') ?: $settings['fromName']));
    }

    $settings['password'] = preg_replace('/\s+/', '', (string)$settings['password']) ?? '';
    return $settings;
}

function mutqinSmtpIsConfigured(): bool
{
    $settings = mutqinSmtpSettings();
    return trim((string)$settings['username']) !== ''
        && trim((string)$settings['password']) !== ''
        && filter_var((string)$settings['username'], FILTER_VALIDATE_EMAIL) !== false;
}

function mutqinSaveSmtpSettings(string $email, string $appPassword): void
{
    $currentSettings = mutqinSmtpSettings();
    $appPassword = preg_replace('/\s+/', '', $appPassword) ?? '';
    if ($appPassword === '') $appPassword = (string)$currentSettings['password'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $appPassword === '') {
        throw new InvalidArgumentException('Alamat Gmail dan App Password wajib diisi.');
    }

    $settings = [
        'host'=>'smtp.gmail.com',
        'port'=>'587',
        'security'=>'starttls',
        'username'=>$email,
        'password'=>$appPassword,
        'fromEmail'=>$email,
        'fromName'=>'MutqinSpace',
    ];
    $encoded = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($encoded === false || file_put_contents(__DIR__ . '/storage/email_settings.json', $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Pengaturan Gmail belum dapat disimpan di folder storage.');
    }
}

function mutqinSmtpWrite($socket, string $data): void
{
    $offset = 0;
    $length = strlen($data);
    while ($offset < $length) {
        $written = fwrite($socket, substr($data, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Tidak dapat mengirim data ke server email.');
        }
        $offset += $written;
    }
}

function mutqinSmtpReadResponse($socket, array $expectedCodes): string
{
    do {
        $line = fgets($socket, 515);
        if ($line === false || !preg_match('/^(\d{3})([ -])/', $line, $matches)) {
            throw new RuntimeException('Respons server email tidak valid.');
        }
        $code = $matches[1];
        $hasMoreLines = $matches[2] === '-';
    } while ($hasMoreLines);

    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('Server email menolak permintaan (kode ' . $code . ').');
    }
    return $code;
}

function mutqinSmtpCommand($socket, string $command, array $expectedCodes): string
{
    mutqinSmtpWrite($socket, $command . "\r\n");
    return mutqinSmtpReadResponse($socket, $expectedCodes);
}

function mutqinSendTaskNotification(array $recipients, array $assignment, string $className, string $teacherName): int
{
    $settings = mutqinSmtpSettings();
    $host = trim((string)$settings['host']);
    $security = strtolower(trim((string)$settings['security']));
    $username = trim((string)$settings['username']);
    $password = (string)$settings['password'];
    $fromEmail = strtolower(trim((string)($settings['fromEmail'] ?: $username)));
    $fromName = trim((string)$settings['fromName']);
    $port = filter_var($settings['port'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>65535]]);

    if ($host === '' || $port === false || !in_array($security, ['starttls', 'ssl'], true)
        || !filter_var($username, FILTER_VALIDATE_EMAIL) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)
        || $password === '' || $fromName === '') {
        throw new RuntimeException('Konfigurasi SMTP belum lengkap atau tidak valid.');
    }
    if (!preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
        throw new RuntimeException('Nama host SMTP tidak valid.');
    }
    if ($username !== '' && (str_contains($username, "\r") || str_contains($username, "\n"))) {
        throw new RuntimeException('Nama pengguna SMTP tidak valid.');
    }

    $validRecipients = [];
    foreach ($recipients as $recipient) {
        $email = strtolower(trim((string)$recipient));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) $validRecipients[$email] = true;
    }
    if (!$validRecipients) return 0;

    $socketHost = ($security === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $context = stream_context_create(['ssl'=>[
        'verify_peer'=>true,
        'verify_peer_name'=>true,
        'peer_name'=>$host,
    ]]);
    $socket = @stream_socket_client($socketHost, $errorCode, $errorMessage, 10, STREAM_CLIENT_CONNECT, $context);
    if ($socket === false) {
        throw new RuntimeException('Tidak dapat terhubung ke server email (kode ' . $errorCode . ').');
    }

    stream_set_timeout($socket, 10);
    try {
        mutqinSmtpReadResponse($socket, ['220']);
        $helo = preg_replace('/[^A-Za-z0-9.-]/', '-', gethostname() ?: 'localhost');
        mutqinSmtpCommand($socket, 'EHLO ' . ($helo ?: 'localhost'), ['250']);

        if ($security === 'starttls') {
            mutqinSmtpCommand($socket, 'STARTTLS', ['220']);
            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new RuntimeException('Koneksi TLS ke server email gagal.');
            }
            mutqinSmtpCommand($socket, 'EHLO ' . ($helo ?: 'localhost'), ['250']);
        }

        mutqinSmtpCommand($socket, 'AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password), ['235']);
        mutqinSmtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', ['250']);
        foreach (array_keys($validRecipients) as $recipient) {
            mutqinSmtpCommand($socket, 'RCPT TO:<' . $recipient . '>', ['250', '251']);
        }
        mutqinSmtpCommand($socket, 'DATA', ['354']);

        $due = (string)($assignment['due'] ?? '');
        $dueDate = DateTime::createFromFormat('!Y-m-d\TH:i', $due, new DateTimeZone('Asia/Jakarta'));
        if ($dueDate) $due = $dueDate->format('d M Y, H:i') . ' WIB';
        $type = ($assignment['type'] ?? '') === 'quiz' ? 'Kuis' : 'Tugas';
        $body = implode("\r\n", [
            'Halo,',
            '',
            'Ada ' . $type . ' baru untuk kelas ' . $className . ' di MutqinSpace.',
            '',
            'Judul: ' . (string)($assignment['title'] ?? ''),
            'Mata pelajaran: ' . (string)($assignment['courseTitle'] ?? ''),
            'Tenggat: ' . $due,
            'Deskripsi: ' . (string)($assignment['description'] ?? ''),
            '',
            'Silakan masuk ke MutqinSpace untuk melihat petunjuk dan mengerjakan tugas.',
            'Dibuat oleh: ' . $teacherName,
        ]);
        $safeFromName = str_replace(["\r", "\n"], ' ', $fromName);
        $encodedFromName = '=?UTF-8?B?' . base64_encode($safeFromName) . '?=';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($type . ' baru: ' . (string)($assignment['title'] ?? '')) . '?=';
        $headers = implode("\r\n", [
            'To: undisclosed-recipients:;',
            'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
            'Subject: ' . $encodedSubject,
            'Date: ' . date(DATE_RFC2822),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($body), 76, "\r\n"),
        ]);
        $headers = preg_replace('/(?m)^\./', '..', $headers) ?? $headers;
        mutqinSmtpWrite($socket, $headers . ".\r\n");
        mutqinSmtpReadResponse($socket, ['250']);
        mutqinSmtpWrite($socket, "QUIT\r\n");
        return count($validRecipients);
    } finally {
        fclose($socket);
    }
}
