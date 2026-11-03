/**
 * Uji browser Fase 1 profil pengguna dan impor guru/pengurus — website admin.
 *
 * Melengkapi uji server `tests/profil_impor_f1_http.php` dengan perilaku yang
 * hanya terlihat di peramban, pada 375px, 768px, dan 1280px:
 *
 *   PI-A  wizard impor Data Guru: unggah → pemetaan kolom (tebakan dari judul) →
 *         pratinjau per baris (tindakan, informasi Tugas/Kamar) → simpan → selesai;
 *         tanpa geser horizontal, satu h1, kerangka bersama
 *   PI-B  pilihan pasangan: radio calon + "Tambah baru"; Simpan dinonaktifkan
 *         sampai "Periksa ulang pilihan"; impor Data Pengurus berjalan sama
 *   PI-C  isi berkas yang memuat markup tampil sebagai teks (tidak dieksekusi)
 *   PI-D  formulir Data Guru memuat isian identitas pribadi tanpa melebar
 *   PI-E  tanpa JavaScript: catatan bahwa impor memerlukan JavaScript
 *   PI-F  tanpa galat JavaScript, tanpa confirm()/alert() bawaan
 *
 *   PIF1_DB=test_pif1_test bash tests/profil_impor_f1_db.sh   # (atau DB sesudah uji integrasi)
 *   DB_NAME=test_pif1_test php -S 127.0.0.1:8991 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8991 node tests/browser/uji-profil-impor-f1.mjs
 *
 * Pustaka Excel dari CDN diganti pembaca CSV kecil berantarmuka sama (seperti
 * uji Tahap 6); pembacaan .xlsx/.xls nyata oleh SheetJS tidak diuji di sini.
 * Uji ini MENGUBAH data uji (guru/pengurus impor).
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const XLSX_SHIM = `window.XLSX = {
  read: function (buf) { return { SheetNames: ['S'], Sheets: { S: new TextDecoder().decode(buf) } }; },
  utils: { sheet_to_json: function (teks) { return teks.split(String.fromCharCode(10)).map(function (b) { return b.replace(String.fromCharCode(13), ''); }).filter(function (b) { return b !== ''; }).map(function (b) { return b.split(';'); }); } }
};`;

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8991';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-profil-impor-f1', import.meta.url).pathname;
const SANDI = process.env.UJI_SANDI ?? 'Sandbox#123';
const LEBAR = [375, 768, 1280];
// Penanda putaran: nama/NIP/NIK unik sehingga uji dapat diulang pada DB yang sama.
const PUTARAN = String(Date.now()).slice(-6);
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
    if (/xlsx@.*xlsx\.full\.min\.js$/.test(url.href)) return route.fulfill({ contentType: 'application/javascript', body: XLSX_SHIM });
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
  if (!js) {
    // Tanpa JS, masuk lewat formulir biasa.
    await page.goto(BASE + '/portal/index.php');
    await page.fill('#username', user);
    await page.fill('#password', SANDI);
    await Promise.all([page.waitForURL(/\/portal\/index\.php/), page.locator('button[type=submit]').first().click()]);
    return { context, page };
  }
  await page.goto(BASE + '/portal/index.php');
  await page.fill('#username', user);
  await page.fill('#password', SANDI);
  await Promise.all([page.waitForURL(/\/portal\/index\.php/), page.getByRole('button', { name: 'Masuk', exact: true }).click()]);
  return { context, page };
}
const melebar = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
async function kerangka(page) {
  const masalah = [];
  const lebih = await melebar(page);
  if (lebih > 0) masalah.push(`melebar ${lebih}px`);
  if ((await page.locator('main h1').count()) !== 1) masalah.push('h1 bukan satu');
  if ((await page.locator('#ah-shell').count()) !== 1) masalah.push('bukan kerangka bersama');
  return masalah;
}
const csv = (baris) => Buffer.from(baris.map((b) => b.join(';')).join('\n'));
const JUDUL = ['Nama Lengkap', 'NIK', 'NIP', 'Tempat Lahir', 'Tanggal Lahir', 'Alamat Rumah', 'Kelurahan/Desa', 'Kecamatan', 'Kabupaten/Kota', 'Provinsi', 'No. HP', 'Email', 'Tugas', 'Kamar/Kobong', 'Tugas Tambahan'];

const browser = await chromium.launch();
try {
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, 'sbx_admin', lebar);
    console.log(`\n=== PI-A wizard impor guru ${lebar}px ===`);
    await page.goto(BASE + '/admin/admin_guru.php?action=impor');
    let m = await kerangka(page);
    cek(m.length === 0, `Halaman impor guru ${lebar}px: kerangka`, m.join(', '));
    const nip = `BRW-${PUTARAN}-${lebar}`;
    const nik = `320${PUTARAN}${String(1000000 + lebar).slice(-7)}`;
    await page.setInputFiles('#impor-berkas', { name: 'guru.xlsx', mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      buffer: csv([JUDUL, [`Browser Guru ${PUTARAN} ${lebar}`, nik, nip, 'Garut', '1990-01-31', 'Jl. <b>Uji</b>', 'Desa', 'Kec', 'Kab', 'Jawa Barat', '081200009' + lebar, '', 'Murobi', 'Kamar <img src=x onerror=alert(1)>', 'Keamanan']]) });
    await page.locator('[data-impor-peta]').waitFor({ state: 'visible' });
    const tebakan = await page.locator('[data-impor-kolom]').evaluateAll((s) => s.map((x) => x.value));
    cek(tebakan.join(',') === 'nama,nik,nip,tempat_lahir,tanggal_lahir,alamat_rumah,kelurahan,kecamatan,kabupaten,provinsi,no_hp,email,tugas,kamar,tugas_tambahan', `Pemetaan kolom ditebak dari judul ${lebar}px`, tebakan.join(','));
    m = await kerangka(page);
    cek(m.length === 0, `Pemetaan kolom tanpa melebar ${lebar}px`, m.join(', '));
    await page.locator('[data-impor-periksa]').click();
    await page.locator('[data-impor-langkah="2"]').waitFor({ state: 'visible' });
    const tabel = await page.locator('[data-impor-tabel]').innerText();
    cek(/Tambah baru/.test(tabel) && /Tugas: Murobi/.test(tabel) && /hanya informasi/.test(tabel), `Pratinjau: tindakan "Tambah baru" dan Tugas/Kamar sebagai informasi ${lebar}px`);
    console.log(`\n=== PI-C markup sebagai teks ${lebar}px ===`);
    cek(/<img src=x onerror=alert\(1\)>/.test(tabel) && (await page.locator('[data-impor-tabel] img, [data-impor-tabel] b').count()) === 0, `Markup dari berkas tampil sebagai teks ${lebar}px`);
    m = await kerangka(page);
    cek(m.length === 0, `Pratinjau tanpa melebar ${lebar}px`, m.join(', '));
    await page.screenshot({ path: `${OUT}/impor-guru-pratinjau-${lebar}.png`, fullPage: true });
    const tombol = page.locator('[data-impor-simpan]');
    cek(await tombol.isEnabled() && /Simpan 1 baris/.test(await tombol.innerText()) && (await tombol.boundingBox()).height >= 38, `Tombol Simpan aktif untuk 1 baris ${lebar}px`);
    await tombol.click();
    await page.locator('[data-impor-selesai]').waitFor({ state: 'visible' });
    cek(/1 baris disimpan\. Penugasan dan akun tidak berubah\./.test(await page.locator('[data-impor-ringkas]').innerText()) && /Ditambahkan/.test(await page.locator('[data-impor-tabel]').innerText()),
      `Simpan: hasil impor dan pesan penugasan/akun tidak berubah ${lebar}px`);
    await page.goto(BASE + '/admin/admin_guru.php?q=' + encodeURIComponent(nip));
    cek((await page.locator('#daftar-guru tbody').innerText()).includes(`Browser Guru ${PUTARAN} ${lebar}`), `Guru hasil impor muncul di daftar ${lebar}px`);
    await page.goto(BASE + '/admin/admin_guru.php?action=impor');
    await page.setInputFiles('#impor-berkas', { name: 'guru.xlsx', mimeType: 'application/octet-stream',
      buffer: csv([JUDUL, [`Browser Guru ${PUTARAN} ${lebar}`, nik, nip, 'Garut', '1990-01-31', 'Jl. <b>Uji</b>', 'Desa', 'Kec', 'Kab', 'Jawa Barat', '081200009' + lebar, '', 'Murobi', 'Kamar <img src=x onerror=alert(1)>', 'Keamanan']]) });
    await page.locator('[data-impor-peta]').waitFor({ state: 'visible' });
    await page.locator('[data-impor-periksa]').click();
    await page.locator('[data-impor-langkah="2"]').waitFor({ state: 'visible' });
    cek(/Tidak berubah/.test(await page.locator('[data-impor-tabel]').innerText()) && await page.locator('[data-impor-simpan]').isDisabled(), `Berkas yang sama diunggah ulang: "Tidak berubah", Simpan nonaktif ${lebar}px`);

    console.log(`\n=== PI-D formulir identitas ${lebar}px ===`);
    await page.goto(BASE + '/admin/admin_guru.php?q=' + encodeURIComponent(nip));
    await page.locator('#daftar-guru a.ah-row-link').first().click();
    await page.getByRole('link', { name: /Ubah/ }).first().click();
    await page.locator('#profil_nik').waitFor();
    m = await kerangka(page);
    const isian = await page.locator('#profil_nik, #profil_tanggal_lahir, #profil_kabupaten, #profil_email').count();
    cek(m.length === 0 && isian === 4 && (await page.locator('#profil_nik').inputValue()) === nik, `Formulir guru: isian identitas terisi, tanpa melebar ${lebar}px`, m.join(', '));
    await page.screenshot({ path: `${OUT}/formulir-guru-${lebar}.png`, fullPage: true });
    await context.close();
  }

  console.log('\n=== PI-B pilihan pasangan & impor pengurus (1280px) ===');
  {
    const { context, page } = await konteks(browser, 'sbx_admin', 1280);
    await page.goto(BASE + '/admin/admin_guru.php?action=impor');
    await page.setInputFiles('#impor-berkas', { name: 'kembar.xlsx', mimeType: 'application/octet-stream', buffer: csv([['Nama', 'NIP atau NIK'], [`Browser Kembar ${PUTARAN}`, `320${PUTARAN}8888881`]]) });
    await page.locator('[data-impor-peta]').waitFor({ state: 'visible' });
    const peta = await page.locator('[data-impor-kolom]').evaluateAll((s) => s.map((x) => x.value));
    cek(peta.join(',') === 'nama,nip_nik', 'Judul "NIP atau NIK" dipetakan ke pengenal otomatis', peta.join(','));
    await context.close();

    // Dua guru bernama sama dibuat lewat formulir resmi lebih dulu.
    const k2 = await konteks(browser, 'sbx_admin', 1280);
    for (let i = 0; i < 2; i++) {
      await k2.page.goto(BASE + '/admin/admin_guru.php?action=create');
      await k2.page.fill('#nama_guru', `Browser Kembar ${PUTARAN}`);
      await Promise.all([k2.page.waitForURL(/action=detail/), k2.page.getByRole('button', { name: 'Simpan' }).click()]);
    }
    const p = k2.page;
    await p.goto(BASE + '/admin/admin_guru.php?action=impor');
    await p.setInputFiles('#impor-berkas', { name: 'kembar.xlsx', mimeType: 'application/octet-stream', buffer: csv([['Nama Lengkap', 'NIK', 'Tempat Lahir'], [`Browser Kembar ${PUTARAN}`, `320${PUTARAN}8888881`, 'Tasik']]) });
    await p.locator('[data-impor-peta]').waitFor({ state: 'visible' });
    await p.locator('[data-impor-periksa]').click();
    await p.locator('[data-impor-langkah="2"]').waitFor({ state: 'visible' });
    const radio = p.locator('[data-impor-tabel] input[type=radio]');
    cek(/Pilih pasangan/.test(await p.locator('[data-impor-tabel]').innerText()) && (await radio.count()) === 3 && await p.locator('[data-impor-simpan]').isDisabled(),
      'Nama sama: dua calon + "Tambah baru"; Simpan nonaktif');
    await radio.first().check();
    cek(await p.locator('[data-impor-simpan]').isDisabled() && /Periksa ulang/.test(await p.locator('[data-impor-ringkas]').innerText()), 'Memilih pasangan menahan Simpan sampai diperiksa ulang');
    await p.locator('[data-impor-ulang]').click();
    await p.waitForFunction(() => /Perbarui/.test(document.querySelector('[data-impor-tabel]').innerText));
    cek(await p.locator('[data-impor-simpan]').isEnabled(), 'Sesudah periksa ulang: baris menjadi "Perbarui" dan Simpan aktif');
    await p.screenshot({ path: `${OUT}/impor-pasangan-1280.png`, fullPage: true });
    await p.locator('[data-impor-simpan]').click();
    await p.locator('[data-impor-selesai]').waitFor({ state: 'visible' });
    cek(/Diperbarui/.test(await p.locator('[data-impor-tabel]').innerText()), 'Pasangan pilihan admin tersimpan');

    await p.goto(BASE + '/admin/admin_pengurus.php?action=impor');
    let mm = await kerangka(p);
    cek(mm.length === 0 && (await p.locator('h1').innerText()).includes('Impor pengurus'), 'Halaman impor pengurus', mm.join(', '));
    await p.setInputFiles('#impor-berkas', { name: 'pengurus.xlsx', mimeType: 'application/octet-stream', buffer: csv([['Nama Lengkap', 'NIP', 'No. HP', 'Tugas'], [`Browser Pengurus ${PUTARAN}`, `BRW-P-${PUTARAN}`, '081200008888', 'Pembimbing']]) });
    await p.locator('[data-impor-peta]').waitFor({ state: 'visible' });
    await p.locator('[data-impor-periksa]').click();
    await p.locator('[data-impor-langkah="2"]').waitFor({ state: 'visible' });
    const teksPengurus = await p.locator('[data-impor-tabel]').innerText();
    cek(/jabatan "Pengurus"/.test(teksPengurus), 'Pratinjau pengurus baru menjelaskan jabatan "Pengurus"', teksPengurus.slice(0, 300));
    await p.locator('[data-impor-simpan]').click();
    await p.locator('[data-impor-selesai]').waitFor({ state: 'visible' });
    cek(/Ditambahkan/.test(await p.locator('[data-impor-tabel]').innerText()), 'Pengurus hasil impor tersimpan');
    await k2.context.close();
  }

  console.log('\n=== PI-E tanpa JavaScript ===');
  {
    const { context, page } = await konteks(browser, 'sbx_admin', 375, false);
    await page.goto(BASE + '/admin/admin_guru.php?action=impor');
    cek((await page.locator('main').innerText()).includes('Impor membutuhkan JavaScript') && (await melebar(page)) <= 0, 'Tanpa JS: catatan impor memerlukan JavaScript');
    await context.close();
  }
} finally {
  await browser.close();
}

console.log('\n=== PI-F galat dan dialog bawaan ===');
cek(galat.length === 0, 'Tanpa galat JavaScript', galat.slice(0, 5).join(' | '));
cek(bawaan.length === 0, 'Tanpa confirm()/alert() bawaan', bawaan.join(' | '));
console.log(`\n${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
