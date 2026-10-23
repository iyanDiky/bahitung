<script setup lang="ts">
import { computed, reactive, ref } from "vue";
import AppDialog from "./AppDialog.vue";
import {
  goalMovements,
  moveGoal,
  saveGoal,
  type SavingGoal,
} from "../data/planningMock";
import { transactionWallets, walletName } from "../data/transactionMock";
import { cents, decimal, money } from "../utils/money";
const props = defineProps<{
  mode: "create" | "edit" | "deposit" | "withdraw" | "detail";
  goal?: SavingGoal;
}>();
const emit = defineEmits<{ close: []; saved: [] }>();
const titles = {
  create: "Buat Target Tabungan",
  edit: "Edit Target Tabungan",
  deposit: "Setor Dana Target",
  withdraw: "Tarik Dana Target",
  detail: "Detail Target Tabungan",
};
const form = reactive({
  name: props.goal?.name ?? "",
  target: props.goal?.target ?? "",
  due: props.goal?.due ?? "",
  walletId: transactionWallets.find((w) => !w.archived)?.id ?? "",
  amount: "",
  date: "2026-10-15",
  note: "",
});
const error = ref("");
const editing = computed(
  () => props.mode === "create" || props.mode === "edit",
);
const history = computed(() =>
  goalMovements.filter((item) => item.goalId === props.goal?.id),
);
const preview = computed(() => {
  try {
    const wallet = transactionWallets.find(
      (item) => item.id === form.walletId,
    )!;
    const amount = cents(form.amount),
      balance = cents(props.goal?.balance ?? "0");
    const walletAfter =
      cents(wallet.balance) + (props.mode === "deposit" ? -amount : amount);
    const goalAfter = balance + (props.mode === "deposit" ? amount : -amount);
    if (!amount || walletAfter < 0n || goalAfter < 0n) return null;
    return {
      wallet: money(decimal(walletAfter)),
      goal: money(decimal(goalAfter)),
    };
  } catch {
    return null;
  }
});
function save() {
  try {
    if (editing.value)
      saveGoal({
        id: props.mode === "edit" ? props.goal?.id : undefined,
        name: form.name,
        target: form.target,
        due: form.due,
        icon: "🎯",
      });
    else if (
      props.goal &&
      (props.mode === "deposit" || props.mode === "withdraw")
    )
      moveGoal({
        goalId: props.goal.id,
        walletId: form.walletId,
        type: props.mode,
        amount: form.amount,
        date: form.date,
        note: form.note,
      });
    emit("saved");
  } catch (cause) {
    error.value = (cause as Error).message;
  }
}
</script>
<template>
  <AppDialog
    :title="titles[mode]"
    :description="goal?.name"
    @close="emit('close')"
  >
    <template v-if="mode === 'detail' && goal"
      ><div class="goal-detail-balance">
        <span>Dana terkumpul</span><strong>{{ money(goal.balance) }}</strong
        ><small
          >dari {{ money(goal.target) }} ·
          {{ goal.due ? "Target " + goal.due : "Tanpa tenggat" }}</small
        >
      </div>
      <h3 class="history-heading">Aktivitas target</h3>
      <p v-if="!history.length" class="planning-muted">
        Belum ada aktivitas baru untuk target ini. Dana awal ditampilkan pada
        saldo di atas.
      </p>
      <ul class="goal-history">
        <li v-for="item in history" :key="item.id">
          <div>
            <b
              >{{ item.type === "deposit" ? "Setor dari" : "Tarik ke" }}
              {{ walletName(item.walletId) }}</b
            ><small
              >{{ item.date }}{{ item.note ? " · " + item.note : "" }}</small
            >
          </div>
          <strong
            :class="item.type === 'deposit' ? 'amount-plus' : 'amount-minus'"
            >{{ item.type === "deposit" ? "+" : "−" }}
            {{ money(item.amount) }}</strong
          >
        </li>
      </ul>
      <button class="secondary-button full-width" @click="emit('close')">
        Tutup
      </button></template
    >
    <form v-else @submit.prevent="save">
      <template v-if="editing"
        ><label class="form-field"
          >Nama Target<input
            v-model="form.name"
            aria-label="Nama Target"
            maxlength="60"
            required
            placeholder="Contoh: Dana darurat" /></label
        ><label class="form-field"
          >Nominal Target (Rp)<input
            v-model="form.target"
            aria-label="Nominal Target (Rp)"
            inputmode="decimal"
            maxlength="20"
            required
            placeholder="15000000"
          /><small
            >Tanpa pemisah ribuan; gunakan titik untuk desimal.</small
          ></label
        ><label class="form-field"
          >Tanggal Target (opsional)<input v-model="form.due" type="date"
        /></label>
        <p class="transfer-info">
          Dana target berasal dari setoran dompet keluarga. Membuat target tidak
          mengubah saldo dompet.
        </p></template
      >
      <template v-else
        ><label class="form-field"
          >{{ mode === "deposit" ? "Dompet Sumber" : "Dompet Tujuan"
          }}<select
            v-model="form.walletId"
            aria-label="Dompet untuk target"
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
        ><label class="form-field"
          >Nominal (Rp)<input
            v-model="form.amount"
            aria-label="Nominal (Rp)"
            inputmode="decimal"
            maxlength="20"
            required
            placeholder="100000"
          /><small
            >Tanpa pemisah ribuan; gunakan titik untuk desimal.</small
          ></label
        ><label class="form-field"
          >Tanggal<input v-model="form.date" type="date" required /></label
        ><label class="form-field"
          >Catatan (opsional)<textarea
            v-model="form.note"
            maxlength="100"
            rows="2"
          ></textarea>
        </label>
        <p class="transfer-info">
          {{
            mode === "deposit"
              ? "Setoran mengurangi saldo tersedia dan menambah dana target."
              : "Penarikan mengurangi dana target dan mengembalikan saldo ke dompet."
          }}
          Total aset keluarga tetap; ini bukan pemasukan atau pengeluaran.
        </p>
        <div v-if="preview" class="transfer-preview">
          <strong>Saldo setelah pencatatan</strong>
          <div>
            <span>Dompet</span><b>{{ preview.wallet }}</b>
          </div>
          <div>
            <span>Dana target</span><b>{{ preview.goal }}</b>
          </div>
        </div></template
      >
      <p v-if="error" class="form-error" role="alert">{{ error }}</p>
      <div class="dialog-actions">
        <button type="button" class="secondary-button" @click="emit('close')">
          Batal</button
        ><button class="primary-button">
          {{
            editing
              ? "Simpan Target"
              : mode === "deposit"
                ? "Catat Setoran"
                : "Catat Penarikan"
          }}
        </button>
      </div>
    </form>
  </AppDialog>
</template>
