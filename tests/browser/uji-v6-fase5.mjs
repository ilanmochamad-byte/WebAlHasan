/**
 * Uji browser PRD V6 Fase 5 — laporan dan ekspor keuangan (halaman web).
 *
 * Melengkapi uji server `tests/v6_phase5_http.php` dengan perilaku yang hanya
 * terlihat di peramban, pada 375px, 768px, dan 1280px:
 *
 *   B-A  laporan admin, petugas seluruh unit, petugas unit, dan wali dua anak:
 *        tanpa geser horizontal, satu h1, kerangka bersama, tabel menjadi kartu
 *        di HP, tombol ekspor ≥ 44 px; navigasi "Laporan" aktif;
 *   B-B  saringan periode (bulan dari/sampai) dan unit lewat formulir GET;
 *        santri → laporan per santri → kembali ke semua santri;
 *   B-C  ekspor CSV lewat tombol (unduhan bernama periode, BOM, kepala kolom);
 *        wali tanpa tombol/ekspor koreksi dan tanpa referensi di berkas;
 *   B-D  tanpa JavaScript (375px): laporan, saringan, dan unduhan tetap jalan;
 *   B-E  wali tidak melihat kas, jejak koreksi beralasan, transfer menunggu;
 *        admin melihat santri nonaktif bersaldo (R-10) dan selisih Rp0;
 *        tanpa galat JavaScript dan tanpa confirm()/alert().
 *
 *   V6F5_DB=test_v6_f5_browser_test bash tests/v6_phase5_db.sh
 *   DB_NAME=test_v6_f5_browser_test php tests/v6_phase5_worker.php <aba> <id sbx_v6_bsa_b> fixture <id V6S0001> <id V6S0002> <id V6S0004>
 *   DB_NAME=test_v6_f5_browser_test php -S 127.0.0.1:8987 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8987 node tests/browser/uji-v6-fase5.mjs
 *
 * Uji ini hanya membaca (laporan) dan menulis audit ekspor.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8987';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-v6-fase5', import.meta.url).pathname;
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
  const context = await browser.newContext({ viewport: { width: lebar, height: lebar < 576 ? 812 : 900 }, javaScriptEnabled: js, acceptDownloads: true });
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
  for (const tombol of await page.locator('.ah-page-head__actions .btn').all()) {
    if (await tombol.isVisible()) {
      const kotak = await tombol.boundingBox();
      if (kotak && kotak.height < 43.5) masalah.push(`tombol ${Math.round(kotak.height)}px`);
    }
  }
  const aktif = await page.locator('nav[aria-label="Bagian keuangan"] [aria-current="page"], .ah-seg [aria-current="page"]').allInnerTexts();
  if (!aktif.some((t) => t.trim() === 'Laporan')) masalah.push('navigasi Laporan tidak aktif (' + aktif.join('|') + ')');
  cek(masalah.length === 0, `B-A ${nama} ${lebar}px: rapi di kerangka bersama, navigasi Laporan aktif`, [...new Set(masalah)].join('; '));
}
const teksMain = (page) => page.locator('main').innerText();
const bulan = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
const kini = new Date();
const B = bulan(kini);
const A = bulan(new Date(kini.getFullYear(), kini.getMonth() - 1, 1));
async function unduh(page, nama) {
  const [d] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: nama }).first().click()]);
  const jalur = await d.path();
  return { nama: d.suggestedFilename(), isi: readFileSync(jalur, 'utf8') };
}

const browser = await chromium.launch();
try {
  // ================================================================ B-A
  for (const lebar of LEBAR) {
    for (const user of ['sbx_admin', 'sbx_v6_bsa_b', 'sbx_v6_bsa_tsanawi', 'sbx_v6_wali_dua']) {
      const { context, page } = await konteks(browser, user, lebar);
      await page.goto(BASE + '/portal/bsa_laporan.php');
      await periksaLayar(page, user, lebar);
      await page.screenshot({ path: `${OUT}/laporan-${user}-${lebar}.png`, fullPage: true });
      await context.close();
    }
  }

  // ================================================================ B-B
  {
    const { context, page } = await konteks(browser, 'sbx_admin', 1280);
    await page.goto(BASE + '/portal/bsa_laporan.php');
    const awal = await teksMain(page);
    cek(/Rekonsiliasi periode: selisih Rp0/.test(awal) && /Santri nonaktif yang masih bersaldo/.test(awal) && /Kas nyata per cara/.test(awal),
      'B-E admin: selisih Rp0, daftar santri nonaktif bersaldo (R-10), kas nyata');
    await page.fill('#lap-dari', A);
    await page.fill('#lap-sampai', A);
    await Promise.all([page.waitForURL(/dari=/), page.getByRole('button', { name: 'Terapkan' }).click()]);
    const u = new URL(page.url());
    const isi = await teksMain(page);
    cek(u.searchParams.get('dari') === A && u.searchParams.get('sampai') === A && /Tagihan terbit\s*Rp0/i.test(isi), `B-B saringan periode ${A}: alamat GET dapat dibagikan; tagihan bulan lalu Rp0`);
    await page.goto(BASE + '/portal/bsa_laporan.php');
    await page.selectOption('#lap-unit', 'Tsanawi');
    await Promise.all([page.waitForURL(/unit=Tsanawi/), page.getByRole('button', { name: 'Terapkan' }).click()]);
    cek(!(await teksMain(page)).includes('SBX V6 Santri SMP'), 'B-B saringan unit Tsanawi: santri SMP tidak tampil');
    await page.goto(BASE + '/portal/bsa_laporan.php');
    await Promise.all([page.waitForURL(/santri=/), page.locator('main a.ah-row-link', { hasText: 'SBX V6 Santri SMP' }).first().click()]);
    const per = await teksMain(page);
    cek(/Laporan untuk SBX V6 Santri SMP/.test(per) && !per.includes('SBX V6 Santri Tsanawi'), 'B-B klik santri → laporan per santri');
    await Promise.all([page.waitForURL((x) => !x.search.includes('santri=')), page.getByRole('link', { name: 'Tampilkan semua santri' }).click()]);
    cek((await teksMain(page)).includes('SBX V6 Santri Tsanawi'), 'B-B "Tampilkan semua santri" kembali ke seluruh santri');

    // ================================================================ B-C
    const s = await unduh(page, 'CSV per santri');
    const t = await unduh(page, 'CSV rincian transaksi');
    const k = await unduh(page, 'CSV koreksi');
    cek(s.nama === `keuangan-santri-${B}.csv` && s.isi.startsWith('﻿NIS,"Nama santri"') && s.isi.includes('V6S0004'), 'B-C admin: unduhan CSV per santri bernama periode, BOM, kepala kolom, termasuk santri keluar');
    cek(t.isi.includes('"Kas baru"') && t.isi.includes('Internal') && !t.isi.includes('TRF-RILIS'), 'B-C rincian transaksi: kolom kas baru/internal, tanpa referensi transfer');
    cek(k.isi.includes('Uji rilis koreksi') && k.isi.includes('BSA-TB-'), 'B-C CSV koreksi: alasan dan nomor kuitansi asal');
    await context.close();
  }
  {
    const { context, page } = await konteks(browser, 'sbx_v6_wali_dua', 1280);
    await page.goto(BASE + '/portal/bsa_laporan.php');
    const isi = await teksMain(page);
    cek(!/Kas nyata|Jejak koreksi|menunggu verifikasi|Santri nonaktif/.test(isi) && !isi.includes('Uji rilis') && (await page.getByRole('link', { name: 'CSV koreksi' }).count()) === 0,
      'B-E wali: tanpa kas, jejak koreksi, transfer menunggu, santri nonaktif, atau tombol CSV koreksi');
    const t = await unduh(page, 'CSV rincian transaksi');
    cek(t.isi.includes('V6S0001') && !t.isi.includes('V6S0004') && !t.isi.includes('TRF-RILIS') && !t.isi.includes('Uji rilis'), 'B-C wali: unduhan rincian transaksi anaknya saja, tanpa referensi/alasan');
    await context.close();
  }

  // ================================================================ B-D
  {
    const { context, page } = await konteks(browser, 'sbx_v6_bsa_b', 375, false);
    await page.goto(BASE + '/portal/bsa_laporan.php');
    await periksaLayar(page, 'sbx_v6_bsa_b tanpa JS', 375);
    await page.fill('#lap-dari', A);
    // Tanpa JavaScript, posisi gulir awal HP dapat menaruh tombol tepat di belakang bilah
    // bawah; pengguna menggulir dahulu (sama dengan uji Fase 3), uji menggulirnya ke tengah.
    const terapkan = page.getByRole('button', { name: 'Terapkan' });
    await terapkan.evaluate((el) => el.scrollIntoView({ block: 'center' }));
    await Promise.all([page.waitForURL(/dari=/), terapkan.click()]);
    cek(new URL(page.url()).searchParams.get('dari') === A && (await teksMain(page)).includes('Per bulan'), 'B-D tanpa JavaScript: saringan GET terkirim (dari ' + A + ')');
    const s = await unduh(page, 'CSV per santri');
    cek(s.nama.startsWith('keuangan-santri-') && s.isi.includes('NIS'), 'B-D tanpa JavaScript: unduhan CSV tetap berjalan');
    await page.screenshot({ path: `${OUT}/laporan-tanpa-js-375.png`, fullPage: true });
    await context.close();
  }
} finally {
  await browser.close();
}
cek(galat.length === 0, 'B-E tanpa galat JavaScript/konsol', galat.slice(0, 5).join(' | '));
cek(bawaan.length === 0, 'B-E tanpa confirm()/alert()/prompt()', bawaan.join(' | '));
console.log(`\nRingkasan browser Fase 5: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal ? 1 : 0);
