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
  const made=await api('sbx_v7_wali_a','/v7/izin/wali/pengajuan',{santri_id:sSatu,tgl_izin:'2028-02-01',tgl_kembali:'2028-02-02',alasan:PENANDA+' asli',idempotency_key:kunci('stale')});
  const id=made.json.data.id;
  const mutate=async (u,action,body)=>api(u,`/v7/izin/pengajuan/${id}/${action}`,{...body,idempotency_key:kunci(action)});
  await mutate('sbx_v7_pembimbing_satu','tinjauan',{tindakan:'minta_perbaikan',catatan:'Perbaikan pertama',version:1});
  const w=await halamanApp('sbx_v7_wali_a');
  await w.goto(app+`/izin-wali/perbaiki?id=${id}`);await tunggu(w,'Permintaan pembimbing');
  await w.getByLabel('Alasan izin').fill(PENANDA+' draf lama');
  // Siklus AppState web yang dipakai hook yang sama di native.
  const state=async s=>w.evaluate(s=>{Object.defineProperty(document,'visibilityState',{configurable:true,value:s});document.dispatchEvent(new Event('visibilitychange'));},s);
  await state('hidden');
  await mutate('sbx_v7_wali_a','perbaikan',{tgl_izin:'2028-02-01',tgl_kembali:'2028-02-02',alasan:PENANDA+' perangkat kedua',catatan:'',version:2});
  await mutate('sbx_v7_pembimbing_satu','tinjauan',{tindakan:'minta_perbaikan',catatan:'Perbaikan kedua',version:3});
  await state('visible');await tunggu(w,'Perbaikan kedua');
  const awal=postV7.length;
  await tombol(w,'Kirim perbaikan').click();await w.waitForTimeout(1000);
  ok(postV7.length===awal || postV7.at(-1).body.version===2,'F-1 draf lama tetap membawa versi asal 2; tidak mengadopsi versi 4');
  ok(sql(`SELECT version FROM izin_pengajuan WHERE id=${id}`)==='4','F-1 perubahan perangkat kedua tidak tertimpa draf lama');
  await w.context().close();
  const version=Number(sql(`SELECT version FROM izin_pengajuan WHERE id=${id}`));
  if(sql(`SELECT status FROM izin_pengajuan WHERE id=${id}`)==='Perlu Perbaikan Wali')await mutate('sbx_v7_wali_a','perbaikan',{tgl_izin:'2028-02-01',tgl_kembali:'2028-02-02',alasan:PENANDA+' untuk murobi',catatan:'',version});
  await mutate('sbx_v7_pembimbing_satu','tinjauan',{tindakan:'teruskan',catatan:'',version:Number(sql(`SELECT version FROM izin_pengajuan WHERE id=${id}`))});
  const m=await halamanApp('sbx_murobi_a');await m.goto(app+`/izin-wali/${id}`);await tunggu(m,'Putuskan di Perizinan');await tombol(m,'Putuskan di Perizinan').click();await tunggu(m,'Diajukan orang tua');
  await m.getByPlaceholder('Minimal 3 karakter').first().fill('Audit keputusan');
  const task=sql("SELECT GROUP_CONCAT(ma.id) FROM murobi_assignments ma JOIN users u ON u.guru_id=ma.guru_id WHERE u.username='sbx_murobi_a' AND ma.is_active=1");
  sql(`UPDATE murobi_assignments SET is_active=0 WHERE id IN (${task})`);
  const penolakan=m.waitForResponse(r=>r.request().method()==='POST' && new URL(r.url()).pathname.endsWith(`/izin/pengajuan/${id}/keputusan`));
  await tombol(m,'Setujui').click();
  const respons=await Promise.race([penolakan,m.waitForTimeout(5000).then(()=>null)]);
  ok(respons!==null && [403,404].includes(respons.status()),'F-2 server menolak POST murobi pada sesi lama (403/404)');
  await m.waitForTimeout(600);
  ok(sql(`SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id=${id}`)==='0','F-2 tidak ada keputusan setelah hak murobi dicabut');
  ok(!(await teks(m)).includes(PENANDA) && !(await teks(m)).includes('SBX V7 Santri Satu'),'F-2 penolakan keputusan 403/404 menutup isi V2 pengajuan wali pada layar lama');
  sql(`UPDATE murobi_assignments SET is_active=1 WHERE id IN (${task})`);
  await m.context().close();
} catch(e) {gagal++;console.log('[gagal] '+e.stack);}
finally {sql("UPDATE murobi_assignments ma JOIN users u ON u.guru_id=ma.guru_id SET ma.is_active=1 WHERE u.username='sbx_murobi_a'");await browser.close();}
console.log(`Ringkasan: ${lulus} lulus, ${gagal} gagal`);process.exit(gagal?1:0);
