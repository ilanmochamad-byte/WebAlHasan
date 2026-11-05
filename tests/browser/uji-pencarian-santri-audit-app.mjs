import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Probe auditor F-1 (Expo WEB + REST nyata), fixture sintetis.
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
  const K = 'sbx_pengurus_biasa';
  const base='/keamanan/kehadiran?date_from=2020-01-01&date_to=2030-01-01';
  // Tambah 30 pertemuan sintetis agar pagination benar-benar dilalui.
  const src=sql('SELECT pertemuan_id,santri_id FROM absensi_santri ORDER BY id LIMIT 1').split('\t');
  for(let i=1;i<=30;i++) {
    const date='2027-01-'+String(i).padStart(2,'0');
    sql(`INSERT IGNORE INTO pertemuan_pengajian(jadwal_id,tanggal_pertemuan,status,created_by)
      SELECT jadwal_id,'${date}','Selesai',created_by FROM pertemuan_pengajian WHERE id=${Number(src[0])}`);
    const mid=Number(sql(`SELECT id FROM pertemuan_pengajian WHERE tanggal_pertemuan='${date}' ORDER BY id DESC LIMIT 1`));
    sql(`INSERT IGNORE INTO pertemuan_peserta(pertemuan_id,santri_id,nis_snapshot,nama_santri_snapshot,kelas_id_snapshot,tahun_ajaran_id_snapshot)
      SELECT ${mid},santri_id,nis_snapshot,nama_santri_snapshot,kelas_id_snapshot,tahun_ajaran_id_snapshot FROM pertemuan_peserta WHERE pertemuan_id=${Number(src[0])} AND santri_id=${Number(src[1])}`);
    sql(`INSERT IGNORE INTO absensi_santri(pertemuan_id,santri_id,status,dicatat_pada,dicatat_oleh)
      SELECT ${mid},santri_id,status,dicatat_pada,dicatat_oleh FROM absensi_santri WHERE pertemuan_id=${Number(src[0])} AND santri_id=${Number(src[1])}`);
  }
  const all=[]; let page=1, total=Infinity;
  while(all.length<total) {
    const r=await api(K,base+'&per_page=100&page='+page++);
    if(r.status!==200) throw Error('API oracle gagal '+r.status);
    total=r.json.data.pagination.total;
    all.push(...r.json.data.items);
  }
  const target=all.at(-1);
  ok(all.length>25 && !all.slice(0,25).some(r=>r.subject_id===target.subject_id), 'Target santri di luar halaman pertama dari '+all.length+' catatan');
  const p=await halamanApp(K);
  await p.goto(app+'/keamanan/kehadiran');
  await p.getByLabel('Dari',{exact:true}).fill('2020-01-01');
  await p.getByLabel('Sampai',{exact:true}).fill('2030-01-01');
  await p.getByRole('button',{name:'Terapkan tanggal',exact:true}).click();
  await tunggu(p,'1/');
  await p.getByRole('button',{name:'Berikutnya',exact:true}).click();
  await tunggu(p,'2/');
  for(const term of [target.identity_number,target.subject_name.toLowerCase()]) {
    const expected=all.filter(r=>r.identity_number.toLowerCase().includes(term.toLowerCase()) || r.subject_name.toLowerCase().includes(term.toLowerCase()));
    await kolom(p).fill(term);
    const r=p.waitForResponse(r=>new URL(r.url()).pathname==='/api/v1/keamanan/kehadiran' && new URL(r.url()).searchParams.get('q')===term);
    await cariKlik(p).click(); const response=await r; const d=(await response.json()).data;
    await tunggu(p,target.subject_name);
    ok(d.pagination.current_page===1 && d.pagination.total===expected.length, 'Cari nama/NIS mengirim q, kembali halaman 1, total seluruh cakupan');
    ok(d.summary.detail_count===expected.length && d.items.every(r=>expected.some(e=>e.attendance_id===r.attendance_id)), 'Hitungan API dan ID hasil sesuai oracle seluruh halaman');
    ok((await teks(p)).includes(target.subject_name), 'Santri sasaran terlihat pada aplikasi');
  }
  await kolom(p).fill('tidak-ada-PCS-CODEX');
  await cariDanTunggu(p,'/api/v1/keamanan/kehadiran','tidak-ada-PCS-CODEX');
  await tunggu(p,'Belum ada catatan kehadiran');
  ok(true,'Tanpa hasil: keadaan kosong jelas');
  await kolom(p).fill(''); await cariDanTunggu(p,'/api/v1/keamanan/kehadiran','');
  await tunggu(p,`${all.length} catatan`);
  ok(true,'Hapus kata kunci mengembalikan hitungan lengkap');
  for(const query of ['q[]=x','q='+ 'x'.repeat(101)]) {
    ok((await api(K,base+'&'+query)).status===422,'REST menolak q tidak sah 422');
  }
  const legacy=await api('sbx_guru_biasa','/reports?date_from=2020-01-01&date_to=2030-01-01');
  const unknown=await api('sbx_guru_biasa','/reports?date_from=2020-01-01&date_to=2030-01-01&q=tidak-ada-PCS-CODEX');
  ok(JSON.stringify(legacy.json)===JSON.stringify(unknown.json),'REST laporan guru lama tetap mengabaikan q (kontrak tidak berubah)');
  await foto(p,'audit-kehadiran'); await lebar(p,'Kehadiran tercari');
  ok(luar.length===0,'Tidak ada permintaan keluar localhost');
} catch(e) { ok(false,'Galat probe: '+e.message); }
finally { await browser.close(); }
console.log(`Ringkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal?1:0);
