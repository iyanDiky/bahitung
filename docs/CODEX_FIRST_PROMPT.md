# Paste into VS Code Codex
Bantu implementasikan frontend **bahitung** dari workspace ini. Baca AGENTS.md, docs/DECISIONS.md, docs/BUSINESS_RULES.md dan lihat seluruh gambar di design/ terlebih dahulu. Branding final **bahitung**.

Tahap pertama:
1. Periksa frontend Vue yang sudah ada dan pastikan `npm install`, `npm run build` berhasil.
2. Perbaiki kesesuaian slicing halaman Dashboard dengan design/dashboard.png secara pixel-conscious, tanpa mengubah keputusan produk.
3. Pecah Dashboard menjadi komponen Vue kecil yang reusable: AppSidebar, AppHeader, SummaryCard, WalletList, BudgetProgressList, GoalsList, RecentTransactions, ExpenseChart.
4. Buat sidebar responsif (mobile drawer) dan semua states loading/empty/error visual.
5. Semua data sementara dari typed mocks; jangan mengarang endpoint Laravel.
6. Setelah Dashboard diterima, slicing halaman Transaksi berdasarkan design/transaksi-transfer.png termasuk dialog transfer dengan biaya admin linked expense, kemudian Budget+Goals dan Anggota.
7. Jalankan build, tampilkan hasil dan daftar file yang berubah tiap iterasi.

Ingat: saldo, transfer, goal, budget dan permission dari PRD v3 akan divalidasi server ketika backend dibuat. Jangan gunakan float untuk operasi uang yang akan dikirim ke backend.
