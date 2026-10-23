<script setup lang="ts">
import { ref, computed } from "vue";
import {
  Plus,
  ArrowLeftRight,
  Wallet,
  ChartPie,
  Target,
  Users,
} from "lucide-vue-next";
import {
  wallets,
  budgets,
  goals,
  transactions,
  summaries,
  formatIDR,
  goalTotal,
} from "../data/mock";
import SummaryCard from "../components/SummaryCard.vue";
import DashboardState from "../components/DashboardState.vue";
import WalletList from "../components/WalletList.vue";
import BudgetProgressList from "../components/BudgetProgressList.vue";
import GoalsList from "../components/GoalsList.vue";
import RecentTransactions from "../components/RecentTransactions.vue";
import ExpenseChart from "../components/ExpenseChart.vue";
import { household, householdMembers, roleLabels } from "../data/householdMock";
const members = computed(() =>
  householdMembers.filter(
    (member) => member.active && member.householdId === household.id,
  ),
);
const state = ref<"ready" | "loading" | "empty" | "error">("ready");
const actions = [
  { label: "Tambah Transaksi", icon: Plus, to: "/pratinjau/transaksi" },
  { label: "Transfer", icon: ArrowLeftRight, to: "/pratinjau/transaksi?action=transfer" },
  { label: "Kelola Dompet", icon: Wallet, to: "/pratinjau/dompet" },
  { label: "Atur Budget", icon: ChartPie, to: "/pratinjau/budget" },
  { label: "Tambah Target", icon: Target, to: "/pratinjau/target?action=create" },
  { label: "Undang Anggota", icon: Users, to: "/pratinjau/anggota?action=invite" },
];
</script>
<template>
  <div class="page">
    <DashboardState
      v-if="state !== 'ready'"
      :state="state"
      @retry="state = 'ready'"
    /><template v-else
      ><div class="summary-grid surface">
        <SummaryCard v-for="card in summaries" :key="card.name" :card="card" />
      </div>
      <p class="allocation-note">
        Dana dialokasikan ke target:
        <strong>{{ formatIDR(goalTotal) }}</strong> · Total aset = saldo dompet
        + dana target.
      </p>
      <div class="action-and-members">
        <section class="surface quick-actions">
          <RouterLink
            v-for="(action, i) in actions"
            :to="action.to"
            :key="action.label"
            class="quick-action"
            :class="{ primary: i === 0 }"
            :title="
              ['/pratinjau/transaksi', '/pratinjau/budget', '/pratinjau/target', '/pratinjau/anggota', '/pratinjau/dompet'].some(
                (path) => action.to.startsWith(path),
              )
                ? action.label
                : action.label + ' (segera hadir)'
            "
            ><component :is="action.icon" :size="23" /><span>{{
              action.label
            }}</span></RouterLink
          >
        </section>
        <section class="surface members">
          <div class="section-heading">
            <strong>Anggota Keluarga</strong
            ><RouterLink to="/pratinjau/anggota">Lihat Semua</RouterLink>
          </div>
          <div class="member-items">
            <div v-for="(person, i) in members" :key="person.id" class="member">
              <div class="member-avatar" :class="'avatar-' + i">
                {{ person.name.charAt(0) }}
              </div>
              <b>{{ person.name.split(" ")[0] }}</b
              ><small>{{ roleLabels[person.role] }}</small>
            </div>
            <RouterLink to="/pratinjau/anggota" class="member"
              ><div class="member-avatar add">+</div>
              <b>Tambah</b><small>Anggota</small></RouterLink
            >
          </div>
        </section>
      </div>
      <div class="three-col">
        <WalletList :wallets="wallets" /><BudgetProgressList
          :budgets="budgets"
        /><GoalsList :goals="goals" />
      </div>
      <div class="bottom-cols">
        <RecentTransactions :transactions="transactions" /><ExpenseChart /></div
    ></template>
  </div>
</template>
