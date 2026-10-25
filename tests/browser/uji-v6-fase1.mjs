/**
 * Uji browser PRD V6 Fase 1 — keuangan santri/BSA (daftar, rincian,
 * formulir setoran/penarikan/tagihan, rincian tagihan).
 *
 * Melengkapi uji server `tests/v6_phase1_http.php` dengan perilaku yang hanya
 * terlihat di peramban, pada 375px, 768px, dan 1280px:
 *
 *   B-A  daftar/rincian/formulir/tagihan petugas, admin, dan wali: tanpa
 *        geser horizontal, satu h1, kerangka bersama, tabel menjadi kartu di
 *        HP, tombol utama ≥ 44 px, tanpa isian ID ketik tangan;
 *   B-B  setiap lebar: setoran lalu penarikan lewat formulir (saldo berubah
 *        tepat), satu tagihan diterbitkan lewat formulir lalu dibaca ulang;
 *   B-C  klik ganda tombol Simpan setoran → tetap satu transaksi;
 *   B-D  tanpa JavaScript (375px): setoran dan tagihan tetap tersimpan sekali;
 *   B-E  admin dan wali tidak melihat tombol/tautan pencatatan; tanpa galat
 *        JavaScript dan tanpa confirm()/alert() bawaan.
 *
 *   V6F1_DB=test_v6_f1_browser_test bash tests/v6_phase1_db.sh
 *   DB_NAME=test_v6_f1_browser_test php -S 127.0.0.1:8998 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8998 node tests/browser/uji-v6-fase1.mjs
 *
 * Uji ini MENAMBAH transaksi dan tagihan pada DB uji. Bangun ulang DB sebelum mengulang.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';


const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8998';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-v6-fase1', import.meta.url).pathname;
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
async function rincian(page, id) {
  await page.goto(BASE + '/portal/bsa_santri.php?id=' + id);
  const teks = await page.locator('main').innerText();
  const m = teks.match(/(\d+) transaksi/);
  // Label statistik ditampilkan kapital lewat CSS; nilai saldo dibaca dari kartu ringkasan.
  const saldo = Number(((await page.locator('.ah-stat').first().innerText()).match(/Rp([\d.]+)/) || [0, '-1'])[1].replaceAll('.', ''));
  return { teks, transaksi: m ? Number(m[1]) : -1, saldo };
}
async function setoran(page, id, nominal, aksi = 'click') {
  await page.goto(BASE + `/portal/bsa_transaksi.php?santri_id=${id}&jenis=setoran`);
  await page.fill('#nominal', nominal);
  const tombol = page.getByRole('button', { name: 'Simpan setoran' });
  await keDasar(page);
  await Promise.all([page.waitForURL(/bsa_santri\.php\?id=\d+/), aksi === 'dblclick' ? tombol.dblclick() : tombol.click()]);
  await page.waitForLoadState('networkidle');
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  // ------------------------------------------------------------- B-A, B-B
  const periode = { 375: '2026-10', 768: '2026-11', 1280: '2026-12' };
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, 'sbx_v6_bsa_b', lebar);
    await page.goto(BASE + '/portal/bsa.php');
    await periksaLayar(page, 'daftar santri petugas', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/daftar-375.png`, fullPage: true });
    const id = await idSantri(page, 'SBX V6 Santri SMP');
    const awal = await rincian(page, id);
    const saldoAwal = awal.saldo;
    await periksaLayar(page, 'rincian santri petugas', lebar);
    await page.goto(BASE + `/portal/bsa_transaksi.php?santri_id=${id}&jenis=setoran`);
    await periksaLayar(page, 'formulir setoran', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/formulir-setoran-375.png`, fullPage: true });
    await setoran(page, id, '100.000');
    await page.goto(BASE + `/portal/bsa_transaksi.php?santri_id=${id}&jenis=penarikan`);
    await periksaLayar(page, 'formulir penarikan', lebar);
    await page.fill('#nominal', '30000');
    await page.fill('#tujuan', `Beli kitab ${lebar}px ` + 'panjangtanpaspasi'.repeat(8));
    await Promise.all([page.waitForURL(/bsa_santri\.php\?id=\d+/), page.getByRole('button', { name: 'Simpan penarikan' }).click()]);
    const sesudah = await rincian(page, id);
    cek(saldoAwal >= 0 && sesudah.transaksi === awal.transaksi + 2 && sesudah.saldo === saldoAwal + 70000,
      `B-B ${lebar}px: setoran Rp100.000 + penarikan Rp30.000 → saldo ${rupiah(saldoAwal + 70000)}`, sesudah.teks.slice(0, 300));
    await periksaLayar(page, 'rincian sesudah transaksi', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/rincian-375.png`, fullPage: true });

    await page.goto(BASE + `/portal/bsa_tagihan_buat.php?santri_id=${id}`);
    await periksaLayar(page, 'formulir tagihan', lebar);
    await page.fill('#periode', periode[lebar]);
    await page.fill('#jatuh_tempo', periode[lebar] + '-10');
    await page.fill('#komponen-0-nama', 'Syahriah uji');
    await page.fill('#komponen-0-nominal', '250.000');
    await page.fill('#komponen-1-nama', 'Makan uji');
    await page.fill('#komponen-1-nominal', '300000');
    if (lebar === 375) await page.screenshot({ path: `${OUT}/formulir-tagihan-375.png`, fullPage: true });
    await Promise.all([page.waitForURL(/bsa_tagihan\.php\?id=\d+/), page.getByRole('button', { name: 'Terbitkan tagihan' }).click()]);
    const tagihan = await page.locator('main').innerText();
    cek(tagihan.includes('Rp550.000') && tagihan.includes('Syahriah uji') && tagihan.includes('Belum dibayar'), `B-B ${lebar}px: tagihan Rp550.000 terbit lalu dibaca ulang`);
    await periksaLayar(page, 'rincian tagihan', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/tagihan-375.png`, fullPage: true });

    // B-C klik ganda
    if (lebar === 375) {
      const sebelum = await rincian(page, id);
      await setoran(page, id, '5000', 'dblclick');
      const ganda = await rincian(page, id);
      cek(ganda.transaksi === sebelum.transaksi + 1, 'B-C 375px: klik ganda Simpan setoran tetap satu transaksi', `${sebelum.transaksi} → ${ganda.transaksi}`);
    }
    await context.close();
  }

  // ------------------------------------------------------------- B-D
  {
    const { context, page } = await konteks(browser, 'sbx_v6_bsa_b', 375, false);
    const id = await idSantri(page, 'SBX V6 Santri Tsanawi');
    const awal = await rincian(page, id);
    await setoran(page, id, '12.500');
    const akhir = await rincian(page, id);
    cek(akhir.transaksi === awal.transaksi + 1 && akhir.saldo === awal.saldo + 12500 && await melebar(page) <= 0, 'B-D 375px tanpa JavaScript: setoran tersimpan sekali dan rincian rapi');
    await page.goto(BASE + `/portal/bsa_tagihan_buat.php?santri_id=${id}`);
    await page.fill('#periode', '2026-10');
    await page.fill('#jatuh_tempo', '2026-10-10');
    await page.fill('#komponen-0-nama', 'Syahriah uji');
    await page.fill('#komponen-0-nominal', '100000');
    await keDasar(page);
    await Promise.all([page.waitForURL(/bsa_tagihan\.php\?id=\d+/), page.getByRole('button', { name: 'Terbitkan tagihan' }).click()]);
    cek((await page.locator('main').innerText()).includes('Rp100.000'), 'B-D 375px tanpa JavaScript: tagihan terbit');
    await context.close();
  }

  // ------------------------------------------------------------- B-E admin dan wali
  for (const [user, nama] of [['sbx_admin', 'admin'], ['sbx_v6_wali_smp', 'wali']]) {
    for (const lebar of LEBAR) {
      const { context, page } = await konteks(browser, user, lebar);
      await page.goto(BASE + '/portal/bsa.php' + (nama === 'admin' ? '?q=SBX+V6' : ''));
      await periksaLayar(page, `daftar ${nama}`, lebar);
      const tautan = page.locator('main a[href*="bsa_santri.php?id="]', { hasText: 'SBX V6 Santri SMP' }).first();
      await Promise.all([page.waitForURL(/bsa_santri\.php/), tautan.click()]);
      await periksaLayar(page, `rincian ${nama}`, lebar);
      const main = await page.locator('main').innerText();
      cek(await page.locator('main a[href*="bsa_transaksi.php"], main a[href*="bsa_tagihan_buat.php"]').count() === 0 && /saldo tabungan/i.test(main),
        `B-E ${lebar}px: ${nama} membaca rincian tanpa tombol/tautan pencatatan`);
      if (nama === 'wali') cek(!main.includes('Ref.'), `B-E ${lebar}px: wali tidak melihat referensi transfer`);
      if (lebar === 375) await page.screenshot({ path: `${OUT}/rincian-${nama}-375.png`, fullPage: true });
      const tagihan = page.locator('main a[href*="bsa_tagihan.php?id="]').first();
      if (await tagihan.count()) {
        await Promise.all([page.waitForURL(/bsa_tagihan\.php/), tagihan.click()]);
        await periksaLayar(page, `rincian tagihan ${nama}`, lebar);
      }
      await context.close();
    }
  }
} finally {
  await browser.close();
}
cek(galat.length === 0, 'B-E tanpa galat JavaScript', galat.join(' | '));
cek(bawaan.length === 0, 'B-E tanpa confirm()/alert() bawaan', bawaan.join(' | '));
console.log(`\nLulus: ${lulus} · Gagal: ${gagal}`);
process.exit(gagal === 0 ? 0 : 1);
