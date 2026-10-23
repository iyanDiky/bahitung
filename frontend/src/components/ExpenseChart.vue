<script setup lang="ts">
import { expenseCategories, formatIDR, expenseTotal } from "../data/mock";
</script>
<template>
  <section class="surface panel">
    <div class="section-heading">
      <strong>Analisis Pengeluaran</strong
      ><RouterLink to="/pratinjau/analisis">Lihat Semua</RouterLink>
    </div>
    <div class="analysis-body">
      <div class="donut">
        <div class="donut-hole">
          <small>Total</small><b>{{ formatIDR(expenseTotal) }}</b>
        </div>
      </div>
      <div class="legend">
        <div v-for="(c, i) in expenseCategories" :key="c.n">
          <i :class="'dot-' + i"></i><span>{{ c.n }}</span
          ><span class="legend-amount">{{ formatIDR(c.amount) }}</span
          ><b>{{ c.p }}</b>
        </div>
      </div>
    </div>
  </section>
</template>
