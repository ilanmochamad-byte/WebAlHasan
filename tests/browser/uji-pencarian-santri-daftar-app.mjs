import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Uji aplikasi (Expo WEB, Chromium) — pencarian santri lewat server pada
// daftar berhalaman: izin & pelanggaran Keamanan, pelanggaran & kasus
// pembinaan (paket 5 November 2026). Bukan native.
//
//   PKF3_DB=test_pcs_daftar_test bash tests/penugasan_keamanan_f4_db.sh
//   PKF3_DB=test_pcs_daftar_test php tests/penugasan_keamanan_f3_seed.php > ids.json
//   DB_NAME=test_pcs_daftar_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8961 tests/redesain_web_tahap1_router.php &
//   (cd <salinan alhasanApps TANPA .env*> && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8961/api/v1 npx expo start --web --port 8098 --clear &)
//   (cd tests/browser && DB_NAME=test_pcs_daftar_test PCS_WEB=http://127.0.0.1:8961 PCS_APP=http://127.0.0.1:8098 SEED_JSON=ids.json OUT_DIR=… node uji-pencarian-santri-daftar-app.mjs)
//
//   D-1 izin Keamanan: NIS santri yang izinnya di halaman > 1 → `q` dikirim ke server, hasil = API, halaman 1 direset
//   D-2 izin Keamanan: kosongkan → daftar penuh; kegagalan jaringan → galat + Coba lagi; pencabutan → ditolak tanpa data
//   D-3 pelanggaran Keamanan: cari NIS (baru di server)
//   D-4 pembinaan murobi: pelanggaran/kasus cari NIS dan nama; tanpa hasil → pesan; santri di luar cakupan tidak muncul
//   D-5 375 px tanpa gulir horizontal; nol permintaan keluar 127.0.0.1
const web = process.env.PCS_WEB, app = process.env.PCS_APP, db = process.env.DB_NAME || '';
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) throw Error('Hanya localhost dan DB test_*_test');
const ids = JSON.parse(fs.readFileSync(process.env.SEED_JSON, 'utf8'));
const out = process.env.OUT_DIR || '/tmp/pcs-daftar';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const tokens = {};
async function api(user, p) {
  if (!tokens[user]) {
    const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: PASS, device_name: 'uji-pcs-daftar' }) });
    tokens[user] = (await r.json()).data.token;
  }
  const r = await fetch(`${web}/api/v1${p}`, { headers: { Authorization: `Bearer ${tokens[user]}` } });
  return { status: r.status, json: await r.json().catch(() => null) };
}

const permintaan = [], luar = [];
const jaringan = { gagalIzin: 0 };
const browser = await chromium.launch({ headless: true });
async function halamanApp(user) {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 } });
  const page = await ctx.newPage();
  page.on('dialog', d => d.accept());
  await page.route('**/*', async route => {
    const req = route.request();
    const u = new URL(req.url());
    if (u.hostname !== '127.0.0.1') { luar.push(u.origin); return route.abort(); }
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    const cors = { 'Access-Control-Allow-Origin': app };
    if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { ...cors, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    permintaan.push({ user, path: u.pathname, q: u.searchParams.get('q'), page: u.searchParams.get('page') });
    if (jaringan.gagalIzin > 0 && u.pathname === '/api/v1/keamanan/izin') {
      jaringan.gagalIzin--;
      return route.fulfill({ status: 503, headers: cors, contentType: 'application/json', body: JSON.stringify({ success: false, data: null, error: { code: 'SERVER_ERROR', message: 'Uji: server sibuk.' } }) });
    }
    const response = await route.fetch();
    await route.fulfill({ response, headers: { ...response.headers(), ...cors } });
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
const kolom = p => p.getByPlaceholder('Nama atau NIS santri');
const cariKlik = p => p.getByRole('button', { name: 'Cari', exact: true });
// Klik Cari lalu tunggu respons server dengan q yang sama (bukan teks lama di layar).
async function cariDanTunggu(p, jalur, q) {
  const resp = p.waitForResponse(r => { const u = new URL(r.url()); return u.pathname === jalur && (u.searchParams.get('q') ?? '') === q && r.request().method() === 'GET'; }, { timeout: 20000 });
  await cariKlik(p).click();
  await resp;
  await p.waitForTimeout(300);
}
const foto = (p, nama) => p.screenshot({ path: path.join(out, `app-pcs-daftar-${nama}-375.png`), fullPage: true });
const lebar = async (p, nama) => {
  const selisih = await p.evaluate(() => {
    const w = document.documentElement.clientWidth;
    const bilah = [...document.querySelectorAll('div')].filter(d => /^Al Hasan\s+Beranda/.test(d.innerText || '') && (d.innerText || '').includes('Profil')).pop() ?? null;
    let maks = w;
    for (const e of document.querySelectorAll('body *')) {
      if (bilah && bilah.contains(e)) continue;
      const r = e.getBoundingClientRect();
      if (r.width > 0) maks = Math.max(maks, r.right);
    }
    return Math.round(maks - w);
  });
  ok(selisih <= 1, `${nama}: konten tanpa gulir horizontal pada 375 px (selisih ${selisih}px)`);
};
const santri = id => sql(`SELECT nis, nama_santri FROM santri WHERE id=${Number(id)}`).split('\t');

try {
  console.log('\n=== D-1. Izin Keamanan: cari lewat server ===');
  const K = 'sbx_pengurus_biasa';
  const hal1 = (await api(K, '/keamanan/izin?page=1')).json.data;
  const sid1 = new Set(hal1.items.map(i => i.santri.id));
  let target = null;
  for (let p = 3; p <= 42 && !target; p++) {
    const d = (await api(K, `/keamanan/izin?page=${p}`)).json.data;
    target = d.items.find(i => !sid1.has(i.santri.id)) ?? null;
  }
  ok(target !== null, 'Ada izin santri yang hanya muncul di halaman ≥ 3 tanpa saringan');
  const [nisT, namaT] = santri(target.santri.id);
  const apiCari = (await api(K, `/keamanan/izin?q=${encodeURIComponent(nisT)}`)).json.data;
  const k = await halamanApp(K);
  await k.goto(app + '/keamanan/izin');
  await tunggu(k, 'pengajuan ·');
  ok(!(await teks(k)).includes(`NIS ${nisT}`), 'Santri target tidak tampil di halaman pertama tanpa saringan');
  // Pindah ke halaman 2 dulu: pencarian harus kembali ke halaman 1 dan menjangkau seluruh halaman.
  await k.getByRole('button', { name: 'Berikutnya', exact: true }).first().click();
  await k.waitForFunction(() => true);
  await k.waitForTimeout(800);
  ok(permintaan.filter(r => r.path === '/api/v1/keamanan/izin').at(-1).page === '2', 'Berpindah ke halaman 2 sebelum mencari');
  await kolom(k).fill(nisT);
  await cariDanTunggu(k, '/api/v1/keamanan/izin', nisT);
  await tunggu(k, `${apiCari.total} pengajuan`);
  const terakhir = permintaan.filter(r => r.path === '/api/v1/keamanan/izin').at(-1);
  ok(terakhir.q === nisT && terakhir.page === '1', 'Aplikasi mengirim q ke server dan kembali ke halaman 1');
  let t = await teks(k);
  ok(t.includes(`NIS ${nisT}`) && t.includes(namaT) && apiCari.items.every(i => t.includes(`#${i.id}`)) && apiCari.items.every(i => i.santri.id === target.santri.id || i.santri.nis.includes(nisT) || i.santri.nama.includes(nisT)),
    `Hasil = API (${apiCari.total} pengajuan), memuat santri dari halaman jauh`);
  await foto(k, 'izin-cari');
  await lebar(k, 'Izin Keamanan tercari');

  console.log('\n=== D-2. Kosongkan, jaringan gagal, pencabutan ===');
  await kolom(k).fill('');
  await cariKlik(k).click();
  await tunggu(k, `${hal1.ringkasan.total} pengajuan`);
  ok(permintaan.filter(r => r.path === '/api/v1/keamanan/izin').at(-1).q === null, 'Kata kunci kosong: tanpa q, daftar penuh kembali');
  jaringan.gagalIzin = 3; // klien mengulang GET 5xx dua kali
  await kolom(k).fill(namaT.toUpperCase());
  await cariKlik(k).click();
  await tunggu(k, 'Coba lagi');
  ok(await kolom(k).inputValue() === namaT.toUpperCase(), 'Galat jaringan: kata kunci tetap, tombol Coba lagi tampil');
  await k.getByRole('button', { name: 'Coba lagi' }).click();
  const apiNama = (await api(K, `/keamanan/izin?q=${encodeURIComponent(namaT.toUpperCase())}`)).json.data;
  await tunggu(k, `${apiNama.total} pengajuan`);
  ok((await teks(k)).includes(namaT), 'Coba lagi memuat hasil pencarian nama (huruf besar)');
  sql(`UPDATE keamanan_assignments SET is_active = 0 WHERE id = ${Number(ids.penugasan_k)}`);
  await kolom(k).fill(nisT);
  await cariKlik(k).click();
  await k.waitForTimeout(1500);
  t = await teks(k);
  ok(!t.includes(`NIS ${nisT}`) && /tidak|ditolak|akses/i.test(t), 'Penugasan dicabut: pencarian ditolak server, tanpa data santri');
  sql(`UPDATE keamanan_assignments SET is_active = 1 WHERE id = ${Number(ids.penugasan_k)}`);

  console.log('\n=== D-3. Pelanggaran Keamanan: cari NIS ===');
  const pel = (await api(K, '/keamanan/pelanggaran')).json.data;
  const sidPel = pel.items[0].santri_id;
  const [nisP, namaP] = santri(sidPel);
  await k.goto(app + '/keamanan/pelanggaran');
  await tunggu(k, `dari ${pel.total}`);
  await kolom(k).fill(nisP);
  await cariDanTunggu(k, '/api/v1/keamanan/pelanggaran', nisP);
  const harapP = pel.items.filter(i => i.santri_id === sidPel).length;
  await tunggu(k, `${harapP} dari ${harapP}`);
  t = await teks(k);
  ok(t.includes(namaP) && pel.items.filter(i => i.santri_id !== sidPel).every(i => !t.includes(i.santri_nama) || i.santri_nama === namaP), `Cari NIS pelanggaran: ${harapP} catatan santri itu saja`);

  console.log('\n=== D-4. Pembinaan murobi ===');
  const M = 'sbx_murobi_a';
  const m = await halamanApp(M);
  for (const [jenis, path] of [['pelanggaran', '/v3/pelanggaran'], ['kasus', '/v3/konseling/kasus']]) {
    const semua = (await api(M, path)).json.data;
    await m.goto(app + `/pembinaan/daftar?jenis=${jenis}`);
    await tunggu(m, 'Halaman 1');
    ok(await kolom(m).count() === 1, `Pembinaan ${jenis}: kolom cari tersedia`);
    if (semua.rows.length === 0) { console.log(`[info] tidak ada ${jenis} dalam cakupan murobi`); continue; }
    const sid = semua.rows[0].santri_id;
    const [nisM, namaM] = santri(sid);
    await kolom(m).fill(nisM);
    await cariDanTunggu(m, `/api/v1${path}`, nisM);
    const harap = semua.rows.filter(r => r.santri_id === sid).length;
    await tunggu(m, `${harap} hasil untuk pencarian`);
    ok(permintaan.filter(r => r.path === `/api/v1${path}`).at(-1).q === nisM, `Pembinaan ${jenis}: q NIS dikirim ke server`);
    t = await teks(m);
    ok((t.match(new RegExp(namaM.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g')) || []).length >= harap, `Pembinaan ${jenis}: ${harap} baris santri yang dicari`);
    await kolom(m).fill(namaM.slice(0, 5).toLowerCase());
    await cariDanTunggu(m, `/api/v1${path}`, namaM.slice(0, 5).toLowerCase());
    await tunggu(m, 'hasil untuk pencarian');
    ok((await teks(m)).includes(namaM), `Pembinaan ${jenis}: cari potongan nama huruf kecil`);
    await kolom(m).fill('zzz-tidak-ada');
    await cariDanTunggu(m, `/api/v1${path}`, 'zzz-tidak-ada');
    await tunggu(m, 'Tidak ada data yang cocok');
    ok(true, `Pembinaan ${jenis}: tanpa hasil → pesan jelas`);
  }
  // Santri yang hanya terlihat admin (di luar cakupan murobi) tidak muncul.
  const adminSid = new Set((await api('sbx_admin', '/v3/pelanggaran')).json.data.rows.map(r => r.santri_id));
  const murobiSid = new Set((await api(M, '/v3/pelanggaran')).json.data.rows.map(r => r.santri_id));
  const luarCakupan = [...adminSid].find(s => !murobiSid.has(s));
  if (luarCakupan) {
    const [nisL, namaL] = santri(luarCakupan);
    await m.goto(app + '/pembinaan/daftar?jenis=pelanggaran');
    await tunggu(m, 'Halaman 1');
    await kolom(m).fill(nisL);
    await cariDanTunggu(m, '/api/v1/v3/pelanggaran', nisL);
    await tunggu(m, '0 hasil untuk pencarian');
    ok(!(await teks(m)).includes(namaL), 'Murobi: NIS santri di luar cakupan → 0 hasil, nama tidak tampil');
  }
  await foto(m, 'pembinaan-cari');
  await lebar(m, 'Pembinaan tercari');

  console.log('\n=== D-5. Jaringan ===');
  ok(luar.length === 0, `Tanpa permintaan keluar 127.0.0.1 (${[...new Set(luar)].join(', ') || 'nol'})`);
} catch (e) {
  ok(false, `Galat tak terduga: ${e.message}`);
} finally {
  await browser.close();
}
console.log(`\nRingkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
