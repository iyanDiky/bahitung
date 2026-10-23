import { api } from "./api";
export { decimal } from "../utils/money";
export interface Wallet {
  id: number;
  name: string;
  type: string;
  balance: string;
  is_archived: boolean;
}
export interface Category {
  id: number;
  name: string;
  type: "income" | "expense";
  is_archived: boolean;
}
export interface Goal {
  id: number;
  name: string;
  target_amount: string;
  target_date: string | null;
  balance: string;
  remaining_amount: string;
  percent_saved: string;
  is_reached: boolean;
  is_archived: boolean;
}
export interface Operation {
  goal_id: number | null;
  id: number;
  type: string;
  status: string;
  amount: string;
  category_id: number | null;
  occurred_on: string;
  note: string | null;
  created_by_member_id: number;
  source_wallet_id: number | null;
  destination_wallet_id: number | null;
  fee_amount: string | null;
  fee_category_id: number | null;
  correction_of_id: number | null;
}
export interface Movement {
  goal_id?: number | null;
  id: number;
  operation_id: number;
  wallet_id: number | null;
  amount_signed: string;
  entry_kind: string;
  occurred_on?: string;
  type?: string;
  note?: string;
  reversal_of_id?: number | null;
}
export interface Detail {
  data: Operation;
  movements: Movement[];
  reversal: null | {
    reason: string;
    created_at: string;
    created_by_member_id: number;
    replacement_operation_id: number | null;
  };
}
export interface Page<T> {
  data: T[];
  meta: { current_page: number; last_page: number; total: number };
}
export async function all<T>(path: string): Promise<T[]> {
  const rows: T[] = [];
  for (let page = 1; ; page++) {
    const result = await api<Page<T>>(
      path + (path.includes("?") ? "&" : "?") + "per_page=100&page=" + page,
    );
    rows.push(...result.data);
    if (page >= result.meta.last_page) return rows;
  }
}
export function cents(value: string): bigint {
  return BigInt(value.replace(".", ""));
}
export function money(value: string): string {
  const amount = cents(value);
  const abs = amount < 0n ? -amount : amount;
  return (
    (amount < 0n ? "−" : "") +
    "Rp " +
    (abs / 100n).toLocaleString("id-ID") +
    (abs % 100n ? "," + (abs % 100n).toString().padStart(2, "0") : "")
  );
}
export function amountInput(value: string): string {
  const clean = value.trim().replace(",", ".");
  if (!/^(?:0|[1-9][0-9]{0,16})(?:\.[0-9]{1,2})?$/.test(clean))
    throw new Error(
      "Isi nominal tanpa pemisah ribuan, maksimal dua angka desimal. Contoh: 150000 atau 150000,50.",
    );
  const [whole, fraction = ""] = clean.split(".");
  return whole + "." + fraction.padEnd(2, "0");
}
export function businessDate(timezone: string): string {
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: timezone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts(new Date());
  return ["year", "month", "day"]
    .map((type) => parts.find((p) => p.type === type)!.value)
    .join("-");
}
export const labels: Record<string, string> = {
  income: "Pemasukan",
  expense: "Pengeluaran",
  transfer: "Transfer",
  opening: "Saldo awal",
  goal_contribution: "Setoran target",
  goal_withdrawal: "Penarikan target",
  cash: "Tunai",
  bank: "Rekening bank",
  ewallet: "E-wallet",
  other: "Lainnya",
};
