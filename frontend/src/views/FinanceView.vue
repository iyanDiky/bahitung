<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import {
  Wallet as WalletIcon,
  Target,
  ArrowLeftRight,
  LogOut,
  Plus,
  ArrowUpRight,
  ArrowDownLeft,
  RefreshCw,
  ArrowRight,
} from "lucide-vue-next";
import LiveGoalCards from "../components/LiveGoalCards.vue";
import FinanceNavigation from "../components/FinanceNavigation.vue";
import type { BudgetWarning } from "../services/budgets";
import AppDialog from "../components/AppDialog.vue";
import { useAuth } from "../stores/auth";
import { api, ApiError, errorMessage } from "../services/api";
import {
  all,
  amountInput,
  businessDate,
  cents,
  decimal,
  labels,
  money,
  type Goal,
  type Wallet,
  type Category,
  type Operation,
  type Detail,
  type Movement,
  type Page,
} from "../services/finance";
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
const write = computed(() => manage.value || current.value?.role === "member");
const section = computed(() =>
  route.path === "/target"
    ? "goals"
    : route.path === "/dompet"
      ? "wallets"
      : route.path === "/transaksi"
        ? "transactions"
        : "dashboard",
);
const title = computed(
  () =>
    ({
      goals: "Target tabungan",
      wallets: "Dompet keluarga",
      transactions: "Transaksi",
      dashboard: "Dashboard",
    })[section.value],
);
const goals = ref<Goal[]>([]);
const goalForm = ref({
  id: 0,
  name: "",
  target_amount: "",
  target_date: "",
  is_archived: false,
});
const goalStatus = ref("active");
const visibleGoals = computed(() =>
  goals.value.filter(
    (g) =>
      goalStatus.value === "all" ||
      (goalStatus.value === "archived" ? g.is_archived : !g.is_archived),
  ),
);
const allocated = computed(() =>
  decimal(goals.value.reduce((sum, g) => sum + cents(g.balance), 0n)),
);
const totalAssets = computed(() =>
  decimal(cents(available.value) + cents(allocated.value)),
);
const isGoalType = (type: string) =>
  ["goal_contribution", "goal_withdrawal"].includes(type);
const goalName = (id: number | null | undefined) =>
  goals.value.find((g) => g.id === id)?.name || "Target";
const wallets = ref<Wallet[]>([]),
  categories = ref<Category[]>([]),
  operations = ref<Operation[]>([]),
  monthly = ref<Operation[]>([]);
const loading = ref(true),
  busy = ref(false),
  error = ref(""),
  message = ref("");
const budgetWarnings = ref<BudgetWarning[]>([]);
const page = ref(1),
  pages = ref(1),
  total = ref(0);
const filters = ref({
  type: "",
  status: "",
  wallet_id: "",
  category_id: "",
  from: "",
  to: "",
});
const modal = ref(""),
  detail = ref<Detail | null>(null),
  history = ref<Movement[]>([]),
  historyWallet = ref<Wallet | Goal | null>(null),
  historyIsGoal = ref(false),
  historyPage = ref(1),
  historyPages = ref(1);
const walletForm = ref({
  id: 0,
  name: "",
  type: "bank",
  opening: "0",
  date: "",
  is_archived: false,
});
const categoryForm = ref({
  id: 0,
  name: "",
  type: "expense",
  is_archived: false,
});
const form = ref({
  type: "expense",
  goal: "",
  wallet: "",
  destination: "",
  category: "",
  amount: "",
  fee: "0",
  feeCategory: "",
  date: "",
  note: "",
  reason: "",
});
const correcting = ref<number | null>(null),
  voidId = ref<number | null>(null),
  reason = ref("");
interface Pending {
  user: number;
  household: number;
  path: string;
  body: Record<string, unknown>;
  key: string;
  label: string;
}
const pending = ref<Pending | null>(null);
const pendingKey = computed(() => "bahitung.pending." + auth.user?.id);
try {
  const saved = JSON.parse(
    sessionStorage.getItem(pendingKey.value) || "null",
  ) as Pending | null;
  if (
    saved?.user === auth.user?.id &&
    saved?.path.startsWith("/api/v1/households/")
  )
    pending.value = saved;
} catch {
  /* No saved request to resume. */
}
const locked = computed(() => busy.value || !!pending.value);
const activeWallets = computed(() =>
  wallets.value.filter((w) => !w.is_archived),
);
const available = computed(() =>
  decimal(wallets.value.reduce((sum, w) => sum + cents(w.balance), 0n)),
);
const monthIncome = computed(() =>
  decimal(
    monthly.value
      .filter((o) => o.type === "income")
      .reduce((s, o) => s + cents(o.amount), 0n),
  ),
);
const monthExpense = computed(() =>
  decimal(
    monthly.value.reduce(
      (s, o) =>
        s +
        (o.type === "expense"
          ? cents(o.amount)
          : o.type === "transfer"
            ? cents(o.fee_amount || "0.00")
            : 0n),
      0n,
    ),
  ),
);
const today = computed(() =>
  businessDate(current.value?.household.timezone || "Asia/Makassar"),
);
const monthLabel = computed(() =>
  new Intl.DateTimeFormat("id-ID", {
    month: "long",
    year: "numeric",
    timeZone: current.value?.household.timezone || "Asia/Makassar",
  }).format(new Date()),
);
const transferTotal = computed(() => {
  try {
    return money(
      decimal(
        cents(amountInput(form.value.amount)) +
          cents(amountInput(form.value.fee)),
      ),
    );
  } catch {
    return "—";
  }
});
let generation = 0;
const base = (id = auth.selected) => "/api/v1/households/" + id;
const walletName = (id: number | null | undefined) =>
  wallets.value.find((w) => w.id === id)?.name || "Dompet";
const categoryName = (id: number | null) =>
  categories.value.find((c) => c.id === id)?.name || "Tanpa kategori";
const displayError = (e: unknown) =>
  e instanceof ApiError
    ? errorMessage(e)
    : e instanceof Error
      ? e.message
      : errorMessage(e);
function canCorrect(o: Operation) {
  return (
    o.status === "posted" &&
    (manage.value ||
      (write.value &&
        o.created_by_member_id === current.value?.id &&
        ["income", "expense", "transfer"].includes(o.type)))
  );
}
function close() {
  if (!busy.value && !pending.value) {
    modal.value = "";
    detail.value = null;
    error.value = "";
  }
}
async function load() {
  const version = ++generation,
    id = auth.selected;
  loading.value = true;
  error.value = "";
  wallets.value = [];
  goals.value = [];
  categories.value = [];
  operations.value = [];
  monthly.value = [];
  if (!id) {
    loading.value = false;
    return;
  }
  try {
    const query = new URLSearchParams({
      page: String(page.value),
      per_page: section.value === "dashboard" ? "6" : "20",
    });
    for (const [k, v] of Object.entries(filters.value)) if (v) query.set(k, v);
    const month = today.value.slice(0, 7) + "-01";
    const [w, c, o, m, g] = await Promise.all([
      all<Wallet>(base(id) + "/wallets"),
      all<Category>(base(id) + "/categories"),
      api<Page<Operation>>(base(id) + "/operations?" + query),
      section.value === "dashboard"
        ? all<Operation>(
            base(id) +
              "/operations?status=posted&from=" +
              month +
              "&to=" +
              today.value,
          )
        : Promise.resolve([]),
      all<Goal>(base(id) + "/goals"),
    ]);
    if (version !== generation) return;
    wallets.value = w;
    goals.value = g;
    categories.value = c;
    operations.value = o.data;
    pages.value = o.meta.last_page;
    total.value = o.meta.total;
    monthly.value = m;
  } catch (e) {
    if (version === generation) error.value = displayError(e);
  } finally {
    if (version === generation) loading.value = false;
  }
}
watch(
  () => auth.selected,
  () => {
    modal.value = "";
    detail.value = null;
    history.value = [];
    message.value = "";
    budgetWarnings.value = [];
    page.value = 1;
    filters.value = {
      type: "",
      status: "",
      wallet_id: "",
      category_id: "",
      from: "",
      to: "",
    };
    void load();
  },
  { immediate: true },
);
watch(
  () => auth.user,
  (user) => {
    if (!user) void router.replace("/login");
  },
);
onBeforeUnmount(() => {
  generation++;
});
async function refresh() {
  if (locked.value) return;
  try {
    await auth.refresh();
    await load();
  } catch (e) {
    error.value = displayError(e);
  }
}
async function simple(action: () => Promise<void>) {
  if (locked.value) return;
  busy.value = true;
  error.value = "";
  message.value = "";
  try {
    await action();
    modal.value = "";
    await load();
    message.value = "Perubahan berhasil disimpan.";
  } catch (e) {
    error.value = displayError(e);
  } finally {
    busy.value = false;
  }
}
async function sendPending() {
  const request = pending.value;
  if (!request || busy.value) return;
  busy.value = true;
  error.value = "";
  try {
    const result = await api<{ warnings?: BudgetWarning[] }>(
      request.path,
      "POST",
      request.body,
      request.key,
    );
    sessionStorage.removeItem(pendingKey.value);
    pending.value = null;
    modal.value = "";
    detail.value = null;
    if (auth.selected === request.household) await load();
    if (auth.selected === request.household) {
      message.value = request.label + " berhasil disimpan.";
      budgetWarnings.value = result.warnings || [];
    }
  } catch (e) {
    error.value = displayError(e);
    if (e instanceof ApiError && e.status >= 400 && e.status < 500) {
      sessionStorage.removeItem(pendingKey.value);
      pending.value = null;
    }
  } finally {
    busy.value = false;
  }
}
async function financial(
  path: string,
  body: Record<string, unknown>,
  label: string,
) {
  if (locked.value || !auth.user || !auth.selected) return;
  const request: Pending = {
    user: auth.user.id,
    household: auth.selected,
    path,
    body,
    key: crypto.randomUUID(),
    label,
  };
  try {
    sessionStorage.setItem(pendingKey.value, JSON.stringify(request));
  } catch {
    error.value =
      "Penyimpanan browser tidak tersedia. Aktifkan penyimpanan agar pencatatan dapat dipulihkan saat koneksi terputus.";
    return;
  }
  pending.value = request;
  await sendPending();
}
function openGoal(goal?: Goal) {
  goalForm.value = {
    id: goal?.id || 0,
    name: goal?.name || "",
    target_amount: goal?.target_amount || "",
    target_date: goal?.target_date || "",
    is_archived: goal?.is_archived || false,
  };
  error.value = "";
  modal.value = "goal";
}
async function saveGoal() {
  if (locked.value) return;
  try {
    const f = goalForm.value;
    const amount = amountInput(f.target_amount);
    if (cents(amount) <= 0n)
      throw new Error("Nominal target harus lebih dari nol.");
    await simple(async () => {
      try {
        await api(
          base() + "/goals" + (f.id ? "/" + f.id : ""),
          f.id ? "PATCH" : "POST",
          {
            name: f.name,
            target_amount: amount,
            target_date: f.target_date || null,
            ...(f.id ? { is_archived: f.is_archived } : {}),
          },
        );
      } catch (e) {
        if (!(e instanceof ApiError) || e.status === 0 || e.status >= 500) {
          modal.value = "";
          await load();
          throw new Error(
            "Hasil penyimpanan belum terkonfirmasi. Periksa daftar Target terbaru sebelum membuat ulang.",
          );
        }
        throw e;
      }
    });
  } catch (e) {
    error.value = displayError(e);
  }
}
function openWallet(wallet?: Wallet) {
  error.value = "";
  walletForm.value = {
    id: wallet?.id || 0,
    name: wallet?.name || "",
    type: wallet?.type || "bank",
    opening: "0",
    date: today.value,
    is_archived: wallet?.is_archived || false,
  };
  modal.value = "wallet";
}
async function saveWallet() {
  const f = walletForm.value;
  if (f.id) {
    await simple(async () => {
      await api(base() + "/wallets/" + f.id, "PATCH", {
        name: f.name,
        type: f.type,
        is_archived: f.is_archived,
      });
    });
    return;
  }
  try {
    await financial(
      base() + "/wallets",
      {
        name: f.name,
        type: f.type,
        opening_amount: amountInput(f.opening),
        opening_on: f.date,
      },
      "Dompet",
    );
  } catch (e) {
    error.value = displayError(e);
  }
}
function openCategory(category?: Category) {
  error.value = "";
  categoryForm.value = {
    id: category?.id || 0,
    name: category?.name || "",
    type: category?.type || "expense",
    is_archived: category?.is_archived || false,
  };
  modal.value = "category";
}
async function saveCategory() {
  const f = categoryForm.value;
  await simple(async () => {
    await api(
      base() + "/categories" + (f.id ? "/" + f.id : ""),
      f.id ? "PATCH" : "POST",
      f.id
        ? { name: f.name, is_archived: f.is_archived }
        : { name: f.name, type: f.type },
    );
  });
}
function openOperation(type = "expense", goal?: Goal) {
  correcting.value = null;
  error.value = "";
  form.value = {
    type,
    goal: String(goal?.id || ""),
    wallet: String(activeWallets.value[0]?.id || ""),
    destination: String(activeWallets.value[1]?.id || ""),
    category: "",
    amount: "",
    fee: "0",
    feeCategory: "",
    date: today.value,
    note: "",
    reason: "",
  };
  modal.value = "operation";
}
async function inspect(id: number) {
  if (locked.value) return;
  busy.value = true;
  error.value = "";
  const version = generation;
  try {
    const response = await api<Detail>(base() + "/operations/" + id);
    if (version === generation) {
      detail.value = response;
      modal.value = "detail";
    }
  } catch (e) {
    error.value = displayError(e);
  } finally {
    busy.value = false;
  }
}
function editOperation() {
  if (!detail.value) return;
  const d = detail.value,
    o = d.data;
  correcting.value = o.id;
  form.value = {
    type: o.type,
    goal: String(o.goal_id || ""),
    wallet: String(
      o.source_wallet_id ||
        d.movements.find((m) => m.entry_kind === "original")?.wallet_id ||
        "",
    ),
    destination: String(o.destination_wallet_id || ""),
    category: String(o.category_id || ""),
    amount: o.amount,
    fee: o.fee_amount || "0",
    feeCategory: String(o.fee_category_id || ""),
    date: o.occurred_on,
    note: o.note || "",
    reason: "",
  };
  modal.value = "operation";
  error.value = "";
}
async function saveOperation() {
  try {
    const f = form.value;
    const payload: Record<string, unknown> = {
      type: f.type,
      amount: amountInput(f.amount),
      occurred_on: f.date,
      note: f.note || null,
    };
    if (payload.amount === "0.00")
      throw new Error("Nominal harus lebih dari nol.");
    if (f.type === "transfer")
      Object.assign(payload, {
        source_wallet_id: Number(f.wallet),
        destination_wallet_id: Number(f.destination),
        fee_amount: amountInput(f.fee),
        fee_category_id: f.feeCategory ? Number(f.feeCategory) : null,
      });
    else if (isGoalType(f.type)) {
      payload.goal_id = Number(f.goal);
      payload[
        f.type === "goal_withdrawal" ? "destination_wallet_id" : "wallet_id"
      ] = Number(f.wallet);
    } else {
      payload.wallet_id = Number(f.wallet);
      if (f.type !== "opening") payload.category_id = Number(f.category);
    }
    if (isGoalType(f.type) && !correcting.value) {
      const { type, goal_id, ...body } = payload;
      await financial(
        base() +
          "/goals/" +
          f.goal +
          (f.type === "goal_withdrawal" ? "/withdrawals" : "/contributions"),
        body,
        labels[f.type],
      );
      return;
    }
    await financial(
      base() +
        "/operations" +
        (correcting.value ? "/" + correcting.value + "/correct" : ""),
      correcting.value ? { reason: f.reason, replacement: payload } : payload,
      correcting.value ? "Koreksi transaksi" : "Transaksi",
    );
  } catch (e) {
    error.value = displayError(e);
  }
}
function openVoid() {
  voidId.value = detail.value?.data.id || null;
  reason.value = "";
  modal.value = "void";
  error.value = "";
}
async function saveVoid() {
  if (voidId.value)
    await financial(
      base() + "/operations/" + voidId.value + "/void",
      { reason: reason.value },
      "Pembatalan transaksi",
    );
}
async function showHistory(
  wallet: Wallet | Goal,
  pageNumber = 1,
  goal = false,
) {
  if (locked.value) return;
  busy.value = true;
  error.value = "";
  const version = generation;
  try {
    const result = await api<Page<Movement>>(
      base() +
        (goal ? "/goals/" : "/wallets/") +
        wallet.id +
        "/movements?page=" +
        pageNumber +
        "&per_page=20",
    );
    if (version === generation) {
      history.value = result.data;
      historyWallet.value = wallet;
      historyIsGoal.value = goal;
      historyPage.value = pageNumber;
      historyPages.value = result.meta.last_page;
      modal.value = "history";
    }
  } catch (e) {
    error.value = displayError(e);
  } finally {
    busy.value = false;
  }
}
async function logout() {
  if (locked.value) return;
  busy.value = true;
  try {
    await auth.logout();
    await router.replace("/login");
  } catch (e) {
    error.value = displayError(e);
  } finally {
    busy.value = false;
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
          <h1>{{ title }}</h1>
          <p class="account-muted">
            {{
              section === "dashboard"
                ? "Ringkasan keuangan keluarga Anda hari ini."
                : section === "goals"
                  ? "Sisihkan dana untuk rencana keluarga, satu langkah setiap hari."
                  : section === "wallets"
                    ? "Semua dompet keluarga, dalam satu tempat."
                    : "Setiap pencatatan punya cerita dan riwayat."
            }}
          </p>
        </div>
        <div class="finance-account">
          <label
            >Keluarga aktif<select
              v-model="auth.selected"
              :disabled="locked || loading"
            >
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
            :disabled="locked"
            @click="logout"
          >
            <LogOut :size="20" /></button
          ><small>{{ auth.user?.name }} · {{ current?.role }}</small>
        </div>
      </header>
      <main>
        <p
          v-if="error && !modal && !pending"
          class="account-error"
          role="alert"
        >
          {{ error }}
          <button class="account-text-button" @click="refresh">
            Coba lagi
          </button>
        </p>
        <p v-if="message" class="account-success" role="status">
          {{ message }}
        </p>
        <div
          v-for="warning in budgetWarnings"
          :key="warning.month_start + ':' + warning.category_id"
          class="live-budget-warning"
          role="status"
        >
          <strong>{{ warning.message }}</strong>
          <p>
            {{
              categories.find((c) => c.id === warning.category_id)?.name ||
              "Kategori"
            }}
            · {{ warning.month_start.slice(0, 7) }} ·
            {{ warning.percent_used.replace(".", ",") }}% terpakai setelah
            pencatatan.
          </p>
          <RouterLink
            :to="{
              path: '/budget',
              query: { month: warning.month_start.slice(0, 7) },
            }"
            >Lihat budget bulan ini</RouterLink
          >
        </div>
        <div v-if="loading" class="finance-empty" role="status">
          Memuat keuangan keluarga…
        </div>
        <template v-else-if="!error || wallets.length || goals.length">
          <section
            v-if="section === 'dashboard' || section === 'goals'"
            class="finance-assets"
            aria-label="Ringkasan aset"
          >
            <article>
              <span class="finance-tile"><WalletIcon /></span>
              <div>
                <p>Total aset</p>
                <strong>{{ money(totalAssets) }}</strong
                ><small>Saldo dompet + dana target, termasuk arsip</small>
              </div>
            </article>
            <article>
              <span class="finance-tile"><Target /></span>
              <div>
                <p>Dana target</p>
                <strong>{{ money(allocated) }}</strong
                ><small>Tersimpan terpisah dari saldo tersedia</small>
              </div>
            </article>
            <article v-if="section === 'goals'">
              <span class="finance-tile"><WalletIcon /></span>
              <div>
                <p>Saldo tersedia</p>
                <strong>{{ money(available) }}</strong
                ><small>Saldo seluruh dompet</small>
              </div>
            </article>
          </section>
          <section v-if="section === 'dashboard'" class="finance-summary">
            <article>
              <span class="finance-tile"><WalletIcon /></span>
              <div>
                <p>Saldo tersedia</p>
                <strong>{{ money(available) }}</strong
                ><small>Seluruh dompet, termasuk arsip</small>
              </div>
            </article>
            <article>
              <span class="finance-tile income"><ArrowDownLeft /></span>
              <div>
                <p>Pemasukan</p>
                <strong>{{ money(monthIncome) }}</strong
                ><small>{{ monthLabel }}</small>
              </div>
            </article>
            <article>
              <span class="finance-tile expense"><ArrowUpRight /></span>
              <div>
                <p>Pengeluaran</p>
                <strong>{{ money(monthExpense) }}</strong
                ><small>{{ monthLabel }} · termasuk biaya admin</small>
              </div>
            </article>
          </section>
          <div class="finance-actions">
            <button
              v-if="manage && section === 'goals'"
              class="account-primary"
              :disabled="locked"
              @click="openGoal()"
            >
              <Plus :size="18" />Tambah target
            </button>
            <button
              v-if="write && section !== 'goals'"
              class="account-primary"
              :disabled="locked"
              @click="openOperation()"
            >
              <Plus :size="18" />Tambah transaksi</button
            ><button
              v-if="write && section !== 'goals'"
              class="account-secondary"
              :disabled="locked"
              @click="openOperation('transfer')"
            >
              <ArrowLeftRight :size="18" />Transfer</button
            ><button
              v-if="manage && section !== 'goals'"
              class="account-secondary"
              :disabled="locked"
              @click="openWallet()"
            >
              <WalletIcon :size="18" />Tambah dompet</button
            ><button
              v-if="manage && section === 'transactions'"
              class="account-secondary"
              :disabled="locked"
              @click="openCategory()"
            >
              Tambah kategori</button
            ><button
              class="finance-icon"
              aria-label="Muat ulang"
              :disabled="locked"
              @click="refresh"
            >
              <RefreshCw :size="18" />
            </button>
          </div>
          <p
            v-if="!wallets.length && section !== 'goals'"
            class="finance-empty"
          >
            Belum ada dompet.
            {{
              manage
                ? "Mulai dengan Tambah dompet dan masukkan saldo awal."
                : "Minta Owner atau Admin membuat dompet keluarga terlebih dahulu."
            }}
          </p>
          <section
            v-if="section === 'goals'"
            class="finance-panel live-target-panel"
          >
            <div class="finance-heading">
              <h2>Rencana keluarga</h2>
              <label
                >Status target<select
                  v-model="goalStatus"
                  aria-label="Status target"
                >
                  <option value="active">Aktif</option>
                  <option value="archived">Diarsipkan</option>
                  <option value="all">Semua target</option>
                </select></label
              >
            </div>
            <p v-if="!goals.length" class="finance-empty">
              Belum ada target tabungan.
              {{
                manage
                  ? "Mulai dengan Tambah target."
                  : "Minta Owner atau Admin menambahkan target keluarga."
              }}
            </p>
            <p v-else-if="!visibleGoals.length" class="finance-empty">
              Tidak ada target pada status ini.
            </p>
            <LiveGoalCards
              :goals="visibleGoals"
              :manage="manage"
              :write="write"
              :locked="locked"
              :has-wallet="!!activeWallets.length"
              @edit="openGoal"
              @deposit="openOperation('goal_contribution', $event)"
              @withdraw="openOperation('goal_withdrawal', $event)"
              @history="showHistory($event, 1, true)"
            />
            <p class="account-muted">
              Setoran dan penarikan hanya memindahkan dana antara dompet dan
              target. Keduanya tidak dihitung sebagai pemasukan atau
              pengeluaran.
            </p>
            <RouterLink v-if="!activeWallets.length" to="/dompet"
              >Buka Dompet untuk menyiapkan dompet aktif</RouterLink
            >
          </section>
          <section
            v-if="['wallets', 'dashboard'].includes(section) && wallets.length"
            class="finance-panel"
          >
            <div class="finance-heading">
              <h2>
                Dompet keluarga <small>{{ activeWallets.length }} aktif</small>
              </h2>
              <RouterLink v-if="section === 'dashboard'" to="/dompet"
                >Lihat semua <ArrowRight :size="16"
              /></RouterLink>
            </div>
            <div class="finance-wallet-grid">
              <article
                v-for="w in section === 'dashboard'
                  ? wallets.slice(0, 4)
                  : wallets"
                :key="w.id"
                class="finance-wallet"
              >
                <span class="finance-tile"><WalletIcon /></span>
                <h3>{{ w.name }}</h3>
                <p>
                  {{ labels[w.type] }}
                  <span v-if="w.is_archived" class="finance-badge">Arsip</span>
                </p>
                <strong>{{ money(w.balance) }}</strong>
                <div class="finance-actions">
                  <button
                    class="account-text-button"
                    :disabled="locked"
                    @click="showHistory(w)"
                  >
                    Riwayat</button
                  ><button
                    v-if="manage"
                    class="account-text-button"
                    :disabled="locked"
                    @click="openWallet(w)"
                  >
                    Kelola dompet
                  </button>
                </div>
              </article>
            </div>
          </section>
          <section
            v-if="section === 'transactions' || section === 'dashboard'"
            class="finance-panel"
          >
            <div class="finance-heading">
              <h2>
                {{
                  section === "dashboard"
                    ? "Transaksi terbaru"
                    : "Riwayat transaksi"
                }}
              </h2>
              <RouterLink v-if="section === 'dashboard'" to="/transaksi"
                >Lihat semua <ArrowRight :size="16" /></RouterLink
              ><small v-else>{{ total }} catatan</small>
            </div>
            <form
              v-if="section === 'transactions'"
              class="finance-filters"
              @submit.prevent="
                page = 1;
                load();
              "
            >
              <label
                >Jenis<select v-model="filters.type" aria-label="Jenis">
                  <option value="">Semua jenis</option>
                  <option
                    v-for="type in [
                      'income',
                      'expense',
                      'transfer',
                      'opening',
                      'goal_contribution',
                      'goal_withdrawal',
                    ]"
                    :value="type"
                  >
                    {{ labels[type] }}
                  </option>
                </select></label
              ><label
                >Status<select v-model="filters.status">
                  <option value="">Semua status</option>
                  <option value="posted">Aktif</option>
                  <option value="reversed">Dibatalkan / dikoreksi</option>
                </select></label
              ><label
                >Dompet<select v-model="filters.wallet_id">
                  <option value="">Semua dompet</option>
                  <option v-for="w in wallets" :value="w.id">
                    {{ w.name }}
                  </option>
                </select></label
              ><label
                >Kategori<select v-model="filters.category_id">
                  <option value="">Semua kategori</option>
                  <option v-for="c in categories" :value="c.id">
                    {{ c.name }}
                  </option>
                </select></label
              ><label
                >Dari tanggal<input v-model="filters.from" type="date" /></label
              ><label
                >Sampai tanggal<input
                  v-model="filters.to"
                  type="date"
                  :min="filters.from" /></label
              ><button class="account-secondary" :disabled="locked">
                Terapkan
              </button>
            </form>
            <p v-if="!operations.length" class="finance-empty">
              Belum ada transaksi{{
                section === "transactions" ? " pada filter ini" : ""
              }}.
            </p>
            <div class="finance-operation-list">
              <button
                v-for="o in operations"
                :key="o.id"
                class="finance-operation"
                :disabled="locked"
                @click="inspect(o.id)"
              >
                <span class="finance-tile" :class="o.type"
                  ><ArrowLeftRight v-if="o.type === 'transfer'" /><ArrowDownLeft
                    v-else-if="
                      o.type === 'income' || o.type === 'opening'
                    " /><ArrowUpRight v-else /></span
                ><span class="finance-operation-name"
                  ><strong>{{ o.note || labels[o.type] }}</strong
                  ><small
                    >{{ o.occurred_on }} · {{ labels[o.type]
                    }}<template v-if="o.category_id">
                      · {{ categoryName(o.category_id) }}</template
                    ></small
                  ><small v-if="o.goal_id"
                    >Target: {{ goalName(o.goal_id) }}</small
                  ><small v-if="o.type === 'transfer'"
                    >{{ walletName(o.source_wallet_id) }} →
                    {{ walletName(o.destination_wallet_id) }} · Biaya
                    {{ money(o.fee_amount || "0.00") }}</small
                  ><span v-if="o.status === 'reversed'" class="finance-badge"
                    >Dibatalkan / dikoreksi</span
                  ></span
                ><strong
                  class="finance-amount"
                  :class="o.status === 'reversed' ? 'reversed' : o.type"
                  >{{
                    o.type === "expense"
                      ? "− "
                      : o.type === "income"
                        ? "+ "
                        : ""
                  }}{{ money(o.amount) }}</strong
                ><ArrowRight :size="16" />
              </button>
            </div>
            <div
              v-if="section === 'transactions' && pages > 1"
              class="finance-pagination"
            >
              <button
                class="account-secondary"
                :disabled="page <= 1 || locked"
                @click="
                  page--;
                  load();
                "
              >
                Sebelumnya</button
              ><span>{{ page }} / {{ pages }}</span
              ><button
                class="account-secondary"
                :disabled="page >= pages || locked"
                @click="
                  page++;
                  load();
                "
              >
                Berikutnya
              </button>
            </div>
          </section>
          <section
            v-if="section === 'transactions' && manage"
            class="finance-panel"
          >
            <div class="finance-heading">
              <h2>Kategori keluarga</h2>
              <button
                class="account-text-button"
                :disabled="locked"
                @click="openCategory()"
              >
                Tambah kategori
              </button>
            </div>
            <p v-if="!categories.length" class="account-muted">
              Tambahkan kategori pemasukan, pengeluaran, dan biaya admin sebelum
              mencatat transaksi.
            </p>
            <div class="finance-categories">
              <button
                v-for="c in categories"
                :key="c.id"
                class="account-secondary"
                :disabled="locked"
                @click="openCategory(c)"
              >
                {{ c.name }} · {{ labels[c.type]
                }}{{ c.is_archived ? " · Arsip" : "" }}
              </button>
            </div>
          </section>
        </template>
      </main>
    </div>

    <AppDialog
      v-if="pending && !busy"
      title="Selesaikan pencatatan"
      description="Hasil pencatatan belum terkonfirmasi. Kirim ulang data yang sama untuk memeriksa hasilnya tanpa mencatat dua kali."
      @close="() => {}"
      ><p>
        {{ pending.label }} ·
        {{
          auth.memberships.find((m) => m.household_id === pending?.household)
            ?.household.name || "Keluarga terkait"
        }}
      </p>
      <p v-if="error" class="account-error" role="alert">{{ error }}</p>
      <button class="account-primary" @click="sendPending">
        Coba lagi
      </button></AppDialog
    >
    <AppDialog
      v-else-if="modal === 'goal'"
      :title="goalForm.id ? 'Kelola target' : 'Tambah target'"
      @close="close"
    >
      <p v-if="error" class="account-error" role="alert">{{ error }}</p>
      <form class="account-form" @submit.prevent="saveGoal">
        <fieldset :disabled="locked">
          <label
            >Nama target<input v-model="goalForm.name" maxlength="120" required
          /></label>
          <label
            >Nominal target (Rp)<input
              v-model="goalForm.target_amount"
              inputmode="decimal"
              required
              aria-label="Nominal target (Rp)"
            /><small
              >Tanpa pemisah ribuan, contoh 5000000 atau 5000000,50.</small
            ></label
          >
          <label
            >Tanggal target (opsional)<input
              v-model="goalForm.target_date"
              type="date"
              min="0001-01-01"
              max="9999-12-31"
          /></label>
          <label v-if="goalForm.id" class="finance-check"
            ><input v-model="goalForm.is_archived" type="checkbox" />Arsipkan
            target</label
          >
          <p v-if="goalForm.id" class="account-muted">
            Arsip tetap menyimpan dana dan riwayat. Aktifkan kembali untuk setor
            atau tarik dana.
          </p>
        </fieldset>
        <button class="account-primary" :disabled="locked">
          Simpan target
        </button>
      </form>
    </AppDialog>
    <AppDialog
      v-else-if="modal === 'wallet'"
      :title="walletForm.id ? 'Kelola dompet' : 'Tambah dompet'"
      @close="close"
      ><p v-if="error" class="account-error" role="alert">{{ error }}</p>
      <form class="account-form" @submit.prevent="saveWallet">
        <fieldset :disabled="locked">
          <label
            >Nama dompet<input
              v-model="walletForm.name"
              required
              maxlength="120" /></label
          ><label
            >Jenis dompet<select v-model="walletForm.type">
              <option
                v-for="type in ['cash', 'bank', 'ewallet', 'other']"
                :value="type"
              >
                {{ labels[type] }}
              </option>
            </select></label
          ><template v-if="!walletForm.id"
            ><label
              >Saldo awal (Rp)<input
                v-model="walletForm.opening"
                inputmode="decimal"
                required
                placeholder="1500000"
              /><small
                >Tanpa pemisah ribuan. Saldo awal bukan pemasukan.</small
              ></label
            ><label
              >Tanggal saldo awal<input
                v-model="walletForm.date"
                type="date"
                :max="today"
                required /></label></template
          ><label v-else class="finance-check"
            ><input v-model="walletForm.is_archived" type="checkbox" />Arsipkan
            dompet (saldo dan riwayat tetap tersimpan)</label
          >
        </fieldset>
        <button class="account-primary" :disabled="locked">
          {{ busy ? "Menyimpan…" : "Simpan dompet" }}
        </button>
      </form></AppDialog
    >
    <AppDialog
      v-else-if="modal === 'category'"
      :title="categoryForm.id ? 'Kelola kategori' : 'Tambah kategori'"
      @close="close"
      ><p v-if="error" class="account-error" role="alert">{{ error }}</p>
      <form class="account-form" @submit.prevent="saveCategory">
        <fieldset :disabled="locked">
          <label
            >Nama kategori<input
              v-model="categoryForm.name"
              maxlength="120"
              required /></label
          ><label
            >Jenis kategori<select
              v-model="categoryForm.type"
              :disabled="!!categoryForm.id"
            >
              <option value="expense">Pengeluaran</option>
              <option value="income">Pemasukan</option>
            </select></label
          ><label v-if="categoryForm.id" class="finance-check"
            ><input
              v-model="categoryForm.is_archived"
              type="checkbox"
            />Arsipkan kategori</label
          >
        </fieldset>
        <button class="account-primary" :disabled="locked">
          Simpan kategori
        </button>
      </form></AppDialog
    >
    <AppDialog
      v-else-if="modal === 'operation'"
      :title="
        correcting
          ? 'Koreksi transaksi'
          : isGoalType(form.type)
            ? labels[form.type]
            : form.type === 'transfer'
              ? 'Transfer antar dompet'
              : 'Tambah transaksi'
      "
      @close="close"
      ><p v-if="error" class="account-error" role="alert">{{ error }}</p>
      <form class="account-form" @submit.prevent="saveOperation">
        <fieldset :disabled="locked">
          <label
            >Jenis transaksi<select
              v-model="form.type"
              @change="form.category = ''"
              :disabled="form.type === 'opening' || isGoalType(form.type)"
            >
              <option v-if="isGoalType(form.type)" :value="form.type">
                {{ labels[form.type] }}
              </option>
              <option v-if="form.type === 'opening'" value="opening">
                Saldo awal
              </option>
              <option value="expense">Pengeluaran</option>
              <option value="income">Pemasukan</option>
              <option value="transfer">Transfer</option>
            </select></label
          >
          <label v-if="isGoalType(form.type)"
            >Target tabungan<select v-model="form.goal" required>
              <option value="" disabled>Pilih target aktif</option>
              <option
                v-for="g in goals.filter((g) => !g.is_archived)"
                :key="g.id"
                :value="String(g.id)"
              >
                {{ g.name }} · {{ money(g.balance) }}
              </option>
            </select></label
          >
          <p v-if="isGoalType(form.type)" class="account-muted">
            {{
              form.type === "goal_withdrawal"
                ? "Dana target akan dikembalikan ke dompet yang dipilih."
                : "Saldo dompet akan dipindahkan ke target yang dipilih."
            }}
            Total aset tetap sama.
          </p>
          <label
            >{{ form.type === "transfer" ? "Dompet asal" : "Dompet transaksi"
            }}<select v-model="form.wallet" required>
              <option value="" disabled>Pilih dompet</option>
              <option v-for="w in activeWallets" :value="String(w.id)">
                {{ w.name }} · {{ money(w.balance) }}
              </option>
            </select></label
          >
          <label v-if="form.type === 'transfer'"
            >Dompet tujuan<select v-model="form.destination" required>
              <option value="" disabled>Pilih tujuan</option>
              <option
                v-for="w in activeWallets.filter(
                  (w) => String(w.id) !== form.wallet,
                )"
                :value="String(w.id)"
              >
                {{ w.name }}
              </option>
            </select></label
          >
          <label v-else-if="form.type !== 'opening' && !isGoalType(form.type)"
            >Kategori transaksi<select v-model="form.category" required>
              <option value="" disabled>Pilih kategori</option>
              <option
                v-for="c in categories.filter(
                  (c) => !c.is_archived && c.type === form.type,
                )"
                :value="String(c.id)"
              >
                {{ c.name }}
              </option>
            </select></label
          >
          <p
            v-if="
              !categories.length &&
              form.type !== 'opening' &&
              !isGoalType(form.type)
            "
            class="account-muted"
          >
            {{
              manage
                ? "Buat kategori melalui halaman Transaksi sebelum mengisi formulir ini."
                : "Minta Owner atau Admin menambahkan kategori terlebih dahulu."
            }}
          </p>
          <label
            >Nominal (Rp)<input
              v-model="form.amount"
              aria-label="Nominal (Rp)"
              inputmode="decimal"
              placeholder="150000"
              required
            /><small
              >Tanpa pemisah ribuan; contoh 150000 atau 150000,50.</small
            ></label
          >
          <template v-if="form.type === 'transfer'"
            ><label
              >Biaya admin (Rp)<input
                v-model="form.fee"
                inputmode="decimal"
                required /></label
            ><label
              >Kategori biaya admin<select
                v-model="form.feeCategory"
                :required="!!form.fee && !/^0([.,]0{1,2})?$/.test(form.fee)"
              >
                <option value="">Tanpa kategori (biaya nol)</option>
                <option
                  v-for="c in categories.filter(
                    (c) => !c.is_archived && c.type === 'expense',
                  )"
                  :value="String(c.id)"
                >
                  {{ c.name }}
                </option>
              </select></label
            >
            <div class="finance-transfer-total">
              <span>Total dipotong dari dompet asal</span
              ><strong>{{ transferTotal }}</strong
              ><small
                >Pokok transfer tidak dihitung sebagai pengeluaran. Biaya admin
                dihitung sebagai pengeluaran.</small
              >
            </div></template
          >
          <label
            >Tanggal transaksi<input
              v-model="form.date"
              type="date"
              :max="today"
              required /></label
          ><label
            >Catatan<input
              v-model="form.note"
              maxlength="2000"
              placeholder="Contoh: Belanja dapur" /></label
          ><label v-if="correcting"
            >Alasan koreksi<textarea
              v-model="form.reason"
              required
              maxlength="2000"
            />
          </label>
        </fieldset>
        <button
          class="account-primary"
          :disabled="locked || !activeWallets.length"
        >
          {{
            busy
              ? "Menyimpan…"
              : correcting
                ? "Simpan koreksi"
                : "Simpan transaksi"
          }}
        </button>
      </form></AppDialog
    >
    <AppDialog
      v-else-if="modal === 'detail' && detail"
      title="Detail transaksi"
      @close="close"
      ><p v-if="error" class="account-error" role="alert">{{ error }}</p>
      <div class="finance-detail">
        <span class="finance-badge"
          >{{ labels[detail.data.type] }} ·
          {{
            detail.data.status === "posted" ? "Aktif" : "Dibatalkan / dikoreksi"
          }}</span
        >
        <h3>{{ money(detail.data.amount) }}</h3>
        <p>{{ detail.data.note || "Tanpa catatan" }}</p>
        <small
          >{{ detail.data.occurred_on }} · Transaksi #{{
            detail.data.id
          }}</small
        >
        <p v-if="detail.data.type === 'transfer'">
          Biaya admin: {{ money(detail.data.fee_amount || "0.00") }}
        </p>
        <h4>Pergerakan saldo</h4>
        <ul>
          <li v-for="m in detail.movements" :key="m.id">
            <span
              >{{
                m.goal_id
                  ? "Target: " + goalName(m.goal_id)
                  : walletName(m.wallet_id)
              }}
              · {{ m.entry_kind === "reversal" ? "Pembalik" : "Asli" }}</span
            ><strong>{{ money(m.amount_signed) }}</strong>
          </li>
        </ul>
        <div v-if="detail.reversal" class="account-success">
          <strong>Alasan: {{ detail.reversal.reason }}</strong>
          <p>
            {{ new Date(detail.reversal.created_at).toLocaleString("id-ID") }}
          </p>
          <button
            v-if="detail.reversal.replacement_operation_id"
            class="account-text-button"
            @click="inspect(detail.reversal!.replacement_operation_id!)"
          >
            Lihat transaksi pengganti
          </button>
        </div>
        <button
          v-if="detail.data.correction_of_id"
          class="account-text-button"
          @click="inspect(detail.data.correction_of_id!)"
        >
          Lihat transaksi asli
        </button>
        <div v-if="canCorrect(detail.data)" class="finance-actions">
          <button
            class="account-primary"
            :disabled="locked"
            @click="editOperation"
          >
            Koreksi transaksi</button
          ><button
            class="account-secondary danger"
            :disabled="locked"
            @click="openVoid"
          >
            Batalkan transaksi
          </button>
        </div>
      </div></AppDialog
    >
    <AppDialog
      v-else-if="modal === 'void'"
      title="Batalkan transaksi"
      description="Saldo dan biaya admin akan dibalik jika kondisi saldo memungkinkan. Riwayat asli tetap tersimpan."
      @close="close"
      ><p v-if="error" class="account-error" role="alert">{{ error }}</p>
      <form class="account-form" @submit.prevent="saveVoid">
        <label
          >Alasan pembatalan<textarea
            v-model="reason"
            required
            maxlength="2000"
            :disabled="locked"
          /></label
        ><button class="account-primary" :disabled="locked">
          Konfirmasi pembatalan
        </button>
      </form></AppDialog
    >
    <AppDialog
      v-else-if="modal === 'history' && historyWallet"
      :title="'Riwayat ' + historyWallet.name"
      @close="close"
      ><p v-if="error" class="account-error" role="alert">{{ error }}</p>
      <div class="finance-detail">
        <h3>{{ money(historyWallet.balance) }}</h3>
        <p v-if="!history.length" class="account-muted">
          Belum ada pergerakan saldo.
        </p>
        <ul>
          <li v-for="m in history" :key="m.id">
            <button
              class="account-text-button"
              :disabled="locked"
              @click="inspect(m.operation_id)"
            >
              {{ m.occurred_on }} · {{ labels[m.type || ""]
              }}<small>{{
                m.entry_kind === "reversal" ? "Pembalik" : m.note
              }}</small></button
            ><strong>{{ money(m.amount_signed) }}</strong>
          </li>
        </ul>
        <div v-if="historyPages > 1" class="finance-pagination">
          <button
            :disabled="locked || historyPage <= 1"
            @click="showHistory(historyWallet!, historyPage - 1, historyIsGoal)"
          >
            Sebelumnya</button
          ><span>{{ historyPage }} / {{ historyPages }}</span
          ><button
            :disabled="locked || historyPage >= historyPages"
            @click="showHistory(historyWallet!, historyPage + 1, historyIsGoal)"
          >
            Berikutnya
          </button>
        </div>
      </div></AppDialog
    >
  </div>
</template>
