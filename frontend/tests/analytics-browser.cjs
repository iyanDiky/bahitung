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
    const email = `analytics-${Date.now()}@example.test`,
      password = "Finance-browser-123";
    const button = (name) => page.getByRole("button", { name, exact: true });
    const nav = async (name) => {
      await page
        .getByRole("navigation", { name: "Menu keuangan" })
        .getByRole("link", { name, exact: true })
        .click();
      await page
        .getByRole("heading", {
          name: name === "Dompet" ? "Dompet keluarga" : name,
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
    const fixtureData = await page.evaluate(async () => {
      const { api } = await import("/src/services/api.ts");
      const { businessDate } = await import("/src/services/finance.ts");
      const root =
        "/api/v1/households/" + sessionStorage.getItem("bahitung.household");
      const wallets = (await api(root + "/wallets")).data;
      const source = wallets.find((w) => w.name === "BCA keluarga").id,
        destination = wallets.find((w) => w.name === "Tunai keluarga").id;
      const category = async (name, type) =>
        (await api(root + "/categories", "POST", { name, type })).data.id;
      const income = await category("Gaji", "income"),
        expense = await category("Belanja", "expense"),
        fee = await category("Biaya admin", "expense");
      const today = businessDate("Asia/Makassar");
      const post = async (body) =>
        (
          await api(
            root + "/operations",
            "POST",
            { occurred_on: today, ...body },
            crypto.randomUUID(),
          )
        ).data.id;
      await post({
        type: "income",
        wallet_id: source,
        category_id: income,
        amount: "200000.00",
      });
      const expenseId = await post({
        type: "expense",
        wallet_id: source,
        category_id: expense,
        amount: "50000.00",
      });
      await post({
        type: "transfer",
        source_wallet_id: source,
        destination_wallet_id: destination,
        amount: "100000.00",
        fee_amount: "2500.00",
        fee_category_id: fee,
      });
      await post({
        type: "expense",
        wallet_id: destination,
        category_id: expense,
        amount: "10000.00",
      });
      const goal = (
        await api(root + "/goals", "POST", {
          name: "Sekolah",
          target_amount: "100000.00",
        })
      ).data.id;
      await api(
        root + "/goals/" + goal + "/contributions",
        "POST",
        { wallet_id: source, amount: "100000.00", occurred_on: today },
        crypto.randomUUID(),
      );
      return { root, source, destination, income, expense, expenseId, today };
    });
    const summary = (label) =>
      page.locator(".live-analysis-summary article").filter({
        has: page
          .locator("p")
          .filter({ hasText: new RegExp("^" + label + "$") }),
      });
    await nav("Analisis");
    await summary("Pengeluaran")
      .getByText("Rp 62.500", { exact: true })
      .waitFor();
    await summary("Pemasukan")
      .getByText("Rp 200.000", { exact: true })
      .waitFor();
    await summary("Surplus periode")
      .getByText("Rp 137.500", { exact: true })
      .waitFor();
    await summary("Biaya admin transfer")
      .getByText("Rp 2.500", { exact: true })
      .waitFor();
    await page
      .locator(".live-category-list")
      .getByText("Biaya admin", { exact: true })
      .waitFor();
    await page
      .getByRole("group", { name: "Jenis kategori laporan" })
      .getByRole("button", { name: "Pemasukan", exact: true })
      .click();
    await page
      .locator(".live-category-list")
      .getByText("Gaji", { exact: true })
      .waitFor();
    await page
      .getByRole("group", { name: "Jenis kategori laporan" })
      .getByRole("button", { name: "Pengeluaran", exact: true })
      .click();
    async function walletFilter(id) {
      await page
        .getByLabel("Dompet laporan", { exact: true })
        .selectOption(String(id));
      await button("Tampilkan laporan").click();
      await page.locator(".live-report-period").waitFor();
    }
    await walletFilter(fixtureData.destination);
    await summary("Pengeluaran")
      .getByText("Rp 10.000", { exact: true })
      .waitFor();
    await summary("Biaya admin transfer")
      .getByText("Rp 0", { exact: true })
      .waitFor();
    await summary("Defisit periode")
      .getByText("−Rp 10.000", { exact: true })
      .waitFor();
    await page
      .getByText("Rasio belum tersedia karena pemasukan nol", { exact: true })
      .waitFor();
    await walletFilter("");
    await page.evaluate(async (f) => {
      const { api } = await import("/src/services/api.ts");
      await api(
        f.root + "/operations/" + f.expenseId + "/correct",
        "POST",
        {
          reason: "Perbaiki laporan",
          replacement: {
            type: "expense",
            wallet_id: f.source,
            category_id: f.expense,
            amount: "75000.00",
            occurred_on: f.today,
          },
        },
        crypto.randomUUID(),
      );
      await api(f.root + "/categories/" + f.expense, "PATCH", {
        is_archived: true,
      });
      await api(f.root + "/wallets/" + f.source, "PATCH", {
        is_archived: true,
      });
    }, fixtureData);
    await button("Muat ulang").click();
    await summary("Pengeluaran")
      .getByText("Rp 87.500", { exact: true })
      .waitFor();
    await page
      .locator(".live-category-list")
      .getByText("Belanja (arsip)", { exact: true })
      .waitFor();
    assert(
      await page
        .getByLabel("Dompet laporan")
        .getByRole("option", { name: "BCA keluarga (arsip)", exact: true })
        .count(),
    );
    const [year, month] = fixtureData.today.split("-").map(Number);
    const previous =
      month === 1
        ? `${year - 1}-12-01`
        : `${year}-${String(month - 1).padStart(2, "0")}-01`;
    await page.getByLabel("Dari tanggal", { exact: true }).fill(previous);
    await button("Tampilkan laporan").click();
    await page.locator(".live-report-period").waitFor();
    await page
      .getByLabel("Tampilan grafik", { exact: true })
      .selectOption("monthly");
    assert.equal(await page.locator(".live-chart-period").count(), 2);
    await page
      .getByText("Lihat rincian angka bulanan", { exact: true })
      .click();
    assert.equal(await page.locator(".live-report-table tbody tr").count(), 2);
    await page
      .getByText("Lihat rincian angka bulanan", { exact: true })
      .click();
    await page.evaluate(() => scrollTo(0, 0));
    await page.screenshot({
      path: "/tmp/bahitung-analytics-desktop.png",
      fullPage: true,
    });
    await page.setViewportSize({ width: 360, height: 800 });
    await page.screenshot({
      path: "/tmp/bahitung-analytics-mobile.png",
      fullPage: true,
    });
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    );
    await page
      .getByLabel("Tampilan grafik", { exact: true })
      .selectOption("daily");
    await page.getByText("Lihat rincian angka harian", { exact: true }).click();
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    );
    await page.getByLabel("Dari tanggal", { exact: true }).fill("2024-01-01");
    await button("Tampilkan laporan").click();
    await page.getByRole("alert").filter({ hasText: "366 hari" }).waitFor();
    assert.equal(await page.locator(".live-analysis-summary").count(), 0);
    await page.getByLabel("Dari tanggal", { exact: true }).fill("2024-02-01");
    await page.getByLabel("Sampai tanggal", { exact: true }).fill("2024-02-29");
    await button("Tampilkan laporan").click();
    await page
      .getByRole("heading", {
        name: "Belum ada pemasukan atau pengeluaran",
        exact: true,
      })
      .waitFor();
    await page
      .getByLabel("Dari tanggal", { exact: true })
      .fill(fixtureData.today.slice(0, 7) + "-01");
    await page
      .getByLabel("Sampai tanggal", { exact: true })
      .fill(fixtureData.today);
    await page.route("**/reports/cashflow?**", (route) => route.abort());
    await button("Tampilkan laporan").click();
    await page
      .getByRole("alert")
      .filter({ hasText: "Tidak dapat terhubung" })
      .waitFor();
    assert.equal(await page.locator(".live-analysis-summary").count(), 0);
    await page.unroute("**/reports/cashflow?**");
    await button("Muat ulang").click();
    await summary("Pengeluaran")
      .getByText("Rp 87.500", { exact: true })
      .waitFor();
    const originalFamily = await page.getByLabel("Keluarga aktif").inputValue();
    const secondFamily = await page.evaluate(async () => {
      const { api } = await import("/src/services/api.ts");
      const { useAuth } = await import("/src/stores/auth.ts");
      const family = await api("/api/v1/households", "POST", {
        name: "Keluarga Analisis Kedua",
        timezone: "Asia/Makassar",
      });
      await useAuth().refresh();
      return String(family.data.id);
    });
    let release;
    const blocked = new Promise((resolve) => (release = resolve));
    let captured;
    const ready = new Promise((resolve) => (captured = resolve));
    await page.route("**/reports/cashflow?**", async (route) => {
      if (
        route
          .request()
          .url()
          .includes("/households/" + originalFamily + "/")
      ) {
        const response = await route.fetch();
        captured();
        await blocked;
        await route.fulfill({ response });
      } else await route.continue();
    });
    await button("Muat ulang").click();
    await ready;
    await page.getByLabel("Keluarga aktif").selectOption(secondFamily);
    await page
      .getByRole("heading", {
        name: "Belum ada pemasukan atau pengeluaran",
        exact: true,
      })
      .waitFor();
    release();
    await page.unrouteAll({ behavior: "wait" });
    await summary("Pemasukan").getByText("Rp 0", { exact: true }).waitFor();
    assert.equal(await page.locator(".live-category-list li").count(), 0);
    await page.getByLabel("Keluarga aktif").selectOption(originalFamily);
    await summary("Pengeluaran")
      .getByText("Rp 87.500", { exact: true })
      .waitFor();
    await page.reload();
    await summary("Pengeluaran")
      .getByText("Rp 87.500", { exact: true })
      .waitFor();
    const { spawnSync } = require("node:child_process");
    const path = require("node:path");
    const viewerEmail = `viewer-${Date.now()}@example.test`;
    const php = String.raw`require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); config(['database.connections.pgsql.database'=>'bahitung_browser_testing']); Illuminate\Support\Facades\DB::purge('pgsql'); if(Illuminate\Support\Facades\DB::connection()->getDatabaseName()!=='bahitung_browser_testing'){exit(2);} $household=App\Models\Household::findOrFail((int)getenv('BAHITUNG_TEST_HOUSEHOLD')); $owner=App\Models\User::findOrFail($household->created_by_user_id); if($owner->email!==getenv('BAHITUNG_TEST_OWNER')){exit(3);} $viewer=App\Models\User::factory()->create(['email'=>getenv('BAHITUNG_TEST_VIEWER'),'password'=>Illuminate\Support\Facades\Hash::make('Finance-browser-123')]); App\Models\HouseholdMember::factory()->create(['user_id'=>$viewer->id,'household_id'=>$household->id,'role'=>'viewer']);`;
    const fixture = spawnSync("./bin/php", ["-r", php], {
      cwd: path.resolve(__dirname, "../../backend"),
      env: {
        ...process.env,
        BAHITUNG_TEST_HOUSEHOLD: originalFamily,
        BAHITUNG_TEST_OWNER: email,
        BAHITUNG_TEST_VIEWER: viewerEmail,
      },
      encoding: "utf8",
    });
    assert.equal(fixture.status, 0, fixture.stderr);
    const viewerContext = await browser.newContext({
      viewport: { width: 360, height: 800 },
    });
    const viewer = await viewerContext.newPage();
    await viewer.goto(base + "/login");
    await viewer.getByLabel("Email", { exact: true }).fill(viewerEmail);
    await viewer.getByLabel("Kata sandi", { exact: true }).fill(password);
    await viewer.getByRole("button", { name: "Masuk", exact: true }).click();
    await viewer.waitForURL("**/dashboard");

    await viewer
      .getByRole("navigation", { name: "Menu keuangan" })
      .getByRole("link", { name: "Analisis", exact: true })
      .click();
    await viewer.locator(".live-analysis-summary").waitFor();
    assert(
      await viewer
        .getByRole("button", { name: "Tampilkan laporan", exact: true })
        .isEnabled(),
    );
    await viewer
      .getByLabel("Dompet laporan", { exact: true })
      .selectOption(String(fixtureData.destination));
    await viewer
      .getByRole("button", { name: "Tampilkan laporan", exact: true })
      .click();
    await viewer
      .locator(".live-analysis-summary article")
      .filter({ hasText: "Pengeluaran" })
      .getByText("Rp 10.000", { exact: true })
      .waitFor();
    await viewerContext.close();
    await button("Keluar").click();
    await page.goto(base + "/analisis");
    await page.waitForURL("**/login?next=**");
    assert.deepEqual(errors, []);
    console.log(
      "Analytics browser passed: totals/categories, fee source filter, goal exclusion, correction/archive refresh, daily/monthly tables, empty/leap period, validation, network recovery, stale-response/family isolation, Viewer, mobile and auth guard.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
