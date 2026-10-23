<script setup lang="ts">
import { ref } from "vue";
import AppDialog from "./AppDialog.vue";
import {
  updateMember,
  isOwner,
  type HouseholdMember,
  type Role,
} from "../data/householdMock";
const props = defineProps<{ member: HouseholdMember }>();
const emit = defineEmits<{ close: []; saved: [] }>();
const role = ref<Role>(props.member.role);
const active = ref(props.member.active);
const error = ref("");
function save() {
  try {
    updateMember(props.member.id, role.value, active.value);
    emit("saved");
  } catch (cause) {
    error.value = (cause as Error).message;
  }
}
</script>
<template>
  <AppDialog
    title="Kelola Anggota"
    :description="member.name"
    @close="emit('close')"
    ><form @submit.prevent="save">
      <label class="form-field"
        >Peran Anggota<select v-model="role" aria-label="Peran Anggota">
          <option v-if="isOwner" value="admin">Admin</option>
          <option value="member">Member</option>
          <option value="viewer">Viewer</option>
        </select></label
      ><label class="member-active-toggle"
        ><input v-model="active" type="checkbox" /> Akses anggota aktif</label
      >
      <p class="transfer-info">
        Anggota nonaktif tidak dapat mengakses keluarga. Riwayat transaksi yang
        pernah dibuat tetap tersimpan.
      </p>
      <p v-if="error" class="form-error" role="alert">{{ error }}</p>
      <div class="dialog-actions">
        <button type="button" class="secondary-button" @click="emit('close')">
          Batal</button
        ><button class="primary-button">Simpan Perubahan</button>
      </div>
    </form></AppDialog
  >
</template>
