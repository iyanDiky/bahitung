<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, reactive, ref } from "vue";
import { X, Info, ArrowRight } from "lucide-vue-next";
import {
  transactionWallets,
  expenseCategories,
  recordMockTransfer,
} from "../data/transactionMock";
import { cents, decimal, money, transferPreview } from "../utils/money";
const props = defineProps<{ initialSource?: string }>();
const activeWallets = transactionWallets.filter((wallet) => !wallet.archived);
const startingSource =
  activeWallets.find((wallet) => wallet.id === props.initialSource)?.id ??
  activeWallets.find((wallet) => wallet.id === "2")?.id ??
  activeWallets[0]?.id ??
  "";
const emit = defineEmits<{ close: []; saved: [id: string] }>();
const dialog = ref<HTMLDialogElement>();
const form = reactive({
  source: startingSource,
  destination:
    activeWallets.find((wallet) => wallet.id !== startingSource)?.id ?? "",
  amount: "",
  fee: "0",
  category: "",
  date: "2026-10-15",
  note: "",
});
const error = ref("");
const saving = ref(false);
const source = computed(() =>
  transactionWallets.find((w) => w.id === form.source),
);
const destination = computed(() =>
  transactionWallets.find((w) => w.id === form.destination),
);
const preview = computed(() => {
  try {
    return transferPreview(
      source.value?.balance ?? "0",
      form.amount,
      form.fee || "0",
    );
  } catch {
    return null;
  }
});
const destinationAfter = computed(() =>
  preview.value && destination.value
    ? money(
        decimal(cents(destination.value.balance) + cents(preview.value.amount)),
      )
    : "—",
);
let previousFocus: HTMLElement | null = null;
let overflow = "";
onMounted(() => {
  previousFocus = document.activeElement as HTMLElement;
  overflow = document.body.style.overflow;
  document.body.style.overflow = "hidden";
  dialog.value?.showModal();
});
onBeforeUnmount(() => {
  document.body.style.overflow = overflow;
  previousFocus?.focus();
});
function save() {
  if (saving.value) return;
  error.value = "";
  saving.value = true;
  try {
    emit("saved", recordMockTransfer({ ...form, fee: form.fee || "0" }));
  } catch (cause) {
    error.value =
      cause instanceof Error ? cause.message : "Transfer belum dapat dicatat.";
    saving.value = false;
  }
}
</script>
<template>
  <dialog
    ref="dialog"
    class="transfer-dialog"
    aria-labelledby="transfer-title"
    aria-describedby="transfer-description"
    @cancel.prevent="emit('close')"
    @click="$event.target === dialog && emit('close')"
  >
    <form @submit.prevent="save">
      <div class="dialog-heading">
        <div>
          <h2 id="transfer-title">Transfer Antar Dompet</h2>
          <p id="transfer-description">
            Pindahkan saldo antar dompet keluarga.
          </p>
        </div>
        <button
          type="button"
          class="dialog-close"
          aria-label="Tutup dialog transfer"
          @click="emit('close')"
        >
          <X :size="20" />
        </button>
      </div>
      <label class="form-field"
        >Dompet Asal<select
          v-model="form.source"
          aria-label="Dompet Asal"
          autofocus
          required
        >
          <option
            v-for="wallet in transactionWallets.filter(
              (item) => !item.archived,
            )"
            :key="wallet.id"
            :value="wallet.id"
          >
            {{ wallet.name }} · {{ money(wallet.balance) }}
          </option>
        </select></label
      >
      <label class="form-field"
        >Dompet Tujuan<select
          v-model="form.destination"
          aria-label="Dompet Tujuan"
          required
        >
          <option value="" disabled>Pilih dompet tujuan</option>
          <option
            v-for="wallet in transactionWallets.filter(
              (item) => !item.archived,
            )"
            :key="wallet.id"
            :value="wallet.id"
            :disabled="wallet.id === form.source"
          >
            {{ wallet.name }} · {{ money(wallet.balance) }}
          </option>
        </select></label
      >
      <label class="form-field"
        >Nominal Transfer (Rp)<input
          v-model="form.amount"
          aria-label="Nominal Transfer (Rp)"
          inputmode="decimal"
          placeholder="500000"
          required
          maxlength="20"
          aria-describedby="amount-help"
        /><small id="amount-help"
          >Tanpa pemisah ribuan; gunakan titik untuk desimal.</small
        ></label
      >
      <div class="form-two">
        <label class="form-field"
          >Biaya Admin (Rp)<input
            v-model="form.fee"
            aria-label="Biaya Admin (Rp)"
            inputmode="decimal"
            placeholder="0"
            maxlength="20" /></label
        ><label class="form-field"
          >Kategori Biaya Admin<select
            v-model="form.category"
            aria-label="Kategori Biaya Admin"
            :required="!!form.fee && !/^0+(\.0{1,2})?$/.test(form.fee)"
          >
            <option value="">Pilih kategori</option>
            <option v-for="category in expenseCategories" :key="category">
              {{ category }}
            </option>
          </select></label
        >
      </div>
      <p class="transfer-info">
        <Info :size="18" />Biaya admin dicatat sebagai pengeluaran terpisah yang
        terkait dengan transfer. Pokok transfer tidak dihitung sebagai
        pengeluaran.
      </p>
      <label class="form-field"
        >Tanggal Transaksi<input
          v-model="form.date"
          aria-label="Tanggal Transaksi"
          type="date"
          required
      /></label>
      <label class="form-field"
        >Catatan (opsional)<textarea
          v-model="form.note"
          aria-label="Catatan (opsional)"
          rows="2"
          maxlength="100"
          placeholder="Contoh: dana pendidikan bulan Oktober"
        ></textarea
        ><small class="character-count"
          >{{ form.note.length }}/100</small
        ></label
      >
      <div
        v-if="preview && form.source !== form.destination"
        class="transfer-preview"
        aria-live="polite"
      >
        <strong>Ringkasan transfer</strong>
        <div>
          <span>Total dipotong</span><b>{{ money(preview.debit) }}</b>
        </div>
        <div>
          <span>Diterima tujuan</span><b>{{ money(preview.amount) }}</b>
        </div>
        <div>
          <span>Saldo asal setelah transfer</span
          ><b>{{ money(preview.remaining) }}</b>
        </div>
        <div>
          <span>Saldo tujuan setelah transfer</span
          ><b>{{ destinationAfter }}</b>
        </div>
      </div>
      <p v-if="error" class="form-error" role="alert">{{ error }}</p>
      <div class="dialog-actions">
        <button type="button" class="secondary-button" @click="emit('close')">
          Batal</button
        ><button class="primary-button" :disabled="saving">
          Catat Transfer <ArrowRight :size="17" />
        </button>
      </div>
    </form>
  </dialog>
</template>
