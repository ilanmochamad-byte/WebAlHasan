/**
 * Uji browser PRD V7 Fase 2 — tinjauan pembimbing, perbaikan dan pembatalan
 * wali pengaju, penerusan, dan keputusan murobi V2.
 *
 * Melengkapi uji server `tests/v7_phase2_http.php` dengan perilaku yang hanya
 * terlihat di peramban, pada 375px, 768px, dan 1280px:
 *
 *   C-A  pembimbing meminta perbaikan lewat dialog tindakan;
 *   C-B  wali pengaju melihat catatan, memperbaiki, dan mengirim ulang;
 *   C-C  wali kedua: status ringkas tanpa formulir;
 *   C-D  pembimbing meneruskan (klik ganda → satu penerusan); murobi melihat
 *        penanda asal wali di rincian V2 dan memutus; wali melihat keputusan;
 *   C-E  pembimbing menolak; wali melihat "Ditolak pada tahap pembimbing";
 *   C-F  wali pengaju membatalkan sesudah diteruskan;
 *   C-G  tanpa JavaScript (375px): minta perbaikan, perbaikan, teruskan, batal;
 *   C-H  tanpa geser horizontal, satu h1, kerangka bersama, tanpa galat
 *        JavaScript dan tanpa confirm()/alert() bawaan.
 *
 *   V7F2_DB=test_v7_f2_browser_test bash tests/v7_phase2_db.sh
 *   DB_NAME=test_v7_f2_browser_test php -S 127.0.0.1:8995 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8995 node tests/browser/uji-v7-fase2.mjs
 *
 * Uji ini MENGUBAH DB uji. Bangun ulang DB sebelum mengulang.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8999';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-v7-fase2', import.meta.url).pathname;
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
  // Gerak dikurangi: gulir halus membuat percobaan ulang klik Playwright terus menggulir.
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
  for (const tombol of await page.locator('.ah-page-head__actions .btn, .ah-actbar .btn').all()) {
    if (await tombol.isVisible()) {
      const kotak = await tombol.boundingBox();
      if (kotak && kotak.height < 43.5) masalah.push(`tombol ${Math.round(kotak.height)}px`);
    }
  }
  const isianId = await page.locator('main input[name$="_id"]:not([type="hidden"]):not([type="checkbox"])').count();
  if (isianId > 0) masalah.push(`${isianId} isian ID`);
  cek(masalah.length === 0, `${nama} ${lebar}px: rapi di kerangka bersama`, [...new Set(masalah)].join('; '));
}
const keDasar = (page) => page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
const isiMain = (page) => page.locator('main').innerText();
async function bukaDialog(page, id) {
  const ringkas = page.locator(`#${id} > summary`);
  await ringkas.evaluate((n) => n.scrollIntoView({ block: 'center' }));
  await ringkas.click();
}
// Dengan JavaScript formulir dipindah ke <dialog> modal; tanpa JavaScript tetap di <details>.
async function kirimDialog(page, tombol) {
  const wadah = (await page.locator('dialog[open]').count()) > 0 ? page.locator('dialog[open]') : page.locator('main');
  const b = wadah.getByRole('button', { name: tombol, exact: true }).first();
  // Tengah layar: tanpa JavaScript bilah bawah HP dapat menutupi tombol di tepi bawah.
  await b.evaluate((n) => n.scrollIntoView({ block: 'center' }));
  // Alamat rincian sama sebelum dan sesudah POST: tunggu dokumen BARU selesai dimuat.
  await Promise.all([page.waitForEvent('load'), b.click()]);
  await page.waitForLoadState('networkidle');
}
async function ajukan(page, santri, dari, sampai, alasan) {
  await page.goto(BASE + '/portal/izin_wali_buat.php');
  await page.selectOption('#santri_id', await page.locator('#santri_id option', { hasText: santri }).first().getAttribute('value'));
  await page.fill('#tgl_izin', dari);
  await page.fill('#tgl_kembali', sampai);
  await page.fill('#alasan', alasan);
  await keDasar(page);
  await Promise.all([page.waitForURL(/izin_wali_detail\.php\?id=\d+/), page.getByRole('button', { name: 'Kirim pengajuan' }).click()]);
  await page.waitForLoadState('networkidle');
  return Number(new URL(page.url()).searchParams.get('id'));
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  const rentang = { 375: ['2026-12-01', '2026-12-02'], 768: ['2026-12-05', '2026-12-06'], 1280: ['2026-12-10', '2026-12-11'] };
  for (const lebar of LEBAR) {
    const santri = 'SBX V7 Santri Cadangan';
    const wali = await konteks(browser, 'sbx_v7_wali_a', lebar);
    const id = await ajukan(wali.page, santri, ...rentang[lebar], `Walimah keluarga browser ${lebar}`);
    const url = BASE + '/portal/izin_wali_detail.php?id=' + id;

    // ------------------------------------------------------------- C-A
    const pb = await konteks(browser, 'sbx_v7_pembimbing_satu', lebar);
    await pb.page.goto(url);
    await periksaLayar(pb.page, `C-A rincian pembimbing`, lebar);
    if (lebar === 375) await pb.page.screenshot({ path: `${OUT}/rincian-pembimbing-375.png`, fullPage: true });
    await bukaDialog(pb.page, 'aksi-minta-perbaikan');
    await pb.page.fill('#catatan_perbaikan', `Mohon jelaskan jam jemput ${lebar}`);
    await kirimDialog(pb.page, 'Kirim permintaan');
    let main = await isiMain(pb.page);
    cek(main.includes('Permintaan perbaikan terkirim') && main.includes('Perlu perbaikan wali') && (await pb.page.locator('#aksi-teruskan').count()) === 0,
      `C-A ${lebar}px: pembimbing meminta perbaikan lewat dialog; tindakan tinjauan hilang`);

    // ------------------------------------------------------------- C-B
    await wali.page.goto(url);
    main = await isiMain(wali.page);
    cek(main.includes('Pembimbing meminta perbaikan') && main.includes(`Mohon jelaskan jam jemput ${lebar}`), `C-B ${lebar}px: wali pengaju melihat catatan perbaikan`);
    await periksaLayar(wali.page, 'C-B rincian wali perlu perbaikan', lebar);
    await bukaDialog(wali.page, 'aksi-perbaiki');
    await wali.page.fill('#tgl_kembali', rentang[lebar][1].replace(/\d\d$/, (d) => String(Number(d) + 1).padStart(2, '0')));
    await wali.page.fill('#alasan_perbaikan', `Walimah keluarga browser ${lebar}, dijemput pukul 08.00`);
    if (lebar === 375) await wali.page.screenshot({ path: `${OUT}/perbaikan-wali-375.png`, fullPage: true });
    await kirimDialog(wali.page, 'Kirim ulang');
    main = await isiMain(wali.page);
    cek(main.includes('Perbaikan terkirim') && main.includes('Menunggu tinjauan pembimbing') && main.includes('dijemput pukul 08.00'), `C-B ${lebar}px: perbaikan terkirim, kembali menunggu pembimbing`);

    // ------------------------------------------------------------- C-C
    const kedua = await konteks(browser, 'sbx_v7_wali_b', lebar);
    await kedua.page.goto(url);
    main = await isiMain(kedua.page);
    cek(!main.includes('Walimah') && !main.includes('jam jemput') && (await kedua.page.locator('main form').count()) === 0 && main.includes('dibuat wali lain'),
      `C-C ${lebar}px: wali kedua hanya status ringkas tanpa formulir`);
    await periksaLayar(kedua.page, 'C-C rincian wali kedua', lebar);
    await kedua.context.close();

    if (lebar === 768) {
      // --------------------------------------------------------- C-E tolak
      await pb.page.goto(url);
      await bukaDialog(pb.page, 'aksi-tolak');
      await pb.page.fill('#alasan_tolak', 'Bertepatan ujian tengah semester');
      await kirimDialog(pb.page, 'Tolak pengajuan');
      await wali.page.goto(url);
      main = await isiMain(wali.page);
      cek(main.includes('Ditolak pada tahap pembimbing (bukan keputusan murobi)') && main.includes('Bertepatan ujian tengah semester')
        && (await wali.page.locator('#aksi-batalkan-wali').count()) === 0, 'C-E 768px: wali melihat penolakan tahap pembimbing beserta alasannya; tanpa tombol batal');
      await periksaLayar(wali.page, 'C-E rincian ditolak pembimbing', lebar);
      await wali.page.screenshot({ path: `${OUT}/ditolak-pembimbing-768.png`, fullPage: true });
    } else {
      // --------------------------------------------------------- C-D teruskan (klik ganda)
      await pb.page.goto(url);
      await bukaDialog(pb.page, 'aksi-teruskan');
      await pb.page.fill('#catatan_teruskan', `Sudah dikonfirmasi ${lebar}`);
      const tombol = pb.page.locator('dialog[open]').getByRole('button', { name: 'Teruskan', exact: true }).first();
      await tombol.scrollIntoViewIfNeeded();
      await Promise.all([pb.page.waitForEvent('load'), tombol.dblclick()]);
      await pb.page.waitForLoadState('networkidle');
      main = await isiMain(pb.page);
      cek((main.match(/Diteruskan ke murobi/g) ?? []).length >= 1 && (await pb.page.locator('section[aria-labelledby="ah-riwayat-tinjauan"] li').count()) === 2,
        `C-D ${lebar}px: klik ganda Teruskan → satu penerusan (dua tinjauan: minta perbaikan + teruskan)`, pb.page.url() + ' ' + main.slice(0, 400));
      if (lebar === 1280) {
        const murobi = await konteks(browser, 'sbx_murobi_a', lebar);
        await murobi.page.goto(BASE + '/portal/izin_detail.php?id=' + id);
        main = await isiMain(murobi.page);
        cek(main.includes('Diajukan wali dan diteruskan pembimbing SBX V7 Pembimbing Satu') && main.includes('Sudah dikonfirmasi 1280'),
          'C-D 1280px: rincian V2 murobi menandai asal wali dan catatan penerusan');
        await murobi.page.screenshot({ path: `${OUT}/v2-murobi-asal-1280.png`, fullPage: true });
        await bukaDialog(murobi.page, 'aksi-putuskan');
        await murobi.page.selectOption('#hasil', 'Disetujui');
        await murobi.page.fill('#alasan_keputusan', 'Diizinkan, kembali tepat waktu');
        await kirimDialog(murobi.page, 'Simpan keputusan');
        await murobi.context.close();
        await wali.page.goto(url);
        main = await isiMain(wali.page);
        cek(main.includes('Keputusan murobi') && main.includes('Diizinkan, kembali tepat waktu') && main.includes('Disetujui murobi'),
          'C-D 1280px: murobi memutus lewat dialog V2; wali pengaju melihat keputusan');
        await periksaLayar(wali.page, 'C-D rincian wali sesudah keputusan', lebar);
        await wali.page.screenshot({ path: `${OUT}/keputusan-wali-1280.png`, fullPage: true });
      } else {
        // ------------------------------------------------------- C-F batal sesudah diteruskan
        await wali.page.goto(url);
        await bukaDialog(wali.page, 'aksi-batalkan-wali');
        await wali.page.fill('#alasan_batal', 'Acara keluarga diundur');
        await kirimDialog(wali.page, 'Batalkan pengajuan');
        main = await isiMain(wali.page);
        cek(main.includes('Pengajuan dibatalkan') && main.includes('Acara keluarga diundur'), `C-F ${lebar}px: wali pengaju membatalkan sesudah diteruskan`, wali.page.url() + ' ' + main.slice(0, 400));
        await periksaLayar(wali.page, 'C-F rincian dibatalkan', lebar);
      }
    }
    await pb.context.close();
    await wali.context.close();
  }

  // ------------------------------------------------------------- C-G tanpa JavaScript
  {
    const wali = await konteks(browser, 'sbx_v7_wali_a', 375, false);
    const pb = await konteks(browser, 'sbx_v7_pembimbing_satu', 375, false);
    const id = await ajukan(wali.page, 'SBX V7 Santri Satu', '2026-12-20', '2026-12-21', 'Tanpa JavaScript');
    const url = BASE + '/portal/izin_wali_detail.php?id=' + id;
    await pb.page.goto(url);
    await bukaDialog(pb.page, 'aksi-minta-perbaikan');
    await pb.page.fill('#catatan_perbaikan', 'Lengkapi tanpa JS');
    await kirimDialog(pb.page, 'Kirim permintaan');
    await wali.page.goto(url);
    await bukaDialog(wali.page, 'aksi-perbaiki');
    await wali.page.fill('#alasan_perbaikan', 'Tanpa JavaScript, sudah dilengkapi');
    await kirimDialog(wali.page, 'Kirim ulang');
    await pb.page.goto(url);
    await bukaDialog(pb.page, 'aksi-teruskan');
    await kirimDialog(pb.page, 'Teruskan');
    await wali.page.goto(url);
    await bukaDialog(wali.page, 'aksi-batalkan-wali');
    await wali.page.fill('#alasan_batal', 'Batal tanpa JS');
    await kirimDialog(wali.page, 'Batalkan pengajuan');
    const main = await isiMain(wali.page);
    cek(main.includes('Pengajuan dibatalkan') && main.includes('Batal tanpa JS') && main.includes('Tanpa JavaScript, sudah dilengkapi'),
      'C-G 375px tanpa JavaScript: minta perbaikan, perbaikan, teruskan, dan batal tersimpan');
    await pb.context.close();
    await wali.context.close();
  }
} finally {
  await browser.close();
}
cek(galat.length === 0, 'C-H tanpa galat JavaScript', galat.join(' | '));
cek(bawaan.length === 0, 'C-H tanpa confirm()/alert() bawaan', bawaan.join(' | '));
console.log(`\nLulus: ${lulus} · Gagal: ${gagal}`);
process.exit(gagal === 0 ? 0 : 1);
