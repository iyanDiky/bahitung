import { reactive } from "vue";
import {
  transactionWallets,
  transactionRows,
  walletName,
} from "./transactionMock";
import { goalMovements, savingGoals, validDate } from "./planningMock";
import { canManageMembers } from "./householdMock";
import { cents, decimal } from "../utils/money";
export type WalletRecord = (typeof transactionWallets)[number];
export const walletTypes = ["Tunai", "Rekening Bank", "E-Wallet", "Tabungan"];
export const walletOpenings = reactive<
  { id: string; walletId: string; amount: string; date: string }[]
>([]);
export function saveWallet(input: {
  id?: string;
  name: string;
  type: string;
  opening: string;
  date: string;
}) {
  if (!canManageMembers.value)
    throw new Error("Hanya Owner atau Admin yang dapat mengelola dompet.");
  const name = input.name.trim();
  if (!name || name.length > 60)
    throw new Error("Nama dompet wajib diisi, maksimal 60 karakter.");
  if (!walletTypes.includes(input.type))
    throw new Error("Pilih jenis dompet yang valid.");
  if (
    transactionWallets.some(
      (w) => w.id !== input.id && w.name.toLowerCase() === name.toLowerCase(),
    )
  )
    throw new Error("Nama dompet sudah digunakan.");
  if (input.id) {
    const wallet = transactionWallets.find((w) => w.id === input.id);
    if (!wallet || wallet.archived)
      throw new Error("Dompet tidak tersedia untuk diedit.");
    wallet.name = name;
    wallet.type = input.type;
  } else {
    const amount = decimal(cents(input.opening || "0"));
    validDate(input.date);
    const id = crypto.randomUUID();
    transactionWallets.push({
      id,
      name,
      type: input.type,
      balance: amount,
      archived: false,
    });
    if (cents(amount) > 0n)
      walletOpenings.unshift({
        id: crypto.randomUUID(),
        walletId: id,
        amount,
        date: input.date,
      });
  }
}
export function setWalletArchived(id: string, archived: boolean) {
  if (!canManageMembers.value)
    throw new Error("Hanya Owner atau Admin yang dapat mengelola dompet.");
  const wallet = transactionWallets.find((w) => w.id === id);
  if (!wallet) throw new Error("Dompet tidak ditemukan.");
  wallet.archived = archived;
}
export function walletHistory(id: string) {
  const rows: {
    id: string;
    date: string;
    title: string;
    detail: string;
    amount: bigint;
  }[] = [];
  for (const row of transactionRows) {
    if (row.walletId === id)
      rows.push({
        id: row.id,
        date: row.date,
        title: row.title,
        detail:
          row.type === "transfer"
            ? "Transfer ke " + walletName(row.destinationId)
            : row.linkedTransferId
              ? "Biaya admin terkait transfer"
              : row.category,
        amount: cents(row.amount) * (row.type === "income" ? 1n : -1n),
      });
    if (row.destinationId === id && row.type === "transfer")
      rows.push({
        id: row.id,
        date: row.date,
        title: row.title,
        detail: "Transfer dari " + walletName(row.walletId),
        amount: cents(row.amount),
      });
  }
  for (const row of goalMovements.filter((row) => row.walletId === id))
    rows.push({
      id: row.id,
      date: row.date,
      title: row.type === "deposit" ? "Setor dana target" : "Tarik dana target",
      detail:
        savingGoals.find((goal) => goal.id === row.goalId)?.name ?? "Target",
      amount: cents(row.amount) * (row.type === "deposit" ? -1n : 1n),
    });
  for (const row of walletOpenings.filter((row) => row.walletId === id))
    rows.push({
      id: row.id,
      date: row.date,
      title: "Saldo awal",
      detail: "Pembukaan dompet · bukan pemasukan",
      amount: cents(row.amount),
    });
  return rows.sort((a, b) => b.date.localeCompare(a.date));
}
