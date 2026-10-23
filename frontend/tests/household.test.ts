import assert from "node:assert/strict";
import {
  household,
  householdMembers,
  invitations,
  inviteMember,
  revokeInvitation,
  updateMember,
  canEditMember,
  transferOwnership,
  saveHousehold,
  isOwner,
} from "../src/data/householdMock";
const now = new Date("2026-10-15T08:00:00Z");
assert.throws(() => inviteMember("bad-email", "member", now));
assert.throws(() => inviteMember("AYAH@PRATAMA.COM", "member", now));
inviteMember("  new@example.com  ", "viewer", now);
assert.equal(invitations[0].email, "new@example.com");
assert.equal(invitations[0].expires, "2026-10-22T08:00:00.000Z");
assert.throws(() => inviteMember("new@example.com", "member", now));
assert.throws(() => inviteMember("admin@example.com", "admin" as never, now));
revokeInvitation(invitations[0].id);
assert.equal(invitations[0].status, "revoked");
inviteMember("new@example.com", "member", now);
inviteMember("new@example.com", "member", new Date("2026-10-23T08:00:00Z"));
assert.equal(invitations.length, 3);
assert.throws(() => updateMember("1", "member", false));
updateMember("3", "viewer", false);
assert.equal(householdMembers[2].active, false);
assert.equal(householdMembers.length, 5);
assert.throws(() => transferOwnership("3", household.name));
assert.throws(() => transferOwnership("2", "wrong"));
assert.equal(
  householdMembers.filter((m) => m.role === "owner" && m.active).length,
  1,
);
const oldName = household.name;
assert.throws(() =>
  saveHousehold({ name: " ", description: "", timezone: "Asia/Jakarta" }),
);
assert.equal(household.name, oldName);
saveHousehold({
  name: "Keluarga Bahagia",
  description: "Bersama",
  timezone: "Asia/Jakarta",
});
assert.equal(household.name, "Keluarga Bahagia");
updateMember("4", "admin", true);
transferOwnership("2", household.name);
assert.equal(isOwner.value, false);
assert.equal(householdMembers[0].role, "admin");
assert.equal(householdMembers[1].role, "owner");
assert.equal(
  householdMembers.filter((m) => m.role === "owner" && m.active).length,
  1,
);
assert.throws(() => updateMember("4", "member", true));
assert.throws(() => updateMember("3", "admin", true));
assert.throws(() => updateMember("2", "viewer", false));
assert.throws(() =>
  saveHousehold({
    name: "Forbidden",
    description: "",
    timezone: "Asia/Makassar",
  }),
);
assert.throws(() => transferOwnership("1", household.name));
updateMember("3", "member", true);
assert.equal(householdMembers[2].role, "member");
assert.equal(householdMembers[2].active, true);
assert.equal(
  canEditMember({ ...householdMembers[2], householdId: "other-household" }),
  false,
);
householdMembers[0].role = "viewer";
assert.throws(() => inviteMember("viewer@example.com", "member", now));
assert.throws(() => revokeInvitation(invitations[0].id));
assert.throws(() => updateMember("3", "viewer", true));
console.log(
  "PASS: invitation validation/duplicates/expiry/revoke, one active owner, owner transfer, Admin boundaries, Viewer restrictions, household scope, retained inactive membership.",
);
