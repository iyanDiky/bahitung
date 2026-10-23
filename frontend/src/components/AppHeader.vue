<script setup lang="ts">
import { Menu, Bell, Search } from "lucide-vue-next";
import { household } from "../data/householdMock";
import { useRoute } from "vue-router";
defineProps<{ menuOpen: boolean }>();
defineEmits<{ openMenu: [] }>();
const route = useRoute();
</script>
<template>
  <header class="topbar">
    <button
      id="menu-toggle"
      class="mobile-menu icon-button"
      aria-label="Buka menu"
      aria-controls="app-sidebar"
      :aria-expanded="menuOpen"
      @click="$emit('openMenu')"
    >
      <Menu :size="22" />
    </button>
    <div class="header-greeting" v-if="route.path === '/pratinjau'">
      <h1>Selamat pagi, {{ household.name }}! 👋</h1>
      <p>Ini ringkasan keuangan keluarga Anda hari ini.</p>
    </div>
    <div class="header-greeting" v-else-if="route.path === '/pratinjau/transaksi'">
      <h1>Transaksi</h1>
      <p>Catat setiap pemasukan, pengeluaran, dan transfer keluarga.</p>
    </div>
    <div
      class="header-greeting"
      v-else-if="route.path === '/pratinjau/budget' || route.path === '/pratinjau/target'"
    >
      <h1>
        {{ route.path === "/pratinjau/budget" ? "Budget & Target" : "Target Tabungan" }}
      </h1>
      <p>Atur budget bulanan dan capai rencana keuangan keluarga.</p>
    </div>
    <div
      class="header-greeting"
      v-else-if="route.path === '/pratinjau/anggota' || route.path === '/pratinjau/pengaturan'"
    >
      <h1>
        {{
          route.path === "/pratinjau/anggota"
            ? "Anggota & Pengaturan Keluarga"
            : "Pengaturan Keluarga"
        }}
      </h1>
      <p>Kelola anggota, peran, dan informasi keluarga Anda.</p>
    </div>
    <div
      class="header-greeting"
      v-else-if="route.path === '/pratinjau/dompet' || route.path === '/pratinjau/analisis'"
    >
      <h1>
        {{ route.path === "/pratinjau/dompet" ? "Dompet Keluarga" : "Analisis Keuangan" }}
      </h1>
      <p>
        {{
          route.path === "/pratinjau/dompet"
            ? "Kelola saldo dan pantau pergerakan dana keluarga."
            : "Lihat arus kas dan pahami pengeluaran keluarga."
        }}
      </p>
    </div>
    <div
      class="header-search"
      aria-label="Pencarian tersedia pada tahap berikutnya"
    >
      <Search :size="17" /><span>Pencarian · segera hadir</span>
    </div>
    <button
      class="icon-button"
      disabled
      aria-label="Notifikasi belum tersedia"
      title="Notifikasi belum tersedia"
    >
      <Bell :size="20" />
    </button>
    <div class="account">
      <div class="avatar" aria-hidden="true">KP</div>
      <span>{{ household.name }}</span>
    </div>
  </header>
</template>
