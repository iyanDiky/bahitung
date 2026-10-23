<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useRouter } from "vue-router";
import { LogOut, Users, Shield, Crown, Eye } from "lucide-vue-next";
import FinanceNavigation from "../components/FinanceNavigation.vue";
import AppDialog from "../components/AppDialog.vue";
import { useAuth } from "../stores/auth";
import { api, ApiError, errorMessage } from "../services/api";
import { all } from "../services/finance";
import "../finance.css";
type Role = "owner" | "admin" | "member" | "viewer";
type Status = "active" | "suspended" | "removed";
interface Member {
  id: number;
  role: Role;
  status: Status;
  joined_at: string | null;
  user: { id: number; name: string };
}
interface Invitation {
  id: number;
  invited_email: string;
  target_role: Role;
  status: string;
  expires_at: string;
}
const auth = useAuth(),
  router = useRouter();
const current = computed(() =>
  auth.memberships.find((m) => m.household_id === auth.selected),
);
const manages = computed(() =>
  ["owner", "admin"].includes(current.value?.role || ""),
);
const members = ref<Member[]>([]),
  invitations = ref<Invitation[]>([]);
const loading = ref(false),
  busy = ref(false),
  error = ref(""),
  message = ref(""),
  formError = ref("");
const search = ref(""),
  statusFilter = ref("");
const visible = computed(() =>
  members.value.filter(
    (m) =>
      m.user.name
        .toLocaleLowerCase()
        .includes(search.value.trim().toLocaleLowerCase()) &&
      (!statusFilter.value || m.status === statusFilter.value),
  ),
);
const roles = {
  owner: "Owner",
  admin: "Admin",
  member: "Member",
  viewer: "Viewer",
};
const statuses = {
  active: "Aktif",
  suspended: "Dinonaktifkan",
  removed: "Keluar",
};
const modal = ref<"invite" | "edit" | "revoke" | null>(null),
  target = ref<Member | null>(null),
  invitation = ref<Invitation | null>(null);
const email = ref(""),
  inviteRole = ref("member"),
  role = ref("member"),
  status = ref<Status>("active"),
  reason = ref("");
let generation = 0;
onBeforeUnmount(() => {
  generation++;
});
function canEdit(m: Member) {
  return (
    manages.value &&
    m.role !== "owner" &&
    (current.value?.role === "owner" || ["member", "viewer"].includes(m.role))
  );
}
function date(value: string | null) {
  return value
    ? new Intl.DateTimeFormat("id-ID", {
        dateStyle: "medium",
        timeZone: current.value?.household.timezone || "Asia/Makassar",
      }).format(new Date(value))
    : "—";
}
function inviteStatus(i: Invitation) {
  return i.status === "pending"
    ? new Date(i.expires_at).getTime() <= Date.now()
      ? "Kedaluwarsa"
      : "Menunggu"
    : { accepted: "Diterima", revoked: "Dicabut", expired: "Kedaluwarsa" }[
        i.status
      ] || i.status;
}
async function load() {
  const version = ++generation,
    household = auth.selected;
  members.value = [];
  invitations.value = [];
  error.value = "";
  loading.value = true;
  try {
    await auth.refresh();
    if (version !== generation || household !== auth.selected) return;
    if (!household) {
      await router.replace("/keluarga");
      return;
    }
    const base = `/api/v1/households/${household}`;
    const [list, invites] = await Promise.all([
      all<Member>(base + "/members"),
      manages.value
        ? all<Invitation>(base + "/invitations")
        : Promise.resolve([]),
    ]);
    if (version !== generation) return;
    members.value = list;
    invitations.value = invites;
  } catch (e) {
    if (version === generation) error.value = errorMessage(e);
  } finally {
    if (version === generation) loading.value = false;
  }
}
watch(
  () => auth.selected,
  () => {
    modal.value = null;
    message.value = "";
    search.value = "";
    statusFilter.value = "";
    void load();
  },
  { immediate: true },
);
watch(
  () => auth.user,
  (u) => {
    if (!u) void router.replace("/login?next=/anggota");
  },
);
function open(kind: "invite" | "edit" | "revoke", m?: Member, i?: Invitation) {
  formError.value = "";
  modal.value = kind;
  target.value = m || null;
  invitation.value = i || null;
  email.value = "";
  inviteRole.value = "member";
  role.value = m?.role || "member";
  status.value = m?.status || "active";
  reason.value = "";
}
async function submit() {
  if (busy.value || !auth.selected || !modal.value) return;
  const household = auth.selected,
    base = `/api/v1/households/${household}`,
    kind = modal.value;
  busy.value = true;
  formError.value = "";
  message.value = "";
  try {
    if (kind === "invite")
      await api(base + "/invitations", "POST", {
        email: email.value.trim(),
        role: inviteRole.value,
      });
    else if (kind === "revoke" && invitation.value)
      await api(base + `/invitations/${invitation.value.id}/revoke`, "POST");
    else if (kind === "edit" && target.value) {
      if (!reason.value.trim()) {
        formError.value = "Alasan perubahan wajib diisi.";
        return;
      }
      await api(base + `/members/${target.value.id}`, "PATCH", {
        role: role.value,
        status: status.value,
        reason: reason.value.trim(),
      });
    }
    if (household !== auth.selected) return;
    modal.value = null;
    message.value =
      kind === "invite"
        ? "Undangan berhasil dibuat. Tautan berlaku 7 hari."
        : kind === "revoke"
          ? "Undangan berhasil dicabut."
          : "Perubahan anggota berhasil disimpan.";
    await load();
  } catch (e) {
    if (household === auth.selected)
      formError.value =
        errorMessage(e) +
        (e instanceof ApiError && (e.status === 0 || e.status >= 500)
          ? " Tutup dialog dan muat ulang daftar untuk memeriksa hasil sebelum mengirim lagi."
          : "");
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
          <h1>Anggota</h1>
          <p class="account-muted">
            Kelola kebersamaan dan akses keuangan keluarga.
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
        <div class="live-members-toolbar">
          <p class="account-muted">
            Semua anggota aktif berbagi dompet keluarga yang sama.
          </p>
          <button
            class="account-secondary"
            :disabled="loading || busy"
            @click="load"
          >
            Muat ulang
          </button>
        </div>
        <p v-if="error" class="account-error" role="alert">{{ error }}</p>
        <p v-if="message" class="account-success" role="status">
          {{ message }}
        </p>
        <p v-if="loading" role="status">Memuat anggota keluarga…</p>
        <div v-else-if="!error" class="live-members-grid">
          <div>
            <section class="finance-panel live-members-intro">
              <Users :size="36" />
              <div>
                <h2>{{ current?.household.name }}</h2>
                <p>
                  {{
                    members.filter((m) => m.status === "active").length
                  }}
                  anggota aktif · {{ members.length }} total anggota
                </p>
              </div>
            </section>
            <section class="finance-panel">
              <div class="live-members-toolbar">
                <div>
                  <h2>Daftar anggota keluarga</h2>
                  <p class="account-muted">
                    Pantau peran dan status akses anggota.
                  </p>
                </div>
                <button
                  v-if="manages"
                  class="account-primary"
                  @click="open('invite')"
                >
                  Undang anggota
                </button>
              </div>
              <div class="live-members-filters">
                <label
                  >Cari anggota<input
                    v-model="search"
                    type="search"
                    placeholder="Nama anggota" /></label
                ><label
                  >Status anggota<select v-model="statusFilter">
                    <option value="">Semua status</option>
                    <option
                      v-for="(label, value) in statuses"
                      :key="value"
                      :value="value"
                    >
                      {{ label }}
                    </option>
                  </select></label
                >
              </div>
              <div class="live-members-table">
                <table>
                  <thead>
                    <tr>
                      <th>Nama</th>
                      <th>Peran</th>
                      <th>Status</th>
                      <th>Bergabung</th>
                      <th>Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="m in visible" :key="m.id">
                      <td>
                        <strong>{{ m.user.name }}</strong
                        ><small v-if="m.user.id === auth.user?.id">Anda</small>
                      </td>
                      <td>
                        <span class="live-members-role">{{
                          roles[m.role]
                        }}</span>
                      </td>
                      <td>{{ statuses[m.status] }}</td>
                      <td>{{ date(m.joined_at) }}</td>
                      <td>
                        <button
                          v-if="canEdit(m)"
                          class="account-secondary"
                          :aria-label="`Ubah ${m.user.name}`"
                          @click="open('edit', m)"
                        >
                          Ubah</button
                        ><span v-else>—</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p v-if="!visible.length" class="account-muted">
                Tidak ada anggota yang cocok dengan pencarian.
              </p>
            </section>
            <section v-if="manages" class="finance-panel">
              <h2>Undangan keluarga</h2>
              <p class="account-muted">
                Undangan berlaku 7 hari. Penerima perlu masuk dengan email
                terverifikasi yang sama.
              </p>
              <p v-if="!invitations.length">Belum ada undangan.</p>
              <article
                v-for="i in invitations"
                :key="i.id"
                class="live-members-invite"
              >
                <div>
                  <strong>{{ i.invited_email }}</strong>
                  <p>{{ roles[i.target_role] }} · {{ inviteStatus(i) }}</p>
                  <small>Berlaku hingga {{ date(i.expires_at) }}</small>
                </div>
                <button
                  v-if="i.status === 'pending'"
                  class="account-secondary"
                  :aria-label="`Cabut undangan ${i.invited_email}`"
                  @click="open('revoke', undefined, i)"
                >
                  Cabut undangan
                </button>
              </article>
            </section>
          </div>
          <aside class="finance-panel live-members-help">
            <h2>Penjelasan peran</h2>
            <article>
              <Crown />
              <div>
                <h3>Owner</h3>
                <p>
                  Mengelola keluarga dan seluruh anggota, termasuk Admin. Satu
                  Owner aktif per keluarga.
                </p>
              </div>
            </article>
            <article>
              <Shield />
              <div>
                <h3>Admin</h3>
                <p>
                  Mengelola keuangan, mengundang dan mengubah Member/Viewer.
                </p>
              </div>
            </article>
            <article>
              <Users />
              <div>
                <h3>Member</h3>
                <p>
                  Mencatat transaksi, mengoreksi transaksi sendiri, dan menyetor
                  dana target.
                </p>
              </div>
            </article>
            <article>
              <Eye />
              <div>
                <h3>Viewer</h3>
                <p>Melihat keuangan dan laporan tanpa mengubah data.</p>
              </div>
            </article>
            <p class="account-muted">
              Menonaktifkan atau mengeluarkan anggota menghentikan aksesnya.
              Riwayat transaksi tetap tersimpan.
            </p>
          </aside>
        </div>
      </main>
    </div>
  </div>
  <AppDialog
    v-if="modal"
    :title="
      modal === 'invite'
        ? 'Undang anggota'
        : modal === 'edit'
          ? 'Ubah anggota'
          : 'Cabut undangan'
    "
    @close="!busy && (modal = null)"
  >
    <form class="account-form" @submit.prevent="submit">
      <fieldset :disabled="busy">
        <template v-if="modal === 'invite'"
          ><label
            >Email penerima<input
              v-model="email"
              type="email"
              maxlength="255"
              required /></label
          ><label
            >Peran undangan<select v-model="inviteRole">
              <option value="member">Member</option>
              <option value="viewer">Viewer</option>
            </select></label
          >
          <p class="account-muted">
            Undangan berlaku 7 hari. Owner dapat mengangkat anggota menjadi
            Admin setelah bergabung.
          </p></template
        >
        <template v-else-if="modal === 'edit'"
          ><p>{{ target?.user.name }}</p>
          <label
            >Peran anggota<select v-model="role">
              <option v-if="current?.role === 'owner'" value="admin">
                Admin
              </option>
              <option value="member">Member</option>
              <option value="viewer">Viewer</option>
            </select></label
          ><label
            >Status akses<select v-model="status">
              <option
                v-for="(label, value) in statuses"
                :key="value"
                :value="value"
              >
                {{ label }}
              </option>
            </select></label
          >
          <p v-if="status !== 'active'" class="account-muted">
            Anggota tidak dapat mengakses keluarga ini. Riwayat transaksinya
            tetap tersimpan.
          </p>
          <label
            >Alasan perubahan<textarea
              v-model="reason"
              required
              maxlength="500"
              rows="3"
            ></textarea></label
        ></template>
        <p v-else>
          Tautan undangan untuk
          <strong>{{ invitation?.invited_email }}</strong> tidak dapat dipakai
          lagi setelah dicabut.
        </p>
        <p v-if="formError" class="account-error" role="alert">
          {{ formError }}
        </p>
        <button class="account-primary" :disabled="busy">
          {{
            busy
              ? "Menyimpan…"
              : modal === "invite"
                ? "Kirim undangan"
                : modal === "edit"
                  ? "Simpan perubahan"
                  : "Ya, cabut undangan"
          }}
        </button>
      </fieldset>
    </form>
  </AppDialog>
</template>
<style scoped>
.live-members-grid {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(260px, 1fr);
  gap: 20px;
}
.live-members-grid > div {
  min-width: 0;
}
.finance-panel {
  margin-bottom: 20px;
}
.live-members-toolbar,
.live-members-intro {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 20px;
}
.live-members-intro {
  justify-content: flex-start;
  color: #2563eb;
}
.live-members-filters {
  display: flex;
  gap: 16px;
  margin: 20px 0;
}
.live-members-filters label {
  display: grid;
  gap: 6px;
  flex: 1;
  min-width: 0;
}
.live-members-filters input,
.live-members-filters select {
  width: 100%;
  border: 1px solid #dce3ed;
  border-radius: 10px;
  padding: 10px;
  background: white;
}
.live-members-table {
  overflow-x: auto;
}
.live-members-table table {
  width: 100%;
  border-collapse: collapse;
  white-space: nowrap;
  text-align: left;
}
.live-members-table th,
.live-members-table td {
  padding: 14px 10px;
  border-bottom: 1px solid #edf0f5;
}
.live-members-table th {
  font-size: 12px;
  color: #64748b;
  background: #f8faff;
}
.live-members-table small {
  display: block;
  color: #64748b;
}
.live-members-role {
  border-radius: 8px;
  background: #eff6ff;
  color: #2563eb;
  padding: 5px 9px;
}
.live-members-help {
  align-self: start;
}
.live-members-help article {
  display: flex;
  gap: 14px;
  background: #f5f8ff;
  padding: 16px;
  border-radius: 12px;
  margin: 16px 0;
}
.live-members-help svg {
  flex-shrink: 0;
  color: #2563eb;
}
.live-members-help p {
  font-size: 13px;
  color: #64748b;
  line-height: 1.6;
}
.live-members-invite {
  display: flex;
  gap: 12px;
  justify-content: space-between;
  align-items: center;
  padding: 18px 0;
  border-bottom: 1px solid #edf0f5;
}
.live-members-invite strong {
  overflow-wrap: anywhere;
}
.live-members-invite small {
  color: #64748b;
}
h2 {
  font-size: 18px;
}
h3 {
  font-size: 15px;
}
.account-form fieldset {
  border: 0;
  padding: 0;
  display: grid;
  gap: 16px;
  min-width: 0;
}
.account-form textarea {
  border: 1px solid #dce3ed;
  border-radius: 10px;
  padding: 10px;
  max-width: 100%;
}
@media (max-width: 1100px) {
  .live-members-grid {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 600px) {
  .live-members-toolbar,
  .live-members-filters,
  .live-members-invite {
    align-items: stretch;
    flex-direction: column;
  }
  .live-members-table {
    margin: 0 -8px;
  }
  .live-members-table th,
  .live-members-table td {
    padding: 12px 8px;
  }
}
</style>
