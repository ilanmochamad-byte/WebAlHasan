/**
 * Uji browser PRD V5 Fase 3 — ringkasan orang tua dan notifikasi.
 *
 * Melengkapi uji server `tests/v5_phase3_http.php` dengan perilaku yang hanya
 * terlihat di peramban, pada 375px, 768px, dan 1280px:
 *
 *   E-A  petugas: detail kunjungan → Susun ringkasan → Pratinjau (persis
 *        tampilan wali, tanpa catatan klinis) → konfirmasi → Terbitkan; tanpa
 *        geser horizontal, satu h1, kerangka bersama, tombol utama ≥ 44 px;
 *        klik ganda Terbitkan tetap satu versi;
 *   E-B  wali: menu "Kesehatan anak", daftar dan detail rapi; notifikasi
 *        generik → ketuk → halaman ringkasan; wali lain ditolak;
 *   E-C  petugas mengganti (alasan wajib) lalu menarik lewat dialog; wali
 *        melihat versi baru lalu keterangan "ditarik";
 *   E-D  tanpa JavaScript (375px): susun → pratinjau → terbit, dan tarik
 *        lewat dialog <details> berfungsi;
 *   E-E  admin dan pembimbing: halaman ringkasan hanya baca;
 *        tanpa galat JavaScript dan tanpa confirm()/alert() bawaan.
 *
 *   V5F3_DB=test_v5_f3_browser_test bash tests/v5_phase3_db.sh
 *   DB_NAME=test_v5_f3_browser_test php -S 127.0.0.1:8996 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8996 OUT_DIR=docs/phase-v5-3/tangkapan node tests/browser/uji-v5-fase3.mjs
 *
 * Uji ini MENAMBAH kunjungan dan ringkasan pada DB uji. Bangun ulang DB
 * sebelum mengulang.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8996';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-v5-fase3', import.meta.url).pathname;
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
  for (const tombol of await page.locator('main form .btn-primary, .ah-page-head__actions .btn').all()) {
    if (await tombol.isVisible()) {
      const kotak = await tombol.boundingBox();
      if (kotak && kotak.height < 43.5) masalah.push(`tombol ${Math.round(kotak.height)}px`);
    }
  }
  cek(masalah.length === 0, `E-A ${nama} ${lebar}px: rapi di kerangka bersama`, [...new Set(masalah)].join('; '));
}
const idDari = (url) => Number(new URL(url).searchParams.get('id'));
async function buatKunjungan(page, penanda) {
  await page.goto(BASE + '/portal/kesehatan_buat.php?q=V5S0201');
  const nilai = await page.locator('#santri_id option').evaluateAll((os) => (os.find((o) => o.textContent.includes('V5S0201')) || {}).value);
  await page.selectOption('#santri_id', nilai);
  await page.fill('#keluhan', `${penanda}-KELUHAN demam`);
  await page.fill('#obat-0-nama', `${penanda}-OBAT`);
  await Promise.all([page.waitForURL(/kesehatan_detail\.php\?id=\d+/), page.getByRole('button', { name: 'Simpan kunjungan' }).click()]);
  return idDari(page.url());
}
async function susunDanPratinjau(page, kid, teks, tl, alasan = null) {
  await page.goto(BASE + '/portal/kesehatan_ringkasan.php?id=' + kid);
  await page.fill('#ringkasan', teks);
  await page.fill('#tindakan-umum', 'Istirahat di klinik');
  await page.fill('#tindak-lanjut-wali', tl);
  if (alasan !== null) await page.fill('#alasan-ganti', alasan);
  await page.getByRole('button', { name: 'Lihat pratinjau' }).click();
  await page.waitForLoadState('domcontentloaded');
}
const versiRingkasan = async (page, kid) => {
  await page.goto(BASE + '/portal/kesehatan_ringkasan.php?id=' + kid);
  return Number(((await page.locator('main').innerText()).match(/Versi\s+(\d+)\s+·\s+terbit/) || [])[1] || 0);
};

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
const terbit = {};
try {
  // ----------------------------------------------------------------- E-A
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, 'sbx_v5_petugas_f', lebar);
    const penanda = `E${lebar}-${Date.now()}`;
    const kid = await buatKunjungan(page, penanda);
    const detail = await page.locator('main').innerText();
    cek(detail.includes('Ringkasan untuk wali') && detail.includes('Belum terbit'), `E-A ${lebar}px: detail kunjungan memuat bagian Ringkasan untuk wali`);
    await Promise.all([page.waitForURL(/kesehatan_ringkasan\.php/), page.getByRole('link', { name: 'Susun ringkasan untuk wali' }).click()]);
    cek(await page.locator('#ringkasan').inputValue() === '' && !(await page.locator('main').innerText()).includes(`${penanda}-KELUHAN`), `E-A ${lebar}px: formulir ringkasan kosong, terpisah dari catatan klinis`);
    await periksaLayar(page, 'formulir susun ringkasan', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/susun-375.png`, fullPage: true });
    const teks = `${penanda}-RINGKASAN Ananda sempat demam, sudah ditangani dan kini beristirahat ` + 'panjangtanpaspasi'.repeat(6);
    await susunDanPratinjau(page, kid, teks, 'Mohon pantau suhu tubuh di rumah.');
    const pratinjau = await page.locator('main').innerText();
    cek(pratinjau.includes('Pratinjau · persis seperti dilihat wali') && pratinjau.includes(`${penanda}-RINGKASAN`) && pratinjau.includes('Yang perlu diperhatikan orang tua')
      && !pratinjau.includes(`${penanda}-KELUHAN`) && !pratinjau.includes(`${penanda}-OBAT`), `E-A ${lebar}px: pratinjau menampilkan tampilan wali tanpa catatan klinis`);
    await periksaLayar(page, 'pratinjau ringkasan', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/pratinjau-375.png`, fullPage: true });
    await page.check('#konfirmasi');
    await Promise.all([page.waitForURL(/kesehatan_ringkasan\.php\?id=\d+$/), page.getByRole('button', { name: 'Terbitkan' }).dblclick()]);
    await page.waitForLoadState('networkidle');
    const sesudah = await page.locator('main').innerText();
    cek(sesudah.includes('Yang dilihat wali saat ini') && sesudah.includes(`${penanda}-RINGKASAN`) && await versiRingkasan(page, kid) === 1, `E-A ${lebar}px: terbit (klik ganda) → tepat versi 1`);
    cek(((await page.locator('main').innerText()).match(/Diterbitkan/g) || []).length === 1, `E-A ${lebar}px: riwayat hanya satu peristiwa terbit`);
    await periksaLayar(page, 'ringkasan terbit (petugas)', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/terbit-petugas-375.png`, fullPage: true });
    terbit[lebar] = { kid, penanda };
    await context.close();
  }

  // ----------------------------------------------------------------- E-B
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, 'sbx_v5_wali_1', lebar);
    cek(await page.locator('a[href*="/portal/kesehatan_wali.php"]').count() > 0, `E-B ${lebar}px: menu "Kesehatan anak" tersedia bagi wali`);
    await page.goto(BASE + '/portal/kesehatan_wali.php');
    const daftar = await page.locator('main').innerText();
    cek(daftar.includes('Informasi kesehatan') && daftar.includes('ada yang perlu diperhatikan') && await page.locator('main a[href*="kesehatan_wali.php?id="]').count() >= 3, `E-B ${lebar}px: daftar memuat ringkasan terbit anak`);
    await periksaLayar(page, 'daftar kesehatan anak', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/wali-daftar-375.png`, fullPage: true });
    await page.goto(BASE + '/portal/notifikasi.php?jenis=kesehatan');
    const notif = await page.locator('main').innerText();
    cek(notif.includes('Informasi kesehatan') && !notif.includes('SBX V5 Wali Santri') && !notif.includes('RINGKASAN'), `E-B ${lebar}px: notifikasi generik tanpa nama/isi`);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/wali-notifikasi-375.png`, fullPage: true });
    await Promise.all([page.waitForURL(/kesehatan_wali\.php\?id=\d+/), page.locator('main a[href*="notifikasi.php?buka="]').first().click()]);
    const detail = await page.locator('main').innerText();
    cek(/RINGKASAN/.test(detail) && detail.includes('Klinik Pesantren Al Hasan') && !/KELUHAN|OBAT|SBX V5 Petugas/.test(detail), `E-B ${lebar}px: ketuk notifikasi → ringkasan anak tanpa catatan klinis/nama petugas`);
    await periksaLayar(page, 'detail kesehatan anak', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/wali-detail-375.png`, fullPage: true });
    await context.close();
  }
  {
    const { context, page } = await konteks(browser, 'sbx_v5_wali_lain', 375);
    const rid = await (async () => {
      const p = await konteks(browser, 'sbx_v5_wali_2', 375);
      await p.page.goto(BASE + '/portal/kesehatan_wali.php');
      const href = await p.page.locator('main a[href*="kesehatan_wali.php?id="]').first().getAttribute('href');
      await p.context.close();
      return Number(href.match(/id=(\d+)/)[1]);
    })();
    const r = await page.goto(BASE + '/portal/kesehatan_wali.php?id=' + rid);
    cek(r.status() === 403 && !(await page.locator('body').innerText()).includes('RINGKASAN'), 'E-B 375px: wali lain membuka tautan ringkasan → 403 tanpa isi');
    await context.close();
  }

  // ----------------------------------------------------------------- E-C
  {
    const { context, page } = await konteks(browser, 'sbx_v5_petugas_f', 375);
    const { kid, penanda } = terbit[375];
    await page.goto(BASE + '/portal/kesehatan_ringkasan.php?id=' + kid);
    cek((await page.locator('#ringkasan').inputValue()).includes(`${penanda}-RINGKASAN`), 'E-C 375px: formulir penggantian diisi versi berlaku');
    await page.fill('#ringkasan', `${penanda}-V2 Ananda sudah sehat kembali.`);
    await page.locator('#alasan-ganti').evaluate((n) => n.removeAttribute('required'));
    await page.getByRole('button', { name: 'Lihat pratinjau' }).click();
    await page.waitForLoadState('domcontentloaded');
    cek((await page.locator('main').innerText()).includes('Alasan penggantian wajib'), 'E-C 375px: penggantian tanpa alasan ditolak server');
    await susunDanPratinjau(page, kid, `${penanda}-V2 Ananda sudah sehat kembali.`, '', 'Pembaruan setelah kontrol');
    await page.check('#konfirmasi');
    await Promise.all([page.waitForURL(/kesehatan_ringkasan\.php\?id=\d+$/), page.getByRole('button', { name: 'Terbitkan versi baru' }).click()]);
    cek(await versiRingkasan(page, kid) === 2 && (await page.locator('main').innerText()).includes('Pembaruan setelah kontrol'), 'E-C 375px: versi 2 terbit; alasan tampil di riwayat');
    await page.locator('#tarik-ringkasan summary').click();
    await page.fill('#alasan-tarik', 'Ditarik untuk disusun ulang');
    await page.screenshot({ path: `${OUT}/tarik-dialog-375.png`, fullPage: true });
    await Promise.all([page.waitForURL(/kesehatan_ringkasan\.php\?id=\d+$/), page.locator('form:has(#alasan-tarik) button[type="submit"]').click()]);
    cek((await page.locator('main').innerText()).includes('Ringkasan telah ditarik'), 'E-C 375px: tarik lewat dialog (dengan JavaScript)');
    await periksaLayar(page, 'ringkasan ditarik (petugas)', 375);
    await context.close();
    const w = await konteks(browser, 'sbx_v5_wali_2', 375);
    await w.page.goto(BASE + '/portal/kesehatan_wali.php');
    const ditarik = w.page.locator('main li', { hasText: 'Ditarik' });
    cek(await ditarik.count() >= 1, 'E-C 375px: daftar wali menandai ringkasan Ditarik');
    await ditarik.first().locator('a').click();
    const teks = await w.page.locator('main').innerText();
    cek(teks.includes('telah ditarik oleh pesantren') && !teks.includes(`${penanda}-V2`), 'E-C 375px: wali melihat keterangan ditarik tanpa isi');
    await w.page.screenshot({ path: `${OUT}/wali-ditarik-375.png`, fullPage: true });
    await w.context.close();
  }

  // ----------------------------------------------------------------- E-D tanpa JS
  {
    const { context, page } = await konteks(browser, 'sbx_v5_petugas_f', 375, false);
    const penanda = `TANPAJS-${Date.now()}`;
    const kid = await buatKunjungan(page, penanda);
    await susunDanPratinjau(page, kid, `${penanda}-RINGKASAN tanpa JavaScript untuk wali.`, '');
    await page.check('#konfirmasi');
    await Promise.all([page.waitForURL(/kesehatan_ringkasan\.php\?id=\d+$/), page.getByRole('button', { name: 'Terbitkan' }).click()]);
    cek(await versiRingkasan(page, kid) === 1 && await melebar(page) <= 0, 'E-D 375px tanpa JavaScript: susun → pratinjau → terbit, rapi');
    await page.locator('#tarik-ringkasan summary').click();
    await page.fill('#alasan-tarik', 'Tarik tanpa JavaScript');
    await Promise.all([page.waitForURL(/kesehatan_ringkasan\.php\?id=\d+$/), page.locator('form:has(#alasan-tarik) button[type="submit"]').click()]);
    cek((await page.locator('main').innerText()).includes('Ringkasan telah ditarik'), 'E-D 375px tanpa JavaScript: tarik lewat <details> berfungsi');
    await context.close();
  }

  // ----------------------------------------------------------------- E-E
  for (const lebar of [375, 1280]) {
    const { kid } = terbit[768];
    for (const user of ['sbx_admin', 'sbx_v5_pembimbing']) {
      const { context, page } = await konteks(browser, user, lebar);
      await page.goto(BASE + '/portal/kesehatan_ringkasan.php?id=' + kid);
      const teks = await page.locator('main').innerText();
      cek(teks.includes('Hanya baca') && await page.locator('#ringkasan').count() === 0 && await page.locator('#tarik-ringkasan').count() === 0
        && (user === 'sbx_admin' ? teks.includes('Riwayat ringkasan') : !teks.includes('Riwayat ringkasan')), `E-E ${lebar}px ${user}: hanya baca${user === 'sbx_admin' ? ' + riwayat' : ', tanpa riwayat'}`);
      await periksaLayar(page, `ringkasan ${user}`, lebar);
      if (lebar === 375 && user === 'sbx_admin') await page.screenshot({ path: `${OUT}/admin-ringkasan-375.png`, fullPage: true });
      await context.close();
    }
  }
} finally {
  await browser.close();
}
cek(galat.length === 0, 'E-E tanpa galat JavaScript', galat.join(' | '));
cek(bawaan.length === 0, 'E-E tanpa confirm()/alert() bawaan', bawaan.join(' | '));
console.log(`\nLulus: ${lulus} · Gagal: ${gagal}`);
process.exit(gagal === 0 ? 0 : 1);
