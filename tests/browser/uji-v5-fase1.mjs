/**
 * Uji browser PRD V5 Fase 1 — kesehatan santri (daftar, formulir, detail).
 *
 * Melengkapi uji server `tests/v5_phase1_http.php` dengan perilaku yang hanya
 * terlihat di peramban, pada 375px, 768px, dan 1280px:
 *
 *   B-A  daftar/formulir/detail petugas dan detail + riwayat akses admin:
 *        tanpa geser horizontal, satu h1, kerangka bersama, tabel menjadi
 *        kartu di HP, tombol utama ≥ 44 px, tanpa isian ID ketik tangan;
 *   B-B  dari HP (375px): satu kunjungan dikirim lewat formulir (teks panjang
 *        tanpa spasi tidak melebarkan halaman) lalu dibaca ulang di detail;
 *   B-C  klik ganda tombol Simpan → tetap satu kunjungan;
 *   B-D  tanpa JavaScript (375px): formulir tetap mengirim satu kunjungan;
 *   B-E  admin tidak melihat tombol/tautan pencatatan; tanpa galat
 *        JavaScript dan tanpa confirm()/alert() bawaan.
 *
 *   V5F1_DB=test_v5_f1_browser_test bash tests/v5_phase1_db.sh
 *   DB_NAME=test_v5_f1_browser_test php -S 127.0.0.1:8996 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8996 node tests/browser/uji-v5-fase1.mjs
 *
 * Uji ini MENAMBAH kunjungan pada DB uji. Bangun ulang DB sebelum mengulang.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8996';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-v5-fase1', import.meta.url).pathname;
const SANDI = process.env.UJI_SANDI ?? 'Sandbox#123';
const LEBAR = [375, 768, 1280];
mkdirSync(OUT, { recursive: true });
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE)) throw new Error('Uji hanya untuk server lokal 127.0.0.1');

let lulus = 0;
let gagal = 0;
const galat = [];
const bawaan = [];
function cek(ok, label, ket = '') {
  ok ? lulus++ : gagal++;
  console.log(`${ok ? '[lulus]' : '[gagal]'} ${label}${!ok && ket ? ' — ' + ket : ''}`);
}
const CDN = [
  [/bootstrap[^/]*\/dist\/css\/bootstrap\.min\.css/, 'bootstrap/dist/css/bootstrap.min.css', 'text/css'],
  [/bootstrap[^/]*\/dist\/js\/bootstrap\.bundle\.min\.js/, 'bootstrap/dist/js/bootstrap.bundle.min.js', 'application/javascript'],
  [/font-awesome.*all\.min\.css/, '@fortawesome/fontawesome-free/css/all.min.css', 'text/css'],
];
async function siapkan(context) {
  await context.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.origin === BASE) return route.continue();
    for (const [re, berkas, tipe] of CDN) {
      const jalur = new URL('./node_modules/' + berkas, import.meta.url);
      if (re.test(url.href) && existsSync(jalur)) return route.fulfill({ contentType: tipe, body: readFileSync(jalur) });
    }
    if (/\/webfonts\/fa-[\w-]+\.woff2$/.test(url.pathname)) {
      const jalur = new URL('./node_modules/@fortawesome/fontawesome-free/webfonts/' + url.pathname.split('/').pop(), import.meta.url);
      if (existsSync(jalur)) return route.fulfill({ contentType: 'font/woff2', body: readFileSync(jalur) });
    }
    return route.abort();
  });
}
async function konteks(browser, user, lebar = 1280, js = true) {
  const context = await browser.newContext({ viewport: { width: lebar, height: lebar < 576 ? 812 : 900 }, javaScriptEnabled: js });
  await siapkan(context);
  const page = await context.newPage();
  page.on('pageerror', (e) => galat.push(`${user} ${lebar}: ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error' && !/net::ERR_|Failed to load resource/.test(m.text())) galat.push(`${user} ${lebar}: ${m.text()}`); });
  page.on('dialog', async (d) => { bawaan.push(d.type() + ': ' + d.message()); await d.dismiss(); });
  await page.goto(BASE + '/portal/index.php');
  await page.fill('#username', user);
  await page.fill('#password', SANDI);
  await Promise.all([page.waitForURL(/\/portal\/index\.php/), page.getByRole('button', { name: 'Masuk', exact: true }).click()]);
  return { context, page };
}
const melebar = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const jumlahKunjungan = async (page) => {
  await page.goto(BASE + '/portal/kesehatan.php');
  return page.locator('main a[href*="kesehatan_detail.php?id="]').count();
};

async function periksaLayar(page, nama, lebar) {
  const masalah = [];
  const lebih = await melebar(page);
  if (lebih > 0) masalah.push(`melebar ${lebih}px`);
  if ((await page.locator('main h1').count()) !== 1) masalah.push('h1 bukan satu');
  if ((await page.locator('#ah-shell').count()) !== 1) masalah.push('bukan kerangka bersama');
  if (lebar === 375) {
    for (const tabel of await page.locator('table.ah-table--cards').all()) {
      if (await tabel.isVisible() && await tabel.locator('thead').evaluate((n) => n.getBoundingClientRect().height > 2)) masalah.push('thead tabel kartu masih tampil');
    }
  }
  for (const tombol of await page.locator('.ah-page-head__actions .btn, .ah-actbar .btn').all()) {
    if (await tombol.isVisible()) {
      const kotak = await tombol.boundingBox();
      if (kotak && kotak.height < 43.5) masalah.push(`tombol ${Math.round(kotak.height)}px`);
    }
  }
  const isianId = await page.locator('main input[name$="_id"]:not([type="hidden"]):not([type="checkbox"])').count();
  if (isianId > 0) masalah.push(`${isianId} isian ID`);
  cek(masalah.length === 0, `B-A ${nama} ${lebar}px: rapi di kerangka bersama`, [...new Set(masalah)].join('; '));
}

async function isiFormulir(page, penanda) {
  await page.goto(BASE + '/portal/kesehatan_buat.php?q=V5S0001');
  const nilai = await page.locator('#santri_id option').evaluateAll((os) => (os.find((o) => /V5S0001/.test(o.textContent)) || {}).value);
  await page.selectOption('#santri_id', nilai);
  await page.fill('#keluhan', `${penanda} keluhan ` + 'x'.repeat(240));
  await page.fill('#catatan_klinis', `${penanda}-catatan-panjang-tanpa-spasi-` + 'y'.repeat(300));
  await page.fill('#tindakan', `${penanda} tindakan kompres`);
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  // ------------------------------------------------------------- B-B, B-A
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, 'sbx_v5_petugas_b', lebar);
    const sebelum = await jumlahKunjungan(page);
    await periksaLayar(page, 'daftar kunjungan', lebar);
    await page.goto(BASE + '/portal/kesehatan_buat.php');
    await periksaLayar(page, 'formulir kunjungan', lebar);
    const penanda = `B${lebar}-${Date.now()}`;
    await isiFormulir(page, penanda);
    await periksaLayar(page, 'formulir terisi', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/formulir-375.png`, fullPage: true });
    await Promise.all([page.waitForURL(/kesehatan_detail\.php\?id=\d+/), page.getByRole('button', { name: 'Simpan kunjungan' }).click()]);
    const main = await page.locator('main').innerText();
    cek(main.includes(`${penanda} tindakan kompres`) && main.includes(`${penanda}-catatan-panjang`), `B-B ${lebar}px: kunjungan dikirim lalu dibaca ulang di detail`);
    await periksaLayar(page, 'detail kunjungan', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/detail-375.png`, fullPage: true });
    cek(await jumlahKunjungan(page) === sebelum + 1, `B-B ${lebar}px: tepat satu kunjungan baru di daftar`);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/daftar-375.png`, fullPage: true });

    // B-C klik ganda
    if (lebar === 375) {
      const awal = await jumlahKunjungan(page);
      await isiFormulir(page, `GANDA-${Date.now()}`);
      await Promise.all([page.waitForURL(/kesehatan_detail\.php\?id=\d+/), page.getByRole('button', { name: 'Simpan kunjungan' }).dblclick()]);
      await page.waitForLoadState('networkidle');
      cek(await jumlahKunjungan(page) === awal + 1, 'B-C 375px: klik ganda Simpan tetap menghasilkan satu kunjungan');
    }
    await context.close();
  }

  // ------------------------------------------------------------- B-D
  {
    const { context, page } = await konteks(browser, 'sbx_v5_petugas_b', 375, false);
    const awal = await jumlahKunjungan(page);
    await isiFormulir(page, `TANPAJS-${Date.now()}`);
    await Promise.all([page.waitForURL(/kesehatan_detail\.php\?id=\d+/), page.getByRole('button', { name: 'Simpan kunjungan' }).click()]);
    cek((await page.locator('main').innerText()).includes('tindakan kompres') && await melebar(page) <= 0, 'B-D 375px tanpa JavaScript: kunjungan tersimpan dan detail rapi');
    cek(await jumlahKunjungan(page) === awal + 1, 'B-D 375px tanpa JavaScript: tepat satu kunjungan baru');
    await context.close();
  }

  // ------------------------------------------------------------- B-E admin
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, 'sbx_admin', lebar);
    await page.goto(BASE + '/portal/kesehatan.php');
    await periksaLayar(page, 'daftar admin', lebar);
    cek(await page.locator('main a[href*="kesehatan_buat.php"]').count() === 0, `B-E ${lebar}px: admin tanpa tombol/tautan Catat kunjungan`);
    const tautan = page.locator('main a[href*="kesehatan_detail.php?id="]').first();
    await Promise.all([page.waitForURL(/kesehatan_detail\.php/), tautan.click()]);
    await periksaLayar(page, 'detail + riwayat akses admin', lebar);
    cek((await page.locator('main').innerText()).includes('Riwayat akses'), `B-E ${lebar}px: admin melihat riwayat akses`);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/detail-admin-375.png`, fullPage: true });
    await context.close();
  }
} finally {
  await browser.close();
}
cek(galat.length === 0, 'B-E tanpa galat JavaScript', galat.join(' | '));
cek(bawaan.length === 0, 'B-E tanpa confirm()/alert() bawaan', bawaan.join(' | '));
console.log(`\nLulus: ${lulus} · Gagal: ${gagal}`);
process.exit(gagal === 0 ? 0 : 1);
