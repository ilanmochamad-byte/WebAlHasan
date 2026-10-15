// Redesain tampilan Fase 2 — Perizinan untuk akun dengan DUA cakupan nyata.
// Prasyarat: fixture `fixture-redesain-multi-cakupan.sh pasang` pada DB *_test
// (sbx_murobi_a = pengurus + murobi), API lokal 127.0.0.1:8940, dan Expo web
// 127.0.0.1:8082. Tidak ada respons yang disuntik: semua dari API nyata.
// Pemakaian: node tests/browser/uji-redesain-fase2-cakupan.mjs <folder-tangkapan>
import { chromium } from 'playwright';

const app = 'http://127.0.0.1:8082';
const api = 'http://127.0.0.1:8940';
const out = process.argv[2];
const cors = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*' };
const hasil = [];
const cek = (nama, lulus, info = '') => hasil.push(`${lulus ? 'LULUS' : 'GAGAL'} ${nama}${info ? ' — ' + info : ''}`);

const browser = await chromium.launch();
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
  return route.fulfill({ response: res, headers: { ...res.headers(), ...cors } });
});
const page = await context.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
const izinTerakhir = () => permintaan.filter((p) => /\/api\/v1\/izin\/(antrean|pengajuan)\?/.test(p)).at(-1) ?? '';
const tungguIzin = async (pola) => {
  for (let i = 0; i < 40; i += 1) {
    if (pola.test(izinTerakhir())) return true;
    await page.waitForTimeout(250);
  }
  return false;
};
const namaKartu = async () =>
  (await page.getByRole('button', { name: /^Pengajuan \d+ untuk / }).evaluateAll((els) => els.map((e) => e.getAttribute('aria-label'))));

await page.goto(app + '/login');
await page.getByPlaceholder('Masukkan username').fill('sbx_murobi_a');
await page.getByPlaceholder('Masukkan password').fill('Sandbox#123');
await page.getByPlaceholder('Masukkan password').press('Enter');
await page.getByText('Assalamu', { exact: false }).waitFor({ timeout: 15000 });

await page.goto(app + '/perizinan');
await page.getByRole('radio', { name: 'Pengurus' }).waitFor({ timeout: 15000 });
await page.getByText('Pengurus — pengajuan yang Anda buat').waitFor({ timeout: 15000 });
const duaMode = (await page.getByRole('radio', { name: 'Pengurus' }).count()) === 1 && (await page.getByRole('radio', { name: 'Murobi' }).count()) === 1;
const awal = izinTerakhir();
await page.screenshot({ path: `${out}/web-cakupan-pengurus.png` });
cek('dua cakupan: pengganti mode menampilkan Pengurus dan Murobi; awal memakai mode pengurus', duaMode && awal.includes('mode=pengurus'), awal);
const tombolPengurus = await page.getByRole('button', { name: 'Ajukan izin baru' }).count();

// Pindah ke murobi.
await page.getByRole('radio', { name: 'Murobi' }).click();
const keMurobi = await tungguIzin(/mode=murobi/);
await page.getByText('Murobi — pengajuan yang diarahkan kepada Anda').waitFor({ timeout: 15000 });
await page.waitForTimeout(800);
await page.screenshot({ path: `${out}/web-cakupan-murobi-antrean.png` });
cek('pindah ke murobi: permintaan memakai mode=murobi dan label cakupan berganti', keMurobi, izinTerakhir());
const antreanKosong = await page.getByText('Tidak ada antrean tindakan').isVisible();
cek('murobi: antrean kosong ditampilkan sebagai keadaan kosong (data nyata 0)', antreanKosong);

// Semua + paginasi pada mode murobi.
await page.getByRole('radio', { name: 'Semua' }).click();
await tungguIzin(/\/pengajuan\?.*mode=murobi.*page=1/);
await page.getByText('46 pengajuan').waitFor({ timeout: 15000 });
const hal1 = await namaKartu();
await page.getByRole('button', { name: 'Berikutnya' }).click();
const keHal2 = await tungguIzin(/mode=murobi.*page=2/);
await page.getByText('Halaman 2 dari 3').waitFor({ timeout: 15000 });
const hal2 = await namaKartu();
cek('murobi semua: paginasi ke halaman 2 memakai mode=murobi dan kartunya berbeda', keHal2 && hal1.length > 0 && hal2.length > 0 && hal1[0] !== hal2[0], `${hal1.length}/${hal2.length} kartu`);

// Filter status tetap pada mode murobi dan kembali ke halaman 1.
await page.getByRole('radio', { name: 'Disetujui' }).click();
const filter = await tungguIzin(/mode=murobi.*page=1.*status=Disetujui|status=Disetujui.*mode=murobi/);
await page.waitForTimeout(1200);
const kartuFilter = await namaKartu();
const semuaDisetujui = kartuFilter.every((n) => n.includes('status Disetujui'));
cek('murobi + Disetujui: mode tetap murobi, halaman kembali 1, semua kartu Disetujui', filter && semuaDisetujui, `${izinTerakhir()} · ${kartuFilter.length} kartu`);

// Pencarian pada mode murobi.
await page.getByRole('radio', { name: 'Semua' }).click();
await page.waitForTimeout(800);
const kataKunci = (kartuFilter[0] ?? hal1[0] ?? '').replace(/^Pengajuan \d+ untuk /, '').split(',')[0];
await page.getByPlaceholder('Cari santri, NIS, atau alasan').fill(kataKunci);
await page.getByPlaceholder('Cari santri, NIS, atau alasan').press('Enter');
const cari = await tungguIzin(new RegExp(`mode=murobi.*q=${encodeURIComponent(kataKunci).replace(/%20/g, '(\\+|%20)')}`));
await page.waitForTimeout(1200);
const kartuCari = await namaKartu();
await page.screenshot({ path: `${out}/web-cakupan-murobi-cari.png` });
cek('murobi + pencarian: q dikirim bersama mode=murobi dan hasil memuat nama yang dicari', cari && kartuCari.length > 0 && kartuCari.every((n) => n.includes(kataKunci)), `"${kataKunci}" · ${kartuCari.length} kartu`);

// Detail memakai mode yang dipilih.
await page.getByRole('button', { name: /^Pengajuan \d+ untuk / }).first().click();
await page.waitForURL(/\/izin\/\d+.*mode=murobi/, { timeout: 15000 }).catch(() => {});
await page.waitForTimeout(1500);
const detailReq = permintaan.filter((p) => /\/api\/v1\/izin\/pengajuan\/\d+\?/.test(p)).at(-1) ?? '';
cek('detail: URL dan permintaan detail memakai mode=murobi', page.url().includes('mode=murobi') && detailReq.includes('mode=murobi'), `${page.url()} · ${detailReq}`);
await page.screenshot({ path: `${out}/web-cakupan-murobi-detail.png` });
await page.goBack();

// Kembali ke pengurus.
await page.getByRole('radio', { name: 'Pengurus' }).click();
const balik = await tungguIzin(/mode=pengurus/);
await page.getByText('Pengurus — pengajuan yang Anda buat').waitFor({ timeout: 15000 });
cek('kembali ke pengurus: mode=pengurus dan tombol pengajuan sesuai kemampuan akun', balik && tombolPengurus === 1, izinTerakhir());
cek('dua cakupan: tidak ada pageerror', errors.length === 0, errors.join('; '));

await context.unrouteAll({ behavior: 'ignoreErrors' });
await browser.close();
console.log(hasil.join('\n'));
process.exitCode = hasil.some((h) => h.startsWith('GAGAL')) ? 1 : 0;
