import { defineStore } from "pinia";
import { ref, watch } from "vue";
import { api, ApiError } from "../services/api";
export interface User {
  id: number;
  name: string;
  email: string;
  email_verified_at: string | null;
}
export interface Membership {
  id: number;
  household_id: number;
  role: "owner" | "admin" | "member" | "viewer";
  status: string;
  household: { id: number; name: string; timezone: string };
}
export const useAuth = defineStore("auth", () => {
  const user = ref<User | null>(null);
  const memberships = ref<Membership[]>([]);
  const selected = ref<number | null>(
    Number(sessionStorage.getItem("bahitung.household")) || null,
  );
  watch(selected, (value) => {
    if (value) sessionStorage.setItem("bahitung.household", String(value));
    else sessionStorage.removeItem("bahitung.household");
  });
  let pending: Promise<void> | null = null;
  function clear() {
    user.value = null;
    memberships.value = [];
    selected.value = null;
  }
  async function refresh() {
    if (pending) return pending;
    pending = (async () => {
      try {
        const result = await api<{
          data: { user: User; memberships: Membership[] };
        }>("/api/v1/me");
        user.value = result.data.user;
        memberships.value = result.data.memberships;
        if (!memberships.value.some((m) => m.household_id === selected.value))
          selected.value = memberships.value[0]?.household_id ?? null;
      } catch (error) {
        clear();
        if (!(error instanceof ApiError && error.status === 401)) throw error;
      } finally {
        pending = null;
      }
    })();
    return pending;
  }
  async function logout() {
    await api("/api/v1/session/logout", "POST");
    clear();
  }
  return { user, memberships, selected, clear, refresh, logout };
});
