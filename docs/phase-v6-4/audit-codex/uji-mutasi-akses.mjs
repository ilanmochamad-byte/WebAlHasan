import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from '../../../tests/browser/node_modules/playwright/index.mjs';

// Uji aplikasi PRD V6 Fase 4 — layar Keuangan (Expo WEB dari repo alhasanApps + API/DB uji nyata).
//
//   V6F4_DB=test_v6_f4_app_test bash tests/v6_phase4_db.sh
//   DB_NAME=test_v6_f4_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8984 tests/redesain_web_tahap1_router.php &
//   (cd <salinan alhasanApps TANPA .env.local> && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8984/api/v1 npx expo start --web --port 8099 --clear &)
//   (cd tests/browser && DB_NAME=test_v6_f4_app_test V6_WEB=http://127.0.0.1:8984 V6_APP=http://127.0.0.1:8099 OUT_DIR=… node uji-v6-fase4-app.mjs)
//
//   K-A  petugas: setoran dari aplikasi, ketukan ganda → satu POST; jawaban hilang lalu
//        kirim ulang → kunci sama, replay, satu kredit; paritas angka dengan website;
//   K-B  belanja, melebihi saldo (pesan server), setoran transfer → antrean, verifikasi;
//   K-C  bayar dari tabungan (wajib centang) dan pembayaran tunai; kuitansi; paritas web;
//   K-D  konflik versi revisi tagihan (409 → "Muat versi terbaru"), penerbitan massal;
//   K-E  admin hanya membaca; rekonsiliasi = API;
//   K-F  wali dua anak: dua kartu terpisah = API; notifikasi → tombol → tagihan; wali lain
//        dengan URL anak lain → ditolak tanpa data;
//   K-G  relasi/penugasan dicabut saat layar terbuka; sesi berakhir → masuk ulang;
//        offline → pesan jelas, lalu pulih;
//   K-H  local/sessionStorage dan konsol tanpa referensi/token; nol permintaan keluar
//        127.0.0.1; 375 px tanpa gulir samping.
//
// Mengubah DB uji. Ini Expo WEB (Chromium 375×812), bukan perangkat native.
const web = process.env.V6_WEB, app = process.env.V6_APP, db = process.env.DB_NAME || '';
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) throw Error('Hanya localhost dan DB test_*_test');
const out = process.env.OUT_DIR || '/tmp/v6f4-tangkapan';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';
const REF = 'REF-APP-RAHASIA';

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const tokens = {};
async function api(user, p, body) {
  if (!tokens[user]) {
    const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: PASS, device_name: 'uji-v6-f4-app' }) });
    tokens[user] = (await r.json()).data.token;
  }
  const r = await fetch(`${web}/api/v1${p}`, {
    method: body ? 'POST' : 'GET', headers: { Authorization: `Bearer ${tokens[user]}`, 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined,
  });
  return { status: r.status, json: await r.json().catch(() => null) };
}
const rp = n => `Rp${String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.')}`;
const kunci = p => `app-uji-${p}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
const sSmp = Number(sql("SELECT id FROM santri WHERE nis='V6S0001'"));
const sTs = Number(sql("SELECT id FROM santri WHERE nis='V6S0002'"));
const saldo = s => Number(sql(`SELECT COALESCE(MAX(saldo),0) FROM bsa_tabungan_akun WHERE santri_id=${s}`));

const browser = await chromium.launch({ headless: true });
const konsol = [];
const permintaanLuar = [];
const postBsa = [];
// Penanda jaringan putus: penangan rute Playwright memanggil server sendiri, jadi
// `context.setOffline` tidak memutus API; permintaan API digagalkan di sini.
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
    if (route.request().method() === 'POST' && u.pathname.startsWith('/api/v1/v6/')) postBsa.push({ user, path: u.pathname, body: route.request().postDataJSON() });
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
const foto = (p, nama) => p.screenshot({ path: path.join(out, `app-${nama}-375.png`), fullPage: true });
const penyimpanan = p => p.evaluate(() => JSON.stringify([Object.entries(localStorage), Object.entries(sessionStorage)]));

// Probe auditor: POST ditolak sesudah penugasan dicabut harus menutup data
// dan draf layar lama juga, bukan hanya menambahkan pesan di bawahnya.
try {
  const user = 'sbx_v6_bsa_b';
  const tugas = sql("SELECT b.id FROM bendahara_bulanan_assignments b JOIN users u ON u.pengurus_id=b.pengurus_id WHERE u.username='sbx_v6_bsa_b'");
  const t = await api(user, '/v6/bsa/tagihan', {idempotency_key:kunci('t'),santri_id:sSmp,periode:'2026-12',jatuh_tempo:'2026-12-31',komponen:[{nama:'Syahriah',nominal:100000}]});
  const tid=t.json.data.id;
  const st = await api(user, `/v6/bsa/santri/${sSmp}/setoran`, {idempotency_key:kunci('s'),nominal:'100000',metode:'Tunai'});
  const xid=st.json.data.id;
  const p = await api(user, `/v6/bsa/tagihan/${tid}/pembayaran`, {idempotency_key:kunci('p'),nominal:'1000',sumber:'Tunai',tanggal_transaksi:'2026-10-26'});
  const pid=p.json.data.id;
  const pt = await api(user, `/v6/bsa/tagihan/${tid}/pembayaran`, {idempotency_key:kunci('pt'),nominal:'1000',sumber:'Transfer',tanggal_transaksi:'2026-10-26',referensi:REF});
  const ptid=pt.json.data.id;
  await api(user, `/v6/bsa/santri/${sSmp}/setoran`, {idempotency_key:kunci('st'),nominal:'5000',metode:'Transfer',referensi:REF});
  const count=()=>sql('SELECT CONCAT((SELECT COUNT(*) FROM bsa_tabungan_transaksi),\":\",(SELECT COUNT(*) FROM bsa_pembayaran),\":\",(SELECT SUM(total) FROM bsa_tagihan))');
  const cases=[
    {name:'setoran',path:`/keuangan/transaksi?santri_id=${sSmp}&mode=setoran`,ready:'Saldo saat ini',setup:async p=>p.getByLabel('Nominal (rupiah)').fill('5000'),button:'Catat setoran'},
    {name:'pembayaran tunai',path:`/keuangan/bayar?tagihan_id=${tid}`,ready:'Sisa',setup:async p=>p.getByLabel('Nominal (rupiah)').fill('5000'),button:'Catat pembayaran'},
    {name:'bayar tabungan',path:`/keuangan/bayar-tabungan?tagihan_id=${tid}`,ready:'Saldo tabungan',setup:async p=>{await p.getByLabel('Nominal dipotong dari tabungan').fill('5000');await p.getByRole('checkbox').click();},button:'Bayar dari tabungan'},
    {name:'revisi',path:`/keuangan/ubah-tagihan?tagihan_id=${tid}`,ready:'versi 1',setup:async p=>{await p.getByRole('radio',{name:'Tambahan',exact:true}).click();await p.getByLabel('Nominal (rupiah)').fill('5000');await p.getByLabel('Alasan (wajib)').fill('Audit pencabutan akses');},button:'Simpan revisi'},
    {name:'verifikasi pembayaran',path:`/keuangan/pembayaran/${ptid}`,ready:'Referensi transfer',setup:async()=>{},button:'Dana sudah diperiksa — verifikasi'},
    {name:'batal pembayaran',path:`/keuangan/pembayaran/${pid}`,ready:'Sisa sesudah pembayaran',setup:async p=>{await tombol(p,'Batalkan pembayaran…').click();await p.getByLabel('Alasan pembatalan (wajib)').fill('Audit pencabutan akses');},button:'Batalkan pembayaran'},
    {name:'koreksi tabungan',path:`/keuangan/tabungan/${xid}`,ready:'Saldo sesudah',setup:async p=>{await tombol(p,'Koreksi transaksi…').click();await p.getByLabel('Alasan koreksi (wajib)').fill('Audit pencabutan akses');},button:'Catat koreksi'},
    {name:'verifikasi setoran',path:'/keuangan/verifikasi',ready:'Setoran tabungan (1)',setup:async()=>{},button:'Sudah diperiksa — verifikasi'},
  ];
  for(const c of cases){
    sql(`UPDATE bendahara_bulanan_assignments SET is_active=1 WHERE id=${tugas}`);
    const page=await halamanApp(user);
    await page.goto(app+c.path);await tunggu(page,c.ready);await c.setup(page);
    const before=count();
    sql(`UPDATE bendahara_bulanan_assignments SET is_active=0 WHERE id=${tugas}`);
    await tombol(page,c.button).click();await tunggu(page,'Informasi tidak dapat diakses');
    await page.waitForTimeout(400);
    const text=await teks(page);
    ok(count()===before,`${c.name}: penolakan sesi lama tanpa mutasi uang`);
    ok(!text.includes('SBX V6 Santri SMP') && !text.includes(REF) && await page.getByRole('textbox').count()===0,
      `${c.name}: 403 membuang data dan draf lama (tanpa nama, referensi, kolom isian)`);
    await page.context().close();
  }
  sql(`UPDATE bendahara_bulanan_assignments SET is_active=1 WHERE id=${tugas}`);
} catch(e){gagal++;console.log('[gagal] '+(e.stack||e));}
finally {sql("UPDATE bendahara_bulanan_assignments b JOIN users u ON u.pengurus_id=b.pengurus_id SET b.is_active=1 WHERE u.username='sbx_v6_bsa_b'");await browser.close();}
console.log(`Ringkasan: ${lulus} lulus, ${gagal} gagal`);process.exit(gagal?1:0);
