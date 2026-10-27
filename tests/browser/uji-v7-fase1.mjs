/**
 * Uji browser PRD V7 Fase 1 — pengajuan izin wali dan routing pembimbing.
 *
 * Melengkapi uji server `tests/v7_phase1_http.php` dengan perilaku yang hanya
 * terlihat di peramban, pada 375px, 768px, dan 1280px:
 *
 *   B-A  wali pengaju: daftar, formulir, kirim, rincian — tanpa geser
 *        horizontal, satu h1, kerangka bersama, tombol utama ≥ 44 px, tanpa
 *        isian ID ketik tangan;
 *   B-B  wali kedua: rincian ringkas tanpa alasan dan tanpa formulir;
 *   B-C  klik ganda tombol Kirim → tetap satu pengajuan;
 *   B-D  tanpa JavaScript (375px): pengajuan wali tersimpan sekali;
 *   B-E  pembimbing (antrean/rincian) dan admin (antrean, rincian, penetapan
 *        pembimbing lewat formulir); murobi tanpa akses; tanpa galat
 *        JavaScript dan tanpa confirm()/alert() bawaan.
 *
 *   V7F1_DB=test_v7_f1_browser_test bash tests/v7_phase1_db.sh
 *   DB_NAME=test_v7_f1_browser_test php -S 127.0.0.1:8999 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8999 node tests/browser/uji-v7-fase1.mjs
 *
 * Uji ini MENAMBAH pengajuan pada DB uji. Bangun ulang DB sebelum mengulang.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8999';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-v7-fase1', import.meta.url).pathname;
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
async function periksaLayar(page, nama, lebar) {
  const masalah = [];
  const lebih = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
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
const keDasar = (page) => page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
async function ajukan(page, santri, dari, sampai, alasan, aksi = 'click') {
  await page.goto(BASE + '/portal/izin_wali_buat.php');
  await page.selectOption('#santri_id', await page.locator('#santri_id option', { hasText: santri }).first().getAttribute('value'));
  await page.fill('#tgl_izin', dari);
  await page.fill('#tgl_kembali', sampai);
  await page.fill('#alasan', alasan);
  const tombol = page.getByRole('button', { name: 'Kirim pengajuan' });
  await keDasar(page);
  await Promise.all([page.waitForURL(/izin_wali_detail\.php\?id=\d+/), aksi === 'dblclick' ? tombol.dblclick() : tombol.click()]);
  await page.waitForLoadState('networkidle');
  return Number(new URL(page.url()).searchParams.get('id'));
}
const jumlahKartu = async (page, nama) => page.locator('main .ah-icard', { hasText: nama }).count();

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
let idPertama = 0;
try {
  // ------------------------------------------------------------- B-A wali pengaju
  const rentang = { 375: ['2026-11-02', '2026-11-03'], 768: ['2026-11-10', '2026-11-11'], 1280: ['2026-11-20', '2026-11-21'] };
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, 'sbx_v7_wali_a', lebar);
    await page.goto(BASE + '/portal/izin_wali.php');
    await periksaLayar(page, 'daftar pengajuan wali', lebar);
    await page.goto(BASE + '/portal/izin_wali_buat.php');
    await periksaLayar(page, 'formulir pengajuan wali', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/formulir-wali-375.png`, fullPage: true });
    const id = await ajukan(page, 'SBX V7 Santri Satu', ...rentang[lebar], `Acara keluarga browser ${lebar}`);
    if (!idPertama) idPertama = id;
    const main = await page.locator('main').innerText();
    cek(id > 0 && main.includes('Menunggu tinjauan pembimbing') && main.includes(`Acara keluarga browser ${lebar}`), `B-A ${lebar}px: pengajuan terkirim, rincian memuat tahap dan alasan`);
    await periksaLayar(page, 'rincian pengajuan wali', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/rincian-wali-375.png`, fullPage: true });
    await context.close();
  }

  // ------------------------------------------------------------- B-B wali kedua
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, 'sbx_v7_wali_b', lebar);
    await page.goto(BASE + '/portal/izin_wali_detail.php?id=' + idPertama);
    const main = await page.locator('main').innerText();
    cek(!main.includes('Acara keluarga browser') && main.includes('dibuat wali lain') && (await page.locator('main form').count()) === 0,
      `B-B ${lebar}px: wali kedua melihat status ringkas tanpa alasan dan tanpa formulir`);
    await periksaLayar(page, 'rincian ringkas wali kedua', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/rincian-wali-kedua-375.png`, fullPage: true });
    await context.close();
  }

  // ------------------------------------------------------------- B-C klik ganda
  {
    const { context, page } = await konteks(browser, 'sbx_v7_wali_a', 1280);
    await ajukan(page, 'SBX V7 Santri Dua', '2026-11-05', '2026-11-06', 'Klik ganda browser', 'dblclick');
    await page.goto(BASE + '/portal/izin_wali.php');
    cek(await jumlahKartu(page, 'SBX V7 Santri Dua') === 1, 'B-C klik ganda Kirim pengajuan → tetap satu pengajuan');
    await context.close();
  }

  // ------------------------------------------------------------- B-D tanpa JavaScript
  {
    const { context, page } = await konteks(browser, 'sbx_v7_wali_a', 375, false);
    const id = await ajukan(page, 'SBX V7 Santri Nol', '2026-11-07', '2026-11-07', 'Tanpa JavaScript');
    await page.goto(BASE + '/portal/izin_wali.php');
    cek(id > 0 && await jumlahKartu(page, 'SBX V7 Santri Nol') === 1, 'B-D 375px tanpa JavaScript: pengajuan tersimpan sekali');
    await context.close();
  }

  // ------------------------------------------------------------- B-E pembimbing, admin, murobi
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, 'sbx_v7_pembimbing_satu', lebar);
    await page.goto(BASE + '/portal/izin_wali.php');
    await periksaLayar(page, 'antrean pembimbing', lebar);
    const main = await page.locator('main').innerText();
    cek(main.includes('SBX V7 Santri Satu') && !main.includes('SBX V7 Santri Dua'), `B-E ${lebar}px: antrean pembimbing hanya pengajuan yang dialamatkan kepadanya`);
    await page.goto(BASE + '/portal/izin_wali_detail.php?id=' + idPertama);
    await periksaLayar(page, 'rincian pembimbing', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/antrean-pembimbing-375.png`, fullPage: true });
    await context.close();
  }
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, 'sbx_admin', lebar);
    await page.goto(BASE + '/portal/izin_wali.php');
    await periksaLayar(page, 'antrean admin', lebar);
    const baris = page.locator('main a[href*="izin_wali_detail.php?id="]', { hasText: 'SBX V7 Santri Dua' }).first();
    await Promise.all([page.waitForURL(/izin_wali_detail\.php/), baris.click()]);
    await periksaLayar(page, 'rincian admin', lebar);
    if (lebar === 1280) {
      await page.selectOption('#pengurus_id', await page.locator('#pengurus_id option', { hasText: 'SBX V7 Pembimbing Dua' }).first().getAttribute('value'));
      await page.fill('#alasan_penetapan', 'Pembimbing kelas ditetapkan lewat browser');
      await Promise.all([page.waitForURL(/izin_wali_detail\.php\?id=\d+$/), page.getByRole('button', { name: 'Tetapkan pembimbing' }).click()]);
      const main = await page.locator('main').innerText();
      cek(main.includes('Pembimbing ditetapkan') && main.includes('Riwayat penetapan pembimbing') && main.includes('Menunggu tinjauan pembimbing'),
        'B-E 1280px: admin menetapkan pembimbing lewat formulir; riwayat penetapan tampil');
      await page.screenshot({ path: `${OUT}/penetapan-admin-1280.png`, fullPage: true });
    }
    if (lebar === 375) await page.screenshot({ path: `${OUT}/rincian-admin-375.png`, fullPage: true });
    await context.close();
  }
  {
    const { context, page } = await konteks(browser, 'sbx_murobi_a', 1280);
    const r = await page.goto(BASE + '/portal/izin_wali.php');
    const d = await page.goto(BASE + '/portal/izin_wali_detail.php?id=' + idPertama);
    cek(r.status() === 403 && d.status() === 404, 'B-E murobi: halaman pengajuan wali 403, rincian 404');
    await context.close();
  }
} finally {
  await browser.close();
}
cek(galat.length === 0, 'B-E tanpa galat JavaScript', galat.join(' | '));
cek(bawaan.length === 0, 'B-E tanpa confirm()/alert() bawaan', bawaan.join(' | '));
console.log(`\nLulus: ${lulus} · Gagal: ${gagal}`);
process.exit(gagal === 0 ? 0 : 1);
