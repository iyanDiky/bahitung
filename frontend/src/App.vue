<script setup lang="ts">
import { ref, watch, nextTick, onBeforeUnmount } from "vue";
import AppSidebar from "./components/AppSidebar.vue";
import AppHeader from "./components/AppHeader.vue";
import { useRoute } from 'vue-router';
import { useAuth } from './stores/auth';
const route = useRoute();
const auth = useAuth();
const clearSession = () => auth.clear();
window.addEventListener('bahitung:unauthenticated', clearSession);
onBeforeUnmount(() => window.removeEventListener('bahitung:unauthenticated', clearSession));
const open = ref(false);
let previousOverflow = "";
watch(open, async (value) => {
  if (value) {
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    await nextTick();
    document.querySelector<HTMLButtonElement>(".side-close")?.focus();
  } else {
    document.body.style.overflow = previousOverflow;
    document.querySelector<HTMLButtonElement>("#menu-toggle")?.focus();
  }
});
function onKeydown(event: KeyboardEvent) {
  if (!open.value) return;
  if (event.key === "Escape") open.value = false;
  if (event.key !== "Tab") return;
  const items = document.querySelectorAll<HTMLElement>(
    "#app-sidebar button, #app-sidebar a",
  );
  const first = items[0];
  const last = items[items.length - 1];
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last?.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first?.focus();
  }
}
const desktop = window.matchMedia("(min-width: 951px)");
const closeOnDesktop = () => {
  if (desktop.matches) open.value = false;
};
desktop.addEventListener("change", closeOnDesktop);
document.addEventListener("keydown", onKeydown);
onBeforeUnmount(() => {
  desktop.removeEventListener("change", closeOnDesktop);
  document.removeEventListener("keydown", onKeydown);
  document.body.style.overflow = previousOverflow;
});
</script>
<template>
  <RouterView v-if="route.meta.account" :key="route.path" />
  <div v-else class="app-shell">
    <AppSidebar :open="open" @close="open = false" />
    <div
      v-if="open"
      class="scrim"
      aria-hidden="true"
      @click="open = false"
    ></div>
    <div class="main-area" :inert="open">
      <AppHeader :menu-open="open" @open-menu="open = true" />
      <main id="main-content"><RouterView /></main>
    </div>
  </div>
</template>
