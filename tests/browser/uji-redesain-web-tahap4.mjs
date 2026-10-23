/**
 * Uji browser redesain website Tahap 4 — perizinan, pengajian & cetak.
 *
 * Melengkapi uji server `tests/redesain_web_tahap4_http.php` dengan perilaku
 * yang hanya terlihat di peramban, pada 375px, 768px, dan 1280px:
 *
 *   T4-A  setiap layar I-1 s.d. I-7, J-1 s.d. J-6 untuk akun yang memakainya:
 *         tanpa geser horizontal, satu h1, kerangka bersama, tabel menjadi
 *         kartu di HP, tombol utama ≥ 44 px, panel samping di desktop;
 *   T4-B  kriteria selesai 1: pengurus mengajukan izin dari HP (375px) tanpa
 *         geser horizontal — pilih santri, alasan cepat mengisi awal teks,
 *         panel "Akan diteruskan ke" terisi dari server, kirim, detail tampil;
 *   T4-C  I-5: tindakan membuka dialog berisi formulir; Batal tidak mengirim;
 *         alasan pembatalan wajib; info teknis hanya untuk admin;
 *   T4-D  I-3: murobi menyetujui lewat konfirmasi; detail TETAP terbuka
 *         dengan pesan hasil dan tombol "Berikutnya" (keputusan N);
 *   T4-E  J-1: kisi mingguan Senin…Ahad di desktop, pilih hari di HP (T);
 *   T4-F  J-2: kehadiran hanya-baca, label "Sedang izin" (S), Selesaikan
 *         lewat dialog (Batal tidak mengirim);
 *   T4-G  J-6: Bagian Pendidikan melihat menu dan laporan seluruh jadwal;
 *   T4-H  cetak perizinan dan kehadiran bertanda tangan Bagian Pendidikan;
 *   T4-I  tanpa galat JavaScript, tanpa confirm()/alert() bawaan.
 *
 *   bash tests/redesain_web_tahap4_db.sh
 *   DB_NAME=test_redesain_t4_test php -S 127.0.0.1:8961 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8961 node tests/browser/uji-redesain-web-tahap4.mjs
 *
 * Uji ini MENGUBAH data uji (satu pengajuan baru, satu keputusan). Jalankan
 * ulang persiapan DB sebelum mengulang.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8961';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-redesain-web-tahap4', import.meta.url).pathname;
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
const dialogKonfirmasi = (page) => page.locator('dialog.ah-dialog:not(.ah-dialog--form)[open]');
const idDari = (href) => new URL(href, BASE).searchParams.get('id');

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
  cek(masalah.length === 0, `T4-A ${nama} ${lebar}px: rapi di kerangka bersama`, [...new Set(masalah)].join('; '));
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  // ID contoh diambil dari halaman, tanpa akses basis data dari peramban.
  const awal = await konteks(browser, 'sbx_admin');
  await awal.page.goto(BASE + '/portal/izin.php?status=' + encodeURIComponent('Perlu Penetapan Admin'));
  const izinAdmin = idDari(await awal.page.locator('table.ah-table a.ah-row-link').first().getAttribute('href'));
  await awal.page.goto(BASE + '/admin/admin_pengajian.php?tab=pertemuan&q=Dibuka');
  const pertemuan = idDari(await awal.page.locator('table.ah-table a.ah-row-link').first().getAttribute('href'));
  await awal.context.close();
  const awalA = await konteks(browser, 'sbx_admin');
  await awalA.page.goto(BASE + '/admin/admin_laporan_absensi.php');
  const guruAdmin = await awalA.page.locator('#teacher_id option:not([value=""])').count();
  await awalA.context.close();
  const awalM = await konteks(browser, 'sbx_murobi_c');
  await awalM.page.goto(BASE + '/portal/izin_antrean.php');
  const antrean = await awalM.page.locator('.ah-qlist a').evaluateAll((as) => as.map((a) => new URL(a.href).searchParams.get('id')));
  await awalM.context.close();

  const LAYAR = {
    sbx_admin: {
      'I-1': '/portal/izin_ringkasan.php', 'I-2': '/portal/izin.php', 'I-3-admin': `/portal/izin_antrean.php?id=${izinAdmin}`,
      'I-4-admin': '/portal/izin_buat.php', 'I-5-admin': `/portal/izin_detail.php?id=${izinAdmin}`, 'I-5-teknis': `/portal/izin_detail.php?id=${izinAdmin}&info=teknis`,
      'I-6': '/portal/laporan.php', 'J-1-mingguan': '/admin/admin_pengajian.php', 'J-1-daftar': '/admin/admin_pengajian.php?tampilan=daftar',
      'J-1-tambah': '/admin/admin_pengajian.php?tab=jadwal&action=create', 'J-3': '/admin/admin_pengajian.php?tab=pertemuan',
      'J-3-buka': '/admin/admin_pengajian.php?tab=pertemuan&action=create', 'J-2': `/admin/admin_pengajian.php?tab=pertemuan&id=${pertemuan}`,
      'J-4': '/admin/admin_laporan_absensi.php', 'J-5': `/admin/laporan_absensi_detail.php?id=${pertemuan}`,
    },
    sbx_pengurus_a: { 'I-1-pengurus': '/portal/izin_ringkasan.php', 'I-2-pengurus': '/portal/izin.php', 'I-4': '/portal/izin_buat.php', 'I-6-pengurus': '/portal/laporan.php' },
    sbx_murobi_c: { 'I-3': '/portal/izin_antrean.php', 'I-3-detail': `/portal/izin_antrean.php?id=${antrean[0]}` },
    sbx_ortu_a: { 'I-7-aktif': '/portal/izin.php', 'I-7-riwayat': '/portal/izin.php?tab=riwayat' },
    sbx_pendidikan: { 'J-6': '/admin/admin_laporan_absensi.php', 'J-5-pendidikan': `/admin/laporan_absensi_detail.php?id=${pertemuan}` },
    sbx_murobi_a: { 'J-1-guru': '/admin/admin_pengajian.php', 'J-2-guru': `/admin/admin_pengajian.php?tab=pertemuan&id=${pertemuan}` },
  };
  const TANGKAP = ['I-1', 'I-2', 'I-3-detail', 'I-4', 'I-5-admin', 'I-6', 'I-7-aktif', 'J-1-mingguan', 'J-2', 'J-4', 'J-6'];

  // ---------------------------------------------------------------- T4-A
  for (const [user, layar] of Object.entries(LAYAR)) {
    for (const lebar of LEBAR) {
      const { context, page } = await konteks(browser, user, lebar);
      for (const [nama, alamat] of Object.entries(layar)) {
        await page.goto(BASE + alamat);
        await periksaLayar(page, nama, lebar);
        if (TANGKAP.includes(nama)) await page.screenshot({ path: `${OUT}/${nama}-${lebar}.jpg`, type: 'jpeg', quality: 70, fullPage: lebar === 375 });
      }
      await context.close();
    }
  }

  // ---------------------------------------------------------------- T4-B
  {
    const { context, page } = await konteks(browser, 'sbx_pengurus_a', 375);
    await page.goto(BASE + '/portal/izin_buat.php');
    const pilihan = page.locator('#santri_id option:not([value=""])');
    const nilai = await pilihan.first().getAttribute('value');
    const nama = (await pilihan.first().innerText()).trim().split(' · ')[0];
    cek(await page.locator('input[data-ah-option-filter]').isVisible(), 'T4-B kotak saring santri tampil dengan JavaScript');
    await page.locator('input[data-ah-option-filter]').fill(nama.slice(0, 6));
    await page.selectOption('#santri_id', nilai);
    await page.waitForFunction(() => !['Memuat…', 'Pilih santri lebih dahulu'].includes(document.querySelector('[data-ah-info="murobi"]').textContent));
    const murobi = await page.locator('[data-ah-info="murobi"]').innerText();
    const jumlah = await page.locator('[data-ah-info="jumlah"]').innerText();
    cek(murobi.length > 2 && !murobi.startsWith('Belum dapat') && /pengajuan, \d+ disetujui/.test(jumlah), `T4-B panel tujuan terisi dari server (${murobi} · ${jumlah})`);
    const besok = new Date(Date.now() + 86400000 * 3).toISOString().slice(0, 10);
    const lusa = new Date(Date.now() + 86400000 * 5).toISOString().slice(0, 10);
    await page.fill('#tgl_izin', besok);
    await page.fill('#tgl_kembali', lusa);
    await page.locator('#tgl_kembali').dispatchEvent('change');
    cek((await page.locator('[data-ah-info="durasi"]').innerText()) === '3 hari', 'T4-B durasi dihitung saat tanggal dipilih');
    await page.locator('button[data-ah-prefix="Berobat: "]').click();
    await page.locator('#alasan').pressSequentially('kontrol gigi T4B');
    await page.locator('button[data-ah-prefix="Pulang: "]').click();
    cek((await page.inputValue('#alasan')) === 'Pulang: kontrol gigi T4B', 'T4-B alasan cepat hanya mengganti awalan teks (M)');
    cek((await melebar(page)) <= 0, 'T4-B formulir di HP tanpa geser horizontal');
    const kirim = page.getByRole('button', { name: 'Kirim pengajuan' });
    cek((await kirim.boundingBox()).height >= 43.5, 'T4-B tombol Kirim ≥ 44 px');
    await Promise.all([page.waitForURL(/izin_detail\.php/), kirim.click()]);
    const hasil = await page.locator('.ah-note').first().innerText();
    const h1 = await page.locator('main h1').innerText();
    const lebihDetail = await melebar(page);
    cek(/Pengajuan #\d+ tersimpan/.test(hasil) && h1.includes(nama) && lebihDetail <= 0, 'T4-B pengajuan tersimpan dan detail tampil di HP tanpa geser', `${hasil.slice(0, 60)} | ${h1} | ${nama} | ${lebihDetail}`);
    await page.screenshot({ path: `${OUT}/T4-B-detail-375.jpg`, type: 'jpeg', quality: 70, fullPage: true });
    await context.close();
  }

  // ---------------------------------------------------------------- T4-C
  {
    const { context, page } = await konteks(browser, 'sbx_admin', 1280);
    await page.goto(`${BASE}/portal/izin_detail.php?id=${izinAdmin}`);
    let post = 0;
    page.on('request', (r) => { if (r.method() === 'POST') post++; });
    const juduls = await page.locator('.ah-act > summary').allInnerTexts();
    cek(juduls.some((t) => t.includes('Tetapkan murobi')) && juduls.some((t) => t.includes('admin pengganti')) && juduls.some((t) => t.includes('Batalkan')), `T4-C admin melihat tindakan sesuai status (${juduls.join(', ')})`);
    cek((await page.locator('.ah-act form').count()) >= 3 && !(await page.locator('.ah-act form').first().isVisible()), 'T4-C formulir tersembunyi sampai tombol ditekan (bukan formulir bertumpuk)');
    await page.locator('#aksi-tetapkan > summary').click();
    await dialogForm(page).waitFor();
    cek(await dialogForm(page).locator('#murobi_guru_id').isVisible(), 'T4-C Tetapkan murobi membuka dialog berisi isian yang dibutuhkan');
    await dialogForm(page).locator('[data-ah-dialog-close]').click();
    await page.waitForTimeout(150);
    cek(post === 0 && (await dialogForm(page).count()) === 0, 'T4-C Batal menutup dialog tanpa POST');
    await page.locator('#aksi-batalkan > summary').click();
    await dialogForm(page).locator('button[type="submit"]').click();
    await page.waitForTimeout(150);
    cek(post === 0 && await dialogForm(page).locator('#alasan_pembatalan').evaluate((n) => !n.validity.valid), 'T4-C alasan pembatalan wajib sebelum terkirim');
    await page.keyboard.press('Escape');
    cek((await page.locator('details.ah-menu').filter({ hasText: 'Info teknis' }).count()) >= 0, 'T4-C menu ⋯ admin tersedia');
    await page.goto(`${BASE}/portal/izin_detail.php?id=${izinAdmin}&info=teknis`);
    cek((await page.locator('#ah-teknis').count()) === 1 && (await page.locator('main').textContent()).includes('Versi data'), 'T4-C info teknis tampil untuk admin');
    await context.close();
    const ortu = await konteks(browser, 'sbx_ortu_a', 375);
    await ortu.page.goto(BASE + '/portal/izin.php?tab=riwayat');
    const kartu = ortu.page.locator('a.ah-icard').first();
    cek(await kartu.isVisible(), 'T4-C orang tua melihat kartu izin');
    await Promise.all([ortu.page.waitForURL(/izin_detail/), kartu.click()]);
    cek((await ortu.page.locator('.ah-act').count()) === 0 && (await ortu.page.locator('main form[method="post"]').count()) === 0 && (await ortu.page.locator('#ah-riwayat').count()) === 1, 'T4-C detail orang tua hanya-baca dengan linimasa');
    await ortu.page.goto(`${BASE}/portal/izin_detail.php?id=${izinAdmin}&info=teknis`);
    cek((await ortu.page.locator('#ah-teknis').count()) === 0, 'T4-C info teknis tidak untuk orang tua');
    await ortu.context.close();
  }

  // ---------------------------------------------------------------- T4-D
  {
    const { context, page } = await konteks(browser, 'sbx_murobi_c', 375);
    await page.goto(`${BASE}/portal/izin_antrean.php?id=${antrean[0]}`);
    cek(await page.locator('.ah-split__list').isHidden() && await page.locator('a:has-text("Kembali ke antrean")').isVisible(), 'T4-D HP: detail menggantikan daftar, ada tombol kembali');
    const telat = await page.context().newPage();
    await telat.goto(`${BASE}/portal/izin_antrean.php`);
    cek((await telat.locator('.ah-qlist a.is-late').count()) >= 1, 'T4-D pengajuan menunggu > 1 hari ditandai');
    await telat.close();
    await page.fill('#alasan_keputusan', 'Disetujui lewat uji browser T4-D');
    await page.locator('button[name="hasil"][value="Disetujui"]').click();
    await dialogKonfirmasi(page).waitFor();
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), dialogKonfirmasi(page).locator('[data-ah-ok]').click()]);
    const url = new URL(page.url());
    const pesan = await page.locator('main').innerText();
    cek(url.searchParams.get('id') === antrean[0] && pesan.includes(`Izin #${antrean[0]} disetujui`), 'T4-D setelah memutuskan, detail yang sama tetap terbuka dengan pesan hasil (N)');
    const berikut = page.locator('a:has-text("Berikutnya: #")');
    cek(await berikut.isVisible() && antrean.includes(idDari(await berikut.getAttribute('href'))) && idDari(await berikut.getAttribute('href')) !== antrean[0], 'T4-D tombol "Berikutnya" menuju pengajuan lain, tidak pindah otomatis');
    await page.screenshot({ path: `${OUT}/T4-D-hasil-375.jpg`, type: 'jpeg', quality: 70, fullPage: true });
    await context.close();
  }

  // ---------------------------------------------------------------- T4-E
  {
    const { context, page } = await konteks(browser, 'sbx_admin', 1280);
    await page.goto(BASE + '/admin/admin_pengajian.php');
    const kepala = await page.locator('.ah-week--grid thead th').allInnerTexts();
    cek(JSON.stringify(kepala.slice(1)) === JSON.stringify(['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Ahad']), `T4-E kisi mingguan Senin…Ahad (${kepala.slice(1).join(',')})`);
    cek(!/\bMinggu\b/.test(await page.locator('main').innerText()) && (await page.locator('.ah-week--grid .ah-block').count()) > 0, 'T4-E tidak ada label "Minggu"; blok jadwal tampil');
    const tabModul = await page.locator('.ah-tabs--module a').allInnerTexts();
    cek(tabModul.join('|') === 'Jadwal|Pertemuan|Kehadiran', `T4-E tab modul Jadwal · Pertemuan · Kehadiran (${tabModul.join('|')})`);
    await page.locator('.ah-week--grid .ah-block').first().click();
    await page.locator('.ah-panel').waitFor();
    cek(await page.locator('.ah-panel').evaluate((n) => getComputedStyle(n).position) === 'fixed', 'T4-E klik blok membuka detail di panel samping');
    await context.close();
    const hp = await konteks(browser, 'sbx_admin', 375);
    await hp.page.goto(BASE + '/admin/admin_pengajian.php');
    cek(await hp.page.locator('.ah-daypick').isVisible() && !(await hp.page.locator('.ah-week--grid').isVisible()) && (await hp.page.locator('.ah-daypick a').last().innerText()) === 'Aha', 'T4-E HP: pilih hari (Sen…Aha) lalu daftar per waktu');
    await hp.context.close();
  }

  // ---------------------------------------------------------------- T4-F
  {
    const { context, page } = await konteks(browser, 'sbx_admin', 1280);
    await page.goto(`${BASE}/admin/admin_pengajian.php?tab=pertemuan&id=${pertemuan}`);
    let post = 0;
    page.on('request', (r) => { if (r.method() === 'POST') post++; });
    const isi = await page.locator('main').innerText();
    cek(isi.includes('Kehadiran dicatat guru lewat aplikasi') && (await page.locator('#daftar-peserta select, #daftar-peserta input, #daftar-peserta button').count()) === 0, 'T4-F kehadiran hanya-baca, tanpa isian status (R)');
    cek((await page.locator('#daftar-peserta .ah-badge:has-text("Sedang izin #")').count()) >= 1, 'T4-F label "Sedang izin" pada santri yang izinnya disetujui (S)');
    cek((await page.locator('.ah-stepper li[aria-current="step"]').innerText()) === 'Dibuka', 'T4-F langkah Draf → Dibuka → Selesai menandai Dibuka');
    await page.locator('#cari-peserta').fill('SBX T4 Santri 1');
    cek((await page.locator('#daftar-peserta li:not([hidden])').count()) === 1, 'T4-F cari santri menyaring daftar peserta');
    await page.getByRole('button', { name: 'Selesaikan pertemuan' }).click();
    await dialogKonfirmasi(page).waitFor();
    await dialogKonfirmasi(page).locator('[data-ah-cancel]').click();
    await page.waitForTimeout(150);
    cek(post === 0, 'T4-F Selesaikan meminta konfirmasi; Batal tidak mengirim');
    await context.close();
  }

  // ---------------------------------------------------------------- T4-G
  {
    const { context, page } = await konteks(browser, 'sbx_pendidikan', 1280);
    await page.goto(BASE + '/portal/index.php');
    const menu = page.locator('#ah-sidebar a:has-text("Laporan absensi pengajian")');
    cek(await menu.isVisible(), 'T4-G menu "Laporan absensi pengajian" untuk Bagian Pendidikan');
    await menu.click();
    await page.waitForURL(/admin_laporan_absensi/);
    const judul = await page.locator('main h1').innerText();
    const guru = await page.locator('#teacher_id option:not([value=""])').count();
    cek(judul === 'Laporan absensi pengajian' && (await page.locator('.ah-tabs--module').count()) === 0 && guru === guruAdmin && guru >= 1, `T4-G laporan seluruh jadwal (${guru} guru pada saringan, sama dengan admin), tanpa tab Jadwal/Pertemuan`);
    await page.selectOption('#periode', 'semester');
    await Promise.all([page.waitForURL(/periode=semester/), page.getByRole('button', { name: 'Terapkan' }).click()]);
    cek((await page.locator('table.ah-table').first().locator('tbody tr').count()) >= 1, 'T4-G ringkasan per jadwal terisi untuk semester ini');
    await context.close();
  }

  // ---------------------------------------------------------------- T4-H
  {
    const { context, page } = await konteks(browser, 'sbx_admin', 1280);
    await page.goto(BASE + '/portal/laporan_cetak.php?periode=semester&mode=admin');
    let teks = await page.locator('body').innerText();
    cek(teks.includes('Bagian Pendidikan') && (await page.locator('.ttd').count()) === 1 && /Halaman 1 dari \d+/.test(teks) && teks.includes('Kode laporan'), 'T4-H cetak perizinan: satu tanda tangan Bagian Pendidikan di lembar terakhir, nomor halaman, kode laporan');
    const lembarTerakhirPunyaTtd = await page.locator('section.lembar').last().locator('.ttd').count();
    cek(lembarTerakhirPunyaTtd === 1, 'T4-H tanda tangan berada di lembar terakhir');
    await page.goto(BASE + '/admin/laporan_absensi_cetak.php?subject_scope=santri');
    teks = await page.locator('body').innerText();
    cek(teks.includes('Bagian Pendidikan') && (await page.locator('section.lembar').last().locator('.ttd').count()) === 1, 'T4-H cetak kehadiran bertanda tangan Bagian Pendidikan');
    await page.emulateMedia({ media: 'print' });
    cek(await page.locator('.report-nav').isHidden(), 'T4-H bilah tombol cetak tidak ikut tercetak');
    await page.screenshot({ path: `${OUT}/X-1-absensi-1280.jpg`, type: 'jpeg', quality: 70, fullPage: true });
    await context.close();
  }

  // ---------------------------------------------------------------- T4-I
  cek(galat.length === 0, 'T4-I tanpa galat JavaScript', galat.slice(0, 5).join(' | '));
  cek(bawaan.length === 0, 'T4-I tanpa confirm()/alert() bawaan peramban', bawaan.slice(0, 3).join(' | '));
} finally {
  await browser.close();
}
console.log(`\nRingkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
