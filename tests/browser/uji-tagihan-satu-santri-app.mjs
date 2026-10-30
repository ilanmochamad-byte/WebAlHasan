import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Uji aplikasi "Terbitkan tagihan" satu santri dari tarif (Expo WEB dari repo
// alhasanApps + API/DB uji nyata). Layar baru `keuangan/terbit-santri` memakai
// pratinjau/penerbitan V6 dengan `santri_id` (perbaikan web PR #100).
//
//   V6F5_DB=test_v6_ss_app_test bash tests/v6_phase5_db.sh
//   DB_NAME=test_v6_ss_app_test php tests/v6_tagihan_satu_santri_seed.php
//   DB_NAME=test_v6_ss_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8986 tests/redesain_web_tahap1_router.php &
//   (cd <salinan alhasanApps TANPA .env*> && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8986/api/v1 npx expo start --web --port 8099 --clear &)
//   (cd tests/browser && DB_NAME=test_v6_ss_app_test SMP_LAIN=<id santri SMP lain> V6_WEB=http://127.0.0.1:8986 V6_APP=http://127.0.0.1:8099 OUT_DIR=… node uji-tagihan-satu-santri-app.mjs)
//
//   A-1  petugas: rincian santri → "Terbitkan tagihan" → rincian tarif = API
//        (Syahriah, Makan, potongan, total Rp300.000) tanpa menulis apa pun;
//   A-2  ketukan ganda "Terbitkan tagihan Rp300.000" → tepat satu tagihan,
//        santri SMP lain tidak ikut, layar membuka rincian tagihan baru;
//   A-3  buka ulang: "sudah ada" + "Buka tagihan" ke tagihan yang sama, tanpa tombol terbit;
//   A-4  santri tanpa kelas: penjelasan, tanpa tombol terbit, isian manual di website;
//   A-5  server lama (respons pratinjau tanpa `santri_id`, banyak baris) → layar
//        menolak, tanpa tombol terbit, nol POST penerbitan;
//   A-6  penugasan dicabut sesudah pratinjau → penerbitan ditolak server,
//        rincian ditutup, tanpa tagihan;
//   A-7  admin, wali, dan petugas unit lain: tanpa tombol di rincian santri;
//        alamat langsung ditolak tanpa POST;
//   A-8  375 px tanpa gulir samping; nol permintaan keluar 127.0.0.1;
//        penyimpanan dan konsol tanpa token.
//
// Ini Expo WEB (Chromium 375×812), bukan perangkat native.
const web = process.env.V6_WEB, app = process.env.V6_APP, db = process.env.DB_NAME || '';
const smpLain = Number(process.env.SMP_LAIN || 0);
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db) || !(smpLain > 0)) throw Error('Hanya localhost, DB test_*_test, dan SMP_LAIN');
const out = process.env.OUT_DIR || '/tmp/v6ss-app-tangkapan';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const tokens = {};
async function api(user, p, body) {
  if (!tokens[user]) {
    const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: PASS, device_name: 'uji-v6ss-app' }) });
    tokens[user] = (await r.json()).data.token;
  }
  const r = await fetch(`${web}/api/v1${p}`, body ? { method: 'POST', headers: { Authorization: `Bearer ${tokens[user]}`, 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
    : { headers: { Authorization: `Bearer ${tokens[user]}` } });
  return { status: r.status, json: await r.json().catch(() => null) };
}
const rp = n => `Rp${String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.')}`;
const kini = new Date();
const B = `${kini.getFullYear()}-${String(kini.getMonth() + 1).padStart(2, '0')}`;
const sid = nis => Number(sql(`SELECT id FROM santri WHERE nis='${nis}'`));
const [sSmp, sTs, sTanpaKelas] = ['V6S0001', 'V6S0002', 'V6S0003'].map(sid);
const tagihan = (s, periode = B) => Number(sql(`SELECT COUNT(*) FROM bsa_tagihan WHERE santri_id=${s} AND periode='${periode}'`));
const semuaTagihan = () => Number(sql(`SELECT COUNT(*) FROM bsa_tagihan WHERE periode='${B}'`));
const penugasanB = Number(sql("SELECT a.id FROM bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id=a.pengurus_id WHERE u.username='sbx_v6_bsa_b'"));

const browser = await chromium.launch({ headless: true });
const konsol = [];
const permintaanLuar = [];
const postTerbit = [];
let serverLama = false;
async function halamanApp(user) {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 } });
  const page = await ctx.newPage();
  page.on('console', m => konsol.push(`${user}: ${m.text()}`));
  await page.route('**/*', async route => {
    const u = new URL(route.request().url());
    if (u.hostname !== '127.0.0.1') { permintaanLuar.push(u.origin); return route.abort(); }
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    if (route.request().method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type,idempotency-key', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    if (route.request().method() === 'POST' && u.pathname === '/api/v1/v6/bsa/penerbitan') postTerbit.push({ user, body: route.request().postDataJSON() });
    const response = await route.fetch();
    if (serverLama && u.pathname === '/api/v1/v6/bsa/penerbitan/pratinjau') {
      // Server sebelum PR #100 mengabaikan santri_id: tanpa `santri_id` di respons, seluruh unit.
      const j = await response.json();
      delete j.data.santri_id;
      j.data.rows = [...j.data.rows, { ...j.data.rows[0], santri_id: smpLain, nama: 'Santri lain' }];
      return route.fulfill({ status: 200, contentType: 'application/json', headers: { 'Access-Control-Allow-Origin': app }, body: JSON.stringify(j) });
    }
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
const foto = (p, n) => p.screenshot({ path: path.join(out, `app-terbit-santri-${n}-375.png`), fullPage: true });
const bukaTerbit = async (p, s) => {
  await p.goto(app + `/keuangan/terbit-santri?santri_id=${s}`);
  await tombol(p, 'Tampilkan tagihan').waitFor({ timeout: 15000 });
};
const tampilkan = async p => { await tombol(p, 'Tampilkan tagihan').click(); await tunggu(p, 'Tagihan '); };

try {
  console.log('\n=== A-1. Petugas: rincian tarif = API ===');
  const p = await halamanApp('sbx_v6_bsa_b');
  await p.goto(app + `/keuangan/santri/${sSmp}`);
  await tombol(p, 'Terbitkan tagihan').waitFor({ timeout: 15000 });
  ok(true, 'Rincian santri (petugas): tombol "Terbitkan tagihan" tampil');
  await tombol(p, 'Terbitkan tagihan').click();
  await p.waitForURL(u => u.pathname === '/keuangan/terbit-santri' && u.searchParams.get('santri_id') === String(sSmp));
  await tombol(p, 'Tampilkan tagihan').waitFor({ timeout: 15000 });
  await tampilkan(p);
  await tunggu(p, 'Total tagihan');
  const a = (await api('sbx_v6_bsa_b', '/v6/bsa/penerbitan/pratinjau', { periode: B, jatuh_tempo: `${B}-10`, unit: '', santri_id: sSmp })).json.data;
  let t = await teks(p);
  ok(a.santri_id === sSmp && a.rows.length === 1 && a.rows[0].total === 300000, `API pratinjau satu santri: satu baris, total ${rp(a.rows[0]?.total ?? 0)}`);
  ok(t.includes('Syahriah (tarif unit SMP) Rp150.000') && t.includes('Makan (tarif unit SMP) Rp200.000') && t.includes('Potongan · Potongan anak guru −Rp50.000')
    && t.includes('Total tagihan Rp300.000'), 'Layar = API: Syahriah Rp150.000, Makan Rp200.000, potongan −Rp50.000, total Rp300.000');
  ok(!t.match(/Nama komponen|Tambah komponen/i), 'Tanpa isian komponen/nominal manual di aplikasi');
  ok(postTerbit.length === 0 && semuaTagihan() === 0, 'Pratinjau tidak menulis: nol POST penerbitan, nol tagihan');
  await lebar(p, 'Rincian tarif');
  await foto(p, 'pratinjau');

  console.log('\n=== A-2. Ketukan ganda → satu tagihan ===');
  await tombol(p, 'Terbitkan tagihan Rp300.000').dblclick();
  await p.waitForURL(u => /^\/keuangan\/tagihan\/\d+$/.test(u.pathname), { timeout: 15000 });
  const tid = Number(sql(`SELECT id FROM bsa_tagihan WHERE santri_id=${sSmp} AND periode='${B}'`));
  ok(tagihan(sSmp) === 1 && semuaTagihan() === 1 && tagihan(smpLain) === 0 && Number(sql(`SELECT total FROM bsa_tagihan WHERE id=${tid}`)) === 300000,
    'Tepat satu tagihan Rp300.000 untuk santri ini; santri SMP lain tidak ikut');
  ok(new URL(p.url()).pathname === `/keuangan/tagihan/${tid}`, 'Sesudah terbit layar membuka rincian tagihan baru');
  ok(postTerbit.every(x => x.body.santri_id === sSmp && /^[0-9a-f]{64}$/.test(x.body.pratinjau_hash)) && new Set(postTerbit.map(x => JSON.stringify(x.body))).size === 1,
    `Kiriman penerbitan membawa santri_id dan hash pratinjau (${postTerbit.length} kiriman, isi sama)`);
  ok(Number(sql(`SELECT jumlah_sasaran FROM bsa_penerbitan ORDER BY id DESC LIMIT 1`)) === 1, 'Catatan penerbitan: sasaran 1');
  await tunggu(p, 'Rp300.000');
  await foto(p, 'terbit');

  console.log('\n=== A-3. Buka ulang: sudah ada ===');
  await bukaTerbit(p, sSmp);
  await tampilkan(p);
  await tunggu(p, 'sudah ada');
  t = await teks(p);
  ok(!(await p.getByRole('button', { name: /^Terbitkan tagihan Rp/ }).count()) && t.includes('koreksi tagihan'), 'Tagihan bulan ini sudah ada: tanpa tombol terbit, arahan koreksi');
  await tombol(p, 'Buka tagihan').click();
  await p.waitForURL(u => u.pathname === `/keuangan/tagihan/${tid}`);
  ok(true, '"Buka tagihan" membuka tagihan yang sama');

  console.log('\n=== A-4. Santri tanpa kelas ===');
  await bukaTerbit(p, sTanpaKelas);
  await tampilkan(p);
  await tunggu(p, 'Tidak ada tagihan dari tarif');
  t = await teks(p);
  ok(t.includes('belum ditempatkan di kelas') && t.includes('dilakukan di website') && !(await p.getByRole('button', { name: /^Terbitkan tagihan Rp/ }).count()),
    'Santri tanpa kelas: penjelasan, arahan ke website, tanpa tombol terbit');
  ok(tagihan(sTanpaKelas) === 0, 'Tanpa tagihan untuk santri tanpa kelas');
  await lebar(p, 'Santri tanpa kelas');

  console.log('\n=== A-5. Server lama mengabaikan santri_id ===');
  serverLama = true;
  const sebelum = postTerbit.length;
  await bukaTerbit(p, smpLain);
  await tampilkan(p).catch(() => {});
  await tunggu(p, 'Server belum mendukung penerbitan satu santri');
  ok(!(await p.getByRole('button', { name: /^Terbitkan tagihan Rp/ }).count()) && !(await teks(p)).includes('Total tagihan'), 'Respons tanpa santri_id/banyak baris: ditolak, tanpa rincian dan tombol terbit');
  ok(postTerbit.length === sebelum && tagihan(smpLain) === 0, 'Nol POST penerbitan, tanpa tagihan');
  await foto(p, 'server-lama');
  serverLama = false;

  console.log('\n=== A-6. Penugasan dicabut sesudah pratinjau ===');
  await bukaTerbit(p, smpLain);
  await tampilkan(p);
  await tombol(p, 'Terbitkan tagihan Rp350.000').waitFor({ timeout: 15000 });
  sql(`UPDATE bendahara_bulanan_assignments SET is_active=0 WHERE id=${penugasanB}`);
  try {
    await tombol(p, 'Terbitkan tagihan Rp350.000').click();
    await tunggu(p, 'tidak dapat diakses');
    t = await teks(p);
    ok(!t.includes('Total tagihan') && !(await p.getByRole('button', { name: /^Terbitkan tagihan Rp/ }).count()), 'Ditolak server: rincian tarif ditutup, tanpa tombol terbit');
    ok(tagihan(smpLain) === 0, 'Tanpa tagihan sesudah penugasan dicabut');
  } finally {
    sql(`UPDATE bendahara_bulanan_assignments SET is_active=1 WHERE id=${penugasanB}`);
  }

  console.log('\n=== A-7. Admin, wali, petugas unit lain ===');
  for (const [user, s, label] of [['sbx_admin', sSmp, 'Admin'], ['sbx_v6_wali_smp', sSmp, 'Wali'], ['sbx_v6_bsa_tsanawi', sTs, 'Petugas Tsanawi (santri sendiri)']]) {
    const q = await halamanApp(user);
    await q.goto(app + `/keuangan/santri/${s}`);
    await tunggu(q, s === sSmp ? 'V6S0001' : 'V6S0002');
    const ada = await tombol(q, 'Terbitkan tagihan').count();
    ok(label.startsWith('Petugas') ? ada === 1 : ada === 0, `${label}: tombol "Terbitkan tagihan" ${label.startsWith('Petugas') ? 'tampil untuk santri dalam unitnya' : 'tidak tampil'}`);
    if (!label.startsWith('Petugas')) {
      const n = postTerbit.length;
      await q.goto(app + `/keuangan/terbit-santri?santri_id=${s}`);
      await tunggu(q, 'tidak dapat diakses');
      ok(!(await tombol(q, 'Tampilkan tagihan').count()) && postTerbit.length === n, `${label}: alamat langsung ditolak tanpa formulir dan tanpa POST`);
    }
    await q.context().close();
  }
  const ts = await halamanApp('sbx_v6_bsa_tsanawi');
  await ts.goto(app + `/keuangan/terbit-santri?santri_id=${sSmp}`);
  await tunggu(ts, 'Data mungkin sudah dihapus');
  ok(!(await tombol(ts, 'Tampilkan tagihan').count()), 'Petugas Tsanawi: santri SMP di luar cakupan → 404, tanpa formulir');
  await ts.context().close();

  console.log('\n=== A-8. Privasi ===');
  const simpan = await p.evaluate(() => JSON.stringify([Object.entries(localStorage), Object.entries(sessionStorage)]));
  ok(!Object.values(tokens).some(tk => simpan.includes(tk) || konsol.some(k => k.includes(tk))), 'Penyimpanan web dan konsol tanpa token API');
  ok(permintaanLuar.length === 0, `Nol permintaan keluar 127.0.0.1 (${[...new Set(permintaanLuar)].join(', ') || 'tidak ada'})`);
} catch (e) {
  ok(false, `Uji berhenti: ${e.message.split('\n')[0]}`);
} finally {
  await browser.close();
}
console.log(`\nRingkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal ? 1 : 0);
