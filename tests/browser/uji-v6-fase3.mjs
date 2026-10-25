/**
 * Uji browser PRD V6 Fase 3 — tabungan lengkap dan pembayaran silang
 * (setoran tunai/transfer, verifikasi, belanja, koreksi pembalik, bayar
 * tagihan dari tabungan, kuitansi tabungan, mutasi, rekonsiliasi).
 *
 * Melengkapi uji server `tests/v6_phase3_http.php` dengan perilaku yang hanya
 * terlihat di peramban, pada 375px, 768px, dan 1280px:
 *
 *   D-A  setiap halaman Fase 3 petugas, admin, dan wali: tanpa geser
 *        horizontal, satu h1, kerangka bersama, tabel menjadi kartu di HP,
 *        tombol utama ≥ 44 px, tanpa isian ID ketik tangan;
 *   D-B  setiap lebar (satu santri per lebar): setoran Rp100.000 (klik ganda
 *        di 375px tetap satu) + belanja Rp25.000 → Rp75.000; tagihan
 *        Rp100.000 dibayar Rp50.000 dari tabungan dengan persetujuan →
 *        saldo Rp25.000, sisa Rp50.000; referensi silang di kedua riwayat;
 *        kuitansi tabungan rapi; PDF satu halaman A4;
 *   D-C  setoran transfer tanpa centang → menunggu (saldo tetap) →
 *        verifikasi; transfer kedua ditolak lewat dialog beralasan;
 *   D-D  koreksi pembalik lewat dialog (dengan JavaScript sebagai dialog
 *        modal; tanpa JavaScript terbuka di tempat); tanpa JavaScript (375px):
 *        setoran, bayar dari tabungan, dan koreksi tetap berjalan;
 *   D-E  admin dan wali tidak melihat tombol pencatatan/verifikasi/koreksi;
 *        wali tanpa referensi transfer dan setoran menunggu; rekonsiliasi
 *        admin memisahkan pembayaran dari tabungan dari kas, selisih Rp0;
 *        tanpa galat JavaScript dan tanpa confirm()/alert().
 *
 *   V6F3_DB=test_v6_f3_browser_test bash tests/v6_phase3_db.sh
 *   DB_NAME=test_v6_f3_browser_test php -S 127.0.0.1:8996 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8996 node tests/browser/uji-v6-fase3.mjs
 *
 * Uji ini MENAMBAH transaksi pada DB uji. Bangun ulang DB sebelum mengulang.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';


const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8996';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-v6-fase3', import.meta.url).pathname;
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
  cek(masalah.length === 0, `D-A ${nama} ${lebar}px: rapi di kerangka bersama`, [...new Set(masalah)].join('; '));
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
async function transaksi(page, idS, jenis, isi, aksi = 'click') {
  await page.goto(BASE + `/portal/bsa_transaksi.php?santri_id=${idS}&jenis=${jenis}`);
  await page.fill('#nominal', isi.nominal);
  if (isi.belanja) await page.check('#jenis-belanja');
  if (isi.transfer) {
    await page.check('#metode-transfer');
    await page.fill('#referensi', isi.transfer);
  }
  if (isi.terverifikasi) await page.check('#terverifikasi');
  if (isi.tujuan) await page.fill('#tujuan', isi.tujuan);
  await kirim(page, jenis === 'setoran' ? 'Simpan setoran' : 'Simpan penarikan', /bsa_santri\.php\?id=\d+/, aksi);
}
async function bayarTabungan(page, idT, nominal) {
  await page.goto(BASE + `/portal/bsa_bayar_tabungan.php?tagihan_id=${idT}`);
  await page.fill('#nominal', nominal);
  await keDasar(page);
  await page.check('#setuju');
  await kirim(page, 'Bayar dari tabungan', /bsa_tagihan\.php\?id=\d+/);
}
const saldoTampil = async (page, idS) => {
  await page.goto(BASE + `/portal/bsa_santri.php?id=${idS}`);
  const t = (await teksMain(page)).replace(/\n/g, ' ');
  const m = t.match(/Saldo tabungan\s*(Rp[\d.]+)/i);
  return m ? m[1] : '';
};
async function bukaDialog(page, id) {
  await page.locator(`#${id} > summary`).click();
  await page.locator(`dialog[open], #${id}[open]`).first().waitFor();
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  let ids = {};
  {
    const { context, page } = await konteks(browser, 'sbx_v6_bsa_b', 1280);
    ids = { 375: await idSantri(page, 'SBX V6 Santri SMP'), 768: await idSantri(page, 'SBX V6 Santri Tsanawi'), 1280: await idSantri(page, 'SBX V6 Santri Tanpa Kelas') };
    await context.close();
  }
  const tagihan = {};
  // ------------------------------------------------------------- D-A, D-B per lebar
  for (const lebar of LEBAR) {
    const idS = ids[lebar];
    const { context, page } = await konteks(browser, 'sbx_v6_bsa_b', lebar);
    await transaksi(page, idS, 'setoran', { nominal: '100.000' }, lebar === 375 ? 'dblclick' : 'click');
    const nSetoran = (await page.locator('main table a[href*="bsa_tabungan_kuitansi.php"]').count());
    if (lebar === 375) cek(nSetoran === 1, 'D-B 375px: klik ganda Simpan setoran tetap satu transaksi', `${nSetoran}`);
    await transaksi(page, idS, 'penarikan', { nominal: '25.000', belanja: true, tujuan: `Kitab ${lebar}px` });
    cek((await saldoTampil(page, idS)) === 'Rp75.000', `D-B ${lebar}px: setoran Rp100.000 + belanja Rp25.000 → saldo Rp75.000`);
    const idT = await tagihanManual(page, idS, '2026-10', '100.000');
    tagihan[lebar] = idT;
    cek((await saldoTampil(page, idS)) === 'Rp75.000', `D-B ${lebar}px: tagihan terbit tanpa memotong tabungan otomatis`);
    await page.goto(BASE + `/portal/bsa_bayar_tabungan.php?tagihan_id=${idT}`);
    await page.fill('#nominal', '50.000');
    await keDasar(page);
    await page.getByRole('button', { name: 'Bayar dari tabungan', exact: true }).click();
    const tertahan = page.url().includes('bsa_bayar_tabungan.php') && (await page.locator('#setuju:invalid').count()) === 1;
    cek(tertahan && (await saldoTampil(page, idS)) === 'Rp75.000', `D-B ${lebar}px: tanpa centang persetujuan formulir tidak terkirim (wajib diisi), saldo tetap`);
    await page.goto(BASE + `/portal/bsa_bayar_tabungan.php?tagihan_id=${idT}`);
    await periksaLayar(page, 'formulir bayar dari tabungan', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/bayar-tabungan-375.png`, fullPage: true });
    await bayarTabungan(page, idT, '50.000');
    const tg = (await teksMain(page)).replace(/\n/g, ' ');
    const nomorKw = (tg.match(/BSA-KW-\d{4}-\d+/) || [''])[0];
    const nomorTb = (tg.match(/BSA-TB-\d{4}-\d+/) || [''])[0];
    cek(tg.includes('Tagihan dibayar dari tabungan') && /Sisa\s*Rp50\.000/i.test(tg) && tg.includes('Dari tabungan') && nomorKw !== '' && nomorTb !== '',
      `D-B ${lebar}px: bayar Rp50.000 dari tabungan → sisa Rp50.000; tagihan merujuk debit ${nomorTb}`);
    await periksaLayar(page, 'tagihan dibayar dari tabungan', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/tagihan-tabungan-375.png`, fullPage: true });
    cek((await saldoTampil(page, idS)) === 'Rp25.000' && (await teksMain(page)).includes(nomorKw), `D-B ${lebar}px: saldo Rp25.000; riwayat tabungan merujuk kuitansi tagihan ${nomorKw}`);
    await periksaLayar(page, 'rincian santri', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/santri-375.png`, fullPage: true });
    await page.locator(`main a:text-is("${nomorTb}")`).first().click();
    await page.waitForLoadState('networkidle');
    const kt = await page.locator('body').innerText();
    cek(kt.includes('Bukti pembayaran tagihan dari tabungan') && kt.includes(nomorKw) && kt.includes('Rp 50.000') && (await melebar(page)) <= 0, `D-B ${lebar}px: bukti debit tabungan terbuka rapi dan merujuk ${nomorKw}`);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/kuitansi-tabungan-375.png`, fullPage: true });
    if (lebar === 1280) {
      const pdf = await page.pdf({ path: `${OUT}/kuitansi-tabungan-a4.pdf`, format: 'A4', preferCSSPageSize: true });
      const halaman = (pdf.toString('latin1').match(/\/Type\s*\/Page[^s]/g) || []).length;
      cek(halaman === 1, 'D-B kuitansi tabungan dicetak/PDF tepat satu halaman A4', `${halaman} halaman`);
    }
    for (const [alamat, nama] of [['/portal/bsa_tabungan.php', 'mutasi tabungan'], ['/portal/bsa_tabungan.php?tab=transfer&status=semua', 'setoran transfer'], ['/portal/bsa_rekonsiliasi.php', 'rekonsiliasi'],
      [`/portal/bsa_transaksi.php?santri_id=${idS}&jenis=setoran`, 'formulir setoran'], [`/portal/bsa_transaksi.php?santri_id=${idS}&jenis=penarikan`, 'formulir pengeluaran']]) {
      await page.goto(BASE + alamat);
      await periksaLayar(page, `${nama} petugas`, lebar);
      if (lebar === 375) await page.screenshot({ path: `${OUT}/${nama.replaceAll(' ', '-')}-375.png`, fullPage: true });
    }
    await context.close();
  }

  // ------------------------------------------------------------- D-C setoran transfer (JS, 375)
  {
    const idS = ids[375];
    const { context, page } = await konteks(browser, 'sbx_v6_bsa_b', 375);
    await transaksi(page, idS, 'setoran', { nominal: '40.000', transfer: 'TRF-BROWSER-F3-1' });
    let t = (await teksMain(page)).replace(/\n/g, ' ');
    cek(t.includes('menunggu verifikasi') && /Saldo tabungan\s*Rp25\.000/i.test(t) && /Setoran menunggu\s*Rp40\.000/i.test(t), 'D-C 375px: transfer tanpa centang → menunggu verifikasi, saldo tetap Rp25.000');
    await page.screenshot({ path: `${OUT}/setoran-menunggu-375.png`, fullPage: true });
    await Promise.all([page.waitForURL(/bsa_santri\.php\?id=\d+/), page.getByRole('button', { name: 'Verifikasi dana masuk' }).first().click()]);
    await page.waitForLoadState('networkidle');
    t = (await teksMain(page)).replace(/\n/g, ' ');
    cek(t.includes('terverifikasi dan masuk saldo') && /Saldo tabungan\s*Rp65\.000/i.test(t), 'D-C 375px: verifikasi dana masuk → saldo Rp65.000');
    await transaksi(page, idS, 'setoran', { nominal: '9.000', transfer: 'TRF-BROWSER-F3-2' });
    const idTolak = await page.locator('details[id^="tolak-setoran-"]').first().getAttribute('id');
    await bukaDialog(page, idTolak);
    await page.locator(`#alasan-${idTolak.replace('tolak-setoran-', 'tolak-')}`).fill('Dana tidak ditemukan (uji browser)');
    await page.screenshot({ path: `${OUT}/dialog-tolak-375.png` });
    await Promise.all([page.waitForURL(/bsa_santri\.php\?id=\d+/), page.getByRole('button', { name: 'Tolak setoran' }).click()]);
    await page.waitForLoadState('networkidle');
    t = (await teksMain(page)).replace(/\n/g, ' ');
    cek(t.includes('ditolak. Saldo tidak berubah') && t.includes('Dana tidak ditemukan (uji browser)') && /Saldo tabungan\s*Rp65\.000/i.test(t), 'D-C 375px: penolakan beralasan lewat dialog → Ditolak, saldo tetap');

    // D-D koreksi belanja lewat dialog (JS)
    const nKoreksiAwal = await page.locator('details[id^="koreksi-"]').count();
    const idBelanja = await page.locator('main tr', { hasText: 'Belanja · Tunai' }).locator('details[id^="koreksi-"]').getAttribute('id');
    await bukaDialog(page, idBelanja);
    await page.locator(`#alasan-${idBelanja}`).fill('Kitab dikembalikan ke toko (uji browser)');
    await Promise.all([page.waitForURL(/bsa_santri\.php\?id=\d+/), page.getByRole('button', { name: 'Simpan koreksi' }).click()]);
    await page.waitForLoadState('networkidle');
    t = (await teksMain(page)).replace(/\n/g, ' ');
    cek(t.includes('Koreksi tercatat') && /Saldo tabungan\s*Rp90\.000/i.test(t) && t.includes('Sudah dikoreksi') && (await page.locator('details[id^="koreksi-"]').count()) === nKoreksiAwal - 1,
      `D-D 375px: koreksi belanja lewat dialog → pembalik masuk Rp25.000, saldo Rp90.000, cap "Sudah dikoreksi"`);
    await context.close();
  }

  // ------------------------------------------------------------- D-D tanpa JavaScript (375)
  {
    const idS = ids[768];
    const { context, page } = await konteks(browser, 'sbx_v6_bsa_b', 375, false);
    await transaksi(page, idS, 'setoran', { nominal: '30.000' });
    cek((await saldoTampil(page, idS)) === 'Rp55.000' && (await melebar(page)) <= 0, 'D-D 375px tanpa JavaScript: setoran Rp30.000 tersimpan (saldo Rp55.000)');
    await bayarTabungan(page, tagihan[768], '50.000');
    const tg = (await teksMain(page)).replace(/\n/g, ' ');
    cek(/Lunas/.test(tg) && tg.includes('Tagihan dibayar dari tabungan') && (await saldoTampil(page, idS)) === 'Rp5.000', 'D-D 375px tanpa JavaScript: bayar Rp50.000 dari tabungan → Lunas, saldo Rp5.000');
    await page.goto(BASE + `/portal/bsa_santri.php?id=${idS}`);
    const idBelanja = await page.locator('main tr', { hasText: 'Belanja · Tunai' }).locator('details[id^="koreksi-"]').getAttribute('id');
    await page.locator(`#${idBelanja} > summary`).click();
    await page.locator(`#alasan-${idBelanja}`).fill('Uji tanpa JavaScript');
    await keDasar(page);
    await Promise.all([page.waitForURL(/bsa_santri\.php\?id=\d+/), page.locator(`#${idBelanja}`).getByRole('button', { name: 'Simpan koreksi' }).click()]);
    cek((await saldoTampil(page, idS)) === 'Rp30.000' && (await melebar(page)) <= 0, 'D-D 375px tanpa JavaScript: dialog koreksi terbuka di tempat, pembalik tersimpan (saldo Rp30.000)');
    await page.screenshot({ path: `${OUT}/tanpa-js-santri-375.png`, fullPage: true });
    await context.close();
  }

  // ------------------------------------------------------------- D-E admin dan wali
  {
    const { context, page } = await konteks(browser, 'sbx_v6_bsa_b', 1280);
    await transaksi(page, ids[375], 'setoran', { nominal: '7.000', transfer: 'TRF-BROWSER-F3-3' });
    await context.close();
  }
  for (const [user, nama] of [['sbx_admin', 'admin'], ['sbx_v6_wali_smp', 'wali']]) {
    for (const lebar of LEBAR) {
      const { context, page } = await konteks(browser, user, lebar);
      for (const [alamat, judul] of [[`/portal/bsa_santri.php?id=${ids[375]}`, 'rincian santri'], [`/portal/bsa_tagihan.php?id=${tagihan[375]}`, 'rincian tagihan']]) {
        await page.goto(BASE + alamat);
        await periksaLayar(page, `${judul} ${nama}`, lebar);
        const html = await page.content();
        cek(!html.includes('bsa_tabungan_aksi.php') && !html.includes('bsa_bayar_tabungan.php') && !html.includes('bsa_transaksi.php') && (await page.locator('details[id^="koreksi-"]').count()) === 0,
          `D-E ${lebar}px: ${nama} tanpa tombol pencatatan/verifikasi/koreksi/bayar dari tabungan (${judul})`);
        if (lebar === 375) await page.screenshot({ path: `${OUT}/${judul.replaceAll(' ', '-')}-${nama}-375.png`, fullPage: true });
      }
      await page.goto(BASE + `/portal/bsa_santri.php?id=${ids[375]}`);
      const html = await page.content();
      const t = await teksMain(page);
      if (nama === 'wali') {
        cek(!html.includes('TRF-BROWSER-F3') && !t.includes('Setoran transfer') && !t.includes('Kitab dikembalikan ke toko') && t.includes('BSA-KW-'),
          `D-E ${lebar}px: wali tanpa referensi, setoran menunggu, atau alasan koreksi; referensi silang kuitansi tagihan tampil`);
      } else {
        cek(html.includes('TRF-BROWSER-F3-3') && t.includes('Setoran transfer') && t.includes('Hanya baca'), `D-E ${lebar}px: admin melihat setoran menunggu dan referensi, berlabel hanya baca`);
        await page.goto(BASE + '/portal/bsa_rekonsiliasi.php');
        await periksaLayar(page, 'rekonsiliasi admin', lebar);
        const r = (await teksMain(page)).replace(/\n/g, ' ');
        cek(/Selisih rekonsiliasi\s*Rp0/i.test(r) && /Pembayaran tagihan dari tabungan\s*Rp200\.000/i.test(r) && r.includes('Perpindahan internal, bukan kas') && r.includes('Belum dihitung'),
          `D-E ${lebar}px: rekonsiliasi admin — selisih Rp0; pembayaran dari tabungan Rp200.000 terpisah dari kas; transfer menunggu belum dihitung`);
        if (lebar === 375) await page.screenshot({ path: `${OUT}/rekonsiliasi-admin-375.png`, fullPage: true });
      }
      await context.close();
    }
  }
} finally {
  await browser.close();
}
cek(galat.length === 0, 'D-E tanpa galat JavaScript', galat.join(' | '));
cek(bawaan.length === 0, 'D-E tanpa confirm()/alert() bawaan', bawaan.join(' | '));
console.log(`\nLulus: ${lulus} · Gagal: ${gagal}`);
process.exit(gagal === 0 ? 0 : 1);
