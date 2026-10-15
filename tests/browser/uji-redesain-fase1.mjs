// Redesain tampilan Fase 1 — koreksi temuan audit 2–4 di Expo web (390 pt).
// Prasyarat: API lokal 127.0.0.1:8940 dengan DB *_test dan Expo web di
// 127.0.0.1:8082 yang memakai API itu. Skenario tanpa menu/memuat/gagal
// memakai respons kemampuan yang dikendalikan; selebihnya API nyata.
// Bagian "fondasi" butuh rute sementara src/app/pratinjau-redesain.tsx
// (tidak di-commit); tanpa rute itu bagian tersebut dilewati.
// Pemakaian: node tests/browser/uji-redesain-fase1.mjs <folder-tangkapan>
import { chromium } from 'playwright';

const app = 'http://127.0.0.1:8082';
const api = 'http://127.0.0.1:8940';
const out = process.argv[2];
const cors = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*' };
const hasil = [];
const cek = (nama, lulus, info = '') => hasil.push(`${lulus ? 'LULUS' : 'GAGAL'} ${nama}${info ? ' — ' + info : ''}`);

function kosongkanIzin(json) {
  const p = json?.data?.profile ?? json?.data;
  if (p?.capabilities) p.capabilities = { ...p.capabilities, list: [], default_mode: null };
  return json;
}

async function sesi(browser, user, { pembinaan = 'asli', tanpaIzin = false } = {}) {
  const context = await browser.newContext({ viewport: { width: 390, height: 900 }, timezoneId: 'Asia/Jakarta' });
  await context.route('**/*', async (route) => {
    const req = route.request();
    const url = new URL(req.url());
    if (url.origin === app) return route.continue();
    if (url.origin !== api) return route.abort();
    if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: cors });
    if (url.pathname.endsWith('/v3/capabilities') && pembinaan !== 'asli') {
      if (pembinaan === 'gagal') return route.fulfill({ status: 500, headers: cors, contentType: 'application/json', body: JSON.stringify({ success: false, data: null, error: { code: 'TEST', message: 'Server uji gagal.' } }) });
      if (pembinaan === 'lambat') await new Promise((r) => setTimeout(r, 6000));
      return route.fulfill({ status: 200, headers: cors, contentType: 'application/json', body: JSON.stringify({ success: true, data: { capabilities: {}, operasional_tersedia: false }, error: null }) });
    }
    const res = await route.fetch();
    if (tanpaIzin && (url.pathname.endsWith('/auth/login') || url.pathname.endsWith('/profile'))) {
      const json = kosongkanIzin(await res.json());
      return route.fulfill({ response: res, headers: { ...res.headers(), ...cors }, body: JSON.stringify(json) });
    }
    return route.fulfill({ response: res, headers: { ...res.headers(), ...cors } });
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(app + '/login');
  await page.getByPlaceholder('Masukkan username').fill(user);
  await page.getByPlaceholder('Masukkan password').fill('Sandbox#123');
  await page.getByPlaceholder('Masukkan password').press('Enter');
  await page.getByText('Assalamu', { exact: false }).waitFor({ timeout: 15000 });
  return { context, page, errors };
}

const browser = await chromium.launch();

// Temuan 4 — akun tanpa menu sama sekali.
{
  const { context, page, errors } = await sesi(browser, 'sbx_ortu_b', { pembinaan: 'lambat', tanpaIzin: true });
  await page.waitForTimeout(1200);
  const memuat = await page.getByText('Memeriksa menu akun…').isVisible();
  const kosongTerlaluDini = await page.getByText('Akun ini belum memiliki menu').isVisible();
  await page.screenshot({ path: `${out}/web-tanpa-menu-memuat.png` });
  cek('tanpa menu: selagi akses pembinaan dimuat tampil "Memeriksa menu akun…" dan belum tampil pesan kosong', memuat && !kosongTerlaluDini);
  await page.getByText('Akun ini belum memiliki menu').waitFor({ timeout: 15000 });
  const lamaTidakTampil = !(await page.getByText('tidak memiliki jadwal mengajar', { exact: false }).isVisible());
  const tanpaUbin = (await page.getByText('Menu', { exact: true }).count()) === 0;
  await page.screenshot({ path: `${out}/web-tanpa-menu.png` });
  cek('tanpa menu: setelah selesai tampil pesan "Akun ini belum memiliki menu", tanpa kisi dan tanpa pesan jadwal lama', lamaTidakTampil && tanpaUbin);
  cek('tanpa menu: tidak ada pageerror', errors.length === 0, errors.join('; '));
  await context.unrouteAll({ behavior: 'ignoreErrors' });
  await context.close();
}
{
  const { context, page, errors } = await sesi(browser, 'sbx_ortu_b', { pembinaan: 'gagal', tanpaIzin: true });
  await page.getByRole('button', { name: 'Muat ulang akses pembinaan' }).waitFor({ timeout: 15000 });
  const tanpaPesanKosong = !(await page.getByText('Akun ini belum memiliki menu').isVisible());
  await page.screenshot({ path: `${out}/web-pembinaan-gagal.png` });
  cek('akses pembinaan gagal: tampil tombol muat ulang, bukan pesan tanpa menu', tanpaPesanKosong);
  cek('akses pembinaan gagal: tidak ada pageerror', errors.length === 0, errors.join('; '));
  await context.unrouteAll({ behavior: 'ignoreErrors' });
  await context.close();
}
// Regresi: orang tua dengan izin tetap melihat ubin dan keterangan tanpa jadwal.
{
  const { context, page } = await sesi(browser, 'sbx_ortu_a');
  await page.getByText('Menu', { exact: true }).waitFor({ timeout: 15000 });
  await page.waitForTimeout(1500);
  const ket = await page.getByText('tidak memiliki jadwal mengajar', { exact: false }).isVisible();
  const kosong = await page.getByText('Akun ini belum memiliki menu').isVisible();
  cek('regresi orang tua: ubin tampil, keterangan tanpa jadwal tetap ada, pesan tanpa menu tidak tampil', ket && !kosong);
  await context.unrouteAll({ behavior: 'ignoreErrors' });
  await context.close();
}
// Temuan 2 — pratinjau komponen fondasi (rute sementara).
{
  const { context, page, errors } = await sesi(browser, 'sbx_guru_biasa');
  await page.goto(app + '/pratinjau-redesain');
  const adaPratinjau = await page.getByText('Terpilih: 2026-10-14').waitFor({ timeout: 8000 }).then(() => true, () => false);
  if (!adaPratinjau) {
    hasil.push('LEWATI fondasi — rute pratinjau sementara tidak ada');
    await context.unrouteAll({ behavior: 'ignoreErrors' });
    await context.close();
    await browser.close();
    console.log(hasil.join('\n'));
process.exitCode = hasil.some((h) => h.startsWith('GAGAL')) ? 1 : 0;
    process.exit(hasil.some((h) => h.startsWith('GAGAL')) ? 1 : 0);
  }
  await page.screenshot({ path: `${out}/web-fondasi.png` });
  await page.getByRole('button', { name: /Jumat, 16 Oktober/ }).click();
  const pilih = await page.getByText('Terpilih: 2026-10-16').isVisible();
  await page.getByRole('button', { name: 'Pekan berikutnya' }).click();
  const maju = await page.getByText('Terpilih: 2026-10-23').isVisible();
  await page.getByRole('button', { name: 'Pekan sebelumnya' }).click();
  await page.getByRole('button', { name: 'Pekan sebelumnya' }).click();
  const mundur = await page.getByText('Terpilih: 2026-10-09').isVisible();
  const hariIni = await page.getByRole('button', { name: /15 Oktober, hari ini/ }).count();
  cek('deretan tanggal: pilih tanggal, pekan maju, pekan mundur, label hari ini', pilih && maju && mundur && hariIni === 0, `pekan 5–11 Okt tidak memuat 15 Okt (count=${hariIni})`);
  await page.getByRole('button', { name: 'Pekan berikutnya' }).click();
  const hariIniAda = await page.getByRole('button', { name: /Kamis, 15 Oktober, hari ini/ }).count();
  cek('deretan tanggal: hari ini diberi label aksesibilitas', hariIniAda === 1);
  const sub = await page.getByRole('button', { name: 'Perizinan, Ajukan & setujui izin santri' }).count();
  cek('baris menu berketerangan: judul + keterangan terbaca sebagai satu label', sub === 1);
  cek('fondasi: tidak ada pageerror', errors.length === 0, errors.join('; '));
  await context.unrouteAll({ behavior: 'ignoreErrors' });
  await context.close();
}
await browser.close();
console.log(hasil.join('\n'));
process.exitCode = hasil.some((h) => h.startsWith('GAGAL')) ? 1 : 0;
