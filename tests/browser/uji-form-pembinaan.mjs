// UI regression with controlled API responses. Does not prove server/native behavior.
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdirSync } from 'node:fs';
const app = process.env.MOBILE_URL ?? 'http://127.0.0.1:8082';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(app)) throw Error('Localhost only');
const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 375, height: 900 }, timezoneId: 'Asia/Jakarta' });
const caps = { 'v3.pelanggaran.kelola': {}, 'v3.konseling.kelola': {} };
const profile = { id: 123, name: 'Pembimbing Uji', username: 'test', roles: ['pengurus'], guru: null };
const catalogs = Array.from({ length: 60 }, (_, i) => ({ id: i + 1, nama: `Katalog ${String(i + 1).padStart(2, '0')}`, tingkat: 'Ringan', poin_default: 5 }));
let denied = false, available = true, holdMutation = false, releaseMutation;
const writes = [], pages = [];
const row = { id: 500, santri_id: 1, tahun_ajaran_id: 1, santri_nama: 'Santri Uji', status: 'Dibuka', version: 1, tujuan: 'Tujuan uji', kerahasiaan: 'Internal' };
await context.addInitScript(() => sessionStorage.setItem('alhasan_teacher_api_token', 'synthetic-test-token'));
await context.route('**/*', async route => {
  const req = route.request(), url = new URL(req.url());
  if (!url.pathname.startsWith('/api/v1/')) return url.origin === app ? route.continue() : route.abort();
  const reply = (data, status = 200) => route.fulfill({ status, contentType: 'application/json', headers: { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*' }, body: JSON.stringify({ success: status < 400, data: status < 400 ? data : null, error: status >= 400 ? { code: 'TEST', message: 'Akses ditolak.' } : null }) });
  if (req.method() === 'OPTIONS') return reply(null);
  const path = url.pathname.replace('/api/v1', '');
  if (path === '/profile') return reply(profile);
  if (path === '/v3/capabilities') return reply({ capabilities: caps, operasional_tersedia: true });
  if (path === '/v3/mobile/options') {
    if (denied) return reply(null, 403);
    const page = Number(url.searchParams.get('page') ?? 1); pages.push(page);
    return reply({ santri: page === 1 ? [{ santri_id: 1, tahun_ajaran_id: 1, nama: 'Santri Uji' }] : [], katalog: available ? catalogs.slice((page - 1) * 25, page * 25) : [], dapat_mencatat: true });
  }
  if (req.method() === 'POST') {
    writes.push(JSON.parse(req.postData()));
    if (holdMutation) await new Promise(resolve => { releaseMutation = resolve; });
    return reply(path.includes('konseling') ? { kasus: row } : { pelanggaran: row });
  }
  if (path === '/v3/pelanggaran/500') return reply({ pelanggaran: { ...row, uraian: 'Uraian uji' }, total_poin: 5, rekomendasi: [], konseling: [], murobi: [] });
  if (path === '/v3/konseling/kasus/500') return reply({ kasus: row, sesi: [], sesi_aktif: [], catatan_murobi: [] });
  return reply({ count: 0, jumlah: 0, rows: [] });
});
const page = await context.newPage();
page.setDefaultTimeout(15000);
const button = name => page.getByRole('button', { name, exact: true });
const visibility = state => page.evaluate(s => {
  Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => s });
  Object.defineProperty(document, 'hidden', { configurable: true, get: () => s === 'hidden' });
  document.dispatchEvent(new Event('visibilitychange'));
}, state);
const choose = async (label, choice, search) => {
  await button(label).click();
  if (search) await page.getByLabel(`Cari ${label.toLowerCase()}`, { exact: true }).fill(search);
  await button(choice).click();
};
const ready = () => button('Santri dalam cakupan aktif').waitFor();
const resume = async () => { await visibility('hidden'); await visibility('visible'); await ready(); };
let count = 0;
const check = (value, name) => { assert.ok(value, name); console.log(`[lulus] ${++count}. ${name}`); };
try {
  await page.goto(app + '/pembinaan');
  await button('Catat pelanggaran').click(); await ready();
  check(pages.includes(3), 'Semua 60 katalog dimuat melewati batas 25');
  check(await button('Pilihan berikutnya').count() === 0 && await button('Katalog 01 · Ringan · 5 poin').count() === 0, 'Formulir ringkas tanpa halaman/tumpukan tombol katalog');
  await choose('Santri dalam cakupan aktif', 'Santri Uji');
  await choose('Jenis pelanggaran', 'Katalog 60 · Ringan · 5 poin', '60');
  await page.getByLabel('Waktu kejadian', { exact: true }).fill('2026-10-04T00:05');
  await page.getByLabel('Uraian kejadian', { exact: true }).fill('Uraian uji');
  check(await button('Simpan').isEnabled(), 'Katalog halaman ketiga dapat dipilih dan disimpan');
  await page.waitForTimeout(350);
  await page.screenshot({ path: '/tmp/form-pembinaan-sebelum.png' });
  await resume();
  check(await page.getByLabel('Uraian kejadian', { exact: true }).inputValue() === 'Uraian uji' && await page.getByLabel('Waktu kejadian', { exact: true }).inputValue() === '2026-10-04T00:05' && await button('Simpan').isEnabled(), 'Isian dan pilihan tetap setelah screenshot dan latar/aktif');
  check((await button('Jenis pelanggaran').innerText()).includes('Katalog 60'), 'Pilihan katalog bertahan setelah muat ulang akses');
  check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Formulir 375px tanpa luapan');
  holdMutation = true;
  await button('Simpan').click();
  await page.waitForTimeout(100); assert.ok(releaseMutation);
  await visibility('hidden'); releaseMutation(); holdMutation = false;
  await page.waitForTimeout(200); await visibility('visible'); await page.waitForURL('**/pembinaan/detail?**');
  // Audit Claude Code: formulir yang sudah tersimpan tidak boleh tampil lagi untuk diubah lalu terkirim sebagai catatan kedua.
  check(writes.length === 1, 'Respons simpan saat latar dibuka setelah akses dimuat ulang, satu POST tanpa kirim ulang');
  check(writes[0].waktu_kejadian === '2026-10-04T00:05', 'Waktu lokal terkirim tanpa pergeseran UTC');
  const fillViolation = async (catalog, text) => {
    await page.goto(app + '/pembinaan/buat?jenis=pelanggaran'); await ready();
    await choose('Santri dalam cakupan aktif', 'Santri Uji'); await choose('Jenis pelanggaran', `Katalog ${catalog} · Ringan · 5 poin`, catalog);
    await page.getByLabel('Waktu kejadian', { exact: true }).fill('2026-10-04T09:00'); await page.getByLabel('Uraian kejadian', { exact: true }).fill(text);
    holdMutation = true; await button('Simpan').click(); await page.waitForTimeout(100); assert.ok(holdMutation && releaseMutation);
  };
  await fillViolation('02', 'Respons terlambat'); await resume(); releaseMutation(); holdMutation = false;
  await page.waitForURL('**/pembinaan/detail?**');
  check(writes.length === 2, 'Respons yang tiba setelah layar aktif kembali tetap dibuka tanpa POST ulang');
  await fillViolation('03', 'Akses dicabut'); await visibility('hidden'); releaseMutation(); holdMutation = false; await page.waitForTimeout(200);
  denied = true; await visibility('visible'); await button('Coba lagi').waitFor();
  denied = false; await button('Coba lagi').click(); await ready();
  check(new URL(page.url()).pathname.endsWith('/pembinaan/buat') && await page.getByLabel('Uraian kejadian', { exact: true }).inputValue() === '', 'Akses ditolak saat kembali: tidak dialihkan ke catatan dan draf dihapus');
  await page.goto(app + '/pembinaan/buat?jenis=kasus'); await ready();
  await choose('Santri dalam cakupan aktif', 'Santri Uji');
  await page.getByLabel('Tujuan pendampingan', { exact: true }).fill('Tujuan uji');
  check(await button('Simpan').isDisabled(), 'Simpan menunggu pilihan kerahasiaan eksplisit');
  await choose('Kerahasiaan (wajib dipilih)', 'Internal'); await resume();
  check((await button('Kerahasiaan (wajib dipilih)').innerText()).includes('Internal') && await page.getByLabel('Tujuan pendampingan', { exact: true }).inputValue() === 'Tujuan uji', 'Kerahasiaan dan tujuan bertahan setelah layar kembali aktif');
  await button('Simpan').click(); await page.waitForURL('**/pembinaan/detail?**');
  check(writes.at(-1).kerahasiaan === 'Internal', 'Pilihan kerahasiaan dikirim sesuai dropdown');
  await page.getByLabel('Jadwal sesi baru / jadwal ulang', { exact: true }).fill('2026-10-05T14:30');
  await page.getByLabel('Ringkasan internal sesi / ringkasan penutupan', { exact: true }).fill('Ringkasan uji');
  await visibility('hidden'); await visibility('visible'); await button('Tambahkan sesi baru').waitFor();
  check(await page.getByLabel('Jadwal sesi baru / jadwal ulang', { exact: true }).inputValue() === '2026-10-05T14:30' && await page.getByLabel('Ringkasan internal sesi / ringkasan penutupan', { exact: true }).inputValue() === 'Ringkasan uji', 'Jadwal dan isian sesi bertahan di latar');
  await page.goto(app + '/pembinaan/buat?jenis=pelanggaran'); await ready();
  await choose('Santri dalam cakupan aktif', 'Santri Uji');
  await choose('Jenis pelanggaran', 'Katalog 01 · Ringan · 5 poin', '01');
  await page.getByLabel('Uraian kejadian', { exact: true }).fill('Draf privat');
  available = false; await resume();
  check(!(await button('Jenis pelanggaran').innerText()).includes('Katalog 01') && await button('Simpan').isDisabled(), 'Katalog yang dicabut tidak dapat dikirim ulang setelah resume');
  denied = true; await visibility('hidden'); await visibility('visible'); await button('Coba lagi').waitFor();
  denied = false; available = true; await button('Coba lagi').click(); await ready();
  check(await page.getByLabel('Uraian kejadian', { exact: true }).inputValue() === '', 'Penolakan akses menghapus draf privat sebelum akses dipulihkan');
  await page.getByLabel('Uraian kejadian', { exact: true }).fill('Hapus saat keluar');
  await page.goBack(); await page.goForward(); await ready();
  check(await page.getByLabel('Uraian kejadian', { exact: true }).inputValue() === '', 'Keluar formulir menghapus draf');
  console.log(`${count} pemeriksaan UI lulus; API dikontrol, bukan bukti server/perangkat fisik.`);
} catch (error) {
  mkdirSync('/tmp/form-pembinaan-failure', { recursive: true });
  await page.screenshot({ path: '/tmp/form-pembinaan-failure/screen.png' });
  console.log((await page.locator('body').innerText()).slice(-3000)); throw error;
} finally { await browser.close(); }
