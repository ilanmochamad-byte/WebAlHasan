/**
 * Uji browser Fase 1 penugasan lintas guru/pengurus — identitas pengurus milik
 * guru (PK-1…PK-4), pada 375px, 768px, dan 1280px.
 *
 * Melengkapi uji server `tests/penugasan_keamanan_f1_http.php` dengan perilaku
 * yang hanya terlihat di peramban:
 *
 *   B-A  formulir tambah pembimbing: kelompok Pengurus dan Guru, peringatan
 *        nama sama tampil, rapi di kerangka bersama tanpa geser horizontal;
 *        Murobi tetap khusus guru tanpa kelompok tambahan;
 *   B-B  dengan JavaScript (1280px): memilih guru dari kelompok Guru, dialog
 *        konfirmasi kerangka (bukan confirm() bawaan), penugasan tersimpan;
 *   B-C  tanpa JavaScript (375px): menugaskan guru tanpa akun sebagai petugas
 *        kesehatan tersimpan sekali;
 *   B-D  Data Pengurus menandai identitas "Guru" + "Buka data guru"; Data Guru
 *        menampilkan "Penugasan sebagai pengurus";
 *   B-E  akun guru beralih ke peran Pengurus lewat pil Peran dan membuka
 *        daftar santri binaan; tanpa galat JavaScript.
 *
 *   PKF1_DB=test_pk_f1_browser_test bash tests/penugasan_keamanan_f1_db.sh
 *   DB_NAME=test_pk_f1_browser_test php -S 127.0.0.1:8973 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8973 node tests/browser/uji-penugasan-keamanan-f1.mjs
 *
 * Uji ini MENAMBAH penugasan pada DB uji. Bangun ulang DB sebelum mengulang.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8973';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-penugasan-keamanan-f1', import.meta.url).pathname;
const SANDI = process.env.UJI_SANDI ?? 'Sandbox#123';
const GURU = process.env.UJI_GURU ?? 'SBX Guru Tanpa Murobi';
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
  const tanpaLabel = await page.evaluate(() => [...document.querySelectorAll('main select, main input:not([type=hidden]), main textarea')]
    .filter((e) => e.offsetParent !== null && !e.labels?.length && !e.getAttribute('aria-label')).map((e) => e.id || e.name));
  if (tanpaLabel.length) masalah.push('tanpa label: ' + tanpaLabel.join(','));
  cek(masalah.length === 0, `${nama} ${lebar}px: rapi di kerangka bersama`, masalah.join('; '));
}
const nilaiOpsi = async (page, pemilih, teks) => page.locator(`${pemilih} option`, { hasText: teks }).first().getAttribute('value');
// Halaman formulir sudah berisi `jenis=…&tambah=1`; tunggu pengalihan ke daftar tanpa `tambah`.
const keDaftar = (jenis) => (u) => u.pathname.endsWith('/admin_penugasan.php') && u.searchParams.get('jenis') === jenis && !u.searchParams.has('tambah');
const keDasar = (page) => page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  // ------------------------------------------------------------- B-A formulir
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, 'sbx_admin', lebar);
    await page.goto(BASE + '/admin/admin_penugasan.php?jenis=pembimbing&tambah=1');
    const grup = await page.locator('#pengurus_id optgroup').evaluateAll((n) => n.map((g) => g.label));
    const guruOpsi = await page.locator('#pengurus_id optgroup[label="Guru"] option').count();
    cek(grup.join('|') === 'Pengurus|Guru' && guruOpsi > 0 && (await nilaiOpsi(page, '#pengurus_id', GURU))?.startsWith('guru:'),
      `B-A ${lebar}px: pemilih memuat kelompok Pengurus dan Guru (nilai guru:ID)`, grup.join('|'));
    const peringatan = page.locator('#subjek-nama-kembar');
    cek(await peringatan.isVisible() && (await peringatan.innerText()).includes('SBX V5 Ganda')
      && (await page.locator('#pengurus_id option', { hasText: 'nama sama dengan pengurus' }).count()) >= 1,
      `B-A ${lebar}px: guru bernama sama dengan pengurus ditandai dan peringatan terlihat`);
    await periksaLayar(page, 'B-A formulir pembimbing', lebar);
    await page.screenshot({ path: `${OUT}/formulir-pembimbing-${lebar}.png`, fullPage: true });
    await page.goto(BASE + '/admin/admin_penugasan.php?jenis=murobi&tambah=1');
    cek((await page.locator('main optgroup').count()) === 0 && (await page.locator('#subjek-nama-kembar').count()) === 0
      && (await page.locator('main option[value^="guru:"]').count()) === 0, `B-A ${lebar}px: Murobi tetap khusus guru tanpa kelompok tambahan`);
    await context.close();
  }

  // ------------------------------------------------------------- B-B dengan JavaScript
  {
    const { context, page } = await konteks(browser, 'sbx_admin', 1280);
    await page.goto(BASE + '/admin/admin_penugasan.php?jenis=pembimbing&tambah=1');
    await page.selectOption('#pengurus_id', await nilaiOpsi(page, '#pengurus_id', GURU));
    await page.selectOption('#target_type', 'Kamar');
    await page.selectOption('#kamar_id', await nilaiOpsi(page, '#kamar_id', 'SBX Kamar A'));
    await keDasar(page);
    await page.getByRole('button', { name: 'Simpan penugasan', exact: true }).click();
    const dialog = page.locator('dialog[open]').first();
    await dialog.waitFor({ state: 'visible' });
    cek((await dialog.innerText()).includes('Penugasan tidak membuat akun'), 'B-B dialog konfirmasi kerangka tampil sebelum menyimpan');
    await Promise.all([page.waitForURL(keDaftar('pembimbing')), dialog.locator('[data-ah-ok]').click()]);
    await page.waitForLoadState('networkidle');
    const main = await page.locator('main').innerText();
    cek(main.includes('tersimpan') && main.includes(GURU), 'B-B 1280px: guru dari kelompok Guru tersimpan sebagai pembimbing');
    await page.screenshot({ path: `${OUT}/daftar-pembimbing-1280.png`, fullPage: true });
    await context.close();
  }

  // ------------------------------------------------------------- B-C tanpa JavaScript
  {
    const { context, page } = await konteks(browser, 'sbx_admin', 375, false);
    await page.goto(BASE + '/admin/admin_penugasan.php?jenis=kesehatan&tambah=1');
    const guruTanpaAkun = await page.locator('#pengurus_id optgroup[label="Guru"] option')
      .filter({ hasNotText: GURU }).filter({ hasNotText: 'nama sama dengan pengurus' }).first();
    const namaTanpaAkun = (await guruTanpaAkun.innerText()).replace(/ — NIP .*| \(guru\).*/, '').trim();
    await page.selectOption('#pengurus_id', await guruTanpaAkun.getAttribute('value'));
    await Promise.all([page.waitForURL(keDaftar('kesehatan')), page.getByRole('button', { name: 'Simpan penugasan', exact: true }).click()]);
    const main = await page.locator('main').innerText();
    const kali = main.split(namaTanpaAkun).length - 1;
    cek(main.includes('tersimpan') && kali >= 1, `B-C 375px tanpa JavaScript: guru (${namaTanpaAkun}) tersimpan sebagai petugas kesehatan`, `muncul ${kali}x`);
    await page.screenshot({ path: `${OUT}/kesehatan-tanpa-js-375.png`, fullPage: true });
    await context.close();
  }

  // ------------------------------------------------------------- B-D Data Pengurus dan Data Guru
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, 'sbx_admin', lebar);
    await page.goto(BASE + '/admin/admin_pengurus.php?state=active&q=' + encodeURIComponent(GURU));
    const baris = page.locator('main tr', { hasText: GURU }).first();
    cek(await baris.locator('.ah-badge', { hasText: 'Guru' }).count() === 1, `B-D ${lebar}px: Data Pengurus menandai identitas milik guru`);
    await periksaLayar(page, 'B-D Data Pengurus', lebar);
    await Promise.all([page.waitForURL(/admin_pengurus\.php\?.*action=detail/), baris.locator('a.ah-row-link').click()]);
    const tautan = page.getByRole('link', { name: 'Buka data guru' }).first();
    cek(await tautan.isVisible(), `B-D ${lebar}px: rincian identitas menautkan ke data guru`);
    await Promise.all([page.waitForURL(/admin_guru\.php\?action=detail/), tautan.click()]);
    cek((await page.locator('main').innerText()).includes('Penugasan sebagai pengurus'), `B-D ${lebar}px: Data Guru menampilkan penugasan sebagai pengurus`);
    await periksaLayar(page, 'B-D Data Guru', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/data-guru-375.png`, fullPage: true });
    await context.close();
  }

  // ------------------------------------------------------------- B-E guru beralih peran
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, 'sbx_guru_biasa', lebar);
    const formPengurus = page.locator('form[action$="/portal/peran.php"]').filter({ has: page.locator('input[name="peran"][value="pengurus"]') }).first();
    cek(await formPengurus.count() === 1, `B-E ${lebar}px: akun guru menawarkan peran Pengurus`);
    await formPengurus.evaluate((f) => f.requestSubmit());
    await page.waitForLoadState('networkidle');
    await page.goto(BASE + '/portal/santri_binaan.php');
    const ada = await page.locator('main a[href*="santri_binaan.php?id="]').count();
    cek(ada > 0, `B-E ${lebar}px: sebagai Pengurus, daftar santri binaan berisi santri kamar binaan`);
    await periksaLayar(page, 'B-E santri binaan guru', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/santri-binaan-guru-375.png`, fullPage: true });
    await context.close();
  }

  cek(galat.length === 0, 'Tanpa galat JavaScript', galat.join(' | '));
  cek(bawaan.length === 0, 'Tanpa confirm()/alert() bawaan', bawaan.join(' | '));
} finally {
  await browser.close();
}
console.log(`\nHasil: ${lulus} lulus, ${gagal} gagal.`);
process.exit(gagal === 0 ? 0 : 1);
