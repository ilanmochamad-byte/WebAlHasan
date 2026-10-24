/**
 * Uji browser PRD V5 Fase 2 — obat, rujukan, tindak lanjut, koreksi
 * berversi, dan pembaca pembimbing/murobi.
 *
 * Melengkapi uji server `tests/v5_phase2_http.php` dengan perilaku yang hanya
 * terlihat di peramban, pada 375px, 768px, dan 1280px:
 *
 *   C-A  daftar (penyaring + ringkasan), formulir dengan rincian, detail,
 *        koreksi, hasil rujukan, riwayat versi (petugas/admin) dan daftar/
 *        detail pembimbing: tanpa geser horizontal, satu h1, kerangka
 *        bersama, tabel menjadi kartu di HP, tombol utama ≥ 44 px;
 *   C-B  dari HP: satu kunjungan dengan dua obat, satu rujukan, satu tindak
 *        lanjut dikirim → satu baris daftar, dua obat di detail;
 *   C-C  catat hasil rujukan → Hasil diketahui, versi 2;
 *   C-D  koreksi beralasan (hapus satu obat) → versi 3; riwayat versi
 *        menampilkan sebelum–sesudah; klik ganda tetap satu versi;
 *   C-E  dua petugas membuka koreksi versi sama → satu tersimpan, yang lain
 *        melihat konflik dan "Muat versi terbaru";
 *   C-F  tanpa JavaScript (375px): simpan sebagai draf, lanjutkan draf,
 *        tetapkan tercatat;
 *   C-G  pembimbing: menu Kesehatan, rincian terbaca tanpa tombol ubah;
 *        tanpa galat JavaScript dan tanpa confirm()/alert() bawaan.
 *
 *   V5F2_DB=test_v5_f2_browser_test bash tests/v5_phase2_db.sh
 *   DB_NAME=test_v5_f2_browser_test php -S 127.0.0.1:8997 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8997 node tests/browser/uji-v5-fase2.mjs
 *
 * Uji ini MENAMBAH kunjungan pada DB uji. Bangun ulang DB sebelum mengulang.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8997';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-v5-fase2', import.meta.url).pathname;
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
const jumlahKunjungan = async (page) => {
  await page.goto(BASE + '/portal/kesehatan.php');
  return page.locator('main a[href*="kesehatan_detail.php?id="]').count();
};

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
  cek(masalah.length === 0, `C-A ${nama} ${lebar}px: rapi di kerangka bersama`, [...new Set(masalah)].join('; '));
}

const idDari = (url) => Number(new URL(url).searchParams.get('id'));
async function pilihSantri(page, nis) {
  await page.goto(BASE + '/portal/kesehatan_buat.php?q=' + nis);
  const nilai = await page.locator('#santri_id option').evaluateAll((os, n) => (os.find((o) => o.textContent.includes(n)) || {}).value, nis);
  await page.selectOption('#santri_id', nilai);
}
async function isiRincian(page, penanda) {
  await page.fill('#keluhan', `${penanda} keluhan demam`);
  await page.fill('#obat-0-nama', `${penanda}-obat-satu-nama-panjang-tanpa-spasi-` + 'z'.repeat(80));
  await page.fill('#obat-0-jumlah', '0,5');
  await page.fill('#obat-0-satuan', 'tablet');
  await page.fill('#obat-0-petunjuk', '3 kali sehari sesudah makan');
  await page.fill('#obat-1-nama', `${penanda}-obat-dua`);
  await page.fill('#obat-1-jumlah', '1');
  await page.fill('#obat-1-satuan', 'sachet');
  await page.fill('#rujukan-0-tujuan', `${penanda} Puskesmas`);
  await page.fill('#rujukan-0-alasan', 'Pemeriksaan darah');
  await page.fill('#tl-0-uraian', `${penanda} kontrol tiga hari`);
}
const versiKunjungan = async (page, id) => {
  await page.goto(BASE + '/portal/kesehatan_detail.php?id=' + id);
  const teks = await page.locator('main').innerText();
  return Number((teks.match(/Versi\s+(\d+)/i) || [])[1] || 0);
};

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
let idUtama = 0;
try {
  // ------------------------------------------------------- C-B, C-C, C-D, C-A
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, 'sbx_v5_petugas_b', lebar);
    const penanda = `C${lebar}-${Date.now()}`;
    await pilihSantri(page, 'V5S0101');
    await isiRincian(page, penanda);
    await periksaLayar(page, 'formulir dengan rincian', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/formulir-rincian-375.png`, fullPage: true });
    await Promise.all([page.waitForURL(/kesehatan_detail\.php\?id=\d+/), page.getByRole('button', { name: 'Simpan kunjungan' }).click()]);
    const id = idDari(page.url());
    if (lebar === 375) idUtama = id;
    const main = await page.locator('main').innerText();
    cek(main.includes(`${penanda}-obat-dua`) && main.includes('0,5 tablet') && main.includes(`${penanda} Puskesmas`) && main.includes('Menunggu hasil') && main.includes(`${penanda} kontrol tiga hari`),
      `C-B ${lebar}px: dua obat, satu rujukan menunggu, satu tindak lanjut tampil di detail`);
    await periksaLayar(page, 'detail dengan rincian', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/detail-rincian-375.png`, fullPage: true });
    await page.goto(BASE + '/portal/kesehatan.php?santri_id=' + (await page.locator('main a[href*="santri_id="]').first().getAttribute('href')).match(/santri_id=(\d+)/)[1]);
    cek(await page.locator(`main a[href$="kesehatan_detail.php?id=${id}"]`).count() === 1 && (await page.locator('main').innerText()).includes('2 obat'), `C-B ${lebar}px: satu baris di riwayat santri dengan penanda 2 obat`);
    await periksaLayar(page, 'riwayat santri + ringkasan', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/daftar-375.png`, fullPage: true });

    // C-C hasil rujukan
    await page.goto(BASE + '/portal/kesehatan_detail.php?id=' + id);
    await Promise.all([page.waitForURL(/kesehatan_hasil_rujukan\.php/), page.getByRole('link', { name: 'Catat hasil rujukan' }).click()]);
    await periksaLayar(page, 'formulir hasil rujukan', lebar);
    await page.fill('#hasil', 'Hasil laboratorium normal');
    await Promise.all([page.waitForURL(/kesehatan_detail\.php\?id=\d+/), page.getByRole('button', { name: 'Simpan hasil' }).click()]);
    cek((await page.locator('main').innerText()).includes('Hasil diketahui') && await versiKunjungan(page, id) === 2, `C-C ${lebar}px: hasil rujukan tercatat, versi 2`);

    // C-D koreksi beralasan + klik ganda
    await page.goto(BASE + '/portal/kesehatan_koreksi.php?id=' + id);
    await periksaLayar(page, 'formulir koreksi', lebar);
    await page.check('#obat-1-hapus');
    await page.fill('#keluhan', `${penanda} keluhan demam tinggi`);
    await page.fill('#alasan', 'Obat kedua tidak jadi diberikan');
    if (lebar === 375) await page.screenshot({ path: `${OUT}/koreksi-375.png`, fullPage: true });
    await Promise.all([page.waitForURL(/kesehatan_detail\.php\?id=\d+/), page.getByRole('button', { name: 'Simpan koreksi' }).dblclick()]);
    await page.waitForLoadState('networkidle');
    const setelah = await page.locator('main').innerText();
    cek(!setelah.includes(`${penanda}-obat-dua`) && setelah.includes('demam tinggi') && await versiKunjungan(page, id) === 3, `C-D ${lebar}px: koreksi (klik ganda) → tepat versi 3, obat kedua tidak aktif`);
    await page.goto(BASE + '/portal/kesehatan_riwayat.php?id=' + id);
    const riwayat = await page.locator('main').innerText();
    cek(riwayat.includes('Versi 3 (terbaru)') && riwayat.includes('Obat kedua tidak jadi diberikan') && riwayat.includes('Dihapus') && riwayat.includes('demam tinggi') && !riwayat.includes('Versi 4'),
      `C-D ${lebar}px: riwayat versi menampilkan alasan dan perubahan sebelum–sesudah`);
    await periksaLayar(page, 'riwayat versi', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/riwayat-375.png`, fullPage: true });
    await context.close();
  }

  // ------------------------------------------------------------- C-E konflik
  {
    const a = await konteks(browser, 'sbx_v5_petugas_b', 375);
    const b = await konteks(browser, 'sbx_v5_petugas_d', 375);
    await a.page.goto(BASE + '/portal/kesehatan_koreksi.php?id=' + idUtama);
    await b.page.goto(BASE + '/portal/kesehatan_koreksi.php?id=' + idUtama);
    await a.page.fill('#tindakan', 'Kompres hangat (petugas B)');
    await a.page.fill('#alasan', 'Tindakan dilengkapi petugas B');
    await b.page.fill('#tindakan', 'Kompres dingin (petugas D)');
    await b.page.fill('#alasan', 'Tindakan dilengkapi petugas D');
    await Promise.all([a.page.waitForURL(/kesehatan_detail\.php/), a.page.getByRole('button', { name: 'Simpan koreksi' }).click()]);
    await b.page.getByRole('button', { name: 'Simpan koreksi' }).click();
    await b.page.waitForLoadState('networkidle');
    const teksB = await b.page.locator('main').innerText();
    cek(/versi 4/.test(teksB) && teksB.includes('Muat versi terbaru') && await b.page.locator('#tindakan').inputValue() === 'Kompres dingin (petugas D)',
      'C-E 375px: petugas kedua melihat konflik, isiannya tetap ada, tombol Muat versi terbaru');
    await periksaLayar(b.page, 'konflik koreksi', 375);
    await b.page.screenshot({ path: `${OUT}/konflik-375.png`, fullPage: true });
    await a.page.goto(BASE + '/portal/kesehatan_detail.php?id=' + idUtama);
    cek((await a.page.locator('main').innerText()).includes('petugas B') && await versiKunjungan(a.page, idUtama) === 4, 'C-E 375px: koreksi pertama utuh, versi 4');
    await a.context.close();
    await b.context.close();
  }

  // ------------------------------------------------------------- C-F tanpa JS
  {
    const { context, page } = await konteks(browser, 'sbx_v5_petugas_b', 375, false);
    await pilihSantri(page, 'V5S0101');
    await page.fill('#keluhan', `TANPAJS-${Date.now()} pusing`);
    await page.fill('#obat-0-nama', 'Obat tanpa JS');
    await Promise.all([page.waitForURL(/kesehatan_detail\.php\?id=\d+/), page.getByRole('button', { name: 'Simpan sebagai draf' }).click()]);
    const id = idDari(page.url());
    cek((await page.locator('main').innerText()).includes('Belum tampil kepada pembimbing/murobi') && await melebar(page) <= 0, 'C-F 375px tanpa JavaScript: draf tersimpan, detail rapi');
    await page.goto(BASE + '/portal/kesehatan_koreksi.php?id=' + id);
    await page.fill('#tindakan', 'Istirahat');
    await Promise.all([page.waitForURL(/kesehatan_detail\.php\?id=\d+/), page.getByRole('button', { name: 'Simpan draf' }).click()]);
    await page.goto(BASE + '/portal/kesehatan_koreksi.php?id=' + id);
    await Promise.all([page.waitForURL(/kesehatan_detail\.php\?id=\d+/), page.getByRole('button', { name: 'Tetapkan sebagai tercatat' }).click()]);
    const teks = await page.locator('main').innerText();
    cek(teks.includes('Tercatat') && !teks.includes('Belum tampil kepada pembimbing') && await versiKunjungan(page, id) === 3, 'C-F 375px tanpa JavaScript: lanjutkan draf lalu tetapkan tercatat (versi 3)');
    await context.close();
  }

  // ------------------------------------------------------------- C-G pembimbing / admin
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, 'sbx_v5_pembimbing', lebar);
    cek(await page.locator('a[href*="/portal/kesehatan.php"]').count() > 0, `C-G ${lebar}px: menu Kesehatan tersedia bagi pembimbing`);
    await page.goto(BASE + '/portal/kesehatan.php');
    await periksaLayar(page, 'daftar pembimbing', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/daftar-pembimbing-375.png`, fullPage: true });
    await page.goto(BASE + '/portal/kesehatan_detail.php?id=' + idUtama);
    const teks = await page.locator('main').innerText();
    cek(teks.includes('Hanya baca') && await page.locator('main a[href*="kesehatan_koreksi.php"], main a[href*="kesehatan_riwayat.php"], main a[href*="kesehatan_hasil_rujukan.php"]').count() === 0,
      `C-G ${lebar}px: pembimbing membaca rincian tanpa tombol ubah/riwayat versi`);
    await periksaLayar(page, 'detail pembimbing', lebar);
    if (lebar === 375) await page.screenshot({ path: `${OUT}/detail-pembimbing-375.png`, fullPage: true });
    await context.close();

    const adm = await konteks(browser, 'sbx_admin', lebar);
    await adm.page.goto(BASE + '/portal/kesehatan_riwayat.php?id=' + idUtama);
    await periksaLayar(adm.page, 'riwayat versi admin', lebar);
    cek(await adm.page.locator('main a[href*="kesehatan_koreksi.php"]').count() === 0 && (await adm.page.locator('main').innerText()).includes('Versi 4 (terbaru)'), `C-G ${lebar}px: admin membaca riwayat versi tanpa tombol koreksi`);
    await adm.context.close();
  }
} finally {
  await browser.close();
}
cek(galat.length === 0, 'C-G tanpa galat JavaScript', galat.join(' | '));
cek(bawaan.length === 0, 'C-G tanpa confirm()/alert() bawaan', bawaan.join(' | '));
console.log(`\nLulus: ${lulus} · Gagal: ${gagal}`);
process.exit(gagal === 0 ? 0 : 1);
