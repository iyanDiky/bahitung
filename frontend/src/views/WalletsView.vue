<script setup lang="ts">
import { computed, ref } from "vue";
import {
  Wallet,
  Landmark,
  Smartphone,
  Plus,
  Pencil,
  Archive,
  RotateCcw,
  ArrowLeftRight,
  Search,
} from "lucide-vue-next";
import { transactionWallets } from "../data/transactionMock";
import { savingGoals } from "../data/planningMock";
import { canManageMembers } from "../data/householdMock";
import {
  setWalletArchived,
  walletHistory,
  type WalletRecord,
} from "../data/walletMock";
import { cents, decimal, money } from "../utils/money";
import WalletDialog from "../components/WalletDialog.vue";
import AppDialog from "../components/AppDialog.vue";
const status = ref("active"),
  query = ref(""),
  notice = ref(""),
  error = ref("");
const editor = ref(false),
  editing = ref<WalletRecord>(),
  selected = ref<WalletRecord>(),
  archive = ref<WalletRecord>();
const visible = computed(() =>
  transactionWallets.filter(
    (w) =>
      (status.value === "all" ||
        (status.value === "archived" ? w.archived : !w.archived)) &&
      w.name.toLowerCase().includes(query.value.trim().toLowerCase()),
  ),
);
const total = computed(() =>
  transactionWallets.reduce((s, w) => s + cents(w.balance), 0n),
);
const activeTotal = computed(() =>
  transactionWallets
    .filter((w) => !w.archived)
    .reduce((s, w) => s + cents(w.balance), 0n),
);
const goals = computed(() =>
  savingGoals.reduce((s, g) => s + cents(g.balance), 0n),
);
const history = computed(() =>
  selected.value ? walletHistory(selected.value.id) : [],
);
function edit(wallet?: WalletRecord) {
  editing.value = wallet;
  editor.value = true;
}
function changeArchive() {
  try {
    if (archive.value)
      setWalletArchived(archive.value.id, !archive.value.archived);
    archive.value = undefined;
    notice.value = "Status dompet diperbarui pada data contoh sesi ini.";
  } catch (cause) {
    error.value = (cause as Error).message;
  }
}
function amount(value: bigint) {
  return (
    (value < 0n ? "− " : "+ ") + money(decimal(value < 0n ? -value : value))
  );
}
</script>
<template>
  <div class="page wallets-page">
    <div class="planning-title-row">
      <p>Semua dompet milik keluarga, terlihat bersama.</p>
      <button
        class="primary-button"
        :disabled="!canManageMembers"
        @click="edit()"
      >
        <Plus :size="18" /> Tambah Dompet
      </button>
    </div>
    <section class="target-summary">
      <article class="surface planning-stat">
        <span class="icon-surface blue"><Wallet :size="25" /></span>
        <div>
          <small>Saldo Dompet Aktif</small
          ><strong>{{ money(decimal(activeTotal)) }}</strong
          ><small>Siap dipakai untuk transaksi</small>
        </div>
      </article>
      <article class="surface planning-stat">
        <span class="icon-surface blue"><Archive :size="25" /></span>
        <div>
          <small>Saldo Dompet Diarsipkan</small
          ><strong>{{ money(decimal(total - activeTotal)) }}</strong
          ><small>Tetap termasuk aset keluarga</small>
        </div>
      </article>
      <article class="surface planning-stat">
        <span class="icon-surface blue"><Landmark :size="25" /></span>
        <div>
          <small>Total Aset Keluarga</small
          ><strong>{{ money(decimal(total + goals)) }}</strong
          ><small>Seluruh dompet + dana target</small>
        </div>
      </article>
    </section>
    <p v-if="notice" class="transfer-notice" role="status">
      {{ notice
      }}<button aria-label="Tutup pemberitahuan" @click="notice = ''">×</button>
    </p>
    <div class="wallet-toolbar">
      <label class="member-search"
        ><Search :size="17" /><input
          v-model="query"
          type="search"
          aria-label="Cari dompet"
          placeholder="Cari nama dompet…" /></label
      ><select v-model="status" aria-label="Status dompet">
        <option value="active">Dompet aktif</option>
        <option value="archived">Diarsipkan</option>
        <option value="all">Semua dompet</option>
      </select>
    </div>
    <div v-if="!visible.length" class="surface planning-empty">
      <Wallet :size="36" />
      <h2>Belum ada dompet yang sesuai</h2>
      <p>Ubah pencarian atau status, atau tambahkan dompet keluarga.</p>
      <button
        class="secondary-button"
        @click="
          status = 'all';
          query = '';
        "
      >
        Reset filter
      </button>
    </div>
    <div class="wallet-card-grid">
      <article
        v-for="wallet in visible"
        :key="wallet.id"
        class="surface wallet-card"
      >
        <div class="wallet-card-head">
          <span class="icon-surface blue"
            ><component
              :is="
                wallet.type === 'Rekening Bank'
                  ? Landmark
                  : wallet.type === 'E-Wallet'
                    ? Smartphone
                    : Wallet
              "
              :size="24" /></span
          ><span
            class="role-pill"
            :class="wallet.archived ? 'role-viewer' : 'role-member'"
            >{{ wallet.archived ? "Diarsipkan" : "Aktif" }}</span
          >
        </div>
        <h2>{{ wallet.name }}</h2>
        <p>{{ wallet.type }} · Dompet bersama</p>
        <strong class="wallet-balance">{{ money(wallet.balance) }}</strong>
        <div class="wallet-card-actions">
          <button
            class="secondary-button"
            :aria-label="'Riwayat ' + wallet.name"
            @click="selected = wallet"
          >
            Lihat Mutasi</button
          ><RouterLink
            v-if="!wallet.archived"
            :to="{
              path: '/transaksi',
              query: { action: 'transfer', source: wallet.id },
            }"
            class="primary-button"
            ><ArrowLeftRight :size="16" /> Transfer</RouterLink
          >
        </div>
        <div v-if="canManageMembers" class="wallet-secondary-actions">
          <button
            v-if="!wallet.archived"
            :aria-label="'Edit ' + wallet.name"
            @click="edit(wallet)"
          >
            <Pencil :size="14" /> Edit</button
          ><button
            :aria-label="
              (wallet.archived ? 'Aktifkan ' : 'Arsipkan ') + wallet.name
            "
            @click="
              archive = wallet;
              error = '';
            "
          >
            <component
              :is="wallet.archived ? RotateCcw : Archive"
              :size="14"
            />{{ wallet.archived ? "Aktifkan kembali" : "Arsipkan" }}
          </button>
        </div>
      </article>
    </div>
    <WalletDialog
      v-if="editor"
      :wallet="editing"
      @close="editor = false"
      @saved="
        editor = false;
        notice = 'Dompet disimpan pada data contoh sesi ini.';
      "
    />
    <AppDialog
      v-if="selected"
      title="Mutasi Dompet"
      :description="selected.name"
      @close="selected = undefined"
      ><div class="goal-detail-balance">
        <span>Saldo saat ini</span><strong>{{ money(selected.balance) }}</strong
        ><small>{{
          selected.archived ? "Dompet diarsipkan" : "Dompet aktif"
        }}</small>
      </div>
      <p class="planning-muted">
        Riwayat contoh yang tersedia, termasuk transfer, biaya admin, dan
        perpindahan dana target. Data awal bukan ledger historis lengkap.
      </p>
      <p v-if="!history.length" class="planning-empty">
        Belum ada mutasi tercatat.
      </p>
      <ul class="goal-history wallet-history">
        <li v-for="row in history" :key="row.id">
          <div>
            <b>{{ row.title }}</b
            ><small>{{ row.date }} · {{ row.detail }}</small>
          </div>
          <strong :class="row.amount < 0n ? 'amount-minus' : 'amount-plus'">{{
            amount(row.amount)
          }}</strong>
        </li>
      </ul>
      <button class="secondary-button full-width" @click="selected = undefined">
        Tutup
      </button></AppDialog
    >
    <AppDialog
      v-if="archive"
      :title="archive.archived ? 'Aktifkan Dompet' : 'Arsipkan Dompet'"
      :description="archive.name"
      @close="archive = undefined"
      ><p class="planning-muted">
        {{
          archive.archived
            ? "Dompet dapat digunakan kembali untuk transaksi baru."
            : "Dompet tidak dapat digunakan untuk transaksi baru. Saldo dan riwayat tetap tersimpan serta tetap dihitung sebagai aset."
        }}
      </p>
      <p v-if="error" class="form-error" role="alert">{{ error }}</p>
      <div class="dialog-actions">
        <button class="secondary-button" @click="archive = undefined">
          Batal</button
        ><button class="primary-button" @click="changeArchive">
          {{ archive.archived ? "Aktifkan" : "Arsipkan" }}
        </button>
      </div></AppDialog
    >
  </div>
</template>
