import { createRouter, createWebHistory } from "vue-router";
import BudgetView from "../views/BudgetView.vue";
import FinanceView from "../views/FinanceView.vue";
import { useAuth } from "../stores/auth";
import DashboardView from "../views/DashboardView.vue";
import TransactionsView from "../views/TransactionsView.vue";
import WalletsView from "../views/WalletsView.vue";
import LiveAnalyticsView from "../views/LiveAnalyticsView.vue";
import AnalyticsView from "../views/AnalyticsView.vue";
import PlanningView from "../views/PlanningView.vue";
import LiveSettingsView from "../views/LiveSettingsView.vue";
import LiveMembersView from "../views/LiveMembersView.vue";
import MembersView from "../views/MembersView.vue";
import AccountView from "../views/AccountView.vue";
import FamilyAccountView from "../views/FamilyAccountView.vue";
const routes = [
  {
    path: "/login",
    name: "login",
    component: AccountView,
    meta: { account: true },
  },
  {
    path: "/daftar",
    name: "register",
    component: AccountView,
    meta: { account: true },
  },
  {
    path: "/lupa-password",
    name: "forgot",
    component: AccountView,
    meta: { account: true },
  },
  {
    path: "/reset-password",
    name: "reset",
    component: AccountView,
    meta: { account: true },
  },
  {
    path: "/verifikasi-email",
    name: "verify",
    component: AccountView,
    meta: { account: true },
  },
  {
    path: "/undangan",
    name: "invitation",
    component: AccountView,
    meta: { account: true },
  },
  {
    path: "/keluarga",
    name: "family-account",
    component: FamilyAccountView,
    meta: { account: true },
  },
  { path: "/", redirect: "/dashboard" },
  {
    path: "/dashboard",
    name: "finance-dashboard",
    component: FinanceView,
    meta: { account: true, finance: true },
  },
  {
    path: "/dompet",
    name: "finance-wallets",
    component: FinanceView,
    meta: { account: true, finance: true },
  },
  {
    path: "/transaksi",
    name: "finance-transactions",
    component: FinanceView,
    meta: { account: true, finance: true },
  },

  { path: "/pratinjau/dompet", name: "wallets", component: WalletsView },
  {
    path: "/analisis",
    name: "finance-analytics",
    component: LiveAnalyticsView,
    meta: { account: true, finance: true },
  },
  { path: "/pratinjau/analisis", name: "analytics", component: AnalyticsView },
  {
    path: "/anggota",
    name: "finance-members",
    component: LiveMembersView,
    meta: { account: true, finance: true },
  },
  { path: "/pratinjau/anggota", name: "members", component: MembersView },
  {
    path: "/pengaturan",
    name: "finance-settings",
    component: LiveSettingsView,
    meta: { account: true, finance: true },
  },
  { path: "/pratinjau/pengaturan", name: "settings", component: MembersView },
  {
    path: "/budget",
    name: "finance-budget",
    component: BudgetView,
    meta: { account: true, finance: true },
  },
  { path: "/pratinjau/budget", name: "budget", component: PlanningView },
  {
    path: "/target",
    name: "finance-goals",
    component: FinanceView,
    meta: { account: true, finance: true },
  },
  { path: "/pratinjau/target", name: "targets", component: PlanningView },
  {
    path: "/pratinjau/transaksi",
    name: "transactions",
    component: TransactionsView,
  },
  { path: "/pratinjau", name: "dashboard", component: DashboardView },
];
const router = createRouter({ history: createWebHistory(), routes });
router.beforeEach(async (to) => {
  if (!to.meta.finance) return;
  const auth = useAuth();
  try {
    await auth.refresh();
  } catch {
    return { path: "/login", query: { next: to.path, connection: "failed" } };
  }
  if (!auth.user) return { path: "/login", query: { next: to.path } };
  if (!auth.selected) return "/keluarga";
});
export default router;
