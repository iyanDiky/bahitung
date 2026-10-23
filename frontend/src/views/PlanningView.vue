<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import {
  Wallet,
  ChartPie,
  PiggyBank,
  Copy,
  Plus,
  Pencil,
  TriangleAlert,
  Target,
} from "lucide-vue-next";
import BudgetEditor from "../components/BudgetEditor.vue";
import GoalDialog from "../components/GoalDialog.vue";
import SavingGoalCard from "../components/SavingGoalCard.vue";
import {
  monthlyBudgets,
  savingGoals,
  goalMovements,
  spending,
  copyBudget,
  previousMonth,
  progress,
  budgetStatus,
  type BudgetLine,
  type SavingGoal,
} from "../data/planningMock";
import {
  transactionWallets,
  expenseCategories,
  walletName,
} from "../data/transactionMock";
import { cents, decimal, money } from "../utils/money";
const route = useRoute();
const router = useRouter();
const isTargets = computed(() => route.path === "/pratinjau/target");
const month = ref("2026-10");
const notice = ref("");
const error = ref("");
const editorOpen = ref(false);
const editingLine = ref<BudgetLine>();
const goalMode = ref<
  "create" | "edit" | "deposit" | "withdraw" | "detail" | null
>(route.query.action === "create" ? "create" : null);
const selectedGoal = ref<SavingGoal>();
const goalFilter = ref("all");
const lines = computed(() =>
  (monthlyBudgets[month.value] ?? []).map((line) => {
    const spent = spending(month.value, line.category);
    return {
      ...line,
      spent,
      percent: progress(spent, cents(line.limit)),
      status: budgetStatus(spent, cents(line.limit)),
    };
  }),
);
const budgetTotal = computed(() =>
  lines.value.reduce((sum, line) => sum + cents(line.limit), 0n),
);
const expenseTotal = computed(() => spending(month.value));
const remainder = computed(() => budgetTotal.value - expenseTotal.value);
const unbudgeted = computed(() =>
  expenseCategories
    .map((category) => ({ category, spent: spending(month.value, category) }))
    .filter(
      (row) =>
        row.spent > 0n &&
        !lines.value.some((line) => line.category === row.category),
    ),
);
const goalTotal = computed(() =>
  savingGoals.reduce((sum, goal) => sum + cents(goal.balance), 0n),
);
const targetTotal = computed(() =>
  savingGoals.reduce((sum, goal) => sum + cents(goal.target), 0n),
);
const allWalletTotal = computed(() =>
  transactionWallets.reduce((sum, wallet) => sum + cents(wallet.balance), 0n),
);
const walletTotal = computed(() =>
  transactionWallets
    .filter((wallet) => !wallet.archived)
    .reduce((sum, wallet) => sum + cents(wallet.balance), 0n),
);
const visibleGoals = computed(() =>
  savingGoals.filter(
    (goal) =>
      goalFilter.value === "all" ||
      (goalFilter.value === "complete"
        ? cents(goal.balance) >= cents(goal.target)
        : cents(goal.balance) < cents(goal.target)),
  ),
);
const shownGoals = computed(() =>
  isTargets.value
    ? visibleGoals.value
    : savingGoals
        .filter((goal) => cents(goal.balance) < cents(goal.target))
        .slice(0, 3),
);
const categoryIcons: Record<string, string> = {
  Makan: "🍴",
  "Belanja Rumah": "🛒",
  Transportasi: "🚙",
  Pendidikan: "🎓",
  Hiburan: "🎮",
  Kesehatan: "♥",
  Lainnya: "•••",
  "Biaya Transfer": "↔",
};
const monthLabel = computed(() =>
  /^\d{4}-(0[1-9]|1[0-2])$/.test(month.value)
    ? new Intl.DateTimeFormat("id-ID", {
        month: "long",
        year: "numeric",
        timeZone: "UTC",
      }).format(new Date(month.value + "-01T00:00:00Z"))
    : "Pilih bulan",
);
const previousLabel = computed(() => {
  try {
    return previousMonth(month.value);
  } catch {
    return "—";
  }
});
watch(month, () => {
  notice.value = "";
  error.value = "";
  editorOpen.value = false;
});
watch(
  () => route.query.action,
  (value) => {
    if (value === "create") openGoal("create");
  },
);
watch(
  () => route.path,
  () => {
    editorOpen.value = false;
    selectedGoal.value = undefined;
    goalMode.value = route.query.action === "create" ? "create" : null;
    notice.value = "";
    error.value = "";
  },
);
function openBudget(line?: BudgetLine) {
  editingLine.value = line;
  editorOpen.value = true;
}
function copy() {
  error.value = "";
  try {
    copyBudget(month.value);
    notice.value =
      "Budget bulan sebelumnya disalin. Silakan sesuaikan batas tiap kategori.";
  } catch (cause) {
    error.value = (cause as Error).message;
  }
}
function openGoal(mode: NonNullable<typeof goalMode.value>, goal?: SavingGoal) {
  selectedGoal.value = goal;
  goalMode.value = mode;
}
function closeGoal() {
  goalMode.value = null;
  if (route.query.action) {
    const query = { ...route.query };
    delete query.action;
    void router.replace({ query });
  }
}
function savedGoal() {
  notice.value = "Perubahan target dicatat pada data contoh sesi ini.";
  closeGoal();
}
function savedBudget() {
  editorOpen.value = false;
  notice.value = "Budget disimpan pada data contoh sesi ini.";
}
function signedMoney(value: bigint) {
  return `${value < 0n ? "− " : ""}${money(decimal(value < 0n ? -value : value))}`;
}
</script>
<template>
  <div class="page planning-page">
    <p v-if="notice" role="status" class="transfer-notice">
      {{ notice
      }}<button aria-label="Tutup pemberitahuan" @click="notice = ''">×</button>
    </p>
    <p v-if="error" role="alert" class="form-error">{{ error }}</p>
    <section v-if="!isTargets" class="surface budget-overview">
      <div class="section-heading">
        <h2>
          Ringkasan Budget <span>{{ monthLabel }}</span>
        </h2>
        <label class="month-picker"
          >Bulan budget<input
            v-model="month"
            type="month"
            min="0001-01"
            max="9999-12"
        /></label>
      </div>
      <div class="budget-overview-grid">
        <div class="planning-stat">
          <span class="icon-surface blue"><Wallet :size="24" /></span>
          <div>
            <small>Total Budget</small
            ><strong>{{ money(decimal(budgetTotal)) }}</strong>
          </div>
        </div>
        <div class="planning-stat">
          <span class="icon-surface blue"><ChartPie :size="24" /></span>
          <div>
            <small>Total Pengeluaran</small
            ><strong>{{ money(decimal(expenseTotal)) }}</strong
            ><small>Termasuk biaya transfer</small>
          </div>
        </div>
        <div class="planning-stat">
          <span class="icon-surface" :class="remainder < 0n ? 'rose' : 'blue'"
            ><PiggyBank :size="24"
          /></span>
          <div>
            <small>{{
              remainder < 0n ? "Melebihi Total Budget" : "Sisa Budget"
            }}</small
            ><strong :class="{ 'amount-minus': remainder < 0n }">{{
              signedMoney(remainder)
            }}</strong>
          </div>
        </div>
        <div class="budget-copy">
          <button class="secondary-button" @click="copy">
            <Copy :size="17" /> Salin Bulan Sebelumnya</button
          ><small
            >Sumber: {{ previousLabel }} · tujuan: {{ month || "—" }}</small
          >
        </div>
      </div>
    </section>
    <template v-else
      ><div class="planning-title-row">
        <p>Wujudkan rencana keluarga, satu setoran setiap waktu.</p>
        <button class="primary-button" @click="openGoal('create')">
          <Plus :size="18" /> Buat Target
        </button>
      </div>
      <section class="target-summary">
        <article class="surface planning-stat">
          <span class="icon-surface blue"><PiggyBank :size="25" /></span>
          <div>
            <small>Dana Terkumpul</small
            ><strong>{{ money(decimal(goalTotal)) }}</strong
            ><small>dari target {{ money(decimal(targetTotal)) }}</small>
          </div>
        </article>
        <article class="surface planning-stat">
          <span class="icon-surface blue"><Wallet :size="25" /></span>
          <div>
            <small>Saldo Dompet Tersedia</small
            ><strong>{{ money(decimal(walletTotal)) }}</strong
            ><small>Dompet aktif, di luar alokasi target</small>
          </div>
        </article>
        <article class="surface planning-stat">
          <span class="icon-surface blue"><Target :size="25" /></span>
          <div>
            <small>Total Aset Keluarga</small
            ><strong>{{ money(decimal(allWalletTotal + goalTotal)) }}</strong
            ><small>Dompet + dana target, dihitung sekali</small>
          </div>
        </article>
      </section></template
    >
    <div :class="isTargets ? 'targets-layout' : 'budget-layout'">
      <div v-if="!isTargets" class="budget-main">
        <section class="surface budget-detail">
          <div class="section-heading">
            <h2>Rincian Budget per Kategori</h2>
          </div>
          <div v-if="!lines.length" class="planning-empty">
            <ChartPie :size="36" />
            <h3>Belum ada budget bulan ini</h3>
            <p>Salin bulan sebelumnya atau tambahkan kategori pertama.</p>
          </div>
          <div v-else class="budget-lines">
            <div class="budget-line-head">
              <span>Kategori</span><span>Pengeluaran / Budget</span
              ><span>Progres</span><span></span>
            </div>
            <article
              v-for="line in lines"
              :key="line.category"
              class="budget-line"
              :class="'budget-' + line.status"
            >
              <div class="budget-category">
                <span class="square-icon light">{{
                  categoryIcons[line.category]
                }}</span
                ><strong>{{ line.category }}</strong>
              </div>
              <div class="budget-values">
                <strong>{{ money(decimal(line.spent)) }}</strong
                ><small>/ {{ money(line.limit) }}</small>
              </div>
              <div class="budget-line-progress">
                <div
                  class="progress"
                  role="progressbar"
                  :aria-label="'Penggunaan budget ' + line.category"
                  :aria-valuenow="Math.min(100, line.percent)"
                  aria-valuemin="0"
                  aria-valuemax="100"
                >
                  <div
                    :style="{ width: Math.min(100, line.percent) + '%' }"
                  ></div>
                </div>
                <span>{{ line.percent }}%</span>
              </div>
              <button
                class="goal-edit"
                :aria-label="'Edit budget ' + line.category"
                @click="openBudget(line)"
              >
                <Pencil :size="16" />
              </button>
              <p v-if="line.status !== 'normal'" class="budget-line-message">
                <TriangleAlert :size="14" />{{
                  line.status === "exceeded"
                    ? "Melebihi budget " +
                      money(decimal(line.spent - cents(line.limit)))
                    : line.status === "reached"
                      ? "Batas budget tercapai"
                      : "Mendekati batas budget"
                }}
              </p>
            </article>
          </div>
          <button
            class="add-budget-button"
            :disabled="lines.length >= expenseCategories.length || !month"
            @click="openBudget()"
          >
            <Plus :size="17" /> Tambah Kategori Budget
          </button>
          <p class="budget-rule-note">
            Budget memberi peringatan; transaksi tetap boleh dicatat selama
            saldo dompet mencukupi.
          </p>
        </section>
        <section v-if="unbudgeted.length" class="surface unbudgeted-panel">
          <h3>Pengeluaran tanpa budget</h3>
          <p>Pengeluaran ini tetap dihitung pada total periode.</p>
          <div v-for="item in unbudgeted" :key="item.category">
            <span>{{ item.category }}</span
            ><strong>{{ money(decimal(item.spent)) }}</strong>
          </div>
        </section>
        <section class="surface allocation-panel">
          <span class="icon-surface blue"><PiggyBank :size="26" /></span>
          <div>
            <h3>Dana Dialokasikan ke Target</h3>
            <p>Terpisah dari saldo dompet, bukan pengeluaran.</p>
            <strong>{{ money(decimal(goalTotal)) }}</strong>
          </div>
          <RouterLink to="/pratinjau/target" class="secondary-button"
            >Kelola Target</RouterLink
          >
        </section>
      </div>
      <section class="surface planning-goals">
        <div class="section-heading">
          <h2>Target Tabungan {{ isTargets ? "Keluarga" : "Aktif" }}</h2>
          <RouterLink v-if="!isTargets" to="/pratinjau/target">Lihat Semua</RouterLink
          ><label v-else class="goal-filter"
            ><span class="sr-only">Status target</span
            ><select v-model="goalFilter" aria-label="Status target">
              <option value="all">Semua target</option>
              <option value="active">Dalam proses</option>
              <option value="complete">Tercapai</option>
            </select></label
          >
        </div>
        <div v-if="!shownGoals.length" class="planning-empty">
          <Target :size="36" />
          <h3>
            Belum ada target
            {{ goalFilter === "complete" ? "tercapai" : "yang sesuai" }}
          </h3>
          <p>
            {{
              goalFilter === "complete"
                ? "Setoran berikutnya membawa keluarga lebih dekat ke tujuan."
                : "Buat target untuk mulai menyisihkan dana keluarga."
            }}
          </p>
        </div>
        <div :class="{ 'target-card-grid': isTargets }">
          <SavingGoalCard
            v-for="goal in shownGoals"
            :key="goal.id"
            :goal="goal"
            @action="openGoal"
          />
        </div>
        <div v-if="!isTargets" class="goals-total">
          <div>
            <PiggyBank :size="24" /><span
              >Total Dana Terkumpul<strong>{{
                money(decimal(goalTotal))
              }}</strong></span
            >
          </div>
          <small
            >Dari {{ money(decimal(targetTotal)) }} target keseluruhan</small
          ><button
            class="secondary-button full-width"
            @click="openGoal('create')"
          >
            <Plus :size="16" /> Buat Target
          </button>
        </div>
      </section>
      <section class="surface goal-activity">
        <div class="section-heading"><h2>Aktivitas Target Terbaru</h2></div>
        <p v-if="!goalMovements.length" class="planning-muted">
          Belum ada setoran atau penarikan baru. Mulai dengan tombol Setor Dana
          pada target pilihanmu.
        </p>
        <ul class="goal-history">
          <li v-for="item in goalMovements.slice(0, 6)" :key="item.id">
            <div>
              <b>{{
                savingGoals.find((goal) => goal.id === item.goalId)?.name
              }}</b
              ><small
                >{{ item.date }} · {{ item.type === "deposit" ? "Dari" : "Ke" }}
                {{ walletName(item.walletId) }}</small
              >
            </div>
            <strong
              :class="item.type === 'deposit' ? 'amount-plus' : 'amount-minus'"
              >{{ item.type === "deposit" ? "+" : "−" }}
              {{ money(item.amount) }}</strong
            >
          </li>
        </ul>
      </section>
    </div>
    <BudgetEditor
      v-if="editorOpen"
      :month="month"
      :line="editingLine"
      @close="editorOpen = false"
      @saved="savedBudget"
    />
    <GoalDialog
      v-if="goalMode"
      :mode="goalMode"
      :goal="selectedGoal"
      @close="closeGoal"
      @saved="savedGoal"
    />
  </div>
</template>
