<script setup lang="ts">
import { computed, ref } from "vue";
import {
  ArrowDownLeft,
  ArrowUpRight,
  ChartNoAxesCombined,
  Receipt,
  ChartPie,
} from "lucide-vue-next";
import { monthlyReport } from "../data/analytics";
import { transactionWallets } from "../data/transactionMock";
import { decimal, money } from "../utils/money";
const month = ref("2026-10"),
  wallet = ref("");
const report = computed(() => {
  try {
    return monthlyReport(month.value, wallet.value);
  } catch {
    return null;
  }
});
const palette = [
  "#2563eb",
  "#8b5cf6",
  "#f59e0b",
  "#14b8a6",
  "#ec4899",
  "#64748b",
  "#38bdf8",
  "#a3a3a3",
];
const peak = computed(
  () =>
    report.value?.days.reduce(
      (max, day) =>
        day.income > max
          ? day.income > day.expense
            ? day.income
            : day.expense
          : day.expense > max
            ? day.expense
            : max,
      0n,
    ) ?? 0n,
);
function percent(value: bigint, total: bigint) {
  return total > 0n ? Number((value * 1000n + total / 2n) / total) / 10 : 0;
}
function formatted(value: bigint) {
  return (value < 0n ? "− " : "") + money(decimal(value < 0n ? -value : value));
}
const ring = computed(() => {
  let cursor = 0n;
  const slices = report.value?.categories.map((item, index) => {
    const start = percent(cursor, report.value!.expense);
    cursor += item.amount;
    return `${palette[index % palette.length]} ${start}% ${percent(cursor, report.value!.expense)}%`;
  });
  return slices?.length ? `conic-gradient(${slices.join(",")})` : "#e8edf5";
});
const monthName = computed(() =>
  report.value
    ? new Intl.DateTimeFormat("id-ID", {
        month: "long",
        year: "numeric",
        timeZone: "UTC",
      }).format(new Date(month.value + "-01T00:00:00Z"))
    : "Pilih bulan",
);
</script>
<template>
  <div class="page analytics-page">
    <div class="analytics-toolbar">
      <div>
        <h2>Laporan {{ monthName }}</h2>
        <p>Kenali pola pemasukan dan pengeluaran keluarga.</p>
      </div>
      <div>
        <label
          >Bulan laporan<input
            v-model="month"
            type="month"
            min="0001-01"
            max="9999-12" /></label
        ><label
          >Dompet<select v-model="wallet" aria-label="Dompet laporan">
            <option value="">Semua dompet</option>
            <option
              v-for="item in transactionWallets"
              :key="item.id"
              :value="item.id"
            >
              {{ item.name }}{{ item.archived ? " (arsip)" : "" }}
            </option>
          </select></label
        >
      </div>
    </div>
    <p v-if="!report" role="alert" class="form-error">
      Pilih bulan laporan yang valid.
    </p>
    <template v-else
      ><section class="analytics-summary">
        <article class="surface planning-stat">
          <span class="icon-surface mint"><ArrowDownLeft :size="24" /></span>
          <div>
            <small>Pemasukan</small
            ><strong>{{ formatted(report.income) }}</strong>
          </div>
        </article>
        <article class="surface planning-stat">
          <span class="icon-surface rose"><ArrowUpRight :size="24" /></span>
          <div>
            <small>Pengeluaran</small
            ><strong>{{ formatted(report.expense) }}</strong>
          </div>
        </article>
        <article class="surface planning-stat">
          <span class="icon-surface blue"
            ><ChartNoAxesCombined :size="24"
          /></span>
          <div>
            <small>{{
              report.net < 0n ? "Defisit Periode" : "Surplus Periode"
            }}</small
            ><strong :class="{ 'amount-minus': report.net < 0n }">{{
              formatted(report.net)
            }}</strong>
          </div>
        </article>
        <article class="surface planning-stat">
          <span class="icon-surface blue"><Receipt :size="24" /></span>
          <div>
            <small>Biaya Admin Transfer</small
            ><strong>{{ formatted(report.fees) }}</strong
            ><small>Sudah termasuk pengeluaran</small>
          </div>
        </article>
      </section>
      <div v-if="!report.count" class="surface planning-empty">
        <ChartNoAxesCombined :size="36" />
        <h2>Belum ada pemasukan atau pengeluaran</h2>
        <p>Pilih bulan atau dompet lain untuk melihat laporan yang tersedia.</p>
      </div>
      <template v-else
        ><section class="surface cashflow-panel">
          <div class="section-heading">
            <div>
              <h2>Arus Kas Harian</h2>
              <p class="planning-muted">
                Pemasukan dan pengeluaran per tanggal · {{ monthName }}
              </p>
            </div>
            <div class="cashflow-key">
              <span><i class="income-key"></i>Pemasukan</span
              ><span><i class="expense-key"></i>Pengeluaran</span>
            </div>
          </div>
          <p class="chart-scale">Skala tertinggi: {{ formatted(peak) }}</p>
          <div class="cashflow-scroll">
            <div
              class="cashflow-chart"
              role="img"
              :aria-label="
                'Grafik arus kas ' +
                monthName +
                '. Pemasukan ' +
                formatted(report.income) +
                ', pengeluaran ' +
                formatted(report.expense) +
                '. Rincian tersedia pada tabel di bawah.'
              "
            >
              <div
                v-for="day in report.days"
                :key="day.date"
                class="cashflow-day"
                :title="
                  day.date +
                  ' · Masuk ' +
                  formatted(day.income) +
                  ' · Keluar ' +
                  formatted(day.expense)
                "
              >
                <div class="cashflow-bars">
                  <i
                    class="cashflow-income"
                    :style="{ height: percent(day.income, peak) + '%' }"
                  ></i
                  ><i
                    class="cashflow-expense"
                    :style="{ height: percent(day.expense, peak) + '%' }"
                  ></i>
                </div>
                <span>{{ Number(day.date.slice(-2)) }}</span>
              </div>
            </div>
          </div>
          <details class="cashflow-details">
            <summary>Lihat rincian angka harian</summary>
            <div class="transaction-table-scroll">
              <table class="transaction-table">
                <caption class="sr-only">
                  Rincian arus kas harian
                </caption>
                <thead>
                  <tr>
                    <th>Tanggal</th>
                    <th>Pemasukan</th>
                    <th>Pengeluaran</th>
                    <th>Selisih</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="day in report.days.filter(
                      (day) => day.income || day.expense,
                    )"
                    :key="day.date"
                  >
                    <td>{{ day.date }}</td>
                    <td>{{ formatted(day.income) }}</td>
                    <td>{{ formatted(day.expense) }}</td>
                    <td>{{ formatted(day.income - day.expense) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </details>
        </section>
        <div class="analytics-bottom">
          <section class="surface category-analysis">
            <div class="section-heading">
              <h2>Pengeluaran per Kategori</h2>
              <ChartPie :size="20" />
            </div>
            <div v-if="!report.expense" class="planning-empty">
              <p>Belum ada pengeluaran pada periode ini.</p>
            </div>
            <div v-else class="category-analysis-content">
              <div
                class="analysis-ring"
                :style="{ background: ring }"
                aria-hidden="true"
              >
                <div>
                  <small>Total Pengeluaran</small
                  ><strong>{{ formatted(report.expense) }}</strong>
                </div>
              </div>
              <ul class="category-breakdown">
                <li v-for="(item, index) in report.categories" :key="item.name">
                  <span
                    class="category-color"
                    :style="{ background: palette[index % palette.length] }"
                  ></span>
                  <div>
                    <strong>{{ item.name }}</strong
                    ><small>{{ formatted(item.amount) }}</small>
                  </div>
                  <b>{{ percent(item.amount, report.expense) }}%</b>
                </li>
              </ul>
            </div>
          </section>
          <section class="surface report-insights">
            <h2>Ringkasan Periode</h2>
            <div>
              <small>Transaksi pemasukan & pengeluaran</small
              ><strong>{{ report.count }} transaksi</strong>
            </div>
            <div v-if="report.categories[0]">
              <small>Pengeluaran terbesar</small
              ><strong>{{ report.categories[0].name }}</strong
              ><span
                >{{ formatted(report.categories[0].amount) }} ·
                {{ percent(report.categories[0].amount, report.expense) }}% dari
                pengeluaran</span
              >
            </div>
            <div>
              <small>Selisih pemasukan dan pengeluaran</small
              ><strong>{{ formatted(report.net) }}</strong
              ><span>{{
                report.net < 0n
                  ? "Pengeluaran lebih besar daripada pemasukan periode ini."
                  : "Pemasukan menutup pengeluaran periode ini."
              }}</span>
            </div>
            <RouterLink to="/pratinjau/transaksi" class="secondary-button full-width"
              >Lihat Transaksi</RouterLink
            >
          </section>
        </div></template
      >
      <p class="report-method">
        Laporan memakai pemasukan dan pengeluaran pada periode pilihan. Biaya
        admin termasuk pengeluaran; pokok transfer, saldo awal, dan perpindahan
        dana target tidak dihitung. Surplus/defisit bukan saldo dompet.
      </p></template
    >
  </div>
</template>
