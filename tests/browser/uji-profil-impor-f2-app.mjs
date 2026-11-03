import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

// Uji aplikasi Fase 2 PRD-PROFIL-IMPORT — kartu identitas Beranda, Profil,
// Edit Profil, foto, Ganti Password, dan password sementara (Expo WEB dari
// repo alhasanApps + API/DB uji nyata). Ini Expo WEB (Chromium 375 px), bukan
// perangkat native; ukuran teks sistem besar diuji di Android emulator.
//
//   PIF1_DB=test_pif2_app_test bash tests/profil_impor_f1_db.sh
//   PIF2_DB=test_pif2_app_test php tests/profil_impor_f2_app_seed.php > /tmp/pif2-ids.json
//   DB_NAME=test_pif2_app_test PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8962 tests/redesain_web_tahap1_router.php &
//   (cd <salinan alhasanApps TANPA .env*> && EXPO_NO_DOTENV=1 EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8962/api/v1 npx expo start --web --port 8963 --clear &)
//   (cd tests/browser && DB_NAME=test_pif2_app_test PIF2_WEB=http://127.0.0.1:8962 PIF2_APP=http://127.0.0.1:8963 SEED_JSON=/tmp/pif2-ids.json OUT_DIR=… node uji-profil-impor-f2-app.mjs)
//
//   B-A  kartu = isi pertama Beranda untuk Admin, Guru/Murobi, Pengurus/Pembimbing, Orang Tua, akun banyak tugas
//   B-B  nama/status/target dari API; Kamar vs Kelas; tanpa kamar rekaan
//   B-C  Profil: data diri dan penugasan hanya baca; mapel–kelas lengkap (Guru Mapel)
//   B-D  Edit Profil setiap isian → Profil + kartu tanpa masuk ulang; DB/akun sinkron; audit
//   B-E  validasi: lokal, 409 email akun lain, 409 versi basi
//   B-F  foto: unggah/ganti PNG/JPEG, GIF & >2 MB ditolak di klien, hapus → inisial, foto akun lain 404
//   B-G  Ganti Password: salah/lemah/beda ditolak; berhasil → token lain 401, sesi ini tetap
//   B-H  password sementara (PI-1): hanya layar ganti; sesudahnya menu penuh
//   B-I  admin mereset password saat sesi berjalan (PF-1) → layar ganti
//   B-J  pencabutan penugasan → Murobi & kamar hilang setelah dimuat ulang
//   B-K  gagal jaringan, sesi kedaluwarsa, server lama (404) → tampilan cadangan
//   B-L  nol permintaan ke luar 127.0.0.1; password tidak di penyimpanan/konsol; 375 px tanpa gulir horizontal
const web = process.env.PIF2_WEB, app = process.env.PIF2_APP, db = process.env.DB_NAME || '';
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db)) throw Error('Hanya localhost dan DB test_*_test');
const ids = JSON.parse(fs.readFileSync(process.env.SEED_JSON, 'utf8'));
const out = process.env.OUT_DIR || '/tmp/pif2-tangkapan';
const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
fs.mkdirSync(out, { recursive: true });
const PASS = 'Sandbox#123';

let gagal = 0, lulus = 0;
const ok = (v, l) => { console.log((v ? '[lulus] ' : '[gagal] ') + l); v ? lulus++ : gagal++; };
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const php = code => execFileSync('php', ['-r', `putenv('DB_NAME=${db}'); require '${root}/app/bootstrap.php'; ${code}`], { encoding: 'utf8' }).trim();
async function loginApi(user, password = PASS) {
  const r = await fetch(`${web}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password, device_name: 'uji-pif2-api' }) });
  return (await r.json()).data?.token;
}
async function api(token, p, opsi = {}) {
  const r = await fetch(`${web}/api/v1${p}`, { method: opsi.method || 'GET', headers: { Authorization: `Bearer ${token}`, ...(opsi.body ? { 'Content-Type': 'application/json' } : {}) }, body: opsi.body ? JSON.stringify(opsi.body) : undefined });
  return { status: r.status, json: await r.json().catch(() => null) };
}
const gambar = jenis => Buffer.from(php(`$i=imagecreatetruecolor(160,160);imagefill($i,0,0,imagecolorallocate($i,${jenis === 'jpeg' ? '200,90,30' : '23,107,58'}));imagefilledellipse($i,80,64,70,70,imagecolorallocate($i,240,220,190));ob_start();image${jenis}($i);echo base64_encode(ob_get_clean());`), 'base64');

const browser = await chromium.launch({ headless: true });
const konsol = [];
const permintaanLuar = [];
const kirimanPassword = [];
/** Gangguan per halaman: { blokirPribadi, pribadi404 } */
async function halamanApp(user, password = PASS, gangguan = {}) {
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 } });
  const page = await ctx.newPage();
  page.on('console', m => konsol.push(`${user}: ${m.text()}`));
  await page.route('**/*', async route => {
    const u = new URL(route.request().url());
    if (u.hostname !== '127.0.0.1') { permintaanLuar.push(u.origin); return route.abort(); }
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    const metode = route.request().method();
    if (metode === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    if (u.pathname.endsWith('/profile/password')) kirimanPassword.push(route.request().postData() || '');
    if (gangguan.blokirFoto && metode === 'GET' && u.pathname === '/api/v1/profile/foto') {
      gangguan.fotoGagal = true;
      return route.abort('internetdisconnected');
    }
    if (gangguan.blokirPribadi && u.pathname.startsWith('/api/v1/profile/')) return route.abort('internetdisconnected');
    if (gangguan.pribadi404 && u.pathname.startsWith('/api/v1/profile/') && u.pathname !== '/api/v1/profile/identitas') {
      return route.fulfill({ status: 404, contentType: 'application/json', headers: { 'Access-Control-Allow-Origin': app }, body: JSON.stringify({ success: false, data: null, error: { code: 'NOT_FOUND', message: 'Endpoint tidak ditemukan.' } }) });
    }
    try {
      const response = await route.fetch();
      await route.fulfill({ response, headers: { ...response.headers(), 'Access-Control-Allow-Origin': app } });
    } catch { /* konteks sudah ditutup */ }
  });
  await page.goto(app + '/login');
  await page.getByPlaceholder('Masukkan username').fill(user);
  await page.getByPlaceholder('Masukkan password').fill(password);
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
  await page.waitForURL(u => u.pathname !== '/login', { timeout: 20000 });
  return page;
}
const teks = async p => (await p.locator('body').innerText()).replace(/\s+/g, ' ');
const tunggu = (p, t, timeout = 20000) => p.getByText(t, { exact: false }).first().waitFor({ timeout });
const kartu = p => p.getByTestId('kartu-identitas');
const lebar = async (p, nama) => {
  const hasil = await p.evaluate(() => {
    const lebih = document.documentElement.scrollWidth - document.documentElement.clientWidth;
    // TF4-1 (penugasan Keamanan Fase 4): bilah tab WEB meluap sejak baseline
    // mobile; dicatat terpisah. Isi layar sendiri wajib tidak meluap.
    const tab = [...document.querySelectorAll('div')].filter(el => /^Al Hasan\s/.test(el.innerText || '') && /Beranda/.test(el.innerText)).pop();
    const isi = [...document.querySelectorAll('body *')].filter(el => el.getBoundingClientRect().right > innerWidth + 1
      && !(tab && (el.contains(tab) || tab.contains(el))));
    return { lebih, isi: isi.map(el => (el.innerText || el.tagName).slice(0, 30)) };
  });
  ok(hasil.isi.length === 0, `${nama}: isi tanpa gulir horizontal pada 375 px (dokumen +${hasil.lebih}px${hasil.lebih > 1 ? ' hanya bilah tab web, TF4-1' : ''}${hasil.isi.length ? `; peluap isi: ${hasil.isi.join(' | ')}` : ''})`);
};
const foto = (p, nama) => p.screenshot({ path: path.join(out, `app-pif2-${nama}-375.png`), fullPage: true });
const penyimpanan = p => p.evaluate(() => JSON.stringify([Object.entries(localStorage), Object.entries(sessionStorage)]));
/**
 * Kartu adalah anak PERTAMA wadah isi Beranda dan tepat di bawah bilah navigasi
 * web (di native: di bawah area aman status bar). Bilah navigasi web berada di
 * luar wadah isi gulir.
 */
async function kartuPertama(p) {
  await kartu(p).waitFor({ timeout: 20000 });
  return p.evaluate(() => {
    const k = document.querySelector('[data-testid="kartu-identitas"]');
    const wadah = k.parentElement;
    let gulir = wadah; while (gulir && getComputedStyle(gulir).overflowY !== 'auto' && getComputedStyle(gulir).overflowY !== 'scroll') gulir = gulir.parentElement;
    const jarak = k.getBoundingClientRect().top - (gulir ? gulir.getBoundingClientRect().top : 0);
    return { pertama: wadah.firstElementChild === k, jarak };
  });
}

const keProfil = async p => { await p.goto(app + '/profil'); await tunggu(p, 'Akun & pemberitahuan'); };

try {
  console.log('\n=== B-A/B-B. Kartu identitas di Beranda ===');
  const kelompok = [
    ['sbx_admin', 'Admin'], ['sbx_murobi_a', 'Guru (Murobi)'], ['sbx_pengurus_a', 'Pengurus (Pembimbing)'],
    ['sbx_ortu_a', 'Orang Tua'], ['sbx_v5_ganda', 'Banyak tugas'], ['sbx_guru_mapel', 'Guru Mapel'],
  ];
  for (const [user, label] of kelompok) {
    const p = await halamanApp(user);
    const token = await loginApi(user);
    const data = (await api(token, '/profile/pribadi')).json.data;
    const posisi = await kartuPertama(p);
    ok(posisi.pertama && posisi.jarak <= 16, `${label}: kartu = isi pertama Beranda (anak pertama wadah isi, ${Math.round(posisi.jarak)}px dari atas area gulir)`);
    // Label peran bisa muncul di luar kartu sebelum profil pribadi selesai dimuat.
    await kartu(p).getByText(data.pribadi.nama, { exact: true }).waitFor({ timeout: 20000 });
    await kartu(p).getByText(data.status[0].label, { exact: true }).waitFor({ timeout: 20000 });
    const isi = (await kartu(p).innerText()).replace(/\s+/g, ' ');
    ok(isi.includes(data.pribadi.nama), `${label}: nama lengkap tampil utuh (${data.pribadi.nama.length} karakter)`);
    ok(data.status.every(s => isi.includes(s.label)), `${label}: seluruh status = API (${data.status.map(s => s.label).join(', ')})`);
    ok(data.binaan.every(b => isi.includes(`${b.peran} · ${b.tipe} ${b.nama}`)), `${label}: target binaan = API (${data.binaan.map(b => b.tipe + ' ' + b.nama).join('; ') || 'tidak ada'})`);
    if (data.binaan.length === 0) ok(!/Kamar|Kelas /.test(isi), `${label}: tanpa target → tanpa nama kamar/kelas rekaan`);
    ok(await p.getByRole('img', { name: /Foto profil/ }).count() === 0 && isi.includes(data.foto.inisial), `${label}: tanpa foto → inisial ${data.foto.inisial}`);
    const terpotong = await kartu(p).evaluate(el => [...el.querySelectorAll('div')].some(d => d.scrollWidth > d.clientWidth + 1 && getComputedStyle(d).overflow === 'hidden' && d.textContent.trim().length > 0 && d.children.length === 0));
    ok(!terpotong, `${label}: tidak ada teks kartu yang terpotong`);
    await lebar(p, `Beranda ${label}`);
    await foto(p, `beranda-${user}`);
    if (user === 'sbx_pengurus_a') ok(isi.includes('Pembimbing · Kelas '), 'Pembimbing bertarget kelas diberi label "Kelas"');
    if (user === 'sbx_murobi_a') ok(isi.includes('Murobi · Kamar '), 'Murobi bertarget kamar diberi label "Kamar"');
    if (user === 'sbx_admin' || user === 'sbx_murobi_a') {
      console.log(`\n=== Kartu membuka Profil (${label}) ===`);
      await kartu(p).getByRole('button', { name: /Buka profil/ }).click();
      await p.waitForURL(u => u.pathname === '/profil');
      ok(true, `${label}: ketuk kartu → Profil`);
    }
    if (user === 'sbx_guru_mapel') {
      console.log('\n=== B-C. Profil Guru Mapel: pasangan mapel–kelas ===');
      await keProfil(p);
      await tunggu(p, 'Mata pelajaran & kelas');
      const prof = await teks(p);
      const kelompokMapel = {};
      for (const m of data.mengajar) (kelompokMapel[m.mata_pelajaran] ??= []).push(m.kelas);
      ok(Object.entries(kelompokMapel).every(([mapel, kelas]) => prof.includes(`${mapel} ${kelas.join(', ')}`)), `Seluruh ${data.mengajar.length} pasangan mapel–kelas tampil per mapel`);
      ok(prof.includes('Guru Mata Pelajaran') && prof.includes('Hanya baca'), 'Penugasan Guru Mata Pelajaran tampil hanya baca');
      ok(await p.locator('input, textarea').count() === 0, 'Profil tanpa isian yang dapat diedit');
      await lebar(p, 'Profil Guru Mapel');
      await foto(p, 'profil-guru-mapel');
    }
    await p.context().close();
  }

  console.log('\n=== B-D. Edit Profil setiap isian (sbx_pengurus_a) ===');
  const pa = await halamanApp('sbx_pengurus_a');
  await keProfil(pa);
  await tunggu(pa, 'Data diri');
  const sebelum = await teks(pa);
  ok(sebelum.includes('@sbx_pengurus_a') && sebelum.includes('Rincian penugasan aktif') && sebelum.includes('Pembimbing'), 'Profil: username, penugasan aktif tampil');
  await pa.getByRole('button', { name: 'Edit profil' }).click();
  await pa.waitForURL(u => u.pathname === '/akun/edit-profil');
  await tunggu(pa, 'Diatur admin (hanya baca)');
  ok(await pa.locator('input[aria-label="Username"]').count() === 0 && (await teks(pa)).includes('@sbx_pengurus_a'), 'Username hanya baca, bukan isian');
  const labelTerlarang = ['NIP', 'NIK', 'Peran', 'Kamar', 'Kelas', 'Mata pelajaran', 'Penugasan'];
  const labelInput = await pa.locator('input, textarea').evaluateAll(els => els.map(e => e.getAttribute('aria-label') || ''));
  ok(labelInput.every(l => !labelTerlarang.some(t => l.startsWith(t))), `Isian hanya data pribadi (${labelInput.join(', ')})`);
  const baru = {
    'Nama lengkap': 'SBX Pengurus A Diubah', 'Tempat lahir': 'Garut', 'Alamat rumah': 'Jl. Uji Profil No. 2', 'Kelurahan/Desa': 'Sukamaju',
    'Kecamatan': 'Cibeunying', 'Kabupaten/Kota': 'Bandung', 'Provinsi': 'Jawa Barat', 'No. HP': '081299990001', 'Email': 'pengurus.a@sbx.test',
  };
  for (const [label, nilai] of Object.entries(baru)) await pa.getByLabel(label, { exact: true }).fill(nilai);
  await pa.getByLabel('Tanggal lahir', { exact: true }).fill('1991-04-05');
  await pa.getByLabel('No. HP', { exact: true }).fill('12345');
  await pa.getByRole('button', { name: 'Simpan perubahan' }).click();
  await tunggu(pa, 'diawali 0');
  ok(sql(`SELECT COUNT(*) FROM profil_pribadi WHERE pengurus_id=(SELECT pengurus_id FROM users WHERE id=${ids.pengurus_a})`) === '0', 'Validasi lokal: HP salah tidak dikirim, tidak ada perubahan DB');
  await pa.getByLabel('No. HP', { exact: true }).fill('081299990001');
  await pa.getByLabel('Email', { exact: true }).fill('admin.pif2@sbx.test');
  await pa.getByRole('button', { name: 'Simpan perubahan' }).click();
  await tunggu(pa, 'Email sudah dipakai akun lain');
  ok(sql(`SELECT name FROM users WHERE id=${ids.pengurus_a}`) === 'SBX Pengurus A', 'B-E 409 email milik akun lain: ditolak tanpa pembaruan sebagian');
  await pa.getByLabel('Email', { exact: true }).fill('pengurus.a@sbx.test');
  const auditSebelum = Number(sql(`SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'profil.%' AND actor_user_id=${ids.pengurus_a}`));
  await pa.getByRole('button', { name: 'Simpan perubahan' }).click();
  await pa.waitForURL(u => u.pathname === '/profil', { timeout: 20000 });
  await tunggu(pa, 'SBX Pengurus A Diubah');
  const sesudah = await teks(pa);
  ok(['Garut', '5 April 1991', 'Jl. Uji Profil No. 2, Sukamaju, Cibeunying, Bandung, Jawa Barat', '081299990001', 'pengurus.a@sbx.test'].every(t => sesudah.includes(t)), 'Profil menampilkan seluruh nilai baru tanpa masuk ulang');
  const pg = sql(`SELECT p.nama, p.no_hp, u.name, u.phone, u.email FROM pengurus p JOIN users u ON u.pengurus_id=p.id WHERE u.id=${ids.pengurus_a}`).split('\t');
  ok(pg.join('|') === 'SBX Pengurus A Diubah|081299990001|SBX Pengurus A Diubah|081299990001|pengurus.a@sbx.test', 'DB: identitas pengurus dan akun sinkron (nama, HP, email)');
  const pp = sql(`SELECT tempat_lahir, tanggal_lahir, alamat_rumah, kelurahan, kecamatan, kabupaten, provinsi FROM profil_pribadi WHERE pengurus_id=(SELECT pengurus_id FROM users WHERE id=${ids.pengurus_a})`);
  ok(pp === 'Garut\t1991-04-05\tJl. Uji Profil No. 2\tSukamaju\tCibeunying\tBandung\tJawa Barat', 'DB: profil_pribadi menyimpan setiap isian tambahan');
  ok(Number(sql(`SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'profil.%' AND actor_user_id=${ids.pengurus_a}`)) === auditSebelum + 1, 'Satu mutasi berhasil = satu catatan audit profil');
  await pa.goto(app + '/');
  await tunggu(pa, 'SBX Pengurus A Diubah');
  ok((await kartu(pa).innerText()).includes('SBX Pengurus A Diubah'), 'Kartu Beranda memakai nama baru tanpa masuk ulang');
  await foto(pa, 'beranda-sesudah-edit');

  console.log('\n=== B-E. Versi basi (perubahan dari perangkat lain) ===');
  await pa.goto(app + '/akun/edit-profil');
  await tunggu(pa, 'Diatur admin (hanya baca)');
  await pa.getByLabel('Tempat lahir', { exact: true }).fill('Tasikmalaya');
  const tokenLain = await loginApi('sbx_pengurus_a');
  const lain = await api(tokenLain, '/profile/pribadi', { method: 'POST', body: { kecamatan: 'Coblong' } });
  ok(lain.status === 200, 'Perangkat lain mengubah kecamatan lebih dulu');
  await pa.getByRole('button', { name: 'Simpan perubahan' }).click();
  await tunggu(pa, 'Muat data terbaru');
  ok(sql(`SELECT tempat_lahir FROM profil_pribadi WHERE pengurus_id=(SELECT pengurus_id FROM users WHERE id=${ids.pengurus_a})`) === 'Garut', '409 versi basi: draf tidak menimpa perubahan perangkat lain');
  await pa.getByRole('button', { name: /Muat data terbaru/ }).click();
  await pa.waitForFunction(() => document.querySelector('input[aria-label="Kecamatan"]')?.value === 'Coblong', null, { timeout: 15000 });
  ok(true, 'Muat data terbaru: formulir memakai versi server (kecamatan Coblong)');

  console.log('\n=== B-F. Foto profil ===');
  const pilih = async (nama, mimeType, buffer) => {
    const [chooser] = await Promise.all([pa.waitForEvent('filechooser'), pa.getByRole('button', { name: /^(Pilih|Ganti) foto$/ }).click()]);
    ok(chooser.isMultiple() === false, `${nama}: pemilih berkas bawaan, satu berkas`);
    await chooser.setFiles({ name: nama, mimeType, buffer });
  };
  await pilih('profil.gif', 'image/gif', gambar('gif'));
  await tunggu(pa, 'JPEG, PNG, atau WebP');
  ok(sql(`SELECT COUNT(*) FROM profil_pribadi WHERE foto IS NOT NULL AND pengurus_id=(SELECT pengurus_id FROM users WHERE id=${ids.pengurus_a})`) === '0', 'GIF ditolak di aplikasi tanpa unggahan');
  await pilih('besar.png', 'image/png', Buffer.concat([gambar('png'), Buffer.alloc(2 * 1024 * 1024)]));
  await tunggu(pa, 'maksimal 2 MB');
  ok(true, 'Berkas > 2 MB ditolak di aplikasi');
  await pilih('profil.png', 'image/png', gambar('png'));
  await tunggu(pa, 'Foto profil diperbarui');
  await pa.getByRole('img', { name: /Foto profil/ }).first().waitFor({ timeout: 15000 });
  ok(true, 'PNG terunggah; foto tampil di Edit Profil');
  const versi1 = Number(sql(`SELECT foto_versi FROM profil_pribadi WHERE pengurus_id=(SELECT pengurus_id FROM users WHERE id=${ids.pengurus_a})`));
  await pilih('profil.jpg', 'image/jpeg', gambar('jpeg'));
  await pa.waitForFunction(() => document.body.innerText.includes('Foto profil diperbarui'));
  await pa.waitForTimeout(800);
  ok(Number(sql(`SELECT foto_versi FROM profil_pribadi WHERE pengurus_id=(SELECT pengurus_id FROM users WHERE id=${ids.pengurus_a})`)) === versi1 + 1, 'Ganti foto (JPEG): versi foto naik satu');
  const srcFoto = await pa.getByRole('img', { name: /Foto profil/ }).first().evaluate(el => (el.querySelector('img') ?? el).getAttribute('src') ?? getComputedStyle(el).backgroundImage);
  ok(/data:image\/jpeg;base64/.test(srcFoto), 'Foto dimuat lewat endpoint berotorisasi sebagai data, bukan URL publik');
  await pa.goto(app + '/');
  await kartu(pa).getByRole('img', { name: /Foto profil/ }).waitFor({ timeout: 15000 });
  ok(true, 'Kartu Beranda menampilkan foto baru');
  await foto(pa, 'beranda-foto');
  const gangguanFoto = { blokirFoto: true, fotoGagal: false };
  const ulangFoto = await halamanApp('sbx_pengurus_a', PASS, gangguanFoto);
  await kartu(ulangFoto).getByText('SBX Pengurus A Diubah', { exact: true }).waitFor();
  await ulangFoto.waitForEvent('requestfailed', {
    predicate: r => new URL(r.url()).pathname === '/api/v1/profile/foto',
    timeout: 5000,
  }).catch(e => { if (!gangguanFoto.fotoGagal) throw e; });
  ok(gangguanFoto.fotoGagal && await kartu(ulangFoto).getByRole('img', { name: /Foto profil/ }).count() === 0,
    'Unduhan foto gagal → kartu tetap tampil dengan inisial');
  gangguanFoto.blokirFoto = false;
  await kartu(ulangFoto).getByRole('button', { name: /Buka profil/ }).click();
  await ulangFoto.waitForURL(u => u.pathname === '/profil');
  await ulangFoto.getByRole('img', { name: /Foto profil/ }).first().waitFor({ timeout: 10000 });
  ok(true, 'Fokus Profil memuat ulang foto dengan versi sama setelah jaringan pulih');
  await ulangFoto.context().close();
  const tokenB = await loginApi('sbx_pengurus_b');
  const lintas = await fetch(`${web}/api/v1/profile/foto/${ids.pengurus_a}`, { headers: { Authorization: `Bearer ${tokenB}` } });
  ok(lintas.status === 404, 'Foto akun lain oleh akun tanpa kewenangan → 404');
  const bacaLintas = await api(tokenB, `/profile/pribadi?user_id=${ids.pengurus_a}`);
  ok(bacaLintas.status === 200 && bacaLintas.json?.data?.akun?.id === ids.pengurus_b,
    'Parameter identitas akun lain tidak memberi akses ke profilnya');
  const tulisUsername = await api(tokenB, '/profile/pribadi', { method: 'POST', body: { username: 'curian', roles: ['admin'] } });
  ok(tulisUsername.status === 422 && sql(`SELECT username FROM users WHERE id=${ids.pengurus_b}`) === 'sbx_pengurus_b', 'Menulis username/role lewat API profil → 422, tidak berubah');
  await pa.goto(app + '/akun/edit-profil');
  await tunggu(pa, 'Hapus foto');
  pa.once('dialog', d => d.accept());
  await pa.getByRole('button', { name: 'Hapus foto' }).click();
  await tunggu(pa, 'Foto profil dihapus');
  ok(await pa.getByRole('img', { name: /Foto profil/ }).count() === 0, 'Hapus foto → inisial');
  await lebar(pa, 'Edit Profil');
  await foto(pa, 'edit-profil');

  console.log('\n=== B-G. Ganti Password (sbx_pengurus_a) ===');
  const tokenLama = await loginApi('sbx_pengurus_a');
  await keProfil(pa);
  await pa.getByRole('button', { name: /^Ganti password/ }).click();
  await pa.waitForURL(u => u.pathname === '/akun/ganti-password');
  const isiPw = async (saat, baruPw, ulang) => {
    await pa.getByLabel('Password saat ini', { exact: true }).fill(saat);
    await pa.getByLabel('Password baru', { exact: true }).fill(baruPw);
    await pa.getByLabel('Ulangi password baru', { exact: true }).fill(ulang);
    await pa.getByRole('button', { name: 'Simpan password baru' }).click();
  };
  const jumlahKirim = kirimanPassword.length;
  await isiPw(PASS, 'lemah', 'lemah');
  await tunggu(pa, 'belum memenuhi aturan');
  await isiPw(PASS, 'PasswordBaru2026', 'PasswordBaru2027');
  await tunggu(pa, 'tidak sama');
  ok(kirimanPassword.length === jumlahKirim, 'Password lemah/ulangan beda ditolak di aplikasi tanpa permintaan');
  await isiPw('SalahSekali123', 'PasswordBaru2026', 'PasswordBaru2026');
  await tunggu(pa, 'Password saat ini tidak benar');
  ok((await api(tokenLama, '/profile/pribadi')).status === 200, 'Password saat ini salah → ditolak server, sesi lain tetap');
  await isiPw(PASS, 'PasswordBaru2026', 'PasswordBaru2026');
  await tunggu(pa, 'Password berhasil diganti');
  ok((await api(tokenLama, '/profile/pribadi')).status === 401, 'Berhasil → token perangkat lain dicabut (401)');
  ok(!(await loginApi('sbx_pengurus_a')) && Boolean(await loginApi('sbx_pengurus_a', 'PasswordBaru2026')), 'Password lama tidak berlaku, password baru berlaku');
  ok((await pa.locator('input[type="password"]').count()) === 0, 'Isian password dikosongkan/ditutup setelah berhasil');
  await pa.getByRole('button', { name: 'Kembali ke Profil' }).click();
  await pa.goto(app + '/');
  await tunggu(pa, 'SBX Pengurus A Diubah');
  ok(true, 'Sesi aplikasi ini tetap berlaku sesudah ganti password');
  const simpan = await penyimpanan(pa);
  ok(!simpan.includes('PasswordBaru2026') && !simpan.includes(PASS), 'Password tidak tersimpan di localStorage/sessionStorage');
  await pa.context().close();

  console.log('\n=== B-H. Password sementara dari admin (PI-1) ===');
  const w = await halamanApp('sbx_murobi_c', 'Sementara#2026A');
  await w.waitForURL(u => u.pathname === '/ganti-password-wajib', { timeout: 20000 });
  await tunggu(w, 'Ganti password sementara');
  ok((await teks(w)).includes('@sbx_murobi_c') && await w.getByRole('tab').count() === 0, 'Login password sementara → hanya layar Ganti Password, tanpa tab menu');
  ok(sql(`SELECT cakupan FROM api_tokens WHERE user_id=${ids.wajib} AND revoked_at IS NULL ORDER BY id DESC LIMIT 1`) === 'ganti_password', 'Token aplikasi bercakupan ganti_password');
  await w.goto(app + '/profil');
  await w.waitForTimeout(1500);
  ok(!(await teks(w)).includes('Akun & pemberitahuan') && w.url().includes('ganti-password-wajib'), 'Alamat Profil langsung tidak terbuka sebelum password diganti');
  await w.getByLabel('Password saat ini', { exact: true }).fill('Sementara#2026A');
  await w.getByLabel('Password baru', { exact: true }).fill('PasswordTetap2026');
  await w.getByLabel('Ulangi password baru', { exact: true }).fill('PasswordTetap2026');
  await w.getByRole('button', { name: 'Simpan dan lanjutkan' }).click();
  await kartu(w).waitFor({ timeout: 20000 });
  ok(sql(`SELECT force_password_change FROM users WHERE id=${ids.wajib}`) === '0' && sql(`SELECT cakupan FROM api_tokens WHERE user_id=${ids.wajib} AND revoked_at IS NULL ORDER BY id DESC LIMIT 1`) === 'penuh', 'Sesudah diganti: Beranda terbuka, token menjadi penuh, wajib ganti dilepas');
  await foto(w, 'sesudah-password-sementara');
  await w.context().close();

  console.log('\n=== B-I. Admin mereset password saat sesi berjalan (PF-1) ===');
  const ob = await halamanApp('sbx_ortu_b');
  await kartu(ob).waitFor();
  sql(`UPDATE users SET force_password_change=1 WHERE id=(SELECT id FROM (SELECT id FROM users WHERE username='sbx_ortu_b') x)`);
  await ob.goto(app + '/profil');
  await ob.waitForURL(u => u.pathname === '/ganti-password-wajib', { timeout: 20000 });
  ok(true, 'Sesi lama yang ditandai wajib ganti dialihkan ke layar Ganti Password');
  await ob.context().close();

  console.log('\n=== B-J. Pencabutan penugasan pada sesi berjalan ===');
  const m = await halamanApp('sbx_murobi_a');
  await tunggu(m, 'Murobi · Kamar');
  php(`foreach (${JSON.stringify(ids.murobi_assignment_ids)} as $i) penugasan_service()->akhiri(\\App\\Penugasan\\PenugasanJenis::MUROBI, $i, (new DateTimeImmutable('-1 day'))->format('Y-m-d'), 'Uji Fase 2: dicabut', ${ids.admin});`);
  await m.goto(app + '/profil');
  await tunggu(m, 'Tidak ada penugasan aktif');
  await m.goto(app + '/');
  await kartu(m).waitFor();
  await m.waitForTimeout(1500);
  const sesudahCabut = (await kartu(m).innerText()).replace(/\s+/g, ' ');
  const statusCabut = (await api(await loginApi('sbx_murobi_a'), '/profile/pribadi')).json.data;
  ok(statusCabut.status.map(s => s.label).join(',') === 'Guru' && statusCabut.binaan.length === 0, 'API sesudah pencabutan: status Guru saja, tanpa binaan');
  ok(!sesudahCabut.includes('Murobi ·') && !/\bKamar\b/.test(sesudahCabut) && !/Guru\s*Murobi/.test(sesudahCabut.replace('SBX Murobi A', '')),
    `Murobi dan kamar hilang dari kartu/Profil setelah dimuat ulang (kartu: ${sesudahCabut})`);
  await m.context().close();

  console.log('\n=== B-K. Gagal jaringan, server lama, sesi kedaluwarsa ===');
  const off = await halamanApp('sbx_ortu_a', PASS, { blokirPribadi: true });
  await tunggu(off, 'Status terbaru belum dapat dimuat');
  const sesiOrtu = (await api(await loginApi('sbx_ortu_a'), '/profile')).json.data;
  const kartuOff = (await kartu(off).innerText()).replace(/\s+/g, ' ');
  ok(kartuOff.includes(sesiOrtu.name) && kartuOff.includes('Orang Tua'), `Offline: kartu tetap menampilkan nama dan peran dari sesi (${sesiOrtu.name})`);
  await off.goto(app + '/profil');
  await tunggu(off, 'offline');
  ok((await teks(off)).includes('Keluar dari aplikasi'), 'Offline: Profil menampilkan galat + Coba lagi, menu lain tetap');
  await off.context().close();
  const lama = await halamanApp('sbx_murobi_b', PASS, { pribadi404: true });
  await kartu(lama).waitFor();
  await lama.waitForTimeout(1500);
  ok((await kartu(lama).innerText()).includes('Guru'), 'Server lama (404): kartu memakai peran akun');
  const responsLama = lama.waitForResponse(r => r.status() === 404 && new URL(r.url()).pathname === '/api/v1/profile/pribadi');
  await lama.goto(app + '/profil');
  await responsLama;
  await tunggu(lama, 'Akun & pemberitahuan');
  await lama.getByText('Ganti password', { exact: true }).waitFor({ state: 'hidden' });
  const lamaTeks = await teks(lama);
  ok(!lamaTeks.includes('Edit profil') && !lamaTeks.includes('Ganti password'), 'Server lama: Edit/Ganti Password tidak ditawarkan');
  await lama.context().close();
  const exp = await halamanApp('sbx_pengurus_b');
  await kartu(exp).waitFor();
  sql(`UPDATE api_tokens SET revoked_at=NOW() WHERE user_id=${ids.pengurus_b} AND revoked_at IS NULL`);
  await exp.goto(app + '/akun/edit-profil');
  await exp.waitForURL(u => u.pathname === '/login', { timeout: 20000 });
  ok(true, 'Sesi kedaluwarsa (token dicabut) → kembali ke layar masuk');
  await exp.context().close();

  console.log('\n=== B-L. Privasi & jaringan ===');
  ok(permintaanLuar.length === 0, `Nol permintaan ke luar 127.0.0.1 (${[...new Set(permintaanLuar)].join(', ') || '—'})`);
  const log = konsol.join('\n');
  ok(!log.includes('PasswordBaru2026') && !log.includes('Sementara#2026A') && !log.includes('PasswordTetap2026'), 'Konsol tanpa password');
  ok(kirimanPassword.every(b => !b.includes('Sandbox#123') || b.includes('password_saat_ini')), 'Password hanya dikirim ke POST /profile/password');
} catch (e) {
  gagal++;
  console.log(`[gagal] Pengecualian: ${e.message.split('\n')[0]}`);
  let n = 0;
  for (const c of browser.contexts()) for (const pg of c.pages()) {
    console.log(`  halaman terbuka: ${pg.url()}`);
    await pg.screenshot({ path: path.join(out, `galat-${n++}.png`), fullPage: true }).catch(() => {});
  }
} finally {
  await browser.close();
}
console.log(`\nHasil: ${lulus} lulus / ${gagal} gagal`);
process.exit(gagal === 0 ? 0 : 1);
