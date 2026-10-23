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
    const email = `finance-${Date.now()}@example.test`,
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
    await nav("Transaksi");
    async function category(name, type) {
      await button("Tambah kategori").first().click();
      await page.getByLabel("Nama kategori").fill(name);
      await page.getByLabel("Jenis kategori").selectOption(type);
      await button("Simpan kategori").click();
      await saved();
    }
    await category("Belanja", "expense");
    await category("Gaji", "income");
    async function operation(type, amount, note) {
      await button("Tambah transaksi").click();
      await page.getByLabel("Jenis transaksi").selectOption(type);
      await page
        .getByLabel("Kategori transaksi")
        .selectOption({ label: type === "income" ? "Gaji" : "Belanja" });
      await page.getByLabel("Nominal (Rp)", { exact: true }).fill(amount);
      await page.getByLabel("Catatan", { exact: true }).fill(note);
      await button("Simpan transaksi").click();
      await saved();
    }
    await nav("Budget");
    await button("Buat budget").click();
    await page
      .getByLabel("Kategori budget 1", { exact: true })
      .selectOption({ label: "Belanja" });
    await page.getByLabel("Batas budget 1", { exact: true }).fill("0");
    await button("Simpan budget").click();
    await page
      .getByRole("alert")
      .filter({ hasText: "lebih dari nol" })
      .waitFor();
    await page.getByLabel("Batas budget 1", { exact: true }).fill("100000");
    await button("Simpan budget").click();
    await saved();
    const budgetMonth = await page.getByLabel("Bulan budget").inputValue();
    const [by, bm] = budgetMonth.split("-").map(Number);
    const nextMonth =
      bm === 12 ? `${by + 1}-01` : `${by}-${String(bm + 1).padStart(2, "0")}`;
    await page.getByLabel("Bulan budget").fill(nextMonth);
    await button("Salin budget bulan lalu").click();
    await button("Salin sekarang").click();
    await saved();
    await button("Edit budget").click();
    await page
      .getByLabel("Batas budget 1", { exact: true })
      .fill("9007199254740993,25");
    await button("Simpan budget").click();
    await saved();
    await page
      .locator(".finance-summary")
      .getByText("Rp 9.007.199.254.740.993,25", { exact: true })
      .first()
      .waitFor();
    await page.getByLabel("Bulan budget").fill(budgetMonth);
    await page
      .locator(".finance-summary")
      .getByText("Rp 100.000", { exact: true })
      .first()
      .waitFor();
    await nav("Transaksi");
    await operation("expense", "100000", "Belanja awal");
    await page
      .locator(".live-budget-warning")
      .filter({ hasText: "100,00%" })
      .waitFor();
    await operation("income", "200000", "Gaji bulan ini");
    await button("Transfer").click();
    await page
      .getByLabel("Dompet tujuan")
      .selectOption({ label: "Tunai keluarga" });
    await page.getByLabel("Nominal (Rp)", { exact: true }).fill("500000");
    await page.getByLabel("Biaya admin (Rp)").fill("2500");
    await page
      .getByLabel("Kategori biaya admin")
      .selectOption({ label: "Belanja" });
    await page.getByLabel("Catatan", { exact: true }).fill("Transfer keluarga");
    assert(await page.getByText("Rp 502.500", { exact: true }).isVisible());
    await button("Simpan transaksi").click();
    await saved();
    await page
      .locator(".live-budget-warning")
      .filter({ hasText: "102,50%" })
      .waitFor();
    await page.locator(".live-budget-warning a").click();
    await page.locator('.live-budget-line[data-status="exceeded"]').waitFor();
    await page.screenshot({
      path: "/tmp/bahitung-budget-desktop.png",
      fullPage: true,
    });
    await page.setViewportSize({ width: 360, height: 800 });
    await page.screenshot({
      path: "/tmp/bahitung-budget-mobile.png",
      fullPage: true,
    });
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    );
    await button("Edit budget").click();
    assert(
      await page.evaluate(
        () =>
          document.querySelector("dialog").scrollWidth <=
          document.querySelector("dialog").clientWidth,
      ),
    );
    await button("Tutup dialog").click();
    await page.setViewportSize({ width: 1440, height: 1000 });
    await nav("Dashboard");
    assert(
      await page
        .locator(".finance-summary")
        .getByText("Rp 1.097.500", { exact: true })
        .isVisible(),
    );
    assert(
      await page
        .locator(".finance-summary")
        .getByText("Rp 102.500", { exact: true })
        .isVisible(),
    );
    await nav("Budget");
    await button("Edit budget").click();
    await page.getByLabel("Batas budget 1", { exact: true }).fill("156250");
    await button("Simpan budget").click();
    await saved();
    await nav("Transaksi");
    await page
      .locator(".finance-operation")
      .filter({ hasText: "Belanja awal" })
      .click();
    await button("Koreksi transaksi").click();
    await page.getByLabel("Nominal (Rp)", { exact: true }).fill("125000");
    await page.getByLabel("Alasan koreksi").fill("Tambah belanja");
    await button("Simpan koreksi").click();
    await saved();
    await page
      .locator(".finance-operation")
      .filter({ hasText: "Transfer keluarga" })
      .click();
    await button("Batalkan transaksi").click();
    await page.getByLabel("Alasan pembatalan").fill("Transfer duplikat");
    await button("Konfirmasi pembatalan").click();
    await saved();
    await page
      .locator(".live-budget-warning")
      .filter({ hasText: "80,00%" })
      .waitFor();
    await nav("Budget");
    await page
      .locator(".live-budget-line")
      .filter({ hasText: "Rp 125.000" })
      .waitFor();
    await nav("Transaksi");
    await button("Tambah transaksi").click();
    await page
      .getByLabel("Kategori transaksi")
      .selectOption({ label: "Belanja" });
    await page.getByLabel("Nominal (Rp)", { exact: true }).fill("9999999");
    await button("Simpan transaksi").click();
    await page
      .getByRole("alert")
      .filter({ hasText: "Saldo dompet tidak mencukupi" })
      .waitFor();
    await button("Tutup dialog").click();
    // Drop a response AFTER backend commit. Reload and retry must not post twice.
    let dropped = false;
    await page.route("**/api/v1/households/*/operations", async (route) => {
      if (
        !dropped &&
        route.request().method() === "POST" &&
        route.request().postDataJSON().note === "Koneksi terputus"
      ) {
        dropped = true;
        await route.fetch();
        await route.abort("failed");
      } else await route.continue();
    });
    await button("Tambah transaksi").click();
    await page
      .getByLabel("Kategori transaksi")
      .selectOption({ label: "Belanja" });
    await page.getByLabel("Nominal (Rp)", { exact: true }).fill("10000");
    await page.getByLabel("Catatan", { exact: true }).fill("Koneksi terputus");
    await button("Simpan transaksi").click();
    await page
      .getByRole("heading", { name: "Selesaikan pencatatan" })
      .waitFor();
    await page.reload();
    await page
      .getByRole("heading", { name: "Selesaikan pencatatan" })
      .waitFor();
    await button("Coba lagi").click();
    await saved();
    assert.equal(
      await page
        .locator(".finance-operation")
        .filter({ hasText: "Koneksi terputus" })
        .count(),
      1,
    );
    assert.equal(
      await page.evaluate(
        () =>
          Object.keys(sessionStorage).filter((k) =>
            k.startsWith("bahitung.pending."),
          ).length,
      ),
      0,
    );
    await nav("Dompet");
    let card = page
      .locator(".finance-wallet")
      .filter({ hasText: "Tunai keluarga" });
    await card.getByRole("button", { name: "Kelola dompet" }).click();
    await page.getByLabel("Arsipkan dompet", { exact: false }).check();
    await button("Simpan dompet").click();
    await saved();
    await card.getByRole("button", { name: "Kelola dompet" }).click();
    await page.getByLabel("Arsipkan dompet", { exact: false }).uncheck();
    await button("Simpan dompet").click();
    await saved();
    await page
      .locator(".finance-wallet")
      .filter({ hasText: "BCA keluarga" })
      .getByRole("button", { name: "Riwayat", exact: true })
      .click();
    await page.getByRole("heading", { name: "Riwayat BCA keluarga" }).waitFor();
    assert((await page.getByRole("dialog").getByText("Pembalik").count()) > 0);
    await button("Tutup dialog").click();
    await nav("Dashboard");
    assert(
      await page
        .locator(".finance-summary")
        .getByText("Rp 1.065.000", { exact: true })
        .isVisible(),
    );
    await page.screenshot({
      path: "/tmp/bahitung-finance-desktop.png",
      fullPage: true,
    });
    const originalFamily = await page.getByLabel("Keluarga aktif").inputValue();
    await page
      .getByRole("navigation", { name: "Menu keuangan" })
      .getByRole("link", { name: "Keluarga", exact: true })
      .click();
    await page.waitForURL("**/keluarga");
    await page.getByLabel("Nama keluarga").fill("Keluarga Kedua");
    await button("Buat keluarga").click();
    await page
      .getByRole("heading", { name: "Keluarga Kedua", exact: true })
      .waitFor();
    await page.getByRole("link", { name: "Buka Dashboard" }).click();
    await button("Muat ulang").waitFor();
    assert.equal(await page.locator(".finance-wallet").count(), 0);
    await nav("Budget");
    await button("Buat budget").waitFor();
    assert.equal(await page.locator(".live-budget-line").count(), 0);
    await page.getByLabel("Keluarga aktif").selectOption(originalFamily);
    await button("Edit budget").waitFor();
    await page
      .locator(".live-budget-line")
      .filter({ hasText: "Belanja" })
      .waitFor();
    await nav("Dashboard");
    await page
      .locator(".finance-wallet")
      .filter({ hasText: "BCA keluarga" })
      .waitFor();
    await page.reload();
    await page
      .locator(".finance-wallet")
      .filter({ hasText: "BCA keluarga" })
      .waitFor();
    assert.equal(
      await page.getByLabel("Keluarga aktif").inputValue(),
      originalFamily,
    );
    await page.setViewportSize({ width: 360, height: 800 });
    await page.screenshot({
      path: "/tmp/bahitung-finance-mobile.png",
      fullPage: true,
    });
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth,
      ),
    );
    await button("Transfer").click();
    await page.getByLabel("Nominal (Rp)", { exact: true }).fill("1000");
    await page.screenshot({
      path: "/tmp/bahitung-finance-transfer-mobile.png",
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

    // Exact decimal helpers must not round persisted money through Number.
    const precision = await page.evaluate(async () => {
      const m = await import("/src/services/finance.ts");
      return [
        m.money("9007199254740993.25"),
        m.amountInput("9007199254740993,25"),
        m.decimal(m.cents("9007199254740993.25") + m.cents("0.01")),
      ];
    });
    assert.deepEqual(precision, [
      "Rp 9.007.199.254.740.993,25",
      "9007199254740993.25",
      "9007199254740993.26",
    ]);

    // Exercise page boundaries and filters using additional test-only transactions.
    await page.evaluate(async () => {
      const { api } = await import("/src/services/api.ts");
      const h = Number(sessionStorage.getItem("bahitung.household"));
      const root = "/api/v1/households/" + h;
      const wallets = await api(root + "/wallets");
      const categories = await api(root + "/categories");
      const wallet = wallets.data.find((w) => w.name === "BCA keluarga");
      const category = categories.data.find((c) => c.name === "Belanja");
      const { businessDate } = await import("/src/services/finance.ts");
      for (let i = 0; i < 21; i++)
        await api(
          root + "/operations",
          "POST",
          {
            type: "expense",
            wallet_id: wallet.id,
            category_id: category.id,
            amount: "0.01",
            occurred_on: businessDate("Asia/Makassar"),
            note: "Uji halaman " + i,
          },
          crypto.randomUUID(),
        );
    });
    await nav("Transaksi");
    assert.equal(await page.locator(".finance-operation").count(), 20);
    await button("Berikutnya").click();
    await page.getByText("2 / 2", { exact: true }).waitFor();
    assert.equal(await page.locator(".finance-operation").count(), 7);
    await page.getByLabel("Jenis", { exact: true }).selectOption("income");
    await button("Terapkan").click();
    await page
      .locator(".finance-operation")
      .filter({ hasText: "Gaji bulan ini" })
      .waitFor();
    assert.equal(await page.locator(".finance-operation").count(), 1);
    // A real Viewer fixture in this test-owned household verifies read-only controls.
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
    await viewer.locator(".finance-wallet").first().waitFor();
    assert.equal(
      await viewer
        .getByRole("button", { name: "Tambah transaksi", exact: true })
        .count(),
      0,
    );
    assert.equal(
      await viewer
        .getByRole("button", { name: "Tambah dompet", exact: true })
        .count(),
      0,
    );
    await viewer.locator(".finance-operation").first().click();
    assert.equal(
      await viewer
        .getByRole("button", { name: "Koreksi transaksi", exact: true })
        .count(),
      0,
    );
    assert.equal(
      await viewer
        .getByRole("button", { name: "Batalkan transaksi", exact: true })
        .count(),
      0,
    );
    await viewer
      .getByRole("button", { name: "Tutup dialog", exact: true })
      .click();
    await viewer
      .getByRole("navigation", { name: "Menu keuangan" })
      .getByRole("link", { name: "Budget", exact: true })
      .click();
    await viewer.locator(".live-budget-line").first().waitFor();
    assert.equal(
      await viewer
        .getByRole("button", { name: "Edit budget", exact: true })
        .count(),
      0,
    );
    await viewerContext.close();
    await button("Keluar").click();
    await page.waitForURL("**/login");
    await page.goto(base + "/dashboard");
    await page.waitForURL("**/login?next=**");
    assert.deepEqual(errors, []);
    console.log(
      "Finance browser passed: Budget create/edit/copy, precision, warnings 80/100/over, fee/correction/void usage, Budget family isolation/Viewer/mobile, login/dashboard, wallets, categories, income/expense, transfer/fee, correction/void, insufficient balance, committed-response loss/reload/retry, history, archive, family isolation, mobile, logout guards.",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
