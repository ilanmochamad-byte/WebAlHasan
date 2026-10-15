// Redesain tampilan Fase 2 — Jadwal per pekan dan Perizinan di Expo web (390 pt).
// Prasyarat: API lokal 127.0.0.1:8940 dengan DB *_test dan Expo web di
// 127.0.0.1:8082 yang memakai API itu. Semua data dari API nyata, kecuali
// skenario "sesi hari ini" yang menyuntikkan satu sesi ke respons pekan
// berjalan (data uji tidak memiliki sesi hari ini).
// Pemakaian: node tests/browser/uji-redesain-fase2.mjs <folder-tangkapan>
import { chromium } from 'playwright';

const app = 'http://127.0.0.1:8082';
const api = 'http://127.0.0.1:8940';
const out = process.argv[2];
const cors = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*' };
const hasil = [];
const cek = (nama, lulus, info = '') => hasil.push(`${lulus ? 'LULUS' : 'GAGAL'} ${nama}${info ? ' — ' + info : ''}`);
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const hariIni = iso(new Date());

async function sesi(browser, user, { sisipSesiHariIni = false } = {}) {
  const context = await browser.newContext({ viewport: { width: 390, height: 900 }, timezoneId: 'Asia/Jakarta' });
  const permintaan = [];
  await context.route('**/*', async (route) => {
    const req = route.request();
    const url = new URL(req.url());
    if (url.origin === app) return route.continue();
    if (url.origin !== api) return route.abort();
    if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: cors });
    permintaan.push(url.pathname + url.search);
    const res = await route.fetch();
    if (sisipSesiHariIni && url.pathname.endsWith('/schedules') && url.searchParams.get('date_from') <= hariIni && url.searchParams.get('date_to') >= hariIni) {
      const json = await res.json();
      const contoh = json.data.items[0];
      if (contoh) {
        json.data.items.unshift({ ...contoh, id: 990001, occurrence_date: hariIni, start_time: '07:30', end_time: '09:00', meeting: null });
        json.data.pagination.total += 1;
      }
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
  return { context, page, errors, permintaan };
}

async function tutup(context) {
  await context.unrouteAll({ behavior: 'ignoreErrors' });
  await context.close();
}

const browser = await chromium.launch();

// Jadwal — admin, data nyata.
{
  const { context, page, errors, permintaan } = await sesi(browser, 'sbx_admin');
  await page.goto(app + '/schedules');
  await page.getByRole('button', { name: 'Pekan berikutnya' }).waitFor({ timeout: 15000 });
  await page.getByText('SESI', { exact: false }).first().waitFor({ timeout: 15000 });
  await page.screenshot({ path: `${out}/web-jadwal-pekan.png`, fullPage: true });
  const sebelum = permintaan.filter((p) => p.startsWith('/api/v1/schedules?')).length;
  const minggu = page.getByRole('button', { name: /^Minggu, 18 Oktober/ });
  let duaSesi = false;
  if (await minggu.count()) {
    await minggu.click();
    await page.getByText('MINGGU, 18 OKTOBER · 2 SESI').waitFor({ timeout: 8000 }).then(() => (duaSesi = true), () => {});
    await page.screenshot({ path: `${out}/web-jadwal-minggu.png`, fullPage: true });
  }
  const sesudah = permintaan.filter((p) => p.startsWith('/api/v1/schedules?')).length;
  cek('jadwal: memilih tanggal pada pekan yang sama menampilkan sesi tanggal itu tanpa memuat ulang', duaSesi && sesudah === sebelum, `permintaan ${sebelum}→${sesudah}`);
  const status = await page.getByText('Belum dimulai').count();
  cek('jadwal: kartu sesi mendatang menampilkan status teks "Belum dimulai"', status >= 2, `jumlah ${status}`);
  await page.getByRole('button', { name: 'Pekan berikutnya' }).click();
  await page.waitForTimeout(1500);
  const terakhir = permintaan.filter((p) => p.startsWith('/api/v1/schedules?')).at(-1) ?? '';
  cek('jadwal: pekan berikutnya memuat rentang Senin–Minggu berikutnya', terakhir.includes('date_from=2026-10-19') && terakhir.includes('date_to=2026-10-25'), terakhir);
  await page.getByRole('radio', { name: 'Rentang kustom' }).click();
  const panel = await page.getByText('Rentang tanggal').isVisible();
  const terapkan = page.getByRole('button', { name: 'Terapkan filter' });
  await terapkan.click();
  await page.getByText('tugas ditemukan', { exact: false }).waitFor({ timeout: 15000 });
  await page.screenshot({ path: `${out}/web-jadwal-kustom.png`, fullPage: true });
  cek('jadwal: rentang kustom tetap tersedia dan menampilkan daftar berkelompok', panel);
  cek('jadwal admin: tidak ada pageerror', errors.length === 0, errors.join('; '));
  await tutup(context);
}

// Jadwal — sesi hari ini (disuntikkan) menampilkan penanda Absen.
{
  const { context, page, errors } = await sesi(browser, 'sbx_admin', { sisipSesiHariIni: true });
  await page.goto(app + '/schedules');
  await page.getByText('Belum diabsen').waitFor({ timeout: 15000 });
  const absen = await page.getByRole('button', { name: /07:30 sampai 09:00, Belum diabsen, ketuk untuk absen/ }).count();
  await page.screenshot({ path: `${out}/web-jadwal-hari-ini.png`, fullPage: true });
  cek('jadwal: sesi hari ini yang belum diabsen tampil "Belum diabsen" + penanda "Absen"', absen === 1 && (await page.getByText('Absen', { exact: true }).count()) === 1);
  cek('jadwal hari ini: tidak ada pageerror', errors.length === 0, errors.join('; '));
  await tutup(context);
}

// Perizinan — admin.
{
  const { context, page, errors, permintaan } = await sesi(browser, 'sbx_admin');
  await page.goto(app + '/perizinan');
  const pilAntrean = page.getByRole('radio', { name: /^Perlu tindakan/ });
  await pilAntrean.waitFor({ timeout: 15000 });
  await page.getByText('pengajuan', { exact: false }).first().waitFor({ timeout: 15000 });
  await page.waitForTimeout(1000);
  await page.screenshot({ path: `${out}/web-izin-antrean.png` });
  const label = await pilAntrean.innerText();
  cek('perizinan: pil "Perlu tindakan" memuat jumlah antrean', /Perlu tindakan · \d+/.test(label), label);
  const tinjau = await page.getByText(/Tinjau & putuskan|Tetapkan murobi/).count();
  cek('perizinan: kartu antrean memberi petunjuk tindakan, tanpa tombol Setujui/Tolak di kartu', tinjau > 0 && (await page.getByRole('button', { name: 'Setujui' }).count()) === 0, `petunjuk ${tinjau}`);
  await page.getByRole('radio', { name: 'Disetujui' }).click();
  await page.waitForTimeout(2000);
  const req = permintaan.filter((p) => p.startsWith('/api/v1/izin/pengajuan?')).at(-1) ?? '';
  const semuaKartu = await page.getByRole('button', { name: /^Pengajuan \d+ untuk / }).count();
  const kartuDisetujui = await page.getByRole('button', { name: /^Pengajuan \d+ untuk .*, status Disetujui/ }).count();
  const statusLain = semuaKartu - kartuDisetujui;
  await page.screenshot({ path: `${out}/web-izin-disetujui.png` });
  cek('perizinan: pil Disetujui memakai parameter status dan hanya menampilkan status Disetujui', req.includes('status=Disetujui') && semuaKartu > 0 && statusLain === 0, `${req} · kartu ${semuaKartu}`);
  const tombol = page.getByRole('button', { name: 'Ajukan izin baru' });
  const adaTombol = await tombol.isVisible();
  await tombol.click();
  await page.waitForURL('**/izin/buat**', { timeout: 15000 });
  cek('perizinan: tombol tetap "Ajukan izin baru" tampil dan membuka formulir', adaTombol);
  cek('perizinan admin: tidak ada pageerror', errors.length === 0, errors.join('; '));
  await tutup(context);
}

// Perizinan — orang tua (baca saja).
{
  const { context, page, errors } = await sesi(browser, 'sbx_ortu_a');
  await page.goto(app + '/perizinan');
  await page.getByRole('radio', { name: 'Semua' }).waitFor({ timeout: 15000 });
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${out}/web-izin-ortu.png` });
  const tanpaAntrean = (await page.getByRole('radio', { name: /^Perlu tindakan/ }).count()) === 0;
  const tanpaTombol = (await page.getByRole('button', { name: 'Ajukan izin baru' }).count()) === 0;
  cek('perizinan orang tua: tanpa pil antrean dan tanpa tombol pengajuan', tanpaAntrean && tanpaTombol);
  cek('perizinan orang tua: tidak ada pageerror', errors.length === 0, errors.join('; '));
  await tutup(context);
}

await browser.close();
console.log(hasil.join('\n'));
process.exitCode = hasil.some((h) => h.startsWith('GAGAL')) ? 1 : 0;
