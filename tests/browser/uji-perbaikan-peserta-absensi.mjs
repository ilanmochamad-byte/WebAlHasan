import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Uji tampilan perbaikan peserta absensi (6 November 2026): tombol "Perbarui
// daftar" di aplikasi (Expo WEB dari repo alhasanApps, Chromium 375 px — bukan
// perangkat native) dan "Perbarui daftar peserta" di website, di atas API/DB
// uji nyata.
//
//   JKG_DB=test_ppa_app_test bash tests/jadwal_kelas_gabungan_f1_db.sh
//   JKG_DB=test_ppa_app_test php tests/jadwal_kelas_gabungan_f2_seed.php > ids.json
//   DB_NAME=test_ppa_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8962 tests/redesain_web_tahap1_router.php &
//   (cd <alhasanApps> && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8962/api/v1 npx expo start --web --port 8099 --clear &)
//   (cd tests/browser && DB_NAME=test_ppa_app_test PPA_WEB=http://127.0.0.1:8962 PPA_APP=http://127.0.0.1:8099 SEED_JSON=ids.json OUT_DIR=… node uji-perbaikan-peserta-absensi.mjs)
//
//   A-1 aplikasi: pertemuan Dibuka menampilkan tombol; draf guru tetap sesudah diperbarui;
//       santri keluar hilang, santri baru muncul; pesan ringkas; ulangan "sudah sesuai"
//   A-2 aplikasi: kirim absensi memakai daftar baru (DB), tombol hilang saat Selesai
//   W-1 website: tombol hanya pada pertemuan Dibuka, meminta konfirmasi, menambah santri baru
const web = process.env.PPA_WEB, app = process.env.PPA_APP, db = process.env.DB_NAME || '';
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) throw Error('Hanya localhost dan DB test_*_test');
const ids = JSON.parse(fs.readFileSync(process.env.SEED_JSON, 'utf8'));
const out = process.env.OUT_DIR || '/tmp/ppa-tangkapan';
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';
const G = 'sbx_guru_biasa';
const T = ids.tanggal;
const JG = ids.jadwal_gabungan, JS = ids.jadwal_satu;
const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const angka = q => Number(sql(q));
// Perubahan data lewat layanan resmi (MasterDataService), seperti admin di website.
const php = kode => execFileSync('php', ['-r', `require '${root}/app/bootstrap.php'; ${kode}`], { encoding: 'utf8', env: { ...process.env, DB_NAME: db } }).trim();
const santriBaru = (label, kelas) => Number(php(`$m = master_data_service(); $id = $m->saveSantri(['nis' => 'PPAW' . bin2hex(random_bytes(3)), 'nama_santri' => '${label}', 'jenis_kelamin' => 'L', 'tempat_lahir' => 'Ciamis', 'tgl_lahir' => '2012-03-03', 'alamat' => '', 'desa' => '', 'kecamatan' => '', 'kab_kota' => '', 'provinsi' => '', 'nama_ayah' => '', 'no_hp_ayah' => '', 'nama_ibu' => '', 'no_hp_ibu' => '', 'asal_sekolah' => '', 'sekolah_saat_ini' => '', 'foto' => 'default.jpg']); $m->assignActiveClass(['santri_id' => $id, 'kelas_id' => ${kelas}, 'tanggal_mulai' => date('Y-m-d')], ${ids.admin_id}); echo $id;`));
const nonaktifkan = id => php(`master_data_service()->setSantriState(${id}, 'deactivate');`);

async function apiLogin(user) {
  const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: PASS, device_name: 'uji-ppa' }) });
  return (await r.json()).data.token;
}
const token = await apiLogin(G);
const buka = async (jadwal) => {
  const r = await fetch(`${web}/api/v1/schedules/${jadwal}/meetings`, { method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify({ date: T, idempotency_key: 'ppa-buka-' + jadwal + '-' + ids.sfx }) });
  return (await r.json()).data.id;
};

const browser = await chromium.launch({ headless: true });
const galat = [];
async function halamanApp(user) {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 } });
  const page = await ctx.newPage();
  page.on('pageerror', e => galat.push(`app: ${e.message}`));
  page.on('dialog', d => d.accept());
  await page.route('**/*', async route => {
    const req = route.request();
    const u = new URL(req.url());
    if (u.hostname !== '127.0.0.1') return route.abort();
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
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
const tunggu = (p, t, timeout = 20000) => p.getByText(t, { exact: false }).first().waitFor({ timeout });
const tombol = (p, nama) => p.getByRole('button', { name: nama, exact: true });
// Redesain gelombang 2 Fase 2 (12 November 2026): "Perbarui daftar" pindah ke
// menu ⋯ ("Tindakan lain") kepala layar. Hanya PENCARI tombol yang disesuaikan
// — pernyataan (assertion) tidak berubah; pada tampilan lama (tombol langsung)
// pembantu ini tidak melakukan apa-apa.
const bukaMenu = async p => {
  await p.waitForTimeout(600); // lembar bawah yang baru ditutup selesai beranimasi
  if (!(await tombol(p, 'Perbarui daftar').isVisible()) && await tombol(p, 'Tindakan lain').isVisible()) {
    await tombol(p, 'Tindakan lain').click();
    await p.waitForTimeout(500); // animasi lembar bawah
  }
};
const tutupMenu = async p => {
  if (await tombol(p, 'Tutup').last().isVisible().catch(() => false)) {
    await tombol(p, 'Tutup').last().click();
    await p.waitForTimeout(500);
  }
};
const kartuSantri = (p, nama) => p.locator('div').filter({ has: p.getByText(nama, { exact: true }) }).filter({ has: p.getByRole('radiogroup') }).last();
const foto = (p, nama) => p.screenshot({ path: path.join(out, `ppa-${nama}.png`), fullPage: true });

try {
  console.log('=== A-1 aplikasi: perbarui daftar santri ===');
  const MG = await buka(JG);
  const [pa1, pa2] = ids.santri_nama.PA;
  const [pi1] = ids.santri_nama.PI;
  ok(angka(`SELECT COUNT(*) FROM pertemuan_peserta WHERE pertemuan_id=${MG}`) === 5, 'Pertemuan gabungan Dibuka dengan 5 peserta');
  const g = await halamanApp(G);
  await g.goto(`${app}/meeting/${MG}`);
  await tunggu(g, 'Santri · 5 orang');
  await bukaMenu(g);
  ok(await tombol(g, 'Perbarui daftar').isVisible(), 'Tombol "Perbarui daftar" tampil pada pertemuan Dibuka');
  await tutupMenu(g);
  await kartuSantri(g, pa2.nama).getByRole('radio', { name: 'Sakit' }).click();
  // Sesudah dibuka: satu santri keluar, dua santri baru ditempatkan.
  nonaktifkan(pi1.id);
  const nama1 = `Santri Baru Satu ${ids.sfx}`, nama2 = `Santri Baru Dua ${ids.sfx}`;
  const b1 = santriBaru(nama1, ids.kelas.PA), b2 = santriBaru(nama2, ids.kelas.PI);
  await bukaMenu(g);
  await tombol(g, 'Perbarui daftar').click();
  await tunggu(g, 'Daftar santri diperbarui: 2 santri ditambahkan, 1 santri dikeluarkan.');
  const isi = await teks(g);
  ok(isi.includes('Santri · 6 orang'), 'Jumlah santri menjadi 6');
  ok(!isi.includes(pi1.nama) && isi.includes(nama1) && isi.includes(nama2), 'Santri keluar hilang, dua santri baru tampil');
  ok(await kartuSantri(g, pa2.nama).getByRole('radio', { name: 'Sakit' }).getAttribute('aria-checked') === 'true', 'Isian guru (Sakit) untuk santri yang tetap tercantum tidak hilang');
  ok(await kartuSantri(g, nama1).getByRole('radio', { name: 'Hadir' }).getAttribute('aria-checked') === 'true', 'Santri baru terisi "Hadir"');
  await foto(g, 'app-sesudah-perbarui-375');
  await bukaMenu(g);
  await tombol(g, 'Perbarui daftar').click();
  await tunggu(g, 'Daftar santri sudah sesuai dengan penempatan kelas terbaru.');
  ok(true, 'Ulangan: pesan "sudah sesuai"');

  console.log('\n=== A-2 aplikasi: kirim absensi dengan daftar baru ===');
  await tombol(g, 'Konfirmasi dan kirim').click();
  await tunggu(g, 'Absensi berhasil disimpan');
  ok(angka(`SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id=${MG}`) === 6
    && angka(`SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id=${MG} AND santri_id IN (${b1},${b2})`) === 2
    && angka(`SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id=${MG} AND santri_id=${pi1.id}`) === 0, 'DB: 6 absensi, termasuk santri baru, tanpa santri keluar');
  ok(sql(`SELECT status FROM absensi_santri WHERE pertemuan_id=${MG} AND santri_id=${pa2.id}`) === 'Sakit', 'DB: status Sakit tersimpan');
  // Menu ⋯ juga harus hilang: di tampilan baru tombolnya berada di dalam menu.
  ok(await tombol(g, 'Perbarui daftar').count() === 0 && await tombol(g, 'Tindakan lain').count() === 0, 'Tombol tidak tampil lagi setelah pertemuan Selesai');
  ok(angka(`SELECT COUNT(*) FROM audit_logs WHERE action='api.meeting.refresh_participants' AND entity_id=${MG}`) === 2, 'Dua audit pembaruan tercatat');

  console.log('\n=== W-1 website: Perbarui daftar peserta ===');
  const MS = await buka(JS);
  const sebelum = angka(`SELECT COUNT(*) FROM pertemuan_peserta WHERE pertemuan_id=${MS}`);
  const nama3 = `Santri Baru Web ${ids.sfx}`;
  const b3 = santriBaru(nama3, ids.kelas.C);
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 } });
  await ctx.route('**/*', r => new URL(r.request().url()).hostname === '127.0.0.1' ? r.fallback() : r.abort());
  const w = await ctx.newPage();
  w.on('pageerror', e => galat.push(`web: ${e.message}`));
  await w.goto(web + '/portal/index.php');
  await w.fill('#username', 'sbx_admin');
  await w.fill('#password', PASS);
  await Promise.all([w.waitForURL(/\/portal\/index\.php/), w.getByRole('button', { name: 'Masuk', exact: true }).click()]);
  await w.goto(`${web}/admin/admin_pengajian.php?tab=pertemuan&id=${MG}`);
  ok(await w.getByRole('button', { name: 'Perbarui daftar peserta' }).count() === 0, 'Pertemuan Selesai: tanpa tombol');
  await w.goto(`${web}/admin/admin_pengajian.php?tab=pertemuan&id=${MS}`);
  ok((await w.content()).includes(`Peserta (${sebelum})`) && !(await w.content()).includes(nama3), `Pertemuan Dibuka menampilkan ${sebelum} peserta tanpa santri baru`);
  await w.getByRole('button', { name: 'Perbarui daftar peserta' }).click();
  const dialog = w.locator('dialog.ah-dialog:not(.ah-dialog--form)[open]');
  await dialog.waitFor();
  ok((await dialog.innerText()).includes('Perbarui daftar peserta?'), 'Konfirmasi diminta sebelum memperbarui');
  await Promise.all([w.waitForNavigation({ waitUntil: 'load' }), dialog.locator('[data-ah-ok]').click()]);
  const hasil = await w.content();
  ok(hasil.includes('Daftar peserta diperbarui: 1 santri ditambahkan') && hasil.includes(nama3) && hasil.includes(`Peserta (${sebelum + 1})`), 'Website: santri baru ditambahkan dan pesan tampil');
  ok(angka(`SELECT COUNT(*) FROM pertemuan_peserta WHERE pertemuan_id=${MS} AND santri_id=${b3}`) === 1, 'DB: peserta baru tersimpan');
  ok(await w.evaluate(() => document.documentElement.scrollWidth - window.innerWidth) <= 1, 'Website 375 px tanpa gulir horizontal');
  await w.screenshot({ path: path.join(out, 'ppa-web-sesudah-perbarui-375.png'), fullPage: true });
  ok(galat.length === 0, 'Tanpa galat JavaScript halaman' + (galat.length ? `: ${galat.join(' | ')}` : ''));
} catch (e) {
  ok(false, 'Galat uji: ' + e.message);
} finally {
  await browser.close();
}
console.log(`\nHasil: ${lulus} lulus, ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
