import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Probe auditor F-1: respons pratinjau API nyata ditahan sampai input berubah
// atau layar melewati siklus latar/aktif. Respons lama tidak boleh mengaktifkan
// penerbitan. Pratinjau tidak menulis tagihan. Jalankan dengan fixture/Metro dan
// variabel DB_NAME, SMP_LAIN, V6_WEB, V6_APP seperti uji-tagihan-satu-santri-app.mjs.
// Kontrol handoff dan koreksi memakai tiga assertion yang sama.
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


const visibility = (p, state) => p.evaluate(state => { Object.defineProperty(document, 'visibilityState', { configurable: true, value: state }); document.dispatchEvent(new Event('visibilitychange')); }, state);
async function holdNext(p) {
  let release, begin; const started = new Promise(r => begin=r); let armed=true;
  await p.route('**/api/v1/v6/bsa/penerbitan/pratinjau', async route => {
    if (!armed) return route.fallback(); armed=false;
    const response=await route.fetch();
    const barrier=new Promise(r=>release=r); begin(); await barrier;
    await route.fulfill({response, headers:{...response.headers(),'Access-Control-Allow-Origin':app}});
  });
  return { started, release:()=>release() };
}
const next = new Date(kini.getFullYear(), kini.getMonth()+1, 1);
const C = `${next.getFullYear()}-${String(next.getMonth()+1).padStart(2,'0')}`;
try {
  const p = await halamanApp('sbx_v6_bsa_b');
  await bukaTerbit(p, smpLain);
  const hold = await holdNext(p);
  await tombol(p,'Tampilkan tagihan').click(); await hold.started;
  await p.getByRole('textbox',{name:'Periode (TTTT-BB)',exact:true}).fill(C);
  await p.locator('input[type="date"]').fill(C+'-10');
  const done = p.waitForResponse(r=>r.url().endsWith('/penerbitan/pratinjau')); hold.release(); await done;
  await tombol(p,'Tampilkan tagihan').waitFor();
  await p.waitForFunction(()=> !document.querySelector('[role="progressbar"]'));
  await p.waitForTimeout(200);
  ok(!(await p.getByRole('button',{name:/^Terbitkan tagihan Rp/}).count()) && !(await teks(p)).includes('Total tagihan'),
    'B-1 respons pratinjau lama sesudah periode/jatuh tempo diubah tidak mengaktifkan tombol terbit');
  await foto(p,'audit-respons-lama-input');
  await p.context().close();

  const bg = await halamanApp('sbx_v6_bsa_b'); await bukaTerbit(bg,smpLain);
  const delayed=await holdNext(bg); await tombol(bg,'Tampilkan tagihan').click(); await delayed.started;
  await visibility(bg,'hidden'); await visibility(bg,'visible');
  await bg.getByRole('textbox',{name:'Periode (TTTT-BB)',exact:true}).waitFor();
  const doneBg = bg.waitForResponse(r=>r.url().endsWith('/penerbitan/pratinjau')); delayed.release(); await doneBg;
  await bg.waitForTimeout(200);
  ok(!(await bg.getByRole('button',{name:/^Terbitkan tagihan Rp/}).count()) && !(await teks(bg)).includes('Total tagihan'),
    'B-2 respons pratinjau dari sebelum latar tidak dimunculkan kembali setelah hak dimuat ulang');
  await bg.context().close();
  ok(tagihan(smpLain) === 0,'Probe pratinjau tidak menerbitkan tagihan');
} catch(e) { ok(false,'Probe berhenti: '+e.message.split('\n')[0]); }
finally { await browser.close(); }
console.log(`Ringkasan probe: ${lulus} lulus, ${gagal} gagal`); process.exit(gagal?1:0);
