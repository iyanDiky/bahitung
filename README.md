# bahitung starter — macOS local
**Contains:** Vue 3 frontend terhubung ke Laravel 13 REST API dan PostgreSQL 17 melalui Docker Compose. Alur akun, Dashboard, Transaksi, Dompet, Budget, Target, Analisis, Anggota, dan Pengaturan Keluarga tersedia untuk pengujian lokal. Data contoh terpisah di `/pratinjau`.

## Requirements
- VS Code + Codex, Docker Desktop
- Node.js 20.19+ or 22.12+ (check `node -v`), npm
- Backend: PHP 8.4+ dengan pdo_pgsql, Composer. Wrapper `backend/bin/php` memilih PHP 8.4 Homebrew tanpa mengubah PHP global.

## 1. Open project
Extract ZIP, open **bahitung-starter** directory in VS Code. Keep `AGENTS.md` and `docs/` available to Codex. UI sources in `design/`.

## 2. Start database
From project root:
```bash
docker compose up -d
# optional:
docker compose ps
```
Connection in pgAdmin (create server registration): host `localhost`, port `5433`, database `bahitung`, username `bahitung`, password `bahitung_local_only` (development only). pgAdmin is a GUI, not a database server; Docker provides PostgreSQL here. If host port 5433 is occupied, change left side of `5433:5432` and matching backend DB_PORT.

## 3. Run frontend
```bash
cd frontend
npm install
npm run dev
```
Open http://localhost:5173/login. `npm run build` validates Vue/TS build. Seluruh halaman inti, termasuk Budget, Target, Analisis, Anggota dan Pengaturan, terhubung ke Laravel; jalankan backend terlebih dahulu. Setelah login pengguna masuk `/dashboard`; tanpa keluarga diarahkan ke `/keluarga` untuk membuat keluarga. Tombol **Buka Dashboard** tersedia di ruang keluarga. Data contoh tetap tersedia mulai `/pratinjau` (dompet/transaksi contoh di `/pratinjau/dompet` dan `/pratinjau/transaksi`), dengan perubahan dalam memori yang hilang setelah reload.

## 4. Run Laravel backend
Laravel sudah tersedia di `backend/`. Untuk setup pertama pada checkout baru:
```bash
cd backend
./bin/composer install
# Hanya jika .env belum ada:
cp .env.example .env
./bin/php artisan key:generate
./bin/php artisan migrate
```
Database harus berjalan terlebih dahulu. Untuk menjalankan API:
```bash
cd backend # dari root proyek
./bin/composer dev
```
Health check: http://127.0.0.1:8000/api/v1/health. Respons 200 berarti PostgreSQL dapat diakses; 503 berarti tidak tersedia. `/up` adalah liveness Laravel.

Panduan lengkap termasuk database pengujian tersedia di [backend/README.md](backend/README.md).
Autentikasi Bearer Sanctum, keluarga dan otorisasi dasar sudah tersedia. Verifikasi/reset akun, undangan, pengelolaan anggota dan transfer Owner juga tersedia pada API. Layar akun/undangan dan ruang keluarga sudah terintegrasi memakai session Sanctum + CSRF. Vite mem-proxy request API; CORS menerima credentials dari origin lokal port 5173. API dompet, kategori, income/expense/transfer + biaya admin, koreksi dan pembatalan telah terhubung ke halaman keuangan. Dashboard merangkum saldo dompet dan transaksi aktif bulan keluarga; saldo awal bukan pemasukan dan hanya fee transfer dihitung sebagai pengeluaran. Backend Budget bulanan (buat/edit/salin dan laporan pemakaian) serta warning transaksi sudah tersedia. Halaman `/budget` telah terhubung ke API, termasuk buat/edit/salin per bulan, pemakaian kategori, pengeluaran tanpa budget, dan peringatan transaksi. Contoh Budget tetap tersedia di `/pratinjau/budget`. Backend Target tabungan (goals) sudah tersedia: metadata/arsip, setoran, penarikan, riwayat, koreksi/void serta rekonsiliasi saldo. Halaman `/target` sudah terhubung: buat/edit/arsip, setor/tarik, riwayat, koreksi dan pembatalan. Dashboard menampilkan total aset wallet + goals; contoh Target tersedia di `/pratinjau/target`. API laporan Analisis (`/reports/cashflow`) sudah tersedia untuk ringkasan pemasukan/pengeluaran, tren harian/bulanan, kategori dan biaya admin dengan filter tanggal/dompet. Halaman `/analisis` sudah terhubung ke API dengan filter tanggal/dompet, grafik dan tabel harian/bulanan, serta rincian kategori pemasukan/pengeluaran. Contoh Analisis tetap tersedia di `/pratinjau/analisis`.

## 5. Status dan tahap berikutnya

- `/anggota`: daftar/pencarian/filter, undang/cabut, perubahan peran/status dengan alasan sesuai hak akses.
- `/pengaturan`: nama/zona waktu khusus Owner dan transfer kepemilikan dengan konfirmasi; Owner lama menjadi Admin.
- Contoh desain tersedia di `/pratinjau/anggota` dan `/pratinjau/pengaturan`.
- Email lokal masih `MAIL_MAILER=log`. Undangan/verifikasi/reset belum dikirim ke inbox nyata.
- Sebelum penggunaan online: konfigurasi dan uji email nyata, uji backup/restore pada database terpisah berikut rekonsiliasi hasil restore, lalu konfigurasi hosting/domain/HTTPS dan pemeriksaan deployment.
- Status pemeriksaan beserta cakupannya dicatat di `docs/PROGRESS.md`. Tes lokal yang lulus belum menggantikan pemeriksaan lingkungan produksi.

## Troubleshooting
- Docker Desktop must be running for `docker compose`.
- `5433` intentionally avoids common local PostgreSQL port `5432`.
- If npm fails due to unsupported Node, upgrade Node.
- macOS Apple Silicon works with postgres official multi-arch Docker image.
- For deploy, **change secrets**, configure production domains/HTTPS, backups, logging, and email before any real financial data.


## Backup dan latihan restore lokal

Jalankan dari root `bahitung-starter` dengan Docker/PostgreSQL aktif dan Python 3:

```bash
python3 backend/bin/backup-drill.py
```

Alat ini khusus database lokal `bahitung` pada service Compose `postgres`:

1. Membuka snapshot PostgreSQL read-only, membuat `pg_dump` custom, serta menyimpan fingerprint seluruh tabel dari snapshot yang sama. Aplikasi tetap dapat berjalan.
2. Membuat database baru bernama `bahitung_restore_drill_<waktu>_<acak>`. Database yang sudah ada tidak ditimpa.
3. Memulihkan dump dengan satu transaksi, membandingkan fingerprint 26 tabel (jumlah tabel mengikuti skema), lalu menjalankan `wallets:reconcile` dan `goals:reconcile` terhadap hasil restore.
4. Memeriksa trigger aktif dan penolakan UPDATE pada tabel append-only yang berisi data. Pemeriksaan mutasi hanya dijalankan pada salinan restore.
5. Menghapus database sementara setelah seluruh pemeriksaan lulus. Jika pemeriksaan gagal, database salinan tetap tersedia untuk investigasi dan proses keluar dengan error.

Hasil berada di `backend/storage/app/private/backups/<waktu>_<acak>/`:
`database.dump`, checksum SHA-256, fingerprint snapshot/restored, serta `report.json`.
Direktori baru menggunakan izin 700 dan file 600; backup dikecualikan dari Git.
Untuk memeriksa file setelah disalin, jalankan `shasum -a 256 -c database.dump.sha256`
dari direktori backup tersebut. Backup memuat data akun/keuangan; izin file bukan enkripsi.

Latihan ini membuat backup baru setiap kali dijalankan, tidak menimpa database utama,
dan tidak melakukan rotasi backup. Ini belum merupakan backup otomatis/off-device.
Dump mencakup database, bukan `.env`, `APP_KEY`, file unggahan, atau konfigurasi layanan;
komponen tersebut perlu disimpan terpisah secara aman untuk pemulihan produksi lengkap.
Laravel config cache harus tidak aktif saat latihan agar DB tujuan tidak tertimpa oleh
konfigurasi cache. Jangan menjalankan migrasi/skema bersamaan dengan latihan restore.

## Menyiapkan email SMTP

Alur verifikasi, undangan, dan reset sudah memakai mailer Laravel. Untuk mengirim
ke inbox nyata, lengkapi `backend/.env` secara lokal berdasarkan layanan yang dipilih:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=<host-dari-layanan>
MAIL_PORT=587
MAIL_USERNAME=<username-dari-layanan>
MAIL_PASSWORD=<password-SMTP-atau-app-password>
MAIL_FROM_ADDRESS=<alamat-pengirim-yang-diizinkan>
MAIL_FROM_NAME=bahitung
MAIL_TIMEOUT=15
MAIL_REQUIRE_TLS=true
```

Gunakan skema/port yang diberikan layanan: `smtp` mendukung STARTTLS, sedangkan
`smtps` memakai TLS sejak koneksi dibuka. Jangan mengisi kredensial di `.env.example`
atau chat. Jika `MAIL_URL` terisi, kosongkan saat memakai konfigurasi terpisah ini.
Sesudah mengubah `.env`, bersihkan config cache dan restart backend bila diperlukan:

```bash
cd backend
./bin/php artisan config:clear
./bin/php artisan mail:check
# Hanya setelah alamat penerima uji disepakati:
./bin/php artisan mail:check --send-to=alamat-penerima
```

`mail:check` tanpa opsi hanya memeriksa konfigurasi, bukan koneksi/kredensial SMTP.
Opsi `--send-to` mengirim satu email uji tanpa token akun; keberhasilan SMTP berarti
server menerima pesan, bukan jaminan inbox. Periksa inbox/spam sebelum melanjutkan
uji daftar/verifikasi, undangan, dan reset dengan akun milik penguji.

`APP_URL` dan `FRONTEND_URL` harus sesuai alamat aplikasi. URL localhost hanya dapat
dibuka dari komputer yang menjalankan aplikasi; uji dari perangkat lain memerlukan
alamat yang dapat dijangkau. Untuk produksi, gunakan URL HTTPS/domain aplikasi.
Log lokal tetap digunakan sampai layanan dan kredensial sudah disiapkan. Pengiriman
masih sinkron; antrean email dengan retry belum diaktifkan pada tahap ini.
