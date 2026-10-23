export interface BudgetWarning {
  code: string;
  month_start: string;
  category_id: number;
  percent_used: string;
  message: string;
}
export interface BudgetLine {
  category_id: number;
  category_name: string;
  is_archived: boolean;
  limit_amount: string;
  used_amount: string;
  remaining_amount: string;
  percent_used: string;
  status: "safe" | "near_limit" | "reached" | "exceeded";
}
export interface BudgetReport {
  id: number | null;
  month_start: string;
  lines: BudgetLine[];
  unbudgeted_expenses: {
    category_id: number;
    category_name: string;
    is_archived: boolean;
    used_amount: string;
  }[];
  totals: {
    limit_amount: string;
    used_amount: string;
    remaining_amount: string;
    unbudgeted_amount: string;
  };
}
export const budgetStatus = {
  safe: "Masih aman",
  near_limit: "Mendekati batas",
  reached: "Batas tercapai",
  exceeded: "Melebihi budget",
};
