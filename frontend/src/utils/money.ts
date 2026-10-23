// Exact minor units for UI previews. Server validation remains authoritative.
export function cents(value: string): bigint {
  if (!/^\d{1,17}(?:\.\d{1,2})?$/.test(value))
    throw new Error(
      "Gunakan angka tanpa pemisah ribuan, maksimal 2 desimal (contoh: 500000 atau 2500.50).",
    );
  const [whole, fraction = ""] = value.split(".");
  return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, "0"));
}
export function decimal(value: bigint): string {
  const sign = value < 0n ? "-" : "";
  const absolute = value < 0n ? -value : value;
  return `${sign}${absolute / 100n}.${String(absolute % 100n).padStart(2, "0")}`;
}
export function money(value: string): string {
  const amount = cents(value);
  const whole = new Intl.NumberFormat("id-ID").format(amount / 100n);
  const fraction = amount % 100n;
  return `Rp ${whole}${fraction ? "," + String(fraction).padStart(2, "0") : ""}`;
}
export function transferPreview(balance: string, amount: string, fee: string) {
  const principal = cents(amount);
  const charge = cents(fee);
  if (principal <= 0n)
    throw new Error("Nominal transfer harus lebih dari nol.");
  const debit = principal + charge;
  if (debit > cents(balance))
    throw new Error(
      "Saldo dompet asal tidak cukup untuk transfer dan biaya admin.",
    );
  return {
    amount: decimal(principal),
    fee: decimal(charge),
    debit: decimal(debit),
    remaining: decimal(cents(balance) - debit),
  };
}
