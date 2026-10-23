<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';
import { api, errorMessage } from '../services/api';
const auth = useAuth(); const router = useRouter();
const loading = ref(true); const busy = ref(false); const error = ref(''); const message = ref(''); const familyName = ref(''); const inviteEmail = ref(''); const inviteRole = ref('member');
interface Member { id:number; role:string; status:string; user:{id:number;name:string} }
interface Invitation {id:number; invited_email:string; target_role:string; status:string; expires_at:string}
const members = ref<Member[]>([]); const invitations = ref<Invitation[]>([]);
const current = computed(() => auth.memberships.find(m => m.household_id === auth.selected));
const manages = computed(() => ['owner','admin'].includes(current.value?.role || ''));
let generation = 0;
async function loadFamily() {
  const version = ++generation; const id = auth.selected; members.value = []; invitations.value = []; error.value = '';
  if (!id) return;
  loading.value = true;
  try {
    async function all<T>(path:string): Promise<T[]> {
      let page = 1; const rows: T[] = [];
      while(true) { const r = await api<{data:T[];meta:{last_page:number}}>(path+'?per_page=100&page='+page); rows.push(...r.data); if (page >= r.meta.last_page) return rows; page++; }
    }
    const rows = await all<Member>('/api/v1/households/'+id+'/members');
    const invites = manages.value ? await all<Invitation>('/api/v1/households/'+id+'/invitations') : [];
    if (version === generation) { members.value = rows; invitations.value = invites; }
  } catch(e) { if (version === generation) error.value = errorMessage(e); }
  finally { if(version === generation) loading.value = false; }
}
async function initialize() { loading.value = true; error.value = ''; try { await auth.refresh(); if(!auth.user) { await router.replace('/login'); return; } await loadFamily(); } catch(e) { error.value = errorMessage(e); } finally { loading.value = false; } }
onMounted(initialize);
watch(() => auth.selected, () => { if(auth.user) void loadFamily(); });
watch(() => auth.user, user => { if(!user && !loading.value) void router.replace('/login'); });
async function act(action: () => Promise<void>) { if (busy.value) return; busy.value = true; error.value = ''; message.value = ''; try { await action(); } catch(e) { error.value = errorMessage(e); } finally { busy.value = false; } }
function create() { void act(async () => { const r = await api<{data:{id:number}}>('/api/v1/households','POST',{name:familyName.value}); await auth.refresh(); auth.selected = r.data.id; familyName.value = ''; await loadFamily(); message.value = 'Keluarga berhasil dibuat.'; }); }
function invite() { const id = auth.selected; void act(async () => { await api('/api/v1/households/'+id+'/invitations','POST',{email:inviteEmail.value,role:inviteRole.value}); inviteEmail.value=''; await loadFamily(); message.value='Undangan berhasil dibuat dan dikirim.'; }); }
function revoke(id:number) { const household = auth.selected; void act(async () => { await api('/api/v1/households/'+household+'/invitations/'+id+'/revoke','POST'); await loadFamily(); message.value='Undangan dicabut.'; }); }
function logout() { void act(async () => { await auth.logout(); await router.replace('/login'); }); }
</script>
<template>
  <div class="family-page">
    <header class="family-header"><RouterLink class="account-brand" to="/keluarga"><span>b</span> bahitung</RouterLink><div class="family-user"><span>{{ auth.user?.name }}</span><button class="account-secondary" :disabled="busy" @click="logout">Keluar</button></div></header>
    <main class="family-main">
      <div class="family-title"><div><p class="account-eyebrow">BERSAMA, LEBIH TERATUR</p><h1>Ruang keluarga Anda</h1><p class="account-muted">Kelola keluarga dan ajak orang terdekat untuk bergabung.</p></div><label v-if="auth.memberships.length">Keluarga aktif<select v-model="auth.selected" :disabled="busy"><option v-for="m in auth.memberships" :key="m.id" :value="m.household_id">{{ m.household.name }}</option></select></label></div>
      <p v-if="error" class="account-error" role="alert">{{ error }} <button @click="initialize">Coba lagi</button></p><p v-if="message" class="account-success" role="status">{{ message }}</p>
      <div v-if="auth.user && !auth.user.email_verified_at" class="verify-banner"><div><strong>Verifikasi email Anda</strong><p>Konfirmasikan {{ auth.user.email }} untuk menerima undangan keluarga.</p></div><RouterLink class="account-secondary" to="/verifikasi-email">Verifikasi email</RouterLink></div>
      <p v-if="loading" role="status">Memuat keluarga…</p>
      <div v-else class="family-grid">
        <section class="family-panel"><template v-if="current"><p class="account-eyebrow">{{ current.role }}</p><h2>{{ current.household.name }}</h2><p class="account-muted">{{ members.length }} anggota · {{ current.household.timezone }}</p><h3>Anggota keluarga</h3><ul class="family-member-list"><li v-for="member in members" :key="member.id"><span class="family-avatar">{{ member.user.name.charAt(0) }}</span><div><strong>{{ member.user.name }}</strong><small>{{ member.status === 'active' ? 'Aktif' : member.status === 'removed' ? 'Keluar' : 'Dinonaktifkan' }}</small></div><span class="family-role">{{ member.role }}</span></li></ul><RouterLink class="account-primary" to="/dashboard">Buka Dashboard</RouterLink><RouterLink class="account-secondary" to="/anggota">Kelola anggota</RouterLink></template><template v-else><div class="family-empty">⌂</div><h2>Mulai dari satu keluarga</h2><p class="account-muted">Buat keluarga baru, atau buka tautan undangan yang Anda terima lewat email.</p></template></section>
        <div class="family-stack"><section class="family-panel"><h2>{{ current ? 'Buat keluarga lainnya' : 'Buat keluarga pertama' }}</h2><form class="account-form" @submit.prevent="create"><label>Nama keluarga<input v-model="familyName" placeholder="Contoh: Keluarga Budi" required maxlength="150" /></label><button class="account-primary" :disabled="busy">{{ busy ? 'Memproses…' : 'Buat keluarga' }}</button></form></section>
        <section v-if="current && manages" class="family-panel"><h2>Undang anggota</h2><p class="account-muted">Kirim undangan melalui email. Berlaku selama 7 hari.</p><form class="account-form" @submit.prevent="invite"><label>Email penerima<input v-model="inviteEmail" type="email" required autocomplete="email" /></label><label>Peran<select v-model="inviteRole"><option value="member">Member — ikut mencatat keuangan</option><option value="viewer">Viewer — hanya melihat</option></select></label><button class="account-primary" :disabled="busy">Kirim undangan</button></form></section></div>
        <section v-if="current && manages" class="family-panel family-wide"><h2>Undangan keluarga</h2><p v-if="!invitations.length" class="account-muted">Belum ada undangan. Mulai ajak anggota keluarga melalui email.</p><ul class="family-invite-list"><li v-for="item in invitations" :key="item.id"><div><strong>{{ item.invited_email }}</strong><small>{{ item.target_role }} · {{ item.status === 'accepted' ? 'Diterima' : item.status === 'revoked' ? 'Dicabut' : new Date(item.expires_at) < new Date() ? 'Kedaluwarsa' : 'Menunggu' }}</small></div><button v-if="item.status === 'pending'" class="account-secondary" :disabled="busy" @click="revoke(item.id)">Cabut undangan</button></li></ul></section>
      </div>
    </main>
  </div>
</template>
