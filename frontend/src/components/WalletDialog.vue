<script setup lang="ts">
import { reactive, ref } from "vue";
import AppDialog from "./AppDialog.vue";
import { saveWallet, walletTypes, type WalletRecord } from "../data/walletMock";
const props = defineProps<{ wallet?: WalletRecord }>();
const emit = defineEmits<{ close: []; saved: [] }>();
const form = reactive({
  name: props.wallet?.name ?? "",
  type: props.wallet?.type ?? "Tunai",
  opening: "0",
  date: "2026-10-15",
});
const error = ref("");
function save() {
  try {
    saveWallet({ ...form, id: props.wallet?.id });
    emit("saved");
  } catch (cause) {
    error.value = (cause as Error).message;
  }
}
</script>
<template>
  <AppDialog
    :title="wallet ? 'Edit Dompet' : 'Tambah Dompet'"
    description="Dompet bersama yang dapat dilihat seluruh anggota keluarga."
    @close="emit('close')"
    ><form @submit.prevent="save">
      <label class="form-field"
        >Nama Dompet<input
          v-model="form.name"
          aria-label="Nama Dompet"
          required
          maxlength="60"
          placeholder="Contoh: BCA Keluarga" /></label
      ><label class="form-field"
        >Jenis Dompet<select v-model="form.type" aria-label="Jenis Dompet">
          <option v-for="type in walletTypes" :key="type">{{ type }}</option>
        </select></label
      ><template v-if="!wallet"
        ><label class="form-field"
          >Saldo Awal (Rp)<input
            v-model="form.opening"
            aria-label="Saldo Awal (Rp)"
            inputmode="decimal"
            maxlength="20"
            required
          /><small
            >Tanpa pemisah ribuan; gunakan titik untuk desimal.</small
          ></label
        ><label class="form-field"
          >Tanggal Pembukaan<input v-model="form.date" type="date" required
        /></label>
        <p class="transfer-info">
          Saldo awal menambah saldo dompet, tetapi tidak dihitung sebagai
          pemasukan.
        </p></template
      >
      <p v-else class="transfer-info">
        Edit nama atau jenis dompet tidak mengubah saldo dan riwayat transaksi.
      </p>
      <p v-if="error" class="form-error" role="alert">{{ error }}</p>
      <div class="dialog-actions">
        <button type="button" class="secondary-button" @click="emit('close')">
          Batal</button
        ><button class="primary-button">Simpan Dompet</button>
      </div>
    </form></AppDialog
  >
</template>
