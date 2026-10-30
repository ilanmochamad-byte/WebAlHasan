/**
 * Uji browser perbaikan "Terbitkan tagihan" satu santri dari tarif.
 *
 *   V6F5_DB=test_v6_satu_santri_browser_test bash tests/v6_phase5_db.sh
 *   DB_NAME=test_v6_satu_santri_browser_test php tests/v6_tagihan_satu_santri_seed.php
 *   DB_NAME=test_v6_satu_santri_browser_test php -S 127.0.0.1:8967 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8967 SMP_LAIN=<id santri SMP aktif selain V6S0001> node tests/browser/uji-tagihan-satu-santri.mjs
 *
 *   B-1  375/768/1280: halaman santri bertarif (rincian tarif + tombol terbit)
 *        dan santri tanpa kelas (isian manual) rapi di kerangka bersama;
 *   B-2  dari rincian santri: tombol "Terbitkan tagihan" → rincian tarif →
 *        Terbitkan → rincian tagihan Rp300.000 (dengan JavaScript, 1280px);
 *   B-3  tanpa JavaScript (375px): terbit dari tarif untuk santri SMP lain;
 *   B-4  tanpa galat JavaScript dan tanpa dialog bawaan.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8967';
const SMP_LAIN = Number(process.env.SMP_LAIN ?? 0);
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-satu-santri', import.meta.url).pathname;
mkdirSync(OUT, { recursive: true });
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE) || !(SMP_LAIN > 0)) throw new Error('Uji hanya untuk server lokal 127.0.0.1 dengan SMP_LAIN');
let lulus = 0, gagal = 0;
const galat = [], bawaan = [];
const cek = (ok, label, ket = '') => { ok ? lulus++ : gagal++; console.log(`${ok ? '[lulus]' : '[gagal]'} ${label}${!ok && ket ? ' — ' + ket : ''}`); };
const CDN = [
  [/bootstrap[^/]*\/dist\/css\/bootstrap\.min\.css/, 'bootstrap/dist/css/bootstrap.min.css', 'text/css'],
  [/bootstrap[^/]*\/dist\/js\/bootstrap\.bundle\.min\.js/, 'bootstrap/dist/js/bootstrap.bundle.min.js', 'application/javascript'],
  [/font-awesome.*all\.min\.css/, '@fortawesome/fontawesome-free/css/all.min.css', 'text/css'],
];
async function konteks(browser, user, lebar, js = true) {
  const context = await browser.newContext({ viewport: { width: lebar, height: lebar < 576 ? 812 : 900 }, javaScriptEnabled: js });
  await context.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.origin === BASE) return route.continue();
    for (const [re, berkas, tipe] of CDN) {
      const jalur = new URL('./node_modules/' + berkas, import.meta.url);
      if (re.test(url.href) && existsSync(jalur)) return route.fulfill({ contentType: tipe, body: readFileSync(jalur) });
    }
    return route.abort();
  });
  const page = await context.newPage();
  page.on('pageerror', (e) => galat.push(`${lebar}: ${e.message}`));
  page.on('dialog', async (d) => { bawaan.push(d.message()); await d.dismiss(); });
  await page.goto(BASE + '/portal/index.php');
  await page.fill('#username', user);
  await page.fill('#password', 'Sandbox#123');
  await Promise.all([page.waitForURL(/\/portal\/index\.php/), page.getByRole('button', { name: 'Masuk', exact: true }).click()]);
  return { context, page };
}
async function idSantri(page, nis) {
  await page.goto(BASE + '/portal/bsa.php?q=' + nis);
  const href = await page.locator('main a[href*="bsa_santri.php?id="]').first().getAttribute('href');
  return Number(new URL(href, BASE).searchParams.get('id'));
}
async function rapi(page, nama, lebar) {
  const masalah = [];
  const lebih = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  if (lebih > 0) masalah.push(`melebar ${lebih}px`);
  if ((await page.locator('main h1').count()) !== 1) masalah.push('h1 bukan satu');
  if ((await page.locator('#ah-shell').count()) !== 1) masalah.push('bukan kerangka bersama');
  if (lebar === 375) for (const t of await page.locator('table.ah-table--cards').all()) {
    if (await t.isVisible() && await t.locator('thead').evaluate((n) => n.getBoundingClientRect().height > 2)) masalah.push('thead tabel kartu tampil');
  }
  for (const b of await page.locator('.ah-actbar .btn').all()) {
    if (await b.isVisible()) { const k = await b.boundingBox(); if (k && k.height < 43.5) masalah.push(`tombol ${Math.round(k.height)}px`); }
  }
  cek(masalah.length === 0, `B-1 ${nama} ${lebar}px rapi`, masalah.join('; '));
}
const teks = (page) => page.locator('main').innerText();

const browser = await chromium.launch();
try {
  const { context: c0, page: p0 } = await konteks(browser, 'sbx_v6_bsa_b', 1280);
  const sSmp = await idSantri(p0, 'V6S0001');
  const sTanpa = await idSantri(p0, 'V6S0003');
  await c0.close();
  for (const lebar of [375, 768, 1280]) {
    const { context, page } = await konteks(browser, 'sbx_v6_bsa_b', lebar);
    await page.goto(BASE + `/portal/bsa_tagihan_buat.php?santri_id=${sSmp}`);
    await rapi(page, 'santri bertarif', lebar);
    await page.screenshot({ path: `${OUT}/tarif-${lebar}.png`, fullPage: true });
    await page.goto(BASE + `/portal/bsa_tagihan_buat.php?santri_id=${sTanpa}`);
    await rapi(page, 'santri tanpa kelas (manual)', lebar);
    await page.screenshot({ path: `${OUT}/manual-${lebar}.png`, fullPage: true });
    await context.close();
  }
  {
    const { context, page } = await konteks(browser, 'sbx_v6_bsa_b', 1280);
    await page.goto(BASE + `/portal/bsa_santri.php?id=${sSmp}`);
    await Promise.all([page.waitForURL(/bsa_tagihan_buat\.php/), page.getByRole('link', { name: 'Terbitkan tagihan' }).click()]);
    const isi = await teks(page);
    cek(/Syahriah[\s\S]*Rp150\.000/.test(isi) && /Potongan anak guru[\s\S]*−Rp50\.000/.test(isi) && /Total tagihan[\s\S]*Rp300\.000/.test(isi) && !isi.includes('Isi komponen manual'),
      'B-2 dari rincian santri: rincian tarif + potongan, total Rp300.000, tanpa isian manual');
    await Promise.all([page.waitForURL(/bsa_tagihan\.php\?id=/), page.getByRole('button', { name: 'Terbitkan tagihan Rp300.000' }).click()]);
    const t = await teks(page);
    cek(t.includes('Tagihan terbit dari tarif') && t.includes('Rp300.000') && t.includes('Syahriah'), 'B-2 Terbitkan → rincian tagihan Rp300.000 dengan komponen tarif');
    await context.close();
  }
  {
    const { context, page } = await konteks(browser, 'sbx_v6_bsa_b', 375, false);
    await page.goto(BASE + `/portal/bsa_tagihan_buat.php?santri_id=${SMP_LAIN}`);
    const tombol = page.getByRole('button', { name: 'Terbitkan tagihan Rp350.000' });
    await tombol.evaluate((el) => el.scrollIntoView({ block: 'center' }));
    await Promise.all([page.waitForURL(/bsa_tagihan\.php\?id=/), tombol.click()]);
    cek((await teks(page)).includes('Rp350.000'), 'B-3 tanpa JavaScript 375px: terbit dari tarif (Rp350.000, tanpa potongan)');
    await context.close();
  }
} finally {
  await browser.close();
}
cek(galat.length === 0, 'B-4 tanpa galat JavaScript', galat.slice(0, 3).join(' | '));
cek(bawaan.length === 0, 'B-4 tanpa dialog bawaan', bawaan.join(' | '));
console.log(`\nRingkasan browser satu santri: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal ? 1 : 0);
