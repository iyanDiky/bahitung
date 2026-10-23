<script setup lang="ts">
import { Target } from "lucide-vue-next";
import { money, type Goal } from "../services/finance";
defineProps<{
  goals: Goal[];
  manage: boolean;
  write: boolean;
  locked: boolean;
  hasWallet: boolean;
}>();
defineEmits<{
  edit: [goal: Goal];
  deposit: [goal: Goal];
  withdraw: [goal: Goal];
  history: [goal: Goal];
}>();
</script>
<template>
  <div class="live-target-grid">
    <article v-for="g in goals" :key="g.id" class="live-target-card">
      <div class="live-target-heading">
        <span class="finance-tile"><Target /></span>
        <div>
          <h3>{{ g.name }}</h3>
          <small>{{
            g.is_archived
              ? "Diarsipkan"
              : g.is_reached
                ? "Target tercapai"
                : "Sedang dikumpulkan"
          }}</small>
        </div>
      </div>
      <p class="live-target-balance">
        {{ money(g.balance) }} <small>dari {{ money(g.target_amount) }}</small>
      </p>
      <progress
        :value="Math.min(100, Number(g.percent_saved))"
        max="100"
        :aria-label="'Progres ' + g.name"
      />
      <div class="live-target-progress">
        <span>{{ g.percent_saved.replace(".", ",") }}%</span
        ><span>Sisa {{ money(g.remaining_amount) }}</span>
      </div>
      <p class="account-muted">
        {{
          g.target_date
            ? "Tanggal target: " + g.target_date
            : "Tanpa batas tanggal"
        }}
      </p>
      <div class="live-target-actions">
        <button
          v-if="write && !g.is_archived"
          class="account-primary"
          :disabled="locked || !hasWallet"
          @click="$emit('deposit', g)"
        >
          Setor dana
        </button>
        <button
          v-if="manage && !g.is_archived"
          class="account-secondary"
          :disabled="locked || !hasWallet || g.balance === '0.00'"
          @click="$emit('withdraw', g)"
        >
          Tarik dana
        </button>
        <button
          class="account-secondary"
          :disabled="locked"
          @click="$emit('history', g)"
        >
          Riwayat target
        </button>
        <button
          v-if="manage"
          class="account-text-button"
          :disabled="locked"
          @click="$emit('edit', g)"
        >
          Kelola target
        </button>
      </div>
    </article>
  </div>
</template>
