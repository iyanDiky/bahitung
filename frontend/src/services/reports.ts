export interface CashflowTotals {
  income_amount: string;
  expense_amount: string;
  net_amount: string;
  fee_amount: string;
  income_count: number;
  expense_count: number;
}
export interface CashflowCategory {
  category_id: number;
  category_name: string;
  is_archived: boolean;
  amount: string;
  fee_amount: string;
  entry_count: number;
  percent: string;
}
export interface CashflowReport {
  household_id: number;
  from: string;
  to: string;
  wallet_id: number | null;
  timezone: string;
  totals: CashflowTotals & { net_percent_of_income: string | null };
  daily: (CashflowTotals & { date: string })[];
  monthly: (CashflowTotals & { month: string })[];
  categories: { income: CashflowCategory[]; expense: CashflowCategory[] };
}
