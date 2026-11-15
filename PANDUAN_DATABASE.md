# Database MutqinSpace

MutqinSpace memakai MySQL/MariaDB untuk akun siswa dan guru, kelas, keanggotaan kelas, materi, tugas, kuis, dan perpustakaan digital. File PDF, foto, dan video disimpan sebagai file di `storage/material_uploads`; MySQL menyimpan nama file, jenis file, ukuran, serta keterkaitannya dengan materi atau tugas. Lampiran foto/video jawaban siswa juga disimpan di folder tersebut, sedangkan catatan pengumpulan berada di `storage/submissions.json`. File di folder unggahan tidak dapat diakses langsung; aplikasi mengirimkannya lewat endpoint yang memeriksa sesi pengguna.

## Menyiapkan database di XAMPP

1. Jalankan **Apache** dan **MySQL** dari XAMPP Control Panel.
2. Buka `http://localhost/phpmyadmin`.
3. Pilih **Import**, lalu impor file `database.sql`.
4. Dari PowerShell di folder proyek, jalankan:

   ```powershell
   C:\xampp\php\php.exe migrate_to_mysql.php
   ```

   Migrasi awal menyalin akun, materi, dan tugas/kuis lama dari file JSON ke database. Jalankan sekali saat menyiapkan database; jangan jalankan ulang setelah data dikelola di aplikasi karena file JSON sumber tidak ikut diperbarui. Jika database sebelumnya sudah dibuat, impor `database.sql` lagi setelah pembaruan untuk menambahkan tabel kelas dan `library_items` (perintah `CREATE TABLE IF NOT EXISTS` aman dijalankan ulang). Untuk mengaktifkan profil siswa yang menyimpan NISN, buka tab **SQL** di phpMyAdmin lalu jalankan sekali: `ALTER TABLE users ADD COLUMN nisn VARCHAR(30) NOT NULL DEFAULT '' AFTER class_name;`. Skema baru dari `database.sql` sudah menyertakan kolom ini. Kolom `exam_mode` untuk ujian mode fokus dibuat otomatis oleh `assignment_manage.php` ketika guru membuka pengelolaan tugas; skema baru juga sudah menyertakannya.

Siswa baru dapat memilih **Buat akun siswa** dari halaman masuk dan mengisi nama lengkap, email, NISN 10 digit, serta kata sandi minimal 8 karakter. Akun tersimpan sebagai siswa, dan NISN dapat digunakan sebagai nama pengguna saat masuk.

## Kelas dan kode undangan

Guru masuk ke **Kelas saya - Buat kelas** dan cukup memberi nama kelas. Aplikasi menyimpan kelas ke `class_groups` dan membuat kode undangan 8 karakter. Guru membagikan kode tersebut kepada siswa. Setelah login, siswa memasukkan kode pada panel **Gabung kelas** di beranda atau halaman Kelas saya; keanggotaannya tersimpan di `class_memberships` dan kelas tampil di **Kelas saya**. Guru melihat kelas yang dibuatnya, kode undangan, dan jumlah siswa yang bergabung.

## Pengaturan koneksi

Pengaturan lokal XAMPP ada di `db.php` (host `127.0.0.1`, port `3306`, database `mutqinspace`, pengguna `root`, kata sandi kosong). Di server lain, atur variabel lingkungan `MUTQIN_DB_HOST`, `MUTQIN_DB_PORT`, `MUTQIN_DB_NAME`, `MUTQIN_DB_USER`, dan `MUTQIN_DB_PASSWORD`.

## Notifikasi email tugas

Saat guru membuat tugas baru, aplikasi mengirim satu email kepada siswa yang sudah bergabung di kelas tujuan. Email siswa tidak saling terlihat. Pengiriman memerlukan akun SMTP; tanpa konfigurasi SMTP, tugas tetap tersimpan dan aplikasi menampilkan pemberitahuan bahwa email belum terkirim. Perubahan tugas yang sudah ada tidak mengirim email ulang.

Atur variabel lingkungan berikut pada server Apache/PHP, lalu mulai ulang Apache. Untuk Gmail, gunakan `smtp.gmail.com`, port `587`, keamanan `starttls`, dan App Password (bukan kata sandi akun biasa).

- `MUTQIN_SMTP_HOST`
- `MUTQIN_SMTP_PORT` (umumnya `587`)
- `MUTQIN_SMTP_SECURITY` (`starttls` atau `ssl`)
- `MUTQIN_SMTP_USER`
- `MUTQIN_SMTP_PASSWORD`
- `MUTQIN_SMTP_FROM_EMAIL` (alamat pengirim; boleh sama dengan akun SMTP)
- `MUTQIN_SMTP_FROM_NAME` (opsional; default `MutqinSpace`)

Simpan kredensial SMTP hanya pada konfigurasi environment server, jangan di dalam kode atau repository.

Akun demo yang ikut dimigrasikan:

- Siswa: `aulia` / `siswa123`
- Guru: `guru` / `admin123`

Data progres dan jawaban siswa masih disimpan di `storage/progress.json` dan `storage/submissions.json`.


## Perpustakaan digital

Guru dapat mengunggah buku paket untuk kelas 10, 11, atau 12 serta file untuk setiap juz di bagian Juz Amma. Siswa yang login dapat membuka file dari menu **Perpustakaan**. Metadata tersimpan pada tabel `library_items`; file masuk ke `storage/library_uploads` dan disajikan melalui endpoint yang memeriksa sesi. Format yang didukung PDF, JPG, PNG, WEBP, MP4, dan WEBM dengan batas 35 MB.
