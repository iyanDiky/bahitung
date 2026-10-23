<script setup lang="ts">
import { computed } from "vue";
import { cents, money } from "../services/finance";
import type { CashflowTotals } from "../services/reports";
const props = defineProps<{
  rows: (CashflowTotals & { label: string })[];
  monthly: boolean;
}>();
const peak = computed(() =>
  props.rows.reduce(
    (max, r) =>
      [cents(r.income_amount), cents(r.expense_amount)].reduce(
        (a, b) => (a > b ? a : b),
        max,
      ),
    0n,
  ),
);
function height(value: string) {
  return peak.value > 0n
    ? Number((cents(value) * 10000n) / peak.value) / 100
    : 0;
}
</script>
<template>
  <div class="live-analysis-legend">
    <span><i class="live-income-dot" />Pemasukan</span
    ><span><i class="live-expense-dot" />Pengeluaran</span>
  </div>
  <p class="account-muted">
    Rincian nominal tersedia pada tabel di bawah grafik.
  </p>
  <div
    class="live-chart-scroll"
    tabindex="0"
    aria-label="Grafik arus kas, gulir untuk melihat seluruh periode"
  >
    <div
      class="live-cashflow-chart"
      role="img"
      :aria-label="
        'Grafik pemasukan dan pengeluaran ' + (monthly ? 'bulanan' : 'harian')
      "
      :style="{
        minWidth:
          Math.max(monthly ? 0 : 450, rows.length * (monthly ? 95 : 32)) + 'px',
      }"
    >
      <div
        v-for="row in rows"
        :key="row.label"
        class="live-chart-period"
        :title="
          row.label +
          ': pemasukan ' +
          money(row.income_amount) +
          ', pengeluaran ' +
          money(row.expense_amount)
        "
      >
        <div class="live-chart-bars">
          <i
            class="live-income-bar"
            :style="{ height: height(row.income_amount) + '%' }"
          /><i
            class="live-expense-bar"
            :style="{ height: height(row.expense_amount) + '%' }"
          />
        </div>
        <small>{{ monthly ? row.label : row.label.slice(5) }}</small>
      </div>
    </div>
  </div>
  <details class="live-report-details">
    <summary>Lihat rincian angka {{ monthly ? "bulanan" : "harian" }}</summary>
    <div class="live-report-table-scroll" tabindex="0">
      <table class="live-report-table">
        <caption>
          Rincian arus kas
          {{
            monthly ? "bulanan" : "harian"
          }}
        </caption>
        <thead>
          <tr>
            <th scope="col">Periode</th>
            <th scope="col">Pemasukan</th>
            <th scope="col">Pengeluaran</th>
            <th scope="col">Selisih</th>
            <th scope="col">Termasuk biaya admin</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.label">
            <th scope="row">{{ r.label }}</th>
            <td>{{ money(r.income_amount) }}</td>
            <td>{{ money(r.expense_amount) }}</td>
            <td>{{ money(r.net_amount) }}</td>
            <td>{{ money(r.fee_amount) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </details>
</template>
