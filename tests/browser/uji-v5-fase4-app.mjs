import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

// Uji aplikasi PRD V5 Fase 4 (Expo web dari repo alhasanApps + API/DB uji nyata).
//
//   V5F3_DB=test_v5_f4_app_test bash tests/v5_phase3_db.sh
//   DB_NAME=test_v5_f4_app_test php -S 127.0.0.1:8982 tests/redesain_web_tahap1_router.php &
//   (cd ~/alhasanApps && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8982/api/v1 npx expo start --web --port 8099 &)
//   (cd tests/browser && DB_NAME=test_v5_f4_app_test V5_WEB=http://127.0.0.1:8982 V5_APP=http://127.0.0.1:8099 OUT_DIR=../../docs/phase-v5-4/tangkapan node uji-v5-fase4-app.mjs)
//
// Kelompok:
//   A  petugas mencatat di aplikasi (dua obat, rujukan, tindak lanjut); ketukan ganda dan jawaban
//      hilang → tepat satu kunjungan; rincian identik dengan halaman web dan API;
//   B  koreksi beralasan di aplikasi; konflik versi (koreksi web lebih dulu) → 409 tanpa menimpa;
//      hasil rujukan; riwayat versi web memuat alasan aplikasi;
//   C  ringkasan: pratinjau → konfirmasi → terbit (ketukan ganda) → satu peristiwa, satu InApp per wali;
//      teks identik di halaman wali web; tarik beralasan;
//   D  wali: daftar/detail identik web, notifikasi generik → detail; wali lain & relasi dicabut 403;
//   E  pembimbing (terkait, hanya baca), pembimbing/murobi di luar cakupan 403, mutasi API ditolak;
//   F  admin: hanya baca, riwayat akses memuat kanal api, penugasan dari aplikasi
//      (buat → petugas baru dapat mencatat; nonaktifkan → sesi lama ditolak; aktifkan);
//   G  sesi kedaluwarsa → kembali ke login; tanpa sesi → tidak ada isi; penyimpanan & konsol bersih;
//   H  tata letak 375 px tanpa gulir horizontal (tangkapan layar diperiksa visual).
const web = process.env.V5_WEB, app = process.env.V5_APP, db = process.env.DB_NAME || '';
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || ''))) throw Error('Hanya localhost');
if (!/^test_.*_test$/.test(db)) throw Error('Hanya DB test_*_test');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const out = process.env.OUT_DIR || '/tmp/v5f4-tangkapan';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';
const tanda = 'V5F4APP' + Math.random().toString(36).slice(2, 7).toUpperCase();

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const sql = q => execFileSync('php', [path.join(root, 'tests/v5_phase4_sql.php'), q], { env: { ...process.env, DB_NAME: db } }).toString().trim();
const tokens = {};
async function api(user, method, p, body) {
  if (!tokens[user]) {
    const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: PASS, device_name: 'uji-v5-f4' }) });
    tokens[user] = (await r.json()).data.token;
  }
  const r = await fetch(`${web}/api/v1${p}`, { method, headers: { Authorization: `Bearer ${tokens[user]}`, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
  return { status: r.status, json: await r.json().catch(() => null) };
}
const kunci = p => `${p}-${Math.random().toString(36).slice(2, 12)}`;

const browser = await chromium.launch({ headless: true });
const konsol = [];
const permintaanLuar = [];
const semuaHalaman = [];
async function halamanApp(user, width = 375) {
  const ctx = await browser.newContext({ viewport: { width, height: 812 } });
  const page = await ctx.newPage();
  semuaHalaman.push([user, page]);
  page.on('console', m => konsol.push(`${user}: ${m.text()}`));
  await page.route('**/*', async route => {
    const u = new URL(route.request().url());
    if (u.hostname !== '127.0.0.1') { permintaanLuar.push(u.origin); return route.abort(); }
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    if (route.request().method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    const response = await route.fetch();
    await route.fulfill({ response, headers: { ...response.headers(), 'Access-Control-Allow-Origin': app } });
  });
  if (user) {
    await page.goto(app + '/login');
    await page.getByPlaceholder('Masukkan username').fill(user);
    await page.getByPlaceholder('Masukkan password').fill(PASS);
    await page.getByRole('button', { name: 'Masuk', exact: true }).click();
    await page.waitForURL(u => u.pathname !== '/login');
  }
  return page;
}
async function webMasuk(user) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  await page.route('**/*', r => (new URL(r.request().url()).hostname === '127.0.0.1' ? r.fallback() : r.abort()));
  await page.goto(web + '/portal/index.php');
  await page.fill('#username', user);
  await page.fill('#password', PASS);
  await Promise.all([page.waitForURL(/\/portal\/index\.php/), page.getByRole('button', { name: 'Masuk', exact: true }).click()]);
  return page;
}
const tombol = (p, name) => p.getByRole('button', { name, exact: true });
const isi = (p, name, v) => p.getByRole('textbox', { name, exact: true }).fill(v);
const teks = async p => (await p.locator('body').innerText()).replace(/\s+/g, ' ');
async function tanpaGulirSamping(p, label) {
  const lebih = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  ok(lebih <= 1, `${label}: tanpa gulir horizontal pada 375 px (selisih ${lebih}px)`);
}
async function tangkap(p, nama) { await p.screenshot({ path: path.join(out, nama + '.png'), fullPage: true }); }

const sW = Number(sql("SELECT id FROM santri WHERE nis='V5S0201'"));
const namaSantri = sql(`SELECT nama_santri FROM santri WHERE id=${sW}`);

try {
  // ======================================================================= A
  console.log('\n=== A. Petugas mencatat dari aplikasi ===');
  const p = await halamanApp('sbx_v5_petugas_f');
  await p.goto(app + '/');
  await tombol(p, 'Kesehatan').click();
  await tombol(p, 'Catat kunjungan').waitFor();
  await tangkap(p, 'app-petugas-hub-375');
  await tombol(p, 'Catat kunjungan').click();
  // Pemilih santri dikelompokkan per kelas penempatan aktif: kelas dulu, santri dimuat saat dibuka.
  const binaanA = sql("SELECT nama_santri FROM santri WHERE nis='V5S0101'");
  await p.getByRole('button', { name: /SBX V5 Kelas X \(\d+\)/ }).click();
  await tombol(p, `${binaanA} · V5S0101`).waitFor();
  ok(await p.getByRole('button', { name: / · P5-\d+$/ }).count() === 0, 'Pemilih santri: membuka satu kelas hanya menampilkan santri kelas itu');
  ok(await p.getByRole('button', { name: /Belum ditempatkan di kelas \(\d+\)/ }).count() === 1, 'Pemilih santri: santri tanpa kelas berada pada kelompok tersendiri');
  await tangkap(p, 'app-petugas-pilih-kelas-375');
  await isi(p, 'Cari nama atau NIS santri aktif', 'V5S0201');
  await tombol(p, 'Cari santri').click();
  await tombol(p, `${namaSantri} · V5S0201`).click();
  await isi(p, 'Keluhan (wajib)', `${tanda}-KELUHAN demam`);
  await isi(p, 'Catatan klinis', `${tanda}-CATATAN suhu 38`);
  await isi(p, 'Tindakan', `${tanda}-TINDAKAN kompres`);
  await tombol(p, 'Tambah obat').click();
  await isi(p, 'Obat 1: nama', `${tanda}-OBAT1`);
  await isi(p, 'Jumlah', '2');
  await tombol(p, 'Tambah obat').click();
  await p.getByRole('textbox', { name: 'Obat 2: nama', exact: true }).fill(`${tanda}-OBAT2`);
  await tombol(p, 'Tambah rujukan').click();
  await isi(p, 'Rujukan 1: tujuan', `${tanda}-RUJUKAN Puskesmas`);
  await tombol(p, 'Tambah tindak lanjut').click();
  await isi(p, 'Tindak lanjut 1: uraian', `${tanda}-TL kontrol`);
  await tanpaGulirSamping(p, 'Formulir catat kunjungan');
  await tangkap(p, 'app-petugas-catat-375');
  // Jawaban server pertama dibuang setelah commit; ketukan ganda hanya mengirim satu POST.
  let posts = 0, dibuang = false;
  await p.route('**/api/v1/v5/kesehatan/kunjungan', async route => {
    if (route.request().method() !== 'POST') return route.fallback();
    posts++;
    if (!dibuang) { dibuang = true; const r = await route.fetch(); ok(r.status() === 201, 'Server menyimpan pada POST pertama (jawaban kemudian dibuang)'); return route.abort('connectionreset'); }
    return route.fallback();
  });
  await tombol(p, 'Simpan sebagai tercatat').dblclick();
  await p.getByText('offline', { exact: false }).first().waitFor();
  ok(posts === 1, `Ketukan ganda hanya mengirim satu POST (terhitung ${posts})`);
  const ulang = p.waitForResponse(r => r.url().endsWith('/v5/kesehatan/kunjungan') && r.request().method() === 'POST');
  await tombol(p, 'Simpan sebagai tercatat').click();
  const ru = await ulang;
  const badan = await ru.json();
  ok(ru.status() === 200 && badan.data.replayed === true, `Kirim ulang dengan kunci sama → ${ru.status()} replayed=${badan.data?.replayed}`);
  await p.unroute('**/api/v1/v5/kesehatan/kunjungan');
  const jumlah = sql(`SELECT COUNT(*) FROM kesehatan_kunjungan WHERE keluhan LIKE '${tanda}-KELUHAN%'`);
  ok(jumlah === '1', `Tepat satu kunjungan tersimpan (baris=${jumlah})`);
  const kid = Number(sql(`SELECT id FROM kesehatan_kunjungan WHERE keluhan LIKE '${tanda}-KELUHAN%'`));
  await p.waitForURL(u => u.pathname === `/kesehatan/kunjungan/${kid}`);
  await p.getByText(`${tanda}-OBAT2`).waitFor();
  const tApp = await teks(p);
  await tangkap(p, 'app-petugas-detail-375');
  await tanpaGulirSamping(p, 'Detail kunjungan');
  const w = await webMasuk('sbx_v5_petugas_f');
  await w.goto(`${web}/portal/kesehatan_detail.php?id=${kid}`);
  const tWeb = await teks(w);
  for (const s of ['KELUHAN demam', 'CATATAN suhu 38', 'TINDAKAN kompres', 'OBAT1', 'OBAT2', 'RUJUKAN Puskesmas', 'TL kontrol']) {
    ok(tApp.includes(`${tanda}-${s}`) && tWeb.includes(`${tanda}-${s}`), `Paritas aplikasi–web: "${s}" tampil di keduanya`);
  }
  const d = (await api('sbx_v5_petugas_f', 'GET', `/v5/kesehatan/kunjungan/${kid}`)).json.data;
  ok(d.obat.length === 2 && d.rujukan.length === 1 && d.tindak_lanjut.length === 1 && d.status === 'Tercatat' && d.versi === 1, 'API: 2 obat, 1 rujukan, 1 tindak lanjut, Tercatat versi 1');
  ok(tApp.includes('Obat yang diberikan (2)') && tApp.includes('Rujukan (1)') && tApp.includes('Tindak lanjut (1)'), 'Aplikasi menampilkan hitungan rincian yang sama');
  const kanal = sql(`SELECT JSON_UNQUOTE(JSON_EXTRACT(after_json,'$.kanal')) FROM audit_logs WHERE action='kesehatan.kunjungan.dicatat' AND entity_id=${kid}`);
  ok(kanal === 'api', `Audit pencatatan memakai kanal api (=${kanal}) tanpa isi klinis`);
  ok(!sql(`SELECT CONCAT(COALESCE(before_json,''),COALESCE(after_json,'')) FROM audit_logs WHERE entity_type LIKE 'kesehatan%'`).includes(tanda), 'audit_logs tanpa penanda isi klinis');

  // ======================================================================= B
  console.log('\n=== B. Koreksi, konflik versi, hasil rujukan ===');
  await tombol(p, 'Koreksi catatan').click();
  await p.getByRole('textbox', { name: 'Keluhan (wajib)', exact: true }).waitFor();
  await isi(p, 'Keluhan (wajib)', `${tanda}-KELUHAN demam dan pusing`);
  await tombol(p, 'Simpan koreksi').click();
  await p.getByText('Alasan wajib diisi', { exact: false }).waitFor();
  ok(sql(`SELECT versi FROM kesehatan_kunjungan WHERE id=${kid}`) === '1', 'Koreksi tanpa alasan ditolak di aplikasi (versi tetap 1)');
  await isi(p, 'Alasan koreksi (wajib)', `${tanda}-ALASAN aplikasi`);
  await tombol(p, 'Simpan koreksi').click();
  await p.waitForURL(u => u.pathname === `/kesehatan/kunjungan/${kid}`);
  await p.getByText('demam dan pusing', { exact: false }).waitFor();
  ok(sql(`SELECT versi FROM kesehatan_kunjungan WHERE id=${kid}`) === '2', 'Koreksi beralasan dari aplikasi → versi 2');
  ok(sql(`SELECT COUNT(*) FROM kesehatan_obat WHERE kunjungan_id=${kid} AND versi_dibatalkan IS NULL`) === '2', 'Koreksi tidak membatalkan obat yang tidak diubah');
  // Konflik: formulir dibuka pada versi 2, lalu petugas lain mengoreksi lebih dulu.
  await tombol(p, 'Koreksi catatan').click();
  await isi(p, 'Tindakan', `${tanda}-TINDAKAN dari aplikasi (kalah)`);
  await isi(p, 'Alasan koreksi (wajib)', 'Perubahan aplikasi yang usang');
  const dk = (await api('sbx_v5_petugas_d', 'GET', `/v5/kesehatan/kunjungan/${kid}`)).json.data;
  const keadaan = { waktu_kunjungan: dk.waktu_kunjungan, keluhan: dk.keluhan, catatan_klinis: dk.catatan_klinis, tindakan: `${tanda}-TINDAKAN dari petugas lain`, obat: dk.obat, rujukan: dk.rujukan, tindak_lanjut: dk.tindak_lanjut };
  const lain = await api('sbx_v5_petugas_d', 'POST', `/v5/kesehatan/kunjungan/${kid}/koreksi`, { ...keadaan, versi: 2, alasan: 'Koreksi petugas lain', idempotency_key: kunci('f4-lain') });
  ok(lain.status === 200 && lain.json.data.versi === 3, 'Petugas lain mengoreksi lebih dulu (versi 3)');
  const konflik = p.waitForResponse(r => r.url().endsWith(`/kunjungan/${kid}/koreksi`));
  await tombol(p, 'Simpan koreksi').click();
  ok((await konflik).status() === 409, 'Aplikasi mengirim versi 2 yang usang → 409');
  await tombol(p, 'Muat versi terbaru (isian ini dibuang)').waitFor();
  ok(sql(`SELECT tindakan FROM kesehatan_kunjungan WHERE id=${kid}`).includes('petugas lain') && sql(`SELECT versi FROM kesehatan_kunjungan WHERE id=${kid}`) === '3', 'Konflik tidak menimpa versi baru');
  await tangkap(p, 'app-petugas-konflik-375');
  await tombol(p, 'Muat versi terbaru (isian ini dibuang)').click();
  await p.getByText('Mengoreksi versi 3', { exact: false }).waitFor();
  ok(true, 'Muat versi terbaru menampilkan versi 3');
  await p.goto(`${app}/kesehatan/kunjungan/${kid}`);
  await tombol(p, 'Catat hasil rujukan').click();
  await isi(p, 'Hasil rujukan (wajib)', `${tanda}-HASIL sembuh`);
  await isi(p, 'Alasan (wajib)', 'Hasil rujukan diterima');
  await tombol(p, 'Simpan hasil rujukan').click();
  await p.waitForURL(u => u.pathname === `/kesehatan/kunjungan/${kid}`);
  await p.getByText(`${tanda}-HASIL sembuh`, { exact: false }).waitFor();
  ok(sql(`SELECT status FROM kesehatan_rujukan WHERE kunjungan_id=${kid} AND versi_dibatalkan IS NULL`) === 'Hasil diketahui', 'Hasil rujukan dari aplikasi tersimpan (versi 4)');
  await w.goto(`${web}/portal/kesehatan_riwayat.php?id=${kid}`);
  const tRiwayat = await teks(w);
  ok(tRiwayat.includes(`${tanda}-ALASAN aplikasi`) && tRiwayat.includes('Hasil rujukan diterima'), 'Riwayat versi web memuat alasan koreksi dari aplikasi');
  await tombol(p, 'Riwayat versi (4)').click();
  await p.getByText('Versi 4', { exact: true }).waitFor();
  const tVersi = await teks(p);
  ok(tVersi.includes(`${tanda}-ALASAN aplikasi`) && tVersi.includes('Koreksi petugas lain'), 'Riwayat versi aplikasi memuat alasan dan pelaku setiap versi');
  await tangkap(p, 'app-petugas-versi-375');

  // ======================================================================= C
  console.log('\n=== C. Ringkasan untuk wali ===');
  await p.goto(`${app}/kesehatan/ringkasan?id=${kid}`);
  await isi(p, 'Ringkasan (wajib, minimal 10 karakter)', `${tanda}-RINGKASAN Ananda sempat demam dan sudah ditangani.`);
  await isi(p, 'Penanganan di klinik', `${tanda}-PENANGANAN istirahat`);
  await isi(p, 'Yang perlu diperhatikan orang tua', `${tanda}-PERHATIAN kontrol pekan depan`);
  await tombol(p, 'Lihat pratinjau').click();
  await p.getByText('Pratinjau — persis seperti dilihat wali').waitFor();
  const tPratinjau = await teks(p);
  ok(!tPratinjau.includes(`${tanda}-CATATAN`) || tPratinjau.indexOf(`${tanda}-CATATAN`) < 0, 'Pratinjau tanpa catatan klinis');
  ok(await tombol(p, 'Terbitkan').isDisabled(), 'Terbitkan nonaktif sebelum konfirmasi');
  await p.getByRole('radio', { name: 'Saya sudah memeriksa pratinjau dan ingin menerbitkannya' }).click();
  await tangkap(p, 'app-petugas-pratinjau-375');
  let terbitPost = 0;
  await p.route(`**/kunjungan/${kid}/ringkasan`, r => { if (r.request().method() === 'POST') terbitPost++; return r.fallback(); });
  await tombol(p, 'Terbitkan').dblclick();
  await p.getByText('Ringkasan versi 1 terbit', { exact: false }).waitFor();
  ok(terbitPost === 1, `Ketukan ganda Terbitkan → satu POST (${terbitPost})`);
  const rid = Number(sql(`SELECT id FROM kesehatan_ringkasan WHERE kunjungan_id=${kid}`));
  ok(sql(`SELECT COUNT(*) FROM kesehatan_ringkasan_peristiwa WHERE ringkasan_id=${rid}`) === '1', 'Tepat satu peristiwa terbit');
  const inapp = sql(`SELECT COUNT(*) FROM notifikasi_outbox WHERE event_key='v5:kesehatan:ringkasan:${rid}:v1' AND kanal='InApp'`);
  const waliBerakun = sql(`SELECT COUNT(*) FROM kesehatan_ringkasan_penerima pe JOIN kesehatan_ringkasan_peristiwa e ON e.id=pe.peristiwa_id WHERE e.ringkasan_id=${rid}`);
  ok(inapp === waliBerakun && Number(inapp) >= 2, `Satu InApp per wali berakun yang berhak (InApp=${inapp}, penerima=${waliBerakun})`);
  const outbox = sql(`SELECT CONCAT(judul,' ',isi,' ',COALESCE(data_json,'')) FROM notifikasi_outbox WHERE event_key LIKE 'v5:kesehatan:ringkasan:${rid}:%'`);
  ok(!outbox.includes(tanda) && !outbox.includes(namaSantri), 'Isi notifikasi generik (tanpa nama santri/isi ringkasan)');

  // ======================================================================= D
  console.log('\n=== D. Wali ===');
  const wali = await halamanApp('sbx_v5_wali_1');
  await wali.goto(app + '/');
  await tombol(wali, 'Kesehatan anak').click();
  await wali.getByText(namaSantri).first().waitFor();
  await tangkap(wali, 'app-wali-daftar-375');
  await wali.goto(app + '/notifikasi');
  await wali.getByText('Informasi kesehatan', { exact: false }).first().click();
  await tombol(wali, 'Buka informasi kesehatan').click();
  await wali.waitForURL(u => u.pathname === `/kesehatan/wali/${rid}`);
  await wali.getByText(`${tanda}-RINGKASAN`, { exact: false }).waitFor();
  const tWali = await teks(wali);
  await tangkap(wali, 'app-wali-detail-375');
  await tanpaGulirSamping(wali, 'Detail ringkasan wali');
  ok(!tWali.includes(`${tanda}-CATATAN`) && !tWali.includes(`${tanda}-OBAT`) && !tWali.includes(`${tanda}-KELUHAN`), 'Layar wali tanpa keluhan/catatan klinis/obat');
  const ww = await webMasuk('sbx_v5_wali_1');
  await ww.goto(`${web}/portal/kesehatan_wali.php?id=${rid}`);
  const tWaliWeb = await teks(ww);
  for (const s of ['RINGKASAN Ananda sempat demam dan sudah ditangani.', 'PENANGANAN istirahat', 'PERHATIAN kontrol pekan depan']) {
    ok(tWali.includes(`${tanda}-${s}`) && tWaliWeb.includes(`${tanda}-${s}`), `Paritas wali aplikasi–web: "${s}"`);
  }
  for (const l of ['Tanggal kunjungan klinik', 'Penanganan di klinik', 'Yang perlu diperhatikan orang tua']) ok(tWali.toLowerCase().includes(l.toLowerCase()) && tWaliWeb.toLowerCase().includes(l.toLowerCase()), `Label sama di aplikasi dan web (tanpa membedakan kapital CSS): ${l}`);
  // Wali lain membuka ID yang sama.
  const lainW = await halamanApp('sbx_v5_wali_lain');
  const tolak = lainW.waitForResponse(r => r.url().includes(`/wali/ringkasan/${rid}`));
  await lainW.goto(`${app}/kesehatan/wali/${rid}`);
  ok((await tolak).status() === 403, 'Wali lain membuka ringkasan anak lain → 403');
  await lainW.getByText('Data belum dapat ditampilkan').waitFor();
  ok(!(await teks(lainW)).includes(tanda), 'Wali lain tidak melihat isi apa pun');
  // Relasi dicabut saat layar terbuka (token lama).
  const cabut = await halamanApp('sbx_v5_wali_cabut');
  await cabut.goto(`${app}/kesehatan/wali/${rid}`);
  await cabut.getByText(`${tanda}-RINGKASAN`, { exact: false }).waitFor();
  const waliCabut = sql("SELECT id FROM wali WHERE nama='SBX V5 Wali Cabut'");
  sql(`UPDATE santri_wali SET archived_at=NOW() WHERE wali_id=${waliCabut} AND santri_id=${sW}`);
  const sesudahCabut = cabut.waitForResponse(r => r.url().includes(`/wali/ringkasan/${rid}`));
  await tombol(cabut, 'Muat ulang').click();
  ok((await sesudahCabut).status() === 403, 'Relasi dicabut → token lama 403 pada muat ulang');
  await cabut.getByText('Data belum dapat ditampilkan').waitFor();
  ok(!(await teks(cabut)).includes(tanda), 'Isi lama dibersihkan dari layar sesudah 403');
  // Tarik oleh petugas: wali melihat keterangan "ditarik" tanpa isi.
  await p.goto(`${app}/kesehatan/ringkasan?id=${kid}`);
  await isi(p, 'Alasan penarikan (wajib)', 'Informasi perlu diperbaiki');
  await tombol(p, 'Tarik ringkasan').click();
  await p.getByText('Ringkasan ditarik.', { exact: false }).waitFor();
  await tombol(wali, 'Muat ulang').click();
  await wali.getByText('Informasi ini telah ditarik oleh pesantren.').waitFor();
  ok(!(await teks(wali)).includes(`${tanda}-RINGKASAN`), 'Sesudah ditarik, wali hanya melihat keterangan tanpa isi');
  ok(sql(`SELECT COUNT(*) FROM notifikasi_outbox WHERE event_key LIKE 'v5:kesehatan:ringkasan:${rid}:%' AND kanal='InApp'`) === inapp, 'Penarikan tidak membuat notifikasi baru');

  // ======================================================================= E
  console.log('\n=== E. Pembimbing dan murobi ===');
  const pb = await halamanApp('sbx_v5_pembimbing');
  await pb.goto(`${app}/kesehatan`);
  await tombol(pb, 'Riwayat kesehatan santri binaan').click();
  await pb.getByText(namaSantri).first().waitFor();
  await pb.goto(`${app}/kesehatan/kunjungan/${kid}`);
  await pb.getByText(`${tanda}-KELUHAN`, { exact: false }).first().waitFor();
  const tPb = await teks(pb);
  ok(!tPb.includes('Koreksi catatan') && !tPb.includes('Catat hasil rujukan') && !tPb.includes('Riwayat versi'), 'Pembimbing terkait: detail hanya baca tanpa tombol mutasi/riwayat versi');
  await tangkap(pb, 'app-pembimbing-detail-375');
  const pbTulis = await api('sbx_v5_pembimbing', 'POST', `/v5/kesehatan/kunjungan/${kid}/koreksi`, { ...keadaan, versi: 4, alasan: 'Percobaan pembimbing', idempotency_key: kunci('f4-pb') });
  ok(pbTulis.status === 403, `Pembimbing mutasi lewat API → ${pbTulis.status}`);
  for (const u of ['sbx_v5_pembimbing_b', 'sbx_v5_murobi']) {
    const x = await halamanApp(u);
    const r = x.waitForResponse(res => res.url().endsWith(`/v5/kesehatan/kunjungan/${kid}`));
    await x.goto(`${app}/kesehatan/kunjungan/${kid}`);
    ok((await r).status() === 403, `${u} (di luar cakupan) membuka detail → 403`);
    ok(!(await teks(x)).includes(tanda), `${u}: tanpa isi`);
    const l = (await api(u, 'GET', `/v5/kesehatan/kunjungan?q=V5S0201`)).json.data;
    ok(l.rows.every(rw => rw.id !== kid), `${u}: daftar tidak memuat kunjungan di luar cakupan`);
  }

  // ======================================================================= F
  console.log('\n=== F. Admin ===');
  const ad = await halamanApp('sbx_admin');
  await ad.goto(`${app}/kesehatan`);
  await tombol(ad, 'Penugasan petugas kesehatan').waitFor();
  ok(!(await teks(ad)).includes('Catat kunjungan'), 'Admin tidak ditawari Catat kunjungan');
  await ad.goto(`${app}/kesehatan/kunjungan/${kid}`);
  await tombol(ad, 'Riwayat akses').click();
  await ad.getByText('kanal api', { exact: false }).first().waitFor();
  ok(true, 'Admin membaca riwayat akses yang memuat pembukaan dari aplikasi (kanal api)');
  await tangkap(ad, 'app-admin-akses-375');
  const adTulis = await api('sbx_admin', 'POST', `/v5/kesehatan/kunjungan/${kid}/ringkasan/tarik`, { revisi_ringkasan: 2, alasan: 'Percobaan admin', idempotency_key: kunci('f4-adm') });
  ok(adTulis.status === 403, `Admin mutasi klinis lewat API → ${adTulis.status}`);
  // Penugasan: tugaskan sbx_v5_petugas (belum bertugas).
  ok((await api('sbx_v5_petugas', 'GET', '/v5/kesehatan/akses')).json.data.catat === false, 'sbx_v5_petugas belum dapat mencatat');
  const calon = await halamanApp('sbx_v5_petugas');
  await ad.goto(`${app}/kesehatan/penugasan`);
  await tombol(ad, 'Tambah petugas kesehatan').click();
  const namaPengurus = sql("SELECT p.nama FROM pengurus p JOIN users u ON u.pengurus_id=p.id WHERE u.username='sbx_v5_petugas'");
  await tombol(ad, 'Pengurus').click();
  await ad.getByRole('textbox', { name: 'Cari pengurus', exact: true }).fill(namaPengurus);
  await ad.getByRole('button', { name: new RegExp('^' + namaPengurus) }).first().click();
  await tangkap(ad, 'app-admin-penugasan-375');
  await tombol(ad, 'Simpan penugasan').click();
  await ad.getByText(`Penugasan ${namaPengurus} tersimpan`, { exact: false }).waitFor();
  ok((await api('sbx_v5_petugas', 'GET', '/v5/kesehatan/akses')).json.data.catat === true, 'Penugasan dari aplikasi → token petugas yang sudah masuk kini dapat mencatat');
  await calon.goto(`${app}/kesehatan`);
  await tombol(calon, 'Catat kunjungan').waitFor();
  ok(true, 'Aplikasi petugas baru menampilkan Catat kunjungan');
  // Nonaktifkan sbx_v5_petugas_g saat formulir terbuka.
  const g = await halamanApp('sbx_v5_petugas_g');
  await g.goto(`${app}/kesehatan/catat`);
  await isi(g, 'Cari nama atau NIS santri aktif', 'V5S0201');
  await tombol(g, 'Cari santri').click();
  await tombol(g, `${namaSantri} · V5S0201`).click();
  await isi(g, 'Keluhan (wajib)', `${tanda}-G dicabut`);
  const namaG = sql("SELECT p.nama FROM pengurus p JOIN users u ON u.pengurus_id=p.id WHERE u.username='sbx_v5_petugas_g'");
  await ad.goto(`${app}/kesehatan/penugasan`);
  const kartuG = ad.locator('div').filter({ hasText: new RegExp('^' + namaG) }).filter({ has: ad.getByRole('button', { name: 'Nonaktifkan', exact: true }) }).last();
  await kartuG.getByRole('button', { name: 'Nonaktifkan', exact: true }).click();
  await ad.getByRole('textbox', { name: 'Alasan (wajib)', exact: true }).fill('Petugas dipindah tugas');
  await ad.getByRole('button', { name: 'Nonaktifkan penugasan', exact: true }).click();
  await ad.getByText(`Penugasan ${namaG} kini dinonaktifkan`, { exact: false }).waitFor();
  const gPost = g.waitForResponse(r => r.url().endsWith('/v5/kesehatan/kunjungan') && r.request().method() === 'POST');
  await tombol(g, 'Simpan sebagai tercatat').click();
  ok((await gPost).status() === 403, 'Penugasan dinonaktifkan saat formulir terbuka → simpan 403');
  ok(sql(`SELECT COUNT(*) FROM kesehatan_kunjungan WHERE keluhan LIKE '${tanda}-G%'`) === '0', 'Tidak ada kunjungan tersimpan sesudah penugasan dicabut');
  await g.getByText('Informasi tidak dapat diakses', { exact: false }).waitFor();
  ok(true, 'Aplikasi menampilkan pesan akses ditolak');
  const pid = Number(sql("SELECT a.id FROM kesehatan_assignments a JOIN users u ON u.pengurus_id=a.pengurus_id WHERE u.username='sbx_v5_petugas_g' ORDER BY a.id DESC LIMIT 1"));
  ok((await api('sbx_v5_petugas_f', 'POST', `/v5/kesehatan/penugasan/${pid}/aktifkan`, { alasan: 'Percobaan petugas' })).status === 403, 'Petugas (bukan admin) mengubah penugasan lewat API → 403');
  const aktif = await api('sbx_admin', 'POST', `/v5/kesehatan/penugasan/${pid}/aktifkan`, { alasan: 'Kembali bertugas' });
  ok(aktif.status === 200 && aktif.json.data.penugasan.status === 'Aktif', 'Admin mengaktifkan kembali lewat API');
  ok(sql(`SELECT COUNT(*) FROM audit_logs WHERE action IN ('penugasan.buat','penugasan.nonaktifkan','penugasan.aktifkan') AND entity_type='kesehatan_assignment' AND created_at >= NOW() - INTERVAL 30 MINUTE`) >= '3', 'Mutasi penugasan dari aplikasi tercatat di audit');

  // ======================================================================= G
  console.log('\n=== G. Sesi, penyimpanan, konsol ===');
  sql("UPDATE api_tokens t JOIN users u ON u.id=t.user_id SET t.expires_at=NOW() - INTERVAL 1 MINUTE WHERE u.username='sbx_v5_pembimbing' AND t.name<>'uji-v5-f4'");
  await pb.goto(`${app}/kesehatan/kunjungan/${kid}`);
  await pb.getByPlaceholder('Masukkan username').waitFor({ timeout: 15000 });
  ok(!(await teks(pb)).includes(tanda), 'Token kedaluwarsa → kembali ke layar masuk tanpa isi');
  await pb.getByPlaceholder('Masukkan username').fill('sbx_v5_pembimbing');
  await pb.getByPlaceholder('Masukkan password').fill(PASS);
  await tombol(pb, 'Masuk').click();
  await pb.waitForURL(u => u.pathname !== '/login');
  await pb.goto(`${app}/kesehatan/kunjungan/${kid}`);
  await pb.getByText(`${tanda}-KELUHAN`, { exact: false }).first().waitFor();
  ok(true, 'Masuk ulang → hak diperiksa server lagi dan rincian terbuka');
  const anon = await halamanApp(null);
  await anon.goto(`${app}/kesehatan/wali/${rid}`);
  await anon.waitForTimeout(1500);
  ok(await anon.getByPlaceholder('Masukkan username').count() > 0 && !(await teks(anon)).includes(tanda), 'Tanpa sesi: rute kesehatan meminta masuk, tanpa isi');
  for (const [nama, hal] of [['petugas', p], ['wali', wali], ['admin', ad], ['pembimbing', pb]]) {
    const simpan = await hal.evaluate(() => JSON.stringify({ l: { ...localStorage }, s: { ...sessionStorage } }));
    ok(!simpan.includes(tanda) && !simpan.includes(namaSantri), `Penyimpanan browser ${nama} tanpa isi kesehatan/nama santri`);
  }
  const semuaKonsol = konsol.join('\n');
  ok(!semuaKonsol.includes(tanda) && !semuaKonsol.includes(namaSantri) && !Object.values(tokens).some(t => semuaKonsol.includes(t)) && !/Sandbox#123/.test(semuaKonsol), `Konsol aplikasi (${konsol.length} baris) tanpa isi klinis, nama santri, token, atau kata sandi`);
  ok(permintaanLuar.length === 0, `Tidak ada permintaan ke host di luar 127.0.0.1 (${[...new Set(permintaanLuar)].join(', ') || 'nol'})`);
  const logPhp = fs.existsSync(process.env.PHP_LOG || '') ? fs.readFileSync(process.env.PHP_LOG, 'utf8') : '';
  if (logPhp) ok(!logPhp.includes(tanda) && !/PHP (Fatal|Warning|Notice|Deprecated)/.test(logPhp), 'Log server PHP tanpa isi klinis dan tanpa galat PHP');
} catch (e) {
  for (const [u, hal] of semuaHalaman) await hal.screenshot({ path: path.join(out, `gagal-${u}.png`), fullPage: true }).catch(() => {});
  throw e;
} finally {
  await browser.close();
}
console.log(`\nHasil: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal ? 1 : 0);
