/**
 * Uji browser sungguhan untuk fitur "Pesan Kredensial Akun Siap Salin"
 * (keputusan pengguna 6 September 2026).
 *
 * Berbeda dari smoke test HTTP, skrip ini benar-benar MERENDER halaman,
 * MENGKLIK tombol salin, MEMBACA papan klip, MENOLAK Clipboard API untuk
 * menguji jalur cadangan, dan MENEKAN tombol kembali peramban.
 *
 * Lebar yang diuji: desktop 1440 px dan ponsel 390 px.
 *
 * Yang diperiksa:
 *   BK-1  panel kredensial muncul setelah akun guru dibuat lewat formulir;
 *   BK-2  isi panel lengkap: nama, email, username, password, URL portal,
 *         instruksi ganti password, dan peringatan keamanan;
 *   BK-3  tombol salin menaruh TEKS BIASA yang sama persis dengan isi kotak;
 *   BK-4  status "Pesan berhasil disalin" tampil pada area aria-live;
 *   BK-5  bila Clipboard API ditolak, teks disorot dan petunjuk manual tampil,
 *         tanpa membuat akun atau password baru;
 *   BK-6  memuat ulang halaman menghilangkan panel dan password;
 *   BK-7  tombol kembali peramban tidak menyajikan panel dari cache;
 *   BK-8  panel terbaca pada desktop dan ponsel tanpa melebarkan halaman;
 *   BK-9  tidak ada galat JavaScript pada konsol;
 *   BK-10 jalur pengurus dan orang tua berperilaku sama.
 *
 * Data uji sintetis dari sandbox `*_test`; tidak ada kredensial nyata.
 *
 * Jalankan:
 *   KREDENSIAL_SEED=1 php tests/browser/seed-kredensial.php
 *   php -S 127.0.0.1:8941 -t . &
 *   BASE_URL=http://127.0.0.1:8941 node tests/browser/uji-kredensial.mjs
 */
import { chromium } from 'playwright';
import { mkdirSync, existsSync, readFileSync } from 'node:fs';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8941';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-kredensial', import.meta.url).pathname;
const ADMIN = {
  username: process.env.UJI_ADMIN ?? 'bk_admin',
  password: process.env.UJI_SANDI ?? 'UjiBrowser#Kredensial9',
};
mkdirSync(OUT, { recursive: true });

const LEBAR = [
  { nama: 'desktop', viewport: { width: 1440, height: 900 } },
  { nama: 'ponsel', viewport: { width: 390, height: 844 } },
];

const hasil = [];
const galatKonsol = [];
/**
 * Galat yang SUDAH ADA pada baseline `main` dan tidak disebabkan paket ini.
 * Dicatat terpisah supaya tetap terlihat, tetapi tidak menyamarkan cacat baru
 * maupun dinyatakan lulus.
 */
const GALAT_BASELINE = [
  {
    pola: /Pattern attribute value \[a-z0-9\._-\]\+ is not a valid regular expression/,
    catatan: 'atribut pattern="[a-z0-9._-]+" pada kolom username admin_akun.php ditolak mesin regexp mode `v` peramban baru; sudah ada sejak baseline main dan menonaktifkan validasi sisi klien (validasi server tetap berjalan)',
  },
];
const temuanBaseline = [];
let nomor = 0;

function catat(nama, lulus, keterangan = '') {
  hasil.push({ nama, lulus, keterangan });
  console.log(`${lulus ? '[lulus]' : '[gagal]'} ${nama}${keterangan ? ' — ' + keterangan : ''}`);
}

async function bidik(page, label) {
  nomor += 1;
  const nama = `${String(nomor).padStart(2, '0')}-${label}.jpg`;
  await page.screenshot({ path: `${OUT}/${nama}`, fullPage: true, type: 'jpeg', quality: 72 });
  return nama;
}

async function halamanMelebar(page) {
  return page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
}

/** Aset CDN dilayani dari salinan lokal; kode aplikasi tidak diubah. */
const CDN_LOKAL = [
  { cocok: /bootstrap[^/]*\/dist\/css\/bootstrap\.min\.css/, berkas: new URL('./node_modules/bootstrap/dist/css/bootstrap.min.css', import.meta.url).pathname, tipe: 'text/css' },
  { cocok: /bootstrap[^/]*\/dist\/js\/bootstrap\.bundle\.min\.js/, berkas: new URL('./node_modules/bootstrap/dist/js/bootstrap.bundle.min.js', import.meta.url).pathname, tipe: 'application/javascript' },
  { cocok: /font-awesome[^"]*all\.min\.css/, berkas: new URL('./node_modules/@fortawesome/fontawesome-free/css/all.min.css', import.meta.url).pathname, tipe: 'text/css' },
];
const cdnTersedia = CDN_LOKAL.every((item) => existsSync(item.berkas));

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

async function masuk(page) {
  await page.goto(`${BASE}/portal/index.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#username', ADMIN.username);
  await page.fill('#password', ADMIN.password);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
}

/** Mengisi satu formulir pembuatan akun lalu menunggu halaman hasil. */
async function buatAkun(page, { seksi, pilihan, nilai }) {
  await page.goto(`${BASE}/admin/admin_akun.php`, { waitUntil: 'domcontentloaded' });
  const form = page.locator(`section[aria-labelledby="${seksi}"] form`);
  if (await form.count() === 0) {
    // Seluruh data master pada seksi ini sudah punya akun. Jalankan ulang
    // seed fixture sebelum menguji: seed-kredensial.php.
    throw new Error(`Formulir "${seksi}" tidak tersedia. Jalankan ulang: KREDENSIAL_SEED=1 php tests/browser/seed-kredensial.php`);
  }
  if (pilihan) {
    await form.locator(`select[name="${pilihan.nama}"]`).selectOption({ index: 1 });
  }
  for (const [nama, isi] of Object.entries(nilai)) {
    await form.locator(`input[name="${nama}"]`).fill(isi);
  }
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    form.locator('button[type="submit"], button:not([type])').first().click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
}

const acak = Math.random().toString(36).slice(2, 7);
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });

try {
  for (const layar of LEBAR) {
    const context = await browser.newContext({ viewport: layar.viewport });
    // Papan klip perlu izin eksplisit agar dapat dibaca kembali oleh pengujian.
    await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE });
    await pasangCdnLokal(context);
    const page = await context.newPage();
    page.on('console', (pesan) => {
      if (pesan.type() !== 'error') return;
      const teks = pesan.text();
      if (/ERR_TUNNEL_CONNECTION_FAILED|ERR_NAME_NOT_RESOLVED|ERR_INTERNET_DISCONNECTED|net::ERR_/.test(teks)) return;
      const baseline = GALAT_BASELINE.find((item) => item.pola.test(teks));
      if (baseline) {
        if (!temuanBaseline.some((item) => item.catatan === baseline.catatan)) {
          temuanBaseline.push(baseline);
        }
        return;
      }
      galatKonsol.push(`${layar.nama}: ${teks}`);
    });
    page.on('pageerror', (error) => galatKonsol.push(`${layar.nama}: ${error.message}`));

    await masuk(page);

    // ----------------------------------------------------------------- BK-1
    const namaGuru = `Ustadz Uji ${layar.nama} ${acak}`;
    const usernameGuru = `bk_guru.${layar.nama}.${acak}`.toLowerCase().replace(/[^a-z0-9._-]/g, '.');
    const emailGuru = `${usernameGuru}@contoh.test`;
    await buatAkun(page, {
      seksi: 'ah-buat-guru',
      pilihan: { nama: 'guru_id' },
      nilai: { name: namaGuru, username: usernameGuru, email: emailGuru, phone: '' },
    });

    const panel = page.locator('#ah-kredensial-teks');
    const adaPanel = await panel.count() > 0;
    catat(`BK-1 [${layar.nama}] Panel kredensial muncul setelah akun guru dibuat`, adaPanel);
    if (!adaPanel) {
      await bidik(page, `${layar.nama}-tanpa-panel`);
      await context.close();
      continue;
    }
    await bidik(page, `${layar.nama}-panel-kredensial`);

    // ----------------------------------------------------------------- BK-2
    const isiPanel = await page.locator('.ah-kredensial').innerText();
    const sandiTampil = (await page.locator('.ah-kredensial__sandi').innerText()).trim();
    for (const [penggalan, label] of [
      [namaGuru, 'nama pengguna'],
      [emailGuru, 'alamat email tujuan'],
      [usernameGuru, 'username'],
      ['https://alhasan.co.id/portal/', 'alamat masuk portal'],
      ['wajib mengganti password pada login pertama', 'instruksi ganti password'],
      ['tidak boleh dibagikan kepada pihak lain', 'peringatan keamanan'],
      ['Bersihkan papan klip', 'peringatan papan klip'],
    ]) {
      catat(`BK-2 [${layar.nama}] Panel memuat ${label}`, isiPanel.includes(penggalan));
    }
    catat(`BK-2 [${layar.nama}] Panel memuat password sementara`, sandiTampil.length > 0 && isiPanel.includes(sandiTampil));

    // ----------------------------------------------------------------- BK-3
    const teksTerlihat = await panel.innerText();
    await page.click('#ah-kredensial-salin');
    await page.waitForFunction(
      () => (document.getElementById('ah-kredensial-status')?.textContent ?? '').length > 0,
      null,
      { timeout: 5000 },
    );
    const isiKlip = await page.evaluate(() => navigator.clipboard.readText());
    catat(`BK-3a [${layar.nama}] Isi papan klip sama persis dengan teks yang terlihat`, isiKlip === teksTerlihat);
    catat(`BK-3b [${layar.nama}] Isi papan klip berupa teks biasa tanpa tag HTML`, !/[<>]/.test(isiKlip.replace(namaGuru, '')));
    catat(`BK-3c [${layar.nama}] Isi papan klip memuat username dan password sementara`,
      isiKlip.includes(`Username: ${usernameGuru}`) && isiKlip.includes(`Password sementara: ${sandiTampil}`));
    catat(`BK-3d [${layar.nama}] Isi papan klip memuat salam dan alamat masuk baku`,
      isiKlip.startsWith('Assalamu’alaikum.') && isiKlip.includes('https://alhasan.co.id/portal/')
        && isiKlip.trimEnd().endsWith('Wassalamu’alaikum.'));

    // ----------------------------------------------------------------- BK-4
    const status = page.locator('#ah-kredensial-status');
    catat(`BK-4a [${layar.nama}] Status penyalinan berbunyi "Pesan berhasil disalin"`,
      (await status.innerText()).trim() === 'Pesan berhasil disalin');
    catat(`BK-4b [${layar.nama}] Status berada pada area aria-live yang dapat dibaca teknologi bantu`,
      (await status.getAttribute('aria-live')) === 'polite' && (await status.getAttribute('role')) === 'status');
    await bidik(page, `${layar.nama}-status-tersalin`);

    // ----------------------------------------------------------------- BK-5
    // Clipboard API ditolak: panel harus menyorot teks dan memberi petunjuk.
    await page.evaluate(() => {
      Object.defineProperty(navigator, 'clipboard', {
        configurable: true,
        value: { writeText: () => Promise.reject(new Error('ditolak')) },
      });
      document.getElementById('ah-kredensial-status').textContent = '';
    });
    await page.click('#ah-kredensial-salin');
    await page.waitForFunction(
      () => (document.getElementById('ah-kredensial-status')?.textContent ?? '').length > 0,
      null,
      { timeout: 5000 },
    );
    const statusTolak = (await status.innerText()).trim();
    catat(`BK-5a [${layar.nama}] Penolakan papan klip menghasilkan petunjuk salin manual`,
      statusTolak.includes('Ctrl+C'), statusTolak);
    const terpilih = await page.evaluate(() => (window.getSelection()?.toString() ?? ''));
    catat(`BK-5b [${layar.nama}] Teks pesan disorot supaya dapat disalin manual`,
      terpilih.includes('Password sementara:'));
    const sandiSetelahGagal = (await page.locator('.ah-kredensial__sandi').innerText()).trim();
    catat(`BK-5c [${layar.nama}] Kegagalan menyalin tidak membuat password atau akun baru`,
      sandiSetelahGagal === sandiTampil);
    await bidik(page, `${layar.nama}-salin-manual`);

    // ----------------------------------------------------------------- BK-8
    catat(`BK-8a [${layar.nama}] Halaman berisi panel tidak melebar`, !(await halamanMelebar(page)));
    const kotakTombol = await page.locator('#ah-kredensial-salin').boundingBox();
    catat(`BK-8b [${layar.nama}] Tombol salin mudah disentuh (tinggi >= 44 px)`,
      (kotakTombol?.height ?? 0) >= 44, `${Math.round(kotakTombol?.height ?? 0)} px`);
    const kotakPanel = await page.locator('.ah-kredensial').boundingBox();
    catat(`BK-8c [${layar.nama}] Panel muat di dalam lebar layar`,
      (kotakPanel?.width ?? 0) <= layar.viewport.width + 1, `${Math.round(kotakPanel?.width ?? 0)} px`);

    // ----------------------------------------------------------------- BK-6
    await page.reload({ waitUntil: 'domcontentloaded' });
    const setelahMuatUlang = await page.content();
    catat(`BK-6a [${layar.nama}] Memuat ulang halaman menghilangkan panel kredensial`,
      !setelahMuatUlang.includes('id="ah-kredensial-teks"'));
    catat(`BK-6b [${layar.nama}] Password tidak muncul lagi setelah muat ulang`,
      !setelahMuatUlang.includes(sandiTampil));

    // ----------------------------------------------------------------- BK-7
    await page.goto(`${BASE}/portal/index.php`, { waitUntil: 'domcontentloaded' });
    await page.goBack({ waitUntil: 'domcontentloaded' });
    const setelahKembali = await page.content();
    catat(`BK-7a [${layar.nama}] Tombol kembali tidak menyajikan panel dari cache`,
      !setelahKembali.includes('id="ah-kredensial-teks"'));
    catat(`BK-7b [${layar.nama}] Tombol kembali tidak menyajikan password dari cache`,
      !setelahKembali.includes(sandiTampil));

    // ---------------------------------------------------------------- BK-10
    if (layar.nama === 'desktop') {
      for (const jalur of [
        { seksi: 'ah-buat-pengurus', pilihan: { nama: 'pengurus_id' }, label: 'pengurus', username: `bk_pengurus.${acak}` },
        { seksi: 'ah-buat-ortu', pilihan: { nama: 'wali_id' }, label: 'orang tua', username: `bk_wali.${acak}` },
      ]) {
        await buatAkun(page, {
          seksi: jalur.seksi,
          pilihan: jalur.pilihan,
          nilai: { name: `Akun ${jalur.label} ${acak}`, username: jalur.username, email: '', phone: '' },
        });
        const adaPanelLain = await page.locator('#ah-kredensial-teks').count() > 0;
        catat(`BK-10 [${layar.nama}] Panel yang sama tampil untuk akun ${jalur.label}`, adaPanelLain);
        if (adaPanelLain) {
          const teksLain = await page.locator('#ah-kredensial-teks').innerText();
          catat(`BK-10b [${layar.nama}] Pesan akun ${jalur.label} memakai teks baku yang sama`,
            teksLain.startsWith('Assalamu’alaikum.') && teksLain.includes(`Username: ${jalur.username}`));
          await bidik(page, `${layar.nama}-panel-${jalur.label.replace(' ', '-')}`);
        }
      }
    }

    await context.close();
  }

  // ------------------------------------------------------------------- BK-9
  catat('BK-9 Tidak ada galat JavaScript baru pada konsol', galatKonsol.length === 0, galatKonsol.join(' | '));
} finally {
  await browser.close();
}

const gagal = hasil.filter((item) => !item.lulus);
console.log('');
if (temuanBaseline.length > 0) {
  console.log('TEMUAN BASELINE (sudah ada sebelum paket ini, TIDAK diperbaiki di sini):');
  temuanBaseline.forEach((item) => console.log(` - ${item.catatan}`));
  console.log('');
}
console.log(`Pemeriksaan browser: ${hasil.length - gagal.length} lulus, ${gagal.length} gagal.`);
console.log(`Tangkapan layar: ${OUT}`);
if (gagal.length > 0) {
  gagal.forEach((item) => console.log(` - ${item.nama}${item.keterangan ? ' — ' + item.keterangan : ''}`));
  process.exit(1);
}
