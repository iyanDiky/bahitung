# PRODUCT REQUIREMENTS DOCUMENT — RumahFin v3
**Produk:** RumahFin — Aplikasi Pencatatan Finansial Rumah Tangga | **Tanggal:** 15 Oktober 2026 | **Status:** Baseline final untuk desain MVP; implementasi belum dimulai.
**Referensi:** Demo Budggt, PRD RumahFin v2, keputusan review pengguna. Dokumen pendamping wajib: `database_RumahFin_v3.dbml`, `Aturan_Bisnis_RumahFin_v3.md`, `API_Contract_RumahFin_v3.md`.

## 1. Executive summary
RumahFin adalah web responsif untuk keuangan keluarga berbasis **multi-user household**. Satu orang dapat tergabung dalam banyak household. Setiap household mempunyai semua dompet bersama, transaksi, kategori, anggaran bulanan, tujuan tabungan, dan laporan. Keamanan aplikasi bergantung pada membership aktif dan role scoped per household.

## 2. Keputusan produk yang telah dikunci
| No | Keputusan | Dampak |
|---|---|---|
| 1 | Model B multi-user keluarga | Membership dan RBAC per household |
| 2 | Semua wallet milik household (opsi A) | Tidak ada privat wallet di MVP |
| 3 | Member boleh koreksi/void transaksi sendiri kapan saja | Reversal immutable, audit, validasi histori; tanpa approval |
| 4 | Overbudget tetap diizinkan dengan warning | Budget adalah guardrail, bukan hard-limit |
| 5 | Bulan berikutnya: salin lalu edit budget sebelumnya | Copy bersifat eksplisit, bukan job otomatis |
| 6 | Transfer fee dimasukkan dalam form transfer | Fee menjadi expense yang terhubung ke transfer |
| 7 | Saldo dompet/goal tidak negatif | Posting atomic, locked projections, historical validation |
| 8 | Vue 3 dipisah dari Laravel REST API | Kontrak JSON dan auth Sanctum |

## 3. Persona dan user journeys
- **Owner**: membuat household, mengelola anggota dan hak akses, meninjau seluruh finansial, melakukan ownership transfer.
- **Admin**: mengatur dompet, kategori, budget, goals; dapat memperbaiki transaksi semua anggota.
- **Member**: mencatat income, expense, transfer; mengoreksi transaksi sendiri tanpa batas waktu; memberi kontribusi goals.
- **Viewer**: melihat dashboard/dompet/budget/laporan tanpa kemampuan mutasi.

### Journeys
1. Daftar/login → buat household → menjadi Owner → buat wallet dengan saldo pembukaan → dashboard.
2. Owner/Admin undang email → penerima email verified accept token sekali pakai → member aktif → memilih household.
3. Member catat expense → saldo berkurang atomik → budget dihitung ulang; jika melebihi limit, transaksi sukses + warning.
4. Member transfer Rp500.000 dengan biaya Rp2.500 → wallet asal -502.500, wallet tujuan +500.000, expense fee Rp2.500.
5. Member koreksi transaksi sendiri yang dibuat bulan lalu → reversal dan operasi pengganti atomic → budget bulan terdampak terhitung ulang → audit terbentuk.
6. Owner/Admin menyalin budget Oktober ke November → edit budget November tanpa memengaruhi Oktober.
7. Member menyisihkan saldo ke goals → saldo tersedia berkurang, goals bertambah, total aset tetap.

## 4. Ruang lingkup
### MVP / P0
Auth register/login/logout/password reset/email verify; household create/list/switch; membership/invitation/RBAC; wallet shared & saldo opening; kategori expense/income; income/expense/transfer + linked fee; koreksi/void reversal; adjustment Owner/Admin; goals deposit/withdrawal; budget bulanan create/copy/edit; dashboard; laporan cashflow/budget usage; audit; responsive UI.
### P1
Lampiran struk, OCR dengan verifikasi manual, transaksi berulang, ekspor PDF/XLSX/CSV, notifikasi proaktif dan laporan detail per anggota.
### P2
AI Advisor, integrasi rekening, dompet pribadi dan visibility control, multi-currency, manajemen utang/piutang.
### Di luar cakupan
Transfer uang sungguhan, akses API bank, investasi, pengelolaan pajak dan akuntansi general ledger formal; Budggt adalah referensi UX, bukan kode yang disalin.

## 5. Fitur dan acceptance criteria
| Fitur | Acceptance criteria MVP |
|---|---|
| Login & household | Setiap anggota punya login sendiri; dapat ikut household A/B dengan isolasi mutlak |
| Member | Tepat satu Owner aktif per household, undangan hash+TTL+single-use |
| Wallet | Semua anggota aktif bisa lihat semua wallet; buka saldo lewat operation opening; saldo tidak negatif |
| Transactions | Ledger append-only; transfer dua mutasi, fee expense; idempotent dan atomic |
| Corrections | Member edit/void transaksi sendiri kapan pun; admin/owner bisa koreksi semua; tidak ada ledger delete |
| Budgets | Limit bulanan kategori expense, 80% warning, >=100% reached/exceeded; transaksi overbudget tetap sukses |
| Budget copy | Owner/Admin dapat copy budget bulan sebelumnya secara eksplisit lalu edit; mencegah duplikasi |
| Goals | Dana dikunci dalam bucket goal; kontribusi bukan expense; penarikan Admin/Owner |
| Dashboard | Total aset = available wallets + allocated goals; rincian transparan |
| Reports | Cashflow income/expense net, transfer principal dan goals tidak masuk; transfer fee adalah expense |
| Audit | Rekam aktor, perubahan, tanggal, referensi dan alasan untuk perubahan kritis |
| Security | Unauthorized 401; disallowed role 403; resource lintas household 404 |

## 6. Spesifikasi aturan domain
Dokumen `Aturan_Bisnis_RumahFin_v3.md` merupakan sumber detail. Ringkasan:
- Setiap request sumber daya selalu menyertakan household context (URL) dan policy check server-side.
- Perhitungan uang menggunakan decimal-string, MySQL `DECIMAL(19,2)`; tidak menggunakan JS floating point untuk akuntansi.
- Seluruh mutasi ledger append-only. Reversal membuat counter-movement; total saldo menghitung original + reversal, tidak memfilter movement asli lagi.
- Kegagalan pada salah satu movement -> seluruh posting rollback. Validasi negatif dilakukan pada saldo saat ini dan, untuk backdated corrections, lintasan saldo historis.
- Adjustment membutuhkan alasan dan hanya boleh Owner/Admin. Budget warning tidak menolak posting; insufficient available balance tetap ditolak.
- Pengeluaran transfer fee masuk kategori expense; kategorinya wajib bila fee positif.

## 7. Data dan relasi
Database desain `database_RumahFin_v3.dbml`: `users`, `households`, `household_members`, `household_invitations`, `wallets`, `categories`, `goals`, `operations`, `balance_movements`, `operation_reversals`, `wallet_balances`, `goal_balances`, `transfer_details`, `budgets`, `budget_lines`, `audit_logs`.

**Pembatasan integritas:** foreign keys + unique composites + household scoping; composite tenant foreign keys saat membuat migrations agar cross-household references mustahil; enforced balance checks dan append-only ledger melalui repository/service dan DB permissions; audit dan reconcile terjadwal. `wallet_balances` / `goal_balances` adalah cache saldo yang dikunci dan dapat direkonstruksi, bukan saldo manual.

## 8. Information architecture frontend
### Desktop
Sidebar: Dashboard, Transaksi, Dompet, Budget, Goals, Analisis; header: household switcher, periode, user menu. Tombol tambah transaksi persist di header. Onboarding & undangan punya halaman tersendiri.
### Mobile
Bottom navigation ringkas: Beranda, Transaksi, Dompet, Budget, Menu; form transaksi tampil sebagai layar penuh atau drawer, dengan validasi angka dan ringkasan dampak saldo/fee.
### Halaman inti
- Dashboard: total aset, saldo tersedia, alokasi goals, pemasukan/pengeluaran bulan, transaksi terbaru, budget warning.
- Transactions: filter tanggal, jenis, kategori, wallet, penulis; detail riwayat koreksi dan biaya transfer.
- Wallets: saldo dan mutasi, archive; tidak ada opsi privat wallet.
- Budget: bulan aktif, bar kategori, badge overbudget, aksi `Salin dari bulan lalu`, editor per kategori.
- Goals: progress, setor, penarikan role-controlled.
- Analisis: income vs expense bulanan, breakdown kategori, metrik biaya transfer.
- Members/settings: undangan, role, household, audit (Owner/Admin).

## 9. API & implementasi teknis
Kontrak normatif tersedia di `API_Contract_RumahFin_v3.md`.
- Frontend: Vue 3 + TypeScript, Vue Router, Pinia, Tailwind, Axios, ECharts.
- Backend: Laravel (REST), Service Layer + Repository Pattern + interfaces + Dependency Injection, FormRequest, Policies, Sanctum, MySQL 8.
- Semua logic saldo ditangani `OperationPostingService` dan `CorrectionService` (bukan controller atau store Vue).
- Datetime UTC, `occurred_on` berdasarkan timezone household, satu mata uang IDR dalam MVP.
- Database tx/locking, idempotency, activity audit, queues email; health checks dan structured logs.

## 10. Non-functional requirements
- Akurasi dan atomicity keuangan diutamakan di atas kecepatan UI.
- Target UX: input transaksi standar dalam <30 detik; p95 dashboard target <2 detik pada beban rumah tangga wajar (divalidasi melalui performance testing).
- Responsive 360px hingga desktop; keyboard-friendly, label jelas, visual warning bukan hanya warna.
- Password hashed, invite hashed, HTTPS production, CSRF jika cookie/session, CORS allowlist, rate limits, security policies, encrypted backups.
- Database restore dan ledger reconciliation harus diuji sebelum production go-live.

## 11. Tahapan delivery
1. **Foundation**: migration, seeded kategori default, Laravel auth, household dan RBAC, contracts.
2. **Finance core**: wallet+opening, ledger, income/expense/transfer+fee, projection locks, reversal/correction, integration tests.
3. **Budget & goals**: monthly copy, warning response, goal buckets.
4. **Frontend**: Vue views, household switching, forms, dashboards, responsive states.
5. **Hardening**: security review, concurrency tests, backdated correction tests, backup/reconciliation, deployment.

## 12. Definition of Done untuk MVP
- Tidak ada lintas-household data leakage dalam test otomasi.
- Ledger selalu reconciles dengan projection setelah posting, transfer, fee, void, correction, goals.
- Dua expense concurrent yang melampaui balance tak dapat keduanya sukses.
- Member correction sendiri kapan pun bekerja, orang lain 403.
- Transfer 500000 + fee 2500 mengurangi aset keluarga tepat 2500 dan menambah expense biaya 2500.
- Overbudget tetap sukses dengan warning; budget copy membuat snapshot mandiri.
- Desktop/mobile dapat menjalankan happy path lengkap; test dan basic runbook deployment tersedia.

## 13. Keputusan yang masih bersifat asumsi implementasi (tidak memblokir MVP)
- Single currency IDR; timezone default Asia/Makassar bisa diubah untuk household.
- Budget limit tidak carry over otomatis.
- Kategori biaya admin transfer perlu dipilih saat fee >0; kategori expense default dapat disediakan sistem.
- Viewer melihat seluruh wallet shared (sama dengan semua anggota), tetapi tidak melihat audit internal.
- Koreksi financial history yang menyebabkan saldo masa lalu negatif ditolak dan mengarahkan Owner/Admin melakukan rekonsiliasi.
- Owner/Admin-only untuk koreksi opening dan goal-related; koreksi transaksi biasa milik Member tetap bebas waktu.
