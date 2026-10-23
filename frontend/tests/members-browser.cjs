// Run against isolated servers 8001/5174 and bahitung_browser_testing.
const { chromium } = require(
  process.env.BAHITUNG_PLAYWRIGHT ||
    "/tmp/bahitung-ui-tools/node_modules/playwright",
);
const assert = require("node:assert/strict");
const { spawnSync } = require("node:child_process");
const path = require("node:path");
(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({
        viewport: { width: 1440, height: 1000 },
      }),
      page = await context.newPage();
    page.setDefaultTimeout(12000);
    const errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    const base = "http://127.0.0.1:5174",
      stamp = Date.now(),
      email = `members-${stamp}@example.test`,
      password = "Finance-browser-123";
    const button = (name) => page.getByRole("button", { name, exact: true });
    await page.goto(base + "/anggota");
    await page.waitForURL("**/login?next=**");
    await page.goto(base + "/daftar");
    await page.getByLabel("Nama lengkap").fill("Pemilik Anggota");
    await page.getByLabel("Email", { exact: true }).fill(email);
    await page.getByLabel("Kata sandi", { exact: true }).fill(password);
    await page.getByLabel("Konfirmasi kata sandi").fill(password);
    await button("Buat akun").click();
    await page.waitForURL("**/keluarga");
    await page.getByLabel("Nama keluarga").fill("Keluarga Anggota");
    await button("Buat keluarga").click();
    await page.getByRole("link", { name: "Buka Dashboard" }).click();
    await page.waitForURL("**/dashboard");
    const household = await page.getByLabel("Keluarga aktif").inputValue();
    const php = String.raw`require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); config(['database.connections.pgsql.database'=>'bahitung_browser_testing']); Illuminate\Support\Facades\DB::purge('pgsql'); $h=App\Models\Household::findOrFail((int)getenv('TEST_HOUSEHOLD')); if(App\Models\User::findOrFail($h->created_by_user_id)->email!==getenv('TEST_OWNER'))exit(2); foreach(['admin','member','viewer'] as $role){$u=App\Models\User::factory()->create(['name'=>'Uji '.$role,'email'=>$role.'-'.getenv('TEST_STAMP').'@example.test','password'=>Illuminate\Support\Facades\Hash::make('Finance-browser-123')]);App\Models\HouseholdMember::factory()->create(['user_id'=>$u->id,'household_id'=>$h->id,'role'=>$role]);}`;
    const fixture = spawnSync("./bin/php", ["-r", php], {
      cwd: path.resolve(__dirname, "../../backend"),
      env: {
        ...process.env,
        TEST_HOUSEHOLD: household,
        TEST_OWNER: email,
        TEST_STAMP: String(stamp),
      },
      encoding: "utf8",
    });
    assert.equal(fixture.status, 0, fixture.stderr);
    await page.goto(base + "/anggota");
    await button("Ubah Uji member").waitFor();
    assert.equal(await button("Ubah Pemilik Anggota").count(), 0);
    await button("Undang anggota").click();
    await page
      .getByLabel("Email penerima")
      .fill(`invite-${stamp}@example.test`);
    await page.getByLabel("Peran undangan").selectOption("viewer");
    await button("Kirim undangan").click();
    await page.getByRole("dialog").waitFor({ state: "hidden" });
    await page
      .getByRole("button", {
        name: `Cabut undangan invite-${stamp}@example.test`,
      })
      .click();
    await button("Ya, cabut undangan").click();
    await page.getByRole("dialog").waitFor({ state: "hidden" });
    await page.getByText("Viewer · Dicabut", { exact: true }).waitFor();
    async function edit(role, status) {
      await button("Ubah Uji member").click();
      await page.getByLabel("Peran anggota").selectOption(role);
      await page.getByLabel("Status akses").selectOption(status);
      await page
        .getByLabel("Alasan perubahan")
        .fill("Pengujian hak akses keluarga");
      await button("Simpan perubahan").click();
      await page.getByRole("dialog").waitFor({ state: "hidden" });
      await button("Ubah Uji member").waitFor();
    }
    await edit("admin", "suspended");
    await page.reload();
    await page
      .getByRole("row")
      .filter({ hasText: "Uji member" })
      .getByText("Dinonaktifkan", { exact: true })
      .waitFor();
    await edit("member", "removed");
    await edit("member", "active");
    await page.getByLabel("Cari anggota").fill("tidak ditemukan");
    await page
      .getByText("Tidak ada anggota yang cocok dengan pencarian.")
      .waitFor();
    await page.getByLabel("Cari anggota").fill("");
    await page.screenshot({
      path: "/tmp/bahitung-members-desktop.png",
      fullPage: true,
    });
    await page.setViewportSize({ width: 360, height: 800 });
    await page.screenshot({
      path: "/tmp/bahitung-members-mobile.png",
      fullPage: true,
    });
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    );
    for (const role of ["admin", "member", "viewer"]) {
      const ctx = await browser.newContext(),
        p = await ctx.newPage();
      await p.goto(base + "/login");
      await p
        .getByLabel("Email", { exact: true })
        .fill(`${role}-${stamp}@example.test`);
      await p.getByLabel("Kata sandi", { exact: true }).fill(password);
      await p.getByRole("button", { name: "Masuk", exact: true }).click();
      await p.waitForURL("**/dashboard");
      await p.goto(base + "/anggota");
      await p
        .getByRole("heading", { name: "Daftar anggota keluarga" })
        .waitFor();
      assert.equal(
        await p
          .getByRole("button", { name: "Undang anggota", exact: true })
          .count(),
        role === "admin" ? 1 : 0,
      );
      assert.equal(
        await p
          .getByRole("button", { name: "Ubah Uji admin", exact: true })
          .count(),
        0,
      );
      if (role === "admin") {
        await p
          .getByRole("button", { name: "Ubah Uji member", exact: true })
          .click();
        assert.equal(
          await p
            .getByLabel("Peran anggota")
            .locator('option[value="admin"]')
            .count(),
          0,
        );
      } else {
        assert.equal(
          await p.getByRole("button", { name: /^Ubah / }).count(),
          0,
        );
        assert.equal(
          await p.getByRole("heading", { name: "Undangan keluarga" }).count(),
          0,
        );
      }
      await ctx.close();
    }
    await page.goto(base + "/keluarga");
    await page.getByLabel("Nama keluarga").fill("Keluarga Kedua");
    await button("Buat keluarga").click();
    await page
      .getByRole("heading", { name: "Keluarga Kedua", exact: true })
      .waitFor();
    await page
      .getByText("Keluarga berhasil dibuat.", { exact: true })
      .waitFor();
    await page.getByRole("link", { name: "Buka Dashboard" }).click();
    await page.waitForURL("**/dashboard");
    await page
      .getByLabel("Keluarga aktif")
      .selectOption({ label: "Keluarga Kedua" });
    await page.goto(base + "/anggota");
    await page
      .getByRole("heading", { name: "Daftar anggota keluarga" })
      .waitFor();
    assert.equal(
      await page.getByText("Uji member", { exact: true }).count(),
      0,
    );
    assert.equal(
      await page
        .getByText(`invite-${stamp}@example.test`, { exact: true })
        .count(),
      0,
    );
    await page.getByLabel("Keluarga aktif").selectOption(household);
    await button("Ubah Uji member").waitFor();
    await page.route("**/members?*", (route) => route.abort());
    await button("Muat ulang").click();
    await page.getByRole("alert").waitFor();
    assert.equal(await button("Ubah Uji member").count(), 0);
    await page.unroute("**/members?*");
    await button("Muat ulang").click();
    await button("Ubah Uji member").waitFor();
    assert.deepEqual(errors, []);
    console.log(
      "Members browser passed: invitations/revoke, roles/status/reactivation, role permissions, search, family isolation, failure/recovery, responsive layout.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
