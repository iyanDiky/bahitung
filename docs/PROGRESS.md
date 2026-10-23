# Progres implementasi bahitung

## Tahap 1 — Dashboard mock (15 Oktober 2026)

Implementasi awal selesai; menunggu review tampilan pengguna sebelum tahap 2,
sesuai urutan `CODEX_FIRST_PROMPT.md` dan `AGENTS.md`.

Perubahan:
- Dashboard dipecah ke AppHeader, SummaryCard, WalletList,
  BudgetProgressList, GoalsList, RecentTransactions, ExpenseChart,
  dan DashboardState; AppSidebar tetap komponen terpisah.
- Layout ringkasan menyatu, aksen lime, jarak panel, dan rincian pengeluaran
  mengikuti referensi dashboard. Avatar memakai inisial dan gambar target
  memakai emoji; belum identik dengan foto pada desain.
- Drawer mobile: backdrop, Escape, fokus keyboard, scroll lock,
  dan otomatis menutup saat pindah ke desktop.
- Pilihan tampilan demo berisi data/loading/kosong/error dan tombol pemulihan.
- Shortcut mengarah ke halaman placeholder tahap berikutnya.
- Mock bertipe eksplisit, tanpa endpoint atau autentikasi buatan.
- Angka mock diselaraskan: dompet Rp13.120.000 + target Rp11.430.000
  = aset Rp24.550.000. Angka ini sengaja berbeda dari gambar referensi
  yang ringkasan dan rinciannya tidak konsisten.

Validasi:
- `npm install` berhasil; audit melaporkan 0 vulnerabilities saat instalasi.
- `npm run build` berhasil (Vue typecheck dan Vite production build).
- Preview lokal tersedia dengan `npm run dev`.
- Belum dilakukan pengujian interaksi browser atau perbandingan screenshot otomatis.

Review manual:
1. Buka Dashboard pada desktop dan lebar mobile 360px.
2. Ubah pilihan Tampilan demo, lalu coba tombol pemulihan.
3. Di mobile, buka menu; periksa Tab, Shift+Tab, Escape, backdrop,
   dan navigasi ke halaman placeholder.
4. Periksa nominal ringkasan, dompet, target, serta rincian pengeluaran.

File yang diubah/ditambahkan:
- `frontend/src/App.vue`
- `frontend/src/components/AppSidebar.vue`
- `frontend/src/components/AppHeader.vue`
- `frontend/src/components/SummaryCard.vue`
- `frontend/src/components/WalletList.vue`
- `frontend/src/components/BudgetProgressList.vue`
- `frontend/src/components/GoalsList.vue`
- `frontend/src/components/RecentTransactions.vue`
- `frontend/src/components/ExpenseChart.vue`
- `frontend/src/components/DashboardState.vue`
- `frontend/src/views/DashboardView.vue`
- `frontend/src/data/mock.ts`
- `frontend/src/style.css`
- `frontend/package-lock.json`
- `docs/PROGRESS.md`

## Berikutnya

2. Setelah Dashboard diterima: halaman Transaksi dan dialog transfer + biaya
   admin; dilanjutkan Budget/Target dan Anggota menggunakan mock.
3. Bootstrap Laravel, autentikasi, household, membership.
4. Ledger, dompet, transaksi beserta tes aturan finansial.
5. Budget, goals, analitik, integrasi.

Backend dan PostgreSQL belum dijalankan pada tahap UI ini.

## Revisi review Dashboard — aksen biru dan tampilan bersih

- Aksen merek diganti menjadi biru elektrik `#2563EB` / `#3B82F6`,
  dengan permukaan biru muda dan teks putih pada tombol utama/menu aktif.
- Logo, navigasi, tautan, progress, diagram, dan fokus keyboard mengikuti warna baru.
- Warna hijau pada nominal pemasukan/tren positif tetap menjadi indikator finansial.
- Label Mode demo/Keluarga demo, kontrol pratinjau, dan catatan teknis di Dashboard dihapus.
- Data contoh tetap tersedia untuk review; belum ada API atau data finansial nyata.
- Komponen loading/empty/error tetap disimpan untuk integrasi berikutnya.
- File revisi: style.css, AppHeader.vue, AppSidebar.vue, BudgetProgressList.vue,
  DashboardView.vue, PlaceholderView.vue, DECISIONS.md, dan PROGRESS.md.

## Tahap 2a — Transaksi dan dialog transfer (15 Oktober 2026)

Selesai untuk review UI:
- Halaman `/transaksi`: ringkasan pemasukan/pengeluaran/pokok transfer,
  filter tanggal, jenis, dompet asal/tujuan, kategori, anggota, pencarian,
  pagination, serta kondisi hasil kosong dan tanggal terbalik.
- Dialog transfer responsif dengan dua dompet, nominal, biaya admin,
  kategori biaya, tanggal, catatan, dan ringkasan saldo setelah transfer.
- Validasi nominal positif, format desimal, saldo termasuk biaya, dompet
  berbeda, kategori expense untuk biaya, serta tanggal kalender yang valid.
- Nominal menggunakan string desimal dan BigInt minor units, bukan float.
- Pencatatan sementara menambah pokok transfer dan linked expense untuk biaya;
  ringkasan pengeluaran menghitung biaya tanpa menghitung pokok transfer.
- Modal native mendukung Escape, fokus, backdrop, Batal, dan scroll lock.
- Shortcut Transfer dari Dashboard langsung membuka dialog.

Batas tahap ini: data Transaksi bersifat in-memory dan kembali ke fixture saat
reload. Saldo serta transaksi Dashboard masih fixture tahap pertama dan belum
tersinkron dengan perubahan di halaman Transaksi. Form income/expense,
edit/void, ledger, otorisasi server, dan integrasi API masuk tahap berikutnya;
belum ada perpindahan uang nyata atau penyimpanan database.

Validasi:
- Vue/TypeScript + Vite production build berhasil.
- Tes nominal dan mock transfer berhasil: desimal presisi, format invalid,
  saldo tidak cukup, validasi tanpa perubahan parsial, transfer dengan/tanpa
  biaya, dan pengurangan total saldo tepat sebesar biaya.
- Browser Chromium: filter dompet/kategori/anggota/jenis, pencarian kosong,
  pagination, transfer beserta linked fee, ringkasan pengeluaran, error saldo,
  Batal, Escape, shortcut Dashboard; tidak ada runtime page error.
- Layout desktop 1440px dan mobile 360px diperiksa; tidak ada horizontal
  overflow halaman/dialog. Tabel mobile dapat digulir horizontal.

Jalankan ulang tes domain dari `frontend/`:
```sh
./node_modules/.bin/esbuild tests/transfer.test.ts --bundle --platform=node --outfile=/tmp/bahitung-transfer-test.cjs
node /tmp/bahitung-transfer-test.cjs
npm run build
```

File ditambah: `frontend/src/views/TransactionsView.vue`,
`frontend/src/components/TransferDialog.vue`,
`frontend/src/data/transactionMock.ts`, `frontend/src/utils/money.ts`,
`frontend/tests/transfer.test.ts`.
File diubah: router/index.ts, AppHeader.vue, DashboardView.vue, style.css,
dan dokumen progres ini.

Berikutnya setelah review Transaksi: slicing Budget/Target dan Anggota.

## Tahap 2b — Budget dan Target (15 Oktober 2026)

Selesai untuk review UI:
- `/budget`: ringkasan bulan, rincian kategori, progress, warning 80%/100%/>100%,
  tambah/edit budget, pengeluaran tanpa budget, serta salin bulan sebelumnya.
- Penyalinan hanya melalui tombol eksplisit, tujuan belum boleh memiliki budget;
  hasil salinan independen dari sumber. Pilih November 2026 untuk mencoba
  menyalin fixture Oktober 2026. Bulan baru tidak otomatis dibuat.
- `/target`: kartu target, filter dalam proses/tercapai, buat/edit target,
  detail, setor dari dompet, tarik ke dompet, dan aktivitas sesi.
- Budget mengambil pengeluaran dari data Transaksi yang sama, termasuk biaya
  transfer. Budget terlampaui memberi warning, tidak menolak transaksi.
- Setoran/penarikan memakai saldo dompet yang sama dengan dialog Transfer.
  Dana target terpisah dari saldo tersedia, total aset tetap, dan pergerakan
  target tidak menjadi income/expense.
- Nominal string desimal dan BigInt; validasi dilakukan sebelum mutasi fixture.
- Dialog native mendukung fokus, Escape, Batal, backdrop, dan scroll lock.
- Shortcut Tambah Target dari Dashboard langsung membuka dialog buat target.

Validasi:
- `npm run build` berhasil (Vue/TypeScript + Vite).
- `tests/planning.test.ts`: copy independen, duplicate guard, transisi tahun,
  nominal invalid, warning threshold, linked transfer fee, deposit/withdraw
  presisi, aset/cashflow tetap, dan input gagal tanpa mutasi parsial.
- Chromium desktop 1440px/mobile 360px: edit/tambah budget, input nol, copy
  bulanan dan penolakan duplikat, buat/setor/tarik/detail/filter target,
  error saldo, Escape/Batal, shortcut Dashboard; tanpa runtime page error.
- Screenshot desktop/mobile ditinjau; tidak ada horizontal overflow halaman
  atau dialog pada pengujian 360px.

Jalankan ulang tes dari `frontend/`:
```sh
./node_modules/.bin/esbuild tests/planning.test.ts --bundle --platform=node --outfile=/tmp/bahitung-planning-test.cjs
node /tmp/bahitung-planning-test.cjs
npm run build
```

File baru:
- `frontend/src/data/planningMock.ts`
- `frontend/src/views/PlanningView.vue`
- `frontend/src/components/AppDialog.vue`
- `frontend/src/components/BudgetEditor.vue`
- `frontend/src/components/GoalDialog.vue`
- `frontend/src/components/SavingGoalCard.vue`
- `frontend/tests/planning.test.ts`

File diubah: `router/index.ts`, `AppHeader.vue`, `DashboardView.vue`,
`style.css`, dan `docs/PROGRESS.md`.

Batas tahap UI: fixture konteks Owner, bukan autentikasi/otorisasi produksi.
Data tersimpan di memori sesi dan kembali semula saat reload. Dashboard masih
fixture tahap pertama. Data contoh awal tidak merupakan ledger historis;
validasi histori, idempotency, RBAC, dan penyimpanan PostgreSQL menunggu backend.

Berikutnya setelah review Budget/Target: halaman Anggota.

## Tahap 2c — Anggota dan Pengaturan Keluarga (15 Oktober 2026)

Budget/Target diterima pengguna; tahap berikutnya diimplementasikan mengikuti
`design/anggota.png` dengan tema biru yang telah disetujui.

Selesai untuk review UI:
- `/anggota`: profil keluarga, jumlah anggota aktif, pencarian nama/email/peran,
  tabel peran/status/tanggal bergabung, dan dialog perubahan anggota.
- `/pengaturan`: informasi keluarga dan zona waktu dengan form yang sama;
  mata uang IDR dan awal budget tanggal 1 tetap sesuai keputusan MVP.
- Undangan lokal Member/Viewer: validasi email, normalisasi, penolakan email
  anggota/undangan aktif duplikat, masa berlaku 7 hari, dan pencabutan.
  Tidak ada email, token undangan produksi, atau request jaringan.
- Peran contoh mengikuti aturan household: Owner dapat mengatur Admin;
  Admin hanya mengelola Member/Viewer; anggota nonaktif tetap tersimpan.
- Transfer kepemilikan dengan pilihan anggota aktif dan konfirmasi nama
  keluarga. Owner lama menjadi Admin, tepat satu Owner aktif tetap terjaga,
  dan tombol/form khusus Owner langsung tidak tersedia bagi aktor lama.
- Nama keluarga pada header dan anggota aktif pada Dashboard memakai fixture
  household yang sama. Shortcut Undang Anggota memfokuskan form undangan.
- Penjelasan peran dikoreksi mengikuti aturan bisnis. Hapus permanen pada
  gambar diganti dengan Arsip Keluarga yang belum aktif sebelum backend.
- Avatar menggunakan inisial. Foto, notifikasi nyata, arsip nyata, dan layanan
  email belum diimplementasikan; UI tidak menyatakan fitur itu sudah bekerja.

Validasi:
- Vue/TypeScript + Vite production build berhasil.
- `tests/household.test.ts`: email/duplikat/expiry/revoke, satu Owner aktif,
  transfer Owner, larangan perubahan Admin lain, larangan eskalasi peran,
  pembatasan Viewer, scope household, dan anggota nonaktif tetap tersimpan.
- Chromium desktop 1440px dan mobile 360px: pencarian/empty state, undangan
  lokal/duplikat/cabut, perubahan role/status, pengaturan/header sinkron,
  konfirmasi kepemilikan, pembatasan Admin setelah transfer, route Pengaturan,
  Escape/Batal, dialog mobile, dan shortcut Dashboard; tanpa page error.
- Screenshot desktop/mobile diperiksa. Tabel bergulir di dalam panel;
  halaman dan dialog tidak overflow horizontal pada lebar 360px.

Jalankan ulang tes dari `frontend/`:
```sh
./node_modules/.bin/esbuild tests/household.test.ts --bundle --platform=node --outfile=/tmp/bahitung-household-test.cjs
node /tmp/bahitung-household-test.cjs
npm run build
```

File baru: `frontend/src/data/householdMock.ts`,
`frontend/src/components/HouseholdSettings.vue`,
`frontend/src/components/MemberDialog.vue`,
`frontend/src/views/MembersView.vue`, `frontend/tests/household.test.ts`.
File diubah: `router/index.ts`, `AppHeader.vue`, `DashboardView.vue`,
`style.css`, dan dokumen progres ini.

Batas implementasi: seluruh perubahan merupakan fixture sesi browser dan
kembali ke awal setelah reload. Pembatasan peran hanya untuk perilaku UI;
bukan autentikasi atau keamanan produksi. Backend tetap harus menerapkan
Policies/Service, undangan terverifikasi, audit, dan isolasi household.

Tahap desain utama Dashboard, Transaksi, Budget/Target, Anggota/Pengaturan
sudah tersedia untuk review. Setelah diterima, prioritas AGENTS berikutnya
adalah bootstrap Laravel dan auth/household membership. Dompet dan Analisis
masih halaman placeholder untuk integrasi keuangan pada tahap lanjutan.

## Tahap 2d — Dompet dan Analisis (15 Oktober 2026)

Atas permintaan pengguna, kedua halaman dilengkapi sebelum tahap backend.
Semua menu utama kini memiliki halaman UI; Dompet/Analisis bukan placeholder.

Selesai:
- `/dompet`: saldo aktif/arsip, aset keluarga, pencarian/filter status,
  tambah/edit dompet, saldo awal, arsip/aktifkan kembali, dan mutasi.
- Dompet bersama memakai sumber saldo yang sama dengan Transaksi/Target.
  Dompet arsip tetap menyimpan saldo/histori, termasuk aset dan laporan,
  tetapi dikeluarkan dari pilihan transfer/setor/tarik dan ditolak oleh mock
  mutation helper jika dipaksakan melalui ID.
- Saldo awal dicatat terpisah dari income; edit metadata tidak mengubah saldo.
  Mutasi mencakup pokok transfer, linked fee, saldo awal, dan goal movements.
- Tombol Transfer pada kartu dompet langsung memilih dompet sumber tersebut.
- `/analisis`: bulan/dompet, income, expense, surplus/defisit, biaya transfer,
  grafik harian, tabel angka harian aksesibel, proporsi kategori, dan ringkasan.
- Pokok transfer, opening, dan goal movement tidak dihitung sebagai cashflow.
  Hanya linked transfer expense masuk pengeluaran dan rincian biaya admin.
- Saldo tersedia pada Target hanya mencakup dompet aktif; total aset tetap
  menyertakan saldo dompet arsip. Nominal dihitung dengan BigInt/decimal string.

Validasi:
- `npm run build` berhasil.
- `tests/wallets-analytics.test.ts` lulus: validasi dompet, create/edit/opening,
  mutasi, guard arsip, laporan arsip tetap ada, agregasi harian/kategori,
  periode/wallet, fee-only expense, goal exclusion, dan role pengelola.
- Tes Transfer dan Planning sebelumnya dijalankan ulang dan lulus.
- Chromium 1440px/360px: create/edit/riwayat/arsip, filter pencarian,
  dompet arsip tidak tersedia di transfer, pemilihan sumber dari kartu,
  integrasi fee ke laporan, filter bulan/dompet, empty state, tabel harian,
  dialog Batal/Escape, dan pemeriksaan overflow; tanpa page error.
- Screenshot desktop/mobile diperiksa. Grafik dan tabel lebar dapat digulir
  dalam panel pada mobile; halaman tidak melebar horizontal.

Tes dari `frontend/`:
```sh
./node_modules/.bin/esbuild tests/wallets-analytics.test.ts --bundle --platform=node --outfile=/tmp/bahitung-wallets-test.cjs
node /tmp/bahitung-wallets-test.cjs
npm run build
```

File baru: `data/walletMock.ts`, `data/analytics.ts`,
`components/WalletDialog.vue`, `views/WalletsView.vue`,
`views/AnalyticsView.vue` di `frontend/src/`, serta
`frontend/tests/wallets-analytics.test.ts`.
File diubah: data/transactionMock.ts, data/planningMock.ts,
components/TransferDialog.vue, components/GoalDialog.vue,
components/AppHeader.vue, views/PlanningView.vue, views/TransactionsView.vue,
views/DashboardView.vue, router/index.ts, style.css, dan dokumen ini.

Batas: semua perubahan masih sesi in-memory, hilang saat reload. Snapshot
awal saldo dan transaksi bukan ledger historis lengkap; UI mutasi menyatakan
keterbatasan ini. Dashboard keuangan masih fixture awal, belum disinkronkan.
Belum ada PostgreSQL, audit/reversal server, atau autentikasi produksi.

## Tahap 3a — Fondasi backend (16 Oktober 2026)

Selesai:
- Laravel 13.36.0 terpasang di `backend/`, Composer lockfile tersedia.
- PHP 8.4.13 dipakai melalui `bin/php` dan `bin/composer`, tanpa mengganti
  PHP global terminal. Laravel Boost tersedia sebagai dependency development.
- PostgreSQL 17 Compose berjalan sehat pada localhost:5433. Database aplikasi
  `bahitung` dan database tes terpisah `bahitung_testing` sudah dimigrasi.
- Migrasi standar Laravel users/cache/jobs; timestamp pengguna timezone-aware.
- `/api/v1/health` memeriksa PostgreSQL melalui Controller → Service →
  Repository interface/implementation. Respons 200 saat tersedia, 503 saat
  gagal tanpa detail exception. `/up` untuk liveness framework.
- JSON untuk route API, CORS origin frontend lokal port 5173, konfigurasi
  environment lokal, APP_KEY, timezone koneksi UTC, dan panduan menjalankan.

Validasi:
- PHPUnit: 9 tes, 24 assertion lulus. Cakupan: koneksi PostgreSQL nyata,
  database tes terisolasi, tabel hasil migrasi, UTC, health sukses/gagal,
  CORS origin diizinkan/ditolak, serta JSON 404.
- Pint lulus; Composer validate --strict dan check-platform-reqs lulus.
- Composer melaporkan tidak ada security vulnerability advisories.
- Server lokal http://127.0.0.1:8000: `/api/v1/health` dan `/up` HTTP 200;
  health melaporkan database `ok`.

Batas: belum ada autentikasi/Sanctum, household/membership/policy, ledger,
atau endpoint keuangan. UI tetap data contoh dalam memori. Tahap berikutnya:
autentikasi dan akses keluarga, lalu integrasi frontend bertahap.
Panduan: `backend/README.md`; instruksi bootstrap awal diarsipkan dalam
`docs/BACKEND_BOOTSTRAP_ORIGINAL.md`.

## Tahap 3b — Autentikasi dan akses keluarga dasar (16 Oktober 2026)

Selesai:
- Laravel Sanctum 4.3.3: register/login, token Bearer 24 jam, logout yang
  mencabut token saat ini, profil `/me` beserta membership aktif.
- Normalisasi email, hashing password, konfirmasi password, batas percobaan
  login/register, dan error JSON konsisten tanpa membocorkan kredensial.
- Create/list/detail keluarga, PATCH nama/timezone khusus Owner, list anggota.
  Pemilihan keluarga melalui ID eksplisit di URL; role selalu per household.
- Controller → FormRequest → Service → Repository interface/implementation;
  HouseholdPolicy dipanggil dari service, binding melalui container.
- Tabel households/household_members/audit_logs dan personal_access_tokens.
  Create household + Owner + audit atomik; unique partial index menolak
  Owner aktif kedua. Tidak ada endpoint yang menghapus/menurunkan Owner.
- Membership suspended/removed dan keluarga arsip/asing ditolak 404.
- Audit before/after untuk perubahan keluarga; trigger PostgreSQL menolak
  UPDATE/DELETE baris audit. Tidak menyimpan token/password dalam audit.
- Migrasi diterapkan ke database aplikasi dan database tes terpisah.

Validasi:
- Seluruh 53 tes lulus, 197 assertion. Termasuk autentikasi nyata dengan token,
  token expired/invalid/logout, limit login, validasi payload, matriks Policy,
  isolasi keluarga, pencabutan membership langsung, larangan mass-assignment,
  batas pagination, rollback ketika audit gagal, satu Owner aktif, audit immutable,
  dan regresi health/CORS.
- Pint seluruh backend lulus; opsi --dirty tidak tersedia karena folder belum Git.
- Composer validate --strict lulus; instalasi Sanctum tanpa advisory keamanan.
- HTTP aktual `/api/v1/me` tanpa Accept/token memberi JSON 401; health tetap 200.

Batas tahap ini:
- Frontend tetap data contoh, belum ada layar login yang terhubung backend.
- Bearer token untuk API; session Sanctum + CSRF untuk Vue belum diaktifkan.
- Reset password, verifikasi email, undangan, ubah role/status, transfer ownership
  belum diimplementasikan. Berikutnya lengkapi alur akun/keanggotaan tersebut,
  kemudian integrasi frontend dan API keuangan sesuai urutan proyek.
- Tidak membuat akun/demo household pada database aplikasi.

Panduan payload dan endpoint: `docs/API_CONTRACT.md`; cara menjalankan/test:
`backend/README.md`. Implementasi di `backend/app`, `backend/routes/api.php`,
`backend/bootstrap/app.php`, `backend/config/sanctum.php`, serta migration,
factory, seeder opt-in, dan `tests/Feature/{AuthTest,HouseholdTest}.php`.

## Tahap 3c — Pemulihan akun dan pengelolaan keanggotaan (16 Oktober 2026)

Selesai pada backend:
- Verifikasi email Laravel melalui notifikasi register/resend, signed URL 60 menit,
  pemeriksaan akun/hash yang cocok, dan throttling.
- Forgot/reset password memakai broker Laravel, respons forgot generik, token
  sekali pakai 60 menit, password terkonfirmasi dan pencabutan semua token login.
  Login/reset mengunci user untuk menyelaraskan perubahan password dan token.
- Undangan Member/Viewer: email dinormalisasi, secret hash-only, TTL 7 hari,
  daftar berpaginasi, pencabutan, serta accept khusus email verified yang cocok.
  Token tidak ada di response API atau audit. Notification register/invite setelah commit.
- PATCH role/status dengan reason wajib. Admin hanya mengelola Member/Viewer;
  Owner dapat mengelola Admin tetapi tidak dapat menghapus/demot Owner lewat PATCH.
  Anggota suspended/removed tetap tersimpan dan dapat dipulihkan oleh pengelola.
- Transfer Owner atomik ke anggota aktif, Owner lama menjadi Admin; tepat satu
  Owner aktif setelah commit. Semua mutasi anggota mengunci household sebelum
  mengubah data, memakai audit atomik yang append-only.
- Controller/FormRequest/Service/Repository dan Policy, tanpa dependency tambahan.

Validasi:
- 54 tes fitur baru lulus; seluruh backend 107 tes, 353 assertion lulus.
- Mencakup signed URL invalid/expired/akun lain, token reset salah/expired/replay,
  generic forgot response, pencabutan token login, throttling, invite secret/hash,
  penerima tidak verified/salah email, revoked/expired/replay, konflik membership,
  policy matrix role, akses lintas household, target Owner invalid, soft removal,
  pemulihan anggota, larangan ubah identitas, serta rollback audit/transfer/accept.
- Pint seluruh backend dan Composer validate --strict lulus. --dirty tidak dapat
  dipakai karena workspace belum berupa repository Git.
- Migration undangan diterapkan ke database testing dan database aplikasi lokal.

Batas:
- Email lokal masih MAIL_MAILER=log, bukan SMTP. Notification delivery retry queue
  belum tersedia; kegagalan kirim setelah commit tidak membatalkan data tersimpan.
- UI Vue tetap data contoh. Link reset/undangan mengarah ke frontend URL yang
  halamannya belum dibuat; verifikasi via API membutuhkan Bearer akun yang cocok.
- Tahap berikutnya: integrasi layar akun/undangan dan session Sanctum + CSRF di Vue,
  lalu lanjut API keuangan sesuai rancangan. Belum ada ledger/wallet persistence.

Dokumentasi diperbarui: `docs/API_CONTRACT.md`, `backend/README.md`, `README.md`.
Tes utama: `backend/tests/Feature/AccountRecoveryTest.php` dan
`backend/tests/Feature/MembershipManagementTest.php`.

## Tahap 3d — Vue akun/keluarga dan session Sanctum (16 Oktober 2026)

Selesai:
- UI biru bahitung untuk login, register, lupa/reset password, verifikasi email,
  dan penerimaan undangan. Redirect lanjutan dibatasi ke route lokal yang dikenal.
- Session cookie Sanctum, regenerate session saat login, logout invalidate,
  XSRF cookie/header untuk request mutasi; tidak ada token auth dalam localStorage.
  API Bearer lama tetap tersedia. Password reset menolak session lama pada request
  berikutnya melalui password hash session yang disiapkan sejak login.
- Ruang `/keluarga`: buat/pilih keluarga, daftar anggota, kirim/list/cabut undangan
  menurut role. Cache daftar dibersihkan saat pindah keluarga; respons lama tidak
  menimpa hasil keluarga yang baru. Data diambil dari backend, bukan fixture.
- `/` menuju ruang keluarga; belum login menuju `/login`. Halaman keuangan lama
  tetap ada mulai `/pratinjau`, terpisah dari data akun keluarga asli.
- Signed email link membuka Vue verifikasi; tautan undangan diteruskan setelah
  register/login. Pengguna unverified diarahkan mengonfirmasi email terlebih dahulu.
- Vite proxy `/api` dan `/sanctum`, CORS credentials dengan origin allowlist,
  penanganan error validasi/koneksi/401/419 dan tombol proses untuk cegah submit ganda.

Validasi:
- Build Vue/TypeScript lulus; Pint dan Composer validate --strict lulus.
- Seluruh backend: 112 tes, 377 assertion lulus.
- Chromium E2E dua akun: register, reload session, verifikasi email, create keluarga,
  invite/accept setelah verifikasi, reset password, login ulang, logout dan 401
  setelah logout. Request lintas situs tanpa XSRF ditolak 419. Laravel 13 menerima
  Sec-Fetch-Site same-origin; pengujian disesuaikan dengan mekanisme framework.
- Desktop 1440px dan mobile 360px diperiksa dari screenshot; tanpa page error
  dan tanpa overflow horizontal. localStorage tidak berisi data autentikasi.
- Tes regresi transfer frontend lulus.
- Browser memakai `bahitung_browser_testing` pada server port 8001/5174;
  fixture tidak mencemari database aplikasi maupun database PHPUnit.

Batas:
- Email masih log lokal. Panduan proxy, URL email dan tes ada di backend/README.md.
- Data keuangan lama tetap fixture; ledger/dompet/transaksi belum terhubung backend.
- Form role/status/transfer Owner dan pengaturan keluarga lama belum terhubung ke
  ruang keluarga baru; endpoint backendnya tetap tersedia untuk integrasi lanjutan.

File utama: frontend/src/views/AccountView.vue, FamilyAccountView.vue,
services/api.ts, stores/auth.ts, account.css, router/index.ts, App.vue;
frontend/tests/auth-browser.cjs; backend/tests/Feature/SessionAuthTest.php;
konfigurasi Sanctum/CORS, AuthController/AuthService dan link notifikasi.

## Tahap 4a — API dompet dan fondasi ledger (16 Oktober 2026)

Selesai:
- Create/list/detail/edit/arsip/aktifkan kembali dompet, riwayat movements.
  Semua data scoped household dan membership aktif; write Owner/Admin saja.
- Migration wallets, wallet_balances, operations, balance_movements, serta
  idempotency_receipts dengan FK komposit untuk mencegah relasi lintas keluarga.
- Opening amount decimal string tepat dua digit, nonnegatif, batas numeric(19,2).
  Tidak memakai float. Opening positif merupakan operation opening, bukan income;
  opening nol tanpa operation. Tanggal default memakai timezone household.
- Wallet + projection + ledger + audit + receipt atomik. Key UUID per household
  wajib untuk create; replay respons original atau 409 IDEMPOTENCY_CONFLICT.
- Lock household dan proyeksi, retry deadlock terbatas. Trigger menolak update/delete
  ledger dan receipt; constraint menolak proyeksi negatif dan movement nol.
- Arsip mempertahankan saldo dan histori. PATCH tidak menerima perubahan saldo.
- Command read-only wallets:reconcile memeriksa seluruh saldo terhadap SUM ledger,
  termasuk wallet arsip/proyeksi hilang, tanpa auto-repair.

Validasi:
- Seluruh backend: 155 tes lulus, 559 assertion.
- Cakupan baru: opening >2^53 dan nominal maksimum tetap presisi, zero opening,
  format nominal salah, tanggal/zona waktu, protected fields, policy matrix,
  isolasi household, anggota removed, archive/re-activate, pagination/filter,
  rollback lengkap jika audit gagal, ledger immutable, composite FK, saldo negatif,
  dan deteksi selisih saldo tanpa perubahan data.
- Dua proses benar-benar bersamaan: key/payload sama menghasilkan satu wallet,
  operation, movement, audit dan receipt; payload berbeda menghasilkan satu
  respons 201 dan satu 409. Schema testing sementara dibersihkan setelah tes.
- Pint dan Composer validate --strict lulus. --dirty tidak tersedia tanpa Git.
- Migration diterapkan ke bahitung_testing dan bahitung; reconcile lokal lulus.

Batas: frontend keuangan belum diintegrasikan, tidak ada data contoh yang dimigrasi.
Ledger tahap ini khusus wallet opening; income/expense/transfer+fee, kategori,
reversal/correction dan goals akan dilengkapi bertahap. Tidak ada klaim transfer
atau pencatatan transaksi pengguna sudah persisten.

File utama: backend/app/Services/WalletService.php,
Repositories/{Contracts/WalletRepository,EloquentWalletRepository}.php,
Http/Controllers/Api/V1/WalletController.php, Http/Requests/*Wallet*Request.php,
Models/Wallet.php, Policies/WalletPolicy.php, migration create_wallet_ledger_foundation,
Console/Commands/ReconcileWalletBalances.php, serta tes WalletLedgerTest dan
WalletConcurrencyTest. Kontrak/panduan di docs/API_CONTRACT.md dan backend/README.md.

## Tahap 4b — Kategori, income/expense dan transfer + fee (16 Oktober 2026)

Selesai:
- API list/create/edit/archive kategori household dengan nama unik per jenis;
  jenis immutable, Owner/Admin menulis dan semua anggota aktif membaca.
- API list/detail/post income, expense, transfer; Member+ menulis, Viewer membaca.
  Filter tanggal, jenis, wallet, kategori (termasuk fee), aktor dan status.
- Transfer principal dan fee terpisah dalam transfer_details yang append-only;
  debit principal+fee pada asal dan kredit principal pada tujuan, semua atomik.
- Decimal-string melalui Brick Math yang sudah terpasang; tidak memakai float.
  Saldo cukup, batas numeric, wallet/kategori aktif dan tenant divalidasi.
- Validasi saldo historis akhir setiap tanggal, termasuk sebelum opening dan
  minimum intermediate setelah tanggal posting; fee ikut dihitung.
- Household lock + projection lock urut ID, retry terbatas, audit dan receipt
  atomik. Replay original dan konflik key lintas endpoint; izin replay tetap dicek.
- Migration diterapkan pada bahitung dan bahitung_testing. Reconcile lokal lulus.

Validasi: seluruh backend 199 tes, 741 assertion lulus. Dua proses expense
Rp80.000 pada saldo Rp100.000 hanya satu sukses; duplicate transfer+fee hanya
sekali terposting. Tes juga mencakup rollback audit, decimal >2^53, overflow,
historis, arsip, role/tenant, FK kategori, transfer detail immutable dan auth.
Pint serta Composer validate --strict lulus.

Batas: frontend keuangan belum terhubung API; data contoh tetap terpisah.
Warnings budget masih kosong, laporan fee belum tersedia. Kategori dapat dibuat
melalui API; belum ada backfill kategori default. Koreksi/void dan adjustment
masih tahap berikutnya, lalu integrasi halaman dompet/transaksi ke API.

File utama: backend/app/Services/{CategoryService,OperationPostingService}.php,
Repositories/{Contracts/FinanceRepository,EloquentFinanceRepository}.php,
Http/Controllers/Api/V1/{CategoryController,OperationController}.php,
Policies/OperationPolicy.php, migration create_categories_and_transfer_details,
tes OperationPostingTest dan OperationConcurrencyTest; kontrak di API_CONTRACT.md.

## Tahap 4c — Koreksi dan void transaksi (16 Oktober 2026)

Selesai:
- Endpoint correct (201) dan void (200), alasan wajib, header idempotency UUID.
- Member mengubah income/expense/transfer sendiri tanpa batas waktu; Owner/Admin
  dapat mengubah operasi anggota lain dan opening; Viewer dilarang. Izin replay
  tetap diperiksa setelah perubahan role/membership.
- Reversal exact opposite append-only, unique reversal_of_id, operation_reversals
  immutable, operation original reversed, replacement linked via correction_of_id.
  Trigger DB memeriksa wallet/operation/tenant/nominal reversal tepat sesuai original.
- Koreksi memvalidasi gabungan reversal + replacement pada saldo sekarang dan
  lintasan saldo akhir tanggal seluruh wallet, termasuk perubahan tanggal/wallet/fee.
  Tidak menolak akibat saldo sementara sebelum replacement yang sebenarnya aman.
- Void mengembalikan fee transfer juga; detail transfer lama tetap immutable.
- GET detail menampilkan alasan/pelaku/waktu reversal dan ID replacement; Viewer
  dapat melihat histori transaksi bersama tanpa mengakses audit internal.
- Lock household dan projection urut ID, transaksi atomik dan durable replay.
  Kegagalan audit membatalkan reversal, replacement, status, saldo dan receipt.
- Migration lokal dan testing diterapkan; wallets:reconcile lokal lulus.

Validasi: 236 tes backend, 956 assertion lulus; Pint dan Composer validate --strict
lulus. Tiga skenario dua proses: duplicate void, dua key berbeda, correct vs void.
Hanya satu reversal tercatat, replay sama persis, key baru mendapat ALREADY_REVERSED.
Tes mencakup income terpakai, tanggal intermediate negatif, fee, perpindahan wallet,
opening Owner/Admin, precision, overflow, chaining, rollback, role/tenant dan auth.

Batas: frontend keuangan tetap fixture, budget warning/laporan belum tersedia,
adjustment dan goal reversal belum tersedia. Tahap berikutnya integrasi halaman
Dompet/Transaksi dengan backend, lalu melanjutkan modul budget/goals dan analytics.

File utama: backend/app/Services/CorrectionService.php, OperationPostingService.php,
Repositories/{Contracts/FinanceRepository,EloquentFinanceRepository}.php,
Http/Controllers/Api/V1/CorrectionController.php, Http/Requests/*OperationRequest.php,
Policies/OperationPolicy.php, migration add_operation_reversals,
tes OperationCorrectionTest dan CorrectionConcurrencyTest.

## Tahap 4d — Dashboard setelah login dan integrasi keuangan Vue (16 Oktober 2026)

Selesai:
- Akar masalah login: safeNext dan route root sebelumnya menuju /keluarga, tanpa
  jalan ke Dashboard backend. Kini default /dashboard dengan guard session/membership;
  akun tanpa keluarga tetap onboarding /keluarga. Tombol Buka Dashboard tersedia.
- Dashboard, Dompet dan Transaksi memakai API keluarga aktif dan layout biru bahitung.
  Saldo dompet dan ringkasan bulan berjalan memakai decimal string/BigInt; seluruh
  halaman data bulan dibaca, opening/principal transfer bukan income/expense,
  fee ikut expense dan operasi reversed tidak masuk ringkasan ekonomi aktif.
- Create/edit/archive/re-activate dompet dan history paginated; pengelolaan kategori;
  income/expense/transfer+fee, detail, koreksi/void dengan alasan; filter/paging.
- Kontrol write sesuai role, Viewer hanya baca. Switch keluarga membuang data lama,
  mengabaikan respons usang dan menyimpan pilihan per tab, diverifikasi saat refresh.
- Posting UUID dan snapshot sessionStorage per akun sebelum request. Respons hilang
  dapat dipulihkan setelah reload dengan key/payload sama; tidak ada debit ganda.
  Token autentikasi tidak disimpan di storage; tetap session Sanctum + CSRF.
- Mock lama dipertahankan di /pratinjau; route dompet/transaksi mock dipindahkan ke
  /pratinjau/dompet dan /pratinjau/transaksi dan tautan preview disesuaikan.

Validasi:
- Build Vue/TypeScript lulus.
- Chromium E2E finance: daftar/onboarding, login langsung Dashboard, wallet/kategori,
  pemasukan/pengeluaran, transfer+fee, koreksi/void, insufficient balance, lost response
  setelah commit + reload/retry tanpa duplikasi, archive/history, filter/pagination,
  switching/reload keluarga, kontrol Viewer memakai membership nyata, presisi >2^53,
  logout/route guards. Semua fixture pada bahitung_browser_testing.
- Regresi browser akun/CSRF/verifikasi/undangan/reset/login/logout lulus.
- Screenshot desktop 1440px dan mobile 360px diperiksa; tanpa overflow horizontal
  atau page error. Artefak /tmp/bahitung-finance-{desktop,mobile,transfer-mobile}.png.
- Health API lokal ok/PostgreSQL ok; /dashboard port 5173 merespons 200.
  Tidak ada perubahan skema/backend pada tahap ini.

Batas: budget, target, analytics, adjustment belum terhubung pada ruang keuangan
aktif. Mock desain lama tetap tersedia terpisah, tidak menjadi data keluarga nyata.
Dashboard saat ini merangkum wallet dan transaksi, belum total aset goals.
Tahap berikutnya backend budget/goals lalu integrasi layar terkait.

File utama: frontend/src/views/FinanceView.vue, services/finance.ts, finance.css,
router/index.ts, services/api.ts, stores/auth.ts, views/FamilyAccountView.vue dan
AccountView.vue; tests/finance-browser.cjs serta penyesuaian auth-browser.cjs.

## Tahap 5a — Backend Budget bulanan (16 Oktober 2026)

Selesai:
- Migration budgets/budget_lines: bulan unik per household, kategori unik per budget,
  FK komposit tenant, limit numeric(19,2) positif, tanggal hari pertama bulan.
- API list/create/detail/update/copy dan reports/budget-usage; Owner/Admin menulis,
  semua anggota aktif membaca. PATCH mengganti seluruh lines; month immutable.
- Salin sumber bulan lebih awal secara eksplisit, target harus kosong; snapshot
  limit independen, tidak carry-over dan tanpa penjadwalan otomatis.
- Perhitungan usage dinamis expense posted + fee transfer, seluruh wallet/aktor.
  Opening, income dan principal transfer tidak masuk; koreksi/void memperbarui
  pemakaian periode/kategori terdampak tanpa cache stale. Unbudgeted dilaporkan terpisah.
- Warning 80%, 100%, exceeded pada posting/correct/void, tanpa memblokir transaksi.
  Decimal-string/Brick Math presisi, receipt replay mempertahankan warning original.
- Household lock, audit dan transaksi atomik; duplikasi bulan dicegah saat concurrent.

Validasi: 279 tes backend, 1204 assertion lulus. Cakupan limit >2^53, threshold,
fee, unbudgeted, lintas bulan/kategori, reversal, replay, copy independence, arsip,
role/tenant/auth, validasi, FK/check constraint, rollback audit dan concurrent
copy-copy/copy-create. Pint dan Composer validate --strict lulus.
Migration diterapkan pada bahitung_testing dan bahitung; reconcile lokal lulus.

Batas: ini tahap backend Budget. UI Budget lama masih mock dan warning API belum
ditampilkan di UI transaksi. Berikutnya integrasi halaman Budget dan warning Vue,
setelah itu Target. Tidak ada perubahan UI atau data contoh pada tahap ini.

File utama: backend/app/Services/{BudgetService,BudgetUsageService}.php,
Repositories/{Contracts/BudgetRepository,EloquentBudgetRepository}.php,
Http/Controllers/Api/V1/BudgetController.php, Http/Requests/*Budget*Request.php,
Services/{OperationPostingService,CorrectionService}.php, migration
create_monthly_budgets, tests/Feature/{BudgetTest,BudgetConcurrencyTest}.php.


## Tahap 5b — Integrasi Budget dan warning transaksi (16 Oktober 2026)

- `/budget` menggunakan session dan household aktif: laporan bulanan, buat/edit
  batas kategori, salin bulan lalu melalui dialog konfirmasi, serta pengeluaran
  tanpa budget. Owner/Admin mengatur; Member/Viewer membaca.
- Pemakaian berasal dari backend, termasuk biaya admin dan dampak koreksi/void.
  Semua nominal tetap string desimal/BigInt; progress visual saja memakai Number.
- Pergantian bulan/keluarga mengosongkan data lama dan mengabaikan respons terlambat.
  Kegagalan koneksi saat mutasi memuat ulang kondisi server sebelum menawarkan tulis ulang.
- FinanceView menampilkan warning 80%/100%/terlampaui beserta tautan periode budget,
  termasuk respons retry transaksi. Warning tidak menggagalkan transaksi tersimpan.
- Navigasi keuangan bersama menambahkan Budget. Mock Budget dipindahkan ke
  `/pratinjau/budget`; link mock tetap terpisah dari data keluarga aktif.
- Validasi: `npm run build` lulus. Chromium finance-browser lulus dengan tambahan
  create/edit/copy Budget, validasi nol, presisi nominal di atas 2^53, independensi
  bulan salinan, warning 80/100/over, fee/correction/void, isolasi keluarga, Viewer,
  serta screenshot desktop 1440px/mobile 360px tanpa overflow.
- File utama: frontend/src/views/BudgetView.vue, views/FinanceView.vue,
  components/FinanceNavigation.vue, services/budgets.ts, router/index.ts,
  finance.css, tests/finance-browser.cjs.

Berikutnya: backend Target tabungan, lalu integrasi layar Target. Analytics masih
pada tahap tampilan contoh. Tidak ada perubahan backend pada tahap ini.

## Tahap 5c — Backend Target tabungan (16 Oktober 2026)

- API goals list/create/detail/update/arsip, riwayat, setoran dan penarikan tersedia.
  Semua pembacaan scoped household aktif; metadata dan penarikan Owner/Admin,
  setoran juga dapat dilakukan Member. Viewer hanya membaca.
- Setoran memindahkan saldo wallet ke goal, penarikan sebaliknya. Keduanya memakai
  OperationPostingService dan ledger yang sama, bukan pemasukan/pengeluaran.
- Migrasi goals/goal_balances, referensi goal pada operations/movements, composite
  tenant foreign keys, constraint tepat satu akun per movement, serta validasi
  reversal exact untuk wallet maupun goal. Histori tetap append-only.
- Posting dan koreksi mengunci household, wallet terurut, lalu goal terurut.
  Saldo saat ini dan historis divalidasi nonnegatif serta batas numeric(19,2).
  Audit, projection, movements dan receipt disimpan atomik. Retry dengan UUID
  yang sama mengulang respons tanpa menggandakan perpindahan dana.
- CorrectionService mendukung koreksi/void Target oleh Owner/Admin. Replacement
  mempertahankan jenis setoran/penarikan dan dapat mengganti target/dompet aktif
  dalam keluarga yang sama. Void memvalidasi dependensi historis kedua akun.
- Target boleh melampaui nominal sasaran; progress dan status tercapai dihitung
  dari saldo. Arsip mempertahankan dana/histori dan memerlukan aktivasi ulang
  sebelum setor/tarik. Metadata tidak mengubah saldo.
- `goals:reconcile` memeriksa proyeksi terhadap ledger tanpa memodifikasi data.
- Validasi: seluruh 310 tests / 1498 assertions lulus, termasuk dua penarikan
  bersamaan, replay, hak akses, isolasi household, overflow historis, presisi,
  rollback audit, koreksi dan constraint database. Pint dan composer validate
  --strict lulus. Migrasi diterapkan ke bahitung_testing dan aplikasi lokal;
  wallets:reconcile dan goals:reconcile keduanya sesuai ledger.
- File utama: backend/app/Services/{GoalService,OperationPostingService,CorrectionService}.php,
  Repositories/{Contracts/GoalRepository,EloquentGoalRepository,EloquentFinanceRepository}.php,
  Http/Controllers/Api/V1/GoalController.php, Http/Requests/*Goal*Request.php,
  Console/Commands/ReconcileGoalBalances.php, routes/api.php,
  database/migrations/2026_10_16_090100_create_goals_and_goal_ledger.php,
  tests/Feature/{GoalTest,GoalConcurrencyTest}.php.

Berikutnya: integrasi halaman Target, termasuk setoran/penarikan dan ringkasan
aset wallet + goals. Halaman /target saat ini masih memakai contoh.


## Tahap 5d — Integrasi Target dan ringkasan aset (16 Oktober 2026)

- `/target` dilindungi session dan memakai data keluarga aktif. Kartu Target:
  progres, dana terkumpul, sisa sasaran, tanggal opsional, status tercapai/arsip.
  Owner/Admin membuat/mengelola; Member dapat setor; Viewer hanya membaca.
- Form setoran/penarikan memakai alur financial request bersama FinanceView:
  nominal string desimal, UUID + snapshot tersimpan sebelum request, retry setelah
  respons hilang/reload tidak mencatat ulang. Error saldo ditampilkan dari backend.
- Riwayat Target paginated dengan original/reversal; detail transaksi mengenali
  wallet dan goal. Owner/Admin dapat koreksi/void; koreksi mempertahankan jenis
  setoran/penarikan. Filter Transaksi mencakup kedua jenis tersebut.
- Dashboard/Target menampilkan total aset wallet + goal termasuk arsip. Dana goal
  terpisah dari saldo tersedia dan tidak dihitung sebagai income/expense.
- Mock tetap di `/pratinjau/target`, dengan link mock diperbarui. Navigasi keuangan
  menambahkan Target; mobile memakai dua baris agar label tetap terbaca.
- Verifikasi: `npm run build` lulus; goals-browser.cjs dan finance-browser.cjs
  keduanya lulus. Cakupan Target: create/edit/arsip/aktifkan, precision >2^53,
  setoran/penarikan, koreksi/void, saldo tidak cukup, respons hilang sesudah commit
  + reload/retry, konservasi aset, pagination riwayat, isolasi keluarga, Viewer dan
  Member, route guard, screenshot desktop 1440px/mobile 360px tanpa overflow.
- File utama: frontend/src/views/FinanceView.vue, components/LiveGoalCards.vue,
  components/FinanceNavigation.vue, services/finance.ts, services/api.ts,
  router/index.ts, finance.css, tests/goals-browser.cjs. Tidak mengubah backend;
  database browser testing dimigrasikan untuk pengujian terpisah.

Berikutnya: backend laporan Analisis, lalu integrasi halaman Analisis.

## Tahap 5e — Backend laporan Analisis (16 Oktober 2026)

- GET `/api/v1/households/{h}/reports/cashflow` dengan from/to inklusif dan
  wallet_id opsional. Seluruh anggota aktif dapat membaca; resource asing,
  membership nonaktif dan keluarga arsip ditolak. Rentang dibatasi 366 hari.
- Ringkasan income/expense/net/fee dan count, series harian serta bulanan termasuk
  periode kosong, kategori income/expense dengan share, status arsip dan fee.
- Satu query agregasi PostgreSQL menjadi sumber seluruh bagian laporan. SUM tetap
  numeric, service memakai BigDecimal; nominal dan persentase dikirim string.
- Fee transfer dihitung sekali sebagai expense pada dompet asal. Opening, pokok
  transfer dan perpindahan dana goals tidak masuk cashflow. Reversal mengikuti
  operasi efektif tanpa double subtraction; koreksi tercermin lintas periode,
  wallet dan kategori. Arsip tidak menghapus histori.
- Verifikasi: seluruh 333 tests / 1584 assertions lulus. Tambahan 23 kasus mencakup
  fee/transfer/goal exclusion, koreksi/void, tanggal inklusif/leap year/batas tahun,
  presisi SUM di atas numeric satu transaksi, defisit/zero income, role, isolasi
  keluarga dan wallet, arsip serta validasi rentang. Pint dan composer validate
  --strict lulus; route reports.cashflow terdaftar.
- File utama: backend/app/Http/Requests/CashflowRequest.php,
  Http/Controllers/Api/V1/ReportController.php, Services/ReportService.php,
  Repositories/{Contracts/ReportRepository,EloquentReportRepository}.php,
  Providers/AppServiceProvider.php, routes/api.php,
  tests/Feature/CashflowReportTest.php. Tidak ada perubahan skema atau dependensi.

Berikutnya: integrasi halaman Analisis ke API. `/analisis` masih memakai contoh.


## Tahap 5f — Integrasi halaman Analisis (16 Oktober 2026)

- `/analisis` kini memakai API cashflow keluarga aktif dan route guard session.
  Filter tanggal inklusif/dompet (termasuk arsip), ringkasan income/expense/net/fee,
  rasio net terhadap income, serta rincian kategori income dan expense.
- Grafik harian/bulanan dan tabel nominal memakai response API yang sama. Grafik
  memakai rasio BigInt; uang tidak melewati Number. Bulan parsial diberi keterangan.
- Periode kosong, validasi >366 hari, defisit/zero income, loading dan error koneksi
  memiliki tampilan tersendiri. Muat ulang memperbarui laporan dan daftar dompet.
- Pergantian keluarga membersihkan wallet/report, dan respons lama tidak menimpa
  laporan baru. Data contoh tetap di `/pratinjau/analisis`, link mock diperbarui.
- Navigasi bersama menambah Analisis; menu HP dapat digeser dan posisi awal
  mengikuti halaman aktif. Kontrol grafik serta tabel dapat dipakai di layar kecil.
- Verifikasi: build Vue/TypeScript/Vite lulus, analytics-browser.cjs lulus setelah
  perbaikan visual HP, finance-browser.cjs lulus. Cakupan mencakup jumlah/kategori,
  fee hanya dompet asal, exclusion Target, koreksi/arsip, harian/bulanan dan tabel,
  periode kosong/leap day, invalid range, network error/recovery, respons terlambat
  saat ganti keluarga, Viewer, reload/session guard dan screenshot 1440/360px.
- File utama: frontend/src/views/LiveAnalyticsView.vue, components/CashflowChart.vue,
  services/reports.ts, components/FinanceNavigation.vue, router/index.ts,
  services/api.ts, finance.css, tests/analytics-browser.cjs.

Integrasi utama Dashboard, Dompet, Transaksi, Budget, Target dan Analisis tersedia.
Tidak ada migrasi atau perubahan dependency pada tahap ini.

## Tahap 5g — Integrasi halaman Anggota (17 Oktober 2026)

- `/anggota` memakai session guard dan API keluarga aktif. Daftar seluruh halaman
  API, pencarian nama, filter status, tanggal bergabung, dan penjelasan peran.
- Owner/Admin mengundang Member/Viewer, melihat status/masa berlaku undangan,
  serta mencabut undangan dengan konfirmasi. Mailer lokal tetap log.
- Perubahan peran/status membutuhkan alasan. Owner dapat mengelola non-Owner;
  Admin hanya Member/Viewer. Member/Viewer hanya membaca daftar anggota.
  Status suspended/removed dapat diaktifkan kembali tanpa menghapus histori.
- Ganti keluarga membersihkan data/form dan mengabaikan response lama. Muat ulang
  menyegarkan membership. Loading, error, pesan hasil, dan pemulihan koneksi tersedia.
- Data contoh tetap di `/pratinjau/anggota`; navigasi live menambah Anggota dan
  halaman Keluarga menyediakan tautan Kelola anggota. Tampilan mengikuti aksen biru.
- Verifikasi: build Vue/TypeScript/Vite lulus; members-browser.cjs lulus dengan API
  nyata pada database browser testing: undang/cabut, role/status/aktivasi ulang,
  pembatasan Admin/Member/Viewer, pencarian, pergantian keluarga, error/recovery,
  screenshot desktop 1440px dan mobile 360px tanpa overflow halaman.
- Tidak ada perubahan backend, skema database, atau dependency aplikasi.

Berikutnya: integrasi Pengaturan Keluarga (nama/zona waktu dan transfer kepemilikan).
- Regresi auth-browser.cjs juga lulus: daftar, reload session/CSRF, verifikasi email,
  buat keluarga, terima undangan, reset password, login/logout, desktop/mobile.

## Tahap 5h — Integrasi Pengaturan Keluarga (17 Oktober 2026)

- `/pengaturan` memakai session guard, API household dan daftar anggota keluarga aktif.
  Owner dapat menyimpan nama dan zona waktu; Admin/Member/Viewer hanya membaca.
  Rupiah dan awal bulan ditampilkan sebagai pengaturan tetap sesuai kontrak MVP.
- Transfer kepemilikan memilih anggota aktif selain diri sendiri, dengan alasan
  wajib dan konfirmasi eksplisit perubahan peran. Owner lama menjadi Admin dan
  kontrol Owner langsung dihapus setelah respons sukses, lalu membership dimuat ulang.
- Ganti keluarga membersihkan form/data; respons lama diabaikan. Loading, error,
  pemulihan koneksi, kandidat kosong, dan pesan hasil tersedia.
- Pratinjau tetap di `/pratinjau/pengaturan`. Menu bersama menambah Pengaturan.
- settings-browser.cjs lulus: persistence nama/zona, Owner/Admin/Member/Viewer,
  konfirmasi transfer, hak akses Owner baru/lama setelah reload, isolasi keluarga,
  kandidat kosong, network recovery, dan screenshot desktop/mobile 360px.
- Tidak ada perubahan backend, skema, atau dependency aplikasi. Server utama
  frontend 5173 dan backend 8000 dijalankan untuk pengguna mencoba aplikasi.

Berikutnya: pemeriksaan alur MVP menyeluruh dan kesiapan operasional lokal
(pengiriman email nyata, backup/restore, serta rekonsiliasi sebelum deployment).
- Verifikasi akhir: build Vue/TypeScript/Vite dan regresi members-browser.cjs lulus.
  Server testing dihentikan; server utama pengguna tetap berjalan.

## Tahap 6a — Pemeriksaan MVP lokal (17 Oktober 2026)

- Seluruh 333 tes backend / 1584 assertions lulus di bahitung_testing.
- Build Vue/TypeScript/Vite lulus. Rekonsiliasi read-only pada database utama:
  saldo dompet dan target sesuai ledger; tidak ada perubahan data.
- Health API utama: service ok, database ok. Server pengguna 5173/8000 tetap aktif.
- Suite browser Auth, Finance, Goals, Analytics, Members, Settings diperiksa pada
  server 5174/8001 dengan database bahitung_browser_testing. Cakupan mencakup role,
  isolasi keluarga, presisi nominal, koreksi/void, transfer/fee, budget warnings,
  lost-response/retry, session, perubahan pengaturan dan kepemilikan, desktop/mobile.
- Percobaan gabungan berhenti pada registrasi suite Members (timeout); Members
  lulus saat diulang setelah jeda. Batas pendaftaran backend 5/menit/IP tetap aktif.
  Penyebab timeout belum dipastikan melalui response HTTP percobaan pertama.
- README diperbarui agar status integrasi mencerminkan implementasi saat ini.

Belum selesai untuk produksi: email nyata (lokal masih log), latihan backup/restore
ke database terpisah dan rekonsiliasi hasil restore, konfigurasi hosting/domain/HTTPS,
secret/cookie produksi serta pemeriksaan setelah deployment. Hasil tes lokal tidak
menyatakan lingkungan produksi sudah siap. Tahap berikut yang dapat dikerjakan
lokal tanpa akun layanan eksternal adalah latihan backup/restore.
- Hasil akhir: keenam suite browser lulus, termasuk Members dan Settings pada
  pengulangan. Server khusus testing dihentikan; server utama tetap berjalan.

## Tahap 6b — Backup dan latihan restore lokal (17 Oktober 2026)

- Menambah `backend/bin/backup-drill.py` (Python standard library, PostgreSQL tools
  dari container Compose), ignore backup, serta panduan operasional di README.
- Backup custom pg_dump dan fingerprint tabel berasal dari satu exported snapshot
  repeatable-read/read-only. Tidak ada write alat uji pada database utama.
- Restore ke database baru berawalan bahitung_restore_drill dengan nama unik,
  single transaction/exit-on-error; database yang sudah ada tidak ditimpa.
- Hasil final: 26 fingerprint tabel cocok, SHA-256 file valid, rekonsiliasi saldo
  dompet/target lulus, 6 trigger aplikasi aktif. UPDATE ditolak pada salinan tabel
  balance_movements, audit_logs, idempotency_receipts dan transfer_details.
  operation_reversals kosong sehingga uji UPDATE tabel tersebut dilewati.
- Percobaan cek UPDATE tambahan awal memakai kolom id pada transfer_details;
  diperbaiki ke operation_id (juga untuk operation_reversals). Versi final lulus.
  Database percobaan yang gagal sudah dibersihkan setelah investigasi.
- Backup final: backend/storage/app/private/backups/20261017t024229z_846aa43e/.
  Dump 92528 bytes, laporan report.json; latihan final 2.82 detik. Direktori 700,
  file 600, dikecualikan dari Git. Database restore final otomatis dihapus.
- Health API utama tetap ok/database ok; server pengguna tetap berjalan.

Batas cakupan: backup database lokal/manual, belum otomatis/off-device/enkripsi;
file unggahan, APP_KEY/.env dan konfigurasi layanan tidak termasuk dump. Perlu
penyimpanan aman terpisah serta kebijakan retensi sebelum produksi. Berikutnya:
konfigurasi dan uji email nyata untuk verifikasi, undangan dan reset password.

## Tahap 6c — Persiapan SMTP (17 Oktober 2026)

- `.env.example` menyediakan host/port/scheme/username/password SMTP; default tetap
  log. `config/mail.php` menambah timeout 15 detik dan require_tls default true.
- Command `mail:check` memeriksa konfigurasi SMTP, alamat pengirim, URL aplikasi,
  dan opsi penerima tanpa mencetak rahasia. Tanpa opsi tidak menghubungi SMTP.
  `--send-to` mengirim satu email uji non-token dan membedakan penerimaan SMTP
  dari kepastian sampai inbox. Error transport ditampilkan tanpa detail kredensial.
- Panduan pengisian `.env`, command pemeriksaan, pengiriman uji, dan pengaruh URL
  localhost ditambahkan ke README. Tidak ada kredensial ditulis ke repository.
- Pint lulus. 61 tests / 180 assertions lulus (MailConfigurationTest,
  AccountRecoveryTest, MembershipManagementTest). Pengiriman eksternal tidak
  dijalankan; tes command menggunakan transport mock.
- Pemeriksaan konfigurasi lokal mengonfirmasi mailer masih log (exit 1 sesuai
  desain). Pengguna ditanya layanan email dan alamat pengirim; belum ada jawaban.

Belum selesai: pilih layanan/pengirim, isi kredensial lokal, kirim uji ke penerima
milik penguji yang disepakati, pastikan inbox, lalu uji tiga alur email nyata.
Pengiriman tetap sinkron; antrean/retry belum diimplementasikan. Tahap ini belum
menyatakan email produksi aktif.

- Pengguna memilih Gmail. Host smtp.gmail.com, port 587, scheme smtp dan TLS
  disiapkan pada .env lokal; mailer tetap log. Alamat pengirim dan App Password
  belum tersedia; tidak ada email eksternal dikirim.

### Aktivasi SMTP Gmail — 18 Oktober 2026

- Pengguna mengisi App Password sendiri pada .env lokal. MAIL_MAILER diaktifkan
  menjadi smtp; config cache dibersihkan. Nilai kredensial tidak ditampilkan.
- mail:check lulus. Koneksi TLS dan autentikasi SMTP Gmail berhasil melalui
  transport aplikasi (start/stop tanpa MAIL FROM/RCPT TO/DATA).
- Belum ada email uji eksternal dikirim. Pengiriman satu email uji dan pemeriksaan
  inbox masih menunggu persetujuan penerima; tiga alur email nyata belum diverifikasi.
- Pengguna mengizinkan satu email uji ke alamat Gmail pengirim. Command
  `mail:check --send-to` selesai exit 0; SMTP menerima pesan berjudul
  “Uji pengiriman email bahitung”. Penerimaan inbox/spam belum dikonfirmasi pengguna.
