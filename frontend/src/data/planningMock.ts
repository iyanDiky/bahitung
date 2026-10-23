import { reactive } from "vue";
import { goals as initialGoals, budgets as initialBudgets } from "./mock";
import {
  expenseCategories,
  transactionRows,
  transactionWallets,
} from "./transactionMock";
import { cents, decimal } from "../utils/money";
export interface BudgetLine {
  category: string;
  limit: string;
}
export interface SavingGoal {
  id: string;
  name: string;
  icon: string;
  target: string;
  balance: string;
  due: string;
}
export interface GoalMovement {
  id: string;
  goalId: string;
  walletId: string;
  type: "deposit" | "withdraw";
  amount: string;
  date: string;
  note: string;
}
// Owner-context fixtures only. No API, authentication, or persisted ledger.
export const monthlyBudgets = reactive<Record<string, BudgetLine[]>>({
  "2026-10": [
    ...initialBudgets.map((b) => ({
      category: b.name,
      limit: `${b.limit}.00`,
    })),
    { category: "Pendidikan", limit: "100000.00" },
    { category: "Lainnya", limit: "1000000.00" },
  ],
});
export const savingGoals = reactive<SavingGoal[]>(
  initialGoals.map((goal, index) => ({
    id: String(index + 1),
    name: goal.name,
    icon: goal.icon,
    target: `${goal.target}.00`,
    balance: `${goal.current}.00`,
    due: ["2027-12-01", "2028-07-01", "2028-12-01"][index],
  })),
);
export const goalMovements = reactive<GoalMovement[]>([]);
export function validMonth(month: string) {
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(month) || month.startsWith("0000"))
    throw new Error("Pilih bulan yang valid.");
}
export function previousMonth(month: string) {
  validMonth(month);
  const [year, number] = month.split("-").map(Number);
  return number === 1
    ? `${year - 1}-12`
    : `${year}-${String(number - 1).padStart(2, "0")}`;
}
export function validDate(date: string) {
  if (
    !/^\d{4}-\d{2}-\d{2}$/.test(date) ||
    !Number.isFinite(Date.parse(date)) ||
    new Date(date).toISOString().slice(0, 10) !== date
  )
    throw new Error("Tanggal tidak valid.");
}
function positive(value: string) {
  const amount = cents(value);
  if (amount <= 0n) throw new Error("Nominal harus lebih dari nol.");
  return amount;
}
export function saveBudget(
  month: string,
  category: string,
  limit: string,
  editing = false,
) {
  validMonth(month);
  const amount = decimal(positive(limit));
  if (!expenseCategories.includes(category))
    throw new Error("Pilih kategori pengeluaran yang valid.");
  const lines = monthlyBudgets[month] ?? [];
  const existing = lines.find((line) => line.category === category);
  if (existing && !editing)
    throw new Error("Kategori ini sudah memiliki budget. Gunakan tombol edit.");
  if (editing && !existing) throw new Error("Budget kategori tidak ditemukan.");
  if (existing) existing.limit = amount;
  else monthlyBudgets[month] = [...lines, { category, limit: amount }];
}
export function copyBudget(month: string) {
  validMonth(month);
  if (monthlyBudgets[month])
    throw new Error(
      "Budget bulan tujuan sudah ada. Salin hanya ke bulan yang belum memiliki budget.",
    );
  const source = monthlyBudgets[previousMonth(month)];
  if (!source?.length)
    throw new Error("Belum ada budget pada bulan sebelumnya untuk disalin.");
  monthlyBudgets[month] = source.map((line) => ({ ...line }));
}
export function spending(month: string, category?: string) {
  return transactionRows
    .filter(
      (row) =>
        row.type === "expense" &&
        row.date.startsWith(`${month}-`) &&
        (!category || row.category === category),
    )
    .reduce((sum, row) => sum + cents(row.amount), 0n);
}
export function progress(amount: bigint, target: bigint) {
  return target > 0n ? Number((amount * 1000n) / target) / 10 : 0;
}
export function budgetStatus(
  spent: bigint,
  limit: bigint,
): "normal" | "warning" | "reached" | "exceeded" {
  return spent > limit
    ? "exceeded"
    : spent === limit
      ? "reached"
      : spent * 100n >= limit * 80n
        ? "warning"
        : "normal";
}
export function saveGoal(input: {
  id?: string;
  name: string;
  target: string;
  due: string;
  icon: string;
}) {
  const name = input.name.trim();
  if (!name || name.length > 60)
    throw new Error("Nama target wajib diisi, maksimal 60 karakter.");
  const target = decimal(positive(input.target));
  if (input.due) validDate(input.due);
  const existing = savingGoals.find((goal) => goal.id === input.id);
  if (input.id && !existing) throw new Error("Target tidak ditemukan.");
  if (existing) Object.assign(existing, { name, target, due: input.due });
  else
    savingGoals.push({
      id: crypto.randomUUID(),
      name,
      target,
      due: input.due,
      icon: input.icon || "🎯",
      balance: "0.00",
    });
}
export function moveGoal(input: Omit<GoalMovement, "id">) {
  const goal = savingGoals.find((item) => item.id === input.goalId);
  const wallet = transactionWallets.find((item) => item.id === input.walletId);
  if (!goal || !wallet) throw new Error("Pilih target dan dompet yang valid.");
  if (wallet.archived)
    throw new Error(
      "Dompet diarsipkan tidak dapat dipakai untuk setoran atau penarikan baru.",
    );
  if (!["deposit", "withdraw"].includes(input.type))
    throw new Error("Jenis perpindahan dana tidak valid.");
  const amount = positive(input.amount);
  validDate(input.date);
  if (input.note.length > 100)
    throw new Error("Catatan maksimal 100 karakter.");
  const deposit = input.type === "deposit";
  if (deposit && amount > cents(wallet.balance))
    throw new Error("Saldo dompet tidak cukup untuk setoran ini.");
  if (!deposit && amount > cents(goal.balance))
    throw new Error("Dana target tidak cukup untuk penarikan ini.");
  const walletBalance = decimal(
    cents(wallet.balance) + (deposit ? -amount : amount),
  );
  const goalBalance = decimal(
    cents(goal.balance) + (deposit ? amount : -amount),
  );
  cents(walletBalance);
  cents(goalBalance);
  const movement = {
    ...input,
    amount: decimal(amount),
    id: crypto.randomUUID(),
  };
  wallet.balance = walletBalance;
  goal.balance = goalBalance;
  goalMovements.unshift(movement);
}
