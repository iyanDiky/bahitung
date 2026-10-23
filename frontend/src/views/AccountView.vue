<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, csrf, errorMessage, safeNext } from '../services/api';
import { useAuth } from '../stores/auth';
const route = useRoute(); const router = useRouter(); const auth = useAuth();
const busy = ref(false); const error = ref(''); const success = ref('');
const name = ref(''); const email = ref(typeof route.query.email === 'string' ? route.query.email : ''); const password = ref(''); const confirmation = ref('');
const mode = computed(() => String(route.name));
const titles: Record<string,string> = { login: 'Selamat datang kembali', register: 'Mulai bersama keluarga', forgot: 'Lupa kata sandi?', reset: 'Buat kata sandi baru', verify: 'Verifikasi email Anda', invitation: 'Undangan keluarga' };
const heading = computed(() => titles[mode.value] || 'Akun bahitung');
const action = computed(() => ({login:'Masuk',register:'Buat akun',forgot:'Kirim tautan reset',reset:'Simpan kata sandi',verify:'Verifikasi email',invitation:'Terima undangan'}[mode.value] || 'Lanjutkan'));
const authenticatedAction = computed(() => mode.value === 'verify' || mode.value === 'invitation');
const next = computed(() => safeNext(route.query.next));
const hasVerificationLink = computed(() => typeof route.query.url === 'string');
watch(() => route.fullPath, () => { error.value = ''; success.value = ''; password.value = ''; confirmation.value = ''; });
onMounted(async () => {
  if (authenticatedAction.value) { try { await auth.refresh(); } catch (e) { error.value = errorMessage(e); } }
});
async function submit() {
  if (busy.value) return; busy.value = true; error.value = ''; success.value = '';
  try {
    if (mode.value === 'login' || mode.value === 'register') {
      await csrf();
      await api('/api/v1/session/'+mode.value, 'POST', { name:name.value, email:email.value, password:password.value, password_confirmation:confirmation.value });
      password.value = ''; confirmation.value = ''; await auth.refresh(); await router.replace(next.value);
    } else if (mode.value === 'forgot') {
      await api('/api/v1/auth/forgot-password', 'POST', { email:email.value }); success.value = 'Jika email terdaftar, tautan reset akan dikirim. Periksa kotak masuk Anda.';
    } else if (mode.value === 'reset') {
      if (typeof route.query.token !== 'string') throw new Error('Tautan tidak lengkap');
      await api('/api/v1/auth/reset-password', 'POST', { email:email.value, token:route.query.token, password:password.value, password_confirmation:confirmation.value });
      auth.clear(); password.value = ''; confirmation.value = ''; await router.replace('/login?reset=berhasil');
    } else if (mode.value === 'verify') {
      const path = route.query.url;
      if (typeof path !== 'string' || !/^\/api\/v1\/auth\/email\/verify\/\d+\/[a-f0-9]{40}\?/.test(path)) { error.value = 'Buka tautan verifikasi dari email Anda, atau kirim ulang tautan.'; return; }
      await api(path); await auth.refresh(); success.value = 'Email berhasil diverifikasi. Anda sudah bisa menerima undangan.';
    } else {
      if (typeof route.query.token !== 'string' || route.query.token.length !== 64) { error.value = 'Tautan undangan tidak lengkap. Minta pengelola mengirim undangan baru.'; return; }
      await api('/api/v1/invitations/accept', 'POST', { token: route.query.token }); await auth.refresh(); await router.replace('/keluarga');
    }
  } catch (e) { error.value = errorMessage(e); }
  finally { busy.value = false; }
}
async function resend() {
  busy.value = true; error.value = '';
  try { await api('/api/v1/auth/email/verification-notification', 'POST'); success.value = 'Tautan verifikasi dikirim. Periksa kotak masuk Anda.'; }
  catch(e) { error.value = errorMessage(e); } finally { busy.value = false; }
}
async function switchAccount() { busy.value = true; try { await auth.logout(); } catch(e) { error.value = errorMessage(e); } finally { busy.value = false; } }
</script>
<template>
  <div class="account-page">
    <aside class="account-story"><RouterLink class="account-brand" to="/keluarga"><span>b</span> bahitung</RouterLink><div><p class="account-eyebrow">RUANG UNTUK KELUARGA</p><h1>Lebih tertata.<br>Lebih tenang.<br>Bersama.</h1><p>Satu ruang untuk merencanakan hari ini<br>dan menjaga impian keluarga.</p></div><small>Langkah kecil hari ini, rencana besar bersama.</small></aside>
    <main class="account-main"><section class="account-card">
      <RouterLink class="account-brand compact-brand" to="/keluarga"><span>b</span> bahitung</RouterLink>
      <p class="account-eyebrow">AKUN BAHITUNG</p><h2>{{ heading }}</h2>
      <p class="account-muted">{{ authenticatedAction ? 'Gunakan akun dengan email penerima tautan.' : 'Tempat untuk mulai menata keuangan bersama.' }}</p>
      <p v-if="route.query.reset === 'berhasil'" class="account-success" role="status">Kata sandi berhasil diubah. Silakan masuk kembali.</p>
      <p v-if="route.query.connection === 'failed'" class="account-error" role="alert">Koneksi ke server belum tersedia. Pastikan backend berjalan, lalu coba masuk lagi.</p>
      <p v-if="error" class="account-error" role="alert">{{ error }}</p><p v-if="success" class="account-success" role="status">{{ success }}</p>
      <template v-if="authenticatedAction && !auth.user">
        <p>Masuk terlebih dahulu untuk melanjutkan tautan ini.</p>
        <RouterLink class="account-primary" :to="{path:'/login',query:{next:route.fullPath}}">Masuk untuk melanjutkan</RouterLink>
        <p>Belum punya akun? <RouterLink :to="{path:'/daftar',query:{next:route.fullPath}}">Daftar sekarang</RouterLink></p>
      </template>
      <form v-else @submit.prevent="submit" class="account-form">
        <p v-if="authenticatedAction">Masuk sebagai <strong>{{ auth.user?.email }}</strong></p>
        <label v-if="mode === 'register'">Nama lengkap<input v-model="name" autocomplete="name" required maxlength="150" /></label>
        <label v-if="['login','register','forgot','reset'].includes(mode)">Email<input v-model="email" type="email" autocomplete="email" required maxlength="255" /></label>
        <label v-if="['login','register','reset'].includes(mode)">Kata sandi<input v-model="password" aria-label="Kata sandi" type="password" :autocomplete="mode === 'login' ? 'current-password' : 'new-password'" required :minlength="mode === 'login' ? 1 : 12" maxlength="72" /><small v-if="mode !== 'login'">Minimal 12 karakter.</small></label>
        <label v-if="['register','reset'].includes(mode)">Konfirmasi kata sandi<input v-model="confirmation" type="password" autocomplete="new-password" required minlength="12" maxlength="72" /></label>
        <RouterLink v-if="mode === 'login'" to="/lupa-password">Lupa kata sandi?</RouterLink>
        <p v-if="mode === 'invitation' && !auth.user?.email_verified_at" class="account-muted">Verifikasi email terlebih dahulu melalui tautan yang dikirim ke email Anda. Setelah itu kembali ke undangan ini.</p>
        <button v-if="mode !== 'verify' || hasVerificationLink" class="account-primary" :disabled="busy || (mode === 'invitation' && !auth.user?.email_verified_at)">{{ busy ? 'Memproses…' : action }}</button>
        <button v-if="authenticatedAction && !auth.user?.email_verified_at" type="button" class="account-secondary" :disabled="busy" @click="resend">Kirim ulang verifikasi</button>
        <RouterLink v-if="authenticatedAction" to="/keluarga">Kembali ke keluarga</RouterLink>
        <button v-if="authenticatedAction" type="button" class="account-text-button" :disabled="busy" @click="switchAccount">Gunakan akun lain</button>
      </form>
      <p v-if="mode === 'login'" class="account-bottom">Belum punya akun? <RouterLink :to="{path:'/daftar',query:{next}}">Daftar sekarang</RouterLink></p>
      <p v-if="mode === 'register'" class="account-bottom">Sudah punya akun? <RouterLink :to="{path:'/login',query:{next}}">Masuk</RouterLink></p>
      <RouterLink v-if="['forgot','reset'].includes(mode)" to="/login">Kembali ke halaman masuk</RouterLink>
    </section></main>
  </div>
</template>
