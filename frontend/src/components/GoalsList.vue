<script setup lang="ts">
import { formatIDR, type GoalMock } from "../data/mock";
defineProps<{ goals: GoalMock[] }>();
</script>
<template>
  <section class="surface panel">
    <div class="section-heading">
      <strong>Target Tabungan Aktif</strong
      ><RouterLink to="/pratinjau/target">Lihat Semua</RouterLink>
    </div>
    <div v-for="g in goals" :key="g.name" class="goal-row">
      <span class="goal-image">{{ g.icon }}</span>
      <div class="flex-grow">
        <b>{{ g.name }}</b
        ><small>{{ formatIDR(g.current) }} / {{ formatIDR(g.target) }}</small>
        <div class="progress">
          <div
            :style="{
              width: Math.min(100, (100 * g.current) / g.target) + '%',
            }"
          ></div>
        </div>
      </div>
      <span class="percentage"
        >{{ Math.round((100 * g.current) / g.target) }}%</span
      >
    </div>
  </section>
</template>
