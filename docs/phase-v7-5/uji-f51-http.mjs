import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from '../../tests/browser/node_modules/playwright/index.mjs';

// Probe lanjutan F5-1: formulir basi, laporan/ekspor/cetak, dan sesi aplikasi lama.
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

let assignment=0;
try {
  const day=new Date(Date.UTC(2076,0,1+Number(sql("SELECT COUNT(*)*3 FROM izin_pengajuan WHERE asal_pengajuan='Wali'")))).toISOString().slice(0,10);
  const made=await api('sbx_v7_wali_a','/v7/izin/wali/pengajuan',{santri_id:sCadangan,tgl_izin:day,tgl_kembali:day,alasan:PENANDA+' stale F51',idempotency_key:kunci('buat')});
  if(made.status!==201)throw Error('Fixture '+made.status);
  const id=made.json.data.id;
  const forwarded=await api('sbx_v7_pembimbing_satu',`/v7/izin/pengajuan/${id}/tinjauan`,{tindakan:'teruskan',version:1,idempotency_key:kunci('teruskan')});
  if(forwarded.status!==200)throw Error('Penerusan '+forwarded.status);
  const m=await halamanApp('sbx_murobi_a');await m.goto(app+`/izin/${id}?mode=murobi`);await tunggu(m,'Diajukan orang tua');
  await m.getByPlaceholder('Minimal 3 karakter').first().fill('Probe keputusan basi F51');
  const ctx=await browser.newContext();
  const request=ctx.request;
  const csrf=html=>html.match(/name="_csrf" value="([^"]+)"/)?.[1];
  const login=await request.get(web+'/portal/index.php');
  await request.post(web+'/admin/cek_login.php',{form:{_csrf:csrf(await login.text()),username:'sbx_murobi_a',password:PASS}});
  const detail=await request.get(web+`/portal/izin_detail.php?id=${id}&mode=murobi`);const html=await detail.text();
  const form=html.match(/<form[^>]*>(?:(?!<\/form>).)*name="aksi" value="putuskan"(?:(?!<\/form>).)*<\/form>/s)?.[0]||'';
  const stale=Object.fromEntries([...form.matchAll(/name="([^"]+)" value="([^"]*)"/g)].map(v=>[v[1],v[2]]));
  ok(detail.status()===200&&html.includes(PENANDA)&&stale.aksi==='putuskan','Kontrol: sesi web dan aplikasi membaca isi serta formulir keputusan sah');
  assignment=Number(sql(`SELECT ma.id FROM murobi_assignments ma JOIN users u ON u.guru_id=ma.guru_id JOIN plotting_kamar pk ON pk.id_kamar=ma.kamar_id AND pk.id_tahun=ma.tahun_ajaran_id WHERE pk.id_santri=${sCadangan} AND u.username='sbx_murobi_a' AND ma.is_active=1 LIMIT 1`));
  if(!assignment)throw Error('Tidak ada penugasan fixture');
  sql(`UPDATE murobi_assignments SET is_active=0 WHERE id=${assignment}`);
  ok(Number(sql("SELECT COUNT(*) FROM murobi_assignments ma JOIN users u ON u.guru_id=ma.guru_id WHERE u.username='sbx_murobi_a' AND ma.is_active=1"))>0,'Kontrol: hak murobi dari penugasan lain tetap aktif');
  const response=m.waitForResponse(r=>r.request().method()==='POST'&&new URL(r.url()).pathname.endsWith(`/izin/pengajuan/${id}/keputusan`));
  await tombol(m,'Setujui').click();const denied=await response;await m.waitForTimeout(600);
  ok([403,404].includes(denied.status())&&!(await teks(m)).includes(PENANDA)&&!(await teks(m)).includes('SBX V7 Santri Cadangan'),'POST aplikasi tanpa reload ditolak dan membersihkan isi pada layar lama');
  const post=await request.post(web+'/portal/izin_aksi.php',{form:{...stale,hasil:'Disetujui',alasan:'Probe keputusan web basi'},maxRedirects:0});
  const location=post.headers().location;const after=location?await request.get(new URL(location,web).href):post;
  ok([302,303,403,404].includes(post.status())&&[403,404].includes(after.status())&&!(await after.text()).includes(PENANDA),'Formulir web basi ditolak; tujuan sesudah POST tidak membuka isi');
  for(const route of [
    `/portal/izin_detail.php?id=${id}&mode=murobi`, `/portal/izin_wali_detail.php?id=${id}`,
    `/portal/laporan.php?mode=murobi&date_from=${day}&date_to=${day}&santri_id=${sCadangan}`,
    `/portal/laporan_cetak.php?mode=murobi&date_from=${day}&date_to=${day}&santri_id=${sCadangan}`,
    `/portal/laporan_csv.php?mode=murobi&date_from=${day}&date_to=${day}&santri_id=${sCadangan}`,
    `/portal/izin_wali_laporan_ekspor.php?santri_id=${sCadangan}&dari=${day}&sampai=${day}`
  ]) {
    const r=await request.get(web+route);const body=await r.text();
    // Filter santri sah masih dapat bernama lewat baris Pengurus V2 lain.
    // Periksa isi baris Wali, bukan label filter di kepala laporan.
    const laporan=/\/laporan(?:_cetak)?\.php/.test(route);
    const visible=laporan?[...body.matchAll(/<tbody[^>]*>([\s\S]*?)<\/tbody>/g)].map(v=>v[1]).join(' '):body;
    ok(!body.includes(PENANDA)&&!visible.includes('SBX V7 Santri Cadangan')&&!body.includes(`izin_detail.php?id=${id}`)&&(!laporan||/Tidak ada pengajuan (yang cocok|sesuai saringan)/.test(body))&&r.status()<500,'Sesi lama tertutup tanpa bocor pada '+route.split('?')[0]+' ('+r.status()+')');
  }
  ok(sql(`SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id=${id}`)==='0'&&sql(`SELECT CONCAT(status,':',version) FROM izin_pengajuan WHERE id=${id}`)==='Diajukan:2','Dua POST basi menghasilkan nol keputusan dan tidak mengubah status/versi');
  await foto(m,'F51-post-basi');await ctx.close();await m.context().close();
} catch(e){gagal++;console.log('[gagal] '+e.stack);}
finally{if(assignment)sql(`UPDATE murobi_assignments SET is_active=1 WHERE id=${assignment}`);await browser.close();}
console.log(`Ringkasan: ${lulus} lulus, ${gagal} gagal`);process.exit(gagal?1:0);
