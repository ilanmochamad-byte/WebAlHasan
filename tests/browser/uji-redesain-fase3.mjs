// Redesain tampilan Fase 3 — Laporan kehadiran dan Profil di Expo web (390 pt).
// Prasyarat: API lokal 127.0.0.1:8940 dengan DB *_test dan Expo web di
// 127.0.0.1:8082 yang memakai API itu. Semua data dari API nyata.
// Pemakaian: node tests/browser/uji-redesain-fase3.mjs <folder-tangkapan>
import { chromium } from 'playwright';

const app = 'http://127.0.0.1:8082';
const api = 'http://127.0.0.1:8940';
const out = process.argv[2];
const cors = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*' };
const hasil = [];
const cek = (nama, lulus, info = '') => hasil.push(`${lulus ? 'LULUS' : 'GAGAL'} ${nama}${info ? ' — ' + info : ''}`);

async function sesi(browser, user, colorScheme = 'light') {
  const context = await browser.newContext({ viewport: { width: 390, height: 900 }, timezoneId: 'Asia/Jakarta', colorScheme });
  const permintaan = [];
  await context.route('**/*', async (route) => {
    const req = route.request();
    const url = new URL(req.url());
    if (url.origin === app) return route.continue();
    if (url.origin !== api) return route.abort();
    if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: cors });
    permintaan.push(url.pathname + url.search);
    const res = await route.fetch();
    return route.fulfill({ response: res, headers: { ...res.headers(), ...cors } });
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('dialog', (d) => void d.dismiss());
  await page.goto(app + '/login');
  await page.getByPlaceholder('Masukkan username').fill(user);
  await page.getByPlaceholder('Masukkan password').fill('Sandbox#123');
  await page.getByPlaceholder('Masukkan password').press('Enter');
  await page.getByText('Assalamu', { exact: false }).waitFor({ timeout: 15000 });
  return { context, page, errors, permintaan };
}
async function tutup(context) {
  await context.unrouteAll({ behavior: 'ignoreErrors' });
  await context.close();
}

const browser = await chromium.launch();

// Laporan — admin, rentang diperluas lewat filter agar berisi data nyata.
{
  const { context, page, errors, permintaan } = await sesi(browser, 'sbx_admin');
  await page.getByRole('button', { name: 'Laporan', exact: true }).click();
  await page.getByText('Laporan kehadiran', { exact: true }).waitFor({ timeout: 15000 });
  const subjudul = await page.getByText('Rekap absensi dari sesi yang Anda ajar').isVisible();
  cek('laporan: judul "Laporan kehadiran" dan subjudul penjelas tampil', subjudul);
  await page.getByRole('button', { name: 'Buka filter' }).click();
  const tanggal = page.locator('input[type="date"]');
  await tanggal.first().waitFor({ timeout: 10000 });
  await tanggal.first().fill('2026-01-01');
  await page.getByRole('button', { name: 'Terapkan filter' }).click();
  const ok = await page.waitForFunction(() => document.body.innerText.includes('31 status = 31 baris detail'), null, { timeout: 15000 }).then(() => true, () => false);
  const req = permintaan.filter((p) => p.startsWith('/api/v1/reports?')).at(-1) ?? '';
  await page.screenshot({ path: `${out}/web-laporan.png`, fullPage: true });
  cek('laporan: filter rentang memakai date_from=2026-01-01 dan kartu ringkasan menampilkan rekap yang konsisten', ok && req.includes('date_from=2026-01-01'), req);
  const cetak = await page.getByRole('button', { name: 'Cetak PDF' }).count();
  const simpan = await page.getByRole('button', { name: 'Simpan PDF' }).count();
  cek('laporan: tombol Cetak PDF dan Simpan PDF ada di kartu ringkasan', cetak === 1 && simpan === 1);
  const ringkasan = await page.getByText(/^\d+ pertemuan · \d+ baris kehadiran · /).count();
  cek('laporan: ringkasan jadwal tampil sebagai baris berketerangan', ringkasan >= 1, `baris ${ringkasan}`);
  const detail = page.getByRole('button', { name: /^Buka detail pertemuan / }).first();
  const adaDetail = await detail.count();
  await detail.click();
  await page.waitForURL('**/report/**', { timeout: 15000 }).catch(() => {});
  cek('laporan: kartu detail kehadiran membuka detail pertemuan', adaDetail === 1 && page.url().includes('/report/'), page.url());
  await page.goBack();
  await page.getByText('Laporan kehadiran', { exact: true }).waitFor({ timeout: 15000 });
  await page.getByRole('button', { name: 'Kembali' }).first().click();
  await page.getByText('Assalamu', { exact: false }).waitFor({ timeout: 15000 });
  cek('laporan: tombol Kembali kembali ke Beranda', new URL(page.url()).pathname === '/', page.url());
  cek('laporan: tidak ada pageerror', errors.length === 0, errors.join('; '));
  await tutup(context);
}

// Laporan — mode gelap.
{
  const { context, page, errors } = await sesi(browser, 'sbx_admin', 'dark');
  await page.goto(app + '/reports');
  await page.getByText('Laporan kehadiran', { exact: true }).waitFor({ timeout: 15000 });
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${out}/web-laporan-gelap.png`, fullPage: true });
  cek('laporan gelap: tampil tanpa pageerror', errors.length === 0, errors.join('; '));
  await tutup(context);
}

// Profil — admin (satu cakupan) dan orang tua.
for (const [user, harapRole, harapCakupan] of [
  ['sbx_admin', 'admin', 'Admin — satu cakupan perizinan'],
  ['sbx_ortu_a', 'orang_tua', 'Orang Tua — satu cakupan perizinan'],
  ['sbx_guru_biasa', 'guru', null],
]) {
  const { context, page, errors } = await sesi(browser, user);
  await page.goto(app + '/profil');
  await page.getByText('Akun & pemberitahuan', { exact: false }).waitFor({ timeout: 15000 });
  await page.waitForTimeout(800);
  await page.screenshot({ path: `${out}/web-profil-${user}.png`, fullPage: true });
  const role = await page.getByText(harapRole, { exact: true }).count();
  const cakupan = harapCakupan ? await page.getByText(harapCakupan).count() : await page.getByText('Cakupan perizinan').count();
  cek(`profil ${user}: label peran dan baris cakupan sesuai`, role === 1 && (harapCakupan ? cakupan === 1 : cakupan === 0), `peran ${role}, cakupan ${cakupan}`);
  const ket = await page.getByText('Atur pemberitahuan di perangkat ini').count() + await page.getByText('Semua pemberitahuan yang masuk').count();
  cek(`profil ${user}: baris menu berketerangan tampil`, ket === 2);
  await page.getByRole('button', { name: /^Perangkat & push/ }).click();
  await page.waitForURL('**/notifikasi/perangkat', { timeout: 15000 }).catch(() => {});
  const keP = page.url().includes('/notifikasi/perangkat');
  await page.goBack();
  await page.getByText('Akun & pemberitahuan', { exact: false }).waitFor({ timeout: 15000 });
  cek(`profil ${user}: baris Perangkat & push membuka layar perangkat`, keP);
  if (user === 'sbx_guru_biasa') {
    await page.getByRole('button', { name: 'Keluar dari aplikasi' }).click();
    const keluar = await page.getByPlaceholder('Masukkan username').waitFor({ timeout: 15000 }).then(() => true, () => false);
    cek('profil: tombol Keluar meminta konfirmasi (dibatalkan) dan tetap di Profil', !keluar);
  }
  cek(`profil ${user}: tidak ada pageerror`, errors.length === 0, errors.join('; '));
  await tutup(context);
}

await browser.close();
console.log(hasil.join('\n'));
process.exitCode = hasil.some((h) => h.startsWith('GAGAL')) ? 1 : 0;
