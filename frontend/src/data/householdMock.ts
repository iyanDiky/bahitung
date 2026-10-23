import { computed, reactive } from "vue";
export type Role = "owner" | "admin" | "member" | "viewer";
export interface HouseholdMember {
  id: string;
  householdId: string;
  name: string;
  email: string;
  role: Role;
  active: boolean;
  joined: string;
}
export interface Invitation {
  id: string;
  householdId: string;
  email: string;
  role: "member" | "viewer";
  created: string;
  expires: string;
  status: "pending" | "revoked";
}
// Single-household UI fixture, not authentication. No email or network requests.
export const household = reactive({
  id: "family-1",
  name: "Keluarga Pratama",
  description:
    "Keluarga kecil yang sedang merencanakan masa depan yang lebih baik bersama.",
  timezone: "Asia/Makassar",
  created: "2024-01-12",
});
export const actorId = "1";
export const householdMembers = reactive<HouseholdMember[]>([
  {
    id: "1",
    householdId: household.id,
    name: "Ayah Pratama",
    email: "ayah@pratama.com",
    role: "owner",
    active: true,
    joined: "2024-01-12",
  },
  {
    id: "2",
    householdId: household.id,
    name: "Ibu Pratama",
    email: "ibu@pratama.com",
    role: "admin",
    active: true,
    joined: "2024-01-12",
  },
  {
    id: "3",
    householdId: household.id,
    name: "Dito Pratama",
    email: "dito@pratama.com",
    role: "member",
    active: true,
    joined: "2024-01-15",
  },
  {
    id: "4",
    householdId: household.id,
    name: "Siska Pratama",
    email: "siska@pratama.com",
    role: "member",
    active: true,
    joined: "2024-01-15",
  },
  {
    id: "5",
    householdId: household.id,
    name: "Nenek",
    email: "nenek@pratama.com",
    role: "viewer",
    active: true,
    joined: "2024-01-20",
  },
]);
export const invitations = reactive<Invitation[]>([]);
export const currentMember = computed(() =>
  householdMembers.find(
    (m) => m.id === actorId && m.householdId === household.id && m.active,
  ),
);
export const canManageMembers = computed(() =>
  ["owner", "admin"].includes(currentMember.value?.role ?? ""),
);
export const isOwner = computed(() => currentMember.value?.role === "owner");
export const roleLabels: Record<Role, string> = {
  owner: "Owner",
  admin: "Admin",
  member: "Member",
  viewer: "Viewer",
};
export const timezones = [
  { value: "Asia/Jakarta", label: "WIB · UTC+7" },
  { value: "Asia/Makassar", label: "WITA · UTC+8" },
  { value: "Asia/Jayapura", label: "WIT · UTC+9" },
];
export function canEditMember(member: HouseholdMember) {
  return (
    member.householdId === household.id &&
    member.id !== actorId &&
    member.role !== "owner" &&
    (isOwner.value ||
      (currentMember.value?.role === "admin" &&
        ["member", "viewer"].includes(member.role)))
  );
}
export function updateMember(id: string, role: Role, active: boolean) {
  const member = householdMembers.find((item) => item.id === id);
  if (!member || !canEditMember(member))
    throw new Error("Perubahan anggota ini tidak diizinkan.");
  if (
    !["admin", "member", "viewer"].includes(role) ||
    (!isOwner.value && role === "admin")
  )
    throw new Error("Peran ini hanya dapat diatur oleh Owner.");
  member.role = role;
  member.active = active;
}
export function inviteMember(
  email: string,
  role: "member" | "viewer",
  now = new Date(),
) {
  if (!canManageMembers.value)
    throw new Error("Hanya Owner atau Admin yang dapat membuat undangan.");
  const normalized = email.trim().toLowerCase();
  if (normalized.length > 254 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalized))
    throw new Error("Masukkan alamat email yang valid.");
  if (!["member", "viewer"].includes(role))
    throw new Error("Undangan hanya tersedia untuk Member dan Viewer.");
  if (
    householdMembers.some(
      (member) =>
        member.householdId === household.id &&
        member.email.toLowerCase() === normalized,
    )
  )
    throw new Error(
      "Email ini sudah terdaftar sebagai anggota. Kelola akses melalui daftar anggota.",
    );
  if (
    invitations.some(
      (invite) =>
        invite.householdId === household.id &&
        invite.email === normalized &&
        invite.status === "pending" &&
        Date.parse(invite.expires) > now.getTime(),
    )
  )
    throw new Error("Masih ada undangan aktif untuk email ini.");
  const invitation: Invitation = {
    id: crypto.randomUUID(),
    householdId: household.id,
    email: normalized,
    role,
    created: now.toISOString(),
    expires: new Date(now.getTime() + 7 * 86400000).toISOString(),
    status: "pending",
  };
  invitations.unshift(invitation);
}
export function revokeInvitation(id: string) {
  if (!canManageMembers.value) throw new Error("Akses tidak diizinkan.");
  const invitation = invitations.find(
    (item) => item.id === id && item.householdId === household.id,
  );
  if (!invitation || invitation.status !== "pending")
    throw new Error("Undangan tidak aktif.");
  invitation.status = "revoked";
}
export function saveHousehold(input: {
  name: string;
  description: string;
  timezone: string;
}) {
  if (!isOwner.value)
    throw new Error("Hanya Owner yang dapat mengubah pengaturan keluarga.");
  const name = input.name.trim(),
    description = input.description.trim();
  if (!name || name.length > 60)
    throw new Error("Nama keluarga wajib diisi, maksimal 60 karakter.");
  if (description.length > 200)
    throw new Error("Deskripsi maksimal 200 karakter.");
  if (!timezones.some((item) => item.value === input.timezone))
    throw new Error("Pilih zona waktu yang tersedia.");
  Object.assign(household, { name, description, timezone: input.timezone });
}
export function transferOwnership(id: string, confirmation: string) {
  if (!isOwner.value)
    throw new Error("Hanya Owner yang dapat memindahkan kepemilikan.");
  const next = householdMembers.find(
    (member) =>
      member.id === id &&
      member.householdId === household.id &&
      member.active &&
      member.id !== actorId,
  );
  if (!next) throw new Error("Pilih anggota aktif lain sebagai Owner baru.");
  if (confirmation !== household.name)
    throw new Error("Nama keluarga pada konfirmasi belum cocok.");
  const previous = currentMember.value!;
  previous.role = "admin";
  next.role = "owner";
}
