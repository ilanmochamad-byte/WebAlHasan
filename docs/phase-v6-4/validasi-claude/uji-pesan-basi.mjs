import { execFileSync } from 'node:child_process';
import { chromium } from '../../../tests/browser/node_modules/playwright/index.mjs';

// Validasi lanjutan Claude atas koreksi F-1 Codex (Expo WEB + API/DB uji, 375×812).
// Sesudah POST ditolak 403 layar ditutup (F-1). Bila hak dipulihkan dan pengguna
// menekan "Coba lagi", formulir/kartu tampil lagi — pesan penolakan lama tidak
// boleh tetap tampil di bawahnya. Dua jalur: formulir setoran dan kartu setoran
// transfer pada antrean verifikasi.
//
//   DB_NAME=test_*_test V6_WEB=http://127.0.0.1:8984 V6_APP=http://127.0.0.1:8099 node uji-pesan-basi.mjs
const web = process.env.V6_WEB, app = process.env.V6_APP, db = process.env.DB_NAME || '';
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) throw Error('Hanya localhost dan DB test_*_test');
const PASS = 'Sandbox#123';
const BASI = 'Informasi tidak dapat diakses';
let lulus = 0, gagal = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const sSmp = Number(sql("SELECT id FROM santri WHERE nis='V6S0001'"));
const tugas = sql("SELECT b.id FROM bendahara_bulanan_assignments b JOIN users u ON u.pengurus_id = b.pengurus_id WHERE u.username = 'sbx_v6_bsa_b'");
const cabut = () => sql(`UPDATE bendahara_bulanan_assignments SET is_active = 0 WHERE id = ${tugas}`);
const pulihkan = () => sql(`UPDATE bendahara_bulanan_assignments SET is_active = 1 WHERE id = ${tugas}`);

const browser = await chromium.launch({ headless: true });
const ctx = await browser.newContext({ viewport: { width: 375, height: 812 } });
const page = await ctx.newPage();
await page.route('**/*', async route => {
  const u = new URL(route.request().url());
  if (u.hostname !== '127.0.0.1') return route.abort();
  if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
  if (route.request().method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
  const response = await route.fetch();
  await route.fulfill({ response, headers: { ...response.headers(), 'Access-Control-Allow-Origin': app } });
});
const teks = async () => (await page.locator('body').innerText()).replace(/\s+/g, ' ');
const tombol = name => page.getByRole('button', { name, exact: true });
try {
  await page.goto(app + '/login');
  await page.getByPlaceholder('Masukkan username').fill('sbx_v6_bsa_b');
  await page.getByPlaceholder('Masukkan password').fill(PASS);
  await tombol('Masuk').click();
  await page.waitForURL(u => u.pathname !== '/login');

  // Jalur 1: formulir setoran.
  await page.goto(app + `/keuangan/transaksi?santri_id=${sSmp}&mode=setoran`);
  await page.getByText('Saldo saat ini').first().waitFor();
  await page.getByLabel('Nominal (rupiah)').fill('5000');
  cabut();
  await tombol('Catat setoran').click();
  await page.getByText('Data belum dapat ditampilkan').first().waitFor();
  ok(!(await teks()).includes('SBX V6 Santri SMP'), 'Formulir: 403 menutup layar tanpa nama santri (F-1 tetap)');
  pulihkan();
  await tombol('Coba lagi').click();
  await page.getByText('Saldo saat ini').first().waitFor();
  ok(!(await teks()).includes(BASI), 'Formulir: sesudah hak dipulihkan dan "Coba lagi", pesan penolakan lama tidak tampil');

  // Jalur 2: kartu setoran transfer pada antrean verifikasi.
  const tok = await (await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: 'sbx_v6_bsa_b', password: PASS, device_name: 'uji-pesan-basi' }) })).json();
  await fetch(`${web}/api/v1/v6/bsa/santri/${sSmp}/setoran`, { method: 'POST', headers: { Authorization: `Bearer ${tok.data.token}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({ idempotency_key: `basi-${Date.now()}`, nominal: 7000, metode: 'Transfer', referensi: 'REF-BASI-1' }) });
  await page.goto(app + '/keuangan/verifikasi');
  await page.getByText('Sudah diperiksa — verifikasi').first().waitFor();
  cabut();
  await tombol('Sudah diperiksa — verifikasi').click();
  await page.getByText('Data belum dapat ditampilkan').first().waitFor();
  ok(!(await teks()).includes('REF-BASI-1'), 'Antrean: 403 menutup layar tanpa referensi transfer (F-1 tetap)');
  pulihkan();
  await tombol('Coba lagi').click();
  await page.getByText('Sudah diperiksa — verifikasi').first().waitFor();
  ok(!(await teks()).includes(BASI), 'Antrean: sesudah "Coba lagi", kartu tampil tanpa pesan penolakan lama');
  ok(sql("SELECT COUNT(*) FROM bsa_setoran_transfer WHERE status='Menunggu'") === '1', 'Tanpa mutasi saat ditolak (transfer tetap Menunggu)');
} catch (e) {
  gagal++;
  console.log('[gagal] Pengecualian: ' + (e?.stack || e));
} finally {
  pulihkan();
  await browser.close();
}
console.log(`\nRingkasan: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
