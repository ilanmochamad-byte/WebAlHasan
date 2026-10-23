/**
 * Uji browser redesain website Tahap 3 — halaman lama ke kerangka baru.
 *
 * Melengkapi uji server `tests/redesain_web_tahap3_http.php` dengan perilaku
 * yang hanya terlihat di peramban, pada 375px, 768px, dan 1280px:
 *
 *   T3-A  setiap layar N-2, N-4, N-5, K-5 s.d. K-8, W-1 s.d. W-4: tanpa geser
 *         horizontal, satu h1, kerangka bersama (bukan sidebar lama), tabel
 *         menjadi kartu di HP, saringan terlipat di HP dan terbuka di layar
 *         lebar, panel samping di desktop;
 *   T3-B  K-5 dialog Tolak (Batal tidak mengirim), Hapus meminta ketik nama;
 *   T3-C  K-6 Tandai lunas lewat dialog; Batal lunas mewajibkan alasan;
 *   T3-D  N-2 coba ulang massal satu per satu dengan bilah kemajuan dan
 *         daftar gagal; kartu WhatsApp tanpa tombol Nyalakan;
 *   T3-E  W-3 zona unggah menampilkan berkas terpilih; W-2 Pratinjau;
 *   T3-F  K-7 matriks dengan kolom lengket (desktop) dan lipatan (HP);
 *   T3-G  tanpa galat JavaScript, tanpa confirm()/alert() bawaan.
 *
 *   bash tests/redesain_web_tahap3_db.sh
 *   DB_NAME=test_redesain_t3_test php -S 127.0.0.1:8951 tests/redesain_web_tahap1_router.php &
 *   BASE_URL=http://127.0.0.1:8951 node tests/browser/uji-redesain-web-tahap3.mjs
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync, existsSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8951';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-redesain-web-tahap3', import.meta.url).pathname;
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
    // jQuery/Summernote sengaja tidak dimuat: penyunting berita harus tetap
    // berfungsi sebagai kotak teks biasa bila CDN tidak tersedia.
    return route.abort();
  });
}
async function konteks(browser, lebar = 1280) {
  const context = await browser.newContext({ viewport: { width: lebar, height: lebar < 576 ? 812 : 900 } });
  await siapkan(context);
  const page = await context.newPage();
  page.on('pageerror', (e) => galat.push(`${lebar}: ${e.message}`));
  page.on('console', (m) => { if (m.type() === 'error' && !/net::ERR_|Failed to load resource/.test(m.text())) galat.push(`${lebar}: ${m.text()}`); });
  page.on('dialog', async (d) => { bawaan.push(d.type() + ': ' + d.message()); await d.dismiss(); });
  await page.goto(BASE + '/portal/index.php');
  await page.fill('#username', 'sbx_admin');
  await page.fill('#password', SANDI);
  await Promise.all([page.waitForURL(/\/portal\/index\.php/), page.getByRole('button', { name: 'Masuk', exact: true }).click()]);
  return { context, page };
}
const melebar = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const dialog = (page) => page.locator('dialog.ah-dialog[open]');

const LAYAR = {
  'N-2-kanal': '/admin/admin_notifikasi.php', 'N-2-gagal': '/admin/admin_notifikasi.php?tab=gagal', 'N-2-riwayat': '/admin/admin_notifikasi.php?tab=riwayat',
  'N-4-psb': '/admin/admin_dashboard.php', 'N-4-santri': '/admin/admin_dashboard.php?tema=santri', 'N-4-kegiatan': '/admin/admin_dashboard.php?tema=kegiatan',
  'N-5': '/admin/admin_pelanggaran.php', 'K-5': '/admin/admin_data.php', 'K-5-panel': '/admin/admin_data.php?id=__PSB__', 'K-5-tambah': '/admin/admin_data.php?action=create',
  'K-5-impor': '/admin/admin_data.php?action=impor', 'K-6': '/admin/admin_pembayaran_psb.php', 'K-6-panel': '/admin/admin_pembayaran_psb.php?id=__BAYAR__',
  'K-7': '/admin/admin_rekap_keuangan.php', 'K-8': '/admin/admin_rekap_santri.php', 'W-1': '/admin/admin_berita.php', 'W-2': '/admin/tambah_berita.php',
  'W-3': '/admin/admin_galeri.php', 'W-4': '/admin/admin_download.php', 'W-4-unggah': '/admin/admin_download.php?action=unggah',
};

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
try {
  // Ambil ID contoh dari daftar (tanpa akses basis data dari peramban).
  const awal = await konteks(browser);
  await awal.page.goto(BASE + '/admin/admin_data.php?status=Baru');
  const psb = new URL(await awal.page.locator('#daftar-pendaftar a.ah-row-link').first().getAttribute('href'), BASE).searchParams.get('id');
  await awal.page.goto(BASE + '/admin/admin_pembayaran_psb.php?status=Belum+Lunas');
  const bayar = new URL(await awal.page.locator('#daftar-pembayaran a.btn').first().getAttribute('href'), BASE).searchParams.get('id');
  await awal.context.close();

  // ---------------------------------------------------------------- T3-A
  for (const lebar of LEBAR) {
    const { context, page } = await konteks(browser, lebar);
    for (const [nama, alamat] of Object.entries(LAYAR)) {
      await page.goto(BASE + alamat.replace('__PSB__', psb).replace('__BAYAR__', bayar));
      const masalah = [];
      const lebih = await melebar(page);
      if (lebih > 0) masalah.push(`melebar ${lebih}px`);
      if ((await page.locator('main h1').count()) !== 1) masalah.push('h1 bukan satu');
      if ((await page.locator('#ah-shell').count()) !== 1 || (await page.locator('#sidebarMenu, .ah-legacy-sidebar').count()) > 0) masalah.push('bukan kerangka bersama');
      if (lebar === 375) {
        for (const tabel of await page.locator('table.ah-table--cards').all()) {
          if (await tabel.locator('thead').evaluate((n) => n.getBoundingClientRect().height > 2)) masalah.push('thead tabel kartu masih tampil');
        }
      }
      const saring = page.locator('details[data-ah-filter]').first();
      if (await saring.count()) {
        const buka = await saring.evaluate((n) => n.open);
        const aktif = await saring.evaluate((n) => n.hasAttribute('data-ah-filter-active'));
        if (lebar === 375 && buka !== aktif) masalah.push('saringan HP tidak terlipat');
        if (lebar > 375 && !buka) masalah.push('saringan layar lebar tidak terbuka');
      }
      if (lebar === 1280 && await page.locator('.ah-panel').count()) {
        const posisi = await page.locator('.ah-panel').evaluate((n) => getComputedStyle(n).position);
        if (posisi !== 'fixed') masalah.push('panel bukan panel samping');
      }
      for (const tombol of await page.locator('.ah-page-head__actions .btn, .ah-panel__foot .btn, .ah-filter__actions .btn').all()) {
        if (await tombol.isVisible()) {
          const kotak = await tombol.boundingBox();
          if (kotak && kotak.height < 43.5) masalah.push(`tombol ${Math.round(kotak.height)}px`);
        }
      }
      cek(masalah.length === 0, `T3-A ${nama} ${lebar}px: rapi di kerangka bersama`, [...new Set(masalah)].join('; '));
      if (['K-5', 'K-5-panel', 'N-2-kanal', 'N-4-psb', 'K-7', 'W-3', 'W-2', 'K-6-panel'].includes(nama)) {
        await page.screenshot({ path: `${OUT}/${nama}-${lebar}.jpg`, type: 'jpeg', quality: 70, fullPage: lebar === 375 });
      }
    }
    await context.close();
  }

  const { context, page } = await konteks(browser, 1280);
  // ---------------------------------------------------------------- T3-B
  await page.goto(`${BASE}/admin/admin_data.php?id=${psb}`);
  let post = 0;
  page.on('request', (r) => { if (r.method() === 'POST') post++; });
  await page.locator(`#formStatusTolak${psb} button`).click();
  await dialog(page).waitFor();
  cek((await dialog(page).locator('.ah-dialog__title').innerText()) === 'Tolak pendaftar ini?' && (await dialog(page).locator('[data-ah-ok]').getAttribute('class')).includes('btn-danger'), 'T3-B Tolak membuka dialog merah yang menyebut tindakannya');
  await dialog(page).locator('[data-ah-cancel]').click();
  await page.waitForTimeout(150);
  cek(post === 0 && (await dialog(page).count()) === 0, 'T3-B Batal tidak mengirim POST');
  await page.locator('.ah-panel details.ah-menu > summary').click();
  await page.locator('.ah-panel form[action="hapus_siswa.php"] button').click();
  await dialog(page).waitFor();
  const label = await page.locator('label[for="ah-dialog-match"]').innerText();
  await dialog(page).locator('[data-ah-ok]').click();
  cek(label.startsWith('Ketik nama "') && (await dialog(page).count()) === 1 && post === 0, 'T3-B Hapus pendaftar meminta ketik nama; kosong tidak mengirim');
  await dialog(page).locator('[data-ah-cancel]').click();

  // ---------------------------------------------------------------- T3-C
  await page.goto(`${BASE}/admin/admin_pembayaran_psb.php?id=${bayar}`);
  await page.locator('.ah-panel form[data-confirm-title="Tandai lunas?"] button').click();
  await dialog(page).waitFor();
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), dialog(page).locator('[data-ah-ok]').click()]);
  cek((await page.locator('.ah-note').first().innerText()).includes('ditandai lunas') && (await page.locator('.ah-panel').innerText()).includes('Lunas'), 'T3-C Tandai lunas lewat dialog, hasil sebagai pesan');
  await page.locator('.ah-panel details.ah-menu > summary').click();
  await page.locator('.ah-panel form button:has-text("Batal lunas")').click();
  await dialog(page).waitFor();
  await dialog(page).locator('[data-ah-ok]').click();
  cek(!(await page.locator('#ah-dialog-reason-error').isHidden()), 'T3-C Batal lunas mewajibkan alasan');
  await page.locator('#ah-dialog-reason').fill('Uji browser Tahap 3');
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), dialog(page).locator('[data-ah-ok]').click()]);
  cek((await page.locator('.ah-note').first().innerText()).includes('dibatalkan'), 'T3-C Batal lunas dengan alasan tersimpan');

  // ---------------------------------------------------------------- T3-D
  await page.goto(`${BASE}/admin/admin_notifikasi.php`);
  const wa = page.locator('.ah-channel--paused');
  cek((await wa.innerText()).includes('Ditangguhkan') && (await wa.locator('button:has-text("Nyalakan")').count()) === 0, 'T3-D kartu WhatsApp Ditangguhkan tanpa tombol Nyalakan');
  await page.goto(`${BASE}/admin/admin_notifikasi.php?tab=gagal`);
  const kotak = page.locator('input[name="outbox_ids[]"]');
  await kotak.nth(0).check();
  await kotak.nth(1).check();
  cek(await page.locator('.ah-selbar').isVisible() && (await page.locator('[data-ah-selcount]').innerText()).includes('2'), 'T3-D bilah pilihan menyebut 2 dipilih');
  await page.locator('.ah-selbar button[type="submit"]').click();
  await page.locator('.ah-bulk__summary').waitFor({ timeout: 15000 });
  const ringkas = await page.locator('.ah-bulk__summary').innerText();
  cek((await page.locator('.ah-bulk__count').innerText()) === '2 / 2' && /^\d berhasil · \d gagal$/.test(ringkas), `T3-D coba ulang massal satu per satu dengan kemajuan (${ringkas})`);
  cek(!ringkas.startsWith('2 berhasil') ? (await page.locator('.ah-bulk__failed li').count()) >= 1 : true, 'T3-D yang gagal didaftar beserta alasannya');

  // ---------------------------------------------------------------- T3-E
  await page.goto(`${BASE}/admin/admin_galeri.php`);
  const contoh = [`${tmpdir()}/t3-satu.png`, `${tmpdir()}/t3-dua.png`];
  for (const f of contoh) writeFileSync(f, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64'));
  await page.locator('input[name="foto[]"]').setInputFiles(contoh);
  cek((await page.locator('.ah-drop__list li').count()) === 2 && (await page.locator('.ah-drop__list').innerText()).includes('t3-satu.png'), 'T3-E zona unggah menampilkan dua berkas terpilih');
  await page.goto(`${BASE}/admin/tambah_berita.php`);
  await page.fill('#judul', 'Judul pratinjau T3');
  await page.fill('#isi', '<p>Isi pratinjau <b>tebal</b></p>');
  await page.locator('[data-berita-pratinjau]').click();
  const pratinjau = page.locator('#pratinjau-berita');
  cek(await pratinjau.evaluate((n) => n.open) && (await pratinjau.locator('#pratinjau-judul').innerText()) === 'Judul pratinjau T3' && (await pratinjau.locator('[data-pratinjau-isi] b').innerText()) === 'tebal', 'T3-E Pratinjau berita menampilkan judul dan isi seperti di situs');
  await pratinjau.locator('[data-pratinjau-tutup]').click();

  // ---------------------------------------------------------------- T3-F
  await page.goto(`${BASE}/admin/admin_rekap_keuangan.php?status=Semua`);
  const lengket = await page.locator('.ah-matrix tbody th[scope="row"]').first().evaluate((n) => getComputedStyle(n).position);
  cek(lengket === 'sticky' && await page.locator('.ah-matrix').isVisible() && !(await page.locator('.ah-fold').first().isVisible()), 'T3-F desktop: matriks dengan kolom komponen lengket');
  await context.close();
  const hp = await konteks(browser, 375);
  await hp.page.goto(`${BASE}/admin/admin_rekap_keuangan.php?status=Semua`);
  cek(!(await hp.page.locator('.ah-matrix').isVisible()) && (await hp.page.locator('.ah-fold').count()) === 3, 'T3-F HP: lipatan per unit (SMP, SMK, Lainnya)');
  await hp.context.close();

  // ---------------------------------------------------------------- T3-G
  cek(galat.length === 0, 'T3-G tanpa galat JavaScript', galat.slice(0, 5).join(' | '));
  cek(bawaan.length === 0, 'T3-G tanpa confirm()/alert() bawaan peramban', bawaan.slice(0, 3).join(' | '));
} finally {
  await browser.close();
}
console.log(`\nRingkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
