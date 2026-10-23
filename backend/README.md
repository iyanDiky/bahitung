# bahitung backend

Fondasi REST API Laravel 13, PHP 8.4+, dan PostgreSQL 17. Vue berada di `../frontend`; tidak perlu menjalankan npm di folder backend.

## Menjalankan lokal

Dari root `bahitung-starter`:
```sh
docker compose up -d postgres
cd backend
./bin/composer install
```
Pada checkout baru, jika `.env` belum ada:
```sh
cp .env.example .env
./bin/php artisan key:generate
```
`.env.example` menggunakan PostgreSQL `127.0.0.1:5433`, database/user `bahitung`. Cocokkan `DB_PASSWORD` dengan password Compose jika diubah.
```sh
./bin/php artisan migrate --no-interaction
./bin/composer dev
```
API: http://127.0.0.1:8000. Wrapper `bin/php` memilih PHP Homebrew 8.4; jika tidak tersedia memakai PHP di PATH. Override dengan `BAHITUNG_PHP=/path/to/php`. Wrapper `bin/composer` menggunakan runtime yang sama, tanpa mengubah PHP global.

## Endpoint dan struktur

- `GET /api/v1/health`: cek PostgreSQL melalui query nyata; 200 dengan `data.status=ok`, atau 503 dengan `data.status=unavailable`. Tidak mengekspos detail exception/kredensial.
- `GET /up`: liveness framework.
- `GET /`: identitas API dan URL health.

Alur health: Controller → HealthService → DatabaseHealthRepository → PostgreSQL. Binding ada di AppServiceProvider. API versi 1 menggunakan respons JSON, termasuk 404 tanpa header Accept.

CORS menerima `http://localhost:5173` dan `http://127.0.0.1:5173`; ubah lewat `CORS_ALLOWED_ORIGINS`. Credentials diaktifkan hanya untuk origin yang diizinkan. Session/cache memakai file, queue sync, email log untuk fondasi lokal. Zona waktu koneksi database UTC; timestamps pengguna memakai tipe timezone-aware.

## Pengujian

Buat database terpisah sekali saja, dari root proyek:
```sh
docker compose exec -T postgres createdb -U bahitung bahitung_testing
```
Lalu dari `backend/`:
```sh
DB_DATABASE=bahitung_testing ./bin/php artisan migrate --no-interaction
./bin/composer test
./bin/php vendor/bin/pint --test
./bin/composer validate --strict
```
Jika database tes sudah ada, lewati `createdb`. PHPUnit mengunci koneksi PostgreSQL lokal dan nama database `bahitung_testing`; user/password berasal dari `.env`. Tes memeriksa koneksi nyata, hasil migrasi, health sukses/gagal, JSON 404 dan CORS. Tidak memakai `migrate:fresh` pada database aplikasi.

## Autentikasi dan keluarga (tahap 3d)

Sanctum 4 menyediakan Bearer token 24 jam; gunakan `Authorization: Bearer <token>`.
Register/login/logout, verifikasi email, reset password, `/me`, buat/list/detail/edit
keluarga, daftar/pengaturan anggota, undangan dan transfer Owner sudah tersedia. Detail payload di [API_CONTRACT.md](../docs/API_CONTRACT.md).
Akun tidak otomatis menjadi anggota keluarga contoh di frontend.

Creator keluarga menjadi Owner secara atomik. Policy memeriksa membership aktif
pada setiap request, dan PATCH pengaturan hanya untuk Owner. Role pada satu
keluarga tidak memberi akses ke keluarga lain. Daftar anggota menyembunyikan
email/kredensial. Audit pembuatan/perubahan disimpan atomik dengan trigger
PostgreSQL yang mencegah perubahan/penghapusan barisnya.

Migrasi tambahan dapat diterapkan lewat `./bin/php artisan migrate`. Database
pengujian juga perlu dimigrasi sebelum tes sebagaimana petunjuk di atas.
Tes memakai transaksi yang di-rollback di `bahitung_testing`, tidak mengisi data
contoh atau kredensial pada database aplikasi. Untuk membersihkan token yang
kedaluwarsa: `./bin/php artisan sanctum:prune-expired --hours=24`.

Login dibatasi per email/IP; register dibatasi per IP. Browser memakai session
Sanctum + CSRF dengan credentials dan origin lokal yang diizinkan. Token Bearer
tetap untuk client API; tidak disimpan ke browser/localStorage.

## Email dan keanggotaan

Email lokal memakai `MAIL_MAILER=log`, isi ada di `storage/logs/laravel.log`.
Tidak ada email nyata yang dikirim oleh setup ini. Register mengirim verifikasi;
resend dan forgot-password memiliki rate limit. Verifikasi membutuhkan session atau Bearer akun yang sama. Email sekarang
membuka halaman Vue `/verifikasi-email` yang meneruskan signed URL ke API. Reset password menggunakan broker
Laravel dengan token 60 menit, sekali pakai, dan mencabut seluruh token login.

Undangan Member/Viewer berlaku 7 hari dan harus diterima oleh email terverifikasi
yang cocok. Secret hanya berada di notifikasi; API dan audit tidak mengembalikannya.
Admin tidak dapat mempromosikan Admin atau mengubah Admin/Owner. Owner tidak dapat
dihapus/didemot melalui PATCH anggota. Transfer ke anggota aktif berlangsung atomik,
dan Owner lama menjadi Admin. Anggota removed tetap disimpan untuk histori.

Semua mutasi keluarga mengunci baris household sebelum mengubah anggota/undangan.
Notifikasi undangan dan registrasi diproses setelah commit. Jika pengiriman mailer
gagal setelah commit, data tetap tersimpan: verifikasi dapat dikirim ulang; undangan
pending dapat dicabut lalu dibuat ulang. Delivery retry queue belum disiapkan.
Jangan membagikan log lokal karena berisi tautan/token email pengujian.

Panduan payload lengkap: [API_CONTRACT.md](../docs/API_CONTRACT.md).

## Frontend dan session (tahap 3d)

Buka http://localhost:5173/login. Route `/` menuju ruang keluarga `/keluarga`;
pengguna tanpa session diarahkan ke login. `/daftar`, `/lupa-password`,
`/reset-password`, `/verifikasi-email`, dan `/undangan` sudah tersedia.
Ruang keluarga memakai database: membuat/memilih keluarga, daftar anggota,
serta kirim/list/cabut undangan sesuai hak akses. Data keluarga contoh tidak
masuk akun baru. Tampilan keuangan lama tetap tersedia mulai `/pratinjau`.

Vite mem-proxy `/api` dan `/sanctum` ke localhost:8000, sehingga cookie dan
XSRF dibaca pada origin frontend yang sama. Pakai hostname konsisten dengan
FRONTEND_URL (default http://localhost:5173), terutama ketika mengikuti email.
Endpoint browser `/api/v1/session/register`, `/session/login`, `/session/logout`
memakai cookie HttpOnly; login meregenerasi session dan tidak membuat token
Bearer. Password reset membatalkan session lama pada request berikutnya melalui
AuthenticateSession. Browser mengambil `/sanctum/csrf-cookie` dan menyertakan
header X-XSRF-TOKEN untuk mutasi. Laravel 13 juga memvalidasi Sec-Fetch-Site;
request same-origin sah, fallback lintas origin memerlukan token CSRF valid.

Build statis membutuhkan reverse proxy `/api` dan `/sanctum` ke Laravel;
Vite proxy hanya bekerja pada dev server. Tidak ada deployment pada tahap ini.

## Uji browser terisolasi

Database browser terpisah agar fixture tidak mengganggu PHPUnit. Dari root:
```sh
docker compose exec -T postgres createdb -U bahitung bahitung_browser_testing
cd backend
DB_DATABASE=bahitung_browser_testing ./bin/php artisan migrate --no-interaction
DB_DATABASE=bahitung_browser_testing APP_URL=http://127.0.0.1:8001 FRONTEND_URL=http://127.0.0.1:5174 SANCTUM_STATEFUL_DOMAINS=127.0.0.1:5174 SESSION_COOKIE=bahitung_browser_test ./bin/php artisan serve --host=127.0.0.1 --port=8001
```
Lewati createdb jika sudah ada. Terminal lain dari frontend:
```sh
BAHITUNG_API_TARGET=http://127.0.0.1:8001 npm run dev -- --port 5174
```
Tes `node frontend/tests/auth-browser.cjs` dari root memakai Playwright lokal
`/tmp/bahitung-ui-tools/node_modules/playwright`; override dengan BAHITUNG_PLAYWRIGHT
ke instalasi Playwright yang sudah tersedia. Fixture hanya ada di database browser.
Skrip membaca tautan dari log mail lokal, jangan jalankan dengan mailer produksi.

## Dompet dan ledger (tahap 4a)

API dompet sudah tersedia: create/list/detail/edit/arsip/aktifkan kembali dan
riwayat movements. POST wajib header `Idempotency-Key` UUID; opening_amount
berupa string dua desimal, optional default nol. Detail di API_CONTRACT.md.
Owner/Admin dapat menulis, semua anggota aktif dapat membaca dompet keluarga.

Opening positif disimpan sebagai operation opening, bukan income. Dompet, saldo,
ledger, audit dan idempotency receipt berada dalam satu transaksi. Key yang sama
akan replay respons tersimpan; payload berbeda ditolak. Concurrent creation diuji
dengan dua proses PostgreSQL nyata. Mutation lock household mengoordinasi membership
serta penulisan dompet; opening mengunci proyeksi saldo. Ledger dan receipt append-only
untuk UPDATE/DELETE; composite FK mencegah relasi lintas household.

Setelah update source, migrasikan database aplikasi dan database tes:
```sh
./bin/php artisan migrate --no-interaction
DB_DATABASE=bahitung_testing ./bin/php artisan migrate --no-interaction
./bin/php artisan test --compact
./bin/php artisan wallets:reconcile
# Optional membatasi pemeriksaan:
./bin/php artisan wallets:reconcile --household=1
```
Reconcile tidak mengubah data; exit 1 berarti proyeksi hilang/tidak sesuai ledger.
Tes race membuat schema unik sementara di bahitung_testing lalu menghapus hanya
schema milik tes tersebut. Database aplikasi tidak dipakai dalam pengujian.

## Batas tahap ini

Frontend keuangan masih data contoh dan belum tersambung ke API dompet. Tahap
berikutnya adalah income/expense/transfer + fee, lalu integrasi dompet/transaksi.
Koreksi/reversal, kategori, goal ledger, budget dan laporan belum tersedia.
UI role/status/transfer Owner dan pengaturan keluarga yang lama belum terhubung
ke API; endpoint backendnya tersedia. Email tetap log lokal.

### Pengujian frontend keuangan

Dengan server browser terisolasi di atas berjalan, jalankan dari root:
```bash
node frontend/tests/finance-browser.cjs
```
Tes membuat akun/keluarga khusus pada bahitung_browser_testing dan memeriksa login
ke Dashboard, wallet/kategori, income/expense/transfer+fee, koreksi/void, filter/paging,
pergantian keluarga, kontrol Viewer, presisi nominal besar, serta respons hilang
setelah commit lalu retry setelah reload tanpa transaksi ganda. Screenshot di
/tmp/bahitung-finance-{desktop,mobile,transfer-mobile}.png. Database aplikasi
bahitung tidak digunakan untuk fixture. Pilihan keluarga/snapshot request sementara
ada di sessionStorage; cookie session tetap satu-satunya autentikasi browser.
