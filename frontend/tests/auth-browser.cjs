// Run against isolated browser test servers (see backend/README.md).
const { chromium } = require(process.env.BAHITUNG_PLAYWRIGHT || '/tmp/bahitung-ui-tools/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const base = 'http://127.0.0.1:5174';
const logFile = path.resolve(__dirname, '../../backend/storage/logs/laravel.log');
const stamp = Date.now();
const email = `browser-${stamp}@example.test`, guest = `guest-${stamp}@example.test`;
const password = 'Browser-password-123';
function link(kind) {
 const content=fs.readFileSync(logFile,'utf8').replace(/=\r?\n/g,'').replace(/=3D/g,'=');
 const matches = content.match(new RegExp('http://127\\.0\\.0\\.1:5174/'+kind+'\\?[^\\s"<>]+','g')) || [];
 assert(matches.length, 'Email link should be logged');
 return matches.at(-1).replace(/&amp;/g,'&').replace(/\)$/, '');
}
(async()=>{
 const browser = await chromium.launch({headless:true});
 try {
 const context = await browser.newContext({viewport:{width:1440,height:1000}});
 const page = await context.newPage(); page.setDefaultTimeout(10000); page.setDefaultNavigationTimeout(10000); const errors=[]; page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base+'/'); await page.waitForURL('**/login?next=**');
 await page.getByRole('link',{name:'Daftar sekarang'}).click();
 await page.getByLabel('Nama lengkap').fill('Pengguna Browser'); await page.getByLabel('Email',{exact:true}).fill(email);
 await page.getByLabel('Kata sandi',{exact:true}).fill(password); await page.getByLabel('Konfirmasi kata sandi').fill(password);
 await page.getByRole('button',{name:'Buat akun',exact:true}).click(); await page.waitForURL('**/keluarga');
 await page.getByRole('heading',{name:'Mulai dari satu keluarga'}).waitFor();
 const verify = link('verifikasi-email');
 await page.reload(); await page.getByRole('heading',{name:'Mulai dari satu keluarga'}).waitFor();
 const noCsrf = await context.request.post(base+'/api/v1/households',{headers:{Origin:base,'Sec-Fetch-Site':'cross-site',Accept:'application/json'},data:{name:'Must not exist'}});
 assert.equal(noCsrf.status(),419,'Cross-site request without CSRF header must be rejected');
 await page.goto(verify); await page.getByRole('button',{name:'Verifikasi email',exact:true}).click(); await page.getByRole('status').filter({hasText:'Email berhasil diverifikasi'}).waitFor();
 await page.getByRole('link',{name:'Kembali ke keluarga'}).click();
 await page.getByLabel('Nama keluarga').fill('Keluarga Browser'); await page.getByRole('button',{name:'Buat keluarga',exact:true}).click();
 await page.getByRole('heading',{name:'Keluarga Browser',exact:true}).waitFor();
 await page.getByLabel('Email penerima').fill(guest); await page.getByRole('button',{name:'Kirim undangan',exact:true}).click();
 await page.getByRole('status').filter({hasText:'Undangan berhasil'}).waitFor(); const invitation = link('undangan');
 await page.screenshot({path:'/tmp/bahitung-family-desktop.png',fullPage:true});
 assert.equal(await page.evaluate(()=>localStorage.length),0);
 const context2=await browser.newContext({viewport:{width:360,height:800}}); const p2=await context2.newPage(); p2.setDefaultTimeout(10000); p2.setDefaultNavigationTimeout(10000); p2.on('pageerror',e=>errors.push(e.message));
 await p2.goto(invitation); await p2.getByRole('link',{name:'Daftar sekarang'}).click();
 await p2.getByLabel('Nama lengkap').fill('Anggota Browser'); await p2.getByLabel('Email',{exact:true}).fill(guest);
 await p2.getByLabel('Kata sandi',{exact:true}).fill(password); await p2.getByLabel('Konfirmasi kata sandi').fill(password);
 await p2.getByRole('button',{name:'Buat akun',exact:true}).click(); await p2.waitForURL('**/undangan?**');
 await p2.getByRole('button',{name:'Terima undangan',exact:true}).waitFor(); assert.equal(await p2.getByRole('button',{name:'Terima undangan',exact:true}).isDisabled(),true);
 await p2.goto(link('verifikasi-email')); await p2.getByRole('button',{name:'Verifikasi email',exact:true}).click(); await p2.getByRole('status').filter({hasText:'Email berhasil diverifikasi'}).waitFor();
 await p2.goto(invitation); await p2.getByRole('button',{name:'Terima undangan',exact:true}).click(); await p2.waitForURL('**/keluarga');
 await p2.getByRole('heading',{name:'Keluarga Browser',exact:true}).waitFor();
 assert.equal(await p2.getByRole('heading',{name:'Undang anggota',exact:true}).count(),0);
 await p2.screenshot({path:'/tmp/bahitung-family-mobile.png',fullPage:true});
 assert(await p2.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth));
 await p2.getByRole('button',{name:'Keluar',exact:true}).click(); await p2.waitForURL('**/login');
 await p2.getByRole('link',{name:'Lupa kata sandi?'}).click(); await p2.getByLabel('Email',{exact:true}).fill(guest); await p2.getByRole('button',{name:'Kirim tautan reset'}).click();
 await p2.getByRole('status').filter({hasText:'Jika email terdaftar'}).waitFor();
 await p2.goto(link('reset-password')); await p2.getByLabel('Kata sandi',{exact:true}).fill('New-browser-password-456'); await p2.getByLabel('Konfirmasi kata sandi').fill('New-browser-password-456'); await p2.getByRole('button',{name:'Simpan kata sandi'}).click(); await p2.waitForURL('**/login?reset=berhasil');
 await p2.getByLabel('Email',{exact:true}).fill(guest); await p2.getByLabel('Kata sandi',{exact:true}).fill('New-browser-password-456'); await p2.getByRole('button',{name:'Masuk',exact:true}).click(); await p2.waitForURL('**/dashboard');
 await page.getByRole('button',{name:'Keluar',exact:true}).click(); await page.waitForURL('**/login');
 assert.equal(await page.evaluate(async()=> (await fetch('/api/v1/me',{headers:{Accept:'application/json'},credentials:'include'})).status),401);
 await page.screenshot({path:'/tmp/bahitung-login-desktop.png',fullPage:true});
 await p2.goto(base+'/login'); await p2.screenshot({path:'/tmp/bahitung-login-mobile.png',fullPage:true}); assert(await p2.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth));
 assert.deepEqual(errors,[]);
 console.log('Browser auth passed: registration, reload, CSRF, email verification, family creation, invitation acceptance, reset, login, logout, desktop/mobile.');
 } finally { await browser.close(); }
})().catch(e=>{ console.error(e.message); process.exit(1); });
