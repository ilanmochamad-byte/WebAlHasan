import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Uji aplikasi Fase 4 penugasan lintas guru/pengurus — layar Keamanan hanya
// baca dan identitas akun (Expo WEB dari repo alhasanApps + API/DB uji nyata).
// Ini Expo WEB (Chromium), bukan perangkat native.
//
//   PKF3_DB=test_pk_f4_app_test bash tests/penugasan_keamanan_f4_db.sh
//   PKF3_DB=test_pk_f4_app_test php tests/penugasan_keamanan_f3_seed.php > /tmp/pkf4-ids.json
//   DB_NAME=test_pk_f4_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8957 tests/redesain_web_tahap1_router.php &
//   (cd <salinan alhasanApps TANPA .env*> && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8957/api/v1 npx expo start --web --port 8096 --clear &)
//   (cd tests/browser && DB_NAME=test_pk_f4_app_test PKF4_WEB=http://127.0.0.1:8957 PKF4_APP=http://127.0.0.1:8096 SEED_JSON=/tmp/pkf4-ids.json OUT_DIR=… node uji-penugasan-keamanan-f4-app.mjs)
//
//   A-A  Beranda: ubin Keamanan untuk K dan G (guru lewat identitas pengurus); tidak untuk N/admin/orang tua
//   A-B  pintu Keamanan: empat bagian, hanya baca, tanpa tombol mutasi
//   A-C  perizinan: daftar = API; rincian lengkap (alasan) berjejak audit; santri nonaktif ditolak
//   A-D  pelanggaran: angka chip = API; rincian + lampiran hanya metadata; santri nonaktif ditolak
//   A-E  konseling: Rahasia tidak di daftar/pencarian; rincian Rahasia ditolak seperti tidak ada
//   A-F  kehadiran: daftar dan rincian pertemuan berjejak audit
//   A-G  profil: dua identitas (G) dan identitas pengurus (K)
//   A-H  N membuka alamat Keamanan langsung → tanpa data
//   A-I  pencabutan penugasan saat layar terbuka → data ditutup, ubin hilang
//   A-J  nol permintaan mutasi/ke luar; penyimpanan/konsol tanpa isi; 375 px tanpa gulir horizontal
const web = process.env.PKF4_WEB, app = process.env.PKF4_APP, db = process.env.DB_NAME || '';
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) throw Error('Hanya localhost dan DB test_*_test');
const ids = JSON.parse(fs.readFileSync(process.env.SEED_JSON, 'utf8'));
const out = process.env.OUT_DIR || '/tmp/pkf4-tangkapan';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const tokens = {};
async function api(user, p) {
  if (!tokens[user]) {
    const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: PASS, device_name: 'uji-pk-f4-app' }) });
    tokens[user] = (await r.json()).data.token;
  }
  const r = await fetch(`${web}/api/v1${p}`, { headers: { Authorization: `Bearer ${tokens[user]}` } });
  return { status: r.status, json: await r.json().catch(() => null) };
}

const browser = await chromium.launch({ headless: true });
const konsol = [];
const permintaanLuar = [];
const mutasiKeamanan = [];
async function halamanApp(user) {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 } });
  const page = await ctx.newPage();
  page.on('console', m => konsol.push(`${user}: ${m.text()}`));
  await page.route('**/*', async route => {
    const u = new URL(route.request().url());
    if (u.hostname !== '127.0.0.1') { permintaanLuar.push(u.origin); return route.abort(); }
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    const metode = route.request().method();
    if (metode === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    if (metode !== 'GET' && u.pathname.startsWith('/api/v1/keamanan')) mutasiKeamanan.push(`${user} ${metode} ${u.pathname}`);
    const response = await route.fetch();
    await route.fulfill({ response, headers: { ...response.headers(), 'Access-Control-Allow-Origin': app } });
  });
  await page.goto(app + '/login');
  await page.getByPlaceholder('Masukkan username').fill(user);
  await page.getByPlaceholder('Masukkan password').fill(PASS);
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
  await page.waitForURL(u => u.pathname !== '/login');
  return page;
}
const teks = async p => (await p.locator('body').innerText()).replace(/\s+/g, ' ');
const tunggu = (p, t, timeout = 20000) => p.getByText(t, { exact: false }).first().waitFor({ timeout });
const lebar = async (p, nama) => {
  const lebih = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  ok(lebih <= 1, `${nama}: tanpa gulir horizontal pada 375 px (selisih ${lebih}px)`);
};
const foto = (p, nama) => p.screenshot({ path: path.join(out, `app-pkf4-${nama}-375.png`), fullPage: true });
const penyimpanan = p => p.evaluate(() => JSON.stringify([Object.entries(localStorage), Object.entries(sessionStorage)]));
const MUTASI = /^(Ajukan|Setujui|Tolak|Simpan|Catat|Koreksi|Batalkan|Teruskan|Tandai|Unduh|Hapus|Kirim|Putuskan|Terbitkan)/i;
const tombolMutasi = async p => (await p.getByRole('button').allInnerTexts()).map(t => t.trim()).filter(t => MUTASI.test(t));
const ubinKeamanan = p => p.getByRole('button', { name: 'Keamanan', exact: true });
const auditJumlah = aksi => Number(sql(`SELECT COUNT(*) FROM audit_logs WHERE action='${aksi}' AND actor_user_id=${ids.k}`));

try {
  console.log('\n=== A-A. Beranda ===');
  const k = await halamanApp('sbx_pengurus_biasa');
  await ubinKeamanan(k).waitFor({ timeout: 20000 });
  ok(true, 'K (pengurus berpenugasan Keamanan): ubin Keamanan tampil');
  await lebar(k, 'Beranda K');
  await foto(k, 'beranda-k');
  const g = await halamanApp('sbx_guru_biasa');
  await ubinKeamanan(g).waitFor({ timeout: 20000 });
  ok(true, 'G (guru lewat identitas pengurus miliknya): ubin Keamanan tampil');
  for (const u of ['sbx_pengurus_b', 'sbx_admin', 'sbx_v7_wali_a']) {
    const p = await halamanApp(u);
    await p.waitForLoadState('networkidle');
    await p.waitForTimeout(1500);
    ok(await ubinKeamanan(p).count() === 0, `${u}: tanpa ubin Keamanan`);
    if (u === 'sbx_pengurus_b') {
      console.log('\n=== A-H. N membuka alamat Keamanan langsung ===');
      await p.goto(app + '/keamanan');
      await tunggu(p, 'Tidak ada penugasan Keamanan');
      ok(true, 'N: pintu Keamanan menampilkan "Tidak ada penugasan Keamanan"');
      await p.goto(app + `/keamanan/izin/${ids.izin_wali}`);
      await tunggu(p, 'Informasi tidak dapat diakses');
      ok(!(await teks(p)).includes(ids.alasan_wali), 'N: rincian izin langsung ditolak server, alasan tidak tampil');
    }
    await p.context().close();
  }

  console.log('\n=== A-B. Pintu Keamanan ===');
  await ubinKeamanan(k).click();
  await tunggu(k, 'Pantauan santri');
  const pintu = await teks(k);
  ok(['Kehadiran pengajian', 'Perizinan santri', 'Pelanggaran', 'Konseling'].every(t => pintu.includes(t)), 'Empat bagian: kehadiran, perizinan, pelanggaran, konseling');
  ok(pintu.includes('Hanya baca') && pintu.includes('Rahasia tidak ditampilkan'), 'Keterangan hanya baca dan kasus Rahasia');
  ok((await tombolMutasi(k)).length === 0, 'Pintu tanpa tombol mutasi');
  await lebar(k, 'Pintu Keamanan');
  await foto(k, 'pintu');

  console.log('\n=== A-C. Perizinan ===');
  const apiIzin = await api('sbx_pengurus_biasa', '/keamanan/izin');
  await k.getByRole('button', { name: /^Perizinan santri/ }).click();
  await tunggu(k, `${apiIzin.json.data.ringkasan.total} pengajuan`);
  ok(true, `Ringkasan perizinan aplikasi = API (${apiIzin.json.data.ringkasan.total} pengajuan)`);
  await k.getByRole('radio', { name: 'Wali', exact: true }).click();
  await tunggu(k, '1 pengajuan');
  const daftarIzin = await teks(k);
  ok(daftarIzin.includes('SBX V7 Santri Satu') && !daftarIzin.includes(ids.alasan_wali), 'Saringan asal Wali: pengajuan wali tampil, daftar tanpa alasan');
  ok((await tombolMutasi(k)).length === 0, 'Daftar perizinan tanpa tombol mutasi');
  await lebar(k, 'Daftar perizinan');
  await foto(k, 'izin-daftar');
  const auditIzin = auditJumlah('keamanan.izin.dilihat');
  await k.getByRole('button', { name: `Buka izin SBX V7 Santri Satu nomor ${ids.izin_wali}` }).click();
  await tunggu(k, ids.alasan_wali);
  const rincianIzin = await teks(k);
  ok(rincianIzin.includes('PKF3 catatan wali') && rincianIzin.includes('Riwayat') && rincianIzin.includes('Belum diputus'), 'Rincian izin lengkap: alasan, catatan wali, keputusan, riwayat (PK-T4)');
  ok((await tombolMutasi(k)).length === 0, 'Rincian izin tanpa tombol tinjau/putus/koreksi/batal');
  ok(auditJumlah('keamanan.izin.dilihat') === auditIzin + 1, 'Pembukaan rincian dari aplikasi tercatat satu kali di audit');
  await lebar(k, 'Rincian izin');
  await foto(k, 'izin-rincian');
  await k.goto(app + `/keamanan/izin/${ids.izin_nonaktif}`);
  await tunggu(k, 'tidak aktif');
  ok(true, 'Izin santri nonaktif dibuka langsung → ditolak (tidak ditemukan/tidak aktif)');

  console.log('\n=== A-D. Pelanggaran ===');
  const apiP = await api('sbx_pengurus_biasa', '/keamanan/pelanggaran');
  const semuaP = Object.values(apiP.json.data.jumlah_status).reduce((a, b) => a + b, 0);
  await k.goto(app + '/keamanan/pelanggaran');
  await tunggu(k, `Semua (${semuaP})`);
  ok(true, `Chip Semua = jumlah API (${semuaP})`);
  ok(await k.getByRole('radio', { name: `Dicatat (${apiP.json.data.jumlah_status.Dicatat})` }).count() === 1, 'Chip Dicatat = API');
  await lebar(k, 'Daftar pelanggaran');
  await foto(k, 'pelanggaran-daftar');
  await k.goto(app + `/keamanan/pelanggaran/${ids.pelanggaran_lampiran}`);
  await tunggu(k, 'bukti-pkf3.pdf');
  const rincianP = await teks(k);
  ok(rincianP.includes('Uraian') && rincianP.includes('Unduh lampiran melalui website'), 'Rincian pelanggaran lengkap; lampiran hanya metadata, unduhan lewat website');
  ok(await k.locator('a[href*="lampiran"]').count() === 0 && (await tombolMutasi(k)).length === 0, 'Tanpa tautan unduh atau tombol mutasi');
  await lebar(k, 'Rincian pelanggaran');
  await foto(k, 'pelanggaran-rincian');
  await k.goto(app + `/keamanan/pelanggaran/${ids.pelanggaran_nonaktif}`);
  await tunggu(k, 'Pelanggaran tidak ditemukan');
  ok(true, 'Pelanggaran santri nonaktif → "tidak ditemukan"');

  console.log('\n=== A-E. Konseling ===');
  const namaRahasia = sql(`SELECT s.nama_santri FROM v3_konseling_kasus k JOIN santri s ON s.id=k.santri_id WHERE k.id=${ids.kasus_rahasia}`);
  const namaInternal = sql(`SELECT s.nama_santri FROM v3_konseling_kasus k JOIN santri s ON s.id=k.santri_id WHERE k.id=${ids.kasus_internal}`);
  await k.goto(app + '/keamanan/konseling');
  await tunggu(k, namaInternal);
  const apiK = await api('sbx_pengurus_biasa', '/keamanan/konseling');
  ok(await k.getByRole('button', { name: /^Buka kasus konseling/ }).count() === apiK.json.data.items.length
    && !apiK.json.data.items.some(i => i.id === ids.kasus_rahasia), `Daftar kasus aplikasi = API (${apiK.json.data.items.length}), tanpa kasus Rahasia`);
  await k.getByLabel('Cari santri').fill(namaRahasia);
  await k.getByRole('button', { name: 'Cari', exact: true }).click();
  await k.waitForTimeout(1500);
  ok(!(await k.getByRole('button', { name: /^Buka kasus konseling/ }).allInnerTexts()).some(t => t.includes('Rahasia')), 'Pencarian nama santri kasus Rahasia tidak memunculkan kasus Rahasia');
  await k.goto(app + `/keamanan/konseling/${ids.kasus_rahasia}`);
  await tunggu(k, 'Kasus tidak ditemukan');
  const pesanRahasia = await teks(k);
  await k.goto(app + '/keamanan/konseling/99999999');
  await tunggu(k, 'Kasus tidak ditemukan');
  ok(pesanRahasia.includes('Kasus tidak ditemukan atau tidak dapat dibaca Keamanan'), 'Kasus Rahasia ditolak dengan pesan yang sama dengan kasus tidak ada');
  await k.goto(app + `/keamanan/konseling/${ids.kasus_internal}`);
  await tunggu(k, 'Tujuan');
  ok((await teks(k)).includes('Pelanggaran tertaut') && (await tombolMutasi(k)).length === 0, 'Rincian kasus Internal lengkap, tanpa tombol mutasi');
  await lebar(k, 'Rincian kasus');
  await foto(k, 'konseling-rincian');

  console.log('\n=== A-F. Kehadiran ===');
  await k.goto(app + '/keamanan/kehadiran');
  await tunggu(k, 'pertemuan');
  const apiR = await api('sbx_pengurus_biasa', '/keamanan/kehadiran');
  ok((await teks(k)).includes(`${apiR.json.data.summary.meeting_count} pertemuan · ${apiR.json.data.summary.detail_count} catatan`), 'Ringkasan kehadiran bulan berjalan = API');
  await lebar(k, 'Kehadiran');
  await foto(k, 'kehadiran');
  const pertemuanPertama = apiR.json.data.items[0]?.meeting_id;
  if (pertemuanPertama) {
    const auditR = auditJumlah('keamanan.kehadiran.dilihat');
    await k.getByRole('button', { name: /^Buka pertemuan/ }).first().click();
    await tunggu(k, 'Peserta');
    ok(auditJumlah('keamanan.kehadiran.dilihat') === auditR + 1 && (await tombolMutasi(k)).length === 0, 'Rincian pertemuan berjejak audit, tanpa tombol ubah kehadiran');
    await foto(k, 'kehadiran-rincian');
  } else {
    ok(false, 'Fixture kehadiran bulan berjalan kosong');
  }

  console.log('\n=== A-G. Profil dua identitas ===');
  await g.goto(app + '/profil');
  await tunggu(g, 'Identitas & penugasan');
  const profilG = await teks(g);
  ok(profilG.includes('Sebagai guru') && profilG.includes('Sebagai pengurus') && profilG.includes('dibentuk dari data guru') && /Sebagai pengurus.*Keamanan/.test(profilG),
    'G: identitas guru dan identitas pengurus milik guru; Keamanan di bawah identitas pengurus');
  // Akun guru (empat tab) sudah meluap 33 px pada bilah tab web di SEMUA tab sejak baseline
  // mobile 367ae5d (kontrol: Beranda/Profil/Jadwal 33 px). Yang diukur di sini bagian baru.
  const kananIdentitas = await g.evaluate(() => Math.max(...[...document.querySelectorAll('[aria-label^="Sebagai"], div')]
    .filter(e => /^Sebagai (guru|pengurus)/.test((e.innerText || '').trim()) && e.children.length > 0)
    .map(e => e.getBoundingClientRect().right)));
  ok(kananIdentitas <= 376, `Profil G: bagian identitas di dalam 375 px (tepi kanan ${Math.round(kananIdentitas)}px)`);
  await foto(g, 'profil-g');
  await k.goto(app + '/profil');
  await tunggu(k, 'Identitas & penugasan');
  const profilK = await teks(k);
  ok(!profilK.includes('Sebagai guru') && profilK.includes('Sebagai pengurus') && profilK.includes('Keamanan'), 'K: hanya identitas pengurus dengan penugasan Keamanan');

  console.log('\n=== A-I. Pencabutan saat layar terbuka ===');
  await g.goto(app + `/keamanan/izin/${ids.izin_wali}`);
  await tunggu(g, ids.alasan_wali);
  sql(`UPDATE keamanan_assignments SET is_active=0 WHERE id=${ids.penugasan_g}`);
  await g.evaluate(() => { Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' }); document.dispatchEvent(new Event('visibilitychange')); });
  await g.evaluate(() => { Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' }); document.dispatchEvent(new Event('visibilitychange')); });
  await tunggu(g, 'Informasi tidak dapat diakses');
  ok(!(await teks(g)).includes(ids.alasan_wali), 'Kembali dari latar sesudah penugasan dicabut: isi ditutup, pesan penolakan server');
  await g.goto(app + '/');
  await g.waitForLoadState('networkidle');
  await g.waitForTimeout(1500);
  ok(await ubinKeamanan(g).count() === 0, 'Beranda G sesudah pencabutan: ubin Keamanan hilang');
  sql(`UPDATE keamanan_assignments SET is_active=1 WHERE id=${ids.penugasan_g}`);

  console.log('\n=== A-J. Privasi dan jaringan ===');
  ok(mutasiKeamanan.length === 0, `Nol permintaan non-GET ke /api/v1/keamanan (${mutasiKeamanan.length})`);
  ok(permintaanLuar.length === 0, `Nol permintaan ke luar 127.0.0.1 (${[...new Set(permintaanLuar)].join(', ')})`);
  const simpan = (await penyimpanan(k)) + (await penyimpanan(g));
  // Expo web menyimpan token sesi di sessionStorage `alhasan_teacher_api_token` (desain lama; native: SecureStore).
  const tanpaSesi = simpan.replace(/\["alhasan_teacher_api_token","[a-f0-9]{64}"\]/g, '');
  ok(!simpan.includes(ids.alasan_wali) && !simpan.includes('PRIVATE-T5') && !simpan.includes('SBX V7 Santri') && !/[a-f0-9]{64}/.test(tanpaSesi),
    'localStorage/sessionStorage tanpa alasan izin, isi konseling, nama santri, atau token di luar kunci sesi');
  ok(!konsol.some(t => t.includes(ids.alasan_wali) || t.includes('PRIVATE-T5') || /Bearer [a-f0-9]{20,}/.test(t)), 'Konsol tanpa alasan izin, isi konseling, atau token');
  ok(Number(sql("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'keamanan.%' AND (after_json LIKE '%PKF3%' OR after_json LIKE '%PRIVATE-T5%')")) === 0, 'Jejak audit Keamanan tanpa isi');
} catch (e) {
  ok(false, `Galat tak terduga: ${e.message}`);
} finally {
  await browser.close();
}
console.log(`\nHasil: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
