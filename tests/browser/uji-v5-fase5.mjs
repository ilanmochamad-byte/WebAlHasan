/**
 * Uji browser PRD V5 Fase 5 — laporan layanan klinik.
 *
 * Melengkapi uji server `tests/v5_phase5_http.php` dengan perilaku yang hanya
 * terlihat di peramban, pada 375px, 768px, dan 1280px:
 *
 *   G-A  petugas, admin, pembimbing, murobi: halaman laporan rapi di kerangka
 *        bersama (tanpa geser horizontal, satu h1, tombol ≥ 44 px), tabel
 *        tertunda/per kelas tidak melebar; bagian pengawasan hanya admin;
 *   G-B  saringan periode dan kelas diterapkan dari formulir; kartu angka
 *        menaut ke daftar kunjungan dengan jumlah sama; baris tertunda
 *        membuka detail santri dalam cakupan;
 *   G-C  tanpa JavaScript (375px): saringan, angka, dan tautan berfungsi;
 *   G-D  wali dan pengurus tanpa penugasan ditolak; tombol "Laporan" pada
 *        daftar kunjungan; tanpa galat JavaScript dan tanpa dialog bawaan;
 *        teks halaman tanpa penanda isi klinis.
 *
 *   V5F5_DB=test_v5_f5_browser_test bash tests/v5_phase5_db.sh
 *   DB_NAME=test_v5_f5_browser_test php -S 127.0.0.1:8997 tests/redesain_web_tahap1_router.php &
 *   (cd tests/browser && BASE_URL=http://127.0.0.1:8997 OUT_DIR=../../docs/phase-v5-5/tangkapan node uji-v5-fase5.mjs)
 *
 * Hanya membaca; DB uji tidak diubah.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8997';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-v5-fase5', import.meta.url).pathname;
const SANDI = process.env.UJI_SANDI ?? 'Sandbox#123';
const LEBAR = [375, 768, 1280];
const PENANDA = 'V5F5RHS';
const SEP = '?dari=2026-09-01&sampai=2026-09-30';
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
  page.on('console', (m) => { if (m.type() === 'error' && !/net::ERR_|Failed to load resource|403/.test(m.text())) galat.push(`${user} ${lebar}: ${m.text()}`); });
  page.on('dialog', async (d) => { bawaan.push(d.type() + ': ' + d.message()); await d.dismiss(); });
  await page.goto(BASE + '/portal/index.php');
  await page.fill('#username', user);
  await page.fill('#password', SANDI);
  await Promise.all([page.waitForURL(/\/portal\/index\.php/), page.getByRole('button', { name: 'Masuk', exact: true }).click()]);
  return { context, page };
}
const melebar = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
async function periksaLayar(page, nama, lebar) {
  const masalah = [];
  const lebih = await melebar(page);
  if (lebih > 0) masalah.push(`melebar ${lebih}px`);
  if ((await page.locator('main h1').count()) !== 1) masalah.push('h1 bukan satu');
  if ((await page.locator('#ah-shell').count()) !== 1) masalah.push('bukan kerangka bersama');
  for (const tombol of await page.locator('main form .btn-primary, .ah-page-head__actions .btn').all()) {
    if (await tombol.isVisible()) {
      const kotak = await tombol.boundingBox();
      if (kotak && kotak.height < 43.5) masalah.push(`tombol ${Math.round(kotak.height)}px`);
    }
  }
  for (const wrap of await page.locator('main .ah-table-wrap').all()) {
    const w = await wrap.evaluate((el) => el.scrollWidth - el.clientWidth);
    if (w > 1) masalah.push(`tabel melebar ${w}px`);
  }
  cek(masalah.length === 0, `${nama} ${lebar}px: rapi di kerangka bersama`, [...new Set(masalah)].join('; '));
}
/** Label → angka kartu. */
const kartu = (page) => page.locator('.ah-stat').evaluateAll((els) => Object.fromEntries(els.map((e) => [
  e.querySelector('.ah-stat__label')?.textContent.trim(), Number((e.querySelector('.ah-stat__value')?.textContent ?? '').replace(/\./g, '')),
])));
const totalDaftar = async (page) => Number(((await page.locator('.ah-card__head .text-muted.small').first().textContent()) ?? '').replace(/\D/g, '') || -1);

const HARAP = { sbx_v5_petugas_f: 6, sbx_admin: 6, sbx_v5_pembimbing: 3, sbx_v5_murobi: 3 };
const browser = await chromium.launch();
try {
  // ----------------------------------------------------------------- G-A
  for (const lebar of LEBAR) {
    for (const [user, total] of Object.entries(HARAP)) {
      const { context, page } = await konteks(browser, user, lebar);
      await page.goto(BASE + '/portal/kesehatan_laporan.php' + SEP);
      const k = await kartu(page);
      const teks = await page.locator('main').innerText();
      cek(k['Kunjungan'] === total && (user.includes('pembimbing') || user.includes('murobi') ? !('Draf' in k) : k['Draf'] === 1),
        `G-A ${lebar}px ${user}: ${k['Kunjungan']} kunjungan (harap ${total}); kartu Draf ${'Draf' in k ? 'tampil' : 'disembunyikan'}`);
      cek(teks.includes('Pengawasan admin') === (user === 'sbx_admin'), `G-A ${lebar}px ${user}: pengawasan ${user === 'sbx_admin' ? 'tampil' : 'tidak tampil'}`);
      cek(!teks.includes(PENANDA) && !(await page.content()).includes(PENANDA), `G-A ${lebar}px ${user}: tanpa penanda isi klinis`);
      await periksaLayar(page, `laporan ${user}`, lebar);
      if (lebar !== 768) await page.screenshot({ path: `${OUT}/laporan-${user.replace('sbx_', '')}-${lebar}.png`, fullPage: true });
      await context.close();
    }
  }

  // ----------------------------------------------------------------- G-B
  {
    const { context, page } = await konteks(browser, 'sbx_v5_petugas_f', 375);
    await page.goto(BASE + '/portal/kesehatan.php');
    const tombolLaporan = page.locator('.ah-page-head__actions a', { hasText: 'Laporan' });
    cek(await tombolLaporan.count() === 1, 'G-B daftar kunjungan memuat tombol Laporan');
    await Promise.all([page.waitForURL(/kesehatan_laporan\.php/), tombolLaporan.click()]);
    // Saringan di HP terlipat bila tidak ada saringan aktif; buka bila perlu.
    if (!(await page.locator('#dari').isVisible())) await page.locator('details.ah-filter > summary').click();
    await page.fill('#dari', '2026-09-01');
    await page.fill('#sampai', '2026-09-30');
    const kelasY = await page.locator('#kelas_id option', { hasText: 'SBX V5 Kelas Y' }).getAttribute('value');
    await page.selectOption('#kelas_id', kelasY);
    await Promise.all([page.waitForURL(/kelas_id=/), page.getByRole('button', { name: 'Terapkan' }).click()]);
    const k = await kartu(page);
    cek(k['Kunjungan'] === 2 && k['Draf'] === 1 && new URL(page.url()).searchParams.get('dari') === '2026-09-01', 'G-B saringan periode + kelas Y dari formulir: 2 kunjungan (1 draf)');
    await page.goto(BASE + '/portal/kesehatan_laporan.php' + SEP);
    await Promise.all([page.waitForURL(/kesehatan\.php\?/), page.locator('a.ah-stat', { hasText: 'Menunggu hasil' }).click()]);
    cek(await totalDaftar(page) === 2, 'G-B kartu "Menunggu hasil" → daftar 2 kunjungan (sama dengan laporan)');
    await context.close();
  }
  {
    const { context, page } = await konteks(browser, 'sbx_v5_pembimbing', 768);
    await page.goto(BASE + '/portal/kesehatan_laporan.php' + SEP);
    await Promise.all([page.waitForURL(/kesehatan\.php\?/), page.locator('a.ah-stat', { hasText: /^Kunjungan/ }).first().click()]);
    cek(await totalDaftar(page) === 3, 'G-B pembimbing: kartu Kunjungan → daftar 3 kunjungan');
    await page.goto(BASE + '/portal/kesehatan_laporan.php' + SEP);
    const baris = page.locator('#ah-tertunda-tl ~ * a.ah-row-link, section[aria-labelledby="ah-tertunda-tl"] a.ah-row-link').first();
    await Promise.all([page.waitForURL(/kesehatan_detail\.php\?id=\d+/), baris.click()]);
    const detail = await page.locator('main').innerText();
    cek(detail.includes('SBX V5 Lapor A'), 'G-B pembimbing: baris tindak lanjut tertunda membuka detail santri binaan');
    await context.close();
  }

  // ----------------------------------------------------------------- G-C
  {
    const { context, page } = await konteks(browser, 'sbx_v5_petugas_f', 375, false);
    await page.goto(BASE + '/portal/kesehatan_laporan.php');
    await page.fill('#dari', '2026-09-01');
    await page.fill('#sampai', '2026-09-30');
    await Promise.all([page.waitForURL(/dari=2026-09-01/), page.getByRole('button', { name: 'Terapkan' }).click()]);
    const k = await kartu(page);
    cek(k['Kunjungan'] === 6 && k['Rujukan'] === 4 && k['Tindak lanjut terbuka'] === 4, 'G-C 375px tanpa JavaScript: saringan periode diterapkan (6/4/4)');
    await Promise.all([page.waitForURL(/kesehatan\.php\?/), page.locator('a.ah-stat', { hasText: 'Tindak lanjut terbuka' }).click()]);
    cek(await totalDaftar(page) === 4, 'G-C tanpa JavaScript: kartu "Tindak lanjut terbuka" → daftar 4 kunjungan');
    await periksaLayar(page, 'G-C daftar dari laporan (tanpa JS)', 375);
    await page.goto(BASE + '/portal/kesehatan_laporan.php' + SEP);
    await periksaLayar(page, 'G-C laporan tanpa JS', 375);
    await page.screenshot({ path: `${OUT}/laporan-petugas-375-tanpa-js.png`, fullPage: true });
    await context.close();
  }

  // ----------------------------------------------------------------- G-D
  for (const user of ['sbx_v5_wali_1', 'sbx_pengurus_biasa']) {
    const { context, page } = await konteks(browser, user, 375);
    const r = await page.goto(BASE + '/portal/kesehatan_laporan.php' + SEP);
    const teks = await page.locator('body').innerText();
    cek(r.status() === 403 && !teks.includes('SBX V5 Lapor'), `G-D ${user}: 403 tanpa data santri`);
    await context.close();
  }
} finally {
  await browser.close();
}
cek(galat.length === 0, 'G-D tanpa galat JavaScript', galat.join(' | '));
cek(bawaan.length === 0, 'G-D tanpa confirm()/alert() bawaan', bawaan.join(' | '));
console.log(`\nLulus: ${lulus} · Gagal: ${gagal}`);
process.exit(gagal === 0 ? 0 : 1);
