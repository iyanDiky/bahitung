<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import {
  ArrowDownLeft,
  ArrowUpRight,
  ArrowLeftRight,
  Search,
  Plus,
  ChevronLeft,
  ChevronRight,
  Inbox,
} from "lucide-vue-next";
import TransferDialog from "../components/TransferDialog.vue";
import {
  transactionRows,
  transactionWallets,
  walletName,
  type Kind,
} from "../data/transactionMock";
import { cents, decimal, money } from "../utils/money";
const route = useRoute();
const router = useRouter();
const transferOpen = ref(route.query.action === "transfer");
const notice = ref("");
const kind = ref<Kind | "all">("all");
const query = ref("");
const wallet = ref("");
const category = ref("");
const author = ref("");
const from = ref("2026-10-01");
const to = ref("2026-10-31");
const page = ref(1);
const perPage = 10;
const labels = {
  all: "Semua",
  income: "Pemasukan",
  expense: "Pengeluaran",
  transfer: "Transfer",
};
const tabs = ["all", "income", "expense", "transfer"] as const;
const icons = {
  income: ArrowDownLeft,
  expense: ArrowUpRight,
  transfer: ArrowLeftRight,
};
const categories = computed(() =>
  [...new Set(transactionRows.map((row) => row.category))].sort(),
);
const authors = computed(() => [
  ...new Set(transactionRows.map((row) => row.author)),
]);
const invalidDates = computed(
  () => !!from.value && !!to.value && from.value > to.value,
);
const matches = computed(() =>
  transactionRows
    .filter((row) => {
      const text =
        `${row.title} ${row.category} ${row.author} ${walletName(row.walletId)} ${walletName(row.destinationId)}`.toLocaleLowerCase(
          "id",
        );
      return (
        !invalidDates.value &&
        (!from.value || row.date >= from.value) &&
        (!to.value || row.date <= to.value) &&
        (!wallet.value ||
          row.walletId === wallet.value ||
          row.destinationId === wallet.value) &&
        (!category.value || row.category === category.value) &&
        (!author.value || row.author === author.value) &&
        text.includes(query.value.trim().toLocaleLowerCase("id"))
      );
    })
    .sort((a, b) => b.date.localeCompare(a.date)),
);
const filtered = computed(() =>
  matches.value.filter(
    (row) => kind.value === "all" || row.type === kind.value,
  ),
);
const pageCount = computed(() =>
  Math.max(1, Math.ceil(filtered.value.length / perPage)),
);
const visible = computed(() =>
  filtered.value.slice((page.value - 1) * perPage, page.value * perPage),
);
const totals = computed(() =>
  (["income", "expense", "transfer"] as const).map((type) => ({
    type,
    amount: decimal(
      matches.value
        .filter((row) => row.type === type)
        .reduce((sum, row) => sum + cents(row.amount), 0n),
    ),
  })),
);
watch([kind, query, wallet, category, author, from, to], () => {
  page.value = 1;
});
watch(pageCount, (value) => {
  page.value = Math.min(page.value, value);
});
watch(
  () => route.query.action,
  (value) => {
    if (value === "transfer") transferOpen.value = true;
  },
);
function resetFilters() {
  kind.value = "all";
  query.value = "";
  wallet.value = "";
  category.value = "";
  author.value = "";
  from.value = "2026-10-01";
  to.value = "2026-10-31";
  page.value = 1;
}
function closeTransfer() {
  transferOpen.value = false;
  if (route.query.action) {
    const query = { ...route.query };
    delete query.action;
    void router.replace({ query });
  }
}
function saved(id: string) {
  const row = transactionRows.find((item) => item.id === id)!;
  resetFilters();
  from.value = row.date.slice(0, 7) + "-01";
  to.value = row.date;
  notice.value =
    "Transfer ditambahkan ke data contoh. Perubahan hanya berlaku selama sesi ini dan akan kembali semula saat halaman dimuat ulang.";
  closeTransfer();
}
function dateLabel(date: string) {
  return new Intl.DateTimeFormat("id-ID", {
    day: "numeric",
    month: "short",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(date + "T00:00:00Z"));
}
</script>
<template>
  <div class="page transactions-page">
    <div class="transaction-summary">
      <article
        v-for="summary in totals"
        :key="summary.type"
        class="surface transaction-summary-card"
      >
        <div
          class="icon-surface"
          :class="summary.type === 'expense' ? 'rose' : 'blue'"
        >
          <component :is="icons[summary.type]" :size="26" />
        </div>
        <div>
          <p>{{ labels[summary.type] }} Periode Ini</p>
          <h2>{{ money(summary.amount) }}</h2>
          <small>{{
            summary.type === "transfer"
              ? "Pokok transfer, di luar biaya admin"
              : summary.type === "expense"
                ? "Termasuk biaya admin transfer"
                : "Total pemasukan keluarga"
          }}</small>
        </div>
      </article>
    </div>
    <div class="transaction-toolbar">
      <div class="transaction-tabs" aria-label="Jenis transaksi">
        <button
          v-for="tab in tabs"
          :key="tab"
          :class="{ selected: kind === tab }"
          :aria-pressed="kind === tab"
          @click="kind = tab"
        >
          <component v-if="tab !== 'all'" :is="icons[tab]" :size="17" />{{
            labels[tab]
          }}
          <span
            >({{
              matches.filter((row) => tab === "all" || row.type === tab).length
            }})</span
          >
        </button>
      </div>
      <button class="primary-button" @click="transferOpen = true">
        <Plus :size="18" /> Catat Transfer
      </button>
    </div>
    <div class="transaction-filters">
      <label>Dari tanggal<input v-model="from" type="date" /></label
      ><label>Sampai tanggal<input v-model="to" type="date" /></label
      ><label
        >Dompet<select v-model="wallet" aria-label="Dompet">
          <option value="">Semua dompet</option>
          <option
            v-for="item in transactionWallets"
            :key="item.id"
            :value="item.id"
          >
            {{ item.name }}
          </option>
        </select></label
      ><label
        >Kategori<select v-model="category" aria-label="Kategori">
          <option value="">Semua kategori</option>
          <option v-for="item in categories" :key="item">{{ item }}</option>
        </select></label
      ><label
        >Anggota<select v-model="author" aria-label="Anggota">
          <option value="">Semua anggota</option>
          <option v-for="item in authors" :key="item">{{ item }}</option>
        </select></label
      ><label class="transaction-search"
        >Cari transaksi
        <div>
          <Search :size="17" /><input
            v-model="query"
            type="search"
            placeholder="Cari deskripsi…"
          /></div
      ></label>
    </div>
    <p v-if="invalidDates" role="alert" class="form-error">
      Tanggal awal tidak boleh melewati tanggal akhir.
    </p>
    <p v-if="notice" role="status" class="transfer-notice">
      {{ notice
      }}<button aria-label="Tutup pemberitahuan" @click="notice = ''">×</button>
    </p>
    <section
      class="surface transaction-table-panel"
      aria-label="Daftar transaksi"
    >
      <div class="transaction-table-scroll">
        <table class="transaction-table">
          <thead>
            <tr>
              <th scope="col">Tanggal</th>
              <th scope="col">Deskripsi</th>
              <th scope="col">Anggota</th>
              <th scope="col">Dompet</th>
              <th scope="col">Kategori</th>
              <th scope="col" class="align-right">Jumlah</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in visible" :key="row.id">
              <td>{{ dateLabel(row.date) }}</td>
              <td>
                <div class="transaction-description">
                  <span
                    class="square-icon"
                    :class="row.type === 'expense' ? 'rose' : 'blue'"
                    ><component :is="icons[row.type]" :size="18"
                  /></span>
                  <div>
                    <strong>{{ row.title }}</strong
                    ><small
                      >{{ labels[row.type]
                      }}<template v-if="row.linkedTransferId">
                        · terkait transfer</template
                      ></small
                    ><small v-if="row.linkedTransferId">{{
                      transactionRows.find(
                        (item) => item.id === row.linkedTransferId,
                      )?.title
                    }}</small>
                  </div>
                </div>
              </td>
              <td>{{ row.author }}</td>
              <td>
                {{ walletName(row.walletId)
                }}<small v-if="row.destinationId" class="destination-name"
                  >→ {{ walletName(row.destinationId) }}</small
                >
              </td>
              <td>
                <span class="category-pill">{{ row.category }}</span>
              </td>
              <td
                class="align-right transaction-amount"
                :class="
                  row.type === 'income'
                    ? 'amount-plus'
                    : row.type === 'expense'
                      ? 'amount-minus'
                      : 'transfer-amount'
                "
              >
                {{
                  row.type === "income"
                    ? "+ "
                    : row.type === "expense"
                      ? "− "
                      : "↔ "
                }}{{ money(row.amount) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="!visible.length" class="transactions-empty">
        <Inbox :size="38" />
        <h2>Tidak ada transaksi yang cocok</h2>
        <p>Coba kata kunci lain atau sesuaikan filter.</p>
        <button class="secondary-button" @click="resetFilters">
          Reset filter
        </button>
      </div>
      <footer class="table-pagination">
        <span
          >Menampilkan {{ filtered.length ? (page - 1) * perPage + 1 : 0 }}–{{
            Math.min(page * perPage, filtered.length)
          }}
          dari {{ filtered.length }} transaksi</span
        >
        <div>
          <button
            class="secondary-button"
            aria-label="Halaman sebelumnya"
            :disabled="page <= 1"
            @click="page--"
          >
            <ChevronLeft :size="16" /></button
          ><span aria-live="polite">{{ page }} / {{ pageCount }}</span
          ><button
            class="secondary-button"
            aria-label="Halaman berikutnya"
            :disabled="page >= pageCount"
            @click="page++"
          >
            <ChevronRight :size="16" />
          </button>
        </div>
      </footer>
    </section>
    <TransferDialog
      :initial-source="
        typeof route.query.source === 'string' ? route.query.source : undefined
      "
      v-if="transferOpen"
      @close="closeTransfer"
      @saved="saved"
    />
  </div>
</template>
