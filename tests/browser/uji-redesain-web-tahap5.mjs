/**
 * Uji browser redesain website Tahap 5 — pembinaan.
 *
 * Melengkapi uji server `tests/redesain_web_tahap5_http.php` dengan perilaku
 * yang hanya terlihat di peramban, pada 375px, 768px, dan 1280px:
 *
 *   T5-A  setiap layar V-1 s.d. V-7 dan M-4 untuk akun yang memakainya: tanpa
 *         geser horizontal, satu h1, kerangka bersama, tabel menjadi kartu di
 *         HP, tombol utama ≥ 44 px, panel samping di desktop, tanpa isian ID;
 *   T5-B  pengurus mencatat pelanggaran dari HP: panel, jenis menampilkan
 *         tingkat/poin dan total poin setelah catatan, kirim, detail tampil;
 *   T5-C  V-2: Batalkan catatan lewat dialog (Batal tidak mengirim), tombol
 *         publikasi nonaktif dengan sebab pada pelanggaran bertaut kasus Rahasia;
 *   T5-D  V-3: papan — kasus Rahasia hanya untuk pemilik dan admin (kartu dan
 *         angka kolom), wizard buka kasus dua langkah;
 *   T5-E  V-4: dialog "Catat hasil sesi" hanya menampilkan isian yang relevan
 *         dengan hasil; jadwal ulang tersimpan;
 *   T5-F  V-5: wizard Penerima → Isi → Pratinjau → Terbitkan;
 *   T5-G  V-7: orang tua melihat judul bertanggal, nama anak, pengirim, tanpa
 *         jenis sumber; "Saya sudah membaca";
 *   T5-H  V-6: saringan bernama (tanpa isian ID), ringkasan angka;
 *   T5-I  tanpa galat JavaScript, tanpa confirm()/alert() bawaan.
 *
 *   bash tests/redesain_web_tahap5_db.sh
 *   DB_NAME=test_redesain_t5_test php -S 127.0.0.1:8981 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8981 node tests/browser/uji-redesain-web-tahap5.mjs
 *
 * Uji ini MENGUBAH data uji (pelanggaran, sesi, publikasi baru). Bangun ulang
 * DB sebelum mengulang. Nomor catatan mengikuti seed Tahap 5 pada DB baru.
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8981';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-redesain-web-tahap5', import.meta.url).pathname;
const SANDI = process.env.UJI_SANDI ?? 'Sandbox#123';
const LEBAR = [375, 768, 1280];
const SEED = { a1Tsb: 2, a1Bls: 3, a2: 4, c1: 5, kasusInternal: 1, kasusRahasia: 2, pubKasus: 1 };
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
  cek(masalah.length === 0, `T5-A ${nama} ${lebar}px: rapi di kerangka bersama`, [...new Set(masalah)].join('; '));
}

const LAYAR = {
  sbx_admin: {
    'V-1-admin': '/portal/v3_pelanggaran.php', 'V-2-admin': `/portal/v3_pelanggaran_detail.php?id=${SEED.a1Bls}`,
    'V-3-admin': '/portal/v3_konseling.php', 'V-3-daftar': '/portal/v3_konseling.php?tampil=daftar',
    'V-4-admin-rahasia': `/portal/v3_konseling_detail.php?id=${SEED.kasusRahasia}`, 'V-5-daftar': '/portal/v3_publikasi_kelola.php',
    'V-5-kelola': `/portal/v3_publikasi_kelola.php?id=${SEED.pubKasus}`, 'V-6-admin': '/portal/v3_laporan.php',
    'M-4': '/admin/admin_v3_katalog.php', 'M-4-ambang-ubah': '/admin/admin_v3_katalog.php?jenis=ambang&edit=1',
  },
  sbx_pengurus_a: {
    'V-1-pengurus': '/portal/v3_pelanggaran.php', 'V-1-catat': '/portal/v3_pelanggaran.php?action=create',
    'V-2-rahasia': `/portal/v3_pelanggaran_detail.php?id=${SEED.a2}`, 'V-2-poin': `/portal/v3_pelanggaran_detail.php?id=${SEED.a1Bls}&tab=poin`,
    'V-3-pengurus': '/portal/v3_konseling.php', 'V-3-wizard-2': '/portal/v3_konseling.php?action=create&santri=1%7C1',
    'V-4-pengurus': `/portal/v3_konseling_detail.php?id=${SEED.kasusInternal}`, 'V-4-riwayat': `/portal/v3_konseling_detail.php?id=${SEED.kasusInternal}&tab=riwayat`,
    'V-5-siapkan': `/portal/v3_publikasi_kelola.php?sumber_type=pelanggaran&sumber_id=${SEED.a1Tsb}`, 'V-6-pengurus': '/portal/v3_laporan.php?jenis=kasus',
  },
  sbx_murobi_a: {
    'V-1-murobi': '/portal/v3_pelanggaran.php', 'V-2-murobi': `/portal/v3_pelanggaran_detail.php?id=${SEED.a1Bls}`,
    'V-3-murobi': '/portal/v3_konseling.php', 'V-4-murobi': `/portal/v3_konseling_detail.php?id=${SEED.kasusInternal}`, 'V-6-murobi': '/portal/v3_laporan.php',
  },
  sbx_ortu_a: { 'V-7-daftar': '/portal/v3_publikasi.php', 'V-7-detail': `/portal/v3_publikasi.php?id=${SEED.pubKasus}`, 'V-6-ortu': '/portal/v3_laporan.php' },
};
const TANGKAP = ['V-1-pengurus', 'V-1-catat', 'V-2-admin', 'V-2-rahasia', 'V-3-admin', 'V-3-wizard-2', 'V-4-pengurus', 'V-5-daftar', 'V-5-siapkan', 'V-6-admin', 'V-7-daftar', 'V-7-detail', 'M-4', 'M-4-ambang-ubah'];

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  // ---------------------------------------------------------------- T5-A
  for (const [user, layar] of Object.entries(LAYAR)) {
    for (const lebar of LEBAR) {
      const { context, page } = await konteks(browser, user, lebar);
      for (const [nama, alamat] of Object.entries(layar)) {
        const r = await page.goto(BASE + alamat);
        if (r.status() !== 200) { cek(false, `T5-A ${nama} ${lebar}px`, `HTTP ${r.status()}`); continue; }
        await periksaLayar(page, nama, lebar);
        if (TANGKAP.includes(nama)) await page.screenshot({ path: `${OUT}/${nama}-${lebar}.jpg`, type: 'jpeg', quality: 70, fullPage: lebar === 375 });
      }
      await context.close();
    }
  }

  // ---------------------------------------------------------------- T5-B
  {
    const { context, page } = await konteks(browser, 'sbx_pengurus_a', 375);
    await page.goto(BASE + '/portal/v3_pelanggaran.php');
    await page.getByRole('link', { name: 'Catat pelanggaran' }).first().click();
    await page.waitForSelector('#ah-panel');
    await pilih(page, '#v3-santri', /SBX Santri C1/);
    await pilih(page, '#v3-katalog', /Membawa HP tanpa izin/);
    const petunjuk = await page.locator('[data-ah-poin-preview]').innerText();
    cek(/Berat · 25 poin\. Total poin santri setelah catatan ini: 35\./.test(petunjuk), 'T5-B pilihan jenis menampilkan tingkat, poin, dan total setelah catatan', petunjuk);
    await page.fill('#v3-uraian', 'Membawa HP ke kelas (uji browser T5-B).');
    cek(await melebar(page) <= 0, 'T5-B formulir catat di HP tanpa geser horizontal');
    await Promise.all([page.waitForURL(/v3_pelanggaran_detail\.php\?id=\d+/), page.getByRole('button', { name: 'Simpan catatan' }).click()]);
    const teks = await teksMain(page);
    cek(teks.includes('Membawa HP tanpa izin') && teks.includes('SBX Santri C1') && teks.includes('35'), 'T5-B detail tampil dengan jenis, santri, dan total poin baru');
    cek(await melebar(page) <= 0, 'T5-B detail di HP tanpa geser horizontal');
    await page.screenshot({ path: `${OUT}/T5-B-detail-375.jpg`, type: 'jpeg', quality: 70, fullPage: true });
    await context.close();
  }

  // ---------------------------------------------------------------- T5-C
  {
    const { context, page } = await konteks(browser, 'sbx_pengurus_a', 1280);
    await page.goto(BASE + `/portal/v3_pelanggaran_detail.php?id=${SEED.a2}`);
    const tombol = page.getByRole('button', { name: 'Siapkan publikasi ke orang tua' });
    cek(await tombol.isDisabled() && (await page.locator('#sebab-publikasi').innerText()).includes('tidak dapat'), 'T5-C pelanggaran bertaut kasus Rahasia: publikasi nonaktif dengan sebab');
    cek(!(await page.locator('main').innerHTML()).includes('<script>window.T5_XSS') && !(await page.evaluate(() => Boolean(window.T5_XSS))), 'T5-C uraian bertag tetap di-escape');
    await page.goto(BASE + `/portal/v3_pelanggaran_detail.php?id=${SEED.c1}`);
    await page.locator('#aksi-batal > summary').click();
    cek(await dialogForm(page).isVisible() && (await dialogForm(page).innerText()).includes('Poin 10 akan dikurangkan'), 'T5-C Batalkan catatan membuka dialog berisi akibatnya');
    await dialogForm(page).getByRole('button', { name: 'Kembali' }).click();
    cek(!(await dialogForm(page).count()) && (await teksMain(page)).includes('Dicatat'), 'T5-C Kembali menutup dialog tanpa mengirim');
    await page.locator('#aksi-batal > summary').click();
    await dialogForm(page).locator('textarea[name="alasan"]').fill('Salah santri (uji browser).');
    await Promise.all([page.waitForLoadState('load'), dialogForm(page).getByRole('button', { name: 'Batalkan catatan' }).click()]);
    cek((await teksMain(page)).includes('Dibatalkan'), 'T5-C pembatalan beralasan tersimpan');
    await context.close();
  }

  // ---------------------------------------------------------------- T5-D
  {
    const hitung = async (user) => {
      const { context, page } = await konteks(browser, user, 1280);
      await page.goto(BASE + '/portal/v3_konseling.php');
      const kartu = await page.locator('.ah-board').innerText();
      const angka = await page.locator('.ah-board__head .ah-chips__count').allInnerTexts();
      await context.close();
      return { kartu, total: angka.reduce((a, b) => a + Number(b), 0) };
    };
    const admin = await hitung('sbx_admin');
    const pemilik = await hitung('sbx_pengurus_a');
    const lain = await hitung('sbx_pengurus_b');
    const murobi = await hitung('sbx_murobi_a');
    cek(admin.kartu.includes('SBX Santri A2') && pemilik.kartu.includes('SBX Santri A2') && admin.total === 2 && pemilik.total === 2, 'T5-D kasus Rahasia tampil bagi admin dan pembimbing pemilik', JSON.stringify([admin.total, pemilik.total]));
    cek(!lain.kartu.includes('SBX Santri A2') && lain.total === 1 && !murobi.kartu.includes('SBX Santri A2') && murobi.total === 1, 'T5-D kasus Rahasia tidak tampil dan tidak terhitung bagi pembimbing lain dan murobi', JSON.stringify([lain.total, murobi.total]));
    cek(!lain.kartu.includes('Rahasia') && !murobi.kartu.includes('Rahasia'), 'T5-D tanpa petunjuk adanya kasus tersembunyi');
    const { context, page } = await konteks(browser, 'sbx_pengurus_a', 375);
    await page.goto(BASE + '/portal/v3_konseling.php');
    await page.getByRole('link', { name: 'Buka kasus' }).first().click();
    cek((await page.locator('.ah-steps .is-current').innerText()).includes('Pilih santri'), 'T5-D wizard langkah 1: pilih santri');
    await pilih(page, '#case-student', /SBX Santri C1/);
    await Promise.all([page.waitForURL(/santri=/), page.getByRole('button', { name: 'Lanjut' }).click()]);
    cek((await page.locator('.ah-steps .is-current').innerText()).includes('Tujuan') && (await page.locator('input[name="pelanggaran_ids[]"]').count()) >= 1, 'T5-D wizard langkah 2 memuat pelanggaran santri itu saja');
    await page.fill('#case-purpose', 'Pendampingan disiplin (uji browser).');
    await page.selectOption('#case-privacy', 'Internal');
    await Promise.all([page.waitForURL(/v3_konseling_detail\.php\?id=\d+/), page.getByRole('button', { name: 'Buka kasus' }).click()]);
    cek((await teksMain(page)).includes('Kasus konseling · SBX Santri C1') && await melebar(page) <= 0, 'T5-D kasus terbuka dari HP tanpa geser horizontal');
    await context.close();
  }

  // ---------------------------------------------------------------- T5-E
  {
    const { context, page } = await konteks(browser, 'sbx_pengurus_a', 1280);
    await page.goto(BASE + `/portal/v3_konseling_detail.php?id=${SEED.kasusInternal}`);
    await page.getByText('Catat hasil sesi', { exact: true }).first().click();
    const d = dialogForm(page);
    const terlihat = async (nama) => d.locator(`[name="${nama}"]`).isVisible();
    cek(await terlihat('ringkasan_internal') && await terlihat('realisasi') && !(await terlihat('alasan')) && !(await terlihat('jadwal')), 'T5-E hasil Selesai: hanya waktu, ringkasan, hasil, tindak lanjut');
    await d.locator('select[name="status"]').selectOption('Dijadwalkan Ulang');
    cek(await terlihat('jadwal') && await terlihat('alasan') && !(await terlihat('ringkasan_internal')) && !(await terlihat('realisasi')), 'T5-E hasil Dijadwalkan ulang: hanya jadwal baru dan alasan');
    await d.locator('select[name="status"]').selectOption('Dibatalkan');
    cek(await terlihat('alasan') && !(await terlihat('jadwal')), 'T5-E hasil Dibatalkan: hanya alasan');
    await d.locator('select[name="status"]').selectOption('Dijadwalkan Ulang');
    await d.locator('[name="alasan"]').fill('Bentrok kegiatan (uji browser).');
    await Promise.all([page.waitForLoadState('load'), d.getByRole('button', { name: 'Simpan hasil' }).click()]);
    cek((await teksMain(page)).includes('Dijadwalkan Ulang') && (await teksMain(page)).includes('Bentrok kegiatan'), 'T5-E jadwal ulang tersimpan dengan alasan');
    await context.close();
  }

  // ---------------------------------------------------------------- T5-F
  {
    const { context, page } = await konteks(browser, 'sbx_pengurus_a', 768);
    await page.goto(BASE + `/portal/v3_publikasi_kelola.php?sumber_type=pelanggaran&sumber_id=${SEED.a1Tsb}`);
    cek((await page.locator('.ah-steps').innerText()).includes('Pratinjau'), 'T5-F wizard Penerima → Isi → Pratinjau');
    await page.fill('#ringkasan', 'Ananda terlambat sholat berjamaah (uji browser).');
    await page.fill('#tindak-lanjut', 'Mohon ingatkan di rumah.');
    await Promise.all([page.waitForSelector('#ah-pratinjau'), page.getByRole('button', { name: 'Lihat pratinjau' }).click()]);
    const pratinjau = await teksMain(page);
    cek(pratinjau.includes('berlaku 30 menit') && /\bdari\b/i.test(pratinjau) && pratinjau.includes('SBX Pengurus A (pembimbing)') && pratinjau.includes('SBX Santri A1'), 'T5-F pratinjau seperti dilihat wali, dengan batas waktu dan pengirim', pratinjau.replace(/\s+/g, ' ').slice(0, 600));
    await page.check('#konfirmasi');
    await Promise.all([page.waitForURL(/v3_publikasi_kelola\.php\?id=\d+/), page.getByRole('button', { name: 'Terbitkan' }).click()]);
    cek((await teksMain(page)).includes('Terbit'), 'T5-F publikasi terbit dan halaman kelola terbuka');
    await context.close();
  }

  // ---------------------------------------------------------------- T5-G
  {
    const { context, page } = await konteks(browser, 'sbx_ortu_a', 375);
    await page.goto(BASE + '/portal/v3_publikasi.php?baca=belum');
    const daftar = await page.locator('.ah-pub-list').innerText();
    cek(daftar.includes('Informasi pembinaan · ') && daftar.includes('SBX Santri A1 · dari SBX Pengurus A (pembimbing)'), 'T5-G daftar: judul bertanggal, nama anak, pengirim');
    cek(!/pelanggaran|konseling|kasus|sesi/i.test(daftar), 'T5-G jenis sumber tidak ditampilkan');
    await page.locator('.ah-pub-list a').first().click();
    await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: 'Saya sudah membaca' }).click()]);
    cek((await teksMain(page)).includes('Ditandai dibaca'), 'T5-G tanda baca tersimpan');
    cek(await melebar(page) <= 0, 'T5-G detail di HP tanpa geser horizontal');
    await context.close();
  }

  // ---------------------------------------------------------------- T5-H
  {
    const { context, page } = await konteks(browser, 'sbx_admin', 1280);
    await page.goto(BASE + '/portal/v3_laporan.php');
    cek((await page.locator('main input[type="number"][name$="_id"], main input[type="text"][name$="_id"]').count()) === 0
      && (await page.locator('select[name="kelas_id"]').count()) === 1 && (await page.locator('select[name="pembimbing_id"]').count()) === 1, 'T5-H saringan kelas/kamar/pembimbing/murobi berupa pilihan bernama');
    await page.selectOption('#kamar_id', { label: 'SBX Kamar A' });
    await Promise.all([page.waitForURL(/kamar_id=/), page.getByRole('button', { name: 'Terapkan' }).click()]);
    const isi = await page.locator('table.ah-table tbody').innerText();
    cek(isi.includes('SBX Santri A1') && !isi.includes('SBX Santri C1'), 'T5-H saringan kamar bernama menyaring baris');
    cek(/santri ≥ ambang/i.test(await page.locator('.ah-stats').innerText()), 'T5-H ringkasan angka tampil');
    await page.screenshot({ path: `${OUT}/T5-H-laporan-1280.jpg`, type: 'jpeg', quality: 70, fullPage: true });
    await context.close();
  }

  // ---------------------------------------------------------------- T5-I
  cek(galat.length === 0, 'T5-I tanpa galat JavaScript', galat.slice(0, 5).join(' | '));
  cek(bawaan.length === 0, 'T5-I tanpa confirm()/alert() bawaan peramban', bawaan.slice(0, 3).join(' | '));
} finally {
  await browser.close();
}
console.log(`\nRingkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
