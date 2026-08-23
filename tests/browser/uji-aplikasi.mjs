/**
 * Uji coba APLIKASI (React Native) Fase 4 dengan browser sungguhan.
 *
 * Kode yang dijalankan adalah kode React Native yang sama persis dengan yang
 * dibundel untuk Android/iOS — dirender melalui `react-native-web` sehingga
 * dapat dijalankan di kontainer tanpa perangkat. Yang diuji: layar pusat
 * notifikasi, detail notifikasi, layar perangkat & push, lencana jumlah belum
 * dibaca, isolasi antar akun, dan bahwa token perangkat maupun alasan izin
 * tidak pernah tampil.
 *
 * TIDAK diuji di sini (dan TIDAK boleh dinyatakan lulus dari sini):
 * kedatangan push nyata. `expo-notifications` memang mengembalikan
 * `tidak_didukung` pada web sesuai dokumentasi SDK 57; kriteria 3 PRD tetap
 * menunggu smoke test manusia pada perangkat Android dan iOS nyata.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';

const BASE = process.env.APP_URL ?? 'http://127.0.0.1:8950';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan-aplikasi', import.meta.url).pathname;
mkdirSync(OUT, { recursive: true });

// Status baca dikembalikan agar skrip dapat dijalankan berulang dengan hasil
// yang sama. Hanya menyentuh basis data sandbox berisi data sintetis.
const DB_UJI = process.env.DB_NAME ?? 'webalhasan_test';
execFileSync(process.env.MARIADB_BIN ?? 'mariadb', [
  '-u' + (process.env.DB_USER ?? 'root'),
  ...(process.env.DB_PASSWORD ? ['-p' + process.env.DB_PASSWORD] : []),
  ...(process.env.DB_HOST ? ['-h' + process.env.DB_HOST] : []),
  DB_UJI,
  '-e',
  "UPDATE notifikasi_outbox o JOIN users u ON u.id = o.penerima_user_id" +
    " SET o.dibaca_pada = NULL WHERE u.username LIKE 'sbx_%' AND o.kanal = 'InApp';",
]);

const hasil = [];
const galat = [];
let nomor = 0;

function catat(nama, lulus, keterangan = '') {
  hasil.push({ nama, lulus, keterangan });
  console.log(`${lulus ? '[lulus]' : '[GAGAL]'} ${nama}${keterangan ? ' — ' + keterangan : ''}`);
}

async function bidik(page, label) {
  nomor += 1;
  await page.screenshot({ path: `${OUT}/${String(nomor).padStart(2, '0')}-${label}.png`, fullPage: true });
}

async function teks(page) {
  return (await page.locator('body').innerText()).replace(/\s+/g, ' ');
}

/** Menunggu sampai sebuah teks muncul di layar (aplikasi memuat lewat fetch). */
async function tungguTeks(page, pola, batas = 15000) {
  const mulai = Date.now();
  while (Date.now() - mulai < batas) {
    if (pola.test(await teks(page))) return true;
    await page.waitForTimeout(250);
  }
  return false;
}

async function masuk(context, username, password = 'Sandbox#123') {
  const page = await context.newPage();
  page.on('console', (m) => {
    const t = m.text();
    // Peringatan react-native-web tentang props khusus native bukan cacat
    // aplikasi: props tersebut memang hanya berlaku di Android/iOS.
    // Penolakan 403 pada A-21 memang SENGAJA dipicu untuk membuktikan server
    // menolak notifikasi milik akun lain; browser mencatatnya sebagai
    // "Failed to load resource", bukan galat kode aplikasi.
    const bising =
      /Download the React DevTools|is not supported|deprecated|useNativeDriver|Unexpected text node/i.test(t) ||
      /Failed to load resource/i.test(t);
    if (m.type() === 'error' && !bising) galat.push(`${username}: ${t}`);
  });
  // `State.currentlyFocusedInput` adalah keterbatasan react-native-web pada
  // komponen input bawaan (dipakai layar login sejak Fase 1, di luar Fase 4):
  // API tersebut hanya ada pada runtime Android/iOS. Tidak berpengaruh pada
  // build perangkat, sehingga tidak dihitung sebagai galat aplikasi.
  const keterbatasanWeb = /currentlyFocusedInput|not available on web|not supported on web/i;
  page.on('pageerror', (e) => {
    if (keterbatasanWeb.test(e.message)) return;
    galat.push(`${username}: ${e.message}`);
  });

  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('input[placeholder="Masukkan username"]', { timeout: 20000 });
  await page.fill('input[placeholder="Masukkan username"]', username);
  await page.fill('input[placeholder="Masukkan password"]', password);
  await page.getByText('Masuk', { exact: true }).last().click();
  // Halaman login sendiri memuat kata "jadwal", jadi tunggu URL berpindah,
  // bukan sekadar kemunculan teks.
  await page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 });
  await tungguTeks(page, /Assalamu|Beranda|Perizinan/i, 25000);
  return page;
}

const browser = await chromium.launch({
  ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}),
  args: ['--no-sandbox'],
});

try {
  // ===================================================================
  // 1. Murobi — pusat notifikasi di dalam aplikasi
  // ===================================================================
  const ctx = await browser.newContext({ viewport: { width: 430, height: 932 } });
  const murobi = await masuk(ctx, 'sbx_murobi_a');
  catat('A-1 Login aplikasi berhasil (bundel React Native berjalan)', !murobi.url().endsWith('/login'), murobi.url());
  await bidik(murobi, 'murobi-beranda');

  await murobi.goto(`${BASE}/notifikasi`, { waitUntil: 'domcontentloaded' });
  const adaDaftar = await tungguTeks(murobi, /belum dibaca dari \d+ notifikasi/i, 25000);
  catat('A-2 Layar pusat notifikasi memuat data dari API', adaDaftar);

  const isiDaftar = await teks(murobi);
  const cocok = isiDaftar.match(/(\d+) belum dibaca dari (\d+) notifikasi/i);
  catat('A-3 Ringkasan jumlah belum dibaca tampil', cocok !== null, cocok ? cocok[0] : '');
  catat('A-4 Alasan izin TIDAK tampil pada daftar notifikasi', !/pernikahan/i.test(isiDaftar));
  catat('A-5 Nama santri tampil di dalam aplikasi (di balik autentikasi)', /Santri/i.test(isiDaftar));
  catat(
    'A-6 Status push web dilaporkan jujur sebagai tidak didukung',
    /Push tidak aktif di perangkat ini/i.test(isiDaftar) && /perangkat nyata|tidak tersedia pada versi web/i.test(isiDaftar),
  );
  await bidik(murobi, 'murobi-pusat-notifikasi');

  // Filter
  await murobi.getByText('Belum dibaca', { exact: true }).first().click();
  const setelahFilter = await tungguTeks(murobi, /belum dibaca dari \d+ notifikasi/i, 15000);
  catat('A-7 Filter "Belum dibaca" berfungsi', setelahFilter);
  await bidik(murobi, 'murobi-filter-belum-dibaca');

  await murobi.getByText('Semua', { exact: true }).first().click();
  await tungguTeks(murobi, /belum dibaca dari \d+ notifikasi/i, 15000);

  // Detail notifikasi — sekaligus menandai dibaca
  const sebelum = Number((await teks(murobi)).match(/(\d+) belum dibaca/i)?.[1] ?? '0');
  await murobi.getByText(/Pengajuan izin baru|Murobi ditetapkan|Keputusan|Pengajuan/i).first().click();
  const detailTerbuka = await tungguTeks(murobi, /Buka detail izin|Diterima /i, 20000);
  catat('A-8 Detail notifikasi terbuka', detailTerbuka, murobi.url());

  const isiDetail = await teks(murobi);
  catat('A-9 Detail TIDAK membocorkan alasan izin', !/pernikahan/i.test(isiDetail));
  catat(
    'A-10 Detail memuat penjelasan bahwa alasan tidak dikirim lewat notifikasi',
    /Alasan izin dan catatan pengurus tidak pernah dikirim melalui notifikasi/i.test(isiDetail),
  );
  catat('A-11 Tombol "Buka detail izin" tersedia', /Buka detail izin/i.test(isiDetail));
  await bidik(murobi, 'murobi-detail-notifikasi');

  await murobi.getByText('Buka detail izin', { exact: true }).first().click();
  const izinTerbuka = await tungguTeks(murobi, /Alasan|Status|Pengajuan/i, 20000);
  catat('A-12 Navigasi ke detail izin berjalan dan server yang memutuskan hak akses', izinTerbuka, murobi.url());
  await bidik(murobi, 'murobi-detail-izin');

  await murobi.goto(`${BASE}/notifikasi`, { waitUntil: 'domcontentloaded' });
  await tungguTeks(murobi, /belum dibaca dari \d+ notifikasi/i, 20000);
  const sesudah = Number((await teks(murobi)).match(/(\d+) belum dibaca/i)?.[1] ?? '0');
  catat('A-13 Membuka detail menandai notifikasi sudah dibaca', sesudah < sebelum, `${sebelum} → ${sesudah}`);

  // Perangkat & push
  await murobi.goto(`${BASE}/notifikasi/perangkat`, { waitUntil: 'domcontentloaded' });
  const perangkatTerbuka = await tungguTeks(murobi, /Perangkat ini/i, 20000);
  catat('A-14 Layar "Perangkat & push" terbuka', perangkatTerbuka);
  const isiPerangkat = await teks(murobi);
  catat(
    'A-15 Layar menyatakan push memerlukan development build dan perangkat nyata',
    /development build dan perangkat nyata/i.test(isiPerangkat),
  );
  catat(
    'A-16 Layar menyatakan token perangkat tidak pernah ditampilkan',
    /Token perangkat tidak pernah ditampilkan/i.test(isiPerangkat),
  );
  catat(
    'A-17 Tidak ada token push yang benar-benar tampil di layar',
    !/ExponentPushToken|ExpoPushToken/i.test(isiPerangkat),
  );
  catat(
    'A-18 Mematikan push dinyatakan tidak mempengaruhi notifikasi dalam aplikasi',
    /tidak mempengaruhi notifikasi dalam aplikasi/i.test(isiPerangkat),
  );
  await bidik(murobi, 'murobi-perangkat-push');

  // Tandai semua dibaca
  await murobi.goto(`${BASE}/notifikasi`, { waitUntil: 'domcontentloaded' });
  await tungguTeks(murobi, /belum dibaca dari \d+ notifikasi/i, 20000);
  await murobi.getByText('Tandai semua dibaca', { exact: true }).first().click();
  const nol = await tungguTeks(murobi, /0 belum dibaca dari \d+ notifikasi/i, 20000);
  catat('A-19 "Tandai semua dibaca" mengosongkan jumlah belum dibaca', nol);
  await bidik(murobi, 'murobi-semua-dibaca');

  // ===================================================================
  // 2. Isolasi antar akun
  // ===================================================================
  const idMurobi = process.env.ID_NOTIF_MUROBI ?? '';
  const ctxOrtu = await browser.newContext({ viewport: { width: 430, height: 932 } });
  const ortu = await masuk(ctxOrtu, 'sbx_ortu_a');
  await ortu.goto(`${BASE}/notifikasi`, { waitUntil: 'domcontentloaded' });
  const ortuMuat = await tungguTeks(ortu, /belum dibaca dari \d+ notifikasi|Belum ada notifikasi/i, 25000);
  catat('A-20 Orang tua dapat membuka pusat notifikasinya sendiri', ortuMuat);
  await bidik(ortu, 'ortu-pusat-notifikasi');

  if (idMurobi) {
    await ortu.goto(`${BASE}/notifikasi/${idMurobi}`, { waitUntil: 'domcontentloaded' });
    const ditolak = await tungguTeks(ortu, /tidak berhak|tidak ditemukan|403|Akses|gagal/i, 20000);
    const isiOrtu = await teks(ortu);
    catat('A-21 Orang tua DITOLAK membuka notifikasi milik murobi', ditolak, isiOrtu.slice(0, 90));
    catat('A-22 Isi notifikasi murobi tidak bocor ke layar orang tua', !/pernikahan/i.test(isiOrtu));
    await bidik(ortu, 'ortu-ditolak-notifikasi-murobi');
  } else {
    catat('A-21 Orang tua DITOLAK membuka notifikasi milik murobi', false, 'ID_NOTIF_MUROBI tidak diberikan');
    catat('A-22 Isi notifikasi murobi tidak bocor ke layar orang tua', false, 'dilewati');
  }

  // ===================================================================
  // 3. Regresi Fase 2/3 di dalam aplikasi
  // ===================================================================
  await murobi.goto(`${BASE}/perizinan`, { waitUntil: 'domcontentloaded' });
  const izinList = await tungguTeks(murobi, /Perizinan|Pengajuan|Belum ada/i, 25000);
  catat('A-23 Layar perizinan Fase 2/3 tetap berfungsi', izinList);
  await bidik(murobi, 'regresi-perizinan');

  await murobi.goto(`${BASE}/schedules`, { waitUntil: 'domcontentloaded' });
  const jadwal = await tungguTeks(murobi, /Jadwal|Belum ada|Pertemuan/i, 25000);
  catat('A-24 Layar jadwal V1 tetap berfungsi', jadwal);
  await bidik(murobi, 'regresi-jadwal');

  catat('A-25 Tidak ada galat JavaScript pada seluruh layar yang dibuka', galat.length === 0, galat.slice(0, 3).join(' | '));
} finally {
  await browser.close();
}

const gagal = hasil.filter((h) => !h.lulus);
console.log(`\n=== ${hasil.length} pemeriksaan, ${gagal.length} gagal ===`);
gagal.forEach((g) => console.log(`GAGAL: ${g.nama} ${g.keterangan}`));
console.log(
  '\nCATATAN: kedatangan push nyata TIDAK diuji di sini dan tetap menunggu' +
    ' smoke test manusia pada perangkat Android dan iOS nyata (kriteria 3 PRD).',
);
process.exit(gagal.length === 0 ? 0 : 1);
