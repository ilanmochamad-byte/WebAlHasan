/**
 * Uji browser Fase 3 penugasan lintas guru/pengurus — penugasan Keamanan (hanya
 * baca), pada 375px, 768px, dan 1280px, dengan dan tanpa JavaScript.
 *
 * Melengkapi uji server `tests/penugasan_keamanan_f3_http.php`. Akun K
 * (`sbx_pengurus_biasa`) memegang Keamanan; akun G (`sbx_guru_biasa`) memegang
 * Keamanan lewat identitas pengurus miliknya dan berpindah ke peran Pengurus.
 *
 *   B-A  menu: grup Keamanan dengan tiga butir; kerangka bersama
 *   B-B  perizinan: daftar, saringan, rincian wali dengan alasan; tanpa formulir POST
 *   B-C  pembinaan: pelanggaran (tautan Rahasia tersembunyi), konseling Internal saja
 *   B-D  kehadiran: seluruh jadwal, Cetak tanpa Ekspor CSV
 *   B-E  G berpindah ke peran Pengurus lalu membuka Keamanan
 *   B-F  tanpa JavaScript (375px): daftar, saringan GET, rincian; tanpa galat JavaScript
 *
 *   PKF3_DB=test_pk_f3_browser_test bash tests/penugasan_keamanan_f3_db.sh
 *   PKF3_DB=test_pk_f3_browser_test php tests/penugasan_keamanan_f3_seed.php > /tmp/pkf3-ids.json
 *   DB_NAME=test_pk_f3_browser_test php -S 127.0.0.1:8983 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8983 SEED_JSON=/tmp/pkf3-ids.json node tests/browser/uji-penugasan-keamanan-f3.mjs
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8983';
const OUT = process.env.OUT_DIR ?? '/tmp/pkf3-browser';
const SANDI = process.env.UJI_SANDI ?? 'Sandbox#123';
const IDS = JSON.parse(readFileSync(process.env.SEED_JSON ?? '/tmp/pkf3-ids.json', 'utf8'));
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
  await Promise.all([page.waitForURL(/\/portal\/index\.php/), page.locator('button[type="submit"]', { hasText: 'Masuk' }).first().click()]);
  return { context, page };
}
async function peran(page, nama) {
  await page.goto(BASE + '/portal/index.php');
  const form = page.locator('form[action$="/portal/peran.php"]').filter({ has: page.locator(`input[name="peran"][value="${nama}"]`) }).first();
  if (await form.count() === 0) return false;
  await Promise.all([page.waitForNavigation(), form.locator('button[type="submit"], button:not([type])').first().dispatchEvent('click')]);
  return true;
}
async function rapi(page, nama, lebar) {
  const masalah = [];
  const lebih = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  if (lebih > 0) masalah.push(`melebar ${lebih}px`);
  if ((await page.locator('#ah-shell').count()) !== 1) masalah.push('bukan kerangka bersama');
  // Tombol keluar dan pemilih peran (akun berperan ganda) adalah bagian kerangka, bukan tindakan Keamanan.
  const post = await page.locator('form[method="post" i]:not([action$="logout.php"]):not([action$="peran.php"])').count();
  if (post > 0) masalah.push(`${post} formulir POST`);
  cek(masalah.length === 0, `${nama} ${lebar}px: kerangka bersama, tanpa formulir POST, tanpa geser horizontal`, masalah.join('; '));
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, 'sbx_pengurus_biasa', lebar);
    // ------------------------------------------------------------- B-A menu
    await page.goto(`${BASE}/portal/index.php`);
    const tautan = ['keamanan_izin.php', 'keamanan_pelanggaran.php', 'admin_laporan_absensi.php'];
    const ada = [];
    for (const t of tautan) ada.push(await page.locator(`a[href$="/${t}"]`).count() > 0);
    cek(ada.every(Boolean), `B-A ${lebar}px: menu memuat Kehadiran, Perizinan, dan Pembinaan Keamanan`);
    // ------------------------------------------------------------- B-B perizinan
    await page.goto(`${BASE}/portal/keamanan_izin.php`);
    cek(await page.locator(`tr[data-id="${IDS.izin_wali}"]`).count() === 1 && await page.getByText('Ekspor CSV').count() === 0,
      `B-B ${lebar}px: daftar izin memuat pengajuan wali; tanpa Ekspor CSV`);
    await rapi(page, 'B-B daftar izin', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/izin-375.png`, fullPage: true });
    await page.goto(`${BASE}/portal/keamanan_izin_detail.php?id=${IDS.izin_wali}`);
    cek((await page.locator('main').innerText()).includes(IDS.alasan_wali), `B-B ${lebar}px: rincian izin wali menampilkan alasan lengkap`);
    await rapi(page, 'B-B rincian izin', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/izin-rincian-375.png`, fullPage: true });
    // ------------------------------------------------------------- B-C pembinaan
    await page.goto(`${BASE}/portal/keamanan_pelanggaran.php`);
    cek(await page.locator(`a[href*="keamanan_pelanggaran_detail.php?id=${IDS.pelanggaran_nonaktif}"]`).count() === 0
      && await page.getByText('Catat pelanggaran').count() === 0, `B-C ${lebar}px: daftar pelanggaran tanpa santri nonaktif dan tanpa Catat`);
    await rapi(page, 'B-C daftar pelanggaran', lebar);
    await page.goto(`${BASE}/portal/keamanan_pelanggaran_detail.php?id=${IDS.pelanggaran_rahasia}`);
    cek(await page.locator(`a[href*="keamanan_konseling_detail.php?id=${IDS.kasus_rahasia}"]`).count() === 0
      && await page.getByText('Tandai mengetahui').count() === 0, `B-C ${lebar}px: tautan kasus Rahasia tidak tampil; tanpa Tandai mengetahui`);
    await rapi(page, 'B-C rincian pelanggaran', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/pelanggaran-rincian-375.png`, fullPage: true });
    await page.goto(`${BASE}/portal/keamanan_konseling.php`);
    cek(await page.locator(`a[href*="keamanan_konseling_detail.php?id=${IDS.kasus_internal}"]`).count() === 1
      && await page.locator(`a[href*="keamanan_konseling_detail.php?id=${IDS.kasus_rahasia}"]`).count() === 0,
      `B-C ${lebar}px: daftar konseling hanya kasus Internal`);
    await rapi(page, 'B-C daftar konseling', lebar);
    await page.goto(`${BASE}/portal/keamanan_konseling_detail.php?id=${IDS.kasus_internal}`);
    await rapi(page, 'B-C rincian konseling', lebar);
    // ------------------------------------------------------------- B-D kehadiran
    await page.goto(`${BASE}/admin/admin_laporan_absensi.php`);
    cek(await page.locator('a[href*="export_laporan_absensi.php"]').count() === 0 && await page.locator('a[href*="laporan_absensi_cetak.php"]').count() === 1,
      `B-D ${lebar}px: kehadiran dengan Cetak, tanpa Ekspor CSV`);
    await rapi(page, 'B-D kehadiran', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/kehadiran-375.png`, fullPage: true });
    await context.close();

    // ------------------------------------------------------------- B-E guru + Keamanan
    const g = await konteks(browser, 'sbx_guru_biasa', lebar);
    cek(await peran(g.page, 'pengurus'), `B-E ${lebar}px: G beralih ke peran Pengurus`);
    await g.page.goto(`${BASE}/portal/keamanan_izin.php`);
    cek(await g.page.locator(`tr[data-id="${IDS.izin_wali}"]`).count() === 1, `B-E ${lebar}px: G membuka daftar izin Keamanan`);
    await rapi(g.page, 'B-E daftar izin G', lebar);
    await g.context.close();
  }

  // ------------------------------------------------------------- B-F tanpa JavaScript
  {
    const { context, page } = await konteks(browser, 'sbx_pengurus_biasa', 375, false);
    await page.goto(`${BASE}/portal/keamanan_izin.php`);
    cek(await page.locator(`tr[data-id="${IDS.izin_wali}"]`).count() === 1, 'B-F 375px tanpa JavaScript: daftar izin tampil');
    await page.selectOption('#f-asal', 'pengurus');
    // Klik DOM (bukan skrip halaman): bilah bawah HP dapat menutupi tombol pada 375px.
    await Promise.all([page.waitForNavigation(), page.locator('form.ah-filter__form button[type="submit"]').first().dispatchEvent('click')]);
    cek(page.url().includes('asal=pengurus') && await page.locator(`tr[data-id="${IDS.izin_wali}"]`).count() === 0,
      'B-F 375px tanpa JavaScript: saringan GET bekerja');
    await page.goto(`${BASE}/portal/keamanan_konseling_detail.php?id=${IDS.kasus_internal}`);
    cek(await page.locator('#ah-kasus').isVisible(), 'B-F 375px tanpa JavaScript: rincian kasus tampil');
    await page.screenshot({ path: `${OUT}/tanpa-js-konseling-375.png`, fullPage: true });
    await context.close();
  }

  cek(galat.length === 0, 'Tanpa galat JavaScript', galat.join(' | '));
  cek(bawaan.length === 0, 'Tanpa confirm()/alert() bawaan', bawaan.join(' | '));
} finally {
  await browser.close();
}
console.log(`\nHasil: ${lulus} lulus, ${gagal} gagal.`);
process.exit(gagal === 0 ? 0 : 1);
