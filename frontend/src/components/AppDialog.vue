<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, useId } from "vue";
import { X } from "lucide-vue-next";
defineProps<{ title: string; description?: string }>();
const emit = defineEmits<{ close: [] }>();
const dialog = ref<HTMLDialogElement>();
const titleId = useId();
let previousFocus: HTMLElement | null = null;
let overflow = "";
onMounted(() => {
  previousFocus = document.activeElement as HTMLElement;
  overflow = document.body.style.overflow;
  document.body.style.overflow = "hidden";
  dialog.value?.showModal();
});
onBeforeUnmount(() => {
  document.body.style.overflow = overflow;
  previousFocus?.focus();
});
</script>
<template>
  <dialog
    ref="dialog"
    class="transfer-dialog planning-dialog"
    :aria-labelledby="titleId"
    @cancel.prevent="emit('close')"
    @click="$event.target === dialog && emit('close')"
  >
    <div class="planning-dialog-content">
      <div class="dialog-heading">
        <div>
          <h2 :id="titleId">{{ title }}</h2>
          <p v-if="description">{{ description }}</p>
        </div>
        <button
          class="dialog-close"
          aria-label="Tutup dialog"
          type="button"
          @click="emit('close')"
        >
          <X :size="20" />
        </button>
      </div>
      <slot />
    </div>
  </dialog>
</template>
