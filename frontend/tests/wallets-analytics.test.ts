import assert from "node:assert/strict";
import {
  saveWallet,
  setWalletArchived,
  walletHistory,
  walletOpenings,
} from "../src/data/walletMock";
import { monthlyReport } from "../src/data/analytics";
import {
  transactionWallets,
  transactionRows,
  recordMockTransfer,
} from "../src/data/transactionMock";
import { savingGoals, moveGoal } from "../src/data/planningMock";
import { householdMembers } from "../src/data/householdMock";
import { cents } from "../src/utils/money";
const initial = monthlyReport("2026-10");
assert.equal(initial.income, 625000000n);
assert.equal(initial.expense, 432000000n);
assert.equal(initial.net, 193000000n);
assert.equal(initial.fees, 0n);
assert.equal(
  initial.categories.reduce((sum, c) => sum + c.amount, 0n),
  initial.expense,
);
assert.equal(
  initial.days.reduce((sum, d) => sum + d.income, 0n),
  initial.income,
);
assert.equal(monthlyReport("2026-11").count, 0);
assert.equal(monthlyReport("2028-02").days.length, 29);
assert.throws(() => monthlyReport("2026-13"));
const count = transactionWallets.length;
for (const bad of [
  { name: "", type: "Tunai", opening: "1", date: "2026-10-15" },
  { name: "Invalid", type: "Tunai", opening: "-1", date: "2026-10-15" },
  { name: "Invalid", type: "Tunai", opening: "1", date: "2026-02-30" },
])
  assert.throws(() => saveWallet(bad));
assert.equal(transactionWallets.length, count);
saveWallet({
  name: "Dompet Baru",
  type: "Tunai",
  opening: "1000000.01",
  date: "2026-10-15",
});
const wallet = transactionWallets.at(-1)!;
assert.equal(wallet.balance, "1000000.01");
assert.equal(walletOpenings.at(-1)?.walletId, wallet.id);
assert.equal(monthlyReport("2026-10").income, initial.income);
assert.equal(walletHistory(wallet.id)[0].amount, 100000001n);
saveWallet({
  id: wallet.id,
  name: "Dompet Edit",
  type: "E-Wallet",
  opening: "0",
  date: "",
});
assert.equal(wallet.balance, "1000000.01");
assert.throws(() =>
  saveWallet({
    name: "dompet edit",
    type: "Tunai",
    opening: "0",
    date: "2026-10-15",
  }),
);
recordMockTransfer({
  source: wallet.id,
  destination: "2",
  amount: "500000",
  fee: "2500",
  category: "Biaya Transfer",
  date: "2026-10-15",
  note: "Uji transfer",
});
assert.equal(wallet.balance, "497500.01");
assert.equal(monthlyReport("2026-10").expense, initial.expense + 250000n);
assert.equal(monthlyReport("2026-10").fees, 250000n);
assert.equal(monthlyReport("2026-10", wallet.id).expense, 250000n);
assert.equal(monthlyReport("2026-10", "2").income, 625000000n);
assert.equal(
  walletHistory(wallet.id).reduce((sum, row) => sum + row.amount, 0n),
  cents(wallet.balance),
);
const beforeExpense = monthlyReport("2026-10").expense;
moveGoal({
  goalId: savingGoals[0].id,
  walletId: wallet.id,
  type: "deposit",
  amount: "100000",
  date: "2026-10-15",
  note: "",
});
assert.equal(monthlyReport("2026-10").expense, beforeExpense);
assert.equal(
  walletHistory(wallet.id).reduce((sum, row) => sum + row.amount, 0n),
  cents(wallet.balance),
);
setWalletArchived(wallet.id, true);
const balance = wallet.balance,
  rows = transactionRows.length;
assert.throws(() =>
  recordMockTransfer({
    source: wallet.id,
    destination: "2",
    amount: "1",
    fee: "0",
    category: "",
    date: "2026-10-15",
    note: "",
  }),
);
assert.throws(() =>
  recordMockTransfer({
    source: "2",
    destination: wallet.id,
    amount: "1",
    fee: "0",
    category: "",
    date: "2026-10-15",
    note: "",
  }),
);
assert.throws(() =>
  moveGoal({
    goalId: "1",
    walletId: wallet.id,
    type: "withdraw",
    amount: "1",
    date: "2026-10-15",
    note: "",
  }),
);
assert.equal(wallet.balance, balance);
assert.equal(transactionRows.length, rows);
assert.equal(monthlyReport("2026-10", wallet.id).expense, 250000n);
setWalletArchived(wallet.id, false);
assert.equal(wallet.archived, false);
householdMembers[0].role = "member";
assert.throws(() => setWalletArchived(wallet.id, true));
assert.throws(() =>
  saveWallet({
    name: "Forbidden",
    type: "Tunai",
    opening: "0",
    date: "2026-10-15",
  }),
);
console.log(
  "PASS: wallet validation/create/edit/opening/history, archive guards and preserved reports, cashflow category/daily sums, month/wallet filters, fee-only transfer expense, goals excluded, management roles.",
);
