// Display-only fixtures. Persisted API amounts must use decimal strings.
export interface WalletMock {
  name: string;
  type: string;
  balance: number;
  color: string;
  icon: "wallet" | "bank" | "phone";
}
export interface BudgetMock {
  name: string;
  spent: number;
  limit: number;
  icon: string;
}
export interface GoalMock {
  name: string;
  current: number;
  target: number;
  icon: string;
}
export interface TransactionMock {
  date: string;
  name: string;
  category: string;
  amount: number;
  icon: string;
}
export interface SummaryMock {
  name: string;
  amount: number;
  icon: "wallet" | "income" | "expense";
  tone: string;
  trend: string;
}
export const formatIDR = (n: number) =>
  "Rp " +
  new Intl.NumberFormat("id-ID", { maximumFractionDigits: 0 }).format(n);
export const wallets: WalletMock[] = [
  {
    name: "Dompet Utama",
    type: "Tunai",
    balance: 3120000,
    color: "green",
    icon: "wallet",
  },
  {
    name: "BCA Keluarga",
    type: "Rekening Bank",
    balance: 4250000,
    color: "purple",
    icon: "bank",
  },
  {
    name: "GoPay",
    type: "E-Wallet",
    balance: 750000,
    color: "orange",
    icon: "phone",
  },
  {
    name: "Dana Pendidikan",
    type: "Tabungan",
    balance: 5000000,
    color: "pink",
    icon: "wallet",
  },
];
export const budgets: BudgetMock[] = [
  { name: "Makan", spent: 1450000, limit: 2000000, icon: "🍴" },
  { name: "Belanja Rumah", spent: 980000, limit: 1500000, icon: "🛒" },
  { name: "Transportasi", spent: 620000, limit: 1000000, icon: "🚙" },
  { name: "Hiburan", spent: 320000, limit: 500000, icon: "🎮" },
  { name: "Kesehatan", spent: 180000, limit: 500000, icon: "♥" },
];
export const goals: GoalMock[] = [
  { name: "Liburan ke Jepang", current: 2500000, target: 15000000, icon: "🗾" },
  { name: "Pendidikan Dito", current: 4000000, target: 30000000, icon: "🎓" },
  { name: "Renovasi Rumah", current: 4930000, target: 50000000, icon: "🏡" },
];
export const transactions: TransactionMock[] = [
  {
    date: "Hari ini",
    name: "Belanja bulanan",
    category: "Belanja Rumah",
    amount: -365000,
    icon: "🛒",
  },
  {
    date: "Hari ini",
    name: "Makan siang",
    category: "Makan",
    amount: -45000,
    icon: "🍴",
  },
  {
    date: "Kemarin",
    name: "Gaji bulan ini",
    category: "Pemasukan",
    amount: 6250000,
    icon: "💰",
  },
  {
    date: "Kemarin",
    name: "Beli buku Dito",
    category: "Pendidikan",
    amount: -120000,
    icon: "📚",
  },
  {
    date: "12 Okt",
    name: "Isi bensin",
    category: "Transportasi",
    amount: -100000,
    icon: "🚙",
  },
];

export const walletTotal = wallets.reduce(
  (total, wallet) => total + wallet.balance,
  0,
);
export const goalTotal = goals.reduce((total, goal) => total + goal.current, 0);
export const expenseTotal = 4320000;
export const summaries: SummaryMock[] = [
  {
    name: "Total Aset Keluarga",
    amount: walletTotal + goalTotal,
    icon: "wallet",
    tone: "mint",
    trend: "+12,5%",
  },
  {
    name: "Saldo Tersedia",
    amount: walletTotal,
    icon: "wallet",
    tone: "blue",
    trend: "+8,1%",
  },
  {
    name: "Pemasukan Bulan Ini",
    amount: 6250000,
    icon: "income",
    tone: "mint",
    trend: "+12,0%",
  },
  {
    name: "Pengeluaran Bulan Ini",
    amount: expenseTotal,
    icon: "expense",
    tone: "rose",
    trend: "−6,8%",
  },
];
export const expenseCategories = [
  { n: "Makan", amount: 1450000, p: "33,6%" },
  { n: "Belanja Rumah", amount: 980000, p: "22,7%" },
  { n: "Transportasi", amount: 620000, p: "14,4%" },
  { n: "Hiburan", amount: 320000, p: "7,4%" },
  { n: "Kesehatan", amount: 180000, p: "4,2%" },
  { n: "Lainnya", amount: 770000, p: "17,8%" },
];
