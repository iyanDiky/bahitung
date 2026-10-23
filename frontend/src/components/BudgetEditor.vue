<script setup lang="ts">
import { ref, computed } from "vue";
import AppDialog from "./AppDialog.vue";
import {
  monthlyBudgets,
  saveBudget,
  spending,
  type BudgetLine,
} from "../data/planningMock";
import { expenseCategories } from "../data/transactionMock";
import { cents, decimal, money } from "../utils/money";
const props = defineProps<{ month: string; line?: BudgetLine }>();
const emit = defineEmits<{ close: []; saved: [] }>();
const category = ref(props.line?.category ?? "");
const limit = ref(props.line?.limit ?? "");
const error = ref("");
const available = computed(() =>
  expenseCategories.filter(
    (category) =>
      !monthlyBudgets[props.month]?.some((line) => line.category === category),
  ),
);
const warning = computed(() => {
  try {
    return (
      cents(limit.value) < spending(props.month, category.value) &&
      !!category.value
    );
  } catch {
    return false;
  }
});
function save() {
  try {
    saveBudget(props.month, category.value, limit.value, !!props.line);
    emit("saved");
  } catch (cause) {
    error.value = (cause as Error).message;
  }
}
</script>
<template>
  <AppDialog
    :title="line ? 'Edit Budget Kategori' : 'Tambah Budget Kategori'"
    :description="'Anggaran untuk ' + month"
    @close="emit('close')"
    ><form @submit.prevent="save">
      <label class="form-field"
        >Kategori<select
          v-model="category"
          aria-label="Kategori budget"
          :disabled="!!line"
          required
        >
          <option value="" disabled>Pilih kategori</option>
          <option v-if="line">{{ line.category }}</option>
          <option v-for="item in available" :key="item">{{ item }}</option>
        </select></label
      >
      <label class="form-field"
        >Batas Anggaran (Rp)<input
          v-model="limit"
          aria-label="Batas Anggaran (Rp)"
          inputmode="decimal"
          placeholder="1000000"
          maxlength="20"
          required
        /><small
          >Tanpa pemisah ribuan; gunakan titik untuk desimal.</small
        ></label
      >
      <p v-if="warning" class="budget-warning">
        Pengeluaran {{ money(decimal(spending(month, category))) }} sudah
        melampaui batas ini. Budget tetap dapat disimpan; peringatan tidak
        memblokir transaksi.
      </p>
      <p v-if="error" class="form-error" role="alert">{{ error }}</p>
      <div class="dialog-actions">
        <button type="button" class="secondary-button" @click="emit('close')">
          Batal</button
        ><button class="primary-button">Simpan Budget</button>
      </div>
    </form></AppDialog
  >
</template>
