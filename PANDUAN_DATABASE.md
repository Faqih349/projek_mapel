# Database MutqinSpace

MutqinSpace memakai MySQL/MariaDB untuk akun siswa dan guru, kelas, keanggotaan kelas, materi, tugas, kuis, pengumpulan, dan perpustakaan digital. File PDF, foto, dan video disimpan sebagai file di `storage/material_uploads`; MySQL menyimpan nama file, jenis file, ukuran, serta keterkaitannya. Pengumpulan dan riwayat revisinya tersimpan di tabel `submissions` dan `submission_history`. File di folder unggahan tidak dapat diakses langsung; aplikasi mengirimkannya lewat endpoint yang memeriksa sesi serta keanggotaan kelas.

## Menyiapkan database di XAMPP

1. Jalankan **Apache** dan **MySQL** dari XAMPP Control Panel.
2. Buka `http://localhost/phpmyadmin`.
3. Pilih **Import**, lalu impor file `database.sql`.
4. Dari PowerShell di folder proyek, jalankan migrasi awal:

   ```powershell
   C:\xampp\php\php.exe migrate_to_mysql.php
   ```

   Migrasi awal menyalin akun, materi, dan tugas/kuis lama dari file JSON ke database. Jalankan sekali saat menyiapkan database; jangan jalankan ulang setelah data dikelola di aplikasi karena file JSON sumber tidak ikut diperbarui.
5. Jika data pengumpulan lama masih ada di `storage/submissions.json`, jalankan migrasi pengumpulan satu kali:

   ```powershell
   C:\xampp\php\php.exe migrate_submissions.php
   ```

   Migrasi pengumpulan aman dijalankan ulang: data yang sudah ada di database tidak ditimpa. Untuk database lama, impor kembali `database.sql` terlebih dahulu agar tabel `submissions` dan `submission_history` dibuat. Perintah `CREATE TABLE IF NOT EXISTS` aman dijalankan ulang.

Siswa baru dapat memilih **Buat akun siswa** dari halaman masuk dan mengisi nama lengkap, email, NISN 10 digit, serta kata sandi minimal 8 karakter. Akun tersimpan sebagai siswa; saat masuk, siswa dapat menggunakan NISN, nama lengkap, atau email.

## Kelas dan kode undangan

Guru masuk ke **Kelas saya - Buat kelas** dan cukup memberi nama kelas. Aplikasi menyimpan kelas ke `class_groups` dan membuat kode undangan 8 karakter. Guru membagikan kode tersebut kepada siswa. Setelah login, siswa memasukkan kode pada panel **Gabung kelas** di beranda atau halaman Kelas saya; keanggotaannya tersimpan di `class_memberships` dan kelas tampil di **Kelas saya**. Guru melihat kelas yang dibuatnya, kode undangan, dan jumlah siswa yang bergabung.

## Pengaturan koneksi

Pengaturan lokal XAMPP ada di `db.php` (host `127.0.0.1`, port `3306`, database `mutqinspace`, pengguna `root`, kata sandi kosong). Di server lain, atur variabel lingkungan `MUTQIN_DB_HOST`, `MUTQIN_DB_PORT`, `MUTQIN_DB_NAME`, `MUTQIN_DB_USER`, dan `MUTQIN_DB_PASSWORD`.

## Notifikasi email tugas

Saat guru membuat tugas baru, aplikasi mengirim satu email kepada siswa yang sudah bergabung di kelas tujuan. Email siswa tidak saling terlihat. Pengiriman memerlukan akun SMTP; tanpa konfigurasi SMTP, tugas tetap tersimpan dan aplikasi menampilkan pemberitahuan bahwa email belum terkirim. Perubahan tugas yang sudah ada tidak mengirim email ulang.

### Menghubungkan Gmail

1. Pada akun Google pengirim, aktifkan **Verifikasi 2 Langkah**.
2. Buat App Password di [Google App Passwords](https://myaccount.google.com/apppasswords). Gunakan App Password, bukan kata sandi Gmail biasa.
3. Masuk sebagai guru, buka **Pengaturan akun**, lalu isi bagian **Notifikasi email tugas** dengan alamat Gmail dan App Password.
4. Tekan **Simpan dan uji Gmail**. Aplikasi mengirim email percobaan ke alamat Gmail tersebut; setelah berhasil, pengaturan tersimpan dan tidak perlu diisi lagi.

App Password disimpan di `storage/email_settings.json`. Folder `storage` diblokir dari akses langsung melalui web, dan file pengaturan ini diabaikan Git; namun App Password tidak dienkripsi di disk. Jangan membagikan atau memasukkan App Password ke repository. Jika menu App Passwords tidak tersedia, akun Google mungkin tidak memenuhi syarat; kata sandi Gmail biasa tidak dapat digunakan.

Akun demo yang ikut dimigrasikan:

- Siswa: `aulia` / `siswa123`
- Guru: `guru` / `admin123`

Data progres belajar masih disimpan di `storage/progress.json`. `storage/submissions.json` hanya menjadi sumber migrasi untuk pengumpulan lama.

## Pengumpulan dan penilaian

Guru dapat melihat siswa yang belum mengumpulkan, pengumpulan terlambat, dan tugas esai yang menunggu penilaian. Penilaian esai dan soal campuran dapat berisi nilai 0–100 serta umpan balik; hasilnya tampil kepada siswa. Siswa dapat memperbarui tugas esai sampai tenggat. Kuis dan soal campuran terkunci setelah dikirim. Setiap revisi esai menyimpan versi serta nilai/umpan balik sebelumnya.


## Perpustakaan digital

Guru dapat mengunggah buku paket untuk kelas 10, 11, atau 12 serta file untuk setiap juz di bagian Juz Amma. Siswa yang login dapat membuka file dari menu **Perpustakaan**. Metadata tersimpan pada tabel `library_items`; file masuk ke `storage/library_uploads` dan disajikan melalui endpoint yang memeriksa sesi. Format yang didukung PDF, JPG, PNG, WEBP, MP4, dan WEBM dengan batas 35 MB.
