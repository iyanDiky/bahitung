<script setup lang="ts">
import { formatIDR, type TransactionMock } from "../data/mock";
defineProps<{ transactions: TransactionMock[] }>();
</script>
<template>
  <section class="surface panel">
    <div class="section-heading">
      <strong>Transaksi Terbaru</strong
      ><RouterLink to="/pratinjau/transaksi">Lihat Semua</RouterLink>
    </div>
    <div v-for="t in transactions" :key="t.name" class="tx-row">
      <small class="tx-date">{{ t.date }}</small>
      <div class="square-icon light">{{ t.icon }}</div>
      <div class="flex-grow">
        <b>{{ t.name }}</b
        ><small>{{ t.category }}</small>
      </div>
      <strong :class="t.amount > 0 ? 'amount-plus' : 'amount-minus'"
        >{{ t.amount > 0 ? "+ " : "− "
        }}{{ formatIDR(Math.abs(t.amount)) }}</strong
      >
    </div>
  </section>
</template>
