import assert from "node:assert/strict";
import { cents, decimal, money, transferPreview } from "../src/utils/money";
import {
  recordMockTransfer,
  transactionRows,
  transactionWallets,
} from "../src/data/transactionMock";
assert.equal(decimal(cents("2500.50")), "2500.50");
assert.equal(money("2500.50"), "Rp 2.500,50");
assert.equal(decimal(cents("99999999999999999.99")), "99999999999999999.99");
for (const value of ["-1", "1e3", "1,000", "0.001", "", "100000000000000000"])
  assert.throws(() => cents(value));
assert.deepEqual(transferPreview("4250000.00", "500000", "2500"), {
  amount: "500000.00",
  fee: "2500.00",
  debit: "502500.00",
  remaining: "3747500.00",
});
assert.throws(() => transferPreview("500000", "500000", "2500"));
assert.throws(() => transferPreview("500000", "0", "0"));
const input = {
  source: "2",
  destination: "4",
  amount: "500000",
  fee: "2500",
  category: "Biaya Transfer",
  date: "2026-10-15",
  note: "Dana pendidikan",
};
const initialRows = transactionRows.length;
const balances = transactionWallets.map((w) => w.balance);
for (const bad of [
  { ...input, destination: "2" },
  { ...input, category: "" },
  { ...input, amount: "99999999" },
  { ...input, date: "2026-02-30" },
]) {
  assert.throws(() => recordMockTransfer(bad));
  assert.equal(transactionRows.length, initialRows);
  assert.deepEqual(
    transactionWallets.map((w) => w.balance),
    balances,
  );
}
const sum = () =>
  transactionWallets.reduce((total, w) => total + cents(w.balance), 0n);
const totalBefore = sum();
const id = recordMockTransfer(input);
assert.equal(transactionWallets[1].balance, "3747500.00");
assert.equal(transactionWallets[3].balance, "5500000.00");
assert.equal(sum(), totalBefore - 250000n);
assert.equal(transactionRows.length, initialRows + 2);
const fee = transactionRows.find((row) => row.linkedTransferId === id)!;
assert.equal(fee.type, "expense");
assert.equal(fee.amount, "2500.00");
assert.equal(transactionRows.find((row) => row.id === id)?.type, "transfer");
const beforeFree = sum();
recordMockTransfer({ ...input, amount: "0.01", fee: "0", category: "" });
assert.equal(sum(), beforeFree);
assert.equal(transactionRows.length, initialRows + 3);
console.log(
  "PASS: exact decimals, invalid amounts, insufficient balance, atomic validation, transfer + linked fee, fee-free transfer.",
);
