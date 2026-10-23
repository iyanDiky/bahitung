# Aturan Bisnis RumahFin v3 — baseline keputusan
Tanggal: 15 Oktober 2026 | Mata uang: IDR | Waktu bisnis: timezone household (default Asia/Makassar)

## Keputusan final dari review
- Model B multi-user. User dapat bergabung dalam lebih dari satu household dan memilih konteks aktif.
- Opsi A: semua dompet milik household dan terlihat oleh seluruh anggota aktif; **tidak ada dompet privat MVP**.
- Member boleh mengoreksi / void transaksi **buatannya sendiri kapan saja**; tidak ada limit 24 jam atau approval, tetapi validasi saldo dan audit wajib.
- Jika penggunaan budget melebihi limit, **transaksi tetap diperbolehkan** selama saldo cukup; API mengembalikan peringatan (termasuk ketika 80%, 100%, dan >100%).
- Budget bulan berikutnya dibuat **melalui aksi eksplisit 'salin bulan sebelumnya' lalu edit**, bukan otomatis.
- Biaya admin transfer diinput **di form transfer yang sama**, dicatat sebagai komponen expense terkait transfer, dan dihitung dalam budget kategori biaya admin bila dipilih.

## 1. RBAC — per household
| Aksi | Owner | Admin | Member | Viewer |
|---|---|---|---|---|
| Baca seluruh data keuangan termasuk semua dompet | Ya | Ya | Ya | Ya |
| Buat income, expense, transfer | Ya | Ya | Ya | Tidak |
| Koreksi/void operasi sendiri | Ya | Ya | Ya | Tidak |
| Koreksi/void operasi orang lain | Ya | Ya | Tidak | Tidak |
| Buat/edit/arsip dompet/kategori | Ya | Ya | Tidak | Tidak |
| Adjustment saldo | Ya | Ya | Tidak | Tidak |
| Buat/edit budget dan goal | Ya | Ya | Tidak | Tidak |
| Setor goal | Ya | Ya | Ya | Tidak |
| Tarik dana goal | Ya | Ya | Tidak | Tidak |
| Undang Member/Viewer & ubah role Member/Viewer | Ya | Ya | Tidak | Tidak |
| Mengubah Admin/Owner; transfer ownership; arsip household | Ya | Tidak | Tidak | Tidak |

Owner harus tepat satu anggota aktif. Admin tidak boleh mengubah Admin lain. User yang dihapus dari household tidak kehilangan rekam jejak transaksi yang dibuat sebelumnya. Setiap request memvalidasi user + membership active + household_id + resource household.

## 2. Aturan posting dan ledger
- Semua jumlah berupa string desimal dengan dua digit, disimpan DECIMAL(19,2); transaksi tidak menggunakan float.
- Setiap operasi uang menggunakan idempotency key per household; key sama + payload sama -> replay respons, key sama + payload berbeda -> 409.
- Endpoint mutasi dijalankan dalam transaksi MySQL. Lock saldo wallet/goal yang terlibat dalam urutan ID konsisten untuk mengurangi deadlock; retry deadlock terbatas.
- `balance_movements` append-only: **saldo efektif = SUM semua movements original dan reversal**. `operations.status=reversed` bukan alasan mengecualikan movements asli karena sudah ada movements penyeimbang. Mengurangi berdasarkan status sekaligus menambah reversal akan salah (double reverse).
- `wallet_balances` / `goal_balances` hanyalah proyeksi locking; updated dalam transaksi yang sama dengan ledger; dapat direkonsiliasi dengan SUM movements.
- Tidak boleh wallet atau goal balance negatif; past-dated correction divalidasi terhadap saldo berjalan dan minimum saldo kumulatif pada setiap tanggal setelah kejadian (atau tolak jika histori tidak bisa diverifikasi).
- Saldo awal wallet dicatat via opening (+wallet), **bukan** sebagai income. Adjustment +/- hanya Owner/Admin dengan alasan wajib, **bukan** income/expense.
- Soft archive wallet jika punya histori; tidak boleh dipakai transaksi baru. Data ledger tidak dapat hard delete.

## 3. Operasi, entry, dan laporan
| Tipe | Mutasi ledger | Komponen laporan |
|---|---|---|
| opening | +wallet | Bukan income |
| income | +wallet | Income, kategori income |
| expense | -wallet | Expense, kategori expense |
| transfer tanpa biaya | -wallet asal; +wallet tujuan | Bukan income/expense |
| transfer dengan biaya | -wallet asal sebesar transfer+fee; +wallet tujuan sebesar transfer | Expense hanya fee, kategori fee expense |
| goal_contribution | -wallet; +goal | Bukan income/expense |
| goal_withdrawal | -goal; +wallet | Bukan income/expense |
| adjustment | +/-wallet | Koreksi saldo, terpisah dari income/expense |
| reversal | mutasi penyeimbang exact opposite | Menghapus efek ekonomi transaksi lama; laporan net mengabaikan operasi yang sudah dibalik lewat pasangan original+reversal |

Transfer fee harus mempunyai kategori expense aktif milik household jika fee > 0. `transfer_details` menyimpan sumber, tujuan, nilai pokok, dan fee. Dashboard: total aset = SUM(wallet_balances)+SUM(goal_balances); saldo tersedia = SUM(wallet_balances), dana goals = SUM(goal_balances). Transfer fee menurunkan total aset sebesar fee.

## 4. Koreksi, void, dan histori
- Seluruh Member boleh koreksi/void **operasi miliknya sendiri kapan pun**; Owner/Admin dapat pada operasi milik siapa pun. Adjustment hanya boleh ditulis/diubah oleh Owner/Admin.
- Koreksi merupakan satu DB transaction: cek hak akses + validasi ulang kondisi setelah pembatalan dan pengganti → tambahkan movement reversal per movement lama, tandai operasi lama `reversed`, buat operasi baru `correction_of_id` → posting movement baru → audit kedua peristiwa.
- Void tanpa pengganti mengerjakan tahapan sama tetapi tanpa posting operasi baru. Dilarang reversal ganda.
- Reversal transaksi historis dapat ditolak ketika membuat saldo pada titik waktu mana pun negatif. Berikan 422 dengan alasan dan petunjuk penyesuaian melalui Admin jika diperlukan.
- Untuk MVP, koreksi `opening` dan goal movement yang sudah memiliki dependensi kompleks memerlukan Owner/Admin (keputusan guardrail teknis agar histori terjaga); Member dapat mengoreksi income/expense/transfer buatannya sendiri kapan pun. Ini tidak menambah batas waktu.

## 5. Budget
- Per household + month_start unik; limit per kategori expense unik per budget; nominal >0.
- Penggunaan budget = jumlah *net posted economically effective expense* yang bertanggal dalam bulan itu, termasuk biaya transfer, dikelompokkan kategori. Semua wallet dan anggota dihitung bersama.
- Reversal dan koreksi tanggal lampau harus memperbarui laporan bulan terdampak. Overbudget tetap boleh diposting; mengembalikan `warnings` dengan level 80%, 100%, atau exceeded. Anggaran bukan validasi saldo.
- Salin budget adalah aksi eksplisit dan atomik `POST .../budgets/copy`: sumber bulan terdahulu, tujuan bulan belum ada; salin baris, lakukan edit setelahnya. Bila tujuan sudah ada -> 409. Tidak mengubah budget sumber. Carry-over saldo budget tidak ada pada MVP.
- Pengeluaran tanpa budget tetap boleh. Kategori baru tidak otomatis dibuatkan budget.

## 6. Undangan, privasi, dan keamanan
- Undangan berbasis email terverifikasi; token acak hanya tersimpan hash, expired dalam 7 hari, single-use/revocable, hanya Member/Viewer bisa diundang via alur MVP.
- Seluruh endpoint selalu memeriksa household membership aktif. Hindari enumerasi ID antar-household (404 lebih aman untuk sumber yang bukan milik household).
- Role switch memperbarui izin seketika; Vue perlu menghapus cache query household sebelumnya.
- Audit append-only menyimpan actor, waktu UTC, alasan, before/after untuk perubahan penting; rahasia/token tidak dicatat.
- Penghapusan/arsip household tidak menghapus data ledger; kebijakan retensi dan unduh data perlu diputuskan sebelum produksi.

## 7. Kasus penerimaan kritis
1. Dua anggota mengirim expense bersamaan ke wallet Rp100.000 masing-masing Rp80.000: hanya satu berhasil.
2. Transfer Rp500.000 fee Rp2.500: debit Rp502.500, kredit Rp500.000, expense laporan Rp2.500; total aset turun Rp2.500.
3. Member koreksi expense miliknya dua bulan lalu: histori audit jelas, budget bulan lama dihitung ulang, dan saldo historis tetap valid.
4. Member mencoba ubah transaksi orang lain -> 403; Viewer mencoba POST -> 403.
5. Budget Rp1.000.000 terpakai Rp1.050.000: transaksi sah bila saldo cukup, warning overbudget muncul.
6. Salin budget Oktober ke November: jumlah line tersalin; perubahan November tidak mengubah Oktober.
7. Pengguna household A mengirim wallet ID household B -> 404/422 tanpa menampilkan data household B.
8. Idempotency key dikirim ulang: tidak terjadi debit kedua.
