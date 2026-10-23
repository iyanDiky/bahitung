<script setup lang="ts">
import { reactive, ref, watch } from "vue";
import {
  household,
  isOwner,
  saveHousehold,
  timezones,
} from "../data/householdMock";
const emit = defineEmits<{ saved: [] }>();
const form = reactive({
  name: household.name,
  description: household.description,
  timezone: household.timezone,
});
const error = ref("");
watch(
  () => [household.name, household.description, household.timezone],
  () =>
    Object.assign(form, {
      name: household.name,
      description: household.description,
      timezone: household.timezone,
    }),
);
function save() {
  error.value = "";
  try {
    saveHousehold(form);
    emit("saved");
  } catch (cause) {
    error.value = (cause as Error).message;
  }
}
</script>
<template>
  <section id="family-settings" class="surface household-settings">
    <h2>Pengaturan Keluarga</h2>
    <p class="members-subtitle">
      Sesuaikan informasi dasar keluarga dan zona waktu pencatatan.
    </p>
    <form @submit.prevent="save">
      <fieldset :disabled="!isOwner">
        <div class="form-two">
          <label class="form-field"
            >Nama Keluarga<input
              v-model="form.name"
              aria-label="Nama Keluarga"
              maxlength="60"
              required /></label
          ><label class="form-field"
            >Mata Uang<input value="Rupiah (IDR)" readonly
          /></label>
        </div>
        <label class="form-field"
          >Deskripsi Keluarga<textarea
            v-model="form.description"
            aria-label="Deskripsi Keluarga"
            rows="2"
            maxlength="200"
          ></textarea>
        </label>
        <div class="form-two">
          <label class="form-field"
            >Awal Periode Budget<input
              value="Tanggal 1 setiap bulan"
              readonly /></label
          ><label class="form-field"
            >Zona Waktu<select v-model="form.timezone" aria-label="Zona Waktu">
              <option
                v-for="zone in timezones"
                :key="zone.value"
                :value="zone.value"
              >
                {{ zone.label }}
              </option>
            </select></label
          >
        </div>
        <p v-if="error" role="alert" class="form-error">{{ error }}</p>
        <button class="primary-button">Simpan Pengaturan</button>
      </fieldset>
    </form>
    <p v-if="!isOwner" class="members-subtitle">
      Pengaturan keluarga hanya dapat diubah oleh Owner.
    </p>
    <div class="notifications-coming">
      <h3>Preferensi Notifikasi</h3>
      <p>
        Ringkasan bulanan, pembaruan budget, dan pemberitahuan anggota akan
        tersedia setelah layanan notifikasi terhubung.
      </p>
    </div>
  </section>
</template>
