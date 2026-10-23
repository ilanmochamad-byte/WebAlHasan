/**
 * Uji browser redesain website Tahap 6 — data pesantren & penugasan.
 *
 * Melengkapi uji server `tests/redesain_web_tahap6_http.php` dengan perilaku
 * yang hanya terlihat di peramban, pada 375px, 768px, dan 1280px:
 *
 *   T6-A  setiap layar D-1 s.d. D-10, M-1 s.d. M-3, G-1 untuk akun yang
 *         memakainya: tanpa geser horizontal, satu h1, kerangka bersama, tabel
 *         menjadi kartu di HP, tombol utama ≥ 44 px, panel samping di desktop,
 *         tanpa isian ID;
 *   T6-B  D-5 dari HP: centang santri → bilah bawah → tujuan → peringatan
 *         asrama → "Saya mengerti" + alasan → tersimpan (keputusan AE);
 *   T6-C  D-7: satu pasangan per layar, "Bukan ganda" lewat dialog beralasan,
 *         "Lewati" tanpa menyimpan (keputusan AD);
 *   T6-D  D-4: wizard Unggah → Periksa → Impor, baris bermasalah dijelaskan,
 *         hanya baris siap tersimpan (keputusan AC);
 *   T6-E  D-10 dari HP: Beranda pengurus → profil santri binaan, tab hanya-baca;
 *   T6-F  G-1 di HP: pilihan jenis membuka daftar; ⋯ Akhiri membuka dialog;
 *   T6-G  tanpa galat JavaScript, tanpa confirm()/alert() bawaan.
 *
 *   bash tests/redesain_web_tahap6_db.sh
 *   DB_NAME=test_redesain_t6_test php -S 127.0.0.1:8981 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8981 node tests/browser/uji-redesain-web-tahap6.mjs
 *
 * Uji ini MENGUBAH data uji (penempatan, tanda bukan ganda, santri impor).
 * Bangun ulang DB sebelum mengulang.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const XLSX_SHIM = `window.XLSX = {
  read: function (buf) { return { SheetNames: ['S'], Sheets: { S: new TextDecoder().decode(buf) } }; },
  utils: { sheet_to_json: function (teks) { return teks.split(String.fromCharCode(10)).map(function (b) { return b.replace(String.fromCharCode(13), ''); }).filter(function (b) { return b !== ''; }).map(function (b) { return b.split(','); }); } }
};`;

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8981';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-redesain-web-tahap6', import.meta.url).pathname;
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
    // Pustaka Excel dari CDN tidak tersedia luring: diganti pembaca CSV kecil
    // berantarmuka sama (XLSX.read / utils.sheet_to_json). Pemeriksaan dan
    // penyimpanan tetap sepenuhnya di server.
    if (/xlsx@.*xlsx\.full\.min\.js$/.test(url.href)) return route.fulfill({ contentType: 'application/javascript', body: XLSX_SHIM });
    return route.abort();
  });
}
async function konteks(browser, user, lebar = 1280) {
  const context = await browser.newContext({ viewport: { width: lebar, height: lebar < 576 ? 812 : 900 } });
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
const dialogForm = (page) => page.locator('dialog.ah-dialog--form[open]');
const teksMain = (page) => page.locator('main').innerText();
// Pilih <option> menurut teks yang cocok dengan pola.
async function pilih(page, selektor, pola) {
  const nilai = await page.locator(selektor + ' option').evaluateAll((os, src) => (os.find((o) => new RegExp(src).test(o.textContent)) || {}).value, pola.source);
  if (!nilai) throw new Error(`Pilihan ${pola} tidak ada di ${selektor}`);
  await page.selectOption(selektor, nilai);
}

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
  if (lebar === 1280 && await page.locator('.ah-panel').count()) {
    const posisi = await page.locator('.ah-panel').evaluate((n) => getComputedStyle(n).position);
    if (posisi !== 'fixed') masalah.push('panel bukan panel samping');
  }
  for (const tombol of await page.locator('.ah-page-head__actions .btn, .ah-actbar .btn, .ah-filter__actions .btn, .ah-act > summary').all()) {
    if (await tombol.isVisible()) {
      const kotak = await tombol.boundingBox();
      if (kotak && kotak.height < 43.5) masalah.push(`tombol ${Math.round(kotak.height)}px`);
    }
  }
  // Keputusan tahap: tidak ada isian ID (nama isian *_id yang diketik tangan).
  const isianId = await page.locator('main input[name$="_id"]:not([type="hidden"]):not([type="checkbox"])').count();
  if (isianId > 0) masalah.push(`${isianId} isian ID`);
  cek(masalah.length === 0, `T6-A ${nama} ${lebar}px: rapi di kerangka bersama`, [...new Set(masalah)].join('; '));
}


const LAYAR = {
  sbx_admin: {
    'D-1': '/admin/admin_master_santri.php', 'D-2-biodata': '/admin/admin_master_santri.php?action=detail&id=1',
    'D-2-tempat': '/admin/admin_master_santri.php?action=detail&id=1&tab=tempat', 'D-2-pembinaan': '/admin/admin_master_santri.php?action=detail&id=2&tab=pembinaan',
    'D-3': '/admin/admin_master_santri.php?action=edit&id=1', 'D-4': '/admin/admin_master_santri.php?action=impor',
    'D-5': '/admin/admin_penempatan_santri.php', 'D-5-panel': '/admin/admin_penempatan_santri.php?ubah=1',
    'D-6': '/admin/admin_wali.php', 'D-6-panel': '/admin/admin_wali.php?action=detail&id=1',
    'D-7': '/admin/admin_wali_rekonsiliasi.php', 'D-7-relasi': '/admin/admin_wali_rekonsiliasi.php?bagian=relasi_belum_lengkap',
    'D-8': '/admin/admin_kelulusan_santri.php?kelas_id=1', 'D-9': '/admin/admin_alumni.php',
    'M-1-guru': '/admin/admin_guru.php', 'M-1-panel': '/admin/admin_guru.php?action=detail&id=205', 'M-1-pengurus': '/admin/admin_pengurus.php',
    'M-2-kelas': '/admin/admin_kelas.php', 'M-2-kamar': '/admin/admin_kamar.php', 'M-2-kamar-panel': '/admin/admin_kamar.php?action=detail&id=3',
    'M-2-kamar-form': '/admin/admin_kamar.php?action=create', 'M-3': '/admin/admin_tahun.php',
    'G-1': '/admin/admin_penugasan.php', 'G-1-mendatang': '/admin/admin_penugasan.php?jenis=panitia_psb', 'G-1-tambah': '/admin/admin_penugasan.php?jenis=pembimbing&tambah=1',
  },
  sbx_pengurus_a: { 'D-10-daftar': '/portal/santri_binaan.php', 'D-10-ringkasan': '/portal/santri_binaan.php?id=1', 'D-10-izin': '/portal/santri_binaan.php?id=1&tab=izin',
    'D-10-pembinaan': '/portal/santri_binaan.php?id=2&tab=pembinaan' },
  sbx_murobi_c: { 'D-10-murobi': '/portal/santri_binaan.php?id=4&tab=sikap' },
};
const TANGKAP = ['D-1', 'D-2-biodata', 'D-3', 'D-4', 'D-5', 'D-5-panel', 'D-6-panel', 'D-7', 'D-8', 'D-9', 'M-1-panel', 'M-2-kamar', 'M-3', 'G-1', 'G-1-mendatang', 'D-10-ringkasan'];

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  // ---------------------------------------------------------------- T6-A
  for (const [user, layar] of Object.entries(LAYAR)) {
    for (const lebar of LEBAR) {
      const { context, page } = await konteks(browser, user, lebar);
      for (const [nama, alamat] of Object.entries(layar)) {
        const r = await page.goto(BASE + alamat);
        if (r.status() !== 200) { cek(false, `T6-A ${nama} ${lebar}px`, `HTTP ${r.status()}`); continue; }
        await periksaLayar(page, nama, lebar);
        if (TANGKAP.includes(nama)) await page.screenshot({ path: `${OUT}/${nama}-${lebar}.jpg`, type: 'jpeg', quality: 70, fullPage: lebar === 375 });
      }
      await context.close();
    }
  }

  // ---------------------------------------------------------------- T6-B
  {
    const { context, page } = await konteks(browser, 'sbx_admin', 375);
    await page.goto(BASE + '/admin/admin_penempatan_santri.php?q=SBX+T6+Putri');
    cek(!(await page.locator('.ah-selbar').isVisible()), 'T6-B bilah pilihan tersembunyi sebelum ada centang');
    await page.locator('input[data-ah-select]').first().check();
    cek(await page.locator('.ah-selbar').isVisible() && (await page.locator('[data-ah-selcount]').innerText()).includes('1 santri'), 'T6-B bilah bawah muncul: "1 santri dipilih"');
    await Promise.all([page.waitForNavigation(), page.locator('.ah-selbar').getByRole('button', { name: 'Tempatkan ke kamar…' }).click()]);
    cek((await teksMain(page)).includes('SBX T6 Putri'), 'T6-B langkah tujuan menyebut santri terpilih');
    await pilih(page, '#tujuan_kamar', /SBX Kamar B/);
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Tinjau perubahan' }).click()]);
    const tinjau = await teksMain(page);
    cek(tinjau.includes('SBX T6 Putri (P) akan ditempatkan di SBX Kamar B (asrama putra)') && tinjau.includes('Tidak sesuai asrama'), 'T6-B tinjauan memperingatkan asrama tidak sesuai per santri');
    cek(await melebar(page) <= 0, 'T6-B tinjauan di HP tanpa geser horizontal');
    await page.screenshot({ path: `${OUT}/T6-B-tinjau-375.jpg`, type: 'jpeg', quality: 70, fullPage: true });
    await page.locator('input[name="konfirmasi_asrama"]').check();
    await page.fill('#alasan_asrama', 'Kamar putri penuh (uji browser).');
    await page.getByRole('button', { name: 'Terapkan perubahan' }).click();
    await Promise.all([page.waitForURL(/kamar_id=0/), page.locator('dialog.ah-dialog[open]').getByRole('button').last().click()]);
    cek((await page.content()).includes('ditempatkan di kamar SBX Kamar B') && (await page.locator('table.ah-table tbody').innerText()).includes('SBX Kamar B'), 'T6-B penempatan dengan konfirmasi + alasan tersimpan (pesan hasil, kamar baru di daftar)');
    await context.close();
  }

  // ---------------------------------------------------------------- T6-C
  {
    const { context, page } = await konteks(browser, 'sbx_admin', 1280);
    await page.goto(BASE + '/admin/admin_wali_rekonsiliasi.php');
    const judul = await page.locator('#ah-pasangan').innerText();
    const total = Number((judul.match(/dari (\d+)/) || [])[1] || 0);
    cek(total >= 3 && (await page.locator('.ah-compare__item').count()) === 2, 'T6-C satu pasangan berdampingan per layar', judul);
    await page.getByRole('link', { name: 'Lewati' }).click();
    cek((await page.locator('#ah-pasangan').innerText()).includes(`2 dari ${total}`), 'T6-C Lewati melompat ke pasangan berikutnya tanpa menyimpan');
    await page.locator('#dialog-bukan-ganda > summary').click();
    cek(await dialogForm(page).isVisible(), 'T6-C "Bukan ganda" membuka dialog beralasan');
    await dialogForm(page).locator('input[name="alasan"]').fill('Orang berbeda (uji browser).');
    await Promise.all([page.waitForNavigation(), dialogForm(page).getByRole('button', { name: 'Simpan tanda' }).click()]);
    cek((await page.locator('#ah-pasangan').innerText()).includes(`dari ${total - 1}`), 'T6-C pasangan bertanda hilang dari kemungkinan ganda');
    await page.goto(BASE + '/admin/admin_wali_rekonsiliasi.php?bagian=bukan_ganda');
    cek((await teksMain(page)).includes('Orang berbeda (uji browser).'), 'T6-C chip "Ditandai bukan ganda" memuat alasan');
    await context.close();
  }

  // ---------------------------------------------------------------- T6-D
  {
    const { context, page } = await konteks(browser, 'sbx_admin', 768);
    await page.goto(BASE + '/admin/admin_master_santri.php?action=impor');
    cek((await page.locator('.ah-steps li.is-current').innerText()).includes('Unggah berkas'), 'T6-D langkah 1 Unggah berkas');
    const csv = 'NIS,Nama,L/P\nSBX-T6-B1,SBX Browser Satu,L,Ciamis,2014-01-01\nSBX-S-001,Sudah Ada,L,,2014-01-01\nSBX-T6-B1,Ganda,L,,2014-01-01\n';
    await page.setInputFiles('#impor-berkas', { name: 'santri.xlsx', mimeType: 'text/csv', buffer: Buffer.from(csv) });
    await page.getByRole('button', { name: 'Periksa data' }).click();
    await page.waitForSelector('[data-impor-langkah="2"]:not([hidden])');
    const stat = await page.locator('[data-impor-stats]').innerText();
    cek(/Baris\s*3/i.test(stat) && /Siap\s*1/i.test(stat) && /Bermasalah\s*2/i.test(stat), 'T6-D Periksa: 3 baris, 1 siap, 2 bermasalah', stat);
    const masalah = await page.locator('[data-impor-tabel]').innerText();
    cek(masalah.includes('sudah terdaftar') && masalah.includes('ganda di dalam berkas'), 'T6-D baris bermasalah dijelaskan');
    cek((await page.locator('.ah-steps li.is-current').innerText()).includes('Periksa data') && (await page.getByRole('button', { name: 'Impor 1 santri' }).isEnabled()), 'T6-D langkah 2 Periksa data; tombol "Impor 1 santri"');
    await page.getByRole('button', { name: 'Impor 1 santri' }).click();
    await page.waitForSelector('[data-impor-selesai]:not([hidden])');
    cek(/Disimpan\s*1/i.test(await page.locator('[data-impor-stats]').innerText()) && (await page.locator('[data-impor-ringkas]').innerText()).includes('1 santri disimpan'), 'T6-D Impor menyimpan hanya baris siap');
    await page.screenshot({ path: `${OUT}/T6-D-hasil-768.jpg`, type: 'jpeg', quality: 70, fullPage: true });
    await context.close();
  }

  // ---------------------------------------------------------------- T6-E
  {
    const { context, page } = await konteks(browser, 'sbx_pengurus_a', 375);
    await page.goto(BASE + '/portal/index.php');
    await Promise.all([page.waitForURL(/santri_binaan\.php\?id=/), page.locator('#ah-santri-binaan').locator('xpath=../..').locator('a.ah-mini-list__row').first().click()]);
    const t = await teksMain(page);
    cek(t.includes('Ringkasan') && t.includes('Hanya-baca') && !t.includes('Nilai & rapor'), 'T6-E Beranda → profil santri binaan, tab terbatas, hanya-baca');
    await Promise.all([page.waitForURL(/tab=pembinaan/), page.locator('.ah-seg').getByRole('link', { name: 'Pembinaan', exact: true }).click()]);
    cek(/total poin semester/i.test(await teksMain(page)) && await melebar(page) <= 0, 'T6-E tab Pembinaan di HP tanpa geser horizontal');
    const r = await page.goto(BASE + '/portal/santri_binaan.php?id=3');
    cek(r.status() === 403 && (await page.locator('body').innerText()).includes('di luar cakupan'), 'T6-E santri di luar cakupan: halaman akses ditolak');
    await context.close();
  }

  // ---------------------------------------------------------------- T6-F
  {
    const { context, page } = await konteks(browser, 'sbx_admin', 375);
    await page.goto(BASE + '/admin/admin_penugasan.php');
    cek(await page.locator('#pilih-jenis').isVisible() && !(await page.locator('.ah-jenis__menu').isVisible()), 'T6-F HP: jenis penugasan berupa pilihan di atas');
    await Promise.all([page.waitForURL(/jenis=pembimbing/), page.selectOption('#pilih-jenis', 'pembimbing')]);
    cek((await teksMain(page)).includes('Pembimbing adalah pengurus'), 'T6-F memilih jenis langsung membuka daftarnya');
    await page.locator('table.ah-table tbody tr').first().locator('.ah-menu > summary').click();
    await Promise.all([page.waitForURL(/aksi=akhiri/), page.locator('.ah-menu[open]').getByRole('link', { name: 'Akhiri penugasan…' }).click()]);
    await page.waitForSelector('dialog.ah-dialog--form[open]');
    cek(await dialogForm(page).isVisible() && (await dialogForm(page).locator('input[name="tanggal_selesai"]').count()) === 1, 'T6-F ⋯ Akhiri membuka dialog tanggal selesai + alasan');
    await context.close();
    const desktop = await konteks(browser, 'sbx_admin', 1280);
    await desktop.page.goto(BASE + '/admin/admin_penugasan.php?jenis=panitia_psb');
    cek(await desktop.page.locator('.ah-jenis__menu').isVisible() && /Disiapkan untuk modul mendatang/i.test(await desktop.page.locator('.ah-jenis__group').innerText()), 'T6-F desktop: menu jenis di kiri dengan kelompok modul mendatang');
    await desktop.context.close();
  }

  // ---------------------------------------------------------------- T6-G
  cek(galat.length === 0, 'T6-G tanpa galat JavaScript', galat.slice(0, 5).join(' | '));
  cek(bawaan.length === 0, 'T6-G tanpa confirm()/alert() bawaan peramban', bawaan.slice(0, 3).join(' | '));
} finally {
  await browser.close();
}
console.log(`\nRingkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
