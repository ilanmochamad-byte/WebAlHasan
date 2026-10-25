/**
 * Uji browser PRD V6 Fase 2 — tagihan bulanan lengkap (tarif, penyesuaian,
 * pratinjau/penerbitan massal, pembayaran, kuitansi, tunggakan, koreksi).
 *
 * Melengkapi uji server `tests/v6_phase2_http.php` dengan perilaku yang hanya
 * terlihat di peramban, pada 375px, 768px, dan 1280px:
 *
 *   C-A  setiap halaman Fase 2 petugas, admin, dan wali: tanpa geser
 *        horizontal, satu h1, kerangka bersama, tabel menjadi kartu di HP,
 *        tombol utama ≥ 44 px, tanpa isian ID ketik tangan;
 *   C-B  petugas lewat formulir: tarif, penyesuaian, pratinjau → terbitkan;
 *   C-C  setiap lebar: tagihan Rp100.000 dibayar Rp40.000 lalu Rp60.000 →
 *        Lunas, sisa Rp0; kuitansi terbuka rapi; klik ganda Simpan
 *        pembayaran tetap satu pembayaran;
 *   C-D  dialog revisi dan pembatalan pembayaran (dengan JavaScript sebagai
 *        dialog modal; tanpa JavaScript terbuka di tempat) tersimpan sekali;
 *   C-E  tanpa JavaScript (375px): pratinjau → terbit, pembayaran, dan
 *        pembatalan beralasan tetap berjalan;
 *   C-F  admin dan wali tidak melihat tombol pencatatan/koreksi; wali tanpa
 *        referensi transfer; tanpa galat JavaScript dan tanpa confirm()/alert().
 *
 *   V6F2_DB=test_v6_f2_browser_test bash tests/v6_phase2_db.sh
 *   DB_NAME=test_v6_f2_browser_test php -S 127.0.0.1:8997 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8997 node tests/browser/uji-v6-fase2.mjs
 *
 * Uji ini MENAMBAH tarif, tagihan, dan pembayaran pada DB uji. Bangun ulang DB sebelum mengulang.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';


const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8997';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-v6-fase2', import.meta.url).pathname;
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
const rupiah = (n) => 'Rp' + n.toLocaleString('id-ID');
// Tanpa JavaScript, gulir otomatis Playwright dapat berhenti saat tombol kirim
// tepat di belakang bilah bawah HP; pengguna menggulir sampai dasar (padding
// bawah kerangka memberi ruang), jadi uji melakukan hal yang sama.
const keDasar = (page) => page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
async function idSantri(page, nama) {
  await page.goto(BASE + '/portal/bsa.php?q=' + encodeURIComponent(nama));
  const href = await page.locator('main a[href*="bsa_santri.php?id="]', { hasText: nama }).first().getAttribute('href');
  return Number(new URL(href, BASE).searchParams.get('id'));
}
const idDari = (url, kunci = 'id') => Number(new URL(url).searchParams.get(kunci));
const teksMain = (page) => page.locator('main').innerText();
async function kirim(page, nama, pola, aksi = 'click') {
  const tombol = page.getByRole('button', { name: nama, exact: true }).last();
  await keDasar(page);
  await Promise.all([page.waitForURL(pola), aksi === 'dblclick' ? tombol.dblclick() : tombol.click()]);
  await page.waitForLoadState('networkidle');
}
async function tagihanManual(page, idSantriTanpa, periode, nominal) {
  await page.goto(BASE + `/portal/bsa_tagihan_buat.php?santri_id=${idSantriTanpa}`);
  await page.fill('#periode', periode);
  await page.fill('#jatuh_tempo', periode + '-10');
  await page.fill('#komponen-0-nama', 'Syahriah browser');
  await page.fill('#komponen-0-nominal', nominal);
  await kirim(page, 'Terbitkan tagihan', /bsa_tagihan\.php\?id=\d+/);
  return idDari(page.url());
}
async function bayar(page, idTagihan, nominal, aksi = 'click', transfer = null) {
  await page.goto(BASE + `/portal/bsa_bayar.php?tagihan_id=${idTagihan}`);
  await page.fill('#nominal', nominal);
  if (transfer) {
    await page.check('#sumber-transfer');
    await page.fill('#referensi', transfer);
  }
  await kirim(page, 'Simpan pembayaran', /bsa_tagihan\.php\?id=\d+/, aksi);
}
const jumlahPembayaran = async (page, idTagihan) => {
  await page.goto(BASE + `/portal/bsa_tagihan.php?id=${idTagihan}`);
  const m = (await teksMain(page)).match(/(\d+) pembayaran/);
  return m ? Number(m[1]) : -1;
};
async function tarif(page, unit, mulai, komponen, alasan = '') {
  await page.goto(BASE + '/portal/bsa_tarif.php');
  await page.selectOption('#unit', unit);
  await page.fill('#berlaku_mulai', mulai);
  for (const [i, [nama, nominal]] of komponen.entries()) {
    await page.fill(`#komponen-${i}-nama`, nama);
    await page.fill(`#komponen-${i}-nominal`, nominal);
  }
  if (alasan) await page.fill('#alasan', alasan);
  await kirim(page, 'Simpan tarif', /bsa_tarif\.php/);
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  // ------------------------------------------------------------- C-B persiapan
  let idSmp = 0;
  let idTsanawi = 0;
  let idTanpa = 0;
  let idTagihanSmp = 0;
  {
    const { context, page } = await konteks(browser, 'sbx_v6_bsa_b', 1280);
    idSmp = await idSantri(page, 'SBX V6 Santri SMP');
    idTsanawi = await idSantri(page, 'SBX V6 Santri Tsanawi');
    idTanpa = await idSantri(page, 'SBX V6 Santri Tanpa Kelas');
    await tarif(page, 'SMP', '2026-10', [['Syahriah browser', '300.000'], ['Makan browser', '200.000']]);
    await tarif(page, 'Tsanawi', '2026-10', [['Syahriah browser', '250.000']]);
    const t = await teksMain(page);
    cek(t.includes('Rp500.000') && t.includes('Rp250.000') && t.includes('Berlaku'), 'C-B tarif SMP Rp500.000 dan Tsanawi Rp250.000 tersimpan lewat formulir');
    await page.goto(BASE + `/portal/bsa_penyesuaian.php?santri_id=${idSmp}`);
    await page.fill('#nama', 'Potongan browser');
    await page.fill('#nominal', '100.000');
    await page.fill('#berlaku_mulai', '2026-10');
    await page.fill('#alasan', 'Santri berprestasi (uji browser)');
    await kirim(page, 'Simpan penyesuaian', /bsa_penyesuaian\.php/);
    cek((await teksMain(page)).includes('Potongan browser'), 'C-B penyesuaian santri beralasan tersimpan');
    await page.goto(BASE + '/portal/bsa_penerbitan.php');
    await page.fill('#periode', '2026-10');
    await page.fill('#jatuh_tempo', '2026-10-10');
    await Promise.all([page.waitForURL(/pratinjau=1/), page.getByRole('button', { name: 'Lihat pratinjau' }).click()]);
    const pr = await teksMain(page);
    const n = Number((pr.match(/Terbitkan (\d+) tagihan/) || [0, 0])[1]);
    cek(n > 1 && pr.includes('Rp400.000') && /dilewati: Belum ditempatkan di kelas/.test(pr), `C-B pratinjau: ${n} tagihan akan terbit; santri berpenyesuaian Rp400.000; yang dilewati dikelompokkan`);
    await page.screenshot({ path: `${OUT}/pratinjau-1280.png`, fullPage: true });
    await kirim(page, `Terbitkan ${n} tagihan`, /bsa_penerbitan\.php\?id=\d+/, 'dblclick');
    const hasil = await teksMain(page);
    cek(hasil.includes(`${n} tagihan terbit`) || hasil.includes('sudah dijalankan sebelumnya'), 'C-B terbitkan (klik ganda) → satu penerbitan');
    await page.goto(BASE + '/portal/bsa_penerbitan.php');
    cek((await page.locator('main a[href*="bsa_penerbitan.php?id="]').count()) === 1, 'C-B riwayat penerbitan berisi tepat satu penerbitan');
    await page.goto(BASE + `/portal/bsa_santri.php?id=${idSmp}`);
    const href = await page.locator('main a[href*="bsa_tagihan.php?id="]').first().getAttribute('href');
    idTagihanSmp = Number(new URL(href, BASE).searchParams.get('id'));
    await context.close();
  }

  // ------------------------------------------------------------- C-A, C-C, C-D per lebar
  const periode = { 375: '2026-11', 768: '2026-12', 1280: '2027-01' };
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, 'sbx_v6_bsa_b', lebar);
    for (const [alamat, nama] of [['/portal/bsa_tarif.php', 'tarif'], ['/portal/bsa_penerbitan.php?pratinjau=1&periode=2026-11&jatuh_tempo=2026-11-10', 'pratinjau'],
      [`/portal/bsa_penyesuaian.php?santri_id=${idSmp}`, 'penyesuaian'], ['/portal/bsa_tunggakan.php', 'tunggakan'], [`/portal/bsa_tagihan.php?id=${idTagihanSmp}`, 'rincian tagihan'],
      [`/portal/bsa_bayar.php?tagihan_id=${idTagihanSmp}`, 'formulir pembayaran']]) {
      await page.goto(BASE + alamat);
      await periksaLayar(page, `${nama} petugas`, lebar);
      if (lebar === 375) await page.screenshot({ path: `${OUT}/${nama.replaceAll(' ', '-')}-375.png`, fullPage: true });
    }
    const idT = await tagihanManual(page, idTanpa, periode[lebar], '100.000');
    await bayar(page, idT, '40.000', lebar === 375 ? 'dblclick' : 'click');
    const satu = await jumlahPembayaran(page, idT);
    if (lebar === 375) cek(satu === 1, 'C-C 375px: klik ganda Simpan pembayaran tetap satu pembayaran', `${satu}`);
    await bayar(page, idT, '60.000');
    const t = await teksMain(page);
    cek(/Lunas/.test(t) && /sisa\s*Rp0/i.test(t.replace(/\n/g, ' ')) && (await page.locator('main a[href*="bsa_kuitansi.php?id="]').count()) >= 2,
      `C-C ${lebar}px: Rp40.000 + Rp60.000 atas Rp100.000 → Lunas, sisa Rp0, dua kuitansi`, t.slice(0, 300));
    await periksaLayar(page, 'tagihan lunas', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/tagihan-lunas-375.png`, fullPage: true });
    const kw = await page.locator('main table a[href*="bsa_kuitansi.php?id="]').first().getAttribute('href');
    await page.goto(new URL(kw, BASE).href);
    const isiKw = await page.locator('body').innerText();
    cek(isiKw.includes('Kuitansi pembayaran tagihan bulanan') && isiKw.includes('Rp 40.000') && (await melebar(page)) <= 0, `C-C ${lebar}px: kuitansi Rp40.000 terbuka tanpa geser horizontal`);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/kuitansi-375.png`, fullPage: true });
    if (lebar === 1280) {
      const pdf = await page.pdf({ path: `${OUT}/kuitansi-a4.pdf`, format: 'A4', preferCSSPageSize: true });
      const halaman = (pdf.toString('latin1').match(/\/Type\s*\/Page[^s]/g) || []).length;
      cek(halaman === 1, 'C-C kuitansi dicetak/PDF tepat satu halaman A4', `${halaman} halaman`);
    }

    // C-D revisi lewat dialog
    await page.goto(BASE + `/portal/bsa_tagihan.php?id=${idTagihanSmp}`);
    await page.locator('#revisi-tagihan > summary').click();
    const dialog = page.locator('dialog[open], #revisi-tagihan[open]').first();
    await dialog.waitFor();
    await page.check('#revisi-tambahan');
    await page.fill('#revisi-nama', `Kitab ${lebar}px`);
    await page.fill('#revisi-nominal', '10.000');
    await page.fill('#revisi-alasan', `Kitab tambahan uji ${lebar}px`);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/dialog-revisi-375.png` });
    await Promise.all([page.waitForURL(/bsa_tagihan\.php\?id=\d+/), page.getByRole('button', { name: 'Simpan revisi' }).click()]);
    await page.waitForLoadState('networkidle');
    const rv = await teksMain(page);
    cek(rv.includes(`Kitab ${lebar}px`) && rv.includes('Revisi tersimpan'), `C-D ${lebar}px: revisi lewat dialog tersimpan dan tampil di rincian biaya`);
    await periksaLayar(page, 'tagihan sesudah revisi', lebar);
    await context.close();
  }

  // ------------------------------------------------------------- C-D pembatalan lewat dialog (JS)
  {
    const { context, page } = await konteks(browser, 'sbx_v6_bsa_b', 375);
    const idT = await tagihanManual(page, idTanpa, '2027-02', '50.000');
    await bayar(page, idT, '50.000');
    const sebelum = await jumlahPembayaran(page, idT);
    await page.locator('details[id^="batal-bayar-"] > summary').first().click();
    await page.locator('input[id^="alasan-bayar-"]').first().fill('Salah catat santri (uji browser)');
    await Promise.all([page.waitForURL(/bsa_tagihan\.php\?id=\d+/), page.getByRole('button', { name: 'Batalkan pembayaran' }).click()]);
    await page.waitForLoadState('networkidle');
    const t = await teksMain(page);
    cek(t.includes('dibatalkan') && /Dibatalkan/.test(t) && /sisa\s*Rp50\.000/i.test(t.replace(/\n/g, ' ')) && (await jumlahPembayaran(page, idT)) === sebelum,
      'C-D 375px: pembatalan beralasan lewat dialog → status Dibatalkan, sisa kembali Rp50.000, baris tetap');
    await context.close();
  }

  // ------------------------------------------------------------- C-E tanpa JavaScript
  {
    const { context, page } = await konteks(browser, 'sbx_v6_bsa_tsanawi', 375, false);
    await page.goto(BASE + '/portal/bsa_penerbitan.php?pratinjau=1&periode=2026-11&jatuh_tempo=2026-11-10');
    const pr = await teksMain(page);
    const n = Number((pr.match(/Terbitkan (\d+) tagihan/) || [0, 0])[1]);
    await kirim(page, `Terbitkan ${n} tagihan`, /bsa_penerbitan\.php\?id=\d+/);
    cek(n > 0 && (await teksMain(page)).includes(`${n} tagihan terbit`) && (await melebar(page)) <= 0, `C-E 375px tanpa JavaScript: pratinjau → terbit ${n} tagihan unit Tsanawi`);
    await page.goto(BASE + `/portal/bsa_santri.php?id=${idTsanawi}`);
    const href = await page.locator('main a[href*="bsa_tagihan.php?id="]').first().getAttribute('href');
    const idT = Number(new URL(href, BASE).searchParams.get('id'));
    await bayar(page, idT, '25.000');
    cek((await jumlahPembayaran(page, idT)) === 1, 'C-E 375px tanpa JavaScript: pembayaran tersimpan sekali');
    await page.locator('details[id^="batal-bayar-"] > summary').first().click();
    await page.locator('input[id^="alasan-bayar-"]').first().fill('Uji tanpa JavaScript');
    await keDasar(page);
    await Promise.all([page.waitForURL(/bsa_tagihan\.php\?id=\d+/), page.getByRole('button', { name: 'Batalkan pembayaran' }).click()]);
    cek(/Dibatalkan/.test(await teksMain(page)) && (await melebar(page)) <= 0, 'C-E 375px tanpa JavaScript: dialog terbuka di tempat, pembatalan beralasan tersimpan');
    await page.screenshot({ path: `${OUT}/tanpa-js-tagihan-375.png`, fullPage: true });
    await context.close();
  }

  // ------------------------------------------------------------- C-F admin dan wali
  {
    const { context, page } = await konteks(browser, 'sbx_v6_bsa_b', 1280);
    await bayar(page, idTagihanSmp, '150.000', 'click', 'TRF-BROWSER-1');
    await context.close();
  }
  for (const [user, nama] of [['sbx_admin', 'admin'], ['sbx_v6_wali_smp', 'wali']]) {
    for (const lebar of LEBAR) {
      const { context, page } = await konteks(browser, user, lebar);
      for (const [alamat, judul] of [['/portal/bsa_tunggakan.php', 'tunggakan'], ['/portal/bsa_pembayaran.php', 'pembayaran'], [`/portal/bsa_tagihan.php?id=${idTagihanSmp}`, 'rincian tagihan']]) {
        await page.goto(BASE + alamat);
        await periksaLayar(page, `${judul} ${nama}`, lebar);
        if (lebar === 375) await page.screenshot({ path: `${OUT}/${judul.replaceAll(' ', '-')}-${nama}-375.png`, fullPage: true });
      }
      const html = await page.content();
      cek(!html.includes('bsa_bayar.php') && !html.includes('bsa_tagihan_aksi.php') && (await page.locator('#revisi-tagihan').count()) === 0,
        `C-F ${lebar}px: ${nama} tanpa tombol pembayaran/koreksi`);
      if (nama === 'wali') {
        const t = await teksMain(page);
        cek(!html.includes('TRF-BROWSER-1') && !t.includes('Riwayat versi') && t.includes('Potongan browser') && !t.includes('Santri berprestasi'),
          `C-F ${lebar}px: wali melihat baris potongan tanpa referensi, alasan, atau riwayat internal`);
      } else {
        cek(html.includes('TRF-BROWSER-1') && (await teksMain(page)).includes('Hanya baca'), `C-F ${lebar}px: admin melihat referensi transfer, berlabel hanya baca`);
      }
      await context.close();
    }
  }
} finally {
  await browser.close();
}
cek(galat.length === 0, 'C-F tanpa galat JavaScript', galat.join(' | '));
cek(bawaan.length === 0, 'C-F tanpa confirm()/alert() bawaan', bawaan.join(' | '));
console.log(`\nLulus: ${lulus} · Gagal: ${gagal}`);
process.exit(gagal === 0 ? 0 : 1);
