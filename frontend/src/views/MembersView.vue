<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from "vue";
import { useRoute } from "vue-router";
import {
  Crown,
  Shield,
  Users,
  Eye,
  UserPlus,
  Send,
  Pencil,
  Search,
  TriangleAlert,
  ArrowLeftRight,
  Archive,
} from "lucide-vue-next";
import HouseholdSettings from "../components/HouseholdSettings.vue";
import AppDialog from "../components/AppDialog.vue";
import MemberDialog from "../components/MemberDialog.vue";
import {
  household,
  householdMembers,
  invitations,
  actorId,
  currentMember,
  canManageMembers,
  isOwner,
  canEditMember,
  inviteMember,
  revokeInvitation,
  transferOwnership,
  roleLabels,
  type HouseholdMember,
} from "../data/householdMock";
const route = useRoute();
const settingsPage = computed(() => route.path === "/pratinjau/pengaturan");
const notice = ref(""),
  error = ref(""),
  query = ref("");
const invite = reactive({ email: "", role: "member" as "member" | "viewer" });
const emailInput = ref<HTMLInputElement>();
const selectedMember = ref<HouseholdMember>();
const ownershipOpen = ref(false),
  nextOwner = ref(""),
  confirmation = ref(""),
  ownershipError = ref("");
const now = ref(Date.now());
const timer = setInterval(() => {
  now.value = Date.now();
}, 60000);
onBeforeUnmount(() => clearInterval(timer));
const activeMembers = computed(() =>
  householdMembers.filter(
    (member) => member.householdId === household.id && member.active,
  ),
);
const visibleMembers = computed(() =>
  householdMembers.filter(
    (member) =>
      member.householdId === household.id &&
      `${member.name} ${member.email} ${roleLabels[member.role]}`
        .toLowerCase()
        .includes(query.value.trim().toLowerCase()),
  ),
);
const familyInvitations = computed(() =>
  invitations.filter((invite) => invite.householdId === household.id),
);
const eligibleOwners = computed(() =>
  activeMembers.value.filter((member) => member.id !== actorId),
);
const roles = [
  {
    key: "owner",
    title: "Owner",
    icon: Crown,
    description:
      "Mengelola keluarga, peran Admin, dan kepemilikan. Tepat satu Owner aktif.",
  },
  {
    key: "admin",
    title: "Admin",
    icon: Shield,
    description:
      "Mengelola keuangan serta Member/Viewer. Tidak dapat mengubah Admin lain atau Owner.",
  },
  {
    key: "member",
    title: "Member",
    icon: Users,
    description:
      "Mencatat transaksi, menyetor target, serta mengoreksi transaksi sendiri kapan saja sesuai validasi saldo.",
  },
  {
    key: "viewer",
    title: "Viewer",
    icon: Eye,
    description:
      "Melihat seluruh dompet dan laporan keluarga, tanpa mengubah data.",
  },
];
function initials(name: string) {
  return name
    .split(" ")
    .slice(0, 2)
    .map((word) => word[0])
    .join("")
    .toUpperCase();
}
function dateLabel(date: string) {
  return new Intl.DateTimeFormat("id-ID", {
    day: "numeric",
    month: "short",
    year: "numeric",
    timeZone: household.timezone,
  }).format(new Date(date.length === 10 ? date + "T00:00:00Z" : date));
}
function inviteStatus(item: (typeof invitations)[number]) {
  return item.status === "revoked"
    ? "Dicabut"
    : Date.parse(item.expires) <= now.value
      ? "Kedaluwarsa"
      : "Menunggu";
}
function createInvitation() {
  error.value = "";
  try {
    inviteMember(invite.email, invite.role);
    invite.email = "";
    notice.value =
      "Undangan contoh dibuat untuk review. Belum ada email yang dikirim.";
  } catch (cause) {
    error.value = (cause as Error).message;
  }
}
function revoke(id: string) {
  error.value = "";
  try {
    revokeInvitation(id);
    notice.value = "Undangan contoh dicabut.";
  } catch (cause) {
    error.value = (cause as Error).message;
  }
}
function openOwnership() {
  nextOwner.value = "";
  confirmation.value = "";
  ownershipError.value = "";
  ownershipOpen.value = true;
}
function confirmOwnership() {
  try {
    transferOwnership(nextOwner.value, confirmation.value);
    ownershipOpen.value = false;
    notice.value =
      "Kepemilikan pada data contoh dipindahkan. Peran Ayah sekarang Admin.";
  } catch (cause) {
    ownershipError.value = (cause as Error).message;
  }
}
watch(
  () => route.query.action,
  async (action) => {
    if (action === "invite") {
      await nextTick();
      emailInput.value?.focus();
    }
  },
  { immediate: true },
);
watch(
  () => route.path,
  () => {
    selectedMember.value = undefined;
    ownershipOpen.value = false;
    error.value = "";
    notice.value = "";
  },
);
</script>
<template>
  <div class="page members-page">
    <p v-if="notice" role="status" class="transfer-notice">
      {{ notice
      }}<button aria-label="Tutup pemberitahuan" @click="notice = ''">×</button>
    </p>
    <section class="surface family-profile">
      <div class="family-avatars" aria-hidden="true">
        <span
          v-for="(member, index) in activeMembers.slice(0, 4)"
          :key="member.id"
          :class="'avatar-' + index"
          >{{ initials(member.name) }}</span
        >
      </div>
      <div class="family-profile-text">
        <h2>{{ household.name }}</h2>
        <p>
          {{ activeMembers.length }} anggota aktif · Dibuat
          {{ dateLabel(household.created) }}
        </p>
        <p>{{ household.description }}</p>
      </div>
      <span class="current-role"
        >Peran Anda:
        <b>{{
          currentMember ? roleLabels[currentMember.role] : "Tidak aktif"
        }}</b></span
      >
    </section>
    <div class="members-layout">
      <div class="members-main">
        <section v-if="!settingsPage" class="surface members-directory">
          <div class="section-heading">
            <div>
              <h2>Daftar Anggota Keluarga</h2>
              <p class="members-subtitle">
                Kelola peran dan akses anggota keluarga.
              </p>
            </div>
            <button
              class="primary-button"
              :disabled="!canManageMembers"
              @click="emailInput?.focus()"
            >
              <UserPlus :size="17" /> Undang Anggota
            </button>
          </div>
          <label class="member-search"
            ><Search :size="17" /><input
              v-model="query"
              aria-label="Cari anggota"
              placeholder="Cari nama, email, atau peran…"
              type="search"
          /></label>
          <div class="members-table-scroll">
            <table class="members-table">
              <thead>
                <tr>
                  <th scope="col">Nama</th>
                  <th scope="col">Peran</th>
                  <th scope="col">Status</th>
                  <th scope="col">Bergabung</th>
                  <th scope="col">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(member, index) in visibleMembers" :key="member.id">
                  <td>
                    <div class="member-identity">
                      <span
                        class="member-avatar"
                        :class="'avatar-' + (index % 4)"
                        >{{ initials(member.name) }}</span
                      >
                      <div>
                        <strong
                          >{{ member.name }}
                          <Crown
                            v-if="member.role === 'owner'"
                            :size="14"
                            class="owner-crown"
                          /><small v-if="member.id === actorId" class="you-tag"
                            >Anda</small
                          ></strong
                        ><small>{{ member.email }}</small>
                      </div>
                    </div>
                  </td>
                  <td>
                    <span class="role-pill" :class="'role-' + member.role">{{
                      roleLabels[member.role]
                    }}</span>
                  </td>
                  <td>
                    <span
                      class="status-pill"
                      :class="{ inactive: !member.active }"
                      >{{ member.active ? "● Aktif" : "○ Nonaktif" }}</span
                    >
                  </td>
                  <td class="member-date">{{ dateLabel(member.joined) }}</td>
                  <td>
                    <button
                      v-if="canEditMember(member)"
                      class="goal-edit"
                      :aria-label="'Kelola ' + member.name"
                      @click="selectedMember = member"
                    >
                      <Pencil :size="16" /></button
                    ><span v-else class="member-locked">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="!visibleMembers.length" class="planning-empty">
            <Users :size="30" />
            <h3>Anggota tidak ditemukan</h3>
            <button class="secondary-button" @click="query = ''">
              Reset pencarian
            </button>
          </div>
        </section>
        <HouseholdSettings
          @saved="notice = 'Pengaturan keluarga disimpan pada sesi ini.'"
        />
        <section class="surface invitation-list">
          <div class="section-heading">
            <h2>Daftar Undangan</h2>
            <span class="invitation-count">{{ familyInvitations.length }}</span>
          </div>
          <p v-if="!familyInvitations.length" class="planning-muted">
            Belum ada undangan. Undang anggota sebagai Member atau Viewer
            melalui form di samping.
          </p>
          <article
            v-for="item in familyInvitations"
            :key="item.id"
            class="invitation-item"
          >
            <div>
              <strong>{{ item.email }}</strong
              ><small
                >{{ roleLabels[item.role] }} · {{ inviteStatus(item) }}</small
              ><small>Berlaku sampai {{ dateLabel(item.expires) }}</small>
            </div>
            <button
              v-if="inviteStatus(item) === 'Menunggu' && canManageMembers"
              class="secondary-button"
              :aria-label="'Cabut undangan ' + item.email"
              @click="revoke(item.id)"
            >
              Cabut
            </button>
          </article>
        </section>
      </div>
      <aside class="members-aside">
        <section class="surface invite-panel">
          <h2>Undang Anggota Keluarga</h2>
          <p class="members-subtitle">
            Berikan akses keuangan keluarga sesuai peran anggota.
          </p>
          <form @submit.prevent="createInvitation">
            <fieldset :disabled="!canManageMembers">
              <label class="form-field"
                >Alamat Email<input
                  ref="emailInput"
                  v-model="invite.email"
                  type="email"
                  aria-label="Alamat Email"
                  placeholder="nama@email.com"
                  maxlength="254"
                  required /></label
              ><label class="form-field"
                >Peran Undangan<select
                  v-model="invite.role"
                  aria-label="Peran Undangan"
                >
                  <option value="member">Member</option>
                  <option value="viewer">Viewer</option>
                </select></label
              >
              <p v-if="error" role="alert" class="form-error">{{ error }}</p>
              <button class="primary-button full-width">
                <Send :size="17" /> Buat Undangan
              </button>
            </fieldset>
          </form>
          <p class="invitation-note">
            Undangan berlaku 7 hari. Pada tahap ini undangan hanya dicatat
            lokal, belum mengirim email.
          </p>
        </section>
        <section class="surface role-guide">
          <h2>Penjelasan Peran Anggota</h2>
          <article
            v-for="role in roles"
            :key="role.key"
            :class="'role-guide-' + role.key"
          >
            <component :is="role.icon" :size="24" />
            <div>
              <h3>{{ role.title }}</h3>
              <p>{{ role.description }}</p>
            </div>
          </article>
        </section>
        <section class="surface household-danger">
          <h2><TriangleAlert :size="21" /> Pengelolaan Kepemilikan</h2>
          <h3>Transfer Kepemilikan</h3>
          <p>
            Pilih satu anggota aktif sebagai Owner baru. Owner sebelumnya
            menjadi Admin.
          </p>
          <button
            class="danger-outline full-width"
            :disabled="!isOwner"
            @click="openOwnership"
          >
            <ArrowLeftRight :size="16" /> Transfer Kepemilikan
          </button>
          <p v-if="!isOwner" class="members-subtitle">
            Hanya Owner yang dapat memindahkan kepemilikan.
          </p>
          <div class="archive-household">
            <h3>Arsip Keluarga</h3>
            <p>
              Pengarsipan menjaga riwayat keuangan tetap tersimpan. Tersedia
              setelah backend terhubung.
            </p>
            <button class="danger-outline full-width" disabled>
              <Archive :size="16" /> Arsip Keluarga
            </button>
          </div>
        </section>
      </aside>
    </div>
    <MemberDialog
      v-if="selectedMember"
      :member="selectedMember"
      @close="selectedMember = undefined"
      @saved="
        selectedMember = undefined;
        notice = 'Peran dan status anggota diperbarui pada data contoh.';
      "
    />
    <AppDialog
      v-if="ownershipOpen"
      title="Transfer Kepemilikan"
      description="Pemindahan ini mengubah peran pada data contoh sesi ini."
      @close="ownershipOpen = false"
      ><form @submit.prevent="confirmOwnership">
        <label class="form-field"
          >Owner Baru<select
            v-model="nextOwner"
            aria-label="Owner Baru"
            required
          >
            <option value="" disabled>Pilih anggota aktif</option>
            <option
              v-for="member in eligibleOwners"
              :key="member.id"
              :value="member.id"
            >
              {{ member.name }} · {{ roleLabels[member.role] }}
            </option>
          </select></label
        >
        <p class="transfer-info">
          Setelah disimpan, Anda menjadi Admin dan pengaturan khusus Owner tidak
          dapat Anda ubah. Riwayat keuangan tetap tersimpan.
        </p>
        <label class="form-field"
          >Ketik “{{ household.name }}” untuk mengonfirmasi<input
            v-model="confirmation"
            aria-label="Konfirmasi nama keluarga"
            required
            autocomplete="off"
        /></label>
        <p v-if="ownershipError" role="alert" class="form-error">
          {{ ownershipError }}
        </p>
        <div class="dialog-actions">
          <button
            type="button"
            class="secondary-button"
            @click="ownershipOpen = false"
          >
            Batal</button
          ><button class="primary-button">Pindahkan Kepemilikan</button>
        </div>
      </form></AppDialog
    >
  </div>
</template>
