import { transactionRows } from "./transactionMock";
import { cents } from "../utils/money";
import { validMonth } from "./planningMock";
export function monthlyReport(month: string, walletId = "") {
  validMonth(month);
  const rows = transactionRows.filter(
    (row) =>
      row.date.startsWith(month + "-") &&
      (!walletId || row.walletId === walletId),
  );
  const income = rows
    .filter((row) => row.type === "income")
    .reduce((sum, row) => sum + cents(row.amount), 0n);
  const expense = rows
    .filter((row) => row.type === "expense")
    .reduce((sum, row) => sum + cents(row.amount), 0n);
  const fees = rows
    .filter((row) => row.type === "expense" && row.linkedTransferId)
    .reduce((sum, row) => sum + cents(row.amount), 0n);
  const grouped = new Map<string, bigint>();
  for (const row of rows.filter((row) => row.type === "expense"))
    grouped.set(
      row.category,
      (grouped.get(row.category) ?? 0n) + cents(row.amount),
    );
  const categories = [...grouped]
    .map(([name, amount]) => ({ name, amount }))
    .sort((a, b) => (a.amount > b.amount ? -1 : a.amount < b.amount ? 1 : 0));
  const days = Array.from(
    {
      length: new Date(
        Number(month.slice(0, 4)),
        Number(month.slice(5)),
        0,
      ).getDate(),
    },
    (_, index) => {
      const date = month + "-" + String(index + 1).padStart(2, "0");
      return {
        date,
        income: rows
          .filter((row) => row.date === date && row.type === "income")
          .reduce((sum, row) => sum + cents(row.amount), 0n),
        expense: rows
          .filter((row) => row.date === date && row.type === "expense")
          .reduce((sum, row) => sum + cents(row.amount), 0n),
      };
    },
  );
  return {
    income,
    expense,
    net: income - expense,
    fees,
    categories,
    days,
    count: rows.filter((row) => row.type !== "transfer").length,
  };
}
