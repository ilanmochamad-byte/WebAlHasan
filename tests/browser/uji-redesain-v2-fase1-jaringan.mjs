import fs from 'node:fs';
import { chromium } from 'playwright';

// Redesain tampilan aplikasi gelombang 2 Fase 1 — rekam permintaan API untuk
// alur yang sama (masuk, Beranda, Profil, Edit profil, Ganti password) agar
// baseline dan kode fase dapat dibandingkan. Hanya memakai pemilih yang ada
// di KEDUA versi. Yang direkam: metode, path, kunci query, kunci badan JSON
// (bukan nilainya — badan memuat password). Dijalankan dua kali pada DB yang
// dipulihkan ke keadaan sama; lalu `banding` membandingkan dua berkas.
//
//   RTV2_WEB=http://127.0.0.1:8971 RTV2_APP=http://127.0.0.1:8972 node uji-redesain-v2-fase1-jaringan.mjs rekam <keluaran.json>
//   node uji-redesain-v2-fase1-jaringan.mjs banding <baseline.json> <sesudah.json>
const [mode, a, b] = process.argv.slice(2);

if (mode === 'banding') {
  const x = JSON.parse(fs.readFileSync(a, 'utf8')), y = JSON.parse(fs.readFileSync(b, 'utf8'));
  let beda = 0, langkah = 0, permintaan = 0;
  for (const kunci of new Set([...Object.keys(x), ...Object.keys(y)])) {
    langkah++;
    const kiri = (x[kunci] ?? []).slice().sort(), kanan = (y[kunci] ?? []).slice().sort();
    permintaan += kanan.length;
    if (JSON.stringify(kiri) === JSON.stringify(kanan)) { console.log(`[sama] ${kunci} (${kiri.length} permintaan)`); continue; }
    beda++;
    console.log(`[BEDA] ${kunci}`);
    for (const p of kiri.filter(p => !kanan.includes(p))) console.log(`   hanya baseline: ${p}`);
    for (const p of kanan.filter(p => !kiri.includes(p))) console.log(`   hanya sesudah : ${p}`);
    if (kiri.length !== kanan.length) console.log(`   jumlah ${kiri.length} → ${kanan.length}`);
  }
  console.log(`\nHasil: ${langkah - beda} langkah sama / ${beda} berbeda; ${permintaan} permintaan dibandingkan`);
  process.exit(beda === 0 ? 0 : 1);
}

const web = process.env.RTV2_WEB, app = process.env.RTV2_APP;
if (mode !== 'rekam' || !a || ![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || ''))) throw Error('Pemakaian: rekam <keluaran.json>, hanya 127.0.0.1');
const PASS = 'Sandbox#123';
const AKUN = ['sbx_guru_biasa', 'sbx_pengurus_biasa', 'sbx_ortu_a', 'sbx_v6_bsa', 'sbx_v5_petugas', 'sbx_pengurus_b', 'sbx_admin'];
const hasil = {};
const luar = [];
const browser = await chromium.launch({ headless: true });

for (const user of AKUN) {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 }, timezoneId: 'Asia/Jakarta' });
  const page = await ctx.newPage();
  let langkah = `${user} 1-masuk-beranda`;
  await page.route('**/*', async route => {
    const req = route.request();
    const u = new URL(req.url());
    if (u.hostname !== '127.0.0.1') { luar.push(u.origin); return route.abort(); }
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    let badan = '';
    const mentah = req.postData();
    if (mentah) { try { badan = ' badan{' + Object.keys(JSON.parse(mentah)).sort().join(',') + '}'; } catch { badan = ' badan(bukan JSON)'; } }
    const query = [...u.searchParams.keys()].sort().join(',');
    (hasil[langkah] ??= []).push(`${req.method()} ${u.pathname.replace(/\/\d+(?=\/|$)/g, '/{id}')}${query ? ' ?' + query : ''}${badan}`);
    try {
      const response = await route.fetch();
      await route.fulfill({ response, headers: { ...response.headers(), 'Access-Control-Allow-Origin': app } });
    } catch { /* konteks ditutup */ }
  });
  const tenang = () => page.waitForTimeout(2500);
  await page.goto(app + '/login');
  await page.getByPlaceholder('Masukkan username').fill(user);
  await page.getByPlaceholder('Masukkan password').fill(PASS);
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
  await page.getByTestId('kartu-identitas').waitFor({ timeout: 30000 });
  await tenang();

  langkah = `${user} 2-profil`;
  await page.getByTestId('kartu-identitas').getByRole('button', { name: /Buka profil/ }).click();
  await page.waitForURL(u => u.pathname === '/profil');
  await page.getByText('Akun & pemberitahuan', { exact: false }).first().waitFor({ timeout: 20000 });
  await tenang();

  if (user === 'sbx_pengurus_biasa') {
    langkah = `${user} 3-edit-profil-buka`;
    await page.getByRole('button', { name: 'Edit profil' }).click();
    await page.waitForURL(u => u.pathname === '/akun/edit-profil');
    await page.getByText('Diatur admin (hanya baca)', { exact: false }).first().waitFor({ timeout: 20000 });
    await tenang();
    langkah = `${user} 4-edit-profil-simpan`;
    await page.getByLabel('Tempat lahir', { exact: true }).fill('Garut');
    await page.getByRole('button', { name: 'Simpan perubahan' }).click();
    await page.waitForURL(u => u.pathname === '/profil', { timeout: 20000 });
    await tenang();
    langkah = `${user} 5-ganti-password-buka`;
    await page.getByRole('button', { name: /^Ganti password/ }).click();
    await page.waitForURL(u => u.pathname === '/akun/ganti-password');
    await tenang();
    langkah = `${user} 6-ganti-password-simpan`;
    await page.getByLabel('Password saat ini', { exact: true }).fill(PASS);
    await page.getByLabel('Password baru', { exact: true }).fill('PasswordBaru2026');
    await page.getByLabel('Ulangi password baru', { exact: true }).fill('PasswordBaru2026');
    await page.getByRole('button', { name: 'Simpan password baru' }).click();
    await page.getByText('Password berhasil diganti', { exact: false }).first().waitFor({ timeout: 20000 });
    await page.getByRole('button', { name: 'Kembali ke Profil' }).click();
    await page.waitForURL(u => u.pathname === '/profil', { timeout: 20000 });
    await tenang();
  }

  langkah = `${user} 7-kembali-beranda`;
  await page.getByText('Beranda', { exact: true }).first().click();
  await page.getByTestId('kartu-identitas').waitFor({ timeout: 20000 });
  await tenang();
  hasil[langkah] ??= [];
  await ctx.close();
}
await browser.close();
if (luar.length) { console.log(`GAGAL: permintaan ke luar 127.0.0.1: ${[...new Set(luar)].join(', ')}`); process.exit(1); }
fs.writeFileSync(a, JSON.stringify(hasil, null, 1));
console.log(`Terekam ${Object.keys(hasil).length} langkah, ${Object.values(hasil).flat().length} permintaan → ${a}`);
