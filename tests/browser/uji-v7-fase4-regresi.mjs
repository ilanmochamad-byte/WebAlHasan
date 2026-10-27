import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Probe auditor Fase 4: aplikasi Expo web + API/DB fixture localhost nyata.
// DB_NAME, V7_WEB dan V7_APP wajib; tidak ada mock respons bisnis.
const web = process.env.V7_WEB, app = process.env.V7_APP, db = process.env.DB_NAME || '';
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) throw Error('Hanya localhost dan DB test_*_test');
const out = process.env.OUT_DIR || '/tmp/v7f4-tangkapan';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';
const PENANDA = 'RAHASIA-APP-V7F4';

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const tokens = {};
async function api(user, p, body) {
  if (!tokens[user]) {
    const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: PASS, device_name: 'uji-v7-f4-app' }) });
    tokens[user] = (await r.json()).data.token;
  }
  const r = await fetch(`${web}/api/v1${p}`, {
    method: body ? 'POST' : 'GET', headers: { Authorization: `Bearer ${tokens[user]}`, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined,
  });
  return { status: r.status, json: await r.json().catch(() => null) };
}
const kunci = p => `app-uji-${p}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
const sSatu = Number(sql("SELECT id FROM santri WHERE nis='V7S0001'"));
const sDua = Number(sql("SELECT id FROM santri WHERE nis='V7S0003'"));
const sCadangan = Number(sql("SELECT id FROM santri WHERE nis='V7S0008'"));

const browser = await chromium.launch({ headless: true });
const konsol = [];
const permintaanLuar = [];
const postV7 = [];
const jaringan = { putus: false };
async function halamanApp(user, opsi = {}) {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 } });
  const page = await ctx.newPage();
  page.on('console', m => konsol.push(`${user}: ${m.text()}`));
  await page.route('**/*', async route => {
    const u = new URL(route.request().url());
    if (u.hostname !== '127.0.0.1') { permintaanLuar.push(u.origin); return route.abort(); }
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    if (jaringan.putus) return route.abort('internetdisconnected');
    if (route.request().method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    if (route.request().method() === 'POST' && (u.pathname.startsWith('/api/v1/v7/') || /\/izin\/pengajuan\/\d+\/keputusan$/.test(u.pathname))) {
      postV7.push({ user, path: u.pathname, body: route.request().postDataJSON() });
    }
    if (opsi.buangJawaban?.(u, route.request())) {
      await route.fetch(); // server memproses, jawaban dibuang (jaringan putus sesudah kirim)
      return route.abort('connectionreset');
    }
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
const lebar = async (p, nama) => {
  const lebih = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  ok(lebih <= 1, `${nama}: tanpa gulir horizontal pada 375 px (selisih ${lebih}px)`);
};
const foto = (p, nama) => p.screenshot({ path: path.join(out, `app-v7-${nama}-375.png`), fullPage: true });
const penyimpanan = p => p.evaluate(() => JSON.stringify([Object.entries(localStorage), Object.entries(sessionStorage)]));
const statusDb = id => sql(`SELECT status FROM izin_pengajuan WHERE id=${id}`);
const bukaPintu = async p => {
  await p.goto(app + '/perizinan');
  await p.getByRole('button', { name: 'Buka izin orang tua melalui pembimbing' }).click();
};
const halamanSaya = [];

try {
  const w=await halamanApp('sbx_v7_wali_a');
  const made=await api('sbx_v7_wali_a','/v7/izin/wali/pengajuan',{santri_id:sSatu,tgl_izin:'2028-05-01',tgl_kembali:'2028-05-02',alasan:PENANDA+' admin pengganti',idempotency_key:kunci('admin')});
  const id=made.json.data.id;
  await api('sbx_v7_pembimbing_satu',`/v7/izin/pengajuan/${id}/tinjauan`,{tindakan:'teruskan',catatan:'',version:1,idempotency_key:kunci('teruskan')});
  const outsider=await halamanApp('sbx_murobi_b');await outsider.goto(app+`/izin-wali/${id}`);await tunggu(outsider,'Data belum dapat ditampilkan');
  ok(!(await teks(outsider)).includes(PENANDA),'Murobi luar cakupan ditolak setelah penerusan');await outsider.context().close();
  const ad=await halamanApp('sbx_admin');await ad.goto(app+`/izin-wali/${id}`);await tunggu(ad,'Buka di Perizinan');await tombol(ad,'Buka di Perizinan').click();await tunggu(ad,'Keputusan Admin Pengganti');
  await ad.getByPlaceholder('Minimal 3 karakter').fill('Keperluan wali terverifikasi');await tombol(ad,'Setujui').click();
  await tunggu(ad,'penggantian');ok(sql(`SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id=${id}`)==='0','Admin tanpa alasan penggantian tidak menghasilkan keputusan');
  await ad.getByPlaceholder('Wajib diisi').first().fill('Murobi berhalangan hadir');await tombol(ad,'Setujui').dblclick();await tunggu(ad,'Keputusan Disetujui tersimpan');
  const detail=(await api('sbx_v7_wali_a',`/v7/izin/pengajuan/${id}`)).json.data;
  ok(detail.pengajuan.id===id && detail.pengajuan.status==='Disetujui' && sql(`SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id=${id}`)==='1','Admin pengganti dari aplikasi: satu keputusan, ID/status sama dengan API');
  await w.goto(app+`/izin-wali/${id}`);await tunggu(w,'Keputusan akhir');ok((await teks(w)).includes('Keperluan wali terverifikasi'),'Wali pengaju membaca keputusan admin yang sama');
  // Jalur pengurus V2 dari aplikasi: buat → batal, serta buat → keputusan.
  const p=await halamanApp('sbx_v7_pembimbing_satu');
  async function buat(tgl){
    await p.goto(app+'/izin/buat?mode=pengurus');await tunggu(p,'SBX V7 Santri Satu');await p.getByRole('radio').filter({hasText:'SBX V7 Santri Satu'}).click();await tombol(p,'Lanjut isi data izin').click();
    await p.getByLabel('Mulai',{exact:true}).fill(tgl);await p.getByLabel('Kembali',{exact:true}).fill(tgl);await p.getByPlaceholder('Minimal 3 karakter').fill('Regresi jalur pengurus');await tombol(p,'Tinjau pengajuan').click();await tombol(p,'Kirim pengajuan').dblclick();await p.waitForURL(/\/izin\/\d+/);await tunggu(p,'Regresi jalur pengurus');return Number(new URL(p.url()).pathname.split('/').pop());
  }
  const idBatal=await buat('2028-06-01');await p.getByPlaceholder('Alasan pembatalan').fill('Kegiatan pengurus berubah');await tombol(p,'Batalkan pengajuan').click();await tunggu(p,'Pengajuan dibatalkan');ok(sql(`SELECT asal_pengajuan FROM izin_pengajuan WHERE id=${idBatal}`)==='Pengurus' && statusDb(idBatal)==='Dibatalkan','V2 aplikasi: pengurus membuat dan membatalkan tanpa tahap pembimbing wali');
  const idPutus=await buat('2028-06-02');
  const m=await halamanApp('sbx_murobi_a');await m.goto(app+`/izin/${idPutus}?mode=murobi`);await tunggu(m,'Keputusan murobi');await m.getByPlaceholder('Minimal 3 karakter').fill('Sesuai kegiatan pesantren');await tombol(m,'Setujui').dblclick();await tunggu(m,'Keputusan Disetujui tersimpan');ok(statusDb(idPutus)==='Disetujui' && sql(`SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id=${idPutus}`)==='1' && sql(`SELECT COUNT(*) FROM izin_pembimbing_tinjauan WHERE pengajuan_id=${idPutus}`)==='0','V2 aplikasi: keputusan langsung murobi satu kali tanpa tinjauan V7');
  // Wali pengaju kehilangan relasi saat formulir batal terbuka: server menolak dan data/draf ditutup.
  const made2=await api('sbx_v7_wali_a','/v7/izin/wali/pengajuan',{santri_id:sSatu,tgl_izin:'2028-07-01',tgl_kembali:'2028-07-02',alasan:PENANDA+' cabut wali',idempotency_key:kunci('cabut-wali')});const id2=made2.json.data.id;
  await w.goto(app+`/izin-wali/${id2}`);await tunggu(w,'Batalkan pengajuan');await w.getByLabel('Alasan pembatalan').fill('Draf batal rahasia');await tombol(w,'Batalkan pengajuan').click();
  const rel=sql(`SELECT GROUP_CONCAT(sw.id) FROM santri_wali sw JOIN users u ON u.wali_id=sw.wali_id WHERE u.username='sbx_v7_wali_a' AND sw.santri_id=${sSatu} AND sw.archived_at IS NULL`);sql(`UPDATE santri_wali SET archived_at=NOW() WHERE id IN (${rel})`);
  await tombol(w,'Ya, batalkan pengajuan').click();await tunggu(w,'Data belum dapat ditampilkan');ok(!(await teks(w)).includes(PENANDA) && !(await teks(w)).includes('Draf batal rahasia') && statusDb(id2)==='Menunggu Pembimbing','POST wali setelah relasi dicabut: isi dan draf tertutup, tanpa pembatalan');sql(`UPDATE santri_wali SET archived_at=NULL WHERE id IN (${rel})`);
} catch(e){gagal++;console.log('[gagal] '+e.stack);}
finally {sql("UPDATE santri_wali sw JOIN users u ON u.wali_id=sw.wali_id SET archived_at=NULL WHERE u.username='sbx_v7_wali_a'");await browser.close();}
console.log(`Ringkasan: ${lulus} lulus, ${gagal} gagal`);process.exit(gagal?1:0);
