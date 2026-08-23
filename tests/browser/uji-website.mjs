/**
 * Uji coba website Fase 4 dengan browser sungguhan (Chromium/Playwright).
 *
 * Berbeda dari smoke test HTTP yang memeriksa kode status dan potongan HTML,
 * skrip ini benar-benar MERENDER halaman, MENGKLIK tombol, dan mengambil
 * tangkapan layar — sehingga masalah tampilan, tombol yang tidak berfungsi,
 * atau galat JavaScript ikut terlihat.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';

// Skrip ini mengubah status baca selama pengujian (B-11, B-13). Agar dapat
// dijalankan berulang dengan hasil yang sama, status baca milik akun uji
// dikembalikan ke "belum dibaca" lebih dulu. Hanya menyentuh basis data
// sandbox `webalhasan_test` berisi data sintetis.
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

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8900';
const OUT = process.env.OUT_DIR ?? new URL('./tangkapan', import.meta.url).pathname;
mkdirSync(OUT, { recursive: true });

const hasil = [];
const galatKonsol = [];
let nomor = 0;

function catat(nama, lulus, keterangan = '') {
  hasil.push({ nama, lulus, keterangan });
  console.log(`${lulus ? '[lulus]' : '[GAGAL]'} ${nama}${keterangan ? ' — ' + keterangan : ''}`);
}

async function bidik(page, label) {
  nomor += 1;
  const nama = `${String(nomor).padStart(2, '0')}-${label}.png`;
  await page.screenshot({ path: `${OUT}/${nama}`, fullPage: true });
  return nama;
}


/**
 * Klik yang menunggu navigasi dengan benar.
 *
 * `waitForLoadState` saja tidak cukup: ia langsung resolve bila halaman sudah
 * selesai dimuat, sehingga assertion berikutnya membaca HALAMAN LAMA. Promise
 * navigasi harus dibuat SEBELUM klik.
 */
async function klikNavigasi(page, locator) {
  const navigasi = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => null);
  await locator.click();
  await navigasi;
  await page.waitForLoadState('domcontentloaded').catch(() => {});
}

/** Teks lengkap halaman termasuk elemen tersembunyi seperti <details>. */
async function teksLengkap(page) {
  return (await page.content()).replace(/<[^>]+>/g, ' ');
}

async function masuk(context, username, password = 'Sandbox#123') {
  const page = await context.newPage();
  // Sandbox memblokir CDN eksternal (Bootstrap/FontAwesome), sehingga kegagalan
  // memuat aset dari cdn.jsdelivr.net dan cdnjs.cloudflare.com BUKAN cacat
  // aplikasi. Yang dipantau adalah galat JavaScript dari kode halaman sendiri.
  page.on('console', (m) => {
    const teks = m.text();
    const asetCdn = /cdn\.jsdelivr\.net|cdnjs\.cloudflare\.com|ERR_TUNNEL_CONNECTION_FAILED|ERR_NAME_NOT_RESOLVED|Failed to load resource/i.test(teks);
    if (m.type() === 'error' && !asetCdn) galatKonsol.push(`${username}: ${teks}`);
  });
  // `Chart is not defined` berasal dari halaman dashboard V1 yang memuat
  // chart.js dari cdn.jsdelivr.net. CDN diblokir sandbox, jadi ini akibat
  // lingkungan pengujian — bukan cacat kode, dan bukan bagian Fase 4.
  const akibatCdn = /Chart is not defined|bootstrap is not defined/i;
  page.on('pageerror', (e) => {
    if (akibatCdn.test(e.message)) return;
    galatKonsol.push(`${username}: ${e.message}`);
  });
  await page.goto(`${BASE}/admin/admin_login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="username"]', username);
  await page.fill('input[name="password"]', password);
  await klikNavigasi(page, page.locator('button[type="submit"], input[type="submit"]').first());
  return page;
}

const browser = await chromium.launch({ ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}), args: ['--no-sandbox'] });

try {
  // =====================================================================
  // 1. Murobi — pusat notifikasi
  // =====================================================================
  const ctxMurobi = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const murobi = await masuk(ctxMurobi, 'sbx_murobi_a');

  await murobi.goto(`${BASE}/portal/notifikasi.php`, { waitUntil: 'networkidle' });
  catat('B-1 Murobi dapat membuka pusat notifikasi', murobi.url().includes('notifikasi.php'));

  const judulHalaman = await murobi.locator('h1').first().innerText();
  catat('B-2 Judul halaman "Notifikasi" tampil', judulHalaman.trim() === 'Notifikasi', judulHalaman.trim());

  const lencanaNav = await murobi.locator('nav .badge.rounded-pill').first().innerText().catch(() => '');
  catat('B-3 Lencana jumlah belum dibaca tampil di navigasi', /^\d+$/.test(lencanaNav.trim()), `lencana="${lencanaNav.trim()}"`);

  const kartuBaru = await murobi.locator('.list-group-item .badge:has-text("Baru")').count();
  catat('B-4 Notifikasi baru tampil pada daftar', kartuBaru > 0, `${kartuBaru} item bertanda Baru`);

  const teksDaftar = await murobi.locator('.list-group').innerText();
  catat(
    'B-5 Alasan izin TIDAK tampil pada daftar notifikasi',
    !teksDaftar.includes('pernikahan') && !teksDaftar.includes('Dijemput orang tua'),
  );
  catat('B-6 Nama santri tampil (in-app di balik autentikasi)', /Santri/i.test(teksDaftar));
  await bidik(murobi, 'murobi-pusat-notifikasi');

  // Filter
  await klikNavigasi(murobi, murobi.locator('.nav-pills a:has-text("Belum dibaca")'));
  catat('B-7 Filter "Belum dibaca" berfungsi', murobi.url().includes('status=belum_dibaca'));
  await bidik(murobi, 'murobi-filter-belum-dibaca');

  // Detail
  await klikNavigasi(murobi, murobi.locator('.nav-pills a:has-text("Semua")'));
  const sebelumDetail = await murobi.locator('nav .badge.rounded-pill').first().innerText().catch(() => '0');
  await klikNavigasi(murobi, murobi.locator('.list-group-item a:has-text("Detail")').first());
  const panelDetail = await murobi.locator('.card').first().innerText();
  catat('B-8 Panel detail notifikasi terbuka', panelDetail.includes('Tutup') || murobi.url().includes('detail='));
  catat('B-9 Detail tidak membocorkan alasan izin', !panelDetail.includes('pernikahan'));
  const adaTautanIzin = await murobi.locator('a:has-text("Buka detail izin")').count();
  catat('B-10 Tautan "Buka detail izin" tersedia', adaTautanIzin > 0);
  await bidik(murobi, 'murobi-detail-notifikasi');

  const sesudahDetail = await murobi.locator('nav .badge.rounded-pill').first().innerText().catch(() => '0');
  catat(
    'B-11 Membuka detail menandai notifikasi dibaca (lencana berkurang)',
    Number(sesudahDetail || 0) < Number(sebelumDetail || 0),
    `${sebelumDetail} → ${sesudahDetail || '0'}`,
  );

  // Deep link ke detail izin, otorisasi server
  await klikNavigasi(murobi, murobi.locator('a:has-text("Buka detail izin")').first());
  catat('B-12 Deep link membuka detail izin untuk murobi yang berhak', murobi.url().includes('izin_detail.php'));
  await bidik(murobi, 'murobi-detail-izin');

  // Tandai semua dibaca
  await murobi.goto(`${BASE}/portal/notifikasi.php`, { waitUntil: 'networkidle' });
  const tombolSemua = murobi.locator('button:has-text("Tandai semua sudah dibaca")');
  const aktifSebelum = await tombolSemua.isEnabled();
  if (aktifSebelum) {
    await klikNavigasi(murobi, tombolSemua);
  }
  const lencanaAkhir = await murobi.locator('nav .badge.rounded-pill').count();
  catat('B-13 Tandai semua dibaca mengosongkan lencana', lencanaAkhir === 0);
  const tombolSetelah = await murobi.locator('button:has-text("Tandai semua sudah dibaca")').isDisabled();
  catat('B-14 Tombol tandai semua nonaktif saat tidak ada yang belum dibaca', tombolSetelah);
  await bidik(murobi, 'murobi-semua-dibaca');

  // =====================================================================
  // 2. Otorisasi silang — orang tua membuka notifikasi murobi
  // =====================================================================
  const ctxOrtu = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const ortu = await masuk(ctxOrtu, 'sbx_ortu_a');
  await ortu.goto(`${BASE}/portal/notifikasi.php`, { waitUntil: 'networkidle' });
  catat('B-15 Orang tua dapat membuka pusat notifikasinya sendiri', ortu.url().includes('notifikasi.php'));
  await bidik(ortu, 'ortu-pusat-notifikasi');

  const idMilikMurobi = process.env.ID_NOTIF_MUROBI ?? '1';
  await ortu.goto(`${BASE}/portal/notifikasi.php?detail=${idMilikMurobi}`, { waitUntil: 'networkidle' });
  const isiOrtu = await teksLengkap(ortu);
  catat(
    'B-16 Orang tua ditolak saat membuka notifikasi milik murobi',
    isiOrtu.includes('tidak dapat dibuka') || isiOrtu.includes('tidak berhak'),
  );
  catat('B-17 Isi notifikasi murobi tidak bocor ke halaman orang tua', !isiOrtu.includes('menunggu keputusan Anda'));
  await bidik(ortu, 'ortu-ditolak-notifikasi-orang-lain');

  const aksesPanel = await ortu.goto(`${BASE}/admin/admin_notifikasi.php`, { waitUntil: 'domcontentloaded' });
  catat('B-18 Orang tua ditolak 403 pada panel kanal admin', aksesPanel.status() === 403, `HTTP ${aksesPanel.status()}`);

  // =====================================================================
  // 3. Admin — panel kanal notifikasi
  // =====================================================================
  const ctxAdmin = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
  const admin = await masuk(ctxAdmin, 'sbx_admin');
  await admin.goto(`${BASE}/admin/admin_notifikasi.php`, { waitUntil: 'networkidle' });
  catat('B-19 Admin dapat membuka panel kanal', (await admin.locator('h1').first().innerText()).includes('Kanal Notifikasi'));

  const kartuKanal = await admin.locator('.card .card-body h2').allInnerTexts();
  catat('B-20 Ketiga kanal tampil', kartuKanal.length >= 3, kartuKanal.join(' | '));

  const inAppSelalu = await admin.locator('button:has-text("Selalu aktif")').count();
  catat('B-21 Kanal in-app ditandai selalu aktif dan tidak dapat dimatikan', inAppSelalu > 0);

  const badanPanel = await teksLengkap(admin);
  catat('B-22 Panel menampilkan NAMA environment, bukan nilainya', badanPanel.includes('WHATSAPP_API_TOKEN'));
  catat('B-23 Panel tidak menampilkan token perangkat', !badanPanel.includes('ExponentPushToken'));
  catat('B-24 Panel tidak menampilkan nilai PUSH_TOKEN_KEY', !badanPanel.includes('KysrKysr'));
  await bidik(admin, 'admin-panel-kanal');

  // Pemeriksaan konfigurasi push
  await klikNavigasi(admin, admin.locator('form:has(input[value="periksa"]):has(input[value="Push"]) button'));
  const setelahPeriksa = await admin.locator('.alert').first().innerText();
  catat('B-25 Pemeriksaan konfigurasi push berjalan dan melaporkan hasil', setelahPeriksa.length > 0, setelahPeriksa.split('\n')[0].slice(0, 80));
  await bidik(admin, 'admin-pemeriksaan-push');

  // Nyalakan push
  await klikNavigasi(admin, admin.locator('form:has(input[value="sakelar"]):has(input[value="Push"]) button:has-text("Nyalakan kanal")'));
  const badanNyala = await teksLengkap(admin);
  catat('B-26 Kanal push dapat dinyalakan admin', badanNyala.includes('Push dinyalakan'));
  await bidik(admin, 'admin-push-nyala');

  // WhatsApp ditolak menyala
  await klikNavigasi(admin, admin.locator('form:has(input[value="sakelar"]):has(input[value="WhatsApp"]) button:has-text("Nyalakan kanal")'));
  const badanWa = await teksLengkap(admin);
  catat(
    'B-27 WhatsApp DITOLAK menyala karena pemeriksaan belum lulus',
    badanWa.includes('tidak dapat dinyalakan') || badanWa.includes('belum lulus'),
  );
  await bidik(admin, 'admin-whatsapp-ditolak');

  // Pesan uji in-app
  await klikNavigasi(admin, admin.locator('form:has(input[value="pesan_uji"]):has(input[value="InApp"]) button'));
  catat('B-28 Pesan uji in-app terkirim ke admin', (await teksLengkap(admin)).includes('Pesan uji'));

  await admin.goto(`${BASE}/portal/notifikasi.php`, { waitUntil: 'networkidle' });
  const daftarAdmin = await admin.locator('.list-group').innerText().catch(() => '');
  catat('B-29 Pesan uji muncul di pusat notifikasi admin', daftarAdmin.includes('Pesan uji'));
  await bidik(admin, 'admin-pesan-uji-diterima');

  // Antrean admin: pengajuan yang perlu penetapan
  await admin.goto(`${BASE}/portal/notifikasi.php`, { waitUntil: 'networkidle' });
  const teksAdmin = await admin.locator('.list-group').innerText().catch(() => '');
  catat('B-30 Admin menerima notifikasi "perlu penetapan murobi"', /penetapan murobi/i.test(teksAdmin));

  // Matikan push kembali (kembalikan keadaan)
  await admin.goto(`${BASE}/admin/admin_notifikasi.php`, { waitUntil: 'networkidle' });
  await klikNavigasi(admin, admin.locator('form:has(input[value="sakelar"]):has(input[value="Push"]) button:has-text("Matikan kanal")'));
  catat('B-31 Kanal push dapat dimatikan kembali', (await teksLengkap(admin)).includes('Push dimatikan'));

  // Halaman kegagalan dan audit
  const badanAkhir = await teksLengkap(admin);
  catat('B-32 Bagian "Pengiriman gagal" tampil', badanAkhir.includes('Pengiriman gagal'));
  catat('B-33 Bagian "Audit perubahan kanal" tampil dan berisi', badanAkhir.includes('Audit perubahan kanal') && badanAkhir.includes('kanal_diubah'));
  await bidik(admin, 'admin-audit-kanal');

  // =====================================================================
  // 4. Regresi tampilan Fase 1-3
  // =====================================================================
  await admin.goto(`${BASE}/portal/izin.php`, { waitUntil: 'networkidle' });
  catat('B-34 Daftar perizinan Fase 2/3 tetap terbuka normal', (await admin.locator('h1').first().innerText()).includes('Daftar Perizinan'));
  await bidik(admin, 'regresi-daftar-perizinan');

  await admin.goto(`${BASE}/portal/izin_antrean.php`, { waitUntil: 'networkidle' });
  catat('B-35 Antrean perizinan tetap terbuka normal', (await admin.locator('h1').first().innerText()).length > 0);

  await admin.goto(`${BASE}/admin/admin_dashboard.php`, { waitUntil: 'networkidle' });
  catat('B-36 Dashboard admin V1 tetap terbuka normal', admin.url().includes('admin_dashboard.php'));
  await bidik(admin, 'regresi-dashboard-admin');

  // =====================================================================
  catat('B-37 Tidak ada galat JavaScript pada seluruh halaman yang dibuka', galatKonsol.length === 0, galatKonsol.slice(0, 3).join(' | '));
} finally {
  await browser.close();
}

const gagal = hasil.filter((h) => !h.lulus);
console.log(`\n=== ${hasil.length} pemeriksaan, ${gagal.length} gagal ===`);
if (gagal.length > 0) {
  gagal.forEach((g) => console.log(`GAGAL: ${g.nama} ${g.keterangan}`));
  process.exit(1);
}
console.log('UJI BROWSER WEBSITE LULUS.');
