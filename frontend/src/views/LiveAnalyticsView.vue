<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useRouter } from "vue-router";
import {
  LogOut,
  ArrowDownLeft,
  ArrowUpRight,
  ChartNoAxesCombined,
  Receipt,
} from "lucide-vue-next";
import FinanceNavigation from "../components/FinanceNavigation.vue";
import CashflowChart from "../components/CashflowChart.vue";
import { useAuth } from "../stores/auth";
import { api, errorMessage } from "../services/api";
import {
  all,
  businessDate,
  cents,
  money,
  type Wallet,
} from "../services/finance";
import type { CashflowReport } from "../services/reports";
import "../finance.css";
const auth = useAuth(),
  router = useRouter();
const current = computed(() =>
  auth.memberships.find((m) => m.household_id === auth.selected),
);
const today = businessDate(
  current.value?.household.timezone || "Asia/Makassar",
);
const filters = ref({ from: today.slice(0, 7) + "-01", to: today, wallet: "" });
const wallets = ref<Wallet[]>([]),
  report = ref<CashflowReport | null>(null),
  loading = ref(false),
  error = ref("");
const granularity = ref<"daily" | "monthly">("daily"),
  categoryType = ref<"expense" | "income">("expense");
const rows = computed(() =>
  !report.value
    ? []
    : granularity.value === "daily"
      ? report.value.daily.map((r) => ({ ...r, label: r.date }))
      : report.value.monthly.map((r) => ({ ...r, label: r.month })),
);
const categories = computed(
  () => report.value?.categories[categoryType.value] || [],
);
const count = computed(() =>
  report.value
    ? report.value.totals.income_count + report.value.totals.expense_count
    : 0,
);
const palette = [
  "#2563eb",
  "#8b5cf6",
  "#f59e0b",
  "#14b8a6",
  "#ec4899",
  "#64748b",
  "#38bdf8",
];
const ring = computed(() => {
  if (!report.value || !categories.value.length) return "#edf2fa";
  const total = cents(
    report.value.totals[
      (categoryType.value + "_amount") as "income_amount" | "expense_amount"
    ],
  );
  let cursor = 0n;
  return (
    "conic-gradient(" +
    categories.value
      .map((c, i) => {
        const start = Number((cursor * 10000n) / total) / 100;
        cursor += cents(c.amount);
        return `${palette[i % palette.length]} ${start}% ${Number((cursor * 10000n) / total) / 100}%`;
      })
      .join(",") +
    ")"
  );
});
let generation = 0;
onBeforeUnmount(() => {
  generation++;
});
async function load(refreshWallets = false) {
  const version = ++generation,
    household = auth.selected,
    selected = { ...filters.value };
  report.value = null;
  error.value = "";
  loading.value = true;
  if (!household) {
    loading.value = false;
    return;
  }
  try {
    const base = "/api/v1/households/" + household;
    const query = new URLSearchParams({ from: selected.from, to: selected.to });
    if (selected.wallet) query.set("wallet_id", selected.wallet);
    const [result, list] = await Promise.all([
      api<{ data: CashflowReport }>(base + "/reports/cashflow?" + query),
      refreshWallets
        ? all<Wallet>(base + "/wallets")
        : Promise.resolve(wallets.value),
    ]);
    if (version !== generation) return;
    wallets.value = list;
    report.value = result.data;
  } catch (e) {
    if (version === generation) error.value = errorMessage(e);
  } finally {
    if (version === generation) loading.value = false;
  }
}
watch(
  () => auth.selected,
  () => {
    wallets.value = [];
    filters.value.wallet = "";
    void load(true);
  },
  { immediate: true },
);
watch(
  () => auth.user,
  (u) => {
    if (!u) void router.replace("/login");
  },
);
async function logout() {
  try {
    await auth.logout();
    await router.replace("/login");
  } catch (e) {
    error.value = errorMessage(e);
  }
}
</script>
<template>
  <div class="finance-shell">
    <FinanceNavigation />
    <div class="finance-main">
      <header class="finance-header">
        <div>
          <p class="account-eyebrow">{{ current?.household.name }}</p>
          <h1>Analisis</h1>
          <p class="account-muted">
            Kenali pola pemasukan dan pengeluaran keluarga.
          </p>
        </div>
        <div class="finance-account">
          <label
            >Keluarga aktif<select v-model="auth.selected">
              <option
                v-for="m in auth.memberships"
                :key="m.id"
                :value="m.household_id"
              >
                {{ m.household.name }}
              </option>
            </select></label
          ><button class="finance-icon" aria-label="Keluar" @click="logout">
            <LogOut :size="20" /></button
          ><small>{{ auth.user?.name }} · {{ current?.role }}</small>
        </div>
      </header>
      <main>
        <form class="live-analysis-filters" @submit.prevent="load()">
          <label
            >Dari tanggal<input
              v-model="filters.from"
              type="date"
              min="0001-01-01"
              max="9999-12-31"
              required /></label
          ><label
            >Sampai tanggal<input
              v-model="filters.to"
              type="date"
              :min="filters.from || '0001-01-01'"
              max="9999-12-31"
              required /></label
          ><label
            >Dompet laporan<select
              v-model="filters.wallet"
              aria-label="Dompet laporan"
            >
              <option value="">Semua dompet</option>
              <option v-for="w in wallets" :key="w.id" :value="String(w.id)">
                {{ w.name }}{{ w.is_archived ? " (arsip)" : "" }}
              </option>
            </select></label
          ><button class="account-primary" :disabled="loading">
            Tampilkan laporan</button
          ><button
            type="button"
            class="account-secondary"
            :disabled="loading"
            @click="load(true)"
          >
            Muat ulang
          </button>
        </form>
        <p class="account-muted">
          Rentang maksimal 366 hari. Tekan Tampilkan laporan setelah mengubah
          filter.
        </p>
        <p v-if="error" class="account-error" role="alert">{{ error }}</p>
        <div v-if="loading" class="finance-empty" role="status">
          Memuat laporan keluarga…
        </div>
        <template v-else-if="report">
          <p class="live-report-period">
            Laporan {{ report.from }} — {{ report.to }} ·
            {{
              report.wallet_id
                ? wallets.find((w) => w.id === report!.wallet_id)?.name
                : "Semua dompet"
            }}
          </p>
          <section class="live-analysis-summary" aria-label="Ringkasan laporan">
            <article>
              <span class="finance-tile income"><ArrowDownLeft /></span>
              <div>
                <p>Pemasukan</p>
                <strong>{{ money(report.totals.income_amount) }}</strong
                ><small>{{ report.totals.income_count }} transaksi</small>
              </div>
            </article>
            <article>
              <span class="finance-tile expense"><ArrowUpRight /></span>
              <div>
                <p>Pengeluaran</p>
                <strong>{{ money(report.totals.expense_amount) }}</strong
                ><small
                  >{{ report.totals.expense_count }} catatan, termasuk transfer
                  berbiaya</small
                >
              </div>
            </article>
            <article>
              <span class="finance-tile"><ChartNoAxesCombined /></span>
              <div>
                <p>
                  {{
                    cents(report.totals.net_amount) < 0n
                      ? "Defisit periode"
                      : "Surplus periode"
                  }}
                </p>
                <strong>{{ money(report.totals.net_amount) }}</strong
                ><small>{{
                  report.totals.net_percent_of_income === null
                    ? "Rasio belum tersedia karena pemasukan nol"
                    : report.totals.net_percent_of_income.replace(".", ",") +
                      "% dari pemasukan"
                }}</small>
              </div>
            </article>
            <article>
              <span class="finance-tile"><Receipt /></span>
              <div>
                <p>Biaya admin transfer</p>
                <strong>{{ money(report.totals.fee_amount) }}</strong
                ><small>Sudah termasuk pengeluaran</small>
              </div>
            </article>
          </section>
          <div
            v-if="!count"
            class="finance-panel live-analysis-panel finance-empty"
          >
            <h2>Belum ada pemasukan atau pengeluaran</h2>
            <p>Pilih periode atau dompet lain untuk melihat laporan.</p>
          </div>
          <template v-else>
            <section class="finance-panel live-analysis-panel">
              <div class="finance-heading">
                <div>
                  <h2>Tren arus kas</h2>
                  <p class="account-muted">
                    {{ report.from }} — {{ report.to }}. Tren bulanan mengikuti
                    rentang tanggal yang dipilih.
                  </p>
                </div>
                <label
                  >Tampilan grafik<select
                    v-model="granularity"
                    aria-label="Tampilan grafik"
                  >
                    <option value="daily">Harian</option>
                    <option value="monthly">Bulanan</option>
                  </select></label
                >
              </div>
              <CashflowChart
                :rows="rows"
                :monthly="granularity === 'monthly'"
              />
            </section>
            <section class="finance-panel live-analysis-panel">
              <div class="finance-heading">
                <h2>Rincian kategori</h2>
                <div
                  class="live-category-tabs"
                  role="group"
                  aria-label="Jenis kategori laporan"
                >
                  <button
                    type="button"
                    class="account-secondary"
                    :aria-pressed="categoryType === 'expense'"
                    @click="categoryType = 'expense'"
                  >
                    Pengeluaran</button
                  ><button
                    type="button"
                    class="account-secondary"
                    :aria-pressed="categoryType === 'income'"
                    @click="categoryType = 'income'"
                  >
                    Pemasukan
                  </button>
                </div>
              </div>
              <p v-if="!categories.length" class="account-muted">
                Belum ada
                {{
                  categoryType === "expense" ? "pengeluaran" : "pemasukan"
                }}
                pada periode ini.
              </p>
              <div v-else class="live-category-layout">
                <div
                  class="live-analysis-ring"
                  :style="{ background: ring }"
                  aria-hidden="true"
                >
                  <div>
                    <span>{{
                      categoryType === "expense" ? "Pengeluaran" : "Pemasukan"
                    }}</span
                    ><strong>{{
                      money(
                        report.totals[
                          categoryType === "expense"
                            ? "expense_amount"
                            : "income_amount"
                        ],
                      )
                    }}</strong>
                  </div>
                </div>
                <ul class="live-category-list">
                  <li v-for="(c, i) in categories" :key="c.category_id">
                    <i :style="{ background: palette[i % palette.length] }" />
                    <div>
                      <strong
                        >{{ c.category_name
                        }}{{ c.is_archived ? " (arsip)" : "" }}</strong
                      ><span
                        >{{ money(c.amount) }} ·
                        {{ c.entry_count }} catatan</span
                      ><small v-if="cents(c.fee_amount) > 0n"
                        >Termasuk biaya admin {{ money(c.fee_amount) }}</small
                      >
                    </div>
                    <b>{{ c.percent.replace(".", ",") }}%</b>
                  </li>
                </ul>
              </div>
            </section></template
          >
          <p class="account-muted live-report-method">
            Laporan memakai transaksi efektif pada tanggal pilihan, termasuk
            koreksi dan pembatalan. Biaya admin masuk pengeluaran dompet asal;
            pokok transfer, saldo awal, dan perpindahan dana Target tidak
            dihitung. Surplus/defisit bukan saldo dompet.
          </p>
          <RouterLink to="/transaksi" class="account-secondary"
            >Buka Transaksi</RouterLink
          >
        </template>
      </main>
    </div>
  </div>
</template>
