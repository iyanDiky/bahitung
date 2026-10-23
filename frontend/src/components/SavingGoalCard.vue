<script setup lang="ts">
import { Plus, Pencil, ArrowUpRight } from "lucide-vue-next";
import { progress, type SavingGoal } from "../data/planningMock";
import { cents, money } from "../utils/money";
defineProps<{ goal: SavingGoal }>();
defineEmits<{
  action: [mode: "detail" | "edit" | "deposit" | "withdraw", goal: SavingGoal];
}>();
</script>
<template>
  <article class="saving-goal-card">
    <div class="saving-goal-heading">
      <span class="saving-goal-icon" aria-hidden="true">{{ goal.icon }}</span>
      <div>
        <h3>{{ goal.name }}</h3>
        <small>{{ goal.due ? "Target " + goal.due : "Tanpa tenggat" }}</small>
      </div>
      <button
        class="goal-edit"
        :aria-label="'Edit target ' + goal.name"
        @click="$emit('action', 'edit', goal)"
      >
        <Pencil :size="15" />
      </button>
    </div>
    <div class="saving-goal-amount">
      <strong>{{ money(goal.balance) }}</strong
      ><span> / {{ money(goal.target) }}</span>
    </div>
    <div class="saving-goal-progress">
      <div
        class="progress"
        role="progressbar"
        :aria-label="'Progres ' + goal.name"
        :aria-valuenow="
          Math.min(100, progress(cents(goal.balance), cents(goal.target)))
        "
        aria-valuemin="0"
        aria-valuemax="100"
      >
        <div
          :style="{
            width:
              Math.min(100, progress(cents(goal.balance), cents(goal.target))) +
              '%',
          }"
        ></div>
      </div>
      <span>{{ progress(cents(goal.balance), cents(goal.target)) }}%</span>
    </div>
    <p v-if="cents(goal.balance) >= cents(goal.target)" class="goal-achieved">
      Target tercapai 🎉
    </p>
    <div class="saving-goal-actions">
      <button
        class="primary-button"
        :aria-label="'Setor dana ' + goal.name"
        @click="$emit('action', 'deposit', goal)"
      >
        <Plus :size="16" /> Setor Dana</button
      ><button
        class="secondary-button"
        :aria-label="'Detail ' + goal.name"
        @click="$emit('action', 'detail', goal)"
      >
        Lihat Detail
      </button>
    </div>
    <button
      class="goal-withdraw"
      :aria-label="'Tarik dana ' + goal.name"
      :disabled="cents(goal.balance) === 0n"
      @click="$emit('action', 'withdraw', goal)"
    >
      <ArrowUpRight :size="14" /> Tarik ke dompet
    </button>
  </article>
</template>
