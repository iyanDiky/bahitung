<script setup lang="ts">
import { onMounted, ref } from "vue";
const menu = ref<HTMLElement>();
onMounted(() => {
  const nav = menu.value,
    active = nav?.querySelector<HTMLElement>(".router-link-active");
  if (nav && active && window.matchMedia("(max-width: 760px)").matches)
    nav.scrollLeft =
      active.offsetLeft -
      nav.offsetLeft -
      (nav.clientWidth - active.clientWidth) / 2;
});
import {
  Settings,
  LayoutDashboard,
  Wallet as WalletIcon,
  ArrowLeftRight,
  Users,
  ChartPie,
  Target,
  ChartNoAxesCombined,
} from "lucide-vue-next";
</script>
<template>
  <aside class="finance-sidebar">
    <RouterLink class="account-brand" to="/dashboard"
      ><span>b</span>bahitung</RouterLink
    >
    <nav ref="menu" aria-label="Menu keuangan">
      <RouterLink to="/dashboard"
        ><LayoutDashboard :size="20" />Dashboard</RouterLink
      ><RouterLink to="/transaksi"
        ><ArrowLeftRight :size="20" />Transaksi</RouterLink
      ><RouterLink to="/dompet"><WalletIcon :size="20" />Dompet</RouterLink
      ><RouterLink to="/budget"><ChartPie :size="20" />Budget</RouterLink
      ><RouterLink to="/target"><Target :size="20" />Target</RouterLink
      ><RouterLink to="/analisis"
        ><ChartNoAxesCombined :size="20" />Analisis</RouterLink
      ><RouterLink to="/anggota"><Users :size="20" />Anggota</RouterLink
      ><RouterLink to="/pengaturan"
        ><Settings :size="20" />Pengaturan</RouterLink
      ><RouterLink to="/keluarga"><Users :size="20" />Keluarga</RouterLink>
    </nav>
    <div class="finance-sidebar-note">
      <strong>Lebih tertata, bersama.</strong>
      <p>Satu tempat untuk keuangan keluarga Anda.</p>
    </div>
  </aside>
</template>
