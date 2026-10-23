// Isolated servers: backend 8001 / frontend 5174, bahitung_browser_testing.
const { chromium } = require(
  process.env.BAHITUNG_PLAYWRIGHT ||
    "/tmp/bahitung-ui-tools/node_modules/playwright",
);
const assert = require("node:assert/strict");
const base = "http://127.0.0.1:5174";
(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({
      viewport: { width: 1440, height: 1000 },
    });
    const page = await context.newPage();
    page.setDefaultTimeout(12000);
    const errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    const email = `goals-${Date.now()}@example.test`,
      password = "Finance-browser-123";
    const button = (name) => page.getByRole("button", { name, exact: true });
    const nav = async (name) => {
      await page
        .getByRole("navigation", { name: "Menu keuangan" })
        .getByRole("link", { name, exact: true })
        .click();
      await page
        .getByRole("heading", {
          name:
            name === "Dompet"
              ? "Dompet keluarga"
              : name === "Target"
                ? "Target tabungan"
                : name,
          exact: true,
          level: 1,
        })
        .waitFor();
      await button("Muat ulang").waitFor();
    };
    const saved = async () => {
      await page.getByRole("dialog").waitFor({ state: "hidden" });
      await page
        .getByRole("status")
        .filter({ hasText: "berhasil disimpan" })
        .waitFor();
    };
    await page.goto(base + "/dompet");
    await page.waitForURL("**/login?next=**");
    await page.goto(base + "/daftar");
    await page.getByLabel("Nama lengkap").fill("Pengguna Finansial");
    await page.getByLabel("Email", { exact: true }).fill(email);
    await page.getByLabel("Kata sandi", { exact: true }).fill(password);
    await page.getByLabel("Konfirmasi kata sandi").fill(password);
    await button("Buat akun").click();
    await page.waitForURL("**/keluarga");
    await page.getByLabel("Nama keluarga").fill("Keluarga Finansial");
    await button("Buat keluarga").click();
    await page.getByRole("link", { name: "Buka Dashboard" }).click();
    await page.waitForURL("**/dashboard");
    await button("Muat ulang").waitFor();
    await button("Keluar").click();
    await page.waitForURL("**/login");
    await page.getByLabel("Email", { exact: true }).fill(email);
    await page.getByLabel("Kata sandi", { exact: true }).fill(password);
    await button("Masuk").click();
    await page.waitForURL("**/dashboard");
    await button("Muat ulang").waitFor();
    assert.equal(await page.getByText("Keluarga Pratama").count(), 0);
    async function wallet(name, amount) {
      await button("Tambah dompet").click();
      await page.getByLabel("Nama dompet").fill(name);
      await page.getByLabel("Saldo awal (Rp)").fill(amount);
      await button("Simpan dompet").click();
      await saved();
    }
    await wallet("BCA keluarga", "1000000");
    await wallet("Tunai keluarga", "0");
    await nav("Target");
    await button("Tambah target").click();
    await page
      .getByLabel("Nama target", { exact: true })
      .fill("Pendidikan anak");
    await page.getByLabel("Nominal target (Rp)", { exact: true }).fill("0");
    await button("Simpan target").click();
    await page
      .getByRole("alert")
      .filter({ hasText: "lebih dari nol" })
      .waitFor();
    await page
      .getByLabel("Nominal target (Rp)", { exact: true })
      .fill("9007199254740993,25");
    await page.getByLabel("Tanggal target (opsional)").fill("2027-12-31");
    await button("Simpan target").click();
    await saved();
    await page
      .locator(".live-target-card")
      .getByText("dari Rp 9.007.199.254.740.993,25", { exact: true })
      .waitFor();
    await button("Kelola target").click();
    await page
      .getByLabel("Nominal target (Rp)", { exact: true })
      .fill("100000");
    await button("Simpan target").click();
    await saved();
    const card = page
      .locator(".live-target-card")
      .filter({ hasText: "Pendidikan anak" });
    async function movement(action, amount, note) {
      await card.getByRole("button", { name: action, exact: true }).click();
      await page.getByLabel("Nominal (Rp)", { exact: true }).fill(amount);
      await page.getByLabel("Catatan", { exact: true }).fill(note);
      await button("Simpan transaksi").click();
      await saved();
    }
    await movement("Setor dana", "100000", "Setoran pendidikan");
    await card.getByText("Target tercapai", { exact: true }).waitFor();
    await movement("Tarik dana", "40000", "Penarikan pendidikan");
    await card.getByText("Sedang dikumpulkan", { exact: true }).waitFor();
    await card
      .getByRole("button", { name: "Riwayat target", exact: true })
      .click();
    await page
      .getByRole("button")
      .filter({ hasText: "Penarikan pendidikan" })
      .click();
    await page.getByText("Target: Pendidikan anak", { exact: false }).waitFor();
    await button("Koreksi transaksi").click();
    assert.equal(await page.getByLabel("Jenis transaksi").isDisabled(), true);
    await page.getByLabel("Nominal (Rp)", { exact: true }).fill("50000");
    await page.getByLabel("Alasan koreksi").fill("Perbaiki penarikan");
    await button("Simpan koreksi").click();
    await saved();
    await card
      .locator(".live-target-balance")
      .filter({ hasText: "Rp 50.000" })
      .waitFor();
    await button("Riwayat target").click();
    await page
      .getByRole("button")
      .filter({ hasText: "Penarikan pendidikan" })
      .first()
      .click();
    await button("Batalkan transaksi").click();
    await page.getByLabel("Alasan pembatalan").fill("Dana tidak jadi diambil");
    await button("Konfirmasi pembatalan").click();
    await saved();
    await card.getByText("Target tercapai", { exact: true }).waitFor();
    await card.getByRole("button", { name: "Setor dana", exact: true }).click();
    await page.getByLabel("Nominal (Rp)", { exact: true }).fill("9999999");
    await button("Simpan transaksi").click();
    await page
      .getByRole("alert")
      .filter({ hasText: "Saldo dompet tidak mencukupi" })
      .waitFor();
    await button("Tutup dialog").click();
    await card.getByRole("button", { name: "Tarik dana", exact: true }).click();
    await page.getByLabel("Nominal (Rp)", { exact: true }).fill("100000.01");
    await button("Simpan transaksi").click();
    await page
      .getByRole("alert")
      .filter({ hasText: "Saldo target tidak mencukupi" })
      .waitFor();
    await button("Tutup dialog").click();
    let dropped = false;
    await page.route("**/goals/*/contributions", async (route) => {
      if (
        !dropped &&
        route.request().method() === "POST" &&
        route.request().postDataJSON().note === "Uji putus koneksi target"
      ) {
        dropped = true;
        await route.fetch();
        await route.abort();
      } else await route.continue();
    });
    await card.getByRole("button", { name: "Setor dana", exact: true }).click();
    await page.getByLabel("Nominal (Rp)", { exact: true }).fill("10000");
    await page
      .getByLabel("Catatan", { exact: true })
      .fill("Uji putus koneksi target");
    await button("Simpan transaksi").click();
    await page
      .getByRole("heading", { name: "Selesaikan pencatatan", exact: true })
      .waitFor();
    await page.reload();
    await page
      .getByRole("heading", { name: "Selesaikan pencatatan", exact: true })
      .waitFor();
    await button("Coba lagi").click();
    await saved();
    await card
      .locator(".live-target-balance")
      .filter({ hasText: "Rp 110.000" })
      .waitFor();
    assert(dropped);
    await page.unroute("**/goals/*/contributions");
    await nav("Dashboard");
    await page
      .locator(".finance-assets article")
      .filter({ hasText: "Total aset" })
      .getByText("Rp 1.000.000", { exact: true })
      .waitFor();
    await page
      .locator(".finance-summary article")
      .filter({ hasText: "Saldo tersedia" })
      .getByText("Rp 890.000", { exact: true })
      .waitFor();
    await page
      .locator(".finance-summary article")
      .filter({ hasText: "Pengeluaran" })
      .getByText("Rp 0", { exact: true })
      .waitFor();
    await nav("Transaksi");
    await page
      .getByLabel("Jenis", { exact: true })
      .selectOption("goal_contribution");
    await button("Terapkan").click();
    await page
      .locator(".finance-operation")
      .filter({ hasText: "Uji putus koneksi target" })
      .waitFor();
    assert.equal(await page.locator(".finance-operation").count(), 2);
    await nav("Target");
    await button("Kelola target").click();
    await page.getByLabel("Arsipkan target").check();
    await button("Simpan target").click();
    await saved();
    assert.equal(await page.locator(".live-target-card").count(), 0);
    await page
      .getByLabel("Status target", { exact: true })
      .selectOption("archived");
    await card.getByText("Diarsipkan", { exact: true }).waitFor();
    assert.equal(
      await card
        .getByRole("button", { name: "Setor dana", exact: true })
        .count(),
      0,
    );
    await page
      .locator(".finance-assets article")
      .filter({ hasText: "Total aset" })
      .getByText("Rp 1.000.000", { exact: true })
      .waitFor();
    await button("Kelola target").click();
    await page.getByLabel("Arsipkan target").uncheck();
    await button("Simpan target").click();
    await saved();
    await page
      .getByLabel("Status target", { exact: true })
      .selectOption("active");
    await card.waitFor();
    await page.screenshot({
      path: "/tmp/bahitung-goals-desktop.png",
      fullPage: true,
    });
    await page.setViewportSize({ width: 360, height: 800 });
    await page.screenshot({
      path: "/tmp/bahitung-goals-mobile.png",
      fullPage: true,
    });
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    );
    await button("Setor dana").click();
    await page.screenshot({
      path: "/tmp/bahitung-goals-deposit-mobile.png",
      fullPage: true,
    });
    assert(
      await page.evaluate(
        () =>
          document.querySelector("dialog").scrollWidth <=
          document.querySelector("dialog").clientWidth,
      ),
    );
    await button("Tutup dialog").click();
    const originalFamily = await page.getByLabel("Keluarga aktif").inputValue();
    await page
      .getByRole("navigation", { name: "Menu keuangan" })
      .getByRole("link", { name: "Keluarga", exact: true })
      .click();
    await page.getByLabel("Nama keluarga").fill("Keluarga Target Kedua");
    await button("Buat keluarga").click();
    await page
      .getByRole("heading", { name: "Keluarga Target Kedua", exact: true })
      .waitFor();
    await page.getByRole("link", { name: "Buka Dashboard" }).click();
    await nav("Target");
    await button("Tambah target").waitFor();
    assert.equal(await page.locator(".live-target-card").count(), 0);
    await page.getByLabel("Keluarga aktif").selectOption(originalFamily);
    await card.waitFor();
    await page.reload();
    await card.waitFor();
    // Test history pagination with tiny real contributions in the isolated test household.
    await page.evaluate(async () => {
      const { api } = await import("/src/services/api.ts");
      const { businessDate } = await import("/src/services/finance.ts");
      const root =
        "/api/v1/households/" + sessionStorage.getItem("bahitung.household");
      const goal = (await api(root + "/goals")).data[0];
      const wallet = (await api(root + "/wallets")).data.find(
        (w) => w.name === "BCA keluarga",
      );
      for (let i = 0; i < 21; i++)
        await api(
          root + "/goals/" + goal.id + "/contributions",
          "POST",
          {
            wallet_id: wallet.id,
            amount: "0.01",
            occurred_on: businessDate("Asia/Makassar"),
            note: "Halaman target " + i,
          },
          crypto.randomUUID(),
        );
    });
    await button("Muat ulang").click();
    await card.waitFor();
    await button("Riwayat target").click();
    await page
      .getByRole("dialog")
      .getByText("1 / 2", { exact: true })
      .waitFor();
    await button("Berikutnya").click();
    await page
      .getByRole("dialog")
      .getByText("2 / 2", { exact: true })
      .waitFor();
    await button("Tutup dialog").click();
    const { spawnSync } = require("node:child_process");
    const path = require("node:path");
    const php = String.raw`require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); config(['database.connections.pgsql.database'=>'bahitung_browser_testing']); Illuminate\Support\Facades\DB::purge('pgsql'); if(Illuminate\Support\Facades\DB::connection()->getDatabaseName()!=='bahitung_browser_testing'){exit(2);} $household=App\Models\Household::findOrFail((int)getenv('BAHITUNG_TEST_HOUSEHOLD')); $owner=App\Models\User::findOrFail($household->created_by_user_id); if($owner->email!==getenv('BAHITUNG_TEST_OWNER')){exit(3);} $viewer=App\Models\User::factory()->create(['email'=>getenv('BAHITUNG_TEST_VIEWER'),'password'=>Illuminate\Support\Facades\Hash::make('Finance-browser-123')]); App\Models\HouseholdMember::factory()->create(['user_id'=>$viewer->id,'household_id'=>$household->id,'role'=>getenv('BAHITUNG_TEST_ROLE')]);`;

    for (const role of ["viewer", "member"]) {
      const roleEmail = `goal-${role}-${Date.now()}@example.test`;
      const fixture = spawnSync("./bin/php", ["-r", php], {
        cwd: path.resolve(__dirname, "../../backend"),
        env: {
          ...process.env,
          BAHITUNG_TEST_HOUSEHOLD: originalFamily,
          BAHITUNG_TEST_OWNER: email,
          BAHITUNG_TEST_VIEWER: roleEmail,
          BAHITUNG_TEST_ROLE: role,
        },
        encoding: "utf8",
      });
      assert.equal(fixture.status, 0, fixture.stderr);
      const ctx = await browser.newContext({
        viewport: { width: 360, height: 800 },
      });
      const actor = await ctx.newPage();
      await actor.goto(base + "/login");
      await actor.getByLabel("Email", { exact: true }).fill(roleEmail);
      await actor.getByLabel("Kata sandi", { exact: true }).fill(password);
      await actor.getByRole("button", { name: "Masuk", exact: true }).click();
      await actor.waitForURL("**/dashboard");
      await actor
        .getByRole("navigation", { name: "Menu keuangan" })
        .getByRole("link", { name: "Target", exact: true })
        .click();
      await actor.locator(".live-target-card").waitFor();
      for (const name of ["Tambah target", "Kelola target", "Tarik dana"])
        assert.equal(
          await actor.getByRole("button", { name, exact: true }).count(),
          0,
        );
      assert.equal(
        await actor
          .getByRole("button", { name: "Setor dana", exact: true })
          .count(),
        role === "member" ? 1 : 0,
      );
      if (role === "member") {
        await actor
          .getByRole("button", { name: "Setor dana", exact: true })
          .click();
        await actor.getByLabel("Nominal (Rp)", { exact: true }).fill("1000");
        await actor
          .getByLabel("Catatan", { exact: true })
          .fill("Setoran anggota");
        await actor
          .getByRole("button", { name: "Simpan transaksi", exact: true })
          .click();
        await actor.getByRole("dialog").waitFor({ state: "hidden" });
      }
      await actor
        .getByRole("button", { name: "Riwayat target", exact: true })
        .click();
      await actor.getByRole("dialog").locator("li button").first().click();
      assert.equal(
        await actor
          .getByRole("button", { name: "Koreksi transaksi", exact: true })
          .count(),
        0,
      );
      assert.equal(
        await actor
          .getByRole("button", { name: "Batalkan transaksi", exact: true })
          .count(),
        0,
      );
      await ctx.close();
    }
    await button("Keluar").click();
    await page.goto(base + "/target");
    await page.waitForURL("**/login?next=**");
    assert.deepEqual(errors, []);
    console.log(
      "Goals browser passed: metadata, precision, deposit/withdraw, corrections/void, insufficient balances, committed-response loss/reload/retry, asset conservation, filters/archive/history pagination, family isolation, Viewer/Member permissions, mobile, auth guard.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
