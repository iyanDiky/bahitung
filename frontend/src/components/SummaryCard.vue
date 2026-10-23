<script setup lang="ts">
import { Wallet, TrendingUp, TrendingDown } from "lucide-vue-next";
import { formatIDR, type SummaryMock } from "../data/mock";
defineProps<{ card: SummaryMock }>();
const icons = { wallet: Wallet, income: TrendingUp, expense: TrendingDown };
</script>
<template>
  <div class="summary">
    <div :class="['icon-surface', card.tone]">
      <component :is="icons[card.icon]" :size="24" />
    </div>
    <div>
      <span class="muted tiny">{{ card.name }}</span>
      <h2>{{ formatIDR(card.amount) }}</h2>
      <small class="positive">{{ card.trend }}</small
      ><small class="muted"> dari bulan lalu</small>
    </div>
  </div>
</template>
