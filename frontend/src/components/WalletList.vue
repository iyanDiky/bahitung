<script setup lang="ts">
import { Landmark, Smartphone, Wallet, ChevronRight } from "lucide-vue-next";
import { formatIDR, type WalletMock } from "../data/mock";
defineProps<{ wallets: WalletMock[] }>();
</script>
<template>
  <section class="surface panel">
    <div class="section-heading">
      <strong>Dompet Keluarga</strong
      ><RouterLink to="/pratinjau/dompet">Lihat Semua</RouterLink>
    </div>
    <div v-for="w in wallets" :key="w.name" class="wallet-row">
      <div :class="['square-icon', w.color]">
        <Landmark v-if="w.icon === 'bank'" :size="19" /><Smartphone
          v-else-if="w.icon === 'phone'"
          :size="19"
        /><Wallet v-else :size="19" />
      </div>
      <div class="flex-grow">
        <b>{{ w.name }}</b
        ><small>{{ w.type }}</small>
      </div>
      <strong>{{ formatIDR(w.balance) }}</strong
      ><ChevronRight :size="15" color="#87909e" />
    </div>
  </section>
</template>
