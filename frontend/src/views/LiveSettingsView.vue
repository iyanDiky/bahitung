<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useRouter } from "vue-router";
import { LogOut, Settings, ArrowLeftRight } from "lucide-vue-next";
import FinanceNavigation from "../components/FinanceNavigation.vue";
import AppDialog from "../components/AppDialog.vue";
import { useAuth } from "../stores/auth";
import { api, ApiError, errorMessage } from "../services/api";
import { all } from "../services/finance";
import "../finance.css";
interface Member {
  id: number;
  role: string;
  status: string;
  user: { id: number; name: string };
}
interface Household {
  id: number;
  name: string;
  timezone: string;
  currency_code: string;
}
const auth = useAuth(),
  router = useRouter();
const current = computed(() =>
  auth.memberships.find((m) => m.household_id === auth.selected),
);
const owner = computed(() => current.value?.role === "owner");
const household = ref<Household | null>(null),
  members = ref<Member[]>([]);
const name = ref(""),
  timezone = ref(""),
  loading = ref(false),
  busy = ref(false),
  error = ref(""),
  message = ref("");
const modal = ref(false),
  target = ref(""),
  reason = ref(""),
  confirmed = ref(false),
  formError = ref("");
const candidates = computed(() =>
  members.value.filter(
    (m) =>
      m.status === "active" &&
      m.role !== "owner" &&
      m.user.id !== auth.user?.id,
  ),
);
const selectedName = computed(
  () => candidates.value.find((m) => String(m.id) === target.value)?.user.name,
);
const zones = computed(() => [
  ...new Set(
    [
      "Asia/Jakarta",
      "Asia/Makassar",
      "Asia/Jayapura",
      household.value?.timezone,
    ].filter((s): s is string => !!s),
  ),
]);
const dirty = computed(
  () =>
    name.value.trim() !== household.value?.name ||
    timezone.value !== household.value?.timezone,
);
let generation = 0;
onBeforeUnmount(() => {
  generation++;
});
async function load() {
  const version = ++generation,
    id = auth.selected;
  household.value = null;
  members.value = [];
  name.value = "";
  timezone.value = "";
  loading.value = true;
  error.value = "";
  try {
    await auth.refresh();
    if (version !== generation || id !== auth.selected) return;
    if (!id) {
      await router.replace("/keluarga");
      return;
    }
    const base = `/api/v1/households/${id}`;
    const [result, list] = await Promise.all([
      api<{ data: Household }>(base),
      all<Member>(base + "/members"),
    ]);
    if (version !== generation) return;
    household.value = result.data;
    members.value = list;
    name.value = result.data.name;
    timezone.value = result.data.timezone;
  } catch (e) {
    if (version === generation) error.value = errorMessage(e);
  } finally {
    if (version === generation) loading.value = false;
  }
}
watch(
  () => auth.selected,
  () => {
    modal.value = false;
    message.value = "";
    void load();
  },
  { immediate: true },
);
watch(
  () => auth.user,
  (u) => {
    if (!u) void router.replace("/login?next=/pengaturan");
  },
);
function openTransfer() {
  target.value = "";
  reason.value = "";
  confirmed.value = false;
  formError.value = "";
  modal.value = true;
}
async function mutate(transfer = false) {
  if (busy.value || !owner.value || !auth.selected) return;
  if (transfer && (!target.value || !reason.value.trim() || !confirmed.value)) {
    formError.value =
      "Pilih anggota, isi alasan, dan konfirmasi perubahan peran.";
    return;
  }
  if (!transfer && !name.value.trim()) {
    error.value = "Nama keluarga wajib diisi.";
    return;
  }
  const id = auth.selected,
    version = generation;
  busy.value = true;
  error.value = "";
  formError.value = "";
  message.value = "";
  try {
    const base = `/api/v1/households/${id}`;
    if (transfer)
      await api(base + "/ownership-transfer", "POST", {
        member_id: Number(target.value),
        reason: reason.value.trim(),
      });
    else
      await api(base, "PATCH", {
        name: name.value.trim(),
        timezone: timezone.value,
      });
    if (id !== auth.selected || version !== generation) return;
    modal.value = false;
    // Immediately remove Owner actions after a successful transfer, even if refresh fails.
    if (transfer && current.value) current.value.role = "admin";
    message.value = transfer
      ? "Kepemilikan berhasil dipindahkan. Peran Anda sekarang Admin."
      : "Pengaturan keluarga berhasil disimpan.";
    await load();
  } catch (e) {
    if (id !== auth.selected || version !== generation) return;
    const text =
      errorMessage(e) +
      (e instanceof ApiError && (e.status === 0 || e.status >= 500)
        ? " Muat ulang untuk memeriksa hasil sebelum mencoba kembali."
        : "");
    if (transfer) formError.value = text;
    else error.value = text;
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
          <h1>Pengaturan keluarga</h1>
          <p class="account-muted">
            Atur informasi keluarga dan kepemilikannya.
          </p>
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
        <div class="live-settings-toolbar">
          <RouterLink to="/anggota">Kelola anggota</RouterLink
          ><button
            class="account-secondary"
            :disabled="busy || loading"
            @click="load"
          >
            Muat ulang
          </button>
        </div>
        <p v-if="error" class="account-error" role="alert">{{ error }}</p>
        <p v-if="message" class="account-success" role="status">
          {{ message }}
        </p>
        <p v-if="loading" role="status">Memuat pengaturan keluarga…</p>
        <div v-else-if="household" class="live-settings-grid">
          <section class="finance-panel">
            <div class="live-settings-title">
              <Settings :size="24" />
              <h2>Informasi keluarga</h2>
            </div>
            <p v-if="!owner" class="account-muted">
              Hanya Owner yang dapat mengubah pengaturan keluarga.
            </p>
            <form class="account-form" @submit.prevent="mutate()">
              <fieldset :disabled="!owner || busy">
                <label
                  >Nama keluarga<input
                    v-model="name"
                    required
                    maxlength="150" /></label
                ><label
                  >Zona waktu<select
                    v-model="timezone"
                    aria-label="Zona waktu"
                    required
                  >
                    <option v-for="zone in zones" :key="zone" :value="zone">
                      {{
                        zone === "Asia/Jakarta"
                          ? "WIB · Asia/Jakarta"
                          : zone === "Asia/Makassar"
                            ? "WITA · Asia/Makassar"
                            : zone === "Asia/Jayapura"
                              ? "WIT · Asia/Jayapura"
                              : zone
                      }}
                    </option>
                  </select></label
                >
                <p class="account-muted">
                  Zona waktu digunakan untuk tanggal bisnis dan periode laporan
                  keluarga. Tanggal transaksi yang sudah dicatat tetap
                  tersimpan.
                </p>
                <label>Mata uang<input value="Rupiah (IDR)" disabled /></label
                ><label
                  >Awal periode budget<input
                    value="Tanggal 1 setiap bulan"
                    disabled /></label
                ><button
                  v-if="owner"
                  class="account-primary"
                  :disabled="busy || !dirty"
                >
                  {{ busy ? "Menyimpan…" : "Simpan pengaturan" }}
                </button>
              </fieldset>
            </form>
          </section>
          <aside>
            <section class="finance-panel">
              <h2>Pemilik keluarga</h2>
              <p class="live-settings-owner">
                {{
                  members.find(
                    (m) => m.role === "owner" && m.status === "active",
                  )?.user.name || "—"
                }}
              </p>
              <p class="account-muted">
                Keluarga memiliki satu Owner aktif. Peran dan hak akses berlaku
                khusus untuk keluarga yang dipilih.
              </p>
            </section>
            <section v-if="owner" class="finance-panel live-settings-transfer">
              <div class="live-settings-title">
                <ArrowLeftRight :size="24" />
                <h2>Transfer kepemilikan</h2>
              </div>
              <p>
                Pindahkan peran Owner kepada anggota aktif. Setelah transfer,
                Anda menjadi Admin dan tidak dapat mengubah pengaturan keluarga.
              </p>
              <p v-if="!candidates.length" class="account-muted">
                Belum ada anggota aktif lain. Undang anggota terlebih dahulu
                melalui halaman Anggota.
              </p>
              <button
                class="account-secondary"
                :disabled="busy || !candidates.length"
                @click="openTransfer"
              >
                Transfer kepemilikan
              </button>
            </section>
          </aside>
        </div>
      </main>
    </div>
  </div>
  <AppDialog
    v-if="modal"
    title="Transfer kepemilikan"
    @close="!busy && (modal = false)"
    ><form class="account-form" @submit.prevent="mutate(true)">
      <fieldset :disabled="busy">
        <label
          >Owner baru<select v-model="target" required>
            <option value="" disabled>Pilih anggota aktif</option>
            <option v-for="m in candidates" :key="m.id" :value="String(m.id)">
              {{ m.user.name }} · {{ m.role }}
            </option>
          </select></label
        ><label
          >Alasan transfer<textarea
            v-model="reason"
            required
            maxlength="500"
            rows="3"
          ></textarea>
        </label>
        <p v-if="selectedName">
          <strong>{{ selectedName }}</strong> akan menjadi Owner. Anda menjadi
          Admin. Hanya Owner baru yang dapat memindahkan kembali kepemilikan.
        </p>
        <label class="live-settings-confirm"
          ><input v-model="confirmed" type="checkbox" required />Saya memahami
          peran saya berubah menjadi Admin.</label
        >
        <p v-if="formError" class="account-error" role="alert">
          {{ formError }}
        </p>
        <button class="account-primary" :disabled="busy || !confirmed">
          {{ busy ? "Memindahkan…" : "Konfirmasi transfer" }}
        </button>
      </fieldset>
    </form></AppDialog
  >
</template>
<style scoped>
.live-settings-toolbar,
.live-settings-title {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 20px;
}
.live-settings-toolbar {
  justify-content: space-between;
}
.live-settings-title {
  color: #2563eb;
}
.live-settings-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
  gap: 24px;
}
.finance-panel {
  margin-bottom: 24px;
}
h2 {
  font-size: 18px;
}
.account-form {
  margin-top: 20px;
}
.account-form fieldset {
  display: grid;
  gap: 18px;
  border: 0;
  padding: 0;
  min-width: 0;
}
.account-form textarea {
  border: 1px solid #dce3ed;
  border-radius: 10px;
  padding: 10px;
  max-width: 100%;
}
.live-settings-owner {
  font-size: 22px;
  margin: 16px 0;
  font-weight: 600;
}
.live-settings-transfer {
  border-color: #fecdd3;
}
.live-settings-transfer p {
  line-height: 1.7;
  margin-bottom: 16px;
}
.live-settings-transfer .live-settings-title {
  color: #be123c;
}
.account-form .live-settings-confirm {
  display: flex;
  align-items: flex-start;
  gap: 10px;
}
.live-settings-confirm input {
  width: 18px;
  min-width: 18px;
  height: 18px;
  margin-top: 3px;
}
.account-muted {
  line-height: 1.7;
}
@media (max-width: 1000px) {
  .live-settings-grid {
    grid-template-columns: 1fr;
  }
}
</style>
