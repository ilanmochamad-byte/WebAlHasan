/**
 * Uji browser PRD V7 Fase 5 — end-to-end kedua jalur dan halaman laporan/cetak
 * lama pada 375px, 768px, 1280px, ditambah tanpa JavaScript (375px).
 *
 *   E-A  jalur wali: wali mengajukan (klik ganda) → pembimbing meminta
 *        perbaikan → wali memperbaiki → pembimbing meneruskan → murobi memutus
 *        di dialog V2 → wali dan wali kedua melihat status yang sama;
 *   E-B  jalur pengurus V2: pembimbing (pengurus) membuat pengajuan langsung
 *        ke murobi → murobi memutus; tidak ada tahap pembimbing;
 *   E-C  wali asing dan pembimbing lain membuka ID pengajuan → ditolak tanpa isi;
 *   E-D  laporan/cetak lama dan V7 (admin, murobi, pembimbing, wali) rapi pada
 *        tiga lebar tanpa melebar;
 *   E-E  tanpa JavaScript (375px): wali membatalkan pengajuan beralasan,
 *        laporan V2 dan V7 tetap dapat dibaca;
 *   E-F  tanpa galat JavaScript dan tanpa confirm()/alert() bawaan.
 *
 *   V7F5_DB=test_v7_f5_browser_test bash tests/v7_phase5_db.sh
 *   DB_NAME=test_v7_f5_browser_test php -S 127.0.0.1:8972 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8972 node tests/browser/uji-v7-fase5.mjs
 *
 * Uji ini MENGUBAH DB uji. Bangun ulang DB sebelum mengulang.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8972';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-v7-fase5', import.meta.url).pathname;
const SANDI = process.env.UJI_SANDI ?? 'Sandbox#123';
const LEBAR = [375, 768, 1280];
const PENANDA = 'RAHASIA-BROWSER-V7F5';
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
const isiMain = (page) => page.locator('main').innerText();
async function rapi(page, nama, lebar, opsi = {}) {
  const masalah = [];
  const lebih = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  if (lebih > 0) masalah.push(`melebar ${lebih}px`);
  if (!opsi.cetak) {
    if ((await page.locator('main h1').count()) !== 1) masalah.push('h1 bukan satu');
    if ((await page.locator('#ah-shell').count()) !== 1) masalah.push('bukan kerangka bersama');
  }
  if (opsi.tanpaAlasan && (await page.content()).includes(PENANDA)) masalah.push('alasan izin tampil');
  cek(masalah.length === 0, `${nama} ${lebar}px: tidak melebar${opsi.cetak ? '' : ', kerangka bersama'}${opsi.tanpaAlasan ? ', tanpa alasan' : ''}`, [...new Set(masalah)].join('; '));
}
const keDasar = (page) => page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
async function bukaDialog(page, id) {
  const ringkas = page.locator(`#${id} > summary`);
  await ringkas.evaluate((n) => n.scrollIntoView({ block: 'center' }));
  await ringkas.click();
}
async function kirimDialog(page, tombol) {
  const wadah = (await page.locator('dialog[open]').count()) > 0 ? page.locator('dialog[open]') : page.locator('main');
  const b = wadah.getByRole('button', { name: tombol, exact: true }).first();
  await b.evaluate((n) => n.scrollIntoView({ block: 'center' }));
  await Promise.all([page.waitForEvent('load'), b.click()]);
  await page.waitForLoadState('networkidle');
}
async function ajukan(page, santri, dari, sampai, alasan, ganda = false) {
  await page.goto(BASE + '/portal/izin_wali_buat.php');
  await page.selectOption('#santri_id', await page.locator('#santri_id option', { hasText: santri }).first().getAttribute('value'));
  await page.fill('#tgl_izin', dari);
  await page.fill('#tgl_kembali', sampai);
  await page.fill('#alasan', alasan);
  await keDasar(page);
  const tombol = page.getByRole('button', { name: 'Kirim pengajuan' });
  if (ganda) {
    // Klik ganda cepat: tombol sibuk menahan klik kedua; server tetap idempoten.
    await Promise.all([page.waitForURL(/izin_wali_detail\.php\?id=\d+/), tombol.dblclick()]);
  } else {
    await Promise.all([page.waitForURL(/izin_wali_detail\.php\?id=\d+/), tombol.click()]);
  }
  await page.waitForLoadState('networkidle');
  return Number(new URL(page.url()).searchParams.get('id'));
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  const hari = { 375: '2027-08-02', 768: '2027-08-09', 1280: '2027-08-16' };
  const hariV2 = { 375: '2027-09-06', 768: '2027-09-13', 1280: '2027-09-20' };
  const idPerLebar = {};
  for (const lebar of LEBAR) {
    const wali = await konteks(browser, 'sbx_v7_wali_a', lebar);
    const pb = await konteks(browser, 'sbx_v7_pembimbing_satu', lebar);
    const murobi = await konteks(browser, 'sbx_murobi_a', lebar);
    const waliB = await konteks(browser, 'sbx_v7_wali_b', lebar);

    // E-A
    const id = await ajukan(wali.page, 'SBX V7 Santri Cadangan', hari[lebar], hari[lebar], PENANDA + ' acara keluarga ' + lebar, true);
    idPerLebar[lebar] = id;
    await wali.page.goto(BASE + '/portal/izin_wali.php');
    const jumlahBaris = await wali.page.locator(`a[href*="izin_wali_detail.php?id=${id}"]`).count();
    cek(id > 0 && jumlahBaris >= 1, `E-A ${lebar}px: wali mengajukan dengan klik ganda → pengajuan #${id} tercantum`);
    await rapi(wali.page, 'E-A daftar wali', lebar);
    await pb.page.goto(BASE + '/portal/izin_wali_detail.php?id=' + id);
    await bukaDialog(pb.page, 'aksi-minta-perbaikan');
    await pb.page.fill('#catatan_perbaikan', 'Mohon cantumkan jam jemput ' + lebar);
    await kirimDialog(pb.page, 'Kirim permintaan');
    cek((await isiMain(pb.page)).includes('Perlu perbaikan'), `E-A ${lebar}px: pembimbing meminta perbaikan`);
    await wali.page.goto(BASE + '/portal/izin_wali_detail.php?id=' + id);
    await bukaDialog(wali.page, 'aksi-perbaiki');
    await wali.page.fill('#alasan_perbaikan', PENANDA + ' acara keluarga, dijemput 16.00 ' + lebar);
    await kirimDialog(wali.page, 'Kirim ulang');
    cek((await isiMain(wali.page)).includes('Menunggu tinjauan pembimbing') || (await isiMain(wali.page)).includes('Menunggu pembimbing'), `E-A ${lebar}px: wali memperbaiki dan mengirim ulang`);
    await pb.page.goto(BASE + '/portal/izin_wali_detail.php?id=' + id);
    await bukaDialog(pb.page, 'aksi-teruskan');
    await kirimDialog(pb.page, 'Teruskan');
    cek((await isiMain(pb.page)).includes('Diteruskan ke murobi'), `E-A ${lebar}px: pembimbing meneruskan ke murobi`);
    await murobi.page.goto(BASE + '/portal/izin_detail.php?id=' + id);
    await rapi(murobi.page, 'E-A rincian V2 murobi', lebar);
    await bukaDialog(murobi.page, 'aksi-putuskan');
    await murobi.page.selectOption('#hasil', 'Disetujui');
    await murobi.page.fill('#alasan_keputusan', 'Diizinkan ' + lebar);
    await kirimDialog(murobi.page, 'Simpan keputusan');
    cek((await isiMain(murobi.page)).includes('Disetujui'), `E-A ${lebar}px: murobi memutus di dialog V2 pada ID yang sama`);
    await wali.page.goto(BASE + '/portal/izin_wali_detail.php?id=' + id);
    const mainWali = await isiMain(wali.page);
    await waliB.page.goto(BASE + '/portal/izin_wali_detail.php?id=' + id);
    const mainB = await isiMain(waliB.page);
    cek(mainWali.includes('Disetujui') && mainB.includes('Disetujui') && !mainB.includes(PENANDA) && (await waliB.page.locator('#aksi-batalkan-wali').count()) === 0,
      `E-A ${lebar}px: wali pengaju dan wali kedua melihat Disetujui; wali kedua tanpa alasan/tindakan`);
    await rapi(wali.page, 'E-A rincian wali sesudah keputusan', lebar);
    await rapi(waliB.page, 'E-A rincian wali kedua', lebar, { tanpaAlasan: true });
    await wali.page.screenshot({ path: `${OUT}/e2e-wali-diputus-${lebar}.png`, fullPage: true });

    // E-B
    await pb.page.goto(BASE + '/portal/izin_buat.php?mode=pengurus');
    await pb.page.selectOption('#santri_id', await pb.page.locator('#santri_id option', { hasText: 'SBX V7 Santri Satu' }).first().getAttribute('value'));
    await pb.page.fill('#tgl_izin', hariV2[lebar]);
    await pb.page.fill('#tgl_kembali', hariV2[lebar]);
    await pb.page.fill('#alasan', 'Jalur pengurus ' + lebar);
    await keDasar(pb.page);
    await Promise.all([pb.page.waitForURL(/izin_detail\.php\?id=\d+/), pb.page.getByRole('button', { name: 'Kirim pengajuan' }).click()]);
    const idV2 = Number(new URL(pb.page.url()).searchParams.get('id'));
    const mainV2 = await isiMain(pb.page);
    cek(idV2 > id && !mainV2.includes('Diajukan wali'), `E-B ${lebar}px: pengurus membuat pengajuan V2 langsung (#${idV2}), tanpa tahap pembimbing`);
    await murobi.page.goto(BASE + '/portal/izin_detail.php?id=' + idV2);
    await bukaDialog(murobi.page, 'aksi-putuskan');
    await murobi.page.selectOption('#hasil', 'Ditolak');
    await murobi.page.fill('#alasan_keputusan', 'Bentrok kegiatan ' + lebar);
    await kirimDialog(murobi.page, 'Simpan keputusan');
    cek((await isiMain(murobi.page)).includes('Ditolak'), `E-B ${lebar}px: murobi memutus pengajuan pengurus`);
    await Promise.all([wali.context.close(), pb.context.close(), murobi.context.close(), waliB.context.close()]);
  }

  // E-C
  for (const [user, nama] of [['sbx_ortu_b', 'wali asing'], ['sbx_v7_pembimbing_dua', 'pembimbing lain'], ['sbx_murobi_b', 'murobi lain']]) {
    const k = await konteks(browser, user, 375);
    const r = await k.page.goto(BASE + '/portal/izin_wali_detail.php?id=' + idPerLebar[375]);
    const isi = await k.page.content();
    cek(r.status() === 404 && !isi.includes(PENANDA) && !isi.includes('SBX V7 Santri Cadangan'), `E-C 375px: ${nama} membuka ID pengajuan → 404 tanpa isi`);
    await k.context.close();
  }

  // E-D
  const halaman = [
    ['sbx_admin', '/portal/laporan.php?mode=admin', 'laporan V2 admin', {}],
    ['sbx_admin', '/portal/laporan_cetak.php?mode=admin', 'cetak laporan V2 admin', { cetak: true }],
    ['sbx_murobi_a', '/portal/laporan.php?mode=murobi', 'laporan V2 murobi', {}],
    ['sbx_v7_pembimbing_satu', '/portal/laporan.php?mode=pengurus', 'laporan V2 pengurus', {}],
    ['sbx_admin', '/portal/izin_wali_laporan.php', 'laporan V7 admin', { tanpaAlasan: true }],
    ['sbx_v7_wali_a', '/portal/izin_wali_laporan.php', 'laporan V7 wali', { tanpaAlasan: true }],
    ['sbx_admin', '/portal/izin.php?mode=admin', 'daftar perizinan V2 admin', {}],
    ['sbx_admin', '/portal/izin_wali.php', 'pemantauan V7 admin', { tanpaAlasan: true }],
  ];
  for (const lebar of LEBAR) {
    for (const [user, alamat, nama, opsi] of halaman) {
      const k = await konteks(browser, user, lebar);
      const r = await k.page.goto(BASE + alamat);
      cek(r.status() === 200, `E-D ${nama} ${lebar}px: 200`);
      await rapi(k.page, `E-D ${nama}`, lebar, opsi);
      if (lebar === 1280 || lebar === 375) await k.page.screenshot({ path: `${OUT}/${nama.replace(/\s+/g, '-')}-${lebar}.png`, fullPage: true });
      await k.context.close();
    }
  }

  // E-E tanpa JavaScript
  const waliTanpa = await konteks(browser, 'sbx_v7_wali_a', 375, false);
  await waliTanpa.page.goto(BASE + '/portal/izin_wali_buat.php');
  await waliTanpa.page.selectOption('#santri_id', await waliTanpa.page.locator('#santri_id option', { hasText: 'SBX V7 Santri Cadangan' }).first().getAttribute('value'));
  await waliTanpa.page.fill('#tgl_izin', '2027-10-04');
  await waliTanpa.page.fill('#tgl_kembali', '2027-10-05');
  await waliTanpa.page.fill('#alasan', PENANDA + ' tanpa JS');
  const kirim = waliTanpa.page.getByRole('button', { name: 'Kirim pengajuan' });
  await kirim.evaluate((n) => n.scrollIntoView({ block: 'center' }));
  await Promise.all([waliTanpa.page.waitForURL(/izin_wali_detail\.php\?id=\d+/), kirim.click()]);
  const idTanpa = Number(new URL(waliTanpa.page.url()).searchParams.get('id'));
  await bukaDialog(waliTanpa.page, 'aksi-batalkan-wali');
  await waliTanpa.page.fill('#alasan_batal', 'Acara batal, tanpa JavaScript');
  await kirimDialog(waliTanpa.page, 'Batalkan pengajuan');
  cek(idTanpa > 0 && (await isiMain(waliTanpa.page)).includes('Dibatalkan'), 'E-E 375px tanpa JavaScript: wali mengajukan lalu membatalkan beralasan');
  await rapi(waliTanpa.page, 'E-E rincian dibatalkan tanpa JavaScript', 375);
  await waliTanpa.context.close();
  const admTanpa = await konteks(browser, 'sbx_admin', 375, false);
  for (const [alamat, nama] of [['/portal/laporan.php?mode=admin', 'laporan V2'], ['/portal/izin_wali_laporan.php', 'laporan V7']]) {
    const r = await admTanpa.page.goto(BASE + alamat);
    cek(r.status() === 200 && (await admTanpa.page.locator('table').count()) > 0, `E-E 375px tanpa JavaScript: ${nama} terbaca`);
    await rapi(admTanpa.page, `E-E ${nama} tanpa JavaScript`, 375);
  }
  await admTanpa.context.close();
} finally {
  await browser.close();
}

// E-F
cek(galat.length === 0, 'E-F tanpa galat JavaScript', galat.slice(0, 5).join(' | '));
cek(bawaan.length === 0, 'E-F tanpa confirm()/alert() bawaan', bawaan.slice(0, 5).join(' | '));
console.log(`\nHasil: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
