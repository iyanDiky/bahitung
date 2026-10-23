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
      email = `settings-${stamp}@example.test`,
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

    await page.goto(base + "/pengaturan");
    await button("Simpan pengaturan").waitFor();
    await page
      .getByLabel("Nama keluarga", { exact: true })
      .fill("Keluarga Baru");
    await page
      .getByLabel("Zona waktu", { exact: true })
      .selectOption("Asia/Jakarta");
    await button("Simpan pengaturan").click();
    await page
      .getByText("Pengaturan keluarga berhasil disimpan.", { exact: true })
      .waitFor();
    await page.reload();
    await button("Simpan pengaturan").waitFor();
    assert.equal(
      await page.getByLabel("Nama keluarga", { exact: true }).inputValue(),
      "Keluarga Baru",
    );
    assert.equal(
      await page.getByLabel("Zona waktu", { exact: true }).inputValue(),
      "Asia/Jakarta",
    );
    await page.screenshot({
      path: "/tmp/bahitung-settings-desktop.png",
      fullPage: true,
    });
    await page.setViewportSize({ width: 360, height: 800 });
    await page.screenshot({
      path: "/tmp/bahitung-settings-mobile.png",
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
      await p.goto(base + "/pengaturan");
      await p
        .getByText("Hanya Owner yang dapat mengubah pengaturan keluarga.", {
          exact: true,
        })
        .waitFor();
      assert(await p.getByLabel("Nama keluarga", { exact: true }).isDisabled());
      assert.equal(
        await p
          .getByRole("button", { name: "Transfer kepemilikan", exact: true })
          .count(),
        0,
      );
      await ctx.close();
    }
    await page.route("**/api/v1/households/" + household, (route) =>
      route.abort(),
    );
    await button("Muat ulang").click();
    await page.getByRole("alert").waitFor();
    await page.unroute("**/api/v1/households/" + household);
    await button("Muat ulang").click();
    await button("Simpan pengaturan").waitFor();
    await button("Transfer kepemilikan").click();
    assert(await button("Konfirmasi transfer").isDisabled());
    await page
      .getByLabel("Owner baru")
      .selectOption({ label: "Uji member · member" });
    await page
      .getByLabel("Alasan transfer")
      .fill("Membagi pengelolaan keluarga");
    await page
      .getByLabel("Saya memahami peran saya berubah menjadi Admin.")
      .check();
    await button("Konfirmasi transfer").click();
    await page.getByRole("dialog").waitFor({ state: "hidden" });
    await page
      .getByText("Hanya Owner yang dapat mengubah pengaturan keluarga.", {
        exact: true,
      })
      .waitFor();
    assert.equal(await button("Simpan pengaturan").count(), 0);
    await page.reload();
    await page
      .getByText("Hanya Owner yang dapat mengubah pengaturan keluarga.", {
        exact: true,
      })
      .waitFor();
    const ctx = await browser.newContext(),
      p = await ctx.newPage();
    await p.goto(base + "/login");
    await p
      .getByLabel("Email", { exact: true })
      .fill(`member-${stamp}@example.test`);
    await p.getByLabel("Kata sandi", { exact: true }).fill(password);
    await p.getByRole("button", { name: "Masuk", exact: true }).click();
    await p.waitForURL("**/dashboard");
    await p.goto(base + "/pengaturan");
    await p
      .getByRole("button", { name: "Simpan pengaturan", exact: true })
      .waitFor();
    assert(await p.getByLabel("Nama keluarga", { exact: true }).isEnabled());
    await ctx.close();
    await page.goto(base + "/keluarga");
    await page.getByLabel("Nama keluarga").fill("Keluarga Kedua");
    await button("Buat keluarga").click();
    await page
      .getByText("Keluarga berhasil dibuat.", { exact: true })
      .waitFor();
    await page.getByRole("link", { name: "Buka Dashboard" }).click();
    await page.waitForURL("**/dashboard");
    await page
      .getByLabel("Keluarga aktif")
      .selectOption({ label: "Keluarga Kedua" });
    await page.goto(base + "/pengaturan");
    await button("Simpan pengaturan").waitFor();
    assert.equal(
      await page.getByLabel("Nama keluarga", { exact: true }).inputValue(),
      "Keluarga Kedua",
    );
    assert(await button("Transfer kepemilikan").isDisabled());
    await page.getByLabel("Keluarga aktif").selectOption(household);
    await page
      .getByText("Hanya Owner yang dapat mengubah pengaturan keluarga.", {
        exact: true,
      })
      .waitFor();
    assert.equal(
      await page.getByLabel("Nama keluarga", { exact: true }).inputValue(),
      "Keluarga Baru",
    );
    assert.deepEqual(errors, []);
    console.log(
      "Settings browser passed: persistence, timezone, role restrictions, transfer confirmation, new/old Owner permissions, family isolation, empty candidates, recovery, responsive layout.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
