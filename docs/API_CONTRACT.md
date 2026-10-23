# RumahFin v3 — REST API Contract (Laravel)
Versi API: `/api/v1` | Format JSON | Auth: Laravel Sanctum | Semua response error JSON | Semua amount adalah **string desimal** `"150000.00"`.

## Konvensi global
- Base path resource household: `/api/v1/households/{householdId}` (selalu eksplisit, tidak berdasarkan household dari body).
- Header `Authorization: Bearer <token>` atau session cookie Sanctum untuk first-party SPA dengan CORS/CSRF dikonfigurasi.
- Semua request tulis ledger memakai `Idempotency-Key: <UUID>`; unik per household. Same key+same payload -> hasil lama; key + beda payload -> `409 IDEMPOTENCY_CONFLICT`.
- Timestamp respons UTC ISO 8601; business date `YYYY-MM-DD` dalam timezone household. Currency IDR pada MVP.
- `GET` list mendukung `page`, `per_page` (maks.100), `sort`, `from`, `to`; hasil berbentuk `data`, `meta` (paging).
- Standard successful item `{"data":{...},"warnings":[]}`; error `{"error":{"code":"INSUFFICIENT_BALANCE","message":"Saldo dompet tidak mencukupi","details":{...}},"request_id":"..."}`.
- Untuk resource dari household lain kembalikan `404`; tanpa login `401`; salah role `403`; validasi `422`; duplicate `409`; limit `429`.
- Kebijakan auth/permission wajib di Laravel Policies + Service; semua `wallet_id`,`goal_id`,`category_id` discope ke household.

## Status implementasi lokal — tahap 5f

Sudah tersedia: register/login/logout, verifikasi email, reset password, `/me`,
create/list/show/update household, list/update anggota, undangan/list/revoke/accept,
dan transfer ownership. API dompet/saldo awal/riwayat, kategori, pemasukan,
pengeluaran, transfer+fee, koreksi/void serta budget dan laporan budget-usage tersedia.
Dashboard/Dompet/Transaksi/Budget dan warning budget sudah terintegrasi Vue.
API Target tabungan (goals), setoran/penarikan, riwayat dan koreksi tersedia.
Layar Target dan ringkasan aset sudah terintegrasi. API laporan cashflow tersedia;
halaman Analisis sudah terintegrasi. Adjustment masih tahap berikutnya.

Autentikasi tahap backend ini menggunakan **Bearer token Sanctum**, berlaku 24 jam.
Token plaintext hanya dikembalikan saat register/login; database menyimpan hash.
Logout mencabut token request saat ini, bukan token perangkat lain. Login dibatasi
5 request/menit per email+IP dan 30/menit per IP; register 5/menit per IP.
Email dinormalisasi lowercase. Register tidak otomatis membuat keluarga.

`POST /auth/register`:
```json
{"name":"Budi","email":"budi@example.test","password":"kata-sandi-panjang","password_confirmation":"kata-sandi-panjang"}
```
Password minimal 12, maksimal 72 karakter. Login menerima `email` dan `password`.
Keduanya mengembalikan `data: {user, token, token_type: "Bearer", expires_at}`,
`warnings: []`; register HTTP 201, login HTTP 200. Password/remember_token tidak
pernah diserialisasi. Error login memakai pesan generik tanpa membedakan email.

`GET /me`: `data: {user, memberships}`; hanya membership aktif pada keluarga
nonarsip. Setiap membership berisi `id`, `household_id`, `role`, `status`, `household`.
Pilih keluarga dengan memakai ID di URL request berikutnya; tidak ada active
household global yang dibagikan antarperangkat.

`POST /households`: `{"name":"Keluarga Budi","timezone":"Asia/Makassar"}`.
Timezone opsional; currency selalu IDR. Creator menjadi satu Owner aktif,
bersamaan dengan audit dalam satu transaksi. `GET /households` dan
`GET /households/{h}/members` memakai `data` + `meta` (page default 1,
per_page default 20, maksimal 100). Daftar anggota hanya menyertakan ID/nama
user, tanpa email/kredensial. Filter lain pada konvensi global belum berlaku
untuk kedua daftar ini.

`PATCH /households/{h}` menerima `name` dan/atau `timezone`, khusus Owner
sesuai tabel endpoint. Admin belum boleh mengubah pengaturan keluarga.
Currency, creator, role, dan archive tidak dapat diubah melalui endpoint ini.
Perubahan dicatat sebagai audit before/after; PostgreSQL menolak UPDATE/DELETE
terhadap baris audit. Keluarga asing/nonaktif/arsip mengembalikan 404.

Browser Vue sekarang memakai session Sanctum + CSRF. `POST /session/register`
dan `/session/login` menerima payload seperti endpoint auth, tetapi hanya
mengembalikan `data.user` tanpa token. Keduanya membutuhkan stateful request dari
origin frontend yang dikonfigurasi. `POST /session/logout` menghapus session.
Cookie diregenerasi saat login, dan reset password membatalkan session lama.
Endpoint Bearer `/auth/*` tetap tersedia untuk client API.

Frontend memanggil `/sanctum/csrf-cookie` sebelum login/register dan mengirim
X-XSRF-TOKEN untuk mutasi. Laravel 13 menerima Sec-Fetch-Site same-origin atau
CSRF token yang cocok; lintas situs tanpa token ditolak 419. CORS allowlist
mendukung credentials. Frontend tidak menyimpan token autentikasi dalam storage.

## Pemulihan akun dan keanggotaan — tahap 3c

Email memakai `MAIL_MAILER=log` di lokal. Pendaftaran mengirim notifikasi
verifikasi setelah commit. Email/notifikasi bukan token yang dikembalikan melalui API.

| Metode | Path setelah `/api/v1` | Payload / perilaku |
|---|---|---|
| POST | `/auth/forgot-password` | `email`; respons 200 generik untuk email dikenal/tidak dikenal |
| POST | `/auth/reset-password` | `email`, `token`, `password`, `password_confirmation` |
| POST | `/auth/email/verification-notification` | Login; kirim ulang jika belum verified |
| GET | `/auth/email/verify/{id}/{hash}` | Login akun yang cocok + query signed URL dari notifikasi |
| POST | `/households/{h}/invitations` | `email`, `role` member/viewer; Owner/Admin |
| GET | `/households/{h}/invitations` | Owner/Admin; `page`/`per_page` maks. 100 |
| POST | `/households/{h}/invitations/{id}/revoke` | Owner/Admin; undangan pending saja |
| POST | `/invitations/accept` | `token`; login + verified email cocok |
| PATCH | `/households/{h}/members/{member}` | `role` dan/atau `status`, `reason` wajib |
| POST | `/households/{h}/ownership-transfer` | `member_id`, `reason` wajib; Owner |

Reset token berlaku 60 menit, sekali pakai. Password baru mengikuti aturan register;
reset berhasil mencabut semua token Sanctum dan mengganti remember token. Request
forgot/reset dibatasi bersama 5/menit per IP, pengiriman ulang reset juga memakai
throttle broker 60 detik. Verifikasi berlaku 60 menit, signed URL relatif, memakai
origin APP_URL dari konfigurasi saat membuat email. Kirim GET tautan tersebut dengan
Bearer milik akun yang sama. Resend/verify dibatasi bersama 5/menit per user.

Undangan berlaku 7 hari; database hanya menyimpan hash SHA-256, token plaintext
hanya pada notifikasi. API list/detail dan audit tidak memuat token/hash.
Undangan pending yang masih berlaku dan email sama menghasilkan 409. Undangan
kedaluwarsa dapat diganti melalui POST baru (yang lama menjadi revoked).
Undangan untuk pengguna yang sudah punya membership ditolak 409; untuk membership
suspended/removed, pengelola dapat mengaktifkan kembali melalui PATCH anggota.
Undangan yang telah diterima/dicabut/kedaluwarsa tidak dapat dipakai untuk bergabung.
Email yang tidak cocok atau resource lintas keluarga menghasilkan 404.

PATCH role menerima admin/member/viewer; status active/suspended/removed.
Owner dapat mengubah anggota non-Owner. Admin hanya dapat mengubah Member/Viewer
menjadi Member/Viewer dan statusnya; tidak dapat mengubah Admin atau Owner.
Owner tidak dapat diturunkan/dihapus melalui PATCH. Target ownership-transfer harus
anggota aktif dari keluarga yang sama, bukan diri sendiri; Owner lama menjadi Admin.
Seluruh mutasi anggota memakai lock baris household, transaksi dan audit atomik.
Penghapusan anggota bersifat status removed; ID serta histori dipertahankan.
Perubahan role/status berlaku pada request selanjutnya tanpa menerbitkan token baru.
Mutasi keanggotaan dibatasi 20/menit per user. Konflik state/replay memakai
`409 STATE_CONFLICT`; validasi 422, role tidak berhak 403, belum login 401.

**Integrasi Vue:** reset menuju `/reset-password`, undangan `/undangan`, verifikasi
menuju `/verifikasi-email?url=<signed-relative-api-url>`. Semua halaman sudah
tersambung. Tautan undangan/verifikasi dipertahankan saat login/register; URL
lanjutan hanya menerima route lokal yang diizinkan. Signed API URL tetap diperiksa
server dan wajib milik akun yang sedang login. Email nyata masih memerlukan mailer.
Ruang keluarga `/keluarga` memakai data backend, sedangkan halaman keuangan lama
bermula di `/pratinjau` dan masih memakai data contoh terpisah.

## Dompet dan saldo awal — tahap 4a

Semua route di bawah wajib login dan membership aktif pada household nonarsip.
Semua anggota aktif dapat membaca; hanya Owner/Admin dapat membuat/mengubah dompet.

| Metode | Path setelah `/api/v1` | Perilaku |
|---|---|---|
| GET | `/households/{h}/wallets` | Daftar dompet beserta balance string; `status=all/active/archived`, `q`, page/per_page |
| POST | `/households/{h}/wallets` | Buat dompet, optional saldo awal; header Idempotency-Key UUID wajib |
| GET | `/households/{h}/wallets/{id}` | Detail termasuk saldo dan status arsip |
| PATCH | `/households/{h}/wallets/{id}` | Edit name/type/is_archived; tidak dapat mengubah saldo |
| GET | `/households/{h}/wallets/{id}/movements` | Riwayat ledger, `from`/`to` tanggal inklusif, page/per_page |

Contoh POST:
```http
Idempotency-Key: 7cd282aa-cb2a-47f7-b70e-709306f86728
```
```json
{"name":"BCA keluarga","type":"bank","opening_amount":"1500000.00","opening_on":"2026-10-16"}
```
Jenis: cash/bank/ewallet/other, nama 1–120 karakter. Opening amount opsional
(default `"0.00"`); wajib string nonnegatif dengan tepat dua desimal, tanpa eksponen,
pemisah ribuan, atau nol di depan. Maksimal `"99999999999999999.99"` (numeric 19,2).
Tanggal opsional, default hari ini pada timezone keluarga; tanggal masa depan ditolak.
Body tidak menerima balance, household_id, created_by_member_id atau is_archived
saat create. Household selalu diambil dari URL dan creator dari membership login.

Respons 201: `data` dompet (id, household_id, name, type, is_archived,
created_by_member_id, timestamps, balance string) dan `warnings: []`.
Opening >0 membuat satu operation type opening + satu movement positif + proyeksi
saldo + audit, atomik bersama dompet. Opening nol membuat proyeksi nol tanpa operasi.
Saldo awal bukan income. Tanggal dan nilai saldo awal tidak dapat diedit melalui PATCH.

Idempotency key unik per household, termasuk dompet tanpa saldo awal. Key dan payload
sama mengembalikan respons 201 original, bahkan jika metadata dompet sudah berubah;
key sama dengan payload/endpoint lain menghasilkan `409 IDEMPOTENCY_CONFLICT`.
Default opening amount dianggap `"0.00"`; opening_on yang tidak diberikan tetap
penanda default dalam fingerprint agar retry lintas hari tidak menggandakan dompet.
Hak akses diperiksa ulang sebelum replay. Request concurrent diserialkan dengan
lock household; proyeksi saldo juga dikunci saat posting opening.

List default mencakup dompet aktif dan arsip; arsip tidak menghapus saldo/riwayat.
`q` adalah substring literal case-insensitive, bukan pola SQL. Per_page default 20,
maksimal 100. Mutasi urut tanggal lalu ID descending; semua amount_signed berupa
string desimal. Resource asing/nonaktif mengembalikan 404, role salah 403,
validasi 422 dan tanpa login 401.

Ledger wallet tahap ini menggunakan foreign key komposit household untuk wallet,
operation dan actor. UPDATE/DELETE movement dan receipt ditolak trigger PostgreSQL;
balance projection tidak boleh negatif. Skema goal/reversal/link fee akan ditambah
pada tahapnya, bukan endpoint yang sudah tersedia saat ini. Tidak ada API untuk
menghapus ledger atau memperbaiki proyeksi secara langsung.

Pemeriksaan konsistensi read-only: `./bin/php artisan wallets:reconcile`, optional
`--household=ID`. Menghitung semua movements termasuk wallet arsip; exit 0 sesuai,
exit 1 jika selisih/proyeksi hilang. Tidak otomatis memperbaiki saldo.

## Kategori dan posting transaksi — tahap 4b

Endpoint kategori dan operations GET/POST/detail di tabel berikut sudah tersedia.
Koreksi/void tersedia mulai tahap 4c di bawah; adjustment, budget, goals dan reports belum tersedia.
Frontend keuangan masih memakai fixture; endpoint ini belum dipanggil form lama.

- Kategori: GET mendukung `type=income/expense`, `status=all/active/archived`,
  page/per_page. POST menerima name (1–120 karakter) dan type; PATCH menerima
  name/is_archived. Owner/Admin menulis, semua anggota aktif membaca.
  Nama unik case-insensitive per household dan jenis, termasuk kategori arsip.
  Jenis kategori tidak dapat diganti; buat kategori baru jika jenis berbeda.
  Tidak ada hard delete atau kategori yang otomatis di-backfill ke keluarga lama.
- POST operations menerima income/expense/transfer sesuai contoh di bawah.
  occurred_on wajib, valid mulai 0001-01-01, tidak boleh melampaui hari ini pada
  timezone household. Nominal wajib string dua desimal, amount >0, fee >=0.
  Income/expense wajib wallet_id dan category_id aktif dengan jenis sesuai.
  Transfer wajib dua wallet berbeda; fee default `"0.00"`, fee_category_id wajib
  bila fee positif dan harus kategori expense aktif. Field jenis lain ditolak.
- Nominal fee disimpan sebagai komponen expense pada transfer_details, terhubung
  ke operation transfer. Ledger mendebit asal sebesar principal+fee dan mengkredit
  tujuan sebesar principal. Tidak membuat income/expense tambahan untuk principal.
  Reports nantinya membaca fee dari transfer_details; endpoint laporan belum ada.
- Respons POST 201 memuat data operation, movements, wallet_balances dan warnings.
  Semua nominal string. Warnings saat ini kosong karena modul budget belum dibuat.
  Hash internal/idempotency key tidak diekspos pada data operation.
- GET operations mendukung page/per_page (maks100), from/to inklusif,
  type, status, wallet_id, category_id, created_by_member_id, sort=asc/desc.
  Default tanggal lalu ID descending. Filter wallet mencakup sumber dan tujuan
  transfer; filter kategori mencakup kategori fee. Opening tetap terlihat.
  GET detail memuat data + movements, tanpa audit internal untuk Viewer.
- Lock household kemudian projection menurut wallet ID; seluruh operation,
  transfer_details, movements, projection, audit dan receipt satu transaksi.
  Kegagalan satu komponen membatalkan semuanya. Transfer_details append-only.
  Idempotency-Key UUID berbagi namespace dengan create wallet; replay respons
  original walau saldo atau status arsip berubah, tetapi izin diperiksa ulang.
- Saldo berjalan dan minimum saldo historis tidak boleh negatif, termasuk fee.
  Histori memakai **saldo akhir setiap tanggal**, karena occurred_on berupa date;
  semua original/reversal dijumlahkan, termasuk tanggal baru tanpa movement lama.
  Backdate sebelum opening atau yang menembus minimum tanggal berikutnya ditolak
  422 HISTORICAL_BALANCE_CONFLICT walau saldo saat ini mencukupi.
- Format/field invalid memakai VALIDATION_ERROR; nominal nol, negatif, atau format
  salah ditolak sebelum posting. Saldo/nominal hasil perhitungan di atas numeric(19,2)
  ditolak INVALID_MONEY_AMOUNT. Saldo kurang INSUFFICIENT_BALANCE, wallet arsip
  WALLET_ARCHIVED, kategori salah jenis/arsip INVALID_CATEGORY_TYPE.

## Koreksi dan pembatalan — tahap 4c

`POST /households/{h}/operations/{id}/correct` (201) dan `/void` (200) tersedia.
Keduanya wajib header Idempotency-Key UUID dan reason string 1–2000 karakter.
Correct wajib replacement lengkap; payload menggunakan aturan POST operations.
Field replacement yang tidak dikenal ditolak. Void tidak menerima replacement.

- Member boleh mengoreksi/void income, expense dan transfer miliknya sendiri,
  tanpa batas umur transaksi. Owner/Admin dapat pada operasi anggota lain.
  Viewer tidak dapat melakukan perubahan. Membership aktif selalu diperiksa ulang,
  termasuk replay. Resource keluarga lain disembunyikan dengan 404.
- Opening hanya dapat dikoreksi/void oleh Owner/Admin. Replacement opening memakai
  `type=opening`, wallet_id, amount positif, occurred_on dan optional note, tanpa
  category_id. Opening tidak dapat diganti menjadi income/expense atau sebaliknya.
  Untuk menghapus efek saldo awal sepenuhnya gunakan void, bukan amount nol.
- Original tidak dihapus. Tambahkan movement `entry_kind=reversal` dengan nominal
  persis kebalikan original, link reversal_of_id, dan tanggal efektif tetap tanggal
  operasi original. Waktu pencatatan reversal tetap waktu aktual UTC.
  Operation lama menjadi reversed; replacement menyimpan correction_of_id.
- DB menjamin satu reversal per original movement dan satu record reversal per
  operation; movement reversal harus sama wallet, household, operation, dan persis
  berlawanan nominalnya. Metadata alasan/pelaku reversal immutable.
- Validasi memakai hasil **gabungan** reversal + replacement pada seluruh dompet
  terkait, bukan saldo sementara di antara kedua langkah. Saldo akhir setiap tanggal
  dan saldo saat ini harus nonnegatif dan dalam batas numeric(19,2). Ini memungkinkan
  koreksi pemasukan yang sudah terpakai jika nominal/tanggal pengganti tetap aman.
  Memindahkan tanggal pemasukan melewati pengeluaran dapat ditolak meski saldo
  saat ini positif. Error negatif `HISTORICAL_BALANCE_CONFLICT`; overflow
  `INVALID_MONEY_AMOUNT`. Seluruh perubahan rollback jika satu langkah gagal.
- Void transaksi lama pada dompet/kategori arsip diperbolehkan jika saldo aman;
  replacement tetap wajib memakai wallet/kategori aktif. Fee transfer turut dibalik,
  transfer_details lama tidak diubah; replacement memiliki detail fee baru.
- Respons berisi data sesuai contoh kontrak, wallet_balances seluruh dompet terdampak,
  dan warnings (masih kosong hingga modul budget). GET detail menyertakan `reversal`
  null atau {created_by_member_id, reason, created_at, replacement_operation_id},
  serta movements original/reversal. Ini histori transaksi bersama, bukan audit
  internal. Operation pengganti menampilkan correction_of_id; daftar dapat memakai
  filter status=posted/reversed.
- Key/payload sama mengembalikan respons original dengan status sama; beda alasan,
  target operation, replacement atau endpoint menghasilkan IDEMPOTENCY_CONFLICT.
  Key baru pada operation yang sudah reversed menghasilkan 409 ALREADY_REVERSED.
  Koreksi berikutnya dilakukan terhadap ID replacement terbaru.
- Lock household lalu seluruh projection menurut ID, validasi, reverse, replacement,
  update projection, audit dan receipt dijalankan dalam satu transaksi.
  Pembatalan/koreksi concurrent hanya membalik original sekali.

Frontend belum terhubung ke endpoint ini. Budget/laporan belum menghitung ulang
melalui API karena modul tersebut belum tersedia; metadata reversal sudah siap.

## Integrasi frontend keuangan — tahap 4d

- `/` dan login tanpa next menuju `/dashboard`. Route Dashboard/Dompet/Transaksi
  memeriksa session `/me`; belum login menuju login, belum punya keluarga menuju
  `/keluarga`. Next hanya menerima route lokal dalam allowlist. Tautan undangan dan
  verifikasi tetap didahulukan. Ruang keluarga menyediakan **Buka Dashboard**.
- `/dashboard`, `/dompet`, `/transaksi` memakai data API keluarga aktif. Pergantian
  keluarga mengosongkan data lama dan mengabaikan respons terlambat. Pilihan keluarga
  disimpan per tab dan selalu divalidasi terhadap membership saat refresh.
- Dompet: create saldo awal, edit metadata/arsip/aktifkan, history paginated.
  Kategori: create/edit/arsip Owner/Admin. Transaksi: income, expense, transfer+fee,
  filter/paging, detail, koreksi dan pembatalan dengan alasan wajib. Kontrol Viewer
  hanya baca; keamanan tetap ditegakkan backend.
- Dashboard menampilkan saldo semua wallet, pemasukan bulan berjalan dan pengeluaran
  termasuk fee. Agregasi bulan membaca seluruh halaman operation status posted,
  tanggal berdasarkan timezone keluarga. Opening dan principal transfer tidak masuk
  income/expense; reversed tidak dihitung lagi dalam ringkasan ekonomi. Belum ada
  total aset goals atau laporan analytics backend pada tahap ini.
- Input nominal tanpa pemisah ribuan, menerima koma/titik desimal maksimal dua angka.
  API tetap menerima decimal string. Formatting/agregasi frontend memakai BigInt sen,
  tidak memakai Number untuk nilai uang.
- Posting memakai UUID Idempotency-Key. Snapshot request disimpan sementara dalam
  sessionStorage per akun sebelum dikirim; jika respons hilang, UI meminta retry
  payload/key yang sama, termasuk setelah reload. Snapshot dihapus saat sukses atau
  penolakan 4xx yang pasti; form tidak mengubah payload yang belum terkonfirmasi.
  Session autentikasi tetap cookie Sanctum, tidak ada bearer token di storage.
- Data contoh dipertahankan di `/pratinjau`, `/pratinjau/dompet` dan
  `/pratinjau/transaksi`; tidak dimigrasi ke keluarga pengguna. Budget/target/analisis
  mock lama tetap terpisah dan belum disertakan di navigasi keuangan aktif.

## Budget bulanan — tahap 5a (backend)

Semua endpoint memeriksa keluarga nonarsip dan membership aktif. Owner/Admin dapat
create/update/copy; semua anggota aktif termasuk Viewer dapat list/detail/report.
Mutasi memakai household lock, transaksi dan audit before/after. Budget tidak menulis
ledger atau memindahkan saldo. Header Idempotency-Key tidak diwajibkan untuk mutasi
budget; create/copy bulan yang sudah ada menghasilkan 409 BUDGET_ALREADY_EXISTS.

- GET `/budgets`: page/per_page (maks100), from/to inklusif berupa tanggal awal bulan;
  urut month_start descending. Item metadata tanpa lines; detail melalui GET `/{id}`.
- POST `/budgets`: month_start tanggal hari pertama bulan valid, lines 1–500, category_id
  unik per budget dan harus kategori expense aktif milik household. Limit positif
  string tepat dua desimal, maksimal numeric(19,2). Anggaran masa depan diperbolehkan.
- PATCH `/budgets/{id}`: wajib **seluruh daftar lines pengganti**. Kategori yang hilang
  dihapus dari rencana, bukan dari riwayat transaksi. Month_start immutable. Menurunkan
  limit di bawah pemakaian tidak ditolak. Kategori arsip yang sudah ada boleh tetap
  dipertahankan/diedit/dihapus; menambah kategori arsip baru ditolak.
- POST `/budgets/copy`: source_month harus lebih awal daripada target_month; keduanya
  hari pertama bulan. Sumber tidak ada 404, target ada 409. Seluruh kategori dan limit
  sumber disalin sebagai snapshot terpisah, termasuk kategori yang kemudian diarsipkan
  (status arsip tetap ditampilkan). Edit target tidak mengubah sumber. Tidak ada
  carry-over sisa/pemakaian, tidak ada proses salin otomatis. Pengeluaran yang memang
  sudah terjadi di bulan target tetap dihitung berdasarkan tanggalnya.
- GET `/budgets/{id}` menyertakan lines {category_id,category_name,is_archived,
  limit_amount,used_amount,remaining_amount,percent_used,status}. Remaining dapat negatif.
  Status safe (<80%), near_limit (>=80% dan <100%), reached (=100%), exceeded (>100%).
  Status memakai perbandingan decimal tepat; percent_used string dua desimal dipotong
  ke bawah agar 99,999% tidak tampil sudah 100%. Semua jumlah dihitung tanpa float.
- `totals` memuat limit_amount/used_amount/remaining_amount untuk kategori yang memiliki
  budget serta unbudgeted_amount. `unbudgeted_expenses` memuat kategori/nominal expense
  yang tidak punya line. Pengeluaran tanpa budget tetap sah.
- GET `/reports/budget-usage?month_start=YYYY-MM-01` mengembalikan format detail yang
  sama; jika belum ada budget, id null, lines kosong dan unbudgeted_expenses tetap ada.
- Pemakaian = operations expense status posted + fee transfer status posted dalam
  rentang [month_start, bulan berikutnya), semua wallet dan aktor. Opening, income,
  pokok transfer tidak dihitung. Kategori/wallet arsip tetap masuk histori. Status
  reversed menghilangkan efek ekonomi lama; hanya replacement aktif dihitung.
  Ledger saldo tetap menjumlah original + reversal, tanpa pengecualian ganda.

POST operation, correct dan void menghitung warning setelah perubahan atomik pada
bulan/kategori terdampak. `warnings` dapat berisi BUDGET_NEAR_LIMIT, BUDGET_REACHED,
BUDGET_EXCEEDED, masing-masing category_id, month_start, percent_used dan message.
Koreksi tanggal/kategori mengevaluasi kondisi akhir kedua bulan/kategori tanpa duplikat.
Warning tidak menolak transaksi jika saldo cukup; tidak ada notifikasi email.
Receipt idempotency menyimpan warning original sehingga replay tidak berubah setelah
budget diedit. GET usage selalu menampilkan pemakaian terbaru, termasuk koreksi/void
bulan lampau. Backend tidak menyimpan cache pemakaian yang bisa tertinggal.

Halaman Budget dan tampilan warning Vue belum diintegrasikan pada tahap ini;
frontend finance yang sudah ada tetap berfungsi. Berikutnya hubungkan layar Budget.

## Daftar endpoint
| Metode | Path (setelah `/api/v1`) | Minimal role | Tujuan |
|---|---|---|---|
| POST | `/auth/register` | Public | Buat akun |
| POST | `/auth/login` | Public | Autentikasi |
| POST | `/auth/logout` | Login | Logout |
| GET | `/me` | Login | Profil dan household membership |
| POST | `/households` | Login | Buat household, creator jadi Owner |
| GET | `/households` | Login | Household yang diikuti |
| GET/PATCH | `/households/{h}` | Viewer/Owner | Baca / ubah informasi |
| GET | `/households/{h}/members` | Viewer | Daftar anggota |
| PATCH | `/households/{h}/members/{member}` | Owner/Admin terbatas | Ubah role/status sesuai kebijakan |
| POST | `/households/{h}/ownership-transfer` | Owner | Transfer owner atomik |
| POST/GET | `/households/{h}/invitations` | Owner/Admin | Undang/List undangan |
| POST | `/invitations/accept` | Login verified | Terima token undangan |
| POST | `/households/{h}/invitations/{id}/revoke` | Owner/Admin | Cabut undangan |
| GET/POST | `/households/{h}/wallets` | Viewer / Owner/Admin | List/buat dompet |
| PATCH | `/households/{h}/wallets/{id}` | Owner/Admin | Edit/arsip dompet |
| GET/POST | `/households/{h}/categories` | Viewer / Owner/Admin | Kategori |
| PATCH | `/households/{h}/categories/{id}` | Owner/Admin | Edit/arsip kategori |
| GET/POST | `/households/{h}/operations` | Viewer / Member+ | Filter/buat transaksi |
| GET | `/households/{h}/operations/{id}` | Viewer | Detail + movements + audit yang diizinkan |
| POST | `/households/{h}/operations/{id}/correct` | Pembuat atau Admin/Owner | Koreksi atomik |
| POST | `/households/{h}/operations/{id}/void` | Pembuat atau Admin/Owner | Batalkan dengan reversal |
| GET/POST | `/households/{h}/goals` | Viewer / Owner/Admin | Goals |
| PATCH | `/households/{h}/goals/{id}` | Owner/Admin | Edit/arsip goal |
| POST | `/households/{h}/goals/{id}/contributions` | Member+ | Setor dana dari wallet |
| POST | `/households/{h}/goals/{id}/withdrawals` | Owner/Admin | Cairkan goal ke wallet |
| GET/POST | `/households/{h}/budgets` | Viewer / Owner/Admin | Anggaran bulanan |
| GET/PATCH | `/households/{h}/budgets/{id}` | Viewer / Owner/Admin | Detail/edit anggaran |
| POST | `/households/{h}/budgets/copy` | Owner/Admin | Salin dari bulan sebelumnya |
| GET | `/households/{h}/dashboard` | Viewer | Total aset/tersedia/goals, ringkasan |
| GET | `/households/{h}/reports/cashflow` | Viewer | Income expense by date/category |
| GET | `/households/{h}/reports/budget-usage` | Viewer | Budget vs pengeluaran |
| GET | `/households/{h}/audit-logs` | Owner/Admin | Audit internal |

`Member+` = Owner/Admin/Member. Endpoint operasi income/expense/transfer dan adjustment berbagi `POST /operations`; adjustment **khusus** Owner/Admin. Endpoint opening hanya otomatis saat pembuatan dompet (tidak boleh dipakai Member).

## Request/response inti
### 1. POST `/households/{h}/operations` — expense
```json
{
  "type": "expense", "amount": "150000.00", "wallet_id": 11,
  "category_id": 8, "occurred_on": "2026-10-15", "note": "Belanja dapur"
}
```
Response `201`:
```json
{
 "data": {"id": 801, "status": "posted", "type": "expense", "amount": "150000.00", "created_by_member_id": 33},
 "wallet_balances": [{"wallet_id": 11, "balance": "850000.00"}],
 "warnings": [{"code": "BUDGET_EXCEEDED", "category_id": 8, "percent_used": "105.00", "message": "Budget kategori terlampaui"}]
}
```
**Peringatan budget tidak membatalkan POST**. Expense saat saldo tidak cukup -> `422 INSUFFICIENT_BALANCE`.

### 2. POST `/households/{h}/operations` — income
```json
{"type":"income","amount":"5000000.00","wallet_id":11,"category_id":2,"occurred_on":"2026-10-15","note":"Gaji"}
```

### 3. POST `/households/{h}/operations` — transfer + fee
```json
{
 "type": "transfer", "source_wallet_id": 11, "destination_wallet_id": 12,
 "amount": "500000.00", "fee_amount": "2500.00", "fee_category_id": 9,
 "occurred_on": "2026-10-15", "note": "Transfer BCA ke DANA"
}
```
Response `201` illustrative:
```json
{
 "data": {"id":802,"type":"transfer","amount":"500000.00","fee_amount":"2500.00","status":"posted"},
 "movements": [
  {"wallet_id":11,"amount_signed":"-502500.00"},
  {"wallet_id":12,"amount_signed":"500000.00"}
 ],
 "warnings": []
}
```
Validation: wallet berbeda, household sama, aktif; `fee_amount >= 0`; fee kategori expense wajib jika fee > 0. Fee dihitung laporan sebagai expense kategori biaya admin pada tanggal transfer, namun nominal pokok tidak masuk income/expense.

### 4. POST `/households/{h}/operations/{id}/correct`
Header `Idempotency-Key`. Body mengirim **payload lengkap baru**, bukan PATCH nilai ledger. Contoh:
```json
{"reason":"Salah nominal","replacement":{"type":"expense","amount":"125000.00","wallet_id":11,"category_id":8,"occurred_on":"2026-10-15","note":"Belanja dapur"}}
```
Response `201`: `{"data":{"reversed_operation_id":801,"replacement_operation_id":803,"result":"corrected"},"warnings":[]}`.
Permission: Owner/Admin atau creator atas transaksi sendiri tanpa batas waktu. Koreksi `opening` dan goal-related bersifat Owner/Admin pada MVP. Backend revalidasi saldo dan semua titik saldo historis relevan; jika tidak valid `422 HISTORICAL_BALANCE_CONFLICT` dengan tidak ada mutasi parsial.

### 5. POST `/households/{h}/operations/{id}/void`
```json
{"reason":"Duplikat pencatatan"}
```
Response `200`: `{"data":{"reversed_operation_id":801,"status":"reversed"}}`. Original dan reversal tetap dapat diaudit; jangan double-exclude original dari saldo.

### 6. POST `/households/{h}/budgets` — buat bulan
```json
{
 "month_start": "2026-11-01",
 "lines": [{"category_id": 8, "limit_amount":"2500000.00"}, {"category_id": 9, "limit_amount":"100000.00"}]
}
```
### 7. POST `/households/{h}/budgets/copy` — salin bulan sebelumnya
```json
{"source_month":"2026-10-01","target_month":"2026-11-01"}
```
Response `201`: `{"data":{"id":71,"month_start":"2026-11-01","copied_from_budget_id":70,"lines_copied":6}}`. Jika tujuan sudah ada -> `409 BUDGET_ALREADY_EXISTS`. Perubahan budget target kemudian hanya memengaruhi target.

### 8. POST `/households/{h}/goals/{id}/contributions`
```json
{"wallet_id":11,"amount":"100000.00","occurred_on":"2026-10-15","note":"Tabungan liburan"}
```
Create operation `goal_contribution`: wallet -100000, goal +100000. Bukan expense. Withdrawal mirip dengan `destination_wallet_id` dan hanya Owner/Admin.

## Status dan kode error prioritas
| HTTP | code | Keterangan |
|---|---|---|
| 401 | UNAUTHENTICATED | Belum login |
| 403 | FORBIDDEN | Role tidak berhak |
| 404 | RESOURCE_NOT_FOUND | Tidak ada atau di luar household |
| 409 | IDEMPOTENCY_CONFLICT | Key dipakai untuk payload berbeda |
| 409 | ALREADY_REVERSED | Transaksi sudah dibalik |
| 409 | BUDGET_ALREADY_EXISTS | Bulan tujuan sudah memiliki budget |
| 422 | INVALID_MONEY_AMOUNT | Desimal negatif/format invalid |
| 422 | INSUFFICIENT_BALANCE | Saldo tidak cukup |
| 422 | HISTORICAL_BALANCE_CONFLICT | Koreksi membuat saldo bertanggal negatif |
| 422 | WALLET_ARCHIVED | Wallet tidak aktif |
| 422 | INVALID_CATEGORY_TYPE | Kategori tidak cocok |
| 422 | CROSS_HOUSEHOLD_REFERENCE | Relasi sumber daya lintas household (boleh dipetakan 404) |
| 429 | RATE_LIMITED | Terlalu banyak permintaan |

## Arsitektur backend & frontend
- Laravel: routes -> FormRequest -> Controller -> Service (transaksi/otorisasi) -> Repository (SQL) -> Eloquent/MySQL. Interfaces di-bind via Service Provider, transaction manager mengoordinasi ledger dan balance projection.
- Services: HouseholdService, InvitationService, WalletService, OperationPostingService, CorrectionService, BudgetService, GoalService, ReportService; domain validators + Policies.
- Vue 3 + TS + Pinia + Vue Router + Axios; `householdStore` mengatur context; `operationStore` mengelola mutations dan toast budget warning; route guards UX hanya pelengkap security backend.
- Unit tests: role permission, uang decimal, budget warning thresholds; integration tests: concurrent debit, transfer fee, reverse/correct idempotent, historical conflicts, cross-household access, budget copy, reconciling balance projections.

## Catatan lintas spesifikasi
- `POST /wallets` input `opening_amount` opsional, membuat wallet + opening operation + projection row secara atomik; bila nol cukup wallet tanpa ledger entry.
- `GET /reports/cashflow` menghitung expense fee transfer dari `transfer_details` dan reversal efektif, bukan sekadar `operations.type=expense`.
- Endpoint `/goals/.../contributions` dan `/withdrawals` merupakan penulisan operation melalui service ledger yang **sama** dengan `/operations`, bukan tabel saldo kedua.
- Password reset/email verification memakai mekanisme standar Laravel, throttled. CORS, secure cookies (jika session SPA), CSRF, backup, monitoring dan encrypted backups adalah prasyarat produksi.


## Target tabungan — tersedia tahap 5c

Semua endpoint memakai prefix `/api/v1/households/{h}` dan session/Bearer Sanctum.
Read untuk seluruh membership aktif. Create/update/arsip untuk Owner/Admin;
setoran untuk Owner/Admin/Member; penarikan dan koreksi/void Target hanya Owner/Admin.

- GET `/goals`: `page`, `per_page` maksimal 100, `status=all|active|archived`
  (default all), `q` pencarian nama. GET `/goals/{id}` mengembalikan satu target.
- POST `/goals`: `name` wajib maksimal 120, `target_amount` string positif
  `numeric(19,2)`, `target_date` opsional/null (YYYY-MM-DD). Target baru bersaldo nol.
- PATCH `/goals/{id}`: `name`, `target_amount`, `target_date` (null menghapus tanggal),
  `is_archived`. Tidak menerima saldo manual, household atau creator dari client.
  Menurunkan nominal target tidak memindahkan dana atau menghapus riwayat.
- Item goal: `id`, `household_id`, `name`, `target_amount`, `target_date`,
  `is_archived`, `created_by_member_id`, timestamps UTC, `balance`,
  `remaining_amount` (minimal nol), `percent_saved` string dua desimal dibulatkan
  turun, `is_reached` dihitung dari saldo saat ini. Nominal tersimpan boleh melewati
  target; target tercapai tidak otomatis diarsipkan. Penarikan dapat mengubah
  status tercapai kembali menjadi belum tercapai.
- GET `/goals/{id}/movements`: page/per_page/from/to, terbaru dahulu; termasuk
  original dan reversal, `operation_id`, `goal_id`, `amount_signed`, `entry_kind`,
  `reversal_of_id`, `type`, `status`, `occurred_on`, `note`, `created_by_member_id`.
- POST `/goals/{id}/contributions` dengan `Idempotency-Key: UUID`:
  `{"wallet_id":11,"amount":"100000.00","occurred_on":"2026-10-16","note":"Tabungan"}`.
- POST `/goals/{id}/withdrawals` dengan header yang sama:
  `{"destination_wallet_id":11,"amount":"25000.00","occurred_on":"2026-10-16"}`.
  Tanggal tidak boleh masa depan menurut timezone keluarga. Nominal positif,
  dua desimal wajib; fee/kategori tidak berlaku pada perpindahan dana target.
- Keduanya 201 dengan detail operation/movements, `wallet_balances`,
  `goal_balances: [{goal_id,balance}]`, `warnings: []`. Receipt disimpan atomik;
  key sama mengulang respons asli, beda payload/target/endpoint menghasilkan 409.
  Hak akses terkini tetap diperiksa saat replay.
- Setoran: -wallet/+goal, penarikan: -goal/+wallet. Bukan income/expense/budget usage.
  Target atau wallet arsip menolak mutasi baru (`GOAL_ARCHIVED`/`WALLET_ARCHIVED`);
  aktifkan kembali sebelum setor/tarik. Arsip tidak menghapus dana yang sudah ada.
- `/operations` GET menerima filter `goal_id`, detail memuat `data.goal_id`;
  setiap movement berisi tepat salah satu `wallet_id` atau `goal_id`.
  Endpoint POST operasi biasa tetap hanya income/expense/transfer.
- POST `/operations/{id}/correct` menerima replacement lengkap dengan jenis Target
  yang sama: contribution memakai `goal_id` + `wallet_id`, withdrawal memakai
  `goal_id` + `destination_wallet_id`, serta amount/occurred_on/note. Goal/wallet
  pengganti boleh berbeda asalkan aktif dan dalam keluarga yang sama. Tidak bisa
  mengubah setoran menjadi penarikan atau menjadi income/expense.
- Koreksi/void memakai ledger reversal immutable dan receipt yang sama dengan
  operasi lain. Validasi menggabungkan reversal + replacement untuk seluruh
  wallet dan goal terdampak, termasuk minimum/maksimum saldo historis. Void tanpa
  replacement boleh memulihkan akun arsip jika seluruh validasi saldo terpenuhi.
- Semua mutasi mengunci household dahulu, kemudian wallet terurut, lalu goal
  terurut, dengan transaksi DB dan retry deadlock terbatas. Saldo saat ini maupun
  historis tidak boleh negatif atau melewati numeric(19,2). Audit, ledger,
  proyeksi dan receipt rollback bersama jika salah satu gagal.
- `./bin/php artisan goals:reconcile [--household=ID]` membandingkan proyeksi
  dengan seluruh movements termasuk reversal, tidak mengubah data. Rekonsiliasi
  wallet tetap menggunakan `wallets:reconcile`.

Integrasi UI tahap 5d: `/target` memakai FinanceView dengan session/household aktif.
Create/edit/arsip, setoran/penarikan, history pagination, serta koreksi/void memakai
API di atas. Snapshot request dan UUID tersimpan per user/tab sebelum penulisan
ledger; setelah koneksi terputus, retry memakai request asli, termasuk sesudah reload.
Metadata create tanpa receipt memuat ulang daftar saat hasil tidak terkonfirmasi
agar pengguna dapat memeriksa target sebelum membuat ulang.
Dashboard dan Target menampilkan total aset wallet + goals (termasuk arsip), dana
Target dan saldo tersedia secara terpisah; setoran/penarikan tidak masuk cashflow.
Detail transaksi menampilkan pergerakan wallet dan goal beserta label yang sesuai;
filter transaksi mendukung setoran/penarikan Target. Contoh Target tetap terpisah
di `/pratinjau/target`. Analytics masih tahap berikutnya.


## Laporan Analisis — backend tersedia tahap 5e

GET `/api/v1/households/{h}/reports/cashflow?from=2026-01-01&to=2026-01-31`
memerlukan session/Bearer dan membership aktif (termasuk Viewer). `from`/`to`
wajib YYYY-MM-DD, inklusif, minimal tahun 0001; to tidak sebelum from, maksimal
366 hari per request. `wallet_id` opsional harus milik household, termasuk arsip;
wallet asing/tidak ditemukan menghasilkan 404. Rentang tanpa transaksi tetap 200.
Rentang masa depan diperbolehkan, namun tidak memiliki posting masa depan.

Respons `data`:
- `household_id`, `from`, `to`, `wallet_id` (null = seluruh wallet), `timezone`.
- `totals`: `income_amount`, `expense_amount`, `net_amount` (income minus expense),
  `fee_amount` (bagian dari expense, jangan dijumlahkan lagi), `income_count`,
  `expense_count`, `net_percent_of_income` (string dua desimal, null jika income nol;
  dapat negatif ketika defisit). Persentase dibulatkan menuju nol, bukan float.
- `daily`: setiap tanggal inklusif secara ascending, `date` beserta field totals
  kecuali persentase; hari kosong bernilai nol.
- `monthly`: setiap bulan yang disentuh rentang, `month=YYYY-MM`, beserta field
  totals kecuali persentase. Bulan pertama/terakhir hanya menghitung tanggal dalam
  filter; bukan otomatis seluruh bulan.
- `categories.income` dan `categories.expense`: array berurutan amount terbesar,
  tie diurutkan category_id; setiap item berisi `category_id`, `category_name`,
  `is_archived`, `amount`, `fee_amount`, `entry_count`, `percent`. Percent merupakan
  bagian dari total jenis yang sama, dibulatkan turun dua desimal sehingga jumlah
  persentase kategori dapat kurang dari 100.00. Array kosong jika tidak ada data.

Amount/sum selalu string desimal dua digit, termasuk total yang melampaui batas
nominal satu transaksi; count berupa integer. Semua series, kategori dan total
berasal dari satu query agregasi agar tidak mencampur snapshot di tengah posting.
Respons `warnings: []`, `Cache-Control: no-store`.

Aturan penghitungan:
- Hanya income/expense berstatus posted dan fee positif transfer posted. Transaksi
  reversed dikeluarkan secara ekonomi; tidak mengurangi reversal sekali lagi.
- Saldo opening, pokok transfer, kontribusi/penarikan goal dan adjustment tidak
  termasuk cashflow. Net laporan bukan perubahan saldo wallet ataupun total aset.
- Filter wallet menghitung income/expense wallet tersebut. Fee transfer hanya
  masuk dompet asal, tidak ikut terhitung pada dompet tujuan.
- `expense_count` menghitung expense biasa + transfer dengan fee positif;
  transfer tanpa fee tidak menambah count. `entry_count` kategori mengikuti aturan
  yang sama, bukan jumlah semua baris ledger.
- Arsip kategori/dompet tidak menghapus transaksi dari laporan. Nama/status kategori
  mengikuti metadata terkini. Koreksi mengganti tanggal/wallet/kategori yang efektif;
  void menghapus efeknya pada request laporan berikutnya.

Integrasi UI tahap 5f: `/analisis` memakai session/household aktif. Filter from/to
(default tanggal 1 hingga hari ini dalam timezone keluarga) dan dompet diterapkan
melalui tombol Tampilkan laporan. Judul periode selalu memakai respons API, bukan
filter yang belum diterapkan. Muat ulang juga memperbarui metadata dompet.
Grafik harian/bulanan dilengkapi tabel nominal dan rincian kategori kedua jenis.
Nilai uang tetap decimal-string/BigInt; konversi Number hanya untuk ukuran grafik.
Pada pergantian keluarga, filter wallet dan data lama dibersihkan; respons lama
diabaikan berdasarkan generation. Kegagalan jaringan/validasi menampilkan pesan
dan tidak mempertahankan ringkasan lama. Viewer dapat memakai seluruh filter.
Contoh Analisis dipisahkan ke `/pratinjau/analisis`. Tidak ada perubahan backend
atau migrasi pada integrasi UI ini.

### Integrasi halaman Anggota

`/anggota` menggunakan daftar members/invitations berhalaman, PATCH anggota dengan
alasan wajib, serta POST undangan/revoke. Owner/Admin melihat kontrol sesuai policy;
Member/Viewer tidak mengambil daftar undangan. Muat ulang menyegarkan `/me`, dan
pergantian household membersihkan state. Contoh UI dipindahkan ke `/pratinjau/anggota`.

### Integrasi Pengaturan Keluarga

`/pengaturan` membaca household dan members. Owner mengirim PATCH nama/zona waktu,
atau POST ownership-transfer dengan member_id dan reason setelah konfirmasi.
Frontend menyegarkan membership sesudah mutasi; Admin/Member/Viewer hanya membaca.
Pratinjau UI tersedia di `/pratinjau/pengaturan`.
