<script setup lang="ts">
import { AlertCircle, Inbox } from "lucide-vue-next";
defineProps<{ state: "loading" | "empty" | "error" }>();
defineEmits<{ retry: [] }>();
</script>
<template>
  <section
    v-if="state === 'loading'"
    class="surface state-panel"
    role="status"
    aria-busy="true"
    aria-label="Memuat dashboard"
  >
    <p>Memuat ringkasan keuangan…</p>
    <div class="skeleton-grid">
      <div v-for="n in 6" :key="n" class="skeleton" />
    </div>
  </section>
  <section
    v-else
    class="surface state-panel"
    :role="state === 'error' ? 'alert' : 'status'"
  >
    <component :is="state === 'error' ? AlertCircle : Inbox" :size="36" />
    <h2>
      {{
        state === "error"
          ? "Dashboard belum dapat dimuat"
          : "Belum ada catatan keuangan"
      }}
    </h2>
    <p>
      {{
        state === "error"
          ? "Ini simulasi kegagalan. Coba lagi untuk menampilkan data demo."
          : "Dompet, transaksi, budget, dan target keluarga akan muncul di sini setelah ditambahkan."
      }}
    </p>
    <button class="retry-button" @click="$emit('retry')">
      {{ state === "error" ? "Coba lagi" : "Lihat contoh data" }}
    </button>
  </section>
</template>
