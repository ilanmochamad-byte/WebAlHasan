/**
 * Uji browser PRD V7 Fase 3 — notifikasi tahap di pusat notifikasi dan
 * laporan lintas asal wali/pengurus.
 *
 * Melengkapi uji server `tests/v7_phase3_http.php` pada 375px, 768px, 1280px:
 *
 *   D-A  wali mengajukan; pembimbing membuka notifikasi → rincian pengajuan;
 *   D-B  pembimbing meneruskan; wali pengaju membuka notifikasi "diteruskan";
 *        wali kedua tidak menerima notifikasi;
 *   D-C  laporan per peran (admin, pembimbing, murobi, wali pengaju, wali
 *        kedua): kerangka rapi, total tabel = jumlah kartu, saringan asal;
 *   D-D  tanpa JavaScript (375px): saringan laporan dan tautan notifikasi;
 *   D-E  admin melihat jejak notifikasi di rincian;
 *   D-F  tanpa galat JavaScript dan tanpa confirm()/alert() bawaan.
 *
 *   V7F3_DB=test_v7_f3_browser_test bash tests/v7_phase3_db.sh
 *   DB_NAME=test_v7_f3_browser_test php -S 127.0.0.1:8994 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8994 node tests/browser/uji-v7-fase3.mjs
 *
 * Uji ini MENGUBAH DB uji. Bangun ulang DB sebelum mengulang.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8994';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-v7-fase3', import.meta.url).pathname;
const SANDI = process.env.UJI_SANDI ?? 'Sandbox#123';
const LEBAR = [375, 768, 1280];
const PENANDA = 'RAHASIA-BROWSER-V7F3';
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
  const context = await browser.newContext({ viewport: { width: lebar, height: lebar < 576 ? 812 : 900 }, javaScriptEnabled: js, reducedMotion: 'reduce' });
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
  for (const tombol of await page.locator('.ah-page-head__actions .btn').all()) {
    if (await tombol.isVisible()) {
      const kotak = await tombol.boundingBox();
      if (kotak && kotak.height < 43.5) masalah.push(`tombol ${Math.round(kotak.height)}px`);
    }
  }
  if ((await page.content()).includes(PENANDA)) masalah.push('alasan izin tampil');
  cek(masalah.length === 0, `${nama} ${lebar}px: rapi di kerangka bersama, tanpa alasan izin`, [...new Set(masalah)].join('; '));
}
const keDasar = (page) => page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
async function ajukan(page, santri, dari, sampai) {
  await page.goto(BASE + '/portal/izin_wali_buat.php');
  await page.selectOption('#santri_id', await page.locator('#santri_id option', { hasText: santri }).first().getAttribute('value'));
  await page.fill('#tgl_izin', dari);
  await page.fill('#tgl_kembali', sampai);
  await page.fill('#alasan', PENANDA + ' acara keluarga');
  await keDasar(page);
  await Promise.all([page.waitForURL(/izin_wali_detail\.php\?id=\d+/), page.getByRole('button', { name: 'Kirim pengajuan' }).click()]);
  await page.waitForLoadState('networkidle');
  return Number(new URL(page.url()).searchParams.get('id'));
}
async function bukaNotif(page, judul) {
  await page.goto(BASE + '/portal/notifikasi.php?jenis=perizinan');
  const tautan = page.locator('a.ah-notif__link', { hasText: judul }).first();
  if ((await tautan.count()) === 0) return null;
  await tautan.evaluate((n) => n.scrollIntoView({ block: 'center' }));
  await Promise.all([page.waitForEvent('load'), tautan.click()]);
  await page.waitForLoadState('networkidle');
  return page.url();
}
async function teruskan(page, id) {
  await page.goto(BASE + '/portal/izin_wali_detail.php?id=' + id);
  const ringkas = page.locator('#aksi-teruskan > summary');
  await ringkas.evaluate((n) => n.scrollIntoView({ block: 'center' }));
  await ringkas.click();
  const wadah = (await page.locator('dialog[open]').count()) > 0 ? page.locator('dialog[open]') : page.locator('main');
  const b = wadah.getByRole('button', { name: 'Teruskan', exact: true }).first();
  await b.evaluate((n) => n.scrollIntoView({ block: 'center' }));
  await Promise.all([page.waitForEvent('load'), b.click()]);
  await page.waitForLoadState('networkidle');
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  const tanggal = { 375: '2027-11-02', 768: '2027-11-04', 1280: '2027-11-06' };
  const ids = {};
  for (const lebar of LEBAR) {
    const wali = await konteks(browser, 'sbx_v7_wali_a', lebar);
    const pb = await konteks(browser, 'sbx_v7_pembimbing_satu', lebar);
    const waliB = await konteks(browser, 'sbx_v7_wali_b', lebar);
    // D-A
    const id = await ajukan(wali.page, 'SBX V7 Santri Cadangan', tanggal[lebar], tanggal[lebar]);
    ids[lebar] = id;
    await pb.page.goto(BASE + '/portal/notifikasi.php?jenis=perizinan');
    await periksaLayar(pb.page, 'D-A pusat notifikasi pembimbing', lebar);
    await pb.page.screenshot({ path: `${OUT}/notifikasi-pembimbing-${lebar}.png`, fullPage: true });
    const tujuan = await bukaNotif(pb.page, 'Pengajuan izin wali menunggu tinjauan');
    cek(tujuan !== null && tujuan.includes('izin_wali_detail.php?id=') && (await pb.page.locator('main').innerText()).includes('SBX V7 Santri Cadangan'),
      `D-A ${lebar}px: notifikasi pembimbing membuka rincian pengajuan`);
    // D-B
    await teruskan(pb.page, id);
    const tujuanWali = await bukaNotif(wali.page, 'Pengajuan izin diteruskan ke murobi');
    cek(tujuanWali !== null && tujuanWali.endsWith('izin_wali_detail.php?id=' + id) && (await wali.page.locator('main').innerText()).includes('Diteruskan ke murobi'),
      `D-B ${lebar}px: wali pengaju membuka notifikasi "diteruskan" ke rincian terkini`);
    await waliB.page.goto(BASE + '/portal/notifikasi.php?jenis=perizinan');
    cek((await waliB.page.locator('a.ah-notif__link', { hasText: 'diteruskan' }).count()) === 0, `D-B ${lebar}px: wali kedua tidak menerima notifikasi pengajuan wali A`);
    await Promise.all([wali.context.close(), pb.context.close(), waliB.context.close()]);
  }

  // D-C
  for (const [user, nama] of [['sbx_admin', 'admin'], ['sbx_v7_pembimbing_satu', 'pembimbing'], ['sbx_murobi_a', 'murobi'], ['sbx_v7_wali_a', 'wali pengaju'], ['sbx_v7_wali_b', 'wali kedua']]) {
    for (const lebar of LEBAR) {
      const k = await konteks(browser, user, lebar);
      await k.page.goto(BASE + '/portal/izin_wali_laporan.php');
      await periksaLayar(k.page, `D-C laporan ${nama}`, lebar);
      const total = Number(await k.page.locator('[data-total]').getAttribute('data-total'));
      const kartu = await k.page.locator('.ah-stat__value').first().innerText().catch(() => '');
      const baris = await k.page.locator('table.ah-table tbody tr').count();
      cek(total >= 0 && kartu.replace(/\D/g, '') === String(total) && baris === Math.min(total, 25),
        `D-C ${nama} ${lebar}px: kartu total = data-total = baris tabel halaman pertama (${total})`, `kartu "${kartu}" baris ${baris}`);
      if (lebar === 1280) await k.page.screenshot({ path: `${OUT}/laporan-${nama.replace(' ', '-')}-${lebar}.png`, fullPage: true });
      if (nama === 'wali kedua') {
        const isi = await k.page.locator('main').innerText();
        cek(isi.includes('Wali lain') && !isi.includes('Lama total'), `D-C wali kedua ${lebar}px: pengajuan wali A ringkas "Wali lain"`);
      }
      if (nama === 'admin' && lebar === 768) {
        await k.page.selectOption('#f-asal', 'wali');
        await Promise.all([k.page.waitForEvent('load'), k.page.getByRole('button', { name: 'Terapkan' }).click()]);
        const asal = await k.page.locator('table.ah-table tbody tr td:nth-child(2)').allInnerTexts();
        cek(k.page.url().includes('asal=wali') && asal.length > 0 && asal.every((a) => a.includes('Wali')), 'D-C admin 768px: saringan asal wali hanya baris asal Wali');
        const ekspor = await k.page.locator('a', { hasText: 'Ekspor CSV' }).getAttribute('href');
        cek(ekspor.includes('izin_wali_laporan_ekspor.php') && ekspor.includes('asal=wali'), 'D-C admin 768px: tautan ekspor membawa saringan yang sama');
      }
      await k.context.close();
    }
  }

  // D-D tanpa JavaScript
  const tanpa = await konteks(browser, 'sbx_admin', 375, false);
  await tanpa.page.goto(BASE + '/portal/izin_wali_laporan.php');
  await tanpa.page.selectOption('#f-hasil', 'disetujui');
  const terapkan = tanpa.page.getByRole('button', { name: 'Terapkan' });
  await terapkan.evaluate((n) => n.scrollIntoView({ block: 'center' }));
  await Promise.all([tanpa.page.waitForEvent('load'), terapkan.click()]);
  const status = await tanpa.page.locator('table.ah-table tbody tr td:nth-child(5)').allInnerTexts();
  cek(tanpa.page.url().includes('hasil=disetujui') && status.length > 0 && status.every((s) => s.includes('Disetujui')), 'D-D 375px tanpa JavaScript: saringan hasil laporan bekerja');
  await periksaLayar(tanpa.page, 'D-D laporan tanpa JavaScript', 375);
  await tanpa.context.close();
  const pbTanpa = await konteks(browser, 'sbx_v7_pembimbing_satu', 375, false);
  const tujuan = await bukaNotif(pbTanpa.page, 'Pengajuan izin wali menunggu tinjauan');
  cek(tujuan !== null && tujuan.includes('izin_wali_detail.php?id='), 'D-D 375px tanpa JavaScript: tautan notifikasi membuka rincian');
  await pbTanpa.context.close();

  // D-E
  const adm = await konteks(browser, 'sbx_admin', 1280);
  await adm.page.goto(BASE + '/portal/izin_wali_detail.php?id=' + ids[1280]);
  const jejak = await adm.page.locator('#ah-jejak-notifikasi').count();
  const isiAdmin = await adm.page.locator('main').innerText();
  cek(jejak === 1 && isiAdmin.includes('Diteruskan ke murobi') && isiAdmin.includes('InApp'), 'D-E admin: jejak notifikasi tampil di rincian');
  await adm.page.screenshot({ path: `${OUT}/rincian-admin-jejak-1280.png`, fullPage: true });
  await adm.context.close();
} finally {
  await browser.close();
}
cek(galat.length === 0, 'D-F tanpa galat JavaScript', galat.join(' | '));
cek(bawaan.length === 0, 'D-F tanpa confirm()/alert() bawaan', bawaan.join(' | '));
console.log(`\nLulus: ${lulus} · Gagal: ${gagal}`);
process.exit(gagal === 0 ? 0 : 1);
