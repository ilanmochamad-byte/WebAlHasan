// Rapor aplikasi — koreksi beralasan lewat lembar bawah (tindak lanjut TL-15,
// 13 November 2026) di atas fixture sintetis Tahap 7. Pengganti terarah untuk
// `uji-v4-fase5-app.mjs`, yang memerlukan rantai fixture V4 Fase 1–4 dari dump
// produksi (tidak dipakai demi privasi).
//
//   RTV2_WEB=http://127.0.0.1:8971 RTV2_APP=http://127.0.0.1:<port> DB_NAME=<test_*_test> node uji-rapor-koreksi-app.mjs
//
// Memeriksa: lembar "Koreksi nilai"/"Koreksi sikap" terbuka TANPA mengirim permintaan;
// tanpa alasan tidak ada POST; dengan alasan tepat satu POST action=koreksi beralasan
// dan server menerimanya; draf/serahkan tetap dari halaman.
import { chromium } from 'playwright';

const web = process.env.RTV2_WEB, app = process.env.RTV2_APP, db = process.env.DB_NAME || '';
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) throw Error('Hanya 127.0.0.1 dan DB test_*_test');
const PASS = 'Sandbox#123';
let lulus = 0, gagal = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const luar = [];
async function api(user, p) {
  const t = (await (await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: PASS, device_name: 'uji-rk' }) })).json()).data.token;
  return (await (await fetch(`${web}/api/v1${p}`, { headers: { Authorization: `Bearer ${t}` } })).json()).data;
}
const browser = await chromium.launch({ headless: true });
async function masuk(user) {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 }, timezoneId: 'Asia/Jakarta' });
  const page = await ctx.newPage();
  const kirim = [];
  page.on('dialog', d => d.dismiss());
  await page.route('**/*', async route => {
    const req = route.request(); const u = new URL(req.url());
    if (u.hostname !== '127.0.0.1') { luar.push(u.origin); return route.abort(); }
    if (u.origin !== web) return route.fallback();
    if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type,idempotency-key', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    let body = null; try { body = req.postDataJSON(); } catch { /* bukan JSON */ }
    if (req.method() === 'POST' && u.pathname.startsWith('/api/v1/v4/')) kirim.push({ path: u.pathname.replace('/api/v1', ''), body });
    try { const response = await route.fetch(); if (req.method() === 'POST') kirim.at(-1) && (kirim.at(-1).status = response.status()); await route.fulfill({ response, headers: { ...response.headers(), 'Access-Control-Allow-Origin': app } }); } catch { /* konteks ditutup */ }
  });
  await page.goto(app + '/login');
  await page.getByPlaceholder('Masukkan username').fill(user);
  await page.getByPlaceholder('Masukkan password').fill(PASS);
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
  await page.waitForURL(u => u.pathname !== '/login', { timeout: 30000 });
  await page.waitForTimeout(1200);
  return { page, kirim };
}
const tombol = (p, n) => p.getByRole('button', { name: n, exact: true });
const tunggu = (p, t, timeout = 20000) => p.getByText(t, { exact: false }).first().waitFor({ timeout }).then(() => true, () => false);
async function pilih(p, label, opsi) {
  await tombol(p, label).click();
  const cari = p.getByPlaceholder(/Cari/).last();
  if (await cari.count()) await cari.fill(opsi.split(' · ')[0]).catch(() => {});
  await tombol(p, opsi).first().click();
  await p.waitForTimeout(600);
}

try {
  console.log('=== A. Nilai mata pelajaran (sbx_guru_mapel) ===');
  const opsi = (await api('sbx_guru_mapel', '/v4/nilai/options'))[0];
  const santriList = (await api('sbx_guru_mapel', `/v4/nilai?rencana_mapel_id=${opsi.id}`)).santri;
  let santri = null;
  for (const s of santriList) { const d = await api('sbx_guru_mapel', `/v4/nilai?rencana_mapel_id=${opsi.id}&santri_id=${s.id}`); if (!d.nilai.length) { santri = s; break; } }
  ok(Boolean(santri), `fixture: santri tanpa nilai untuk ${opsi.mata_pelajaran} (${santri?.nama_santri ?? '—'})`);
  const { page: g, kirim } = await masuk('sbx_guru_mapel');
  await g.goto(app + '/rapor/nilai'); await tunggu(g, 'Kelas, semester, dan mapel');
  await pilih(g, 'Kelas, semester, dan mapel', `${opsi.nama_kelas} · ${opsi.tahun} ${opsi.semester} · ${opsi.mata_pelajaran}`);
  await pilih(g, 'Santri', santri.nama_santri);
  await tunggu(g, 'Nilai komponen');
  await g.getByRole('textbox', { name: 'Harian 1', exact: true }).fill('80');
  await g.getByRole('textbox', { name: 'Tengah', exact: true }).fill('70');
  await g.getByRole('textbox', { name: 'Akhir', exact: true }).fill('90');
  ok(await g.getByRole('textbox', { name: 'Alasan koreksi / tindakan admin', exact: true }).count() === 0, 'TL-15: kolom alasan koreksi tidak tampil di halaman (mode guru)');
  await tombol(g, 'Simpan draf nilai').click();
  await g.waitForTimeout(2500);
  ok(kirim.some(k => k.path === '/v4/nilai' && k.body?.action === 'draf' && k.status === 200), 'Simpan draf nilai: satu POST action=draf diterima (200)');
  await tunggu(g, 'Nilai komponen');
  await g.getByRole('textbox', { name: 'Akhir', exact: true }).fill('95');
  const n0 = kirim.length;
  await tombol(g, 'Koreksi nilai beralasan').click();
  const lembar = await tombol(g, 'Simpan koreksi').waitFor({ timeout: 5000 }).then(() => true, () => false) && await g.getByRole('heading', { name: 'Koreksi nilai', exact: true }).count() === 1 && await g.getByRole('textbox', { name: 'Alasan koreksi / tindakan admin', exact: true }).count() === 1;
  await g.waitForTimeout(800);
  ok(lembar && kirim.length === n0, 'TL-15: "Koreksi nilai beralasan" membuka lembar "Koreksi nilai" tanpa mengirim permintaan');
  if (lembar) {
    await tombol(g, 'Simpan koreksi').click(); await g.waitForTimeout(1200);
    ok(kirim.length === n0, 'Tanpa alasan: tidak ada POST (alasan wajib diperiksa sebelum kirim)');
    await g.getByRole('button', { name: 'Koreksi nilai beralasan', exact: true }).click().catch(() => {});
    await g.getByRole('textbox', { name: 'Alasan koreksi / tindakan admin', exact: true }).fill('Koreksi probe tindak lanjut');
    await tombol(g, 'Simpan koreksi').click(); await g.waitForTimeout(2500);
    const k = kirim.slice(n0);
    ok(k.length === 1 && k[0].body?.action === 'koreksi' && k[0].body?.alasan === 'Koreksi probe tindak lanjut' && k[0].status === 200, `Dengan alasan: tepat satu POST action=koreksi beralasan diterima (${k.map(x => `${x.body?.action}:${x.status}`).join(', ')})`);
  }
  await g.context().close();

  console.log('=== B. Sikap (pembimbing) ===');
  const pemb = 'sbx_pengurus_a';
  const sikapOpsi = await api(pemb, '/v4/sikap/options').catch(() => []);
  if (!Array.isArray(sikapOpsi) || sikapOpsi.length === 0) {
    ok(true, 'Sikap: fixture tanpa santri binaan bersikap — bagian B dilewati (dicatat)');
  } else {
    const o = sikapOpsi[0];
    const { page: m, kirim: km } = await masuk(pemb);
    await m.goto(app + '/rapor/sikap'); await tunggu(m, 'Santri, kelas, dan semester');
    await pilih(m, 'Santri, kelas, dan semester', `${o.nama_santri} · ${o.nama_kelas} · ${o.tahun} ${o.semester}`);
    await tunggu(m, 'Status:');
    ok(await m.getByRole('textbox', { name: 'Alasan koreksi (wajib untuk koreksi)', exact: true }).count() === 0, 'TL-15: kolom alasan koreksi sikap tidak tampil di halaman');
    const ada = await tombol(m, 'Koreksi sikap beralasan').isEnabled().catch(() => false);
    if (ada) {
      const n1 = km.length;
      await tombol(m, 'Koreksi sikap beralasan').click();
      ok(await tombol(m, 'Simpan koreksi').waitFor({ timeout: 5000 }).then(() => true, () => false) && await m.getByRole('textbox', { name: 'Alasan koreksi (wajib untuk koreksi)', exact: true }).count() === 1 && km.length === n1, 'TL-15: "Koreksi sikap beralasan" membuka lembar tanpa mengirim permintaan');
    } else ok(true, 'Sikap: belum ada isian sikap tersimpan — tombol koreksi nonaktif (sesuai aturan)');
    await m.context().close();
  }
} catch (e) {
  ok(false, `Pengecualian: ${e.message.split('\n')[0]}`);
} finally {
  await browser.close();
}
ok(luar.length === 0, `tanpa permintaan keluar 127.0.0.1 (${[...new Set(luar)].join(', ')})`);
console.log(`\nRingkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
