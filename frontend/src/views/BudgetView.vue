<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { LogOut, ChartPie, Wallet, ArrowDownLeft } from "lucide-vue-next";
import FinanceNavigation from "../components/FinanceNavigation.vue";
import AppDialog from "../components/AppDialog.vue";
import { useAuth } from "../stores/auth";
import { api, ApiError, errorMessage } from "../services/api";
import {
  all,
  amountInput,
  businessDate,
  cents,
  money,
  type Category,
} from "../services/finance";
import { budgetStatus, type BudgetReport } from "../services/budgets";
import "../finance.css";
const auth = useAuth(),
  route = useRoute(),
  router = useRouter();
const current = computed(() =>
  auth.memberships.find((m) => m.household_id === auth.selected),
);
const manage = computed(() =>
  ["owner", "admin"].includes(current.value?.role || ""),
);
function validMonth(value: unknown): value is string {
  return (
    typeof value === "string" && /^(?!0000)\d{4}-(0[1-9]|1[0-2])$/.test(value)
  );
}
const month = ref(
  validMonth(route.query.month)
    ? route.query.month
    : businessDate(current.value?.household.timezone || "Asia/Jakarta").slice(
        0,
        7,
      ),
);
const previousMonth = computed(() => {
  const [y, m] = month.value.split("-").map(Number);
  return m === 1
    ? `${String(y - 1).padStart(4, "0")}-12`
    : `${String(y).padStart(4, "0")}-${String(m - 1).padStart(2, "0")}`;
});
const report = ref<BudgetReport | null>(null),
  categories = ref<Category[]>([]);
const loading = ref(false),
  busy = ref(false),
  error = ref(""),
  message = ref(""),
  modal = ref("");
const rows = ref<{ category_id: string; amount: string }[]>([]);
let generation = 0;
onBeforeUnmount(() => {
  generation++;
});
async function load() {
  const ticket = ++generation,
    household = auth.selected;
  report.value = null;
  categories.value = [];
  error.value = "";
  if (!household || !validMonth(month.value)) {
    loading.value = false;
    return;
  }
  loading.value = true;
  try {
    const base = `/api/v1/households/${household}`;
    const [usage, cats] = await Promise.all([
      api<{ data: BudgetReport }>(
        `${base}/reports/budget-usage?month_start=${month.value}-01`,
      ),
      all<Category>(`${base}/categories`),
    ]);
    if (ticket !== generation) return;
    report.value = usage.data;
    categories.value = cats.filter((c) => c.type === "expense");
  } catch (e) {
    if (ticket === generation) error.value = errorMessage(e);
  } finally {
    if (ticket === generation) loading.value = false;
  }
}
watch(
  [() => auth.selected, month],
  () => {
    modal.value = "";
    message.value = "";
    void load();
  },
  { immediate: true },
);
watch(
  () => route.query.month,
  (value) => {
    if (validMonth(value)) month.value = value;
  },
);
watch(
  () => auth.user,
  (user) => {
    if (!user) void router.replace("/login");
  },
);
function choices(index: number) {
  return categories.value.filter(
    (c) =>
      (!c.is_archived ||
        report.value?.lines.some((l) => l.category_id === c.id)) &&
      !rows.value.some((r, i) => i !== index && r.category_id === String(c.id)),
  );
}
function edit() {
  rows.value =
    report.value?.lines.map((l) => ({
      category_id: String(l.category_id),
      amount: l.limit_amount,
    })) || [];
  if (!rows.value.length) rows.value.push({ category_id: "", amount: "" });
  error.value = "";
  modal.value = "edit";
}
async function save() {
  if (busy.value || !manage.value || !report.value) return;
  const household = auth.selected,
    selectedMonth = month.value;
  const base = `/api/v1/households/${household}/budgets`;
  let path = base,
    method = "POST",
    body: object;
  try {
    if (modal.value === "copy") {
      path += "/copy";
      body = {
        source_month: previousMonth.value + "-01",
        target_month: selectedMonth + "-01",
      };
    } else {
      const lines = rows.value.map((r) => {
        const limit = amountInput(r.amount);
        if (!r.category_id || cents(limit) <= 0n)
          throw new Error(
            "Pilih kategori dan isi batas budget lebih dari nol.",
          );
        return { category_id: Number(r.category_id), limit_amount: limit };
      });
      if (
        !lines.length ||
        new Set(lines.map((l) => l.category_id)).size !== lines.length
      )
        throw new Error("Isi minimal satu kategori tanpa duplikasi.");
      if (report.value.id) {
        path += `/${report.value.id}`;
        method = "PATCH";
        body = { lines };
      } else body = { month_start: selectedMonth + "-01", lines };
    }
    busy.value = true;
    error.value = "";
    await api(path, method, body);
    if (household === auth.selected && selectedMonth === month.value) {
      modal.value = "";
      await load();
      message.value = "Budget berhasil disimpan.";
    }
  } catch (e) {
    // A create/copy may have committed before the connection failed. Refresh before offering another write.
    const failure =
      !busy.value && e instanceof Error ? e.message : errorMessage(e);
    if (
      busy.value &&
      (!(e instanceof ApiError) || e.status >= 500 || e.status === 409) &&
      household === auth.selected &&
      selectedMonth === month.value
    ) {
      modal.value = "";
      await load();
    }
    error.value = failure;
  } finally {
    busy.value = false;
  }
}
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
          <h1>Budget</h1>
          <p class="account-muted">Atur batas pengeluaran bulanan keluarga.</p>
        </div>
        <div class="finance-account">
          <label
            >Keluarga aktif<select v-model="auth.selected" :disabled="busy">
              <option
                v-for="m in auth.memberships"
                :key="m.id"
                :value="m.household_id"
              >
                {{ m.household.name }}
              </option>
            </select></label
          ><button
            class="finance-icon"
            aria-label="Keluar"
            :disabled="busy"
            @click="logout"
          >
            <LogOut :size="20" /></button
          ><small>{{ auth.user?.name }} · {{ current?.role }}</small>
        </div>
      </header>
      <main>
        <div class="live-budget-toolbar">
          <label
            >Bulan budget<input
              v-model="month"
              type="month"
              min="0001-01"
              max="9999-12"
              :disabled="busy" /></label
          ><button
            class="account-secondary"
            :disabled="loading || busy"
            @click="load"
          >
            Muat ulang
          </button>
        </div>
        <p v-if="error && !modal" class="account-error" role="alert">
          {{ error }}
        </p>
        <p v-if="message" class="account-success" role="status">
          {{ message }}
        </p>
        <div v-if="loading" class="finance-empty" role="status">
          Memuat budget keluarga…
        </div>
        <template v-else-if="report">
          <section class="finance-summary" aria-label="Ringkasan budget">
            <article>
              <span class="finance-tile"><Wallet /></span>
              <div>
                <p>Total budget</p>
                <strong>{{ money(report.totals.limit_amount) }}</strong
                ><small>{{ month }}</small>
              </div>
            </article>
            <article>
              <span class="finance-tile"><ArrowDownLeft /></span>
              <div>
                <p>Terpakai dalam budget</p>
                <strong>{{ money(report.totals.used_amount) }}</strong
                ><small>Termasuk biaya admin transfer</small>
              </div>
            </article>
            <article>
              <span class="finance-tile"><ChartPie /></span>
              <div>
                <p>Sisa budget</p>
                <strong>{{ money(report.totals.remaining_amount) }}</strong
                ><small>Nilai negatif berarti melampaui batas</small>
              </div>
            </article>
          </section>
          <section class="finance-panel live-budget-panel">
            <div class="live-budget-toolbar">
              <h2>Rincian per kategori</h2>
              <div v-if="manage" class="live-budget-actions">
                <button
                  v-if="!report.id"
                  class="account-secondary"
                  :disabled="busy || month === '0001-01'"
                  @click="
                    error = '';
                    modal = 'copy';
                  "
                >
                  Salin budget bulan lalu</button
                ><button class="account-primary" :disabled="busy" @click="edit">
                  {{ report.id ? "Edit budget" : "Buat budget" }}
                </button>
              </div>
            </div>
            <p v-if="!report.id" class="finance-empty">
              Belum ada budget untuk {{ month }}.
              {{
                manage
                  ? "Buat batas per kategori atau salin bulan lalu."
                  : "Pemilik atau admin dapat mengatur budget keluarga."
              }}
            </p>
            <article
              v-for="line in report.lines"
              :key="line.category_id"
              class="live-budget-line"
              :data-status="line.status"
            >
              <div class="live-budget-toolbar">
                <h3>
                  {{ line.category_name }}
                  <small v-if="line.is_archived">(Diarsipkan)</small>
                </h3>
                <span>{{ budgetStatus[line.status] }}</span>
              </div>
              <p>
                {{ money(line.used_amount) }}
                <span class="account-muted"
                  >dari {{ money(line.limit_amount) }}</span
                >
              </p>
              <progress
                :value="Math.min(100, Number(line.percent_used))"
                max="100"
                :aria-label="'Pemakaian budget ' + line.category_name"
              />
              <div class="live-budget-toolbar">
                <small
                  >{{ line.percent_used.replace(".", ",") }}% terpakai</small
                ><small>Sisa {{ money(line.remaining_amount) }}</small>
              </div>
            </article>
            <p class="account-muted">
              Peringatan muncul mulai 80%. Budget yang terlampaui tetap
              mengizinkan transaksi selama saldo cukup.
            </p>
          </section>
          <section class="finance-panel live-budget-panel">
            <h2>Pengeluaran tanpa budget</h2>
            <strong>{{ money(report.totals.unbudgeted_amount) }}</strong>
            <p class="account-muted">
              Pengeluaran ini belum termasuk dalam total pemakaian budget di
              atas.
            </p>
            <div
              v-for="line in report.unbudgeted_expenses"
              :key="line.category_id"
              class="live-budget-toolbar live-budget-line"
            >
              <span
                >{{ line.category_name }}
                {{ line.is_archived ? "(Diarsipkan)" : "" }}</span
              ><strong>{{ money(line.used_amount) }}</strong>
            </div>
            <p v-if="!report.unbudgeted_expenses.length" class="account-muted">
              Tidak ada pengeluaran di luar kategori budget bulan ini.
            </p>
          </section>
        </template>
      </main>
      <AppDialog
        v-if="modal"
        :title="
          modal === 'copy' ? 'Salin budget bulan lalu' : 'Atur budget ' + month
        "
        @close="!busy && (modal = '')"
      >
        <form class="account-form" @submit.prevent="save">
          <fieldset :disabled="busy">
            <p v-if="error" class="account-error" role="alert">{{ error }}</p>
            <template v-if="modal === 'edit'">
              <p class="account-muted">
                Isi nominal tanpa pemisah ribuan. Menghapus kategori dari
                rencana tidak menghapus transaksinya.
              </p>
              <div
                v-for="(row, index) in rows"
                :key="index"
                class="live-budget-editor-row"
              >
                <label
                  >Kategori {{ index + 1
                  }}<select
                    v-model="row.category_id"
                    :aria-label="'Kategori budget ' + (index + 1)"
                    required
                  >
                    <option value="" disabled>Pilih kategori</option>
                    <option
                      v-for="c in choices(index)"
                      :key="c.id"
                      :value="String(c.id)"
                    >
                      {{ c.name }}{{ c.is_archived ? " (Diarsipkan)" : "" }}
                    </option>
                  </select></label
                ><label
                  >Batas (Rp)<input
                    v-model="row.amount"
                    :aria-label="'Batas budget ' + (index + 1)"
                    inputmode="decimal"
                    required
                    placeholder="1000000" /></label
                ><button
                  type="button"
                  class="account-text-button"
                  :disabled="rows.length === 1"
                  :aria-label="'Hapus kategori ' + (index + 1)"
                  @click="rows.splice(index, 1)"
                >
                  Hapus
                </button>
              </div>
              <button
                type="button"
                class="account-secondary"
                :disabled="rows.length >= 500 || !choices(-1).length"
                @click="rows.push({ category_id: '', amount: '' })"
              >
                Tambah kategori budget
              </button>
              <p
                v-if="!categories.some((c) => !c.is_archived)"
                class="account-muted"
              >
                Kategori pengeluaran aktif dapat dibuat di halaman Transaksi.
              </p>
            </template>
            <p v-else>
              Salin batas kategori dari {{ previousMonth }} ke {{ month }}?
              Pengeluaran tidak ikut disalin. Perubahan bulan tujuan tidak
              mengubah bulan sumber.
            </p>
            <div class="live-budget-actions">
              <button
                type="button"
                class="account-secondary"
                @click="modal = ''"
              >
                Batal</button
              ><button class="account-primary" type="submit">
                {{
                  busy
                    ? "Menyimpan…"
                    : modal === "copy"
                      ? "Salin sekarang"
                      : "Simpan budget"
                }}
              </button>
            </div>
          </fieldset>
        </form>
      </AppDialog>
    </div>
  </div>
</template>
