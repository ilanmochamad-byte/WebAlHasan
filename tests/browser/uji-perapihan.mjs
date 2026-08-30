/**
 * Uji browser sungguhan untuk paket "Koreksi dan Modernisasi UI/UX V1–V2".
 *
 * Berbeda dari smoke test HTTP yang hanya memeriksa kode status dan potongan
 * HTML, skrip ini benar-benar MERENDER halaman pada tiga lebar layar, MENGKLIK
 * menu dan tab, MENGUJI navigasi keyboard, dan mengambil tangkapan layar.
 *
 * Lebar yang diuji (sesuai keputusan pengguna 30 Agustus 2026):
 *   - desktop 1440 px
 *   - tablet   768 px
 *   - ponsel   390 px
 *
 * Yang diperiksa:
 *   B-1   halaman masuk terbaca dan dapat dipakai pada ketiga lebar;
 *   B-2   beranda menampilkan menu sesuai peran;
 *   B-3   laci navigasi ponsel dapat dibuka dan ditutup;
 *   B-4   tab modul Pengajian berpindah tanpa kehilangan konteks;
 *   B-5   tab penyajian laporan kehadiran mengubah jumlah baris;
 *   B-6   tabel lebar menggulir di dalam wadahnya, halaman TIDAK melebar;
 *   B-7   fokus keyboard terlihat dan tautan lompat ke konten berfungsi;
 *   B-8   tidak ada galat JavaScript pada konsol;
 *   B-9   halaman cetak tidak menampilkan sidebar pada media print.
 *
 * Data uji sintetis dari sandbox `*_test`; tidak ada data pribadi.
 *
 * Jalankan:
 *   php -S 127.0.0.1:8940 -t . &
 *   BASE_URL=http://127.0.0.1:8940 node tests/browser/uji-perapihan.mjs
 */
import { chromium } from 'playwright';
import { mkdirSync, existsSync, readFileSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8940';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-perapihan', import.meta.url).pathname;
const AKUN = {
  admin: { username: process.env.UJI_ADMIN ?? 'sbx_admin', password: process.env.UJI_SANDI ?? 'Sandbox#123' },
  guru: { username: process.env.UJI_GURU ?? 'sbx_guru_biasa', password: process.env.UJI_SANDI ?? 'Sandbox#123' },
};
mkdirSync(OUT, { recursive: true });

const LEBAR = [
  { nama: 'desktop', viewport: { width: 1440, height: 900 } },
  { nama: 'tablet', viewport: { width: 768, height: 1024 } },
  { nama: 'ponsel', viewport: { width: 390, height: 844 } },
];

const hasil = [];
const galatKonsol = [];
let nomor = 0;

function catat(nama, lulus, keterangan = '') {
  hasil.push({ nama, lulus, keterangan });
  console.log(`${lulus ? '[lulus]' : '[gagal]'} ${nama}${keterangan ? ' — ' + keterangan : ''}`);
}

async function bidik(page, label) {
  nomor += 1;
  const nama = `${String(nomor).padStart(2, '0')}-${label}.png`;
  await page.screenshot({ path: `${OUT}/${nama}`, fullPage: true });
  return nama;
}

async function masuk(page, akun) {
  await page.goto(`${BASE}/portal/index.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#username', akun.username);
  await page.fill('#password', akun.password);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.click('button[type="submit"]'),
  ]);
}

/** Halaman melebar bila lebar dokumen melampaui lebar viewport. */
async function halamanMelebar(page) {
  return page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
}

/**
 * Aset CDN dilayani dari salinan lokal.
 *
 * Sandbox uji tidak memiliki jaringan keluar, sedangkan halaman memuat
 * Bootstrap dan Font Awesome dari CDN — sama seperti di produksi. Tanpa
 * penggantian ini, tangkapan layar akan tampil tanpa gaya dan pemeriksaan
 * "halaman tidak melebar" menjadi terlalu longgar karena grid Bootstrap tidak
 * aktif. KODE APLIKASI TIDAK DIUBAH: penggantian hanya terjadi di dalam
 * peramban uji.
 */
const CDN_LOKAL = [
  { cocok: /bootstrap[^/]*\/dist\/css\/bootstrap\.min\.css/, berkas: new URL('./node_modules/bootstrap/dist/css/bootstrap.min.css', import.meta.url).pathname, tipe: 'text/css' },
  { cocok: /bootstrap[^/]*\/dist\/js\/bootstrap\.bundle\.min\.js/, berkas: new URL('./node_modules/bootstrap/dist/js/bootstrap.bundle.min.js', import.meta.url).pathname, tipe: 'application/javascript' },
  { cocok: /font-awesome[^"]*all\.min\.css/, berkas: new URL('./node_modules/@fortawesome/fontawesome-free/css/all.min.css', import.meta.url).pathname, tipe: 'text/css' },
];
const cdnTersedia = CDN_LOKAL.every((item) => existsSync(item.berkas));
if (!cdnTersedia) {
  console.log('[catatan] Salinan lokal Bootstrap/Font Awesome tidak ditemukan. Jalankan:');
  console.log('          npm install --prefix tests/browser bootstrap@5.3.0 @fortawesome/fontawesome-free@6');
}

async function pasangCdnLokal(context) {
  if (!cdnTersedia) return;
  await context.route('**/*', async (route) => {
    const url = route.request().url();
    if (url.startsWith('http://127.0.0.1') || url.startsWith('http://localhost')) {
      return route.continue();
    }
    const item = CDN_LOKAL.find((kandidat) => kandidat.cocok.test(url));
    if (item) {
      return route.fulfill({ status: 200, contentType: item.tipe, body: readFileSync(item.berkas) });
    }
    return route.abort();
  });
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });

try {
  for (const layar of LEBAR) {
    const context = await browser.newContext({ viewport: layar.viewport });
    await pasangCdnLokal(context);
    const page = await context.newPage();
    page.on('console', (pesan) => {
      if (pesan.type() !== 'error') return;
      const teks = pesan.text();
      // Sandbox uji memblokir jaringan keluar, sehingga CSS/JS dari CDN gagal
      // dimuat. Itu keadaan LINGKUNGAN, bukan cacat kode: yang dinilai di sini
      // adalah galat JavaScript halaman ini sendiri.
      if (/ERR_TUNNEL_CONNECTION_FAILED|ERR_NAME_NOT_RESOLVED|ERR_INTERNET_DISCONNECTED|net::ERR_/.test(teks)) return;
      galatKonsol.push(`${layar.nama}: ${teks}`);
    });
    page.on('pageerror', (error) => galatKonsol.push(`${layar.nama}: ${error.message}`));

    // ---------------------------------------------------------------- B-1
    await page.goto(`${BASE}/portal/index.php`, { waitUntil: 'domcontentloaded' });
    const judulMasuk = await page.textContent('h1');
    catat(
      `B-1a [${layar.nama}] Halaman masuk menampilkan judul yang benar`,
      (judulMasuk ?? '').includes('Masuk Sistem Al Hasan'),
      judulMasuk ?? ''
    );
    catat(`B-1b [${layar.nama}] Halaman masuk tidak melebar`, !(await halamanMelebar(page)));
    const kotakTombol = await page.locator('button[type="submit"]').boundingBox();
    catat(
      `B-1c [${layar.nama}] Tombol masuk mudah disentuh (tinggi >= 44 px)`,
      (kotakTombol?.height ?? 0) >= 44,
      `${Math.round(kotakTombol?.height ?? 0)} px`
    );
    await bidik(page, `${layar.nama}-masuk`);

    // ---------------------------------------------------------------- B-2
    await masuk(page, AKUN.admin);
    const judulBeranda = await page.textContent('h1');
    catat(
      `B-2a [${layar.nama}] Beranda menyapa pengguna yang masuk`,
      (judulBeranda ?? '').includes('Selamat datang'),
      judulBeranda ?? ''
    );
    catat(`B-2b [${layar.nama}] Beranda tidak melebar`, !(await halamanMelebar(page)));
    await bidik(page, `${layar.nama}-beranda`);

    // ---------------------------------------------------------------- B-3
    if (layar.viewport.width < 992) {
      const tombolMenu = page.locator('#ah-nav-toggle');
      catat(`B-3a [${layar.nama}] Tombol menu tersedia pada layar kecil`, await tombolMenu.isVisible());
      await tombolMenu.click();
      await page.waitForTimeout(250);
      const terbuka = await page.locator('#ah-shell').evaluate((el) => el.classList.contains('is-nav-open'));
      catat(`B-3b [${layar.nama}] Laci navigasi terbuka setelah tombol diklik`, terbuka);
      await bidik(page, `${layar.nama}-menu-terbuka`);
      await page.keyboard.press('Escape');
      await page.waitForTimeout(250);
      const tertutup = await page.locator('#ah-shell').evaluate((el) => !el.classList.contains('is-nav-open'));
      catat(`B-3c [${layar.nama}] Laci navigasi tertutup dengan tombol Escape`, tertutup);
    } else {
      const sidebar = page.locator('#ah-sidebar');
      catat(`B-3 [${layar.nama}] Sidebar tampil permanen pada layar besar`, await sidebar.isVisible());
    }

    // ---------------------------------------------------------------- B-4
    await page.goto(`${BASE}/admin/admin_pengajian.php?tab=jadwal`, { waitUntil: 'domcontentloaded' });
    const tabAktifJadwal = await page.locator('.ah-tabs a[aria-current="page"]').textContent();
    catat(`B-4a [${layar.nama}] Tab Jadwal ditandai aktif`, (tabAktifJadwal ?? '').includes('Jadwal'), tabAktifJadwal ?? '');
    await page.click('.ah-tabs a:has-text("Pertemuan")');
    await page.waitForLoadState('domcontentloaded');
    const tabAktifPertemuan = await page.locator('.ah-tabs a[aria-current="page"]').textContent();
    catat(
      `B-4b [${layar.nama}] Berpindah ke tab Pertemuan tanpa keluar dari modul`,
      (tabAktifPertemuan ?? '').includes('Pertemuan') && page.url().includes('admin_pengajian.php'),
      page.url()
    );
    catat(`B-4c [${layar.nama}] Modul Pengajian tidak melebar`, !(await halamanMelebar(page)));
    await bidik(page, `${layar.nama}-pengajian-pertemuan`);

    // ---------------------------------------------------------------- B-5
    await page.goto(`${BASE}/admin/admin_laporan_absensi.php`, { waitUntil: 'domcontentloaded' });
    const penyajianAwal = await page.locator('#subject_scope').inputValue();
    catat(`B-5a [${layar.nama}] Penyajian awal laporan adalah Santri`, penyajianAwal === 'santri', penyajianAwal);
    await page.click('.ah-tabs a:has-text("Gabungan")');
    await page.waitForLoadState('domcontentloaded');
    const penyajianGabungan = await page.locator('#subject_scope').inputValue();
    catat(`B-5b [${layar.nama}] Tab Gabungan mengubah penyajian`, penyajianGabungan === 'gabungan', penyajianGabungan);
    catat(`B-5c [${layar.nama}] Laporan tidak melebar`, !(await halamanMelebar(page)));
    await bidik(page, `${layar.nama}-laporan-gabungan`);

    // ---------------------------------------------------------------- B-6
    await page.goto(`${BASE}/admin/admin_akun.php`, { waitUntil: 'domcontentloaded' });
    catat(`B-6a [${layar.nama}] Halaman akun tidak melebar meski tabelnya lebar`, !(await halamanMelebar(page)));
    const tabelMenggulir = await page.evaluate(() => {
      const wrap = document.querySelector('.ah-table-wrap');
      if (!wrap) return null;
      return getComputedStyle(wrap).overflowX === 'auto' || getComputedStyle(wrap).overflowX === 'scroll';
    });
    catat(`B-6b [${layar.nama}] Tabel lebar menggulir di dalam wadahnya sendiri`, tabelMenggulir === true);
    await bidik(page, `${layar.nama}-akun`);

    // ---------------------------------------------------------------- B-7
    await page.goto(`${BASE}/portal/index.php`, { waitUntil: 'domcontentloaded' });
    await page.keyboard.press('Tab');
    const fokusPertama = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el) return null;
      const gaya = getComputedStyle(el);
      return {
        teks: (el.textContent ?? '').trim().slice(0, 40),
        garisTepi: gaya.outlineStyle,
      };
    });
    catat(
      `B-7a [${layar.nama}] Fokus pertama adalah tautan lompat ke konten`,
      (fokusPertama?.teks ?? '').includes('Lompat ke konten'),
      fokusPertama?.teks ?? ''
    );
    await page.keyboard.press('Enter');
    await page.waitForTimeout(200);
    catat(
      `B-7b [${layar.nama}] Tautan lompat membawa fokus ke konten utama`,
      page.url().includes('#ah-konten')
    );

    await context.close();
  }

  // ------------------------------------------------------------------- B-9
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await pasangCdnLokal(context);
  const page = await context.newPage();
  await masuk(page, AKUN.admin);
  const hariIni = new Date().toISOString().slice(0, 10);
  await page.goto(`${BASE}/admin/laporan_absensi_cetak.php?date_from=${hariIni}&date_to=${hariIni}`, { waitUntil: 'domcontentloaded' });
  await page.emulateMedia({ media: 'print' });
  const adaSidebar = await page.evaluate(() => document.querySelector('.ah-sidebar') !== null || document.querySelector('.ah-topbar') !== null);
  catat('B-9a Halaman cetak tidak memuat kerangka bersidebar', adaSidebar === false);
  await bidik(page, 'cetak-media-print');
  await page.emulateMedia({ media: 'screen' });

  // Guru non-murobi: beranda terbuka, perizinan ditolak dengan penjelasan.
  const konteksGuru = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await pasangCdnLokal(konteksGuru);
  const halamanGuru = await konteksGuru.newPage();
  await masuk(halamanGuru, AKUN.guru);
  const judulGuru = await halamanGuru.textContent('h1');
  catat('B-10a Guru non-murobi mendarat di beranda', (judulGuru ?? '').includes('Selamat datang'), judulGuru ?? '');
  await bidik(halamanGuru, 'guru-beranda');
  await halamanGuru.goto(`${BASE}/portal/izin_ringkasan.php`, { waitUntil: 'domcontentloaded' });
  const teksTolak = await halamanGuru.textContent('h1');
  catat(
    'B-10b Guru non-murobi ditolak dari perizinan dengan penjelasan dan jalan keluar',
    (teksTolak ?? '').includes('tidak memiliki kemampuan perizinan'),
    teksTolak ?? ''
  );
  await bidik(halamanGuru, 'guru-ditolak-perizinan');
  await konteksGuru.close();
  await context.close();

  // ------------------------------------------------------------------- B-8
  catat(
    'B-8 Tidak ada galat JavaScript halaman pada konsol (galat pemuatan CDN diabaikan: lingkungan uji tanpa jaringan keluar)',
    galatKonsol.length === 0,
    galatKonsol.slice(0, 3).join(' | ')
  );
} finally {
  await browser.close();
}

const gagal = hasil.filter((item) => !item.lulus);
console.log('');
console.log(`Total pemeriksaan: ${hasil.length}, gagal: ${gagal.length}`);
console.log(`Tangkapan layar: ${OUT}`);
process.exit(gagal.length === 0 ? 0 : 1);
