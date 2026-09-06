/**
 * Uji browser sungguhan untuk "Penempatan Kelas & Kamar Santri"
 * (keputusan pengguna 6 September 2026).
 *
 * Berbeda dari smoke test HTTP, skrip ini benar-benar MERENDER halaman,
 * MENGKLIK tombol, MENCENTANG kotak, MENGISI formulir, MENEKAN Tab, dan
 * MEMBACA konsol peramban.
 *
 * Lebar yang diuji: desktop 1440 px, tablet 768 px, ponsel 390 px.
 *
 * Yang diperiksa:
 *   BP-1  menu Master Data → Penempatan Kelas & Kamar ada dan menandai dirinya
 *         aktif; breadcrumb menunjukkan jalur yang benar;
 *   BP-2  identitas tahun ajaran/semester aktif tampil;
 *   BP-3  pencarian dan seluruh filter bekerja dari peramban;
 *   BP-4  penempatan individual lewat pilihan pada baris tabel;
 *   BP-5  penempatan massal: centang, jumlah terpilih, tinjauan, penerapan;
 *   BP-6  konfirmasi tampil sebelum perubahan diterapkan;
 *   BP-7  pesan kesalahan kapasitas jelas dan tidak mengubah data;
 *   BP-8  kapasitas kamar (terisi/kapasitas dan sisa) terbaca pada pilihan;
 *   BP-9  pagination bekerja dan mempertahankan filter;
 *   BP-10 tautan dari Data Santri, Data Kelas, dan Data Kamar membuka halaman
 *         penempatan dengan filter yang sesuai;
 *   BP-11 alamat lama admin_santri.php tetap membuka halaman baru;
 *   BP-12 keyboard: seluruh kontrol dapat dicapai Tab dan fokus terlihat;
 *   BP-13 halaman tidak melebar pada ketiga lebar layar;
 *   BP-14 tidak ada galat konsol maupun permintaan jaringan yang gagal.
 *
 * Data uji sintetis dari sandbox `*_test`; tangkapan layar tidak memuat data
 * pribadi.
 *
 * Jalankan:
 *   PENEMPATAN_SEED=1 php tests/browser/seed-penempatan.php
 *   php -S 127.0.0.1:8942 -t . &
 *   BASE_URL=http://127.0.0.1:8942 node tests/browser/uji-penempatan.mjs
 */
import { chromium } from 'playwright';
import { mkdirSync, existsSync, readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8942';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-penempatan', import.meta.url).pathname;
const ADMIN = {
  username: process.env.UJI_ADMIN ?? 'bp_admin',
  password: process.env.UJI_SANDI ?? 'UjiBrowser#Penempatan9',
};
mkdirSync(OUT, { recursive: true });

const LEBAR = [
  { nama: 'desktop', viewport: { width: 1440, height: 900 } },
  { nama: 'tablet', viewport: { width: 768, height: 1024 } },
  { nama: 'ponsel', viewport: { width: 390, height: 844 } },
];

const HALAMAN = '/admin/admin_penempatan_santri.php';
const hasil = [];
const galatKonsol = [];
const permintaanGagal = [];
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

/** Memilih opsi berdasarkan potongan teksnya (label memuat angka kapasitas). */
async function pilihOpsi(select, potongan) {
  const nilai = await select.evaluate((el, teks) => {
    const opsi = Array.from(el.options).find((item) => item.textContent.includes(teks));
    return opsi ? opsi.value : '';
  }, potongan);
  if (nilai === '') {
    throw new Error(`Opsi "${potongan}" tidak ditemukan pada ${await select.getAttribute('id')}`);
  }
  await select.selectOption(nilai);
  return nilai;
}

/**
 * Menyiapkan ulang fixture sebelum setiap lebar layar diuji.
 *
 * Tanpa ini, penempatan dari putaran sebelumnya membuat kamar uji ikut terisi
 * sehingga hasil putaran berikutnya bergantung pada urutan — bukan pada
 * perilaku halaman.
 */
function seedUlang(bersihkan = false) {
  const skrip = new URL('./seed-penempatan.php', import.meta.url).pathname;
  execFileSync(process.env.PHP_BINARY ?? 'php', bersihkan ? [skrip, '--bersihkan'] : [skrip], {
    env: { ...process.env, PENEMPATAN_SEED: '1' },
    stdio: ['ignore', 'ignore', 'inherit'],
  });
}

async function masuk(page) {
  await page.goto(`${BASE}/portal/index.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#username', ADMIN.username);
  await page.fill('#password', ADMIN.password);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
}

/** Membuka menu laci pada layar kecil supaya sidebar dapat diperiksa. */
async function bukaNavigasi(page, layar) {
  if (layar.viewport.width >= 992) return;
  const tombol = page.locator('#ah-nav-toggle');
  if (await tombol.count() > 0 && await tombol.isVisible()) {
    await tombol.click();
    await page.waitForTimeout(200);
  }
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });

try {
  for (const layar of LEBAR) {
    seedUlang();
    const context = await browser.newContext({ viewport: layar.viewport });
    await pasangCdnLokal(context);
    const page = await context.newPage();
    page.on('console', (pesan) => {
      if (pesan.type() !== 'error') return;
      const teks = pesan.text();
      if (/ERR_TUNNEL_CONNECTION_FAILED|ERR_NAME_NOT_RESOLVED|ERR_INTERNET_DISCONNECTED|net::ERR_/.test(teks)) return;
      galatKonsol.push(`${layar.nama}: ${teks}`);
    });
    page.on('pageerror', (error) => galatKonsol.push(`${layar.nama}: ${error.message}`));
    page.on('response', (respons) => {
      const url = respons.url();
      if (!url.startsWith(BASE)) return;
      if (respons.status() >= 500) {
        permintaanGagal.push(`${layar.nama}: ${respons.status()} ${url}`);
      }
    });

    await masuk(page);
    await page.goto(`${BASE}${HALAMAN}`, { waitUntil: 'domcontentloaded' });

    // ----------------------------------------------------------------- BP-1
    await bukaNavigasi(page, layar);
    const menu = page.locator('#ah-sidebar a[href$="admin_penempatan_santri.php"]');
    catat(`BP-1a [${layar.nama}] Menu Penempatan Kelas & Kamar ada pada navigasi`, await menu.count() > 0);
    catat(`BP-1b [${layar.nama}] Menu tersebut ditandai sebagai halaman aktif`,
      await menu.first().getAttribute('aria-current') === 'page');
    const remah = (await page.locator('.ah-crumbs').innerText()).replace(/\s+/g, ' ').trim();
    catat(`BP-1c [${layar.nama}] Breadcrumb menunjukkan jalur Master Data`,
      remah.includes('Master Data') && remah.includes('Penempatan'), remah);
    await bidik(page, `${layar.nama}-daftar`);

    // ----------------------------------------------------------------- BP-2
    const isiHalaman = await page.locator('main').innerText();
    catat(`BP-2 [${layar.nama}] Identitas tahun ajaran/semester aktif tampil`,
      /Semester aktif:\s*\d{4}\/\d{4}\s+(Ganjil|Genap)/.test(isiHalaman));

    // ----------------------------------------------------------------- BP-8
    const opsiKamar = await page.locator('#massal_kamar option').allInnerTexts();
    catat(`BP-8 [${layar.nama}] Pilihan kamar menampilkan terisi/kapasitas dan sisa tempat`,
      opsiKamar.some((teks) => /\(\d+\/\d+ — (sisa \d+|penuh)\)/.test(teks)),
      opsiKamar.find((teks) => teks.includes('BP Kamar')) ?? '');

    // ----------------------------------------------------------------- BP-3
    await page.goto(`${BASE}${HALAMAN}?q=BPS07`, { waitUntil: 'domcontentloaded' });
    let baris = await page.locator('#tabel-penempatan tbody tr').count();
    catat(`BP-3a [${layar.nama}] Pencarian NIS menyaring daftar menjadi satu baris`, baris === 1, `${baris} baris`);

    await page.goto(`${BASE}${HALAMAN}?status=tanpa_kelas`, { waitUntil: 'domcontentloaded' });
    const tanpaKelas = await page.locator('#tabel-penempatan tbody tr').allInnerTexts();
    catat(`BP-3b [${layar.nama}] Filter "belum mempunyai kelas" hanya menampilkan santri tanpa kelas`,
      tanpaKelas.length > 0 && tanpaKelas.every((teks) => teks.includes('Belum ada kelas')));

    await page.goto(`${BASE}${HALAMAN}?status=tanpa_kamar`, { waitUntil: 'domcontentloaded' });
    const tanpaKamar = await page.locator('#tabel-penempatan tbody tr').allInnerTexts();
    catat(`BP-3c [${layar.nama}] Filter "belum mempunyai kamar" hanya menampilkan santri tanpa kamar`,
      tanpaKamar.length > 0 && tanpaKamar.every((teks) => teks.includes('Belum ada kamar')));

    await page.goto(`${BASE}${HALAMAN}?jk=P`, { waitUntil: 'domcontentloaded' });
    const perempuan = await page.locator('#tabel-penempatan tbody tr td:nth-child(4)').allInnerTexts();
    catat(`BP-3d [${layar.nama}] Filter jenis kelamin bekerja`,
      perempuan.length > 0 && perempuan.every((teks) => teks.trim() === 'P'));

    await page.goto(`${BASE}${HALAMAN}?q=tidak-ada-santri-ini`, { waitUntil: 'domcontentloaded' });
    catat(`BP-3e [${layar.nama}] Keadaan "tidak ada hasil pencarian" dijelaskan, bukan tabel kosong`,
      (await page.locator('.ah-empty').count()) > 0
      && (await page.locator('.ah-empty').innerText()).includes('Tidak ada santri yang cocok'));
    await bidik(page, `${layar.nama}-tanpa-hasil`);

    // ----------------------------------------------------------------- BP-4
    await page.goto(`${BASE}${HALAMAN}?q=BPS20`, { waitUntil: 'domcontentloaded' });
    const kamarBaris = page.locator('#tabel-penempatan tbody tr').first().locator('select[id^="kamar-"]');
    await pilihOpsi(kamarBaris, 'BP Kamar Lapang');
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.locator('#tabel-penempatan tbody tr').first().locator('button:has-text("Simpan kamar")').click(),
    ]);
    const setelahIndividual = await page.locator('main').innerText();
    catat(`BP-4a [${layar.nama}] Penempatan individual berhasil dan dilaporkan`,
      setelahIndividual.includes('Berhasil') && setelahIndividual.includes('BP Kamar Lapang'));
    catat(`BP-4b [${layar.nama}] Baris santri menampilkan kamar barunya`,
      (await page.locator('#tabel-penempatan tbody tr').first().innerText()).includes('BP Kamar Lapang'));
    await bidik(page, `${layar.nama}-individual`);

    // ------------------------------------------------------------ BP-5, BP-6
    await page.goto(`${BASE}${HALAMAN}?status=tanpa_kamar`, { waitUntil: 'domcontentloaded' });
    const kotak = page.locator('.ah-pilih-santri');
    const jumlahKotak = await kotak.count();
    await kotak.nth(0).check();
    await kotak.nth(1).check();
    const terpilih = (await page.locator('[data-jumlah-terpilih]').innerText()).trim();
    catat(`BP-5a [${layar.nama}] Jumlah santri terpilih tampil dan benar`, terpilih === '2', terpilih);
    catat(`BP-5b [${layar.nama}] Pilihan hanya berlaku untuk baris yang terlihat`,
      (await page.locator('#ah-terpilih').innerText()).includes('halaman lain tidak pernah ikut terpilih'));

    await pilihOpsi(page.locator('#massal_kamar'), 'BP Kamar Sedang');
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.locator('button:has-text("Tinjau penempatan kamar")').click(),
    ]);
    const tinjauan = await page.locator('main').innerText();
    catat(`BP-6a [${layar.nama}] Layar konfirmasi tampil sebelum perubahan diterapkan`,
      tinjauan.includes('Konfirmasi perubahan penempatan'));
    catat(`BP-6b [${layar.nama}] Konfirmasi menyebut jumlah santri dan tujuannya`,
      tinjauan.includes('2 santri terpilih') && tinjauan.includes('BP Kamar Sedang'));
    catat(`BP-6c [${layar.nama}] Konfirmasi menampilkan kapasitas kamar tujuan`,
      /terisi \d+ dari \d+, sisa \d+ tempat/.test(tinjauan));
    await bidik(page, `${layar.nama}-konfirmasi`);

    page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.locator('button:has-text("Terapkan perubahan")').click(),
    ]);
    const setelahMassal = await page.locator('main').innerText();
    catat(`BP-5c [${layar.nama}] Penempatan massal diterapkan dan hasilnya dilaporkan`,
      setelahMassal.includes('Berhasil') && setelahMassal.includes('2 santri ditempatkan'));
    await bidik(page, `${layar.nama}-hasil-massal`);

    // ----------------------------------------------------------------- BP-7
    await page.goto(`${BASE}${HALAMAN}?status=tanpa_kamar`, { waitUntil: 'domcontentloaded' });
    await page.locator('.ah-pilih-santri').nth(0).check();
    await pilihOpsi(page.locator('#massal_kamar'), 'BP Kamar Penuh');
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      page.locator('button:has-text("Tinjau penempatan kamar")').click(),
    ]);
    const tinjauanPenuh = await page.locator('main').innerText();
    catat(`BP-7a [${layar.nama}] Kamar penuh ditandai "Kapasitas tidak cukup" pada konfirmasi`,
      tinjauanPenuh.includes('Kapasitas tidak cukup'));
    catat(`BP-7b [${layar.nama}] Pesan kesalahan menjelaskan sisa tempat dan jumlah yang diminta`,
      /Kapasitas kamar .* tidak mencukupi/.test(tinjauanPenuh));
    const tombolTerapkan = page.locator('button:has-text("Terapkan perubahan")');
    catat(`BP-7c [${layar.nama}] Tombol terapkan dinonaktifkan saat perubahan tidak mungkin`,
      await tombolTerapkan.isDisabled());
    await bidik(page, `${layar.nama}-kapasitas-penuh`);

    // ----------------------------------------------------------------- BP-9
    await page.goto(`${BASE}${HALAMAN}?jk=L`, { waitUntil: 'domcontentloaded' });
    const adaPagination = await page.locator('nav[aria-label="Navigasi halaman"]').count() > 0;
    catat(`BP-9a [${layar.nama}] Pagination server tampil untuk daftar panjang`, adaPagination);
    if (adaPagination) {
      await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        page.locator('nav[aria-label="Navigasi halaman"] a:has-text("Berikutnya")').first().click(),
      ]);
      catat(`BP-9b [${layar.nama}] Berpindah halaman mempertahankan filter`,
        page.url().includes('jk=L') && page.url().includes('page=2'), page.url());
    }

    // ---------------------------------------------------------------- BP-10
    await page.goto(`${BASE}/admin/admin_kamar.php`, { waitUntil: 'domcontentloaded' });
    const tautanKamar = page.locator('a[href*="admin_penempatan_santri.php?kamar_id="]').first();
    catat(`BP-10a [${layar.nama}] Data Kamar menyediakan tautan menuju penempatan`, await tautanKamar.count() > 0);
    if (await tautanKamar.count() > 0) {
      await Promise.all([page.waitForLoadState('domcontentloaded'), tautanKamar.click()]);
      catat(`BP-10b [${layar.nama}] Tautan dari Data Kamar membawa filter kamar`,
        page.url().includes('kamar_id='), page.url());
      const nilaiFilter = await page.locator('#filter_kamar').inputValue();
      catat(`BP-10c [${layar.nama}] Filter kamar terisi otomatis di halaman penempatan`, nilaiFilter !== '');
    }
    await page.goto(`${BASE}/admin/admin_kelas.php`, { waitUntil: 'domcontentloaded' });
    catat(`BP-10d [${layar.nama}] Data Kelas menyediakan tautan penempatan berfilter`,
      await page.locator('a[href*="admin_penempatan_santri.php?kelas_id="]').count() > 0);
    await page.goto(`${BASE}/admin/admin_master_santri.php`, { waitUntil: 'domcontentloaded' });
    catat(`BP-10e [${layar.nama}] Data Santri menyediakan tautan penempatan`,
      await page.locator('a[href*="admin_penempatan_santri.php"]').count() > 0);

    // ---------------------------------------------------------------- BP-11
    await page.goto(`${BASE}/admin/admin_santri.php?cari=BPS07&filter_status=no_room`, { waitUntil: 'domcontentloaded' });
    catat(`BP-11a [${layar.nama}] Alamat lama membuka halaman penempatan baru`,
      page.url().includes('admin_penempatan_santri.php'), page.url());
    catat(`BP-11b [${layar.nama}] Filter lama ikut terbawa`,
      page.url().includes('q=BPS07') && page.url().includes('status=tanpa_kamar'));

    // ---------------------------------------------------------------- BP-12
    await page.goto(`${BASE}${HALAMAN}?q=BPS0`, { waitUntil: 'domcontentloaded' });
    await page.locator('#q').focus();
    const urutanFokus = [];
    for (let i = 0; i < 14; i += 1) {
      await page.keyboard.press('Tab');
      urutanFokus.push(await page.evaluate(() => {
        const el = document.activeElement;
        if (!el) return '';
        return `${el.tagName.toLowerCase()}#${el.id || ''}.${(el.getAttribute('name') || '')}`;
      }));
    }
    catat(`BP-12a [${layar.nama}] Seluruh filter dapat dicapai dengan Tab`,
      urutanFokus.some((f) => f.includes('jk')) && urutanFokus.some((f) => f.includes('sekolah'))
      && urutanFokus.some((f) => f.includes('kelas_id')) && urutanFokus.some((f) => f.includes('kamar_id')),
      urutanFokus.join(' > '));
    const kotakPertama = page.locator('.ah-pilih-santri').first();
    await kotakPertama.focus();
    await page.keyboard.press('Space');
    catat(`BP-12b [${layar.nama}] Kotak centang dapat dipilih dengan papan ketik`, await kotakPertama.isChecked());
    const fokusTerlihat = await page.evaluate(() => {
      const el = document.activeElement;
      if (!el) return false;
      const gaya = window.getComputedStyle(el);
      return gaya.outlineStyle !== 'none' || gaya.boxShadow !== 'none';
    });
    catat(`BP-12c [${layar.nama}] Fokus papan ketik terlihat`, fokusTerlihat);
    const labelKotak = await kotakPertama.getAttribute('aria-label');
    catat(`BP-12d [${layar.nama}] Kotak centang punya label yang menyebut nama santri`,
      (labelKotak ?? '').startsWith('Pilih '), labelKotak ?? '');

    // ---------------------------------------------------------------- BP-13
    catat(`BP-13a [${layar.nama}] Halaman daftar tidak melebar`, !(await halamanMelebar(page)));
    await page.goto(`${BASE}${HALAMAN}?q=BPS`, { waitUntil: 'domcontentloaded' });
    catat(`BP-13b [${layar.nama}] Tabel panjang tidak membuat halaman menggeser horizontal`,
      !(await halamanMelebar(page)));
    const wadahTabel = await page.locator('.ah-table-wrap').first().boundingBox();
    catat(`BP-13c [${layar.nama}] Wadah tabel muat di dalam lebar layar`,
      (wadahTabel?.width ?? 0) <= layar.viewport.width + 1, `${Math.round(wadahTabel?.width ?? 0)} px`);
    await bidik(page, `${layar.nama}-akhir`);

    await context.close();
  }

  // ---------------------------------------------------------------- BP-14
  catat('BP-14a Tidak ada galat JavaScript pada konsol', galatKonsol.length === 0, galatKonsol.join(' | '));
  catat('BP-14b Tidak ada permintaan yang gagal (5xx)', permintaanGagal.length === 0, permintaanGagal.join(' | '));
} finally {
  await browser.close();
  // Fixture dibersihkan kembali: akun `bp_admin` yang tertinggal menambah
  // jumlah admin aktif, dan itu mengubah cabang yang diambil pemeriksaan
  // KA-7/KA-8 pada regresi `tests/perapihan_integration.php`.
  try {
    seedUlang(true);
  } catch (galat) {
    console.log(`[gagal] Fixture uji browser tidak dapat dibersihkan — ${galat.message}`);
  }
}

const gagal = hasil.filter((item) => !item.lulus);
console.log('');
console.log(`Pemeriksaan browser: ${hasil.length - gagal.length} lulus, ${gagal.length} gagal.`);
console.log(`Tangkapan layar: ${OUT}`);
if (gagal.length > 0) {
  gagal.forEach((item) => console.log(` - ${item.nama}${item.keterangan ? ' — ' + item.keterangan : ''}`));
  process.exit(1);
}
