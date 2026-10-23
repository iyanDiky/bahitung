import { reactive } from "vue";
import { wallets } from "./mock";
import { cents, decimal, transferPreview } from "../utils/money";
export type Kind = "income" | "expense" | "transfer";
export interface TransactionRow {
  id: string;
  date: string;
  type: Kind;
  title: string;
  walletId: string;
  destinationId?: string;
  category: string;
  amount: string;
  author: string;
  linkedTransferId?: string;
  note?: string;
}
export interface TransferInput {
  source: string;
  destination: string;
  amount: string;
  fee: string;
  category: string;
  date: string;
  note: string;
}
export const expenseCategories = [
  "Makan",
  "Belanja Rumah",
  "Transportasi",
  "Hiburan",
  "Kesehatan",
  "Pendidikan",
  "Lainnya",
  "Biaya Transfer",
];
export const transactionWallets = reactive(
  wallets.map((wallet, index) => ({
    id: String(index + 1),
    name: wallet.name,
    balance: `${wallet.balance}.00`,
    type: wallet.type,
    archived: false,
  })),
);
export const transactionRows = reactive<TransactionRow[]>([
  {
    id: "1",
    date: "2026-10-15",
    type: "expense",
    title: "Makan siang",
    walletId: "1",
    category: "Makan",
    amount: "45000.00",
    author: "Ibu",
  },
  {
    id: "2",
    date: "2026-10-14",
    type: "transfer",
    title: "Dana pendidikan bulan Oktober",
    walletId: "2",
    destinationId: "4",
    category: "Transfer",
    amount: "500000.00",
    author: "Ayah",
  },
  {
    id: "3",
    date: "2026-10-14",
    type: "expense",
    title: "Belanja bulanan",
    walletId: "1",
    category: "Belanja Rumah",
    amount: "365000.00",
    author: "Ibu",
  },
  {
    id: "4",
    date: "2026-10-13",
    type: "income",
    title: "Gaji bulan Oktober",
    walletId: "2",
    category: "Gaji",
    amount: "6250000.00",
    author: "Ayah",
  },
  {
    id: "5",
    date: "2026-10-13",
    type: "expense",
    title: "Beli buku Dito",
    walletId: "4",
    category: "Pendidikan",
    amount: "120000.00",
    author: "Ibu",
  },
  {
    id: "6",
    date: "2026-10-12",
    type: "expense",
    title: "Isi bensin",
    walletId: "1",
    category: "Transportasi",
    amount: "100000.00",
    author: "Ayah",
  },
  {
    id: "7",
    date: "2026-10-11",
    type: "transfer",
    title: "Isi saldo GoPay",
    walletId: "2",
    destinationId: "3",
    category: "Transfer",
    amount: "1000000.00",
    author: "Ayah",
  },
  {
    id: "8",
    date: "2026-10-10",
    type: "expense",
    title: "Belanja dapur",
    walletId: "1",
    category: "Belanja Rumah",
    amount: "615000.00",
    author: "Ibu",
  },
  {
    id: "9",
    date: "2026-10-09",
    type: "expense",
    title: "Belanja bahan makanan",
    walletId: "1",
    category: "Makan",
    amount: "1405000.00",
    author: "Ibu",
  },
  {
    id: "10",
    date: "2026-10-08",
    type: "expense",
    title: "Transportasi keluarga",
    walletId: "2",
    category: "Transportasi",
    amount: "520000.00",
    author: "Ayah",
  },
  {
    id: "11",
    date: "2026-10-07",
    type: "expense",
    title: "Rekreasi keluarga",
    walletId: "3",
    category: "Hiburan",
    amount: "320000.00",
    author: "Ayah",
  },
  {
    id: "12",
    date: "2026-10-06",
    type: "expense",
    title: "Apotek",
    walletId: "1",
    category: "Kesehatan",
    amount: "180000.00",
    author: "Ibu",
  },
  {
    id: "13",
    date: "2026-10-05",
    type: "expense",
    title: "Keperluan keluarga",
    walletId: "2",
    category: "Lainnya",
    amount: "650000.00",
    author: "Ayah",
  },
]);
export function walletName(id?: string) {
  return transactionWallets.find((w) => w.id === id)?.name ?? "—";
}
// In-memory UI fixture only: no network, persistence, real transfer, or ledger posting.
export function recordMockTransfer(input: TransferInput) {
  const source = transactionWallets.find((w) => w.id === input.source);
  const destination = transactionWallets.find(
    (w) => w.id === input.destination,
  );
  if (!source || !destination || source.id === destination.id)
    throw new Error("Pilih dua dompet yang berbeda.");
  if (source.archived || destination.archived)
    throw new Error(
      "Dompet diarsipkan tidak dapat dipakai untuk transaksi baru.",
    );
  const preview = transferPreview(source.balance, input.amount, input.fee);
  if (cents(input.fee) > 0n && !expenseCategories.includes(input.category))
    throw new Error("Pilih kategori pengeluaran untuk biaya admin.");
  if (
    !/^\d{4}-\d{2}-\d{2}$/.test(input.date) ||
    !Number.isFinite(Date.parse(input.date)) ||
    new Date(input.date).toISOString().slice(0, 10) !== input.date
  )
    throw new Error("Tanggal transaksi tidak valid.");
  if (input.note.length > 100)
    throw new Error("Catatan maksimal 100 karakter.");
  const destinationBalance = decimal(
    cents(destination.balance) + cents(preview.amount),
  );
  cents(destinationBalance); // Enforce numeric(19,2) range before changing either wallet.
  const id = crypto.randomUUID();
  const rows: TransactionRow[] = [
    {
      id,
      date: input.date,
      type: "transfer",
      title: input.note || "Transfer antar dompet",
      walletId: source.id,
      destinationId: destination.id,
      category: "Transfer",
      amount: preview.amount,
      author: "Ayah",
      note: input.note,
    },
  ];
  if (cents(preview.fee) > 0n)
    rows.push({
      id: crypto.randomUUID(),
      date: input.date,
      type: "expense",
      title: "Biaya admin transfer",
      walletId: source.id,
      category: input.category,
      amount: preview.fee,
      author: "Ayah",
      linkedTransferId: id,
    });
  source.balance = preview.remaining;
  destination.balance = destinationBalance;
  transactionRows.unshift(...rows);
  return id;
}
