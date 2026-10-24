import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Uji aplikasi PRD V5 Fase 5 — layar laporan klinik (Expo web dari repo alhasanApps + API/DB uji nyata).
//
//   V5F5_DB=test_v5_f5_app_test bash tests/v5_phase5_db.sh
//   DB_NAME=test_v5_f5_app_test php -S 127.0.0.1:8982 tests/redesain_web_tahap1_router.php &
//   (cd ~/alhasanApps && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8982/api/v1 npx expo start --web --port 8099 &)
//   (cd tests/browser && V5_WEB=http://127.0.0.1:8982 V5_APP=http://127.0.0.1:8099 OUT_DIR=../../docs/phase-v5-5/tangkapan node uji-v5-fase5-app.mjs)
//
//   H-A  petugas, admin, pembimbing A/B, murobi: tombol "Laporan layanan klinik" di pintu kesehatan;
//        periode "Bulan lalu" → angka layar = API untuk periode yang sama; Draf hanya staf;
//        pengawasan hanya admin; nama santri di luar cakupan tidak tampil; 375 px tanpa gulir samping;
//   H-B  ketuk baris tertunda → detail kunjungan (dibaca ulang dari server);
//   H-C  wali dan pengurus tanpa penugasan: layar laporan menampilkan penolakan tanpa data;
//   H-D  penyimpanan perangkat (local/sessionStorage) dan konsol tanpa penanda isi klinis/nama santri.
//
// Hanya membaca; DB uji tidak diubah. Ini Expo WEB (Chromium), bukan perangkat native.
const web = process.env.V5_WEB, app = process.env.V5_APP;
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || ''))) throw Error('Hanya localhost');
const out = process.env.OUT_DIR || '/tmp/v5f5-tangkapan';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';
const PENANDA = 'V5F5RHS';

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const tokens = {};
async function api(user, p) {
  if (!tokens[user]) {
    const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: PASS, device_name: 'uji-v5-f5' }) });
    tokens[user] = (await r.json()).data.token;
  }
  const r = await fetch(`${web}/api/v1${p}`, { headers: { Authorization: `Bearer ${tokens[user]}` } });
  return { status: r.status, json: await r.json().catch(() => null) };
}
// Periode "Bulan lalu" dihitung sama dengan aplikasi (tanggal lokal).
const kini = new Date();
const iso = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const bulanLalu = { dari: iso(new Date(kini.getFullYear(), kini.getMonth() - 1, 1)), sampai: iso(new Date(kini.getFullYear(), kini.getMonth(), 0)) };
const angka = n => n.toLocaleString('id-ID');

const browser = await chromium.launch({ headless: true });
const konsol = [];
const permintaanLuar = [];
async function halamanApp(user) {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 } });
  const page = await ctx.newPage();
  page.on('console', m => konsol.push(`${user}: ${m.text()}`));
  await page.route('**/*', async route => {
    const u = new URL(route.request().url());
    if (u.hostname !== '127.0.0.1') { permintaanLuar.push(u.origin); return route.abort(); }
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    if (route.request().method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
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
async function penyimpanan(p) {
  return p.evaluate(() => JSON.stringify([Object.entries(localStorage), Object.entries(sessionStorage)]));
}

const PERAN = {
  sbx_v5_petugas_f: { staf: true, admin: false, tidakBoleh: [] },
  sbx_admin: { staf: true, admin: true, tidakBoleh: [] },
  sbx_v5_pembimbing: { staf: false, admin: false, tidakBoleh: ['SBX V5 Lapor B', 'SBX V5 Lapor Luar'] },
  sbx_v5_pembimbing_b: { staf: false, admin: false, tidakBoleh: ['SBX V5 Lapor A', 'SBX V5 Lapor Luar'] },
  sbx_v5_murobi: { staf: false, admin: false, tidakBoleh: ['SBX V5 Lapor B', 'SBX V5 Lapor Luar'] },
};
try {
  console.log(`\n=== H-A/H-B. Laporan per peran (Bulan lalu = ${bulanLalu.dari} s.d. ${bulanLalu.sampai}) ===`);
  for (const [user, h] of Object.entries(PERAN)) {
    const harap = (await api(user, `/v5/kesehatan/laporan?dari=${bulanLalu.dari}&sampai=${bulanLalu.sampai}`)).json.data;
    const p = await halamanApp(user);
    await p.goto(app + '/kesehatan');
    await tombol(p, 'Laporan layanan klinik').waitFor();
    ok(true, `${user}: tombol "Laporan layanan klinik" di pintu kesehatan`);
    await tombol(p, 'Laporan layanan klinik').click();
    await p.getByText('Laporan layanan klinik', { exact: true }).waitFor();
    await p.getByRole('radio', { name: 'Bulan lalu' }).click();
    await p.getByText(`${angka(harap.kunjungan.total)} (${angka(harap.kunjungan.santri)} santri)`).first().waitFor({ timeout: 15000 }).catch(() => {});
    const t = await teks(p);
    const k = harap.kunjungan, r = harap.rujukan, tl = harap.tindak_lanjut;
    ok(t.includes(`${angka(k.total)} (${angka(k.santri)} santri)`) && t.includes(`${angka(k.dengan_obat)} kunjungan · ${angka(harap.obat.pemberian)} pemberian`)
      && t.includes(`${angka(r.total)} · ${angka(r.menunggu)} menunggu hasil · ${angka(r.diketahui)} diketahui`)
      && t.includes(`${angka(tl.total)} · ${angka(tl.terbuka)} terbuka · ${angka(tl.selesai)} selesai`),
      `${user}: angka layar = API (kunjungan ${k.total}, obat ${harap.obat.pemberian}, rujukan ${r.total}, TL ${tl.total})`);
    ok(/ Draf \d/.test(t) === h.staf && t.includes('Pengawasan admin') === h.admin, `${user}: Draf ${h.staf ? 'tampil' : 'tersembunyi'}, pengawasan ${h.admin ? 'tampil' : 'tidak tampil'}`);
    ok(h.tidakBoleh.every(n => !t.includes(n)) && !t.includes(PENANDA), `${user}: tanpa santri di luar cakupan dan tanpa penanda isi klinis`);
    const lebih = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    ok(lebih <= 1, `${user}: tanpa gulir horizontal pada 375 px (selisih ${lebih}px)`);
    await p.screenshot({ path: path.join(out, `app-laporan-${user.replace('sbx_', '')}-375.png`), fullPage: true });
    if (user === 'sbx_v5_petugas_f' || user === 'sbx_v5_pembimbing') {
      const baris = p.getByRole('button', { name: /^Buka kunjungan SBX V5 Lapor A/ }).first();
      await baris.click();
      await p.waitForURL(u => /\/kesehatan\/kunjungan\/\d+/.test(u.pathname));
      await p.getByText('SBX V5 Lapor A').first().waitFor();
      ok(true, `${user}: ketuk baris tertunda → detail kunjungan santri A`);
    }
    ok(!(await penyimpanan(p)).includes(PENANDA) && !(await penyimpanan(p)).includes('SBX V5 Lapor'), `${user}: local/sessionStorage tanpa isi klinis atau nama santri`);
    await p.context().close();
  }

  console.log('\n=== H-C. Ditolak ===');
  for (const user of ['sbx_v5_wali_1', 'sbx_pengurus_biasa']) {
    const p = await halamanApp(user);
    await p.goto(app + '/kesehatan/laporan');
    await p.waitForTimeout(2500);
    const t = await teks(p);
    ok(!t.includes('SBX V5 Lapor') && !/\d+ \(\d+ santri\)/.test(t) && /hanya untuk|tidak|ditolak|akses/i.test(t), `${user}: layar laporan menolak tanpa data`);
    await p.context().close();
  }
} finally {
  await browser.close();
}
ok(!konsol.some(l => l.includes(PENANDA) || l.includes('SBX V5 Lapor')), `H-D konsol tanpa isi klinis/nama santri (${konsol.length} baris)`);
ok(permintaanLuar.length === 0, `H-D tidak ada permintaan ke luar localhost (${[...new Set(permintaanLuar)].join(', ')})`);
console.log(`\nLulus: ${lulus} · Gagal: ${gagal}`);
process.exit(gagal === 0 ? 0 : 1);
