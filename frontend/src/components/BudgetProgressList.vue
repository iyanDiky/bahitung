<script setup lang="ts">
import { formatIDR, type BudgetMock } from "../data/mock";
defineProps<{ budgets: BudgetMock[] }>();
</script>
<template>
  <section class="surface panel">
    <div class="section-heading">
      <strong>Budget Bulan Ini</strong
      ><RouterLink to="/pratinjau/budget">Lihat Semua</RouterLink>
    </div>
    <div v-for="b in budgets" :key="b.name" class="budget-row">
      <span class="emoji">{{ b.icon }}</span>
      <div class="flex-grow">
        <div class="budget-name">
          <b>{{ b.name }}</b
          ><small>{{ formatIDR(b.spent) }} / {{ formatIDR(b.limit) }}</small>
        </div>
        <div class="progress">
          <div
            :style="{
              width: Math.min((b.spent / b.limit) * 100, 100) + '%',
              background: b.spent / b.limit >= 1 ? '#ef526b' : 'var(--brand-bright)',
            }"
          ></div>
        </div>
      </div>
      <b class="percentage">{{ Math.round((b.spent / b.limit) * 100) }}%</b>
    </div>
  </section>
</template>
