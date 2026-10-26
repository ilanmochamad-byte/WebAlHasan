import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Uji aplikasi PRD V6 Fase 5 — layar Laporan keuangan (Expo WEB dari repo alhasanApps + API/DB uji nyata).
//
//   V6F5_DB=test_v6_f5_app_test bash tests/v6_phase5_db.sh
//   DB_NAME=test_v6_f5_app_test php tests/v6_phase5_worker.php <aba> <id sbx_v6_bsa_b> fixture <id V6S0001> <id V6S0002> <id V6S0004>
//   DB_NAME=test_v6_f5_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8986 tests/redesain_web_tahap1_router.php &
//   (cd <salinan alhasanApps TANPA .env*> && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8986/api/v1 npx expo start --web --port 8099 --clear &)
//   (cd tests/browser && DB_NAME=test_v6_f5_app_test V6_WEB=http://127.0.0.1:8986 V6_APP=http://127.0.0.1:8099 OUT_DIR=… node uji-v6-fase5-app.mjs)
//
//   M-A  petugas: Laporan per periode = API (tagihan, terbayar, saldo, kas baru, selisih Rp0);
//        pilihan "Bulan lalu" meminta periode bulan lalu ke server; 375 px tanpa gulir samping;
//   M-B  admin: hanya membaca; daftar santri nonaktif bersaldo (R-10); jejak koreksi beralasan;
//   M-C  petugas unit Tsanawi: hanya santri Tsanawi;
//   M-D  wali dua anak: "Laporan keuangan anak" dua anak saja, tanpa kas, alasan, transfer
//        menunggu, atau referensi; ketuk anak → rincian keuangan anak;
//   M-E  relasi wali/penugasan dicabut saat layar terbuka → data lama ditutup sesudah muat ulang;
//   M-F  layar laporan tidak mengirim POST; local/sessionStorage dan konsol tanpa referensi/
//        token/nominal; nol permintaan keluar 127.0.0.1.
//
// Ini Expo WEB (Chromium 375×812), bukan perangkat native.
const web = process.env.V6_WEB, app = process.env.V6_APP, db = process.env.DB_NAME || '';
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) throw Error('Hanya localhost dan DB test_*_test');
const out = process.env.OUT_DIR || '/tmp/v6f5-tangkapan';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const tokens = {};
async function api(user, p) {
  if (!tokens[user]) {
    const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: PASS, device_name: 'uji-v6-f5-app' }) });
    tokens[user] = (await r.json()).data.token;
  }
  const r = await fetch(`${web}/api/v1${p}`, { headers: { Authorization: `Bearer ${tokens[user]}` } });
  return { status: r.status, json: await r.json().catch(() => null) };
}
const rp = n => `${n < 0 ? '−' : ''}Rp${String(Math.abs(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.')}`;
const bulan = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
const kini = new Date();
const B = bulan(kini), A = bulan(new Date(kini.getFullYear(), kini.getMonth() - 1, 1));
const nama = nis => sql(`SELECT nama_santri FROM santri WHERE nis='${nis}'`);
const [namaSmp, namaTs, namaKeluar] = ['V6S0001', 'V6S0002', 'V6S0004'].map(nama);
const sSmp = Number(sql("SELECT id FROM santri WHERE nis='V6S0001'"));
const sTs = Number(sql("SELECT id FROM santri WHERE nis='V6S0002'"));

const browser = await chromium.launch({ headless: true });
const konsol = [];
const permintaanLuar = [];
const postBsa = [];
const laporanDiminta = [];
async function halamanApp(user) {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 } });
  const page = await ctx.newPage();
  page.on('console', m => konsol.push(`${user}: ${m.text()}`));
  await page.route('**/*', async route => {
    const u = new URL(route.request().url());
    if (u.hostname !== '127.0.0.1') { permintaanLuar.push(u.origin); return route.abort(); }
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    if (route.request().method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    if (route.request().method() === 'POST' && u.pathname.startsWith('/api/v1/v6/')) postBsa.push({ user, path: u.pathname });
    if (u.pathname === '/api/v1/v6/bsa/laporan') laporanDiminta.push({ user, dari: u.searchParams.get('dari'), sampai: u.searchParams.get('sampai') });
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
const tombol = (p, name) => p.getByRole('button', { name, exact: true });
const tunggu = (p, t, timeout = 15000) => p.getByText(t, { exact: false }).first().waitFor({ timeout });
const lebar = async (p, label) => {
  const lebih = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  ok(lebih <= 1, `${label}: tanpa gulir horizontal pada 375 px (selisih ${lebih}px)`);
};
const foto = (p, n) => p.screenshot({ path: path.join(out, `app-laporan-${n}-375.png`), fullPage: true });
const penyimpanan = p => p.evaluate(() => JSON.stringify([Object.entries(localStorage), Object.entries(sessionStorage)]));
const bukaLaporan = async (p, label, ubin = 'Keuangan') => {
  await tombol(p, ubin).click();
  await tombol(p, label).click();
  await tunggu(p, 'Saldo akhir');
};

try {
  console.log('\n=== M-A. Petugas: laporan = API ===');
  const p = await halamanApp('sbx_v6_bsa_b');
  await bukaLaporan(p, 'Laporan per periode');
  const a = (await api('sbx_v6_bsa_b', `/v6/bsa/laporan?dari=${B}&sampai=${B}`)).json.data;
  let t = await teks(p);
  ok(t.includes(`Terbit (${a.tagihan.terbit.jumlah}) ${rp(a.tagihan.terbit.nominal)}`) && t.includes(`Terbayar ${rp(a.tagihan.terbayar)}`) && t.includes(`Saldo akhir ${rp(a.tabungan.saldo_akhir)}`)
    && t.includes(`Penerimaan kas baru ${rp(a.penerimaan_kas_baru)}`) && t.includes('Rekonsiliasi: selisih Rp0'),
    `Angka layar = API: tagihan ${rp(a.tagihan.terbit.nominal)}, terbayar ${rp(a.tagihan.terbayar)}, saldo akhir ${rp(a.tabungan.saldo_akhir)}, kas baru ${rp(a.penerimaan_kas_baru)}, selisih Rp0`);
  ok(t.includes(`Pembayaran tagihan dari tabungan (bukan kas) ${rp(a.internal.pembayaran_dari_tabungan)}`) && t.includes('Alasan: Uji rilis koreksi'),
    'Pembayaran dari tabungan tampil terpisah sebagai bukan kas; jejak koreksi beralasan');
  ok(t.includes(namaSmp) && t.includes(namaTs) && !t.includes(namaKeluar), 'Per santri: santri aktif dalam cakupan; santri keluar tidak tampil bagi petugas');
  await lebar(p, 'Laporan petugas');
  await foto(p, 'petugas');
  await p.getByRole('radio', { name: 'Bulan lalu' }).click();
  await tunggu(p, 'Terbit (0)');
  const terakhir = laporanDiminta.filter(x => x.user === 'sbx_v6_bsa_b').at(-1);
  ok(terakhir?.dari === A && terakhir?.sampai === A && (await teks(p)).includes('Terbit (0) Rp0'), `"Bulan lalu" meminta periode ${A} ke server; data bulan lalu kosong`);

  console.log('\n=== M-B. Admin: R-10 dan hanya membaca ===');
  const pa = await halamanApp('sbx_admin');
  await bukaLaporan(pa, 'Laporan per periode');
  t = await teks(pa);
  ok(t.includes('Santri nonaktif masih bersaldo/bertunggakan') && t.includes(namaKeluar) && t.includes('saldo Rp12.000'), 'Admin: santri keluar bersaldo Rp12.000 dilaporkan (R-10)');
  ok(!(await pa.getByRole('button', { name: /Catat|Terbitkan|Batalkan|Verifikasi/ }).count()), 'Laporan admin tanpa tombol mutasi');
  await lebar(pa, 'Laporan admin');
  await foto(pa, 'admin');

  console.log('\n=== M-C. Petugas unit Tsanawi ===');
  const pt = await halamanApp('sbx_v6_bsa_tsanawi');
  await bukaLaporan(pt, 'Laporan per periode');
  t = await teks(pt);
  const at = (await api('sbx_v6_bsa_tsanawi', '/v6/bsa/laporan')).json.data;
  ok(t.includes(namaTs) && !t.includes(namaSmp) && t.includes(`Saldo akhir ${rp(at.tabungan.saldo_akhir)}`) && t.includes('unit Tsanawi'), 'Petugas Tsanawi: hanya santri Tsanawi; angka = API');

  console.log('\n=== M-D. Wali dua anak ===');
  const pw = await halamanApp('sbx_v6_wali_dua');
  await bukaLaporan(pw, 'Laporan keuangan anak', 'Keuangan anak');
  t = await teks(pw);
  const aw = (await api('sbx_v6_wali_dua', '/v6/bsa/laporan')).json.data;
  ok(t.includes(namaSmp) && t.includes(namaTs) && !t.includes(namaKeluar) && t.includes(`Saldo akhir ${rp(aw.tabungan.saldo_akhir)}`) && t.includes('Per anak'),
    `Wali dua anak: dua anak saja, saldo akhir ${rp(aw.tabungan.saldo_akhir)} = API`);
  ok(!t.includes('Kas dan perpindahan internal') && !t.includes('Alasan:') && !t.includes('menunggu verifikasi') && !t.includes('TRF-RILIS') && !t.includes('Rekonsiliasi:'),
    'Wali: tanpa kas, alasan koreksi, transfer menunggu, referensi, atau status rekonsiliasi internal');
  await lebar(pw, 'Laporan wali');
  await foto(pw, 'wali');
  await pw.getByRole('button', { name: `Buka keuangan ${namaTs}` }).click();
  await tunggu(pw, 'Saldo tabungan');
  ok((await teks(pw)).includes(namaTs), 'Ketuk anak → rincian keuangan anak itu (dibaca ulang dari server)');

  console.log('\n=== M-E. Pencabutan saat layar terbuka ===');
  const pws = await halamanApp('sbx_v6_wali_smp');
  await bukaLaporan(pws, 'Laporan keuangan anak', 'Keuangan anak');
  ok((await teks(pws)).includes(namaSmp), 'Wali SMP: anaknya tampil');
  const relasi = sql(`SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id WHERE u.username = 'sbx_v6_wali_smp' AND sw.santri_id = ${sSmp} AND sw.archived_at IS NULL`);
  sql(`UPDATE santri_wali SET archived_at = NOW() WHERE id = ${relasi}`);
  await pws.getByRole('radio', { name: '3 bulan' }).click();
  await pws.waitForTimeout(1500);
  t = await teks(pws);
  ok(!t.includes(namaSmp) && !t.includes(rp(Number(sql(`SELECT saldo FROM bsa_tabungan_akun WHERE santri_id = ${sSmp}`)))),
    'Relasi dicabut lalu muat ulang: nama dan saldo anak hilang dari layar terbuka');
  sql(`UPDATE santri_wali SET archived_at = NULL WHERE id = ${relasi}`);
  const tugas = sql("SELECT a.id FROM bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id = a.pengurus_id WHERE u.username = 'sbx_v6_bsa_tsanawi' AND a.is_active = 1 ORDER BY a.id LIMIT 1");
  sql(`UPDATE bendahara_bulanan_assignments SET is_active = 0 WHERE id = ${tugas}`);
  await pt.getByRole('radio', { name: 'Tahun ini' }).click();
  await pt.waitForTimeout(1500);
  t = await teks(pt);
  ok(!t.includes(namaTs) && !t.includes('Saldo akhir') && /hanya untuk|akses/i.test(t), 'Penugasan dinonaktifkan: laporan petugas ditutup dengan pesan akses, tanpa data lama');
  sql(`UPDATE bendahara_bulanan_assignments SET is_active = 1 WHERE id = ${tugas}`);

  console.log('\n=== M-F. Hanya membaca, penyimpanan, jaringan ===');
  ok(postBsa.length === 0, `Layar laporan tidak mengirim POST /v6 (terhitung ${postBsa.length})`);
  const simpan = (await Promise.all([p, pa, pw, pws].map(penyimpanan))).join('');
  ok(!/TRF-RILIS|Uji rilis|Bearer|Rp\d|"saldo/.test(simpan), 'local/sessionStorage tanpa referensi, alasan, token, atau nominal');
  ok(!konsol.some(l => /TRF-RILIS|Bearer|Uji rilis|Rp\d/.test(l)), `Konsol tanpa referensi/token/nominal (${konsol.length} baris)`);
  ok(permintaanLuar.length === 0, `Nol permintaan keluar 127.0.0.1 (${[...new Set(permintaanLuar)].join(', ') || 'tidak ada'})`);
} catch (e) {
  ok(false, 'Galat skrip: ' + (e?.message ?? e));
} finally {
  await browser.close();
}
console.log(`\nRingkasan aplikasi Fase 5: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal ? 1 : 0);
