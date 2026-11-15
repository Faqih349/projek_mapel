<?php
declare(strict_types=1);

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
    $host = trim((string)(getenv('MUTQIN_SMTP_HOST') ?: ''));
    $portValue = getenv('MUTQIN_SMTP_PORT') ?: '587';
    $security = strtolower(trim((string)(getenv('MUTQIN_SMTP_SECURITY') ?: 'starttls')));
    $username = (string)(getenv('MUTQIN_SMTP_USER') ?: '');
    $password = (string)(getenv('MUTQIN_SMTP_PASSWORD') ?: '');
    $fromEmail = strtolower(trim((string)(getenv('MUTQIN_SMTP_FROM_EMAIL') ?: $username)));
    $fromName = trim((string)(getenv('MUTQIN_SMTP_FROM_NAME') ?: 'MutqinSpace'));
    $port = filter_var($portValue, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>65535]]);

    if ($host === '' || $port === false || !in_array($security, ['starttls', 'ssl'], true)
        || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || $fromName === ''
        || (($username === '') !== ($password === ''))) {
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

        if ($username !== '') {
            mutqinSmtpCommand($socket, 'AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password), ['235']);
        }
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
