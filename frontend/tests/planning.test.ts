import assert from "node:assert/strict";
import {
  copyBudget,
  monthlyBudgets,
  saveBudget,
  previousMonth,
  spending,
  budgetStatus,
  saveGoal,
  savingGoals,
  moveGoal,
  goalMovements,
} from "../src/data/planningMock";
import {
  transactionWallets,
  transactionRows,
  recordMockTransfer,
} from "../src/data/transactionMock";
import { cents } from "../src/utils/money";
assert.equal(previousMonth("2027-01"), "2026-12");
assert.throws(() => copyBudget("2026-13"));
assert.throws(() => copyBudget("2026-10"));
assert.throws(() => copyBudget("2026-09"));
const originalLimit = monthlyBudgets["2026-10"][0].limit;
copyBudget("2026-11");
assert.equal(
  monthlyBudgets["2026-11"].length,
  monthlyBudgets["2026-10"].length,
);
saveBudget("2026-11", "Makan", "1234567.89", true);
assert.equal(monthlyBudgets["2026-10"][0].limit, originalLimit);
assert.equal(monthlyBudgets["2026-11"][0].limit, "1234567.89");
assert.throws(() => copyBudget("2026-11"));
assert.throws(() => saveBudget("2026-11", "Makan", "100"));
assert.throws(() => saveBudget("2026-12", "Makan", "0"));
assert.equal(monthlyBudgets["2026-12"], undefined);
assert.throws(() => saveBudget("2026-12", "Gaji", "100"));
assert.equal(spending("2026-10"), 432000000n);
assert.equal(spending("2026-11"), 0n);
assert.equal(budgetStatus(79n, 100n), "normal");
assert.equal(budgetStatus(80n, 100n), "warning");
assert.equal(budgetStatus(100n, 100n), "reached");
assert.equal(budgetStatus(105n, 100n), "exceeded");
saveBudget("2026-10", "Biaya Transfer", "1");
recordMockTransfer({
  source: "2",
  destination: "3",
  amount: "100",
  fee: "2500",
  category: "Biaya Transfer",
  date: "2026-10-15",
  note: "",
});
assert.equal(spending("2026-10", "Biaya Transfer"), 250000n);
assert.equal(
  budgetStatus(spending("2026-10", "Biaya Transfer"), 100n),
  "exceeded",
);
const totalAssets = () =>
  transactionWallets.reduce((sum, wallet) => sum + cents(wallet.balance), 0n) +
  savingGoals.reduce((sum, goal) => sum + cents(goal.balance), 0n);
const beforeAssets = totalAssets();
const beforeRows = transactionRows.length;
const beforeExpense = spending("2026-10");
saveGoal({
  name: "Dana darurat",
  target: "10000000",
  due: "2027-01-01",
  icon: "🎯",
});
const goal = savingGoals.at(-1)!;
assert.equal(goal.balance, "0.00");
assert.equal(totalAssets(), beforeAssets);
const input = {
  goalId: goal.id,
  walletId: "2",
  type: "deposit" as const,
  amount: "100000.01",
  date: "2026-10-15",
  note: "Setoran",
};
const walletBefore = cents(transactionWallets[1].balance);
moveGoal(input);
assert.equal(goal.balance, "100000.01");
assert.equal(cents(transactionWallets[1].balance), walletBefore - 10000001n);
assert.equal(totalAssets(), beforeAssets);
assert.equal(transactionRows.length, beforeRows);
assert.equal(spending("2026-10"), beforeExpense);
moveGoal({ ...input, type: "withdraw", amount: "0.01" });
assert.equal(goal.balance, "100000.00");
assert.equal(totalAssets(), beforeAssets);
const snapshot = JSON.stringify({
  transactionWallets,
  savingGoals,
  goalMovements,
});
for (const bad of [
  { ...input, amount: "99999999999" },
  { ...input, type: "withdraw" as const, amount: "100001" },
  { ...input, amount: "-1" },
  { ...input, date: "2026-02-30" },
  { ...input, walletId: "unknown" },
]) {
  assert.throws(() => moveGoal(bad));
  assert.equal(
    JSON.stringify({ transactionWallets, savingGoals, goalMovements }),
    snapshot,
  );
}
assert.throws(() => saveGoal({ name: " ", target: "100", due: "", icon: "" }));
assert.throws(() =>
  saveGoal({ name: "Invalid", target: "0", due: "", icon: "" }),
);
saveGoal({
  id: goal.id,
  name: "Dana darurat keluarga",
  target: "50000",
  due: "",
  icon: "",
});
assert.equal(goal.balance, "100000.00");
assert.equal(totalAssets(), beforeAssets);
console.log(
  "PASS: independent budget copy, duplicates, month boundaries, warnings, linked transfer fee, exact goal deposits/withdrawals, unchanged assets/cashflow, atomic invalid writes.",
);
