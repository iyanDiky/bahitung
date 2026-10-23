<script setup lang="ts">
import {
  LayoutDashboard,
  ArrowLeftRight,
  Wallet,
  ChartPie,
  Target,
  ChartNoAxesCombined,
  Users,
  Settings,
  X,
} from "lucide-vue-next";
import { useRoute } from "vue-router";
defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();
const route = useRoute();
const links = [
  { to: "/pratinjau", text: "Dashboard", icon: LayoutDashboard },
  { to: "/pratinjau/transaksi", text: "Transaksi", icon: ArrowLeftRight },
  { to: "/pratinjau/dompet", text: "Dompet", icon: Wallet },
  { to: "/pratinjau/budget", text: "Budget", icon: ChartPie },
  { to: "/pratinjau/target", text: "Target", icon: Target },
  { to: "/pratinjau/analisis", text: "Analisis", icon: ChartNoAxesCombined },
  { to: "/pratinjau/anggota", text: "Anggota", icon: Users },
  { to: "/pratinjau/pengaturan", text: "Pengaturan", icon: Settings },
];
</script>
<template>
  <aside
    id="app-sidebar"
    aria-label="Menu utama"
    class="sidebar"
    :class="{ visible: open }"
  >
    <div class="brand">
      <div class="brand-icon">b</div>
      <span>bahitung</span
      ><button
        aria-label="Tutup menu"
        class="side-close"
        @click="emit('close')"
      >
        <X :size="18" />
      </button>
    </div>
    <nav aria-label="Navigasi utama" class="navlinks">
      <template v-for="(item, i) in links" :key="item.to"
        ><div v-if="i === 7" class="nav-divider"></div>
        <RouterLink
          :to="item.to"
          class="navlink"
          :class="{ active: route.path === item.to }"
          @click="emit('close')"
          ><component :is="item.icon" :size="19" :stroke-width="2" /><span>{{
            item.text
          }}</span></RouterLink
        ></template
      >
    </nav>
    <div class="sidebar-foot">
      <strong>💙 bahitung</strong>
      <p>Keuangan keluarga lebih tertata, bersama-sama.</p>
    </div>
  </aside>
</template>
